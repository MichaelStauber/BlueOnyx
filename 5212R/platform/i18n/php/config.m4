PHP_ARG_ENABLE([i18n],
  [whether to enable BlueOnyx i18n support],
  [AS_HELP_STRING([--enable-i18n], [Enable BlueOnyx i18n extension])],
  [yes])

if test "$PHP_I18N" != "no"; then
  PHP_ADD_INCLUDE([/usr/sausalito/include])
  PHP_EVAL_INCLINE([`pkg-config --cflags glib-2.0`])
  PHP_EVAL_LIBLINE([-L/usr/sausalito/lib -li18n `pkg-config --libs glib-2.0`], [I18N_SHARED_LIBADD])
  PHP_NEW_EXTENSION([i18n], [i18n_php8.c], [$ext_shared])
  PHP_SUBST([I18N_SHARED_LIBADD])
fi
