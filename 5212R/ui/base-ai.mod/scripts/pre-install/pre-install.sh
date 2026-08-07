#!/bin/sh

echo "pre-install $$ $0" >> /tmp/install.log
 
# Most of the dependencies are chained anyway:
/usr/bin/dnf install -y base-ai-*

exit 0
