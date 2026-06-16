/* $Id: php_cce.h 259 2004-01-03 06:28:40Z shibuya $ */
/* Copyright 2001 Sun Microsystems, Inc.  All rights reserved. */
/* Modernized for PHP 8.3 by BlueOnyx Project */

#ifndef _PHP_CCE_H
#define _PHP_CCE_H

#include <php.h>

extern zend_module_entry ccephp_module_entry;
#define phpext_ccephp_ptr &ccephp_module_entry

/* Resource type for CCE handles */
#define CCE_TYPE 43

PHP_MINFO_FUNCTION(ccephp);
PHP_MINIT_FUNCTION(ccephp);

/* Core functions */
PHP_FUNCTION(ccephp_new);
PHP_FUNCTION(ccephp_connect);
PHP_FUNCTION(ccephp_auth);
PHP_FUNCTION(ccephp_authkey);
PHP_FUNCTION(ccephp_bye);
PHP_FUNCTION(ccephp_suspended);
PHP_FUNCTION(ccephp_begin);
PHP_FUNCTION(ccephp_commit);
PHP_FUNCTION(ccephp_create);
PHP_FUNCTION(ccephp_destroy);
PHP_FUNCTION(ccephp_set);
PHP_FUNCTION(ccephp_get);
PHP_FUNCTION(ccephp_names);
PHP_FUNCTION(ccephp_find);
PHP_FUNCTION(ccephp_findx);
PHP_FUNCTION(ccephp_whoami);
PHP_FUNCTION(ccephp_endkey);
PHP_FUNCTION(ccephp_errors);
PHP_FUNCTION(ccephp_is_rollback);

/* Admin functions */
PHP_FUNCTION(ccephp_suspend);
PHP_FUNCTION(ccephp_resume);

/* Handler functions */
PHP_FUNCTION(ccephp_bye_handler);
PHP_FUNCTION(ccephp_bad_data);
PHP_FUNCTION(ccephp_handler_get);

#endif