#!/bin/bash

MYSQLSTATE=$(pidof mysqld mariadb mariadbd|wc -l)
if [ $MYSQLSTATE != 0 ]; then
  /bin/echo "is running..."
fi
