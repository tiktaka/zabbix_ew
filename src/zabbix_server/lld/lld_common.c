/*
** Copyright (C) 2001-2024 Zabbix SIA
**
** This program is free software: you can redistribute it and/or modify it under the terms of
** the GNU Affero General Public License as published by the Free Software Foundation, version 3.
**
** This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
** without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
** See the GNU Affero General Public License for more details.
**
** You should have received a copy of the GNU Affero General Public License along with this program.
** If not, see <https://www.gnu.org/licenses/>.
**/

#include "lld.h"

#include "zbxcommon.h"
#include "zbxdbhigh.h"
#include "audit/zbxaudit.h"
#include "zbxdb.h"
#include "zbxnum.h"
#include "../server_constants.h"

ZBX_VECTOR_DECL(id_name_pair, zbx_id_name_pair_t)
ZBX_VECTOR_IMPL(id_name_pair, zbx_id_name_pair_t)
ZBX_VECTOR_IMPL(lld_discovery_ptr, zbx_lld_discovery_t *)

int	lld_ids_names_compare_func(const void *d1, const void *d2)
{
	const zbx_id_name_pair_t	*id_name_pair_entry_1 = (const zbx_id_name_pair_t *)d1;
	const zbx_id_name_pair_t	*id_name_pair_entry_2 = (const zbx_id_name_pair_t *)d2;

	ZBX_RETURN_IF_NOT_EQUAL(id_name_pair_entry_1->id, id_name_pair_entry_2->id);

	return 0;
}

void	lld_field_str_rollback(char **field, char **field_orig, zbx_uint64_t *flags, zbx_uint64_t flag)
{
	if (0 == (*flags & flag))
		return;

	zbx_free(*field);
	*field = *field_orig;
	*field_orig = NULL;
	*flags &= ~flag;
}

/******************************************************************************
 *                                                                            *
 * Purpose: calculates when to delete lost resources in overflow-safe way     *
 *                                                                            *
 ******************************************************************************/
int	lld_end_of_life(int lastcheck, int lifetime)
{
	return ZBX_JAN_2038 - lastcheck > lifetime ? lastcheck + lifetime : ZBX_JAN_2038;
}

static int	lld_get_lifetime_ts(int obj_lastcheck, const zbx_lld_lifetime_t *lifetime)
{
	int	ts;

	if (ZBX_LLD_LIFETIME_TYPE_AFTER == lifetime->type)
		ts = lld_end_of_life(obj_lastcheck, lifetime->duration);
	else if (ZBX_LLD_LIFETIME_TYPE_IMMEDIATELY == lifetime->type)
		ts = 1;
	else
		ts = 0;

	return ts;
}

static int	lld_check_lifetime_elapsed(int lastcheck, int ts)
{
	if (0 == ts || lastcheck <= ts)
		return FAIL;

	return SUCCEED;
}

/******************************************************************************
 *                                                                            *
 * Purpose: add new discovery record                                          *
 *                                                                            *
 * Parameters: discoveries - [OUT] discovery records                          *
 *             id          - [IN] object id                                   *
 *             name        - [IN] object name                                 *
 *                                                                            *
 * Return value: added discovery record                                       *
 *                                                                            *
 ******************************************************************************/
zbx_lld_discovery_t	*lld_add_discovery(zbx_hashset_t *discoveries, zbx_uint64_t id, const char *name)
{
	zbx_lld_discovery_t	discovery_local = {.id = id, .name = name, .flags = ZBX_LLD_DISCOVERY_UPDATE_NONE};

	return (zbx_lld_discovery_t *)zbx_hashset_insert(discoveries, &discovery_local, sizeof(discovery_local));
}

/******************************************************************************
 *                                                                            *
 * Purpose: update fields for discovered objects                              *
 *                                                                            *
 * Parameters: discovery        - [IN] object discovery record                *
 *             discovery_status - [IN] current discovery status               *
 *             ts_delete        - [IN] current object removal time            *
 *                                                                            *
 ******************************************************************************/
void	lld_process_discovered_object(zbx_lld_discovery_t *discovery, unsigned char discovery_status, int ts_delete)
{
	discovery->flags |= ZBX_LLD_DISCOVERY_UPDATE_LASTCHECK;

	if (ZBX_LLD_DISCOVERY_STATUS_NORMAL != discovery_status)
	{
		discovery->flags |= ZBX_LLD_DISCOVERY_UPDATE_DISCOVERY_STATUS;
		discovery->discovery_status = ZBX_LLD_DISCOVERY_STATUS_NORMAL;
	}

	if (0 != ts_delete)
	{
		discovery->flags |= ZBX_LLD_DISCOVERY_UPDATE_TS_DELETE;
		discovery->ts_delete = 0;
	}
}

/******************************************************************************
 *                                                                            *
 * Purpose: update fields for discovered objects that were disabled because   *
 *          being lost in last discovery processing                           *
 *                                                                            *
 * Parameters: discovery      - [IN] object discovery record                  *
 *             object_status  - [IN] current object status (enabled/disabled) *
 *             disable_source - [IN] lld object disabling status              *
 *             ts_disable     - [IN] current object disable time              *
 *                                                                            *
 ******************************************************************************/
void	lld_enable_discovered_object(zbx_lld_discovery_t *discovery, unsigned char object_status,
		unsigned char disable_source, int ts_disable)
{
	if (ZBX_LLD_OBJECT_STATUS_DISABLED == object_status && ZBX_DISABLE_SOURCE_LLD_LOST == disable_source)
	{
		discovery->flags |= ZBX_LLD_DISCOVERY_UPDATE_DISABLE_SOURCE | ZBX_LLD_DISCOVERY_UPDATE_OBJECT_STATUS;
		discovery->disable_source = ZBX_DISABLE_SOURCE_DEFAULT;
		discovery->object_status = ZBX_LLD_OBJECT_STATUS_ENABLED;
	}

	if (0 != ts_disable)
	{
		discovery->flags |= ZBX_LLD_DISCOVERY_UPDATE_TS_DISABLE;
		discovery->ts_disable = 0;
	}
}

/******************************************************************************
 *                                                                            *
 * Purpose: update fields for lost objects                                    *
 *                                                                            *
 * Parameters: discovery        - [IN] object discovery record                *
 *             object_status    - [IN] current object status                  *
 *             lastcheck        - [IN] last time object was discovered        *
 *             now              - [IN] current timestamp                      *
 *             lifetime         - [IN] period how long lost resources are     *
 *                                     kept                                   *
 *             discovery_status - [IN] current discovery status               *
 *             disable_source   - [IN] lld object disabling status            *
 *             ts_delete        - [IN] current object removal time            *
 *                                                                            *
 ******************************************************************************/
void	lld_process_lost_object(zbx_lld_discovery_t *discovery, unsigned char object_status, int lastcheck, int now,
		const zbx_lld_lifetime_t *lifetime, unsigned char discovery_status, int disable_source, int ts_delete)
{
	int	ts;

	ts = lld_get_lifetime_ts(lastcheck, lifetime);

	if (ts != ts_delete)
	{
		discovery->flags |= ZBX_LLD_DISCOVERY_UPDATE_TS_DELETE;
		discovery->ts_delete = ts;
	}

	if (ZBX_LLD_DISCOVERY_STATUS_LOST != discovery_status)
	{
		discovery->flags |= ZBX_LLD_DISCOVERY_UPDATE_DISCOVERY_STATUS;
		discovery->discovery_status = ZBX_LLD_DISCOVERY_STATUS_LOST;
	}

	if (SUCCEED == lld_check_lifetime_elapsed(now, ts))
	{
		if (ZBX_LLD_OBJECT_STATUS_ENABLED == object_status || ZBX_DISABLE_SOURCE_LLD_LOST == disable_source)
			discovery->flags |= ZBX_LLD_DISCOVERY_DELETE_OBJECT;
	}
}

/******************************************************************************
 *                                                                            *
 * Purpose: update fields for lost objects that must be disabled              *
 *                                                                            *
 * Parameters: discovery        - [IN] object discovery record                *
 *             object_status    - [IN] current object status                  *
 *             lastcheck        - [IN] last time object was discovered        *
 *             now              - [IN] current timestamp                      *
 *             lifetime         - [IN] period how long lost resources are     *
 *                                     kept enabled                           *
 *             ts_disable       - [IN] current object removal time            *
 *                                                                            *
 ******************************************************************************/
void	lld_disable_lost_object(zbx_lld_discovery_t *discovery, unsigned char object_status, int lastcheck, int now,
		const zbx_lld_lifetime_t *lifetime, int ts_disable)
{
	int	ts;

	ts = lld_get_lifetime_ts(lastcheck, lifetime);

	if (ts != ts_disable)
	{
		discovery->flags |= ZBX_LLD_DISCOVERY_UPDATE_TS_DISABLE;
		discovery->ts_disable = ts;
	}

	if (SUCCEED != lld_check_lifetime_elapsed(now, ts))
		return;

	if (ZBX_LLD_OBJECT_STATUS_ENABLED == object_status)
	{
		discovery->flags |= ZBX_LLD_DISCOVERY_UPDATE_DISABLE_SOURCE | ZBX_LLD_DISCOVERY_UPDATE_OBJECT_STATUS;
		discovery->disable_source = ZBX_DISABLE_SOURCE_LLD_LOST;
		discovery->object_status = ZBX_LLD_OBJECT_STATUS_DISABLED;
	}
}

/******************************************************************************
 *                                                                            *
 * Purpose: lock objects with pending status updates in database and check    *
 *          their actual statuses there                                       *
 *                                                                            *
 * Parameters: discoveries      - [IN] object discoveries                     *
 *             upd_ids          - [IN] ids of objects to be updated           *
 *             id_field         - [IN] object id field name                   *
 *             object_table     - [IN] object table name                      *
 *                                                                            *
 * Comments: If object doesn't need to be updated or has been removed the     *
 *           corresponding discovery flags are reset.                         *
 *                                                                            *
 ******************************************************************************/
static void	lld_check_objects_in_db(zbx_hashset_t *discoveries, zbx_vector_uint64_t *upd_ids,
				const char *id_field, const char *object_table)
{
	zbx_db_result_t		result;
	zbx_db_row_t		row;
	char			*sql = NULL;
	size_t			sql_alloc = 0, sql_offset = 0;
	zbx_lld_discovery_t	*discovery;

	zbx_snprintf_alloc(&sql, &sql_alloc, &sql_offset, "select %s,status from %s where",
			id_field, object_table );

	zbx_db_add_condition_alloc(&sql, &sql_alloc, &sql_offset, id_field, upd_ids->values, upd_ids->values_num);
	zbx_strcpy_alloc(&sql, &sql_alloc, &sql_offset, ZBX_FOR_UPDATE);

	result = zbx_db_select("%s", sql);
	zbx_free(sql);

	while (NULL != (row = zbx_db_fetch(result)))
	{
		zbx_uint64_t	id;
		unsigned char	value;

		ZBX_STR2UINT64(id, row[0]);

		if (NULL == (discovery = (zbx_lld_discovery_t *)zbx_hashset_search(discoveries, &id)))
		{
			THIS_SHOULD_NEVER_HAPPEN;
			continue;
		}

		discovery->flags |= ZBX_LLD_DISCOVERY_UPDATE_OBJECT_EXISTS;

		if (0 != (discovery->flags & ZBX_LLD_DISCOVERY_UPDATE_OBJECT_STATUS))
		{
			ZBX_STR2UCHAR(value, row[1]);
			if (value == discovery->object_status)
				discovery->flags &= ~ZBX_LLD_DISCOVERY_UPDATE_OBJECT_STATUS;
		}
	}
	zbx_db_free_result(result);

	/* reset discovery flags for already removed objects */
	for (int i = 0; i < upd_ids->values_num; i++)
	{
		if (NULL == (discovery = (zbx_lld_discovery_t *)zbx_hashset_search(discoveries, &upd_ids->values[i])))
		{
			THIS_SHOULD_NEVER_HAPPEN;
			continue;
		}

		if (0 == (discovery->flags & ZBX_LLD_DISCOVERY_UPDATE_OBJECT_EXISTS))
			discovery->flags = 0;
	}
}

/******************************************************************************
 *                                                                            *
 * Purpose: flush pending discovery record updates to database                *
 *                                                                            *
 * Parameters: discoveries            - [IN] object discoveries               *
 *             id_field               - [IN] object id field name             *
 *             object_table           - [IN] object table name                *
 *                                           (can be NULL if object status    *
 *                                           is not changed)                  *
 *             discovery_table        - [IN] object discovery record table    *
 *             now                    - [IN] current timestamp                *
 *             cb_status              - [IN] function to convert common lld   *
 *                                           status to object specific status *
 *                                           (can be NULL if object status    *
 *                                           is not changed)                  *
 *             cb_delete_objects      - [IN] function to delete objects by    *
 *                                           their ids                        *
 *             cb_audit_create        - [IN] function to create object audit  *
 *                                           entry                            *
 *             cb_audit_update_status - [IN] function to update object status *
 *                                           change in audit                  *
 *                                           (can be NULL if object status    *
 *                                           is not changed)                  *
 *                                                                            *
 ******************************************************************************/
void	lld_flush_discoveries(zbx_hashset_t *discoveries, const char *id_field, const char *object_table,
		const char *discovery_table, int now, get_object_status_val cb_status, delete_ids_f cb_delete_objects,
		object_audit_entry_create_f cb_audit_create, object_audit_entry_update_status_f cb_audit_update_status)
{
	int				updates_num = 0;
	zbx_vector_uint64_t		upd_ids, del_ids, upd_ts;
	zbx_vector_lld_discovery_ptr_t	object_updates, discovery_updates;
	zbx_hashset_iter_t		iter;
	zbx_lld_discovery_t		*discovery;
	char				*sql = NULL;
	size_t				sql_alloc = 0, sql_offset = 0;

	zabbix_log(LOG_LEVEL_DEBUG, "In %s()", __func__);

	zbx_vector_lld_discovery_ptr_create(&object_updates);
	zbx_vector_lld_discovery_ptr_create(&discovery_updates);
	zbx_vector_uint64_create(&upd_ids);
	zbx_vector_uint64_create(&del_ids);
	zbx_vector_uint64_create(&upd_ts);

	zbx_hashset_iter_reset(discoveries, &iter);
	while (NULL != (discovery = (zbx_lld_discovery_t *)zbx_hashset_iter_next(&iter)))
	{
		if (0 == discovery->flags || ZBX_LLD_DISCOVERY_DELETE_OBJECT == discovery->flags)
			continue;

		if (0 != (discovery->flags & (ZBX_LLD_DISCOVERY_UPDATE_OBJECT_STATUS)))
			zbx_vector_uint64_append(&upd_ids, discovery->id);

		updates_num++;
	}

	if (0 == updates_num)
		goto out;

	zbx_db_begin();

	/* lock object table rows and double check if they need to be updated */
	if (0 != upd_ids.values_num)
	{
		zbx_vector_uint64_sort(&upd_ids, ZBX_DEFAULT_UINT64_COMPARE_FUNC);
		lld_check_objects_in_db(discoveries, &upd_ids, id_field, object_table);
	}

	/* prepare updates */
	zbx_hashset_iter_reset(discoveries, &iter);
	while (NULL != (discovery = (zbx_lld_discovery_t *)zbx_hashset_iter_next(&iter)))
	{
		if (0 != (discovery->flags & ZBX_LLD_DISCOVERY_DELETE_OBJECT))
		{
			cb_audit_create(ZBX_AUDIT_LLD_CONTEXT, ZBX_AUDIT_ACTION_DELETE, discovery->id, discovery->name,
					(int)ZBX_FLAG_DISCOVERY_CREATED);
			zbx_vector_uint64_append(&del_ids, discovery->id);

			continue;
		}

		if (0 != (discovery->flags & ZBX_LLD_DISCOVERY_UPDATE_OBJECT_STATUS))
			zbx_vector_lld_discovery_ptr_append(&object_updates, discovery);

		if (0 != (discovery->flags & ZBX_LLD_DISCOVERY_UPDATE))
		{
			if (ZBX_LLD_DISCOVERY_UPDATE_LASTCHECK == (discovery->flags & ZBX_LLD_DISCOVERY_UPDATE))
				zbx_vector_uint64_append(&upd_ts, discovery->id);
			else
				zbx_vector_lld_discovery_ptr_append(&discovery_updates, discovery);
		}
	}

	if (0 != del_ids.values_num)
	{
		zbx_vector_uint64_sort(&del_ids, ZBX_DEFAULT_UINT64_COMPARE_FUNC);
		cb_delete_objects(&del_ids, ZBX_AUDIT_LLD_CONTEXT);
	}

	if (0 == object_updates.values_num && 0 == discovery_updates.values_num && 0 == upd_ts.values_num)
		goto commit;

	zbx_db_begin_multiple_update(&sql, &sql_alloc, &sql_offset);

	zbx_vector_lld_discovery_ptr_sort(&object_updates, ZBX_DEFAULT_UINT64_PTR_COMPARE_FUNC);
	for (int i = 0; i < object_updates.values_num; i++)
	{
		discovery = object_updates.values[i];

		zbx_snprintf_alloc(&sql, &sql_alloc, &sql_offset, "update %s set status=%d where %s=" ZBX_FS_UI64 ";\n",
				object_table, cb_status(discovery->object_status), id_field, discovery->id);
		zbx_db_execute_overflowed_sql(&sql, &sql_alloc, &sql_offset);

		unsigned char	old_status = ZBX_LLD_OBJECT_STATUS_ENABLED == discovery->object_status ?
						ZBX_LLD_OBJECT_STATUS_DISABLED : ZBX_LLD_OBJECT_STATUS_ENABLED;

		cb_audit_create(ZBX_AUDIT_LLD_CONTEXT, ZBX_AUDIT_ACTION_UPDATE, discovery->id,
				discovery->name, (int)ZBX_FLAG_DISCOVERY_CREATED);

		cb_audit_update_status(ZBX_AUDIT_LLD_CONTEXT, discovery->id, (int)ZBX_FLAG_DISCOVERY_CREATED,
				cb_status(old_status), cb_status(discovery->object_status));
	}

	zbx_vector_lld_discovery_ptr_sort(&discovery_updates, ZBX_DEFAULT_UINT64_PTR_COMPARE_FUNC);
	for (int i = 0; i < discovery_updates.values_num; i++)
	{
		char	delim = ' ';

		discovery = discovery_updates.values[i];

		zbx_snprintf_alloc(&sql, &sql_alloc, &sql_offset, "update %s set", discovery_table);

		if (0 != (discovery->flags & ZBX_LLD_DISCOVERY_UPDATE_LASTCHECK))
		{
			zbx_snprintf_alloc(&sql, &sql_alloc, &sql_offset, "%clastcheck=%d", delim, now);
			delim = ',';
		}

		if (0 != (discovery->flags & ZBX_LLD_DISCOVERY_UPDATE_DISCOVERY_STATUS))
		{
			zbx_snprintf_alloc(&sql, &sql_alloc, &sql_offset, "%cstatus=%u", delim,
					discovery->discovery_status);
			delim = ',';
		}

		if (0 != (discovery->flags & ZBX_LLD_DISCOVERY_UPDATE_DISABLE_SOURCE))
		{
			zbx_snprintf_alloc(&sql, &sql_alloc, &sql_offset, "%cdisable_source=%u", delim,
					discovery->disable_source);
			delim = ',';
		}

		if (0 != (discovery->flags & ZBX_LLD_DISCOVERY_UPDATE_TS_DELETE))
		{
			zbx_snprintf_alloc(&sql, &sql_alloc, &sql_offset, "%cts_delete=%d", delim,
					discovery->ts_delete);
			delim = ',';
		}

		if (0 != (discovery->flags & ZBX_LLD_DISCOVERY_UPDATE_TS_DISABLE))
		{
			zbx_snprintf_alloc(&sql, &sql_alloc, &sql_offset, "%cts_disable=%d", delim,
					discovery->ts_disable);
		}

		zbx_snprintf_alloc(&sql, &sql_alloc, &sql_offset, " where %s=" ZBX_FS_UI64 ";\n",
				id_field, discovery->id);
		zbx_db_execute_overflowed_sql(&sql, &sql_alloc, &sql_offset);
	}

	zbx_db_end_multiple_update(&sql, &sql_alloc, &sql_offset);

	if (16 < sql_offset)	/* in ORACLE always present begin..end; */
		zbx_db_execute("%s", sql);

	if (0 != upd_ts.values_num)
	{
		zbx_vector_uint64_sort(&upd_ts, ZBX_DEFAULT_UINT64_COMPARE_FUNC);
		zbx_snprintf_alloc(&sql, &sql_alloc, &sql_offset, "update %s set lastcheck=%d where",
				discovery_table, now);
		zbx_db_execute_multiple_query(sql, id_field, &upd_ts);
	}

	zbx_free(sql);
commit:
	zbx_db_commit();
out:
	zbx_vector_uint64_destroy(&upd_ts);
	zbx_vector_uint64_destroy(&del_ids);
	zbx_vector_uint64_destroy(&upd_ids);
	zbx_vector_lld_discovery_ptr_destroy(&discovery_updates);
	zbx_vector_lld_discovery_ptr_destroy(&object_updates);

	zabbix_log(LOG_LEVEL_DEBUG, "End of %s()", __func__);
}
