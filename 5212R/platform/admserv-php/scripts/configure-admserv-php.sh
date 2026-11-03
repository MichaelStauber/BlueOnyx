#! /bin/sh
#
# Configuration for 5212R AdmServ PHP-FPM (SOLARSPEED.NET)

# NOTE: zip-devel must NOT be installed. But solarspeed-libzip MUST be installed!

case "$1" in

        p|-p)
            PACKAGE=$2
                echo -n "Using Package Version $PACKAGE "
                echo ""
                ;;

        *)
                echo ""
                echo "PHP-Builder"
                echo ""
                echo "Usage: $0 {-p 8.1 }"
                echo ""
                echo "Options:"
                echo "   -p:    Specifiy the Package Version (8.1, 8.2, ...)"
                echo ""
                exit 1

esac

if [ "$PACKAGE" == "8.0" ] || [ "$PACKAGE" == "8.1" ] || [ "$PACKAGE" == "8.2" ] || [ "$PACKAGE" == "8.3" ];then

        # Ensure the new Libzip is used:
        #echo "/home/solarspeed/libzip/lib64" > /etc/ld.so.conf.d/solarspeed-libzip.conf
        #ldconfig

        make distclean

        cat `aclocal --print-ac-dir`/{libtool,ltoptions,ltsugar,ltversion,lt~obsolete}.m4 >>aclocal.m4

        # Force use of system libtool:
        libtoolize --force --copy
        cat `aclocal --print-ac-dir`/{libtool,ltoptions,ltsugar,ltversion,lt~obsolete}.m4 >build/libtool.m4

        # Regenerate configure scripts (patches change config.m4's)
        touch configure.ac
        ./buildconf --force

#        unset LIBZIP_CFLAGS
#        unset LIBZIP_LIBS

        # Also preload the new libzip to bloody well make sure it is used:
#        LD_PRELOAD="/home/solarspeed/libzip/lib64/libzip.so.5"
#        export LD_PRELOAD

#        export CFLAGS="-I/home/solarspeed/libzip/include"
#        export LDFLAGS="-L/home/solarspeed/libzip/lib64"
#        export CPPFLAGS="-I/home/solarspeed/libzip/include"
#        export PKG_CONFIG_PATH=/home/solarspeed/libzip/lib64/pkgconfig/:/home/solarspeed/curl/lib/pkgconfig/
#        export LD_LIBRARY_PATH="/home/solarspeed/libzip/lib64"

        ./configure \
         --prefix=/home/solarspeed/admserv-php \
         --enable-shared \
         --enable-cli \
         --enable-fpm \
         --with-fpm-user=admserv \
         --with-fpm-group=admserv \
         --with-fpm-systemd \
         --with-config-file-path=/home/solarspeed/admserv-php/etc \
         --with-config-file-scan-dir=/home/solarspeed/admserv-php/etc/php.d \
         --enable-shared \
         --disable-debug \
         --disable-rpath \
         --enable-opcache \
         --enable-mysqlnd \
         --with-mysqli \
         --with-pgsql=shared \
         --enable-dom \
         --enable-simplexml \
         --enable-xml \
         --with-snmp=shared \
         --enable-xmlreader  \
         --enable-xmlwriter  \
         --enable-pdo  \
         --with-pdo-odbc=unixODBC,/usr \
         --with-pdo-mysql=mysqlnd \
         --with-pdo-pgsql=shared \
         --with-pdo-sqlite \
         --with-sqlite3 \
         --enable-bcmath \
         --enable-calendar \
         --enable-ctype \
         --enable-exif \
         --enable-ftp \
         --enable-gd \
         --with-webp \
         --with-jpeg \
         --with-xpm \
         --with-password-argon2 \
         --enable-gd-jis-conv \
         --with-layout=GNU \
         --enable-mbregex \
         --enable-mbstring \
         --enable-shmop \
         --enable-sockets \
         --with-bz2 \
         --with-curl \
         --with-db4=/usr \
         --with-gettext=/usr \
         --with-gmp \
         --with-iconv \
         --with-kerberos \
         --with-layout=GNU \
         --with-ldap \
         --with-mhash \
         --with-openssl \
         --with-pspell \
         --with-zlib \
         --enable-soap \
         --with-libdir=lib64 \
         --with-unixODBC=/usr \
         --enable-intl \
         --with-freetype \
         --with-xsl \
         --with-sodium \
         --with-pear \
         --mandir=/home/solarspeed/admserv-php/man/ \
         --docdir=/home/solarspeed/admserv-php/docs/ \
         --sbindir=/home/solarspeed/admserv-php/sbin/ \
         --with-zip

#         --with-zip=/home/solarspeed/libzip/lib64/libzip.so.5
#         --with-imap \
#         --with-imap-ssl \

fi

if [ "$PACKAGE" == "8.4" ];then

        # Ensure the new Libzip is used:
        #echo "/home/solarspeed/libzip/lib64" > /etc/ld.so.conf.d/solarspeed-libzip.conf
        #ldconfig

        make distclean

        cat `aclocal --print-ac-dir`/{libtool,ltoptions,ltsugar,ltversion,lt~obsolete}.m4 >>aclocal.m4

        # Force use of system libtool:
        libtoolize --force --copy
        cat `aclocal --print-ac-dir`/{libtool,ltoptions,ltsugar,ltversion,lt~obsolete}.m4 >build/libtool.m4

        # Regenerate configure scripts (patches change config.m4's)
        touch configure.ac
        ./buildconf --force

        unset LIBZIP_CFLAGS
        unset LIBZIP_LIBS

        # Also preload the new libzip to bloody well make sure it is used:
        LD_PRELOAD="/home/solarspeed/libzip/lib64/libzip.so.5"
        export LD_PRELOAD

        export CFLAGS="-I/home/solarspeed/libzip/include"
        export LDFLAGS="-L/home/solarspeed/libzip/lib64"
        export CPPFLAGS="-I/home/solarspeed/libzip/include"
        export PKG_CONFIG_PATH=/home/solarspeed/libzip/lib64/pkgconfig/:/home/solarspeed/curl/lib/pkgconfig/
        export LD_LIBRARY_PATH="/home/solarspeed/libzip/lib64"

        ./configure \
         --prefix=/home/solarspeed/admserv-php \
         --enable-shared \
         --enable-cli \
         --enable-fpm \
         --with-fpm-user=admserv \
         --with-fpm-group=admserv \
         --with-fpm-systemd \
         --with-config-file-path=/home/solarspeed/admserv-php/etc \
         --with-config-file-scan-dir=/home/solarspeed/admserv-php/etc/php.d \
         --enable-shared \
         --disable-debug \
         --disable-rpath \
         --enable-opcache \
         --enable-mysqlnd \
         --with-mysqli \
         --with-pgsql=shared \
         --enable-dom \
         --enable-simplexml \
         --enable-xml \
         --with-snmp=shared \
         --enable-xmlreader  \
         --enable-xmlwriter  \
         --enable-pdo  \
         --with-pdo-odbc=unixODBC,/usr \
         --with-pdo-mysql=mysqlnd \
         --with-pdo-pgsql=shared \
         --with-pdo-sqlite \
         --with-sqlite3 \
         --enable-bcmath \
         --enable-calendar \
         --enable-ctype \
         --enable-exif \
         --enable-ftp \
         --enable-gd \
         --with-webp \
         --with-jpeg \
         --with-xpm \
         --with-password-argon2 \
         --enable-gd-jis-conv \
         --with-layout=GNU \
         --enable-mbregex \
         --enable-mbstring \
         --enable-shmop \
         --enable-sockets \
         --with-bz2 \
         --with-curl \
         --with-db4=/usr \
         --with-gettext=/usr \
         --with-gmp \
         --with-iconv \
         --with-kerberos \
         --with-layout=GNU \
         --with-ldap \
         --with-mhash \
         --with-openssl \
         --with-pspell \
         --with-zlib \
         --enable-soap \
         --with-libdir=lib64 \
         --with-unixODBC=/usr \
         --enable-intl \
         --with-freetype \
         --with-xsl \
         --with-sodium \
         --with-pear \
         --mandir=/home/solarspeed/admserv-php/man/ \
         --docdir=/home/solarspeed/admserv-php/docs/ \
         --sbindir=/home/solarspeed/admserv-php/sbin/ \
         --with-zip=/home/solarspeed/libzip/lib64/libzip.so.5

fi

#         --with-zip \

exit
