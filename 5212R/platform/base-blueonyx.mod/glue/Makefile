BUILD=$(shell date --rfc-3339=date | sed s/-//g)
PRODUCT=$(shell if [ `uname -m` = "x86_64" ]; then if [ `cat /etc/build |grep 5212R|wc -l` = "1" ]; then echo -n "5212"; else echo -n "5212"; fi else echo -n "5212"; fi)

all:

clean:

install:
	mkdir -p $(PREFIX)/etc
	#mkdir -p $(PREFIX)/etc/rc.d/init.d
	mkdir -p $(PREFIX)/etc/logrotate.d
	mkdir -p $(PREFIX)/root
	chmod 550 $(PREFIX)/root
	mkdir -p $(PREFIX)/etc/locks
	mkdir -p $(PREFIX)/usr/sausalito/sbin
	mkdir -p $(PREFIX)/usr/sausalito/scripts
	mkdir -p $(PREFIX)/usr/sausalito/runonce
	mkdir -p $(PREFIX)/usr/share/doc/blueonyx-release-5212R
	mkdir -p $(PREFIX)/usr/lib/systemd/system/
	mkdir -p $(PREFIX)/usr/share/ssl/certs/
	mkdir -p $(PREFIX)/var/lib/dovecot/
	tar cBf - --exclude '.svn' etc | (cd $(PREFIX)/ ; tar xBf -)
	cat etc/build | sed -e 's/XX_BUILD_XX/$(BUILD)/' | \
			sed -e 's/XX_PRODUCT_XX/$(PRODUCT)/g' > $(PREFIX)/etc/build
	tar cBf - --exclude '.svn' triggers | (cd $(PREFIX)/usr/sausalito/ ; tar xBf -)
	mkdir -p $(PREFIX)/var/www/html
	install -m 0644 httpd/index.html $(PREFIX)/var/www/html/index.html
	tar cBf - --exclude '.svn' root | (cd $(PREFIX)/ ; tar xBf -)
	tar cBf - --exclude '.svn' sbin | (cd $(PREFIX)/usr/sausalito ; tar xBf -)
	tar cBf - --exclude '.svn' scripts | (cd $(PREFIX)/usr/sausalito ; tar xBf -)
	tar cBf - --exclude '.svn' configs | (cd $(PREFIX)/usr/sausalito ; tar xBf -)
	install -m 0755 configs/systemd/blueonyx.service $(PREFIX)/usr/lib/systemd/system/blueonyx.service
	install -m 0644 doc/* $(PREFIX)/usr/share/doc/blueonyx-release-5212R
	install -m 0600 dh/sendmail-2048.dh $(PREFIX)/usr/share/ssl/certs/sendmail-2048.dh
	install -m 0644 dh/ssl-parameters.dat $(PREFIX)/var/lib/dovecot/ssl-parameters.dat

