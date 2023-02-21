<?php
/*
** Zabbix
** Copyright (C) 2001-2022 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


require_once dirname(__FILE__).'/../../include/CWebTest.php';
require_once dirname(__FILE__).'/../../include/helpers/CDataHelper.php';
require_once dirname(__FILE__).'/../behaviors/CMessageBehavior.php';

/**
 * @backup config, widget
 *
 * @onBefore prepareDashboardData, prepareProblemsData
 */
class testDashboardProblemsWidgetDisplay extends CWebTest {

	use TableTrait;

	private static $hostid;
	private static $dashboardid;
	private static $triggerids;
	private static $acktime;

	/**
	 * Attach MessageBehavior to the test.
	 *
	 * @return array
	 */
	public function getBehaviors() {
		return [CMessageBehavior::class];
	}


	public function prepareDashboardData() {
		$response = CDataHelper::call('dashboard.create', [
			'name' => 'Dashboard for Problem widget check',
			'auto_start' => 0,
			'pages' => [
				[
					'name' => 'First Page',
					'display_period' => 3600
				]
			]
		]);

		$this->assertArrayHasKey('dashboardids', $response);
		self::$dashboardid = $response['dashboardids'][0];
	}

	public function prepareProblemsData() {
		// Create hostgroup for hosts with items triggers.
		$hostgroups = CDataHelper::call('hostgroup.create', [['name' => 'Group for Problems Widgets']]);
		$this->assertArrayHasKey('groupids', $hostgroups);
		$groupid = $hostgroups['groupids'][0];

		// Create host for items and triggers.
		$hosts = CDataHelper::call('host.create', [
			'host' => 'Host for Problems Widgets',
			'groups' => [['groupid' => $groupid]]
		]);
		$this->assertArrayHasKey('hostids', $hosts);
		self::$hostid = $hosts['hostids'][0];

		// Create items on previously created host.
		$item_names = ['float', 'char', 'log', 'unsigned', 'text'];

		$items_data = [];
		foreach ($item_names as $i => $item) {
			$items_data[] = [
				'hostid' => self::$hostid,
				'name' => $item,
				'key_' => $item,
				'type' => 2,
				'value_type' => $i
			];
		}

		$items = CDataHelper::call('item.create', $items_data);
		$this->assertArrayHasKey('itemids', $items);

		// Create triggers based on items.
		$triggers = CDataHelper::call('trigger.create', [
			[
				'description' => 'Trigger for widget float',
				'expression' => 'last(/Host for Problems Widgets/float)=0',
				'priority' => 0
			],
			[
				'description' => 'Trigger for widget char',
				'expression' => 'last(/Host for Problems Widgets/char)=0',
				'priority' => 1,
				'manual_close' => 1
			],
			[
				'description' => 'Trigger for widget log',
				'expression' => 'last(/Host for Problems Widgets/log)=0',
				'priority' => 2
			],
			[
				'description' => 'Trigger for widget unsigned',
				'expression' => 'last(/Host for Problems Widgets/unsigned)=0',
				'priority' => 3
			],
			[
				'description' => 'Trigger for widget text',
				'expression' => 'last(/Host for Problems Widgets/text)=0',
				'priority' => 4
			]
		]);
		$this->assertArrayHasKey('triggerids', $triggers);
		self::$triggerids = CDataHelper::getIds('description');

		// Create events.
		$time = time();
		$i=0;
		foreach (self::$triggerids as $name => $id) {
			DBexecute('INSERT INTO events (eventid, source, object, objectid, clock, ns, value, name, severity) VALUES ('.
				(1009950 + $i).', 0, 0, '.zbx_dbstr($id).', '.$time.', 0, 1, '.zbx_dbstr($name).', '.zbx_dbstr($i).')'
			);
			$i++;
		}

		// Create problems.
		$j=0;
		foreach (self::$triggerids as $name => $id) {
			DBexecute('INSERT INTO problem (eventid, source, object, objectid, clock, ns, name, severity) VALUES ('.
				(1009950 + $j).', 0, 0, '.zbx_dbstr($id).', '.$time.', 0, '.zbx_dbstr($name).', '.zbx_dbstr($j).')'
			);
			$j++;
		}

		// Change triggers' state to Problem. Manual close is true for the problem: Trigger for widget char'.
		DBexecute('UPDATE triggers SET value = 1 WHERE description IN ('.zbx_dbstr('Trigger for widget float').', '.
				zbx_dbstr('Trigger for widget log').', '.zbx_dbstr('Trigger for widget unsigned').', '.
				zbx_dbstr('Trigger for widget text').')'
		);
		DBexecute('UPDATE triggers SET value = 1, manual_close = 1 WHERE description = '.zbx_dbstr('Trigger for widget char'));

		// Suppress the problem: 'Trigger for widget text'.
		DBexecute('INSERT INTO event_suppress (event_suppressid, eventid, maintenanceid, suppress_until) VALUES (100990, 1009954, NULL, 0)');

		// Acknowledge the problem: 'Trigger for widget unsigned' and get acknowledge time.
		CDataHelper::call('event.acknowledge', [
			'eventids' => 1009953,
			'action' => 6,
			'message' => 'Acknowledged event'
		]);

		$event = CDataHelper::call('event.get', [
			'eventids' => 1009953,
			'select_acknowledges' => ['clock']
		]);
		self::$acktime = CTestArrayHelper::get($event, '0.acknowledges.0.clock');
	}

	public static function getCheckWidgetTable() {
		return [
			// #0 Widget with empty 'Show lines' field.
			[
				[
					'fields' => [
						'Name' => 'Group filter',
						'Host groups' => 'Group for Problems Widgets'
					],
					'result' => [
						'Trigger for widget unsigned',
						'Trigger for widget log',
						'Trigger for widget char',
						'Trigger for widget float'
					]
				]
			],
			[
				[
					'fields' => [
						'Name' => 'Group, unsupressed filter',
						'Host groups' => 'Group for Problems Widgets',
						'Show suppressed problems' => true
					],
					'result' => [
						'Trigger for widget text',
						'Trigger for widget unsigned',
						'Trigger for widget log',
						'Trigger for widget char',
						'Trigger for widget float'
					]
				]
			],
			[
				[
					'fields' => [
						'Name' => 'Group, unucknowledged filter',
						'Host groups' => 'Group for Problems Widgets',
						'Show unacknowledged only' => true
					],
					'result' => [
						'Trigger for widget log',
						'Trigger for widget char',
						'Trigger for widget float'
					]
				]
			],
			[
				[
					'fields' => [
						'Name' => 'Group, unucknowledged filter',
						'Host groups' => 'Group for Problems Widgets',
						'Sort entries by' => 'Severity (ascending)'
					],
					'result' => [
						'Trigger for widget float',
						'Trigger for widget char',
						'Trigger for widget log',
						'Trigger for widget unsigned'
					]
				]
			]
		];
	}

	/**
	 * @dataProvider getCheckWidgetTable
	 */
	public function testDashboardProblemsWidgetDisplay_CheckTable($data) {
		$this->page->login()->open('zabbix.php?action=dashboard.view&dashboardid='.self::$dashboardid);
		$dashboard = CDashboardElement::find()->one();
		$form = $dashboard->edit()->addWidget()->asForm();
		$dialog = COverlayDialogElement::find()->one()->waitUntilReady();

		// Fill Problems widget filter.
		$form->fill(['Type' => CFormElement::RELOADABLE_FILL('Problems')]);
		$form->fill($data['fields']);
		$form->submit();

		// Check saved dashboard.
		$dialog->ensureNotPresent();
		$dashboard->save();
		$this->assertMessage(TEST_GOOD, 'Dashboard updated');

		// Assert Problems widget's table.
		$this->assertTableDataColumn($data['result'], 'Problem • Severity');

		// Delete created widget.
		$dashboard->edit()->deleteWidget($data['fields']['Name'])->save();
		$this->assertMessage(TEST_GOOD, 'Dashboard updated');
	}
}
