#!/usr/bin/perl -w
# $Id: copy.pl

use lib qw(/usr/sausalito/perl);
use Sauce::Service;
use File::Copy;

my $admserv_index = '/usr/sausalito/ui/web/index.html';
my $splash_dir = '/usr/sausalito/ui/web/base/workgroup';
my $qube_home = '/home/groups/home/web';
my $raq_home = '/home/sites/home/web';

$ARGV[0] ||= '';
if ($ARGV[0] =~ /splash/) {
    if (-d $qube_home) {
        opendir(SPL, $splash_dir);
        while($_ = readdir(SPL)) {
            if (/default_home/) {
                my $new_filename = $_;
                $new_filename =~ s/default_home/index/;

                copy("$splash_dir/$_", "$home_dir/$new_filename");
                chown((getpwnam('admin'))[2], (getgrnam('home'))[2], "$home_dir/$new_filename");
                chmod(0664, "$home_dir/$new_filename");
                unlink("$home_dir/index.html");
            }
        }
        closedir(SPL);

        my $locale;
        open(LOCALE, "/etc/cobalt/locale");
        chomp($locale = <LOCALE>);
        close(LOCALE);

        my $page = "/etc/skel/group/$locale/web/index.html";
        if ($locale && (-r $page)) {
            my $target = '/home/groups/guest-share/web/index.html';
            copy($page, $target);
            chown((getpwnam('admin'))[2], (getgrnam('guest-share'))[2], $target);
            chmod(0664, $target);
        }
        if ($locale && (-r $page)) {
            my $target = '/home/groups/restore/web/index.html';
            copy($page, $target);
            chown((getpwnam('admin'))[2], (getgrnam('restore'))[2], $target);
            chmod(0664, $target);
        }
        $page = "/etc/skel/user/$locale/web/index.html";
        if ($locale && (-r $page)) {
            my $target = '/home/users/admin/web/index.html';
            copy($page, $target);
            chown((getpwnam('admin'))[2], (getgrnam('users'))[2], $target);
            chmod(0644, $target);
        }

        # the wrong place for the wrong fix 
        if (-d '/home/groups/guest-share/user/en') {
            system('/bin/rm -rf /home/groups/guest-share/user');
        }

        if (-d '/home/groups/guest-share/group/en') {
            system('/bin/rm -rf /home/groups/guest-share/group');
        }
    }
    elsif (-d $raq_home) {
        # just spit out a place holder for now
        open(INDEX, ">$raq_home/index.html");
        print INDEX <<INDEXHTML;
<HTML>
<HEAD>
<META HTTP-EQUIV="expires" CONTENT="-1">
<META HTTP-EQUIV="Pragma" CONTENT="no-cache">
</HEAD>
<BODY onLoad="location='http://'+location.host+':444/login/'">
</BODY>
</HTML>
INDEXHTML
        close(INDEX);
        chmod(0644, "$raq_home/index.html");

        #
        # setup the tmp directories since this means they just
        # finished the setup wizard
        #
        service_set_init('tmpinit', 'on', '12345');
        service_run_init('tmpinit', '', 'nobg');
    }

    # always wipe out the index.html file in the admin web directory
    open(INDEX, ">$admserv_index");
    print INDEX <<INDEXHTML;
<HTML>
<HEAD>
<META HTTP-EQUIV="expires" CONTENT="-1">
<META HTTP-EQUIV="Pragma" CONTENT="no-cache">
</HEAD>
<BODY onLoad="location='http://'+location.host+'/login/'">
</BODY>
</HTML>
INDEXHTML
        close(INDEX);
        chmod(0644, $admserv_index);
}
elsif (@ARGV < 2) {
    print STDERR "Usage:  $0 <filename> <destination filename>\n";
    exit(1);
}
else {

    my $file = shift @ARGV;
    my $dest = shift @ARGV;

    copy($file, $dest);

}
exit(0);

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