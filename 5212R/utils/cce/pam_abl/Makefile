PREFIX ?= /usr
LIBDIR ?= $(shell [ "$(shell uname -m)" = "x86_64" ] && echo "/lib64" || echo "/lib")

.PHONY: all install clean

all:
	./build.sh

install:
	install -D -m 755 cmake_build/pam_abl         $(DESTDIR)$(PREFIX)/bin/pam_abl
	install -D -m 755 cmake_build/pam_abl_test    $(DESTDIR)$(PREFIX)/bin/pam_abl_test
	install -D -m 755 cmake_build/pam_abl.so      $(DESTDIR)$(LIBDIR)/security/pam_abl.so
	install -D -m 644 conf/pam_abl.conf           $(DESTDIR)/etc/security/pam_abl.conf
	install -d -m 755                             $(DESTDIR)/var/lib/pam_abl
	install -D -m 755 cmake_build/pam_abl         $(DESTDIR)$(PREFIX)/sausalito/bin/pam_abl_gui

	# Precompress man pages
	gzip -9 -c doc/pam_abl.1.txt      > doc/pam_abl.1.gz
	gzip -9 -c doc/pam_abl.8.txt      > doc/pam_abl.8.gz
	gzip -9 -c doc/pam_abl.conf.5.txt > doc/pam_abl.conf.5.gz

	# Install only the .gz man pages
	install -D -m 644 doc/pam_abl.1.gz           $(DESTDIR)$(PREFIX)/share/man/man1/pam_abl.1.gz
	install -D -m 644 doc/pam_abl.8.gz           $(DESTDIR)$(PREFIX)/share/man/man8/pam_abl.8.gz
	install -D -m 644 doc/pam_abl.conf.5.gz      $(DESTDIR)$(PREFIX)/share/man/man5/pam_abl.conf.5.gz

clean:
	./build.sh clean
	rm -f doc/*.gz
