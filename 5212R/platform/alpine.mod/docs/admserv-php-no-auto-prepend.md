# admserv-php: do NOT set auto_prepend_file

**Date:** 2026-11-01  
**Context:** CI 4.7.5 / Chorizo GUI work on rain.smd.net  
**Work tree:** `tmp/5212R-Alpine-CI-update/`

## Rule

**AdmServ PHP (`admserv-php` / `admserv-php-fpm`) must NOT use:**

```ini
auto_prepend_file = /usr/sausalito/configs/php/set_php_headers.php
```

Correct for AdmServ:

```ini
auto_prepend_file =
```

(same as stock `platform/base-admserv.mod/glue/etc/admserv/php.ini`)

## Why

- `set_php_headers.php` is a **Vsite / customer-PHP** helper (quota header/footer, php2shell-related env), not for the GUI SAPI.
- ionCube-encoded Chorizo modules (e.g. `Modules/Solarspeed/Rspamd/Config/Routes.php`) **refuse to run** when `auto_prepend_file` or `auto_append_file` is set → GUI HTTP 500 / empty body when `Config/Routes.php` scans module routes.
- php2shell hedge for AdmServ is only `zz-php2shell-hedge.ini` (`session.upload_progress.enabled=Off`) via swatch hotfixes – **not** auto_prepend.

## Where it still belongs

- Vsite Apache PHP / Extra-PHP FPM pools (`base-apache` / `base-vsite` handlers) – keep prepend there for sites.

## Rebuild checklist (admserv-php 8.3.x and future **8.4.27**)

When packaging or rebuilding `admserv-php`:

1. Ship `php.ini` with **`auto_prepend_file =`** (empty) and **`auto_append_file =`** (empty).
2. Do **not** copy Extra-PHP/Vsite php.ini defaults wholesale into AdmServ.
3. Do **not** bake `set_php_headers.php` into AdmServ php.ini in `%post` or construct scripts.
4. After install on a test box:  
   `/home/solarspeed/admserv-php/bin/php -i | grep auto_prepend_file`  
   must show empty value; then load GUI login with ionCube modules present.
5. `systemctl try-restart admserv-php-fpm` after php.ini changes (not `reload` alone if workers stay up – prefer try-restart).

## Live fix applied (rain, 2026-11-01)

- Cleared `auto_prepend_file` in `/home/solarspeed/admserv-php/etc/php.ini`
- Backup: `php.ini.bak-pre-noprepend-*`
- stop/start `admserv-php-fpm` so workers pick up php.ini

## Smoke after CI 4.7.5 + this fix (rain)

- `GET https://127.0.0.1:81/login` → **302** to `https://rain.smd.net:81/login`
- follow redirects → **200**, title `Login - BlueOnyx 5212R - rain.smd.net`
- Cookies: `bx_session`, `BlueOnyx_CSRF_cookie`
- Login form present (`id="login_form"`)

Also required for 4.7.5: refresh stock Config files that gained properties (notably `Config\Feature::$strictLocaleNegotiation`), keep sacred overlays via `bx_ci4_fixer.sh`.
