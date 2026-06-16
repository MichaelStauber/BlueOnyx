#!/bin/bash

#
# The CodeIgniter 4 BlueOnyx uses has some stock files replaced with modifications. 
# If CI us upgraded (manually or via composer) these modified files must be rotated
# back in.
#

if [ -f /usr/sausalito/ui/chorizo/ci4/app/Config/Autoload.php.bx ];then
	cp /usr/sausalito/ui/chorizo/ci4/app/Config/Autoload.php.bx /usr/sausalito/ui/chorizo/ci4/app/Config/Autoload.php
fi

if [ -f /usr/sausalito/ui/chorizo/ci4/app/Config/Routes.php.bx ]; then
	cp /usr/sausalito/ui/chorizo/ci4/app/Config/Routes.php.bx /usr/sausalito/ui/chorizo/ci4/app/Config/Routes.php
fi

if [ -f /usr/sausalito/ui/chorizo/ci4/app/Config/Session.php.bx ]; then
	cp /usr/sausalito/ui/chorizo/ci4/app/Config/Session.php.bx /usr/sausalito/ui/chorizo/ci4/app/Config/Session.php
fi

if [ -f /usr/sausalito/ui/chorizo/ci4/app/Config/Filters.php.csrf ];then
	cp /usr/sausalito/ui/chorizo/ci4/app/Config/Filters.php.csrf /usr/sausalito/ui/chorizo/ci4/app/Config/Filters.php
fi

# This is no longer required!
#if [ -f /usr/sausalito/ui/chorizo/ci4/app/Libraries/ci4-modified-autoloader/Autoloader.php.bx ];then
#	cp /usr/sausalito/ui/chorizo/ci4/app/Libraries/ci4-modified-autoloader/Autoloader.php.bx /usr/sausalito/ui/chorizo/ci4/vendor/codeigniter4/framework/system/Autoloader/Autoloader.php
#fi

if [ -f /usr/sausalito/ui/chorizo/ci4/app/Views/errors/html/production.php.bx ];then
	cp /usr/sausalito/ui/chorizo/ci4/app/Views/errors/html/production.php.bx /usr/sausalito/ui/chorizo/ci4/app/Views/errors/html/production.php
fi

#
# Copyright (c) 2008-2024 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2024 Team BlueOnyx, BLUEONYX.IT
# All Rights Reserved.
# 
# 1. Redistributions of source code must retain the above copyright 
#    notice, this list of conditions and the following disclaimer.
# 
# 2. Redistributions in binary form must reproduce the above copyright 
#    notice, this list of conditions and the following disclaimer in 
#    the documentation and/or other materials provided with the 
#    distribution.
# 
# 3. Neither the name of the copyright holder nor the names of its 
#    contributors may be used to endorse or promote products derived 
#    from this software without specific prior written permission.
# 
# THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS 
# "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT 
# LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS 
# FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE 
# COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, 
# INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, 
# BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; 
# LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER 
# CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT 
# LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN 
# ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE 
# POSSIBILITY OF SUCH DAMAGE.
# 
# You acknowledge that this software is not designed or intended for 
# use in the design, construction, operation or maintenance of any 
# nuclear facility.
#