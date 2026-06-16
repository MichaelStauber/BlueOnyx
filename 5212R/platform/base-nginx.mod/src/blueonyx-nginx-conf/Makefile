# $Id: Makefile 

#ifdef TOPDIR
#include $(TOPDIR)/devel/defines.mk
#else
include /usr/sausalito/devel/defines.mk
#endif

.PHONY: all
all: install

install: 
	mkdir -p $(PREFIX)/etc/nginx/conf
	mkdir -p $(PREFIX)/etc/nginx/vsites
	mkdir -p $(PREFIX)/etc/nginx/headers.d
	install -m 0644 -o root -g root etc/nginx/conf/00-default.conf $(PREFIX)/etc/nginx/conf/00-default.conf
	install -m 0644 -o root -g root etc/nginx/ssl_proto_chiffres.conf $(PREFIX)/etc/nginx/ssl_proto_chiffres.conf.bxdefault
	install -m 0644 -o root -g root etc/nginx/headers.d/security.conf $(PREFIX)/etc/nginx/headers.d/security.conf
	install -m 0644 -o root -g root etc/nginx/headers.d/https_headers.conf $(PREFIX)/etc/nginx/headers.d/https_headers.conf
	install -m 0644 -o root -g root etc/nginx/ssl_defaults.conf $(PREFIX)/etc/nginx/ssl_defaults.conf.bxdefault
