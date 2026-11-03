# phpsysinfo (BlueOnyx packaging)

Builds the `phpsysinfo` RPM from upstream [phpsysinfo/phpsysinfo](https://github.com/phpsysinfo/phpsysinfo).

## Flow

1. Resolve latest tag via GitHub API (or `PSI_VERSION=3.4.7`)
2. Download `vVERSION.tar.gz` to `/tmp/phpsysinfo-src/`
3. Stage under `/usr/sausalito/ui/web/base/phpsysinfo/.phpsysinfo/`
4. Inject **sysinfoAccess** cookie check into `index.php` and `js.php`
5. Ship glue `phpsysinfo.ini.org` / `phpsysinfo.ini` when present
6. `rpmbuild -ba`

## GUI

`PHPSysinfo` controller sets `sysinfoAccess` cookie and iframes  
`/base/phpsysinfo/.phpsysinfo/index.php?disp=bootstrap`.

## Module

`base-phpsysinfo.mod`: `BUILDSRC=yes`, `REQUIRES=php-xml phpsysinfo`.
