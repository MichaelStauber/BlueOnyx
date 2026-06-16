#!/usr/bin/perl -w
# $Id: :sysreset:linkToWizard.pl
# Copyright 2000-2002 Sun Microsystems, Inc., All rights reserved.

# use these two packages to make sure diretories needed exist
use lib qw(/usr/sausalito/perl);
use Sauce::Config;
use File::Path;
use Base::HomeDir qw(homedir_get_group_dir);

# default to assuming the existence of some home directory for use as the
# default since the qube puts the public server in /home/groups/home/web
# and raqs don't care
# temporary hack because get_group_dir hashes
my $default_webdir = '/var/www/html';

# default welcome file, this should be product specific, so I'm assuming a standard name
my $welcome_file = '/intro.html';

my $fileName80 = "$default_webdir/index.html";
my $fileName81 = "/usr/sausalito/ui/web/index.html";

# need to make sure directories exists otherwise this won't work
mkpath($default_webdir, 0755) unless( -d $default_webdir );

mkpath("/usr/sausalito/ui/web", 1, 0755) unless(-d "/usr/sausalito/ui/web");

open(FILE, ">$fileName80")
    || die("Error: linkToWizard.pl: Cannot open file $fileName80\n");
print FILE <<END;
<HTML>
<HEAD>
<META HTTP-EQUIV="expires" CONTENT="-1">
<META HTTP-EQUIV="Pragma" CONTENT="no-cache">
</HEAD>
<BODY onLoad=\"location='http://'+location.host+':444/'\">
</BODY>
</HTML>
END
close(FILE);

# it is just a temporary file and everyone should be able to clean it up if
# it is left unclean
chmod(0644, $fileName80);

open(FILE, ">$fileName81")
    || die("Error: linkToWizard.pl: Cannot open file $fileName81\n");
print FILE <<END;
<HTML>
<HEAD>
<META HTTP-EQUIV="expires" CONTENT="-1">
<META HTTP-EQUIV="Pragma" CONTENT="no-cache">
</HEAD>
<BODY onLoad=\"location='$welcome_file'\">
</BODY>
</HTML>
END
close(FILE);

chmod(0644, $fileName81);

exit 0;

# 
# Copyright (c) 2008-2022 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2022 Team BlueOnyx, BLUEONYX.IT
# Copyright (c) 2003 Sun Microsystems, Inc. 
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