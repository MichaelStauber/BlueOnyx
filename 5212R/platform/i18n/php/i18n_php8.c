#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include <php.h>
#include "ext/standard/info.h"

#include <glib.h>
#include <time.h>

#include "php_i18n.h"
#include <cce/i18n.h>

static HashTable i18n_handles;
static zend_long i18n_next_handle_id = 1;

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_i18n_new, 0, 2, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO(0, domain, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, locale, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_MASK_EX(arginfo_i18n_get, 0, 2, MAY_BE_STRING | MAY_BE_FALSE)
	ZEND_ARG_TYPE_INFO(0, handle_id, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO(0, tag, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, domain, IS_STRING, 0, "\"\"")
	ZEND_ARG_ARRAY_INFO(0, vars, 1)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_MASK_EX(arginfo_i18n_get_property, 0, 3, MAY_BE_STRING | MAY_BE_FALSE)
	ZEND_ARG_TYPE_INFO(0, handle_id, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO(0, property, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, domain, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, language, IS_STRING, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_MASK_EX(arginfo_i18n_get_file, 0, 2, MAY_BE_STRING | MAY_BE_FALSE)
	ZEND_ARG_TYPE_INFO(0, handle_id, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO(0, file, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_i18n_availlocales, 0, 0, IS_ARRAY, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, domain, IS_STRING, 0, "\"\"")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_i18n_locales, 0, 1, IS_ARRAY, 0)
	ZEND_ARG_TYPE_INFO(0, handle_id, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, domain, IS_STRING, 0, "\"\"")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_MASK_EX(arginfo_i18n_strftime, 0, 3, MAY_BE_STRING | MAY_BE_FALSE)
	ZEND_ARG_TYPE_INFO(0, handle_id, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO(0, format, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, timestamp, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_MASK_EX(arginfo_i18n_interpolate, 0, 2, MAY_BE_STRING | MAY_BE_FALSE)
	ZEND_ARG_TYPE_INFO(0, handle_id, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO(0, magicstr, IS_STRING, 0)
	ZEND_ARG_ARRAY_INFO(0, vars, 1)
ZEND_END_ARG_INFO()

static void php_i18n_handle_dtor(zval *zv)
{
	i18n_handle *i18n = (i18n_handle *) Z_PTR_P(zv);

	if (i18n) {
		i18n_destroy(i18n);
	}
}

static i18n_handle *php_i18n_fetch_handle(zend_long handle_id)
{
	zval *zhandle;

	zhandle = zend_hash_index_find(&i18n_handles, handle_id);
	if (!zhandle) {
		php_error_docref(NULL, E_WARNING,
		    "%ld is not a valid i18n object index!", handle_id);
		return NULL;
	}

	return (i18n_handle *) Z_PTR_P(zhandle);
}

static zend_long php_i18n_store_handle(i18n_handle *i18n)
{
	zend_long handle_id;
	zval zhandle;

	handle_id = i18n_next_handle_id++;
	ZVAL_PTR(&zhandle, i18n);
	zend_hash_index_update(&i18n_handles, handle_id, &zhandle);

	return handle_id;
}

static i18n_vars *php_i18n_array_to_vars(zval *array)
{
	i18n_vars *vars;
	HashTable *ht;
	zend_string *key;
	zend_ulong num_key;
	zval *value;

	vars = i18n_vars_new();
	if (!array || Z_TYPE_P(array) != IS_ARRAY) {
		return vars;
	}

	ht = Z_ARRVAL_P(array);
	ZEND_HASH_FOREACH_KEY_VAL(ht, num_key, key, value) {
		zend_string *string_value;
		char numeric_key[32];
		const char *var_key;

		string_value = zval_get_string(value);
		if (key) {
			var_key = ZSTR_VAL(key);
		} else {
			snprintf(numeric_key, sizeof(numeric_key), "%lu",
			    (unsigned long) num_key);
			var_key = numeric_key;
		}

		i18n_vars_add(vars, (char *) var_key, ZSTR_VAL(string_value));
		zend_string_release(string_value);
	} ZEND_HASH_FOREACH_END();

	return vars;
}

static void php_i18n_return_string_or_false(zval *return_value, char *result)
{
	if (!result) {
		RETURN_FALSE;
	}

	RETVAL_STRING(result);
}

static int php_i18n_return_gslist_strings(zval *return_value, GSList *list)
{
	GSList *cursor;

	array_init(return_value);

	for (cursor = list; cursor; cursor = g_slist_next(cursor)) {
		add_next_index_string(return_value, (char *) cursor->data);
	}

	return SUCCESS;
}

PHP_FUNCTION(i18n_new)
{
	char *domain = NULL, *locale = NULL;
	size_t domain_len = 0, locale_len = 0;
	char *domain_arg = NULL, *locale_arg = NULL;
	i18n_handle *i18n;

	ZEND_PARSE_PARAMETERS_START(2, 2)
		Z_PARAM_STRING(domain, domain_len)
		Z_PARAM_STRING(locale, locale_len)
	ZEND_PARSE_PARAMETERS_END();

	if (domain_len > 0) {
		domain_arg = domain;
	}
	if (locale_len > 0) {
		locale_arg = locale;
	}

	i18n = i18n_new(domain_arg, locale_arg);
	if (!i18n) {
		php_error_docref(NULL, E_WARNING,
		    "i18n_new did not return a handle");
		RETURN_LONG(0);
	}

	RETURN_LONG(php_i18n_store_handle(i18n));
}

PHP_FUNCTION(i18n_availlocales)
{
	char *domain = NULL;
	size_t domain_len = 0;
	GSList *result;

	ZEND_PARSE_PARAMETERS_START(0, 1)
		Z_PARAM_OPTIONAL
		Z_PARAM_STRING(domain, domain_len)
	ZEND_PARSE_PARAMETERS_END();

	result = i18n_availlocales((domain_len > 0) ? domain : NULL);
	if (php_i18n_return_gslist_strings(return_value, result) == FAILURE) {
		RETURN_FALSE;
	}
}

PHP_FUNCTION(i18n_locales)
{
	zend_long handle_id;
	char *domain = NULL;
	size_t domain_len = 0;
	i18n_handle *i18n;
	GSList *result;

	ZEND_PARSE_PARAMETERS_START(1, 2)
		Z_PARAM_LONG(handle_id)
		Z_PARAM_OPTIONAL
		Z_PARAM_STRING(domain, domain_len)
	ZEND_PARSE_PARAMETERS_END();

	i18n = php_i18n_fetch_handle(handle_id);
	if (!i18n) {
		RETURN_FALSE;
	}

	result = i18n_locales(i18n, (domain_len > 0) ? domain : NULL);
	if (php_i18n_return_gslist_strings(return_value, result) == FAILURE) {
		RETURN_FALSE;
	}
}

PHP_FUNCTION(i18n_get_property)
{
	zend_long handle_id;
	char *property, *domain, *language = NULL;
	size_t property_len, domain_len, language_len = 0;
	i18n_handle *i18n;
	char *result;

	ZEND_PARSE_PARAMETERS_START(3, 4)
		Z_PARAM_LONG(handle_id)
		Z_PARAM_STRING(property, property_len)
		Z_PARAM_STRING(domain, domain_len)
		Z_PARAM_OPTIONAL
		Z_PARAM_STRING_OR_NULL(language, language_len)
	ZEND_PARSE_PARAMETERS_END();

	i18n = php_i18n_fetch_handle(handle_id);
	if (!i18n) {
		RETURN_FALSE;
	}

	result = i18n_get_property(i18n, property, domain,
	    (language && language_len > 0) ? language : NULL);
	php_i18n_return_string_or_false(return_value, result);
}

PHP_FUNCTION(i18n_get_file)
{
	zend_long handle_id;
	char *file;
	size_t file_len;
	i18n_handle *i18n;
	char *result;

	ZEND_PARSE_PARAMETERS_START(2, 2)
		Z_PARAM_LONG(handle_id)
		Z_PARAM_STRING(file, file_len)
	ZEND_PARSE_PARAMETERS_END();

	i18n = php_i18n_fetch_handle(handle_id);
	if (!i18n) {
		RETURN_FALSE;
	}

	result = i18n_get_file(i18n, file);
	php_i18n_return_string_or_false(return_value, result);
}

static void php_i18n_interpolate_common(INTERNAL_FUNCTION_PARAMETERS,
	char *(*interpolate_fn)(i18n_handle *, char *, i18n_vars *))
{
	zend_long handle_id;
	char *magicstr;
	size_t magicstr_len;
	zval *vars = NULL;
	i18n_handle *i18n;
	i18n_vars *native_vars;
	char *result;

	ZEND_PARSE_PARAMETERS_START(2, 3)
		Z_PARAM_LONG(handle_id)
		Z_PARAM_STRING(magicstr, magicstr_len)
		Z_PARAM_OPTIONAL
		Z_PARAM_ARRAY(vars)
	ZEND_PARSE_PARAMETERS_END();

	i18n = php_i18n_fetch_handle(handle_id);
	if (!i18n) {
		RETURN_FALSE;
	}

	native_vars = php_i18n_array_to_vars(vars);
	result = interpolate_fn(i18n, magicstr, native_vars);
	i18n_vars_destroy(native_vars);

	php_i18n_return_string_or_false(return_value, result);
}

PHP_FUNCTION(i18n_interpolate)
{
	php_i18n_interpolate_common(INTERNAL_FUNCTION_PARAM_PASSTHRU,
	    i18n_interpolate);
}

PHP_FUNCTION(i18n_interpolate_js)
{
	php_i18n_interpolate_common(INTERNAL_FUNCTION_PARAM_PASSTHRU,
	    i18n_interpolate_js);
}

PHP_FUNCTION(i18n_interpolate_html)
{
	php_i18n_interpolate_common(INTERNAL_FUNCTION_PARAM_PASSTHRU,
	    i18n_interpolate_html);
}

static void php_i18n_get_common(INTERNAL_FUNCTION_PARAMETERS,
	char *(*get_fn)(i18n_handle *, char *, char *, i18n_vars *))
{
	zend_long handle_id;
	char *tag;
	size_t tag_len;
	char *domain = NULL;
	size_t domain_len = 0;
	zval *vars = NULL;
	i18n_handle *i18n;
	i18n_vars *native_vars = NULL;
	char *result;

	ZEND_PARSE_PARAMETERS_START(2, 4)
		Z_PARAM_LONG(handle_id)
		Z_PARAM_STRING(tag, tag_len)
		Z_PARAM_OPTIONAL
		Z_PARAM_STRING(domain, domain_len)
		Z_PARAM_ARRAY(vars)
	ZEND_PARSE_PARAMETERS_END();

	i18n = php_i18n_fetch_handle(handle_id);
	if (!i18n) {
		RETURN_FALSE;
	}

	if (vars) {
		native_vars = php_i18n_array_to_vars(vars);
	}

	result = get_fn(i18n, tag, (domain_len > 0) ? domain : NULL, native_vars);
	if (native_vars) {
		i18n_vars_destroy(native_vars);
	}

	php_i18n_return_string_or_false(return_value, result);
}

PHP_FUNCTION(i18n_get)
{
	php_i18n_get_common(INTERNAL_FUNCTION_PARAM_PASSTHRU, i18n_get);
}

PHP_FUNCTION(i18n_get_js)
{
	php_i18n_get_common(INTERNAL_FUNCTION_PARAM_PASSTHRU, i18n_get_js);
}

PHP_FUNCTION(i18n_get_html)
{
	php_i18n_get_common(INTERNAL_FUNCTION_PARAM_PASSTHRU, i18n_get_html);
}

PHP_FUNCTION(i18n_strftime)
{
	zend_long handle_id;
	char *format;
	size_t format_len;
	zend_long timestamp;
	i18n_handle *i18n;
	char *result;

	ZEND_PARSE_PARAMETERS_START(3, 3)
		Z_PARAM_LONG(handle_id)
		Z_PARAM_STRING(format, format_len)
		Z_PARAM_LONG(timestamp)
	ZEND_PARSE_PARAMETERS_END();

	i18n = php_i18n_fetch_handle(handle_id);
	if (!i18n) {
		RETURN_FALSE;
	}

	result = i18n_strftime(i18n, (format_len > 0) ? format : NULL,
	    (time_t) timestamp);
	php_i18n_return_string_or_false(return_value, result);
}

PHP_MINIT_FUNCTION(i18n)
{
	return SUCCESS;
}

PHP_RINIT_FUNCTION(i18n)
{
#if defined(ZTS) && defined(COMPILE_DL_I18N)
	ZEND_TSRMLS_CACHE_UPDATE();
#endif

	zend_hash_init(&i18n_handles, 8, NULL, php_i18n_handle_dtor, 0);
	i18n_next_handle_id = 1;

	return SUCCESS;
}

PHP_RSHUTDOWN_FUNCTION(i18n)
{
	zend_hash_destroy(&i18n_handles);
	return SUCCESS;
}

PHP_MINFO_FUNCTION(i18n)
{
	php_info_print_table_start();
	php_info_print_table_row(2, "BlueOnyx i18n support", "enabled");
	php_info_print_table_end();
}

static const zend_function_entry i18n_functions[] = {
	PHP_FE(i18n_new, arginfo_i18n_new)
	PHP_FE(i18n_get, arginfo_i18n_get)
	PHP_FE(i18n_get_js, arginfo_i18n_get)
	PHP_FE(i18n_get_html, arginfo_i18n_get)
	PHP_FE(i18n_get_property, arginfo_i18n_get_property)
	PHP_FE(i18n_get_file, arginfo_i18n_get_file)
	PHP_FE(i18n_availlocales, arginfo_i18n_availlocales)
	PHP_FE(i18n_locales, arginfo_i18n_locales)
	PHP_FE(i18n_strftime, arginfo_i18n_strftime)
	PHP_FE(i18n_interpolate, arginfo_i18n_interpolate)
	PHP_FE(i18n_interpolate_js, arginfo_i18n_interpolate)
	PHP_FE(i18n_interpolate_html, arginfo_i18n_interpolate)
	PHP_FE_END
};

zend_module_entry i18n_module_entry = {
	STANDARD_MODULE_HEADER,
	"i18n",
	i18n_functions,
	PHP_MINIT(i18n),
	NULL,
	PHP_RINIT(i18n),
	PHP_RSHUTDOWN(i18n),
	PHP_MINFO(i18n),
	NO_VERSION_YET,
	STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_I18N
# ifdef ZTS
ZEND_TSRMLS_CACHE_DEFINE()
# endif
ZEND_GET_MODULE(i18n)
#endif

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