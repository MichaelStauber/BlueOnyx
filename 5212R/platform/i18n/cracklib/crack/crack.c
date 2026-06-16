/*
   +----------------------------------------------------------------------+
   | PHP Version 4                                                        |
   +----------------------------------------------------------------------+
   | Copyright (c) 1997-2005 The PHP Group                                |
   +----------------------------------------------------------------------+
   | This source file is subject to version 3.0 of the PHP license,       |
   | that is bundled with this package in the file LICENSE, and is        |
   | available through the world-wide-web at the following url:           |
   | http://www.php.net/license/3_0.txt.                                  |
   | If you did not receive a copy of the PHP license and are unable to   |
   | obtain it through the world-wide-web, please send a note to          |
   | license@php.net so we can mail you a copy immediately.               |
   +----------------------------------------------------------------------+
   | Authors: Alexander Feldman                                           |
   |          Sascha Kettler                                              |
   +----------------------------------------------------------------------+
 */
/* $Id: crack.c,v 1.26 2005/09/21 08:59:57 skettler Exp $ */

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "php_ini.h"
#include "php_globals.h"
#include "ext/standard/info.h"
#include "ext/standard/php_string.h"

#if HAVE_CRACK

#include "php_crack.h"
#include "libcrack/src/cracklib.h"

/* True global resources - no need for thread safety here */
static int le_crack;

ZEND_BEGIN_ARG_INFO_EX(crack_opendict_args, 0, ZEND_RETURN_VALUE, 1)
	ZEND_ARG_INFO(0, dictionary)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(crack_closedict_args, 0, ZEND_RETURN_VALUE, 0)
	ZEND_ARG_INFO(0, dictionary)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(crack_check_args, 0, ZEND_RETURN_VALUE, 1)
	ZEND_ARG_INFO(0, password)
	ZEND_ARG_INFO(0, username)
	ZEND_ARG_INFO(0, gecos)
	ZEND_ARG_INFO(0, dictionary)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(crack_getlastmessage_args, 0, ZEND_RETURN_VALUE, 0)
ZEND_END_ARG_INFO()

/* {{{ crack_functions[]
 */
zend_function_entry crack_functions[] = {
	ZEND_FE(crack_opendict,			crack_opendict_args)
	ZEND_FE(crack_closedict,			crack_closedict_args)
	ZEND_FE(crack_check,				crack_check_args)
	ZEND_FE(crack_getlastmessage,	crack_getlastmessage_args)
	{NULL, NULL, NULL}
};
/* }}} */

/* {{{ crack_module_entry
 */
zend_module_entry crack_module_entry = {
    STANDARD_MODULE_HEADER,
	"crack",
	crack_functions,
	PHP_MINIT(crack),
	PHP_MSHUTDOWN(crack),
	PHP_RINIT(crack),
	PHP_RSHUTDOWN(crack),
	PHP_MINFO(crack),
	"0.4",
	STANDARD_MODULE_PROPERTIES,
};
/* }}} */

ZEND_DECLARE_MODULE_GLOBALS(crack)

#ifdef COMPILE_DL_CRACK
ZEND_GET_MODULE(crack)
#endif

/* {{{ PHP_INI */
PHP_INI_BEGIN()
	STD_PHP_INI_ENTRY("crack.default_dictionary", NULL, PHP_INI_PERDIR|PHP_INI_SYSTEM, OnUpdateString, default_dictionary, zend_crack_globals, crack_globals)
PHP_INI_END()
/* }}} */

/* {{{ php_crack_init_globals
 */
static void php_crack_init_globals(zend_crack_globals *crack_globals)
{
	crack_globals->last_message = NULL;
	crack_globals->default_dict = NULL;
}
/* }}} */

/* {{{ php_crack_checkpath
 */
static int php_crack_checkpath(char* path)
{
	char *filename;
	int filename_len;
	int result = SUCCESS;

	if (php_check_open_basedir(path)) {
		return FAILURE;
	}

	return SUCCESS;
}
/* }}} */

/* {{{ php_crack_set_default_dict
 */
static void php_crack_set_default_dict(zend_resource *id)
{
	if (CRACKG(default_dict) != NULL) {
		zend_list_close(CRACKG(default_dict));
	}

	CRACKG(default_dict) = id;
	id->gc.refcount++;
}
/* }}} */

/* {{{ php_crack_get_default_dict
 */
static zend_resource * php_crack_get_default_dict(INTERNAL_FUNCTION_PARAMETERS)
{
	if ((NULL == CRACKG(default_dict)) && (NULL != CRACKG(default_dictionary))) {
		CRACKLIB_PWDICT *pwdict;
		printf("trying to open: %s\n", CRACKG(default_dictionary));
		pwdict = cracklib_pw_open(CRACKG(default_dictionary), "r");
		if (NULL != pwdict) {
			ZVAL_RES(return_value, zend_register_resource(pwdict, le_crack));
			php_crack_set_default_dict(Z_RES_P(return_value));
		}
	}

	return CRACKG(default_dict);
}
/* }}} */

/* {{{ php_crack_module_dtor
 */
static void php_crack_module_dtor(zend_resource *rsrc)
{
	CRACKLIB_PWDICT *pwdict = (CRACKLIB_PWDICT *) rsrc->ptr;

	if (pwdict != NULL) {
		cracklib_pw_close(pwdict);
	}
}
/* }}} */

/* {{{ PHP_MINIT_FUNCTION
 */
PHP_MINIT_FUNCTION(crack)
{
#ifdef ZTS
	ZEND_INIT_MODULE_GLOBALS(crack, php_crack_init_globals, NULL);
#endif

	REGISTER_INI_ENTRIES();
	le_crack = zend_register_list_destructors_ex(php_crack_module_dtor, NULL, "crack dictionary", module_number);

	return SUCCESS;
}

/* {{{ PHP_MSHUTDOWN_FUNCTION
 */
PHP_MSHUTDOWN_FUNCTION(crack)
{
	UNREGISTER_INI_ENTRIES();
	return SUCCESS;
}
/* }}} */

/* {{{ PHP_RINIT_FUNCTION
 */
PHP_RINIT_FUNCTION(crack)
{
	CRACKG(last_message) = NULL;
	CRACKG(default_dict) = NULL;

	return SUCCESS;
}
/* }}} */

/* {{{ PHP_RSHUTDOWN_FUNCTION
 */
ZEND_MODULE_DEACTIVATE_D(crack)
{
	if (NULL != CRACKG(last_message)) {
		efree(CRACKG(last_message));
	}

	return SUCCESS;
}
/* }}} */

/* {{{ PHP_MINFO_FUNCTION
 */
PHP_MINFO_FUNCTION(crack)
{
	php_info_print_table_start();
	php_info_print_table_header(2, "crack support", "enabled");
	php_info_print_table_end();

	DISPLAY_INI_ENTRIES();
}
/* }}} */

/* {{{ proto resource crack_opendict(string dictionary)
   Opens a new cracklib dictionary */
PHP_FUNCTION(crack_opendict)
{
	char *path;
	size_t path_len;
	CRACKLIB_PWDICT *pwdict;

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &path, &path_len) == FAILURE) {
		RETURN_FALSE;
	}

	if (php_crack_checkpath(path) == FAILURE) {
		RETURN_FALSE;
	}

	pwdict = cracklib_pw_open(path, "r");
	if (NULL == pwdict) {
		php_error_docref(NULL, E_WARNING, "Could not open crack dictionary: %s", path);
		RETURN_FALSE;
	}

	RETURN_RES(zend_register_resource(pwdict, le_crack));
	php_crack_set_default_dict(Z_RES_P(return_value));
}
/* }}} */

/* {{{ proto bool crack_closedict([resource dictionary])
   Closes an open cracklib dictionary */
PHP_FUNCTION(crack_closedict)
{
	zval *dictionary = NULL;
	zend_resource *id;
	CRACKLIB_PWDICT *pwdict;

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "|r", &dictionary)) {
		RETURN_FALSE;
	}

	if (NULL == dictionary) {
		id = php_crack_get_default_dict(INTERNAL_FUNCTION_PARAM_PASSTHRU);
		if (id == NULL) {
			php_error_docref(NULL, E_WARNING, "Could not open default crack dicionary"); 
			RETURN_FALSE;
		}
	}
	if((pwdict = (CRACKLIB_PWDICT *)zend_fetch_resource(Z_RES_P(dictionary), "crack dictionary", le_crack)) == NULL)
	{
		RETURN_FALSE;
	}
	if (NULL == dictionary) {
		zend_list_close(CRACKG(default_dict));
		CRACKG(default_dict) = NULL;
	}
	else {
		zend_list_close(Z_RES_P(dictionary));
	}
	RETURN_TRUE;
}
/* }}} */

/* {{{ proto bool crack_check(string password [, string username [, string gecos [, resource dictionary]]])
   Performs an obscure check with the given password */
PHP_FUNCTION(crack_check)
{
	zval *dictionary = NULL;
	char *password = NULL;
	size_t password_len;
	char *username = NULL;
	size_t username_len;
	char *gecos = NULL;
	size_t gecos_len;
	char *message;
	CRACKLIB_PWDICT *pwdict;
	zend_resource *crack_res;

	if (NULL != CRACKG(last_message)) {
		efree(CRACKG(last_message));
		CRACKG(last_message) = NULL;
	}

	if (zend_parse_parameters_ex(ZEND_PARSE_PARAMS_QUIET, ZEND_NUM_ARGS(), "rs", &dictionary, &password, &password_len) == FAILURE) {
		if (zend_parse_parameters(ZEND_NUM_ARGS(), "s|ssr", &password, &password_len, &username, &username_len, &gecos, &gecos_len, &dictionary) == FAILURE) {
			RETURN_FALSE;
		}
	}

	if (NULL == dictionary) {
		crack_res = php_crack_get_default_dict(INTERNAL_FUNCTION_PARAM_PASSTHRU);
		if (crack_res == NULL || crack_res->ptr == NULL) {
			php_error(E_WARNING, "Could not open default crack dicionary");
			RETURN_FALSE;
		}

	}
	else {
		if((pwdict = (CRACKLIB_PWDICT *)zend_fetch_resource(Z_RES_P(dictionary), "crack dictionary", le_crack)) == NULL) {
			php_error(E_WARNING, "Could not open crack dicionary resource");
			RETURN_FALSE;
		}
	}

	message = cracklib_fascist_look_ex(pwdict, password, username, gecos);

	if (NULL == message) {
		CRACKG(last_message) = estrdup("strong password");
		RETURN_TRUE;
	}
	else {
		CRACKG(last_message) = estrdup(message);
		RETURN_FALSE;
	}
}
/* }}} */

/* {{{ proto string crack_getlastmessage(void)
   Returns the message from the last obscure check */
PHP_FUNCTION(crack_getlastmessage)
{
	if (ZEND_NUM_ARGS() != 0) {
		WRONG_PARAM_COUNT;
	}

	if (NULL == CRACKG(last_message)) {
		php_error_docref(NULL, E_WARNING, "No obscure checks in this session");
		RETURN_FALSE;
	}

	RETURN_STRING(CRACKG(last_message));
}
/* }}} */

#endif /* HAVE_CRACK */
