#!/usr/bin/perl -w -I/usr/sausalito/perl/ -I/usr/sausalito/handlers/base/email
# $Id: personal.pl
#
# translate the User.Email.aliases property into EmailAlias
# objects.  Temporary fix since I don't feel like rewriting the UI tonight.

use CCE;
use Email;
use Sauce::Util;

# Debugging switch:
$DEBUG = "0";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

my $fail = 0;

my $cce = new CCE (Namespace => "Email", Domain => 'base-email');
$cce->connectfd();

my $oid = $cce->event_oid();
my $old = $cce->event_old();
my $new = $cce->event_new();
my $obj = $cce->event_object();

my ($ok, $user_obj, $user_old, $user_new) = $cce->get($oid);

if ($cce->event_is_create() && !$user_obj->{name}) {
    &debug_msg("Defering CCE transaction.");
    $cce->bye('DEFER');
    exit(0);
}

# so to make this easy, no longer use aliases
# just put everything in virtuser, appending the system fqdn if the user
# is not part of a site, and appending the site fqdn if the user is a member
# of a site
if ($cce->event_is_destroy()) {
    for my $alias ($cce->scalar_to_array($old->{aliases})) {
        my ($aoid) = $cce->find('EmailAlias',
                        {
                            'alias' => $alias,
                            'action' => $user_old->{name},
                            'site' => $user_old->{site}
                        });
        if ($aoid) {
            &debug_msg("Destroying OID $aoid");
            $cce->destroy($aoid);
        }
    }
}
else {
    my $fqdn = '';
    my $vsite = {};
    if ($user_obj->{site}) {
        my ($vsoid) = $cce->find('Vsite', { 'name' => $user_obj->{site} });
        ($ok, $vsite) = $cce->get($vsoid);

        if (!$ok) {
            $cce->bye('FAIL', 'cantReadVsite', { 'name' => $user_obj->{site} });
            &debug_msg("FAIL: 'cantReadVsite'");
            exit(1);
        }
        $fqdn = $vsite->{fqdn};
    }

    # info to use when finding aliases
    my $find_action = (exists($user_new->{name}) ? $user_old->{name} : $user_obj->{name});
    my $find_site = (exists($user_new->{site}) ? $user_old->{site} : $user_obj->{site});
    my %new_aliases = map { $_ => 1 } $cce->scalar_to_array($obj->{aliases});

    for my $alias (keys(%new_aliases)) {
        # sanity check
        if (!$alias) { next; }
        
        if (!$cce->event_is_create()) {
            ($oid) = $cce->find('EmailAlias',
                            {
                                'alias' => $alias,
                                'action' => $find_action,
                                'site' => $find_site
                            });
            if ($oid) {
                ($ok) = $cce->set($oid, '',
                                {
                                    'action' => $user_obj->{name},
                                    'site' => $user_obj->{site},
                                    'alias' => $alias,
                                    'fqdn' => $fqdn
                                });
                if (!$ok) {
                    $cce->bye('FAIL', '[[base-email.cantUpdateAlias]]');
                    &debug_msg("FAIL: 'cantUpdateAlias'");
                    exit(1);
                }

                # go to next alias
                next;
            }
        }

        # need to create the alias if we got here
        ($ok) = $cce->create('EmailAlias',
                        {
                            'alias' => $alias,
                            'action' => $user_obj->{name},
                            'site' => $user_obj->{site},
                            'fqdn' => $fqdn
                        });
        if (!$ok) {
            $cce->bye('FAIL', '[[base-email.cantCreateAlias]]');
            &debug_msg("FAIL: 'cantCreateAlias'");
            exit(1);
        }
    }

    # delete old aliases as necessary
    my @old_aliases = $cce->scalar_to_array($old->{aliases});

    for my $alias (@old_aliases) {
        if (!exists($new_aliases{$alias})) {
            ($oid) = $cce->find('EmailAlias',
                                {
                                    'action' => $find_action,
                                    'alias' => $alias,
                                    'site' => $find_site
                                });
            if ($oid) {
                &debug_msg("Destroying OID $oid");
                $cce->destroy($oid);
            }
        }
    }
}

# Restart Sendmail on an as needed basis:
my ($sys_oid) = $cce->find('System');
(my $ok, $obj) = $cce->get($sys_oid, 'Email');
if ($ok){
    Sauce::Service::service_run_init('sendmail', 'stop') if $obj->{enableSMTP};
    Sauce::Service::service_run_init('sendmail', 'restart') if $obj->{enableSMTP};
}
    
$cce->bye('SUCCESS');
exit 0;

#
### Subs:
#

sub debug_msg {
    if ($DEBUG) {
        my $msg = shift;
        setlogsock('unix');
        openlog($0,'','user');
        syslog('info', "$ARGV[0]: $msg");
        closelog;
    }
}

# 
# Copyright (c) 2008-2024 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2024 Team BlueOnyx, BLUEONYX.IT
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