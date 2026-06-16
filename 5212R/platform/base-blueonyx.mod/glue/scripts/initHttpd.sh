#!/bin/sh

perl -pi.bak -e 'if (/^AddDefaultCharset/) { s/AddDefaultCharset/#AddDefaultCharset/ }' /etc/httpd/conf/httpd.conf

perl -pi.bak -e 's/LogFormat "%h %l %u %t \\"%r\\" %>s %b \\"%\{Referer\}i\\" \\"%\{User-Agent\}i\\"" combined/LogFormat "%v %h %l %u %t \\"%r\\" %>s %b \\"%\{Referer\}i\\" \\"%\{User-Agent\}i\\"" combined/g' /etc/httpd/conf/httpd.conf

# disable error alias
perl -pi.bak -e 's|^Alias /error/|#Alias /error/|g' /etc/httpd/conf/httpd.conf

# disable ScriptAlias
perl -pi.bak -e 's/^ScriptAlias/#ScriptAlias/g' /etc/httpd/conf/httpd.conf

# CentOS and their bloody appstreams break /etc/httpd/conf.d/php.conf.
PHPBROKEN=`/bin/cat /etc/httpd/conf.d/php.conf | /bin/grep "#    Require all denied" | /usr/bin/wc -l`
if [ $PHPBROKEN = "0" ]; then
    perl -0777 -i.bak -pe 's/#<Files ".user.ini">\n    Require all denied\n#<\/Files>/#<Files ".user.ini">\n#    Require all denied\n#<\/Files>/igs' /etc/httpd/conf.d/php.conf
fi
