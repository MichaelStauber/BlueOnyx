PHP_ARG_ENABLE([cce],
  [whether to enable BlueOnyx CCE PHP extension],
  [AS_HELP_STRING([--enable-cce], [Enable BlueOnyx CCE extension])],
  [yes])

if test "$PHP_CCE" != "no"; then
  PHP_EVAL_INCLINE([`glib-config --cflags`])
  PHP_EVAL_LIBLINE([-lcce_common -lcce `glib-config --libs`], [CCE_SHARED_LIBADD])
  PHP_NEW_EXTENSION([cce], [cce.c], [$ext_shared])
  PHP_SUBST([CCE_SHARED_LIBADD])
fi