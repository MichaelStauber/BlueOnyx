#!/usr/bin/perl -I/usr/sausalito/perl -I/usr/sausalito/handlers/base/email
# $Id: vsite_opendkim_d.pl
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
use Data::Dumper;

&debug_msg("Startup vsite_opendkim_d.pl");

my $cce = new CCE;
$cce->connectfd();

my $old = $cce->event_old();
if ($cce->event_is_destroy()) {
    $Vsite_oid = $old->{'OID'};
    $group = $old->{'site'};
    $VirtualSite = $old->{'fqdn'};
    $VirtualDomain = $old->{'domain'};
    $Vsite = $old;
    &debug_msg("THIS IS A DESTROY EVENT FOR FQDN: $VirtualSite of domain $VirtualDomain");
}
else {
    # Not a destroy() event? Bye!
    $cce->bye("SUCCESS");
    exit(0);
}

&debug_msg("OID is: $Vsite_oid");

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

&debug_msg("We are tasked to handle a deletion of OpenDKIM on Vsites of hostname $VirtualDomain and this was triggered via $VirtualSite");

    # Is this the last Vsite belonging to this domain?
    if ($NumVsites_of_this_Domain == '0') {
        # It is! We ned to remove the Key from the KeyTable and all related entries from the SigningTable:

        # Remove Domain entry from KeyTable:
        $ret = Sauce::Util::editfile($OpenDKIM_KeyTable, *remove_domain_from_KeyTable, $VirtualDomain);
        if (!$ret) {
            &debug_msg("Failed to edit $OpenDKIM_KeyTable!");
            $cce->bye('FAIL', 'cantEditFile', {'file' => $OpenDKIM_KeyTable});
            exit(0);
        }

        # Remove Domain entry from SigningTable:
        $ret = Sauce::Util::editfile($OpenDKIM_SigningTable, *remove_domain_from_SigningTable, $VirtualDomain);
        if (!$ret) {
            &debug_msg("Failed to edit $OpenDKIM_KeyTable!");
            $cce->bye('FAIL', 'cantEditFile', {'file' => $OpenDKIM_SigningTable});
            exit(0);
        }

        # Remove DNS TXT record if possible:
        &debug_msg("Checking if 'DnsRecord' for hostname 'default._domainkey' and domainname '$VirtualDomain' of type 'TXT' exists.");
        my (@DNS_oids) = $cce->find('DnsRecord', { 'hostname' => 'default._domainkey', 'domainname' => $VirtualDomain, 'type' => 'TXT'});
        $need_update_dns = '0';
        foreach my $fx (@DNS_oids)  {
            &debug_msg("Need to remove DnsRecord with OID $fx");
            $ok = $cce->destroy($fx);
            $need_update_dns++;
        }
        if ($need_update_dns gt '0') {
            $ok = $cce->set($oids[0], 'DNS', { 'commit' => time() });
        }

        # Remove key as well:
        $vsite_dkim_dir = $OpenDKIM_key_dir . '/' . $VirtualDomain;
        if (-d $vsite_dkim_dir) {
            &debug_msg("Removing Vsite DKIM Key directory $vsite_dkim_dir");
            system("rm -Rf $vsite_dkim_dir");
        }

        # Fix ownerships:
        system("chown -R opendkim:opendkim $OpenDKIM_dir");

        # Restart service:
        if ($System_Email->{'enableOpenDKIM'} eq '1') {
            Sauce::Service::service_run_init('opendkim', 'restart');
        }
    }
    else {
        #
        # Now we need to be clever:
        #
        # There are two or more Vsites with the same domain name, but different FQDNs. We're deleting one of them.
        # Means: We keep the TXT DNS record, we keep the KeyTable and the key directory. BUT: We need to clean up
        # the SigningTable and remove all aliases that the deleted Vsite was using. 
        # 

        # Build a hash-ref with all aliases pertaining to this Vsite:
        $this_domain_aliases = {};
        $this_domain_aliases->{$Vsite->{'fqdn'}} = $Vsite->{'fqdn'};
        @Vsite_Email_Aliases = $cce->scalar_to_array($Vsite->{'mailAliases'});
        foreach my $a (@Vsite_Email_Aliases) {
            $this_domain_aliases->{$a} = $a;
        }

        # Selectively Remove Domain entries from SigningTable:
        $ret = Sauce::Util::editfile($OpenDKIM_SigningTable, *selectively_remove_domain_from_SigningTable, $VirtualDomain, $this_domain_aliases);
        if (!$ret) {
            &debug_msg("Failed to edit $OpenDKIM_KeyTable!");
            $cce->bye('FAIL', 'cantEditFile', {'file' => $OpenDKIM_SigningTable});
            exit(0);
        }
    }

$cce->bye("SUCCESS");
exit(0);

#
### Subs:
#

sub selectively_remove_domain_from_SigningTable {
    my $in  = shift;
    my $out = shift;
    my $VirtualDomain = shift;
    my $this_domain_aliases = shift;

    &debug_msg("Selectively Editing $OpenDKIM_SigningTable");

    @purge_records = ();

    while (my ($key, $value) = each %{ $this_domain_aliases }) {
        &debug_msg("Adding: " . '*@' . $key . ' default._domainkey.' . $VirtualDomain . " to deletion filter");
        push(@purge_records, '*@' . $key . ' default._domainkey.' . $VirtualDomain . "\n");
    }

    select $out;
    while( <$in> ) {
        $print_me = $_;
        if (in_array(\@purge_records, $print_me)) {
            &debug_msg("DKIM record of $VirtualDomain in $OpenDKIM_SigningTable needs dropping: $print_me");
        }
        else {
            print $print_me;
        }
    }
    &debug_msg("Editing Done!");
    return 1;    
}

sub in_array {
    my ($arr,$search_for) = @_;
    my %items = map {$_ => 1} @$arr; # create a hash out of the array values
    return (exists($items{$search_for}))?1:0;
}

sub remove_domain_from_KeyTable {
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
    &debug_msg("Editing Done!");
    return 1;    
}

sub remove_domain_from_SigningTable {
    my $in  = shift;
    my $out = shift;
    my $VirtualDomain = shift;

    &debug_msg("Editing $OpenDKIM_SigningTable");

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