#!/usr/bin/env perl
#
# Copyright (C) 2001-2024 Zabbix SIA
#
# This program is free software: you can redistribute it and/or modify it under the terms of
# the GNU Affero General Public License as published by the Free Software Foundation, version 3.
#
# This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
# without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
# See the GNU Affero General Public License for more details.
#
# You should have received a copy of the GNU Affero General Public License along with this program.
# If not, see <https://www.gnu.org/licenses/>.

use strict;
use File::Basename;

my (%output, $insert_into, $fields, @values_list);

my %mysql = (
	"database"	=>	"mysql",
	"before"	=>	"START TRANSACTION;\n",
	"after"		=>	"COMMIT;\n",
	"exec_cmd"	=>	";\n"
);

my %oracle = (
	"database"	=>	"oracle",
	"before"	=>	"SET DEFINE OFF\n",
	"after"		=>	"",
	"exec_cmd"	=>	"\n/\n\n"
);

my %postgresql = (
	"database"	=>	"postgresql",
	"before"	=>	"START TRANSACTION;\n",
	"after"		=>	"COMMIT;\n",
	"exec_cmd"	=>	";\n"
);

# Maximum line length that SQL*Plus can read from .sql file is 2499 characters.
# Splitting long entries in 'media_type' table have to happen before SQL*Plus limit has been reached and end-of-line
# character has to stay intact in one line.
my $oracle_field_limit = 2048;

sub process_table
{
	my $line = $_[0];

	$line = "`$line`" if ($output{'database'} eq 'mysql');

	$insert_into = "INSERT INTO $line";
	@values_list = ();  # Reset the values list when processing a new table
}

sub process_fields
{
	my $line = $_[0];

	my @array = split(/\|/, $line);

	my $first = 1;
	$fields = "(";

	if ($output{'database'} eq 'mysql')
	{
		foreach (@array)
		{
			$fields = "$fields," if ($first == 0);
			$first = 0;

			$_ =~ s/\s+$//; # remove trailing spaces

			$fields = "$fields`$_`";
		}
	}
	else
	{
		foreach (@array)
		{
			$fields = "$fields," if ($first == 0);
			$first = 0;

			$_ =~ s/\s+$//; # remove trailing spaces

			$fields = "$fields$_";
		}
	}

	$fields = "$fields)";
}

sub process_row
{
	my $line = $_[0];

	my @array = split(/\|/, $line);

	my $first = 1;
	my $values = "(";
	my $split_script_field = 0;

	foreach (@array)
	{
		$values = "$values," if ($first == 0);
		$first = 0;

		# remove leading and trailing spaces
		$_ =~ s/^\s+//;
		$_ =~ s/\s+$//;

		if ($_ eq 'NULL')
		{
			$values = "$values$_";
		}
		else
		{
			my $modifier = '';

			# escape backslashes
			if (/\\/)
			{
				if ($output{'database'} eq 'postgresql')
				{
					$_ =~ s/\\/\\\\/g;
					$modifier = 'E';
				}
				elsif ($output{'database'} eq 'mysql')
				{
					$_ =~ s/\\/\\\\/g;
				}
			}

			# escape single quotes
			if (/'/)
			{
				if ($output{'database'} eq 'mysql')
				{
					$_ =~ s/'/\\'/g;
				}
				else
				{
					$_ =~ s/'/''/g;
				}
			}

			$_ =~ s/&pipe;/|/g;
			$_ =~ s/&tab;/\t/g;

			if ($output{'database'} eq 'mysql')
			{
				$_ =~ s/&eol;/\\r\\n/g;
				$_ =~ s/&bsn;/\\n/g;
			}
			elsif ($output{'database'} eq 'oracle')
			{
				$_ =~ s/&eol;/' || chr(13) || chr(10) || '/g;
				$_ =~ s/&bsn;/' || chr(10) || '/g;

				if (length($_) > $oracle_field_limit)
				{
					my @sections = unpack("(a$oracle_field_limit)*", $_);
					my $move_to_next;
					my $first_part = 1;
					my $script;

					$split_script_field = 1;

					foreach (@sections)
					{
						# split after 'end of line' character and move what is left to the next line
						if (/(.*' \|\| (?:chr\(13\) \|\| )?chr\(10\) \|\| ')(.*)/)
						{
							if ($first_part == 1)
							{
								$script = "TO_NCLOB('$1')";
								$first_part = 0;
							}
							else
							{
								$script = "${script}||\nTO_NCLOB('$move_to_next$1')";
							}

							$move_to_next = $2;
						}
						else
						{
							$move_to_next = "$move_to_next$_";
						}
					}

					if (length($move_to_next) > 0)
					{
						if (length($script) + length($move_to_next) < $oracle_field_limit)
						{
							substr($script, length($script) - 2, 2, "$move_to_next')");
						}
						else
						{
							substr($script, length($script), 0, "||\nTO_NCLOB('$move_to_next')");
						}
					}

					$_ = $script;
				}
			}
			else
			{
				$_ =~ s/&eol;/\x0D\x0A/g;
				$_ =~ s/&bsn;/\x0A/g;
			}

			# can be set to 1 only if Oracle DB is used
			if ($split_script_field == 1)
			{
				$values = "$values$modifier$_";
				$split_script_field = 0;
			}
			else
			{
				$values = "$values$modifier'$_'";
			}
		}
	}

	$values = "$values)";

	# Add the current row's values to the list
	push @values_list, $values;
}

sub flush_bulk_insert
{
	if (@values_list) {
		my $bulk_insert = $output{'database'} eq 'mysql' || $output{'database'} eq 'postgresql'
			? "$insert_into $fields VALUES ".join(",\n", @values_list).$output{'exec_cmd'}
			: "$insert_into $fields\nvalues ".join("\n/\n\n$insert_into $fields\nvalues ", @values_list).$output{'exec_cmd'};
		print $bulk_insert;
		@values_list = ();  # Clear the values list after printing
	}
}

sub usage
{
	print "Usage: $0 [mysql|oracle|postgresql]\n";
	print "The script generates Zabbix SQL data files for different database engines.\n";
	exit;
}

sub main
{
	if ($#ARGV != 0)
	{
		usage();
	}

	open(INFO, dirname($0)."/../src/data.tmpl");
	my @lines = <INFO>;
	close(INFO);

	open(INFO, dirname($0)."/../src/templates.tmpl");
	push(@lines, <INFO>);
	close(INFO);

	open(INFO, dirname($0)."/../src/dashboards.tmpl");
	push(@lines, <INFO>);
	close(INFO);

	if ($ARGV[0] eq 'mysql')		{ %output = %mysql; }
	elsif ($ARGV[0] eq 'oracle')		{ %output = %oracle; }
	elsif ($ARGV[0] eq 'postgresql')	{ %output = %postgresql; }
	else					{ usage(); }

	print $output{"before"};

	my ($line, $type);
	foreach $line (@lines)
	{
		$line =~ tr/\t//d;
		chop($line);

		($type, $line) = split(/\|/, $line, 2);

		if ($type)
		{
			$type =~ s/\s+$//; # remove trailing spaces

			if ($type eq 'FIELDS')		{ process_fields($line); }
			elsif ($type eq 'TABLE')	{ flush_bulk_insert(); process_table($line); }
			elsif ($type eq 'ROW')		{ process_row($line); }
		}
	}

	flush_bulk_insert();  # Ensure the last batch of rows is printed

	print "DELETE FROM changelog$output{'exec_cmd'}";

	print $output{"after"};
}

main();
