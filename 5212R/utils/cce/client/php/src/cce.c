/* $Id: cce.c                                               */
/* Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET  */
/* Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT       */
/* All Rights Reserved.                                     */
/* Modernized for PHP 8.3 by BlueOnyx Project               */
/*
 * In PHP 8, zend_register_resource() returns a resource zval. We keep that
 * native resource, but also accept the legacy numeric resource id in all
 * command wrappers so older callers keep working.
 * get_handle() uses the resource id to recover the zend_resource* from the
 * regular_list, then verifies the type.
 */

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include <php.h>
#include <php_ini.h>
#include <ext/standard/info.h>

#include <cce.h>
#include <cce_common.h>
#include <glib.h>

#include <php_cce.h>

/* ==================== Arginfo stubs (PHP 8+ requires these) ==================== */

/* ccephp_new: void -> int */
ZEND_BEGIN_ARG_INFO(arginfo_ccephp_new, 0)
ZEND_END_ARG_INFO()

/* ccephp_connect: int, string -> bool */
ZEND_BEGIN_ARG_INFO(arginfo_ccephp_connect, 0)
	ZEND_ARG_INFO(0, handle)
	ZEND_ARG_INFO(0, socket)
ZEND_END_ARG_INFO()

/* ccephp_suspended: int -> string|false */
ZEND_BEGIN_ARG_INFO(arginfo_ccephp_suspended, 0)
	ZEND_ARG_INFO(0, handle)
ZEND_END_ARG_INFO()

/* ccephp_auth: int, string, string -> string|false */
ZEND_BEGIN_ARG_INFO(arginfo_ccephp_auth, 0)
	ZEND_ARG_INFO(0, handle)
	ZEND_ARG_INFO(0, user)
	ZEND_ARG_INFO(0, pass)
ZEND_END_ARG_INFO()

/* ccephp_authkey: int, string, string -> bool */
ZEND_BEGIN_ARG_INFO(arginfo_ccephp_authkey, 0)
	ZEND_ARG_INFO(0, handle)
	ZEND_ARG_INFO(0, user)
	ZEND_ARG_INFO(0, sessionId)
ZEND_END_ARG_INFO()

/* ccephp_bye: int -> bool */
ZEND_BEGIN_ARG_INFO(arginfo_ccephp_bye, 0)
	ZEND_ARG_INFO(0, handle)
ZEND_END_ARG_INFO()

/* ccephp_begin: int -> bool */
ZEND_BEGIN_ARG_INFO(arginfo_ccephp_begin, 0)
	ZEND_ARG_INFO(0, handle)
ZEND_END_ARG_INFO()

/* ccephp_commit: int -> bool */
ZEND_BEGIN_ARG_INFO(arginfo_ccephp_commit, 0)
	ZEND_ARG_INFO(0, handle)
ZEND_END_ARG_INFO()

/* ccephp_destroy: int, int -> bool */
ZEND_BEGIN_ARG_INFO(arginfo_ccephp_destroy, 0)
	ZEND_ARG_INFO(0, handle)
	ZEND_ARG_INFO(0, oid)
ZEND_END_ARG_INFO()

/* ccephp_endkey: int -> bool */
ZEND_BEGIN_ARG_INFO(arginfo_ccephp_endkey, 0)
	ZEND_ARG_INFO(0, handle)
ZEND_END_ARG_INFO()

/* ccephp_errors: int -> array|false */
ZEND_BEGIN_ARG_INFO(arginfo_ccephp_errors, 0)
	ZEND_ARG_INFO(0, handle)
ZEND_END_ARG_INFO()

/* ccephp_get: int, int [, string] -> array|false */
ZEND_BEGIN_ARG_INFO(arginfo_ccephp_get, 0)
	ZEND_ARG_INFO(0, handle)
	ZEND_ARG_INFO(0, oid)
	ZEND_ARG_INFO(0, space)
ZEND_END_ARG_INFO()

/* ccephp_handler_get: int, int [, string] -> array|false */
ZEND_BEGIN_ARG_INFO(arginfo_ccephp_handler_get, 0)
	ZEND_ARG_INFO(0, handle)
	ZEND_ARG_INFO(0, oid)
	ZEND_ARG_INFO(0, space)
ZEND_END_ARG_INFO()

/* ccephp_find: int, string, array [, int, string] -> array|false */
ZEND_BEGIN_ARG_INFO(arginfo_ccephp_find, 0)
	ZEND_ARG_INFO(0, handle)
	ZEND_ARG_INFO(0, classname)
	ZEND_ARG_INFO(0, props)
	ZEND_ARG_INFO(0, sorttype)
	ZEND_ARG_INFO(0, sortkey)
ZEND_END_ARG_INFO()

/* ccephp_findx: int, string, array, array [, string, string] -> array|false */
ZEND_BEGIN_ARG_INFO(arginfo_ccephp_findx, 0)
	ZEND_ARG_INFO(0, handle)
	ZEND_ARG_INFO(0, classname)
	ZEND_ARG_INFO(0, props)
	ZEND_ARG_INFO(0, reprops)
	ZEND_ARG_INFO(0, sorttype)
	ZEND_ARG_INFO(0, sortkey)
ZEND_END_ARG_INFO()

/* ccephp_create: int, string, array -> int */
ZEND_BEGIN_ARG_INFO(arginfo_ccephp_create, 0)
	ZEND_ARG_INFO(0, handle)
	ZEND_ARG_INFO(0, class)
	ZEND_ARG_INFO(0, props)
ZEND_END_ARG_INFO()

/* ccephp_set: int, int, string, array -> bool */
ZEND_BEGIN_ARG_INFO(arginfo_ccephp_set, 0)
	ZEND_ARG_INFO(0, handle)
	ZEND_ARG_INFO(0, oid)
	ZEND_ARG_INFO(0, namespace)
	ZEND_ARG_INFO(0, props)
ZEND_END_ARG_INFO()

/* ccephp_names: int, mixed -> array|false */
ZEND_BEGIN_ARG_INFO(arginfo_ccephp_names, 0)
	ZEND_ARG_INFO(0, handle)
	ZEND_ARG_INFO(0, arg)
ZEND_END_ARG_INFO()

/* ccephp_whoami: int -> int */
ZEND_BEGIN_ARG_INFO(arginfo_ccephp_whoami, 0)
	ZEND_ARG_INFO(0, handle)
ZEND_END_ARG_INFO()

/* ccephp_is_rollback: int -> bool */
ZEND_BEGIN_ARG_INFO(arginfo_ccephp_is_rollback, 0)
	ZEND_ARG_INFO(0, handle)
ZEND_END_ARG_INFO()

/* ccephp_suspend: int [, string] -> bool */
ZEND_BEGIN_ARG_INFO(arginfo_ccephp_suspend, 0)
	ZEND_ARG_INFO(0, handle)
	ZEND_ARG_INFO(0, reason)
ZEND_END_ARG_INFO()

/* ccephp_resume: int -> bool */
ZEND_BEGIN_ARG_INFO(arginfo_ccephp_resume, 0)
	ZEND_ARG_INFO(0, handle)
ZEND_END_ARG_INFO()

/* ccephp_bye_handler: int, int [, string] -> bool */
ZEND_BEGIN_ARG_INFO(arginfo_ccephp_bye_handler, 0)
	ZEND_ARG_INFO(0, handle)
	ZEND_ARG_INFO(0, reason)
	ZEND_ARG_INFO(0, message)
ZEND_END_ARG_INFO()

/* ccephp_bad_data: int, int, string, string, string -> bool */
ZEND_BEGIN_ARG_INFO(arginfo_ccephp_bad_data, 0)
	ZEND_ARG_INFO(0, handle)
	ZEND_ARG_INFO(0, oid)
	ZEND_ARG_INFO(0, space)
	ZEND_ARG_INFO(0, key)
	ZEND_ARG_INFO(0, reason)
ZEND_END_ARG_INFO()

/* ---- Resource type handle ---- */
static int le_cce_handle;

/* Wrapper destructor for PHP 8 resource API */
static void php_cce_handle_dtor(zend_resource *rsrc)
{
	cce_handle_t *handle = (cce_handle_t *)rsrc->ptr;
	if (handle) {
		cce_handle_destroy(handle);
		rsrc->ptr = NULL;
	}
}

/* ---- Helper: fetch handle by resource index ---- */
static cce_handle_t *get_handle(zend_long index)
{
	zval *zv;
	zend_resource *res;
	cce_handle_t *handle;

	/* Look up the zval in the regular_list by numeric index */
	zv = zend_hash_index_find(&EG(regular_list), (zend_ulong)index);
	if (!zv) {
		php_error(E_WARNING, "Index %ld invalid", (long)index);
		return NULL;
	}

	res = Z_RES_P(zv);
	if (!res) {
		php_error(E_WARNING, "Index %ld has null resource", (long)index);
		return NULL;
	}

	if (res->type != le_cce_handle) {
		php_error(E_WARNING, "Index %ld was not of type cce handle", (long)index);
		return NULL;
	}

	handle = (cce_handle_t *)res->ptr;
	if (!handle) {
		php_error(E_WARNING, "Index %ld has null handle", (long)index);
		return NULL;
	}

	return handle;
}

/* ---- Helper: accept either a resource or a legacy numeric handle ---- */
static cce_handle_t *get_handle_from_zval(zval *handle_zv)
{
	if (Z_TYPE_P(handle_zv) == IS_LONG) {
		return get_handle(Z_LVAL_P(handle_zv));
	}

	if (Z_TYPE_P(handle_zv) == IS_RESOURCE) {
		return get_handle((zend_long) Z_RES_HANDLE_P(handle_zv));
	}

	php_error(E_WARNING, "Handle argument must be a cce resource or handle id");
	return NULL;
}

/* ---- PHP hash -> cce_props_t ---- */
static cce_props_t *php_hash_to_props(HashTable *ht)
{
	cce_props_t *props;
	zend_string *key;
	zval *val;

	props = cce_props_new();

	if (ht == NULL) {
		return props;
	}

	if (!zend_hash_num_elements(ht)) {
		return props;
	}

	ZEND_HASH_FOREACH_STR_KEY_VAL(ht, key, val) {
		if (key) {
			zend_string *val_str = zval_get_string(val);
			cce_props_set(props, ZSTR_VAL(key), ZSTR_VAL(val_str));
			zend_string_release(val_str);
		}
	} ZEND_HASH_FOREACH_END();

	return props;
}

/* ---- cce_props_t -> PHP array ---- */
static int cce_props_to_zval(cce_props_t *props, zval *php_hash)
{
	char *key, *val;
	zval old_vals;

	array_init(php_hash);

	if (props == NULL) {
		return 0;
	}

	array_init(&old_vals);

	cce_props_reinit(props);
	while ((key = cce_props_nextkey(props))) {
		val = cce_props_get(props, key);
		add_assoc_string(php_hash, key, val);
		if ((val = cce_props_get_old(props, key))) {
			add_assoc_string(&old_vals, key, val);
		}
	}

	add_assoc_zval(php_hash, "OLD", &old_vals);
	return 1;
}

/* ---- GSList of ints -> PHP array ---- */
static int glist_ints_to_zval(GSList *list, zval *z_list)
{
	array_init(z_list);

	while (list) {
		add_next_index_long(z_list, GPOINTER_TO_INT(list->data));
		list = g_slist_next(list);
	}

	return 1;
}

/* ---- GSList of strings -> PHP array ---- */
static int glist_strs_to_zval(GSList *list, zval *z_list)
{
	while (list) {
		add_next_index_string(z_list, (char *)list->data);
		list = g_slist_next(list);
	}
	return 1;
}

/* ---- GSList of errors -> PHP array ---- */
static int glist_errors_to_zval(GSList *list, zval *z_list)
{
	cce_error_t *cce_error;

	array_init(z_list);

	while (list) {
		zval error;
		array_init(&error);

		cce_error = (cce_error_t *)list->data;

		add_assoc_long(&error, "code", cce_error->code);
		add_assoc_long(&error, "oid", cce_error->oid);

		if (cce_error->key) {
			add_assoc_string(&error, "key", cce_error->key);
		}
		if (cce_error->message) {
			add_assoc_string(&error, "message", cce_error->message);
		}

		add_next_index_zval(z_list, &error);
		list = g_slist_next(list);
	}
	return 1;
}

/* ---- Module entry ---- */
zend_function_entry ccephp_functions[] = {
	PHP_FE(ccephp_auth, arginfo_ccephp_auth)
	PHP_FE(ccephp_suspend, arginfo_ccephp_suspend)
	PHP_FE(ccephp_resume, arginfo_ccephp_resume)
	PHP_FE(ccephp_authkey, arginfo_ccephp_authkey)
	PHP_FE(ccephp_bye, arginfo_ccephp_bye)
	PHP_FE(ccephp_connect, arginfo_ccephp_connect)
	PHP_FE(ccephp_suspended, arginfo_ccephp_suspended)
	PHP_FE(ccephp_begin, arginfo_ccephp_begin)
	PHP_FE(ccephp_commit, arginfo_ccephp_commit)
	PHP_FE(ccephp_create, arginfo_ccephp_create)
	PHP_FE(ccephp_destroy, arginfo_ccephp_destroy)
	PHP_FE(ccephp_endkey, arginfo_ccephp_endkey)
	PHP_FE(ccephp_errors, arginfo_ccephp_errors)
	PHP_FE(ccephp_find, arginfo_ccephp_find)
	PHP_FE(ccephp_findx, arginfo_ccephp_findx)
	PHP_FE(ccephp_get, arginfo_ccephp_get)
	PHP_FE(ccephp_names, arginfo_ccephp_names)
	PHP_FE(ccephp_new, arginfo_ccephp_new)
	PHP_FE(ccephp_set, arginfo_ccephp_set)
	PHP_FE(ccephp_whoami, arginfo_ccephp_whoami)
	PHP_FE(ccephp_is_rollback, arginfo_ccephp_is_rollback)
	PHP_FE(ccephp_handler_get, arginfo_ccephp_handler_get)
	{NULL, NULL, NULL}
};

zend_module_entry ccephp_module_entry = {
	STANDARD_MODULE_HEADER,
	"cce",
	ccephp_functions,
	PHP_MINIT(ccephp),
	NULL,
	NULL,
	NULL,
	PHP_MINFO(ccephp),
	NO_VERSION_YET,
	STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_CCE
ZEND_GET_MODULE(ccephp)
#endif

PHP_MINIT_FUNCTION(ccephp)
{
	le_cce_handle = zend_register_list_destructors_ex(
		php_cce_handle_dtor, NULL, "cce handle", module_number);
	return SUCCESS;
}

PHP_MINFO_FUNCTION(ccephp)
{
	php_info_print_table_start();
	php_info_print_table_row(2, "BlueOnyx CCE Support", "enabled");
	php_info_print_table_row(2, "Version", "0.99.6");
	php_info_print_table_end();
}

/* ==================== PHP_FE implementations ==================== */

/*
 * ccephp_new: creates a new CCE handle and returns the PHP resource.
 */
PHP_FUNCTION(ccephp_new)
{
    cce_handle_t *handle = cce_handle_new();
    RETURN_RES(zend_register_resource(handle, le_cce_handle));  // Moderne Art
}

PHP_FUNCTION(ccephp_connect)
{
	zval *handle_zv;
	char *socket;
	size_t socket_len;
	cce_handle_t *handle;

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "zs",
			&handle_zv, &socket, &socket_len) == FAILURE) {
		RETURN_FALSE;
	}

	handle = get_handle_from_zval(handle_zv);
	if (!handle) RETURN_FALSE;

	if (cce_connect_cmnd(handle, socket)) {
		RETURN_TRUE;
	} else {
		RETURN_FALSE;
	}
}

PHP_FUNCTION(ccephp_suspended)
{
	zval *handle_zv;
	cce_handle_t *handle;
	char *reason;

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "z", &handle_zv) == FAILURE) {
		RETURN_FALSE;
	}

	handle = get_handle_from_zval(handle_zv);
	if (!handle) RETURN_FALSE;

	reason = cce_suspended(handle);
	if (reason) {
		RETURN_STRING(reason);
	} else {
		RETURN_FALSE;
	}
}

PHP_FUNCTION(ccephp_auth)
{
	zval *handle_zv;
	char *user, *pass;
	size_t user_len, pass_len;
	char *sessionId;
	cce_handle_t *handle;

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "zss",
			&handle_zv, &user, &user_len, &pass, &pass_len) == FAILURE) {
		RETURN_FALSE;
	}

	handle = get_handle_from_zval(handle_zv);
	if (!handle) RETURN_FALSE;

	sessionId = cce_auth_cmnd(handle, user, pass);
	if (sessionId) {
		RETURN_STRING(sessionId);
	} else {
		RETURN_FALSE;
	}
}

PHP_FUNCTION(ccephp_authkey)
{
	zval *handle_zv;
	char *user, *sessionId;
	size_t user_len, sessionId_len;
	int ret;
	cce_handle_t *handle;

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "zss",
			&handle_zv, &user, &user_len,
			&sessionId, &sessionId_len) == FAILURE) {
		RETURN_FALSE;
	}

	handle = get_handle_from_zval(handle_zv);
	if (!handle) RETURN_FALSE;

	ret = cce_authkey_cmnd(handle, user, sessionId);
	RETURN_BOOL(ret);
}

PHP_FUNCTION(ccephp_get)
{
	zval *handle_zv;
	zend_long oid;
	char *space = NULL;
	size_t space_len = 0;
	cce_handle_t *handle;
	cce_props_t *props;

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "zl|s",
			&handle_zv, &oid, &space, &space_len) == FAILURE) {
		RETURN_FALSE;
	}

	handle = get_handle_from_zval(handle_zv);
	if (!handle) RETURN_FALSE;

	props = cce_get_cmnd(handle, oid, (space_len ? space : NULL));

	if (!cce_props_to_zval(props, return_value)) {
		RETURN_FALSE;
	}
}

PHP_FUNCTION(ccephp_handler_get)
{
	zval *handle_zv;
	zend_long oid;
	char *space = NULL;
	size_t space_len = 0;
	cce_handle_t *handle;
	cce_props_t *props;

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "zl|s",
			&handle_zv, &oid, &space, &space_len) == FAILURE) {
		RETURN_FALSE;
	}

	handle = get_handle_from_zval(handle_zv);
	if (!handle) RETURN_FALSE;

	props = cce_get_cmnd(handle, oid, (space_len ? space : NULL));

	if (!cce_props_to_zval(props, return_value)) {
		RETURN_FALSE;
	}

	switch (cce_props_state(props)) {
		case CCE_CREATED:
			add_assoc_long(return_value, "CREATED", 1);
			break;
		case CCE_DESTROYED:
			add_assoc_long(return_value, "DESTROYED", 1);
			break;
		default:
			break;
	}
}

PHP_FUNCTION(ccephp_find)
{
	zval *handle_zv;
	zval *sorttype_zv = NULL, *sortkey_zv = NULL;
	zend_long sorttype = 0;
	char *classname = NULL, *sortkey = NULL;
	size_t classname_len, sortkey_len;
	zval *props_zv;
	cce_handle_t *handle;
	cce_props_t *cce_props;
	GSList *result;

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "zsa|zz",
			&handle_zv, &classname, &classname_len,
			&props_zv,
			&sorttype_zv,
			&sortkey_zv) == FAILURE) {
		RETURN_FALSE;
	}

	handle = get_handle_from_zval(handle_zv);
	if (!handle) RETURN_FALSE;

	cce_props = php_hash_to_props(HASH_OF(props_zv));

	if (!classname || !classname_len) {
		php_error(E_WARNING, "ccephp_find: invalid class name");
		RETURN_FALSE;
	}

	if (sorttype_zv) {
		sorttype = zval_get_long(sorttype_zv);
	}
	if (sortkey_zv && Z_TYPE_P(sortkey_zv) == IS_STRING) {
		sortkey = Z_STRVAL_P(sortkey_zv);
		sortkey_len = Z_STRLEN_P(sortkey_zv);
	}

	if (sortkey && sortkey_len > 0) {
		result = cce_find_sorted_cmnd(handle, classname, cce_props,
			sortkey, sorttype);
	} else {
		result = cce_find_cmnd(handle, classname, cce_props);
	}

	if (!glist_ints_to_zval(result, return_value)) {
		php_error(E_WARNING, "Could not init return value in ccephp_find");
	}

	cce_props_destroy(cce_props);
}

PHP_FUNCTION(ccephp_findx)
{
	zval *handle_zv;
	zval *sorttype_zv = NULL, *sortkey_zv = NULL;
	char *classname = NULL;
	size_t classname_len;
	zval *props_zv, *reprops_zv;
	cce_handle_t *handle;
	cce_props_t *cce_props, *cce_reprops;
	GSList *result;
	zend_string *sorttype_str = NULL, *sortkey_str = NULL;

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "zsaa|zz",
			&handle_zv,
			&classname, &classname_len,
			&props_zv,
			&reprops_zv,
			&sorttype_zv,
			&sortkey_zv) == FAILURE) {
		RETURN_FALSE;
	}

	handle = get_handle_from_zval(handle_zv);
	if (!handle) RETURN_FALSE;

	cce_props = php_hash_to_props(HASH_OF(props_zv));
	cce_reprops = php_hash_to_props(HASH_OF(reprops_zv));

	if (!classname || !classname_len) {
		php_error(E_WARNING, "ccephp_findx: invalid class name");
		RETURN_FALSE;
	}

	if (sorttype_zv) {
		sorttype_str = zval_get_string(sorttype_zv);
	}
	if (sortkey_zv) {
		sortkey_str = zval_get_string(sortkey_zv);
	}

	result = cce_findx_cmnd(handle, classname, cce_props, cce_reprops,
		sorttype_str ? ZSTR_VAL(sorttype_str) : NULL,
		sortkey_str ? ZSTR_VAL(sortkey_str) : NULL);

	if (!glist_ints_to_zval(result, return_value)) {
		php_error(E_WARNING, "Could not init return value in ccephp_findx");
	}

	if (sorttype_str) {
		zend_string_release(sorttype_str);
	}
	if (sortkey_str) {
		zend_string_release(sortkey_str);
	}

	cce_props_destroy(cce_props);
	cce_props_destroy(cce_reprops);
}

PHP_FUNCTION(ccephp_begin)
{
	zval *handle_zv;
	cce_handle_t *handle;

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "z", &handle_zv) == FAILURE) {
		RETURN_FALSE;
	}

	handle = get_handle_from_zval(handle_zv);
	if (!handle) RETURN_FALSE;

	RETURN_BOOL(cce_begin_cmnd(handle));
}

PHP_FUNCTION(ccephp_commit)
{
	zval *handle_zv;
	cce_handle_t *handle;

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "z", &handle_zv) == FAILURE) {
		RETURN_FALSE;
	}

	handle = get_handle_from_zval(handle_zv);
	if (!handle) RETURN_FALSE;

	RETURN_BOOL(cce_commit_cmnd(handle));
}

PHP_FUNCTION(ccephp_destroy)
{
	zval *handle_zv;
	zend_long oid;
	cce_handle_t *handle;

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "zl", &handle_zv, &oid) == FAILURE) {
		RETURN_FALSE;
	}

	handle = get_handle_from_zval(handle_zv);
	if (!handle) RETURN_FALSE;

	RETURN_BOOL(cce_destroy_cmnd(handle, oid));
}

PHP_FUNCTION(ccephp_errors)
{
	zval *handle_zv;
	cce_handle_t *handle;
	GSList *errors;

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "z", &handle_zv) == FAILURE) {
		RETURN_FALSE;
	}

	handle = get_handle_from_zval(handle_zv);
	if (!handle) RETURN_FALSE;

	errors = cce_last_errors_cmnd(handle);
	if (!glist_errors_to_zval(errors, return_value)) {
		RETURN_FALSE;
	}
}

PHP_FUNCTION(ccephp_create)
{
	zval *handle_zv;
	char *class_str = NULL;
	size_t class_len = 0;
	zval *z_props;
	cce_handle_t *handle;
	cce_props_t *props;
	cscp_oid_t oid;

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "zs!a",
			&handle_zv, &class_str, &class_len, &z_props) == FAILURE) {
		RETURN_FALSE;
	}

	handle = get_handle_from_zval(handle_zv);
	if (!handle) RETURN_FALSE;

	props = php_hash_to_props(HASH_OF(z_props));
	oid = cce_create_cmnd(handle, class_str, props);
	cce_props_destroy(props);

	RETURN_LONG((zend_long)oid);
}

PHP_FUNCTION(ccephp_set)
{
	zval *handle_zv;
	zend_long oid;
	char *namespace_str = NULL;
	size_t namespace_len = 0;
	zval *z_props;
	cce_handle_t *handle;
	cce_props_t *props;
	int ret;

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "zls!a",
			&handle_zv, &oid,
			&namespace_str, &namespace_len,
			&z_props) == FAILURE) {
		RETURN_FALSE;
	}

	handle = get_handle_from_zval(handle_zv);
	if (!handle) RETURN_FALSE;

	props = php_hash_to_props(HASH_OF(z_props));
	ret = cce_set_cmnd(handle, oid,
		(namespace_len ? namespace_str : NULL), props);
	cce_props_destroy(props);

	RETURN_BOOL(ret);
}

PHP_FUNCTION(ccephp_names)
{
	zval *handle_zv;
	zval *arg;
	cce_handle_t *handle;
	GSList *result;

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "zz", &handle_zv, &arg) == FAILURE) {
		RETURN_FALSE;
	}

	handle = get_handle_from_zval(handle_zv);
	if (!handle) RETURN_FALSE;

	if (Z_TYPE_P(arg) == IS_STRING) {
		result = cce_names_class_cmnd(handle, Z_STRVAL_P(arg));
	} else if (Z_TYPE_P(arg) == IS_LONG) {
		result = cce_names_oid_cmnd(handle, Z_LVAL_P(arg));
	} else {
		php_error(E_WARNING,
			"Second arg passed to cce names must be a long or a string.");
		RETURN_FALSE;
	}

	array_init(return_value);
	if (!glist_strs_to_zval(result, return_value)) {
		RETURN_FALSE;
	}
}

PHP_FUNCTION(ccephp_bye)
{
	zval *handle_zv;
	cce_handle_t *handle;

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "z", &handle_zv) == FAILURE) {
		RETURN_FALSE;
	}

	handle = get_handle_from_zval(handle_zv);
	if (!handle) RETURN_FALSE;

	RETURN_BOOL(cce_bye_cmnd(handle));
}

PHP_FUNCTION(ccephp_endkey)
{
	zval *handle_zv;
	cce_handle_t *handle;

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "z", &handle_zv) == FAILURE) {
		RETURN_FALSE;
	}

	handle = get_handle_from_zval(handle_zv);
	if (!handle) RETURN_FALSE;

	RETURN_BOOL(cce_endkey_cmnd(handle));
}

PHP_FUNCTION(ccephp_whoami)
{
	zval *handle_zv;
	cce_handle_t *handle;

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "z", &handle_zv) == FAILURE) {
		RETURN_FALSE;
	}

	handle = get_handle_from_zval(handle_zv);
	if (!handle) RETURN_FALSE;

	RETURN_LONG((zend_long)cce_whoami_cmnd(handle));
}

PHP_FUNCTION(ccephp_bye_handler)
{
	zval *handle_zv;
	zend_long reason;
	char *message = NULL;
	size_t message_len = 0;
	cce_handle_t *handle;

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "zl|s",
			&handle_zv, &reason, &message, &message_len) == FAILURE) {
		RETURN_FALSE;
	}

	handle = get_handle_from_zval(handle_zv);
	if (!handle) RETURN_FALSE;

	RETURN_BOOL(cce_bye_handler_cmnd(handle, reason,
		(message_len ? message : NULL)));
}

PHP_FUNCTION(ccephp_bad_data)
{
	zval *handle_zv;
	zend_long oid;
	char *space, *key, *reason_str;
	size_t space_len, key_len, reason_len;
	cce_handle_t *handle;

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "zlsss",
			&handle_zv, &oid,
			&space, &space_len,
			&key, &key_len,
			&reason_str, &reason_len) == FAILURE) {
		RETURN_FALSE;
	}

	handle = get_handle_from_zval(handle_zv);
	if (!handle) RETURN_FALSE;

	RETURN_BOOL(cce_bad_data_cmnd(handle, oid, space, key, reason_str));
}

PHP_FUNCTION(ccephp_suspend)
{
	zval *handle_zv;
	char *reason = NULL;
	size_t reason_len = 0;
	cce_handle_t *handle;

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "z|s",
			&handle_zv, &reason, &reason_len) == FAILURE) {
		RETURN_FALSE;
	}

	handle = get_handle_from_zval(handle_zv);
	if (!handle) RETURN_FALSE;

	if (cce_admin_cmnd(handle, "SUSPEND", (reason_len ? reason : NULL))) {
		RETURN_TRUE;
	} else {
		RETURN_FALSE;
	}
}

PHP_FUNCTION(ccephp_resume)
{
	zval *handle_zv;
	cce_handle_t *handle;

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "z", &handle_zv) == FAILURE) {
		RETURN_FALSE;
	}

	handle = get_handle_from_zval(handle_zv);
	if (!handle) RETURN_FALSE;

	if (cce_admin_cmnd(handle, "RESUME", NULL)) {
		RETURN_TRUE;
	} else {
		RETURN_FALSE;
	}
}

PHP_FUNCTION(ccephp_is_rollback)
{
	zval *handle_zv;
	cce_handle_t *handle;

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "z", &handle_zv) == FAILURE) {
		RETURN_FALSE;
	}

	handle = get_handle_from_zval(handle_zv);
	if (!handle) RETURN_FALSE;

	RETURN_BOOL(cce_is_rollback(handle));
}

/*
Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
All Rights Reserved.

1. Redistributions of source code must retain the above copyright 
   notice, this list of conditions and the following disclaimer.

2. Redistributions in binary form must reproduce the above copyright 
   notice, this list of conditions and the following disclaimer in 
   the documentation and/or other materials provided with the 
   distribution.

3. Neither the name of the copyright holder nor the names of its 
   contributors may be used to endorse or promote products derived 
   from this software without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS 
"AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT 
LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS 
FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE 
COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, 
INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, 
BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; 
LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER 
CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT 
LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN 
ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE 
POSSIBILITY OF SUCH DAMAGE.

You acknowledge that this software is not designed or intended for 
use in the design, construction, operation or maintenance of any 
nuclear facility.

*/
