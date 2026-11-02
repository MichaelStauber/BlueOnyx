# admserv-php (SVN build tree)

BlueOnyx AdmServ PHP-FPM under `/home/solarspeed/admserv-php`.

## Staging (mandatory)

`make` **never** writes `/home/solarspeed/admserv-php`.

| Path | Role |
|------|------|
| `PREFIX` | Final path on servers (`./configure --prefix=…` only) |
| `DESTDIR` / `INSTALL_ROOT` | `build/dest/` - PHP's `make install` needs **INSTALL_ROOT**, not DESTDIR |
| `STAGE` | `build/dest/home/solarspeed/admserv-php` - RPM payload |

```bash
make rpm      # fertiges RPM+SRPM (as_rpms/ + as_srpms/)
make clean    # build/ + as_rpms/ + as_srpms/ weg
```

Intern macht `make rpm` bereits: resolve → download → configure → build → stage (INSTALL_ROOT) → PECL redis/apcu → package. Kein separates `make stage package` noetig.

Install on a host only with `rpm -Uvh …` when you intend to.

## Defaults

| Variable | Default |
|----------|---------|
| PHP_MAJOR | 8.4 |
| PHP_VERSION | **latest 8.4.x from php.net** (override: `PHP_VERSION=8.4.27`) |
| RELEASE | 1 |
| PREFIX | /home/solarspeed/admserv-php |
| ZEND_API | 20240924 (PHP 8.4) |

Resolver: `scripts/resolve-php-version.sh` (php.net JSON). Download verifies SHA256 when the API provides it.

## Commands

```bash
cd .../tmp/5212R-Alpine-CI-update/admserv-php
make help
make resolve-version        # show newest 8.4.x
make rpm                    # resolve + download + configure + build + install + rpmbuild
make rpm PHP_VERSION=8.4.27 # pin
make clean                  # wipe build/ entirely (tarball + sources gone)
```

**make clean** leaves no PHP upstream tarball or unpacked tree in the WC (and `build/` is svn:ignored). Nothing upstream is committed to SVN.

Build host: **builder10** (rpmbuild + devel deps). Packaging sources live in this SVN tree; rain is for GUI/CI testing after RPM install.

## Layout

- `Makefile` - orchestration
- `scripts/configure-admserv-php.sh` - `./configure` flags (8.3 and 8.4 branches)
- `skel/etc/` - php.ini, php-fpm.conf, php.d (ioncube, hedge), **auto_prepend_file empty**
- `templates/admserv-php.spec.in` - RPM template (excludes cce.so / i18n.so)

## Not in this RPM

- `cce.so` / `i18n.so` and their `php.d/*.ini` - built and owned by `cce` / `i18n` modules against `ADM_PHP_PREFIX=/home/solarspeed/admserv-php`

## After upgrading 8.3 -> 8.4

1. Install new `admserv-php` RPM on rain
2. Rebuild/install i18n + cce PHP extensions against new headers/`php-config`
3. Confirm `php -m` / GUI login; confirm `auto_prepend_file` empty in AdmServ php.ini
