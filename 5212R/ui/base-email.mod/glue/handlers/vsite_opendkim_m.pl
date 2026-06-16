#!/usr/bin/perl -I/usr/sausalito/perl -I/usr/sausalito/handlers/base/email
# $Id: vsite_opendkim_m.pl
#

# Debugging switch:
$DEBUG = "0";
if ($DEBUG) {
  use Sys::Syslog qw( :DEFAULT setlogsock);
}

use CCE;
use Email;
use Sauce::Service;
use Sauce::Util;

&debug_msg("Startup vsite_opendkim_m.pl");

my $cce = new CCE;
$cce->connectfd();

my $Vsite_oid = $cce->event_oid();
&debug_msg("OID is: $Vsite_oid");

my ($ok, $Vsite) = $cce->get($Vsite_oid);
my ($ok, $Vsite_Email) = $cce->get($Vsite_oid, "Email");
my ($ok, $Vsite_OpenDKIM) = $cce->get($Vsite_oid, 'OpenDKIM');

my $group = $Vsite->{'site'};
my $VirtualSite = $Vsite->{'fqdn'};
my $VirtualDomain = $Vsite->{'domain'};

# OpenDKIM-Genkey: 
$OpenDKIM_Genkey = '/usr/sbin/opendkim-genkey';

# OpenDKIM: Config file:
$OpenDKIM_cfg = '/etc/opendkim.conf';
$OpenDKIM_dir = '/etc/opendkim';
$OpenDKIM_key_dir = '/etc/opendkim/keys';
$OpenDKIM_KeyTable = '/etc/opendkim/KeyTable';
$OpenDKIM_SigningTable = '/etc/opendkim/SigningTable';

my @oids = $cce->find('System');
if (not @oids) {
    $cce->bye('FAIL');
    exit 1;
}

my ($ok, $System) = $cce->get($oids[0]);
unless ($ok and $System) {
    $cce->bye('FAIL');
    exit 1;
}
my ($ok, $System_Email) = $cce->get($oids[0], 'Email');
unless ($ok and $System_Email) {
    $cce->bye('FAIL');
    exit 1;
}
my ($ok, $System_DNS) = $cce->get($oids[0], 'DNS');
unless ($ok and $System_DNS) {
    $cce->bye('FAIL');
    exit 1;
}

if (! -d $sendmail_milters_d) {
    system("mkdir $sendmail_milters_d");
    system("chmod 0644 $sendmail_milters_d");
    system("chown root:root $sendmail_milters_d");
}

#
### Walk through all Vsites and get a list of all possible FQDNs that we need to include in the DKIM alias list:
#

@AllAliasesOfDomain = ();
$all_domain_aliases = {};
$NumVsites_of_this_Domain = '0';
@AllDomainOids = $cce->findx('Vsite', {}, {'domain' => $VirtualDomain});
foreach my $x (@AllDomainOids) {
    $NumVsites_of_this_Domain++;
    my ($ok, $ThisVsite) = $cce->get($x);
    $all_domain_aliases->{$ThisVsite->{'fqdn'}} = $ThisVsite->{'fqdn'};
    $all_domain_aliases->{$ThisVsite->{'domain'}} = $ThisVsite->{'domain'};

    @Vsite_Email_Aliases = $cce->scalar_to_array($ThisVsite->{'mailAliases'});
    foreach my $a (@Vsite_Email_Aliases) {
        $all_domain_aliases->{$a} = $a;
    }
}
@AllAliasesOfDomain = ();
while (my ($key, $value) = each %{ $all_domain_aliases }) {
    push(@AllAliasesOfDomain, $value);
}

&debug_msg("We are tasked to fiddle with OpenDKIM on Vsites of hostname $VirtualDomain and this was triggered via $VirtualSite");

# Edit KeyTable:
$ret = Sauce::Util::editfile($OpenDKIM_KeyTable, *add_domain_to_KeyTable, $VirtualDomain);
if (!$ret) {
    &debug_msg("Failed to edit $OpenDKIM_KeyTable!");
    $cce->bye('FAIL', 'cantEditFile', {'file' => $OpenDKIM_KeyTable});
    exit(0);
}

# Edit SigningTable:
$ret = Sauce::Util::editfile($OpenDKIM_SigningTable, *add_domain_to_SigningTable, $VirtualDomain, $all_domain_aliases);
if (!$ret) {
    &debug_msg("Failed to edit $OpenDKIM_SigningTable!");
    $cce->bye('FAIL', 'cantEditFile', {'file' => $OpenDKIM_SigningTable});
    exit(0);
}

# Set Vsite NameSpace 'OpenDKIM' to 'enabled' => '1' for all Vsites of this hostname:
foreach my $x (@AllDomainOids) {
    ($ok) = $cce->set($x, 'OpenDKIM', { 'enabled' => '1' });
}

# Fix ownerships:
system("chown -R opendkim:opendkim $OpenDKIM_dir");

# Restart service:
if ($System_Email->{'enableOpenDKIM'} eq '1') {
    Sauce::Service::service_run_init('opendkim', 'restart');
}

$cce->bye("SUCCESS");
exit(0);

#
### Subs:
#

sub add_domain_to_KeyTable {
    my $in  = shift;
    my $out = shift;
    my $VirtualDomain = shift;

    &debug_msg("Editing $OpenDKIM_KeyTable");

    $purge_record = 'default._domainkey.' . $VirtualDomain;

    select $out;
    while( <$in> ) {
        if ( /^$purge_record(.*)$/o ) {
            &debug_msg("Found DKIM record pertaining to $VirtualDomain in $OpenDKIM_KeyTable. Dropping it!");
        }
        else {
            print $_;
        }
    }

    &debug_msg("Adding: default._domainkey.$VirtualDomain $VirtualDomain:default:/etc/opendkim/keys/$VirtualDomain/default.private");
    print "default._domainkey.$VirtualDomain $VirtualDomain:default:/etc/opendkim/keys/$VirtualDomain/default.private\n";

    &debug_msg("Editing Done!");
    return 1;    
}

sub add_domain_to_SigningTable {
    my $in  = shift;
    my $out = shift;
    my $VirtualDomain = shift;
    my $all_domain_aliases = shift;

    &debug_msg("Editing $OpenDKIM_SigningTable with payload " . $Vsite->{'mailAliases'});

    $purge_record = 'default._domainkey.' . $VirtualDomain;

    select $out;
    while( <$in> ) {
        if ( /(.*)$purge_record$/o ) {
            &debug_msg("Found DKIM record pertaining to $VirtualDomain in $OpenDKIM_SigningTable. Dropping it!");
        }
        else {
            print $_;
        }
    }

    while (my ($key, $value) = each %{ $all_domain_aliases }) {
        &debug_msg("Adding: " . '*@' . $key . ' default._domainkey.' . $VirtualDomain);
        print '*@' . $key . ' default._domainkey.' . $VirtualDomain . "\n";
    }


    &debug_msg("Editing Done!");
    return 1;    
}

# Debug:
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
# Copyright (c) 2008-2023 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2023 Team BlueOnyx, BLUEONYX.IT
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