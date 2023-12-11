/*
** Zabbix
** Copyright (C) 2001-2023 Zabbix SIA
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

#ifndef ZABBIX_PROXY_GROUP_H
#define ZABBIX_PROXY_GROUP_H

#include "zbxcommon.h"
#include "dbsync.h"

void	dc_sync_proxy_group(zbx_dbsync_t *sync, zbx_uint64_t revision);
void	dc_sync_host_proxy(zbx_dbsync_t *sync, zbx_uint64_t revision);
void	dc_update_host_proxy(const char *host_old, const char *host_new);
int	dc_get_host_redirect(const char *host, zbx_comms_redirect_t *redirect);

#endif
