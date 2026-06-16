#ifndef PHP_I18N_H
#define PHP_I18N_H

#include <php.h>

extern zend_module_entry i18n_module_entry;
#define phpext_i18n_ptr &i18n_module_entry

PHP_MINIT_FUNCTION(i18n);
PHP_RINIT_FUNCTION(i18n);
PHP_RSHUTDOWN_FUNCTION(i18n);
PHP_MINFO_FUNCTION(i18n);

PHP_FUNCTION(i18n_new);
PHP_FUNCTION(i18n_get);
PHP_FUNCTION(i18n_get_js);
PHP_FUNCTION(i18n_get_html);
PHP_FUNCTION(i18n_get_property);
PHP_FUNCTION(i18n_get_file);
PHP_FUNCTION(i18n_availlocales);
PHP_FUNCTION(i18n_locales);
PHP_FUNCTION(i18n_strftime);
PHP_FUNCTION(i18n_interpolate);
PHP_FUNCTION(i18n_interpolate_js);
PHP_FUNCTION(i18n_interpolate_html);

#endif
