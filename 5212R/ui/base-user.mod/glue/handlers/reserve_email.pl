#!/usr/bin/perl -I/usr/sausalito/perl
# 
# reserve the user alias for this user

use CCE;

my $cce = new CCE;
$cce->connectfd();

my $user = $cce->event_object();
my $user_new = $cce->event_new();
my $user_old = $cce->event_old();

my $ok = 0;
my $vsite = {};

# Debugging switch:
$DEBUG = "0";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

&debug_msg("Start-Up: $0\n");

# get vsite info for creates and modifies
if (!$cce->event_is_destroy() && $user->{site}) {
    my ($vsoid) = $cce->find('Vsite', { 'name' => $user->{site} });
    if ($vsoid) {
        ($ok, $vsite) = $cce->get($vsoid);

        if (!$ok) {
            &debug_msg("[[base-user.cantReadVsite]]\n");
            $cce->bye('FAIL', '[[base-user.cantReadVsite]]');
            exit(1);
        }
    }
}

my $oid = 0;

if (!$cce->event_is_create() && (exists($user_new->{name}) || exists($user_new->{site}) || $cce->event_is_destroy())) {
    ($oid) = $cce->find('EmailAlias',
                    {
                        'alias' => $user_old->{name},
                        'site' => $user_old->{site}
                    });
}

# do what is supposed to happen
if ($cce->event_is_destroy()) {
    ($ok) = $cce->destroy($oid);

    if ($oid && !$ok) {
        &debug_msg("[[base-user.cantDeleteEmailAlias]]\n");
        $cce->bye('FAIL', '[[base-user.cantDeleteEmailAlias]]');
        exit(1);
    }
}
elsif (!$oid) {

    if ((!$user->{name}) && ($user_new->{name})) {
        $user->{name} = $user_new->{name};
    }

    if ((!$user->{site}) && ($user_new->{site})) {
        $user->{site} = $user_new->{site};
    }

    if ((!$user->{action}) && ($user_new->{action})) {
        $user->{action} = $user_new->{action};
    }

    if ((!$user->{fqdn}) && ($user_new->{fqdn})) {
        $user->{fqdn} = $user_new->{fqdn};
    }

    # create
    ($ok) = $cce->create('EmailAlias',
                        {
                            'alias' => $user->{name},
                            'site' => $user->{site},
                            'action' => $user->{name},
                            'fqdn' => $vsite->{fqdn}
                        });
    if (!$ok) {
        $cce->bye('FAIL');
        exit(1);
    }
}
else {
    # modify
    ($ok) = $cce->set($oid, '',
                    {
                        'alias' => $user->{name},
                        'site' => $user->{site},
                        'action' => $user->{name},
                        'fqdn' => $vsite->{fqdn}
                    });

    if (!$ok) {
        &debug_msg("Ultimate failure at end of script.\n");
        $cce->bye('FAIL');
        exit(1);
    }
}

$cce->bye('SUCCESS');
exit(0);

# For debugging:
sub debug_msg {
    if ($DEBUG) {
        my $msg = shift;
        setlogsock('unix');
        openlog($0,'ndelay','user');
        syslog('LOG_INFO|LOG_LOCAL0', "$ARGV[0]: $msg");
        closelog();
    }
}

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