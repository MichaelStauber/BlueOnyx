#!/usr/bin/perl -I/usr/sausalito/perl
# $Id: import_sshd_settings.pl

# This script parses /etc/ssh/sshd_config and brings CODB up to date on how SSH is configured.

# Debugging switch:
$DEBUG = "0";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
    &debug_msg("Debug enabled.\n");
}

# Uncomment correct type:
$whatami = "constructor";
#$whatami = "handler";

# Location of sshd_config:
$sshd_config = "/etc/ssh/sshd_config";

#
#### No configureable options below!
#

use CCE;
use Data::Dumper;

my $cce = new CCE;

if ($whatami eq "handler") {
    $cce->connectfd();
}
else {
    $cce->connectuds();
}

# Full default sshd_config template (with trailing newline):
$full_default_sshd_config = <<'END_SSHD_CONFIG';
#       $OpenBSD: sshd_config,v 1.104 2021/07/02 05:11:21 dtucker Exp $

# This is the sshd server system-wide configuration file.  See
# sshd_config(5) for more information.

# This sshd was compiled with PATH=/usr/local/bin:/usr/bin:/usr/local/sbin:/usr/sbin

# The strategy used for options in the default sshd_config shipped with
# OpenSSH is to specify options with their default value where
# possible, but leave them commented.  Uncommented options override the
# default value.

# To modify the system-wide sshd configuration, create a  *.conf  file under
#  /etc/ssh/sshd_config.d/  which will be automatically included below
Include /etc/ssh/sshd_config.d/*.conf

# If you want to change the port on a SELinux system, you have to tell
# SELinux about this change.
# semanage port -a -t ssh_port_t -p tcp #PORTNUMBER
#
#Port 22
#AddressFamily any
#ListenAddress 0.0.0.0
#ListenAddress ::

#HostKey /etc/ssh/ssh_host_rsa_key
#HostKey /etc/ssh/ssh_host_ecdsa_key
#HostKey /etc/ssh/ssh_host_ed25519_key

# Ciphers and keying
#RekeyLimit default none

# Logging
#SyslogFacility AUTH
#LogLevel INFO

# Authentication:

#LoginGraceTime 2m
#PermitRootLogin prohibit-password
#StrictModes yes
#MaxAuthTries 6
#MaxSessions 10

#PubkeyAuthentication yes

# The default is to check both .ssh/authorized_keys and .ssh/authorized_keys2
# but this is overridden so installations will only check .ssh/authorized_keys
AuthorizedKeysFile      .ssh/authorized_keys

#AuthorizedPrincipalsFile none

#AuthorizedKeysCommand none
#AuthorizedKeysCommandUser nobody

# For this to work you will also need host keys in /etc/ssh/ssh_known_hosts
#HostbasedAuthentication no
# Change to yes if you don't trust ~/.ssh/known_hosts for
# HostbasedAuthentication
#IgnoreUserKnownHosts no
# Don't read the user's ~/.rhosts and ~/.shosts files
#IgnoreRhosts yes

# To disable tunneled clear text passwords, change to no here!
#PasswordAuthentication yes
#PermitEmptyPasswords no

# Change to no to disable s/key passwords
#KbdInteractiveAuthentication yes

# Kerberos options
#KerberosAuthentication no
#KerberosOrLocalPasswd yes
#KerberosTicketCleanup yes
#KerberosGetAFSToken no
#KerberosUseKuserok yes

# GSSAPI options
#GSSAPIAuthentication no
#GSSAPICleanupCredentials yes
#GSSAPIStrictAcceptorCheck yes
#GSSAPIKeyExchange no
#GSSAPIEnablek5users no

# Set this to 'yes' to enable PAM authentication, account processing,
# and session processing. If this is enabled, PAM authentication will
# be allowed through the KbdInteractiveAuthentication and
# PasswordAuthentication.  Depending on your PAM configuration,
# PAM authentication via KbdInteractiveAuthentication may bypass
# the setting of "PermitRootLogin without-password".
# If you just want the PAM account and session checks to run without
# PAM authentication, then enable this but set PasswordAuthentication
# and KbdInteractiveAuthentication to 'no'.
# WARNING: 'UsePAM no' is not supported in Fedora and may cause several
# problems.
#UsePAM no

#AllowAgentForwarding yes
#AllowTcpForwarding yes
#GatewayPorts no
#X11Forwarding no
#X11DisplayOffset 10
#X11UseLocalhost yes
#PermitTTY yes
#PrintMotd yes
#PrintLastLog yes
#TCPKeepAlive yes
#PermitUserEnvironment no
#Compression delayed
#ClientAliveInterval 0
#ClientAliveCountMax 3
#UseDNS no
#PidFile /var/run/sshd.pid
#MaxStartups 10:30:100
#PermitTunnel no
#ChrootDirectory none
#VersionAddendum none

# no default banner path
#Banner none

# override default of no subsystems
Subsystem       sftp    /usr/libexec/openssh/sftp-server

# Example of overriding settings on a per-user basis
#Match User anoncvs
#       X11Forwarding no
#       AllowTcpForwarding no
#       PermitTTY no
#       ForceCommand cvs server
PermitRootLogin without-password
StrictModes no
AllowTcpForwarding no
PasswordAuthentication yes
X11Forwarding no
X11DisplayOffset 10
PubkeyAuthentication yes
Protocol 2
Port 22
END_SSHD_CONFIG


# Array setup:
@yes = ('Yes', 'yes', '1');
@boolKeys = ('PermitRootLogin', 'PasswordAuthentication', 'RSAAuthentication', 'PubkeyAuthentication', 'GoogleAuthentication', 'AllowTcpForwarding');

# Config file present?
if (-f $sshd_config) {

    # Check if sshd_config has been truncated by cloud-init:
    &check_truncated_sshd_config();

    # Array of config switches that we want to update in CCE:
    &items_of_interest;

    # Read, parse and hash config:
    &ini_read;
        
    # Verify input and set defaults if needed:
    &verify;
        
    # Shove ouput into CCE:
    &feedthemonster;
}
else {
    # Ok, we have a problem: No config file found.
    # So we just weep silently and exit. 
    $cce->bye('FAIL', "$sshd_config not found!");
    exit(1);
}

$cce->bye('SUCCESS');
exit(0);

#
### Subs:
#

sub check_truncated_sshd_config {
    my $line_count = 0;
    my %recovered = ();
    my $has_sftp_subsystem = 0;

    open(F, $sshd_config) or return;
    while (my $line = <F>) {
        next if $line =~ /^\s*$/ || $line =~ /^\s*#/;
        $line_count++;
        if ($line =~ /^(\S+)\s+(.*)$/) {
            $recovered{$1} = $2;
        }
        if ($line =~ /^Subsystem\s+sftp\s+\/usr\/libexec\/openssh\/sftp-server\s*$/) {
            $has_sftp_subsystem = 1;
        }
    }
    close(F);

    if ($line_count < 5 || !$has_sftp_subsystem) {
        &debug_msg("Detected invalid sshd_config: $line_count non-comment lines, SFTP Subsystem present? $has_sftp_subsystem. Rebuilding.\n");

        # Create a temporary file
        my $tmpfile = "$sshd_config.new";
        open(my $fh, ">", $tmpfile) or die "Cannot write $tmpfile: $!";
        print $fh $full_default_sshd_config;
        close($fh);

        # Read the new content into array
        open(my $in, "<", $tmpfile) or die "Cannot read $tmpfile: $!";
        my @new_lines;
        while (my $line = <$in>) {
            if ($line =~ /^(\S+)\s+(.*)$/ && exists $recovered{$1}) {
                $line = "$1 $recovered{$1}\n";
            }
            push @new_lines, $line;
        }
        close($in);

        # Replace original config
        open(my $out, ">", $sshd_config) or die "Cannot overwrite $sshd_config: $!";
        print $out @new_lines;
        close($out);

        unlink $tmpfile;
        &debug_msg("sshd_config restored with merged settings from invalid config.\n");
    }
}

# Read and parse config:
sub ini_read {
    open (F, $sshd_config) || die "Could not open $sshd_config: $!";

    while ($line = <F>) {
        chomp($line);
        next if $line =~ /^\s*$/;                   # skip blank lines
        next if $line =~ /^\#*$/;                   # skip comment lines
        if ($line =~ /^([A-Za-z_\.]\w*)/) {     
            $line =~s/\#(.*)$//g;                   # Remove trailing comments in lines
            $line =~s/\"//g;                        # Remove double quotation marks

            @row = split (/ /, $line);              # Split row at the delimiter
            &debug_msg("Reading: $row[0] - $row[1] \n");
            $CONFIG{$row[0]} = $row[1];             # Hash the splitted row elements
        }
    }
    close(F);

    # At this point we have all switches from the config cleanly in a hash, split in key / value pairs.
    # To read to which value "key" is set we query $CONFIG{'key'} for example. 

}

sub verify {

    # Find out if we have ever run before:
    @oid = $cce->find('System');
    ($ok, $sshd_settings) = $cce->get($oid, "SSH");

    if ($#oids < 0) {
        $first_run = "1";
    }
    else {
        if ($sshd_settings{'force_update'} eq "") {
            $first_run = "1";
        }
        else {
            $first_run = "0";
        }
    }

    # Go through list of config switches we're interested in:
    foreach $entry (@whatweneed) {
        if (!$CONFIG{"$entry"}) {
            # Found key without value - setting defaults for those that need it:
            if ($entry eq "PermitRootLogin") {
                &debug_msg("Defaulting: $entry - $CONFIG{$entry}\n");
                $CONFIG{"$entry"} = "0";
            }
            if ($entry eq "Protocol") {
                &debug_msg("Defaulting: $entry - $CONFIG{$entry}\n");
                $CONFIG{"$entry"} = "2";
            }
            if ($entry eq "Port") {
                &debug_msg("Defaulting: $entry - $CONFIG{$entry}\n");
                $CONFIG{"$entry"} = "22";
            }
            if ($entry eq "PasswordAuthentication") {
                &debug_msg("Defaulting: $entry - $CONFIG{$entry}\n");
                $CONFIG{"$entry"} = "yes";
            }
            if ($entry eq "RSAAuthentication") {
                &debug_msg("Defaulting: $entry - $CONFIG{$entry}\n");
                $CONFIG{"$entry"} = "no";
            }
            if ($entry eq "PubkeyAuthentication") {
                &debug_msg("Defaulting: $entry - $CONFIG{$entry}\n");
                $CONFIG{"$entry"} = "yes";
            }
            if ($entry eq "AllowTcpForwarding") {
                &debug_msg("Defaulting: $entry - $CONFIG{$entry}\n");
                $CONFIG{"$entry"} = "no";
            }
        }

        if ($CONFIG{Protocol} eq "2") {
            $CONFIG{RSAAuthentication} = "0";
        }

        # Convert selected config file values (No|no|Yes|yes) to bool (0|1) for CODB:
        if (in_array(\@boolKeys, $entry)) {
            if ($entry eq 'PermitRootLogin') {
                # Special case for tri-state option 'PermitRootLogin':
                if ($CONFIG{'PermitRootLogin'} eq 'without-password') {
                    $CONFIG{'PermitRootLogin'} = '2';
                }
                elsif (($CONFIG{'PermitRootLogin'} eq 'Yes') || ($CONFIG{'PermitRootLogin'} eq 'yes')) {
                    $CONFIG{'PermitRootLogin'} = '1';
                }
                else {
                    $CONFIG{'PermitRootLogin'} = '0';
                }
            }
            else {
                # Handly only the true boolKeys:
                if (in_array(\@yes, $CONFIG{$entry})) {
                    $CONFIG{$entry} = '1';
                }
                else {
                    $CONFIG{$entry} = '0';
                }
            }
        }

        # For debugging only:
        if ($DEBUG == "1") {
            print $entry . " = " . $CONFIG{"$entry"} . "\n";
        }
        &debug_msg("Post-Verify: $entry - $CONFIG{$entry}\n");
    }

    # Check state of Google-Authentication:
    $GoogleAuthentication = `cat /etc/ssh/sshd_config.d/* /etc/ssh/sshd_config|grep -v ^#|awk NF |grep ChallengeResponseAuthentication|grep yes|wc -l`;
    chomp($GoogleAuthentication);
    $CONFIG{'GoogleAuthentication'} = $GoogleAuthentication;
    print 'GoogleAuthentication' . " = " . $CONFIG{'GoogleAuthentication'} . "\n";
    &debug_msg("Post-Verify: GoogleAuthentication - $CONFIG{'GoogleAuthentication'}\n");
}

sub feedthemonster {

    if ($DEBUG == "1") {
        foreach $entry (@whatweneed) {
            print $entry . " = " . $CONFIG{"$entry"} . "\n";
        }
    }

    @oid = $cce->find('System');
    ($ok, $sshd_settings) = $cce->get($oid);

        # Object already present in CCE. Updating it.
        ($sys_oid) = $cce->find('System');
        ($ok, $sys) = $cce->get($sys_oid);
        ($ok) = $cce->update($sys_oid, 'SSH',{
            'Port' => $CONFIG{"Port"},  
            'Protocol' => $CONFIG{"Protocol"},   
            'PermitRootLogin' => $CONFIG{"PermitRootLogin"},
            'XPasswordAuthentication' => $CONFIG{"PasswordAuthentication"},
            'RSAAuthentication' => '0',
            'PubkeyAuthentication' => $CONFIG{"PubkeyAuthentication"},
            'GoogleAuthentication' => $CONFIG{"GoogleAuthentication"},
            'AllowTcpForwarding' => $CONFIG{"AllowTcpForwarding"},
            'force_update' => time()  
        });
    

}

sub items_of_interest {
    # List of config switches that we're interested in:
    @whatweneed = ( 
        'PermitRootLogin', 
        'Protocol', 
        'Port',
        'PasswordAuthentication',
        'RSAAuthentication',
        'PubkeyAuthentication',
        'AllowTcpForwarding'
    );
}

sub in_array {
    my ($arr,$search_for) = @_;
    my %items = map {$_ => 1} @$arr; # create a hash out of the array values
    return (exists($items{$search_for}))?1:0;
}

sub debug_msg {
    if ($DEBUG) {
        my $msg = shift;
        setlogsock('unix');
        openlog($0,'','user');
        syslog('info', "$ARGV[0]: $msg");
        closelog;
    }
}

$cce->bye('SUCCESS');
exit(0);

# 
# Copyright (c) 2008-2025 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2025 Team BlueOnyx, BLUEONYX.IT
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