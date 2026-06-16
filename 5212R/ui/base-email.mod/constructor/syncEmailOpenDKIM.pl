#!/usr/bin/perl -I. -I/usr/sausalito/perl -I/usr/sausalito/handlers/base/email
# $Id: syncOpenDKIM.pl

use Sauce::Util;
use Sauce::Config;
use Sauce::Service;
use CCE;
use Email;

# Debugging switch:
$DEBUG = "0";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

my $cce = new CCE;
$cce->connectuds();

# OpenDKIM: Config file:
$OpenDKIM_cfg = '/etc/opendkim.conf';
$OpenDKIM_dir = '/etc/opendkim';

# Sendmail milters.d directory:
$sendmail_milters_d = '/etc/mail/milters.d';
$sendmail_milters_sample = '/etc/mail/milters.d/00-sample.cf';
$sendmail_milters_opendkim = '/etc/mail/milters.d/05-opendkim.cf';
$postfix_milters_opendkim = '/etc/postfix/milters.d/05-opendkim.cf';

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

my $postfix_milters_d = '/etc/postfix/milters.d';
if (! -d $postfix_milters_d) {
    system("mkdir -p $postfix_milters_d");
    system("chmod 0755 $postfix_milters_d");
    system("chown root:root $postfix_milters_d");
}

if (! -d $sendmail_milters_d) {
    system("mkdir -p $sendmail_milters_d");
    system("chmod 0755 $sendmail_milters_d");
    system("chown root:root $sendmail_milters_d");
}

if (! -f $sendmail_milters_sample) {
    $sample_milter_text = "# BlueOnyx Sendmail Milter Configuration:\n";
    $sample_milter_text .= "# =======================================\n";
    $sample_milter_text .= "#\n";
    $sample_milter_text .= "# This directory is parsed by Sendmail for files ending with *.cf\n";
    $sample_milter_text .= "# If any files are found, comments and blank lines are stripped\n";
    $sample_milter_text .= "# and the content is added as milter to the sendmail.mc\n";
    $sample_milter_text .= "#\n";
    $sample_milter_text .= "# File names should start with numbers. Config files with lower \n";
    $sample_milter_text .= "# numbers will be put first.\n";
    $sample_milter_text .= "#\n";
    $sample_milter_text .= "# Example content:\n";
    $sample_milter_text .= "# \n";
    $sample_milter_text .= "# INPUT_MAIL_FILTER(`opendkim', `S=inet:8891\@localhost')\n";
    $sample_milter_text .= "#\n";
    $sample_milter_text .= "# Names reserved for the AV-SPAM:\n";
    $sample_milter_text .= "#\n";
    $sample_milter_text .= "# 01-milter-greylist.cf\n";
    $sample_milter_text .= "# 02-milter-geoip.cf\n";
    $sample_milter_text .= "# 03-spamassassin.cf\n";
    $sample_milter_text .= "# 04-clamav.cf\n";
    $sample_milter_text .= "#\n";
    $sample_milter_text .= "# Names reserved for BlueOnyx:\n";
    $sample_milter_text .= "#\n";
    $sample_milter_text .= "# 05-opendkim.cf\n";
    $sample_milter_text .= "#\n";
    open(FH, '>', $sendmail_milters_sample);
    print FH $sample_milter_text;
    close(FH);
    system("chmod 0644 $sendmail_milters_sample");
    system("chown root:root $sendmail_milters_sample");
}


# Fiddle the active RBL settings back into sendmail.mc:
$ret = Sauce::Util::editfile($OpenDKIM_cfg, *edit_OpenDKIM_config, $System_Email);
if (!$ret) {
    &debug_msg("Failed to edit $OpenDKIM_cfg!");
    $cce->bye('FAIL', 'cantEditFile', {'file' => $OpenDKIM_cfg});
    exit(0);
}

# Delete Backup configs:
system("rm -f /etc/opendkim.conf.backup.*");

if (-d $OpenDKIM_dir) {
    system("chown -R opendkim:opendkim $OpenDKIM_dir");
}

if ($System_Email->{'enableOpenDKIM'} eq '1') {
    &debug_msg("Enabling and restarting OpenDKIM service.");
    Sauce::Service::service_set_init('opendkim', 1);
    Sauce::Service::service_run_init('opendkim', 'restart');
    if (! -f $sendmail_milters_opendkim) {
        $sample_milter_text = "# BlueOnyx OpenDKIM Milter Configuration:\n";
        $sample_milter_text .= "# =======================================\n";
        $sample_milter_text .= "#\n";
        $sample_milter_text .= "INPUT_MAIL_FILTER(`opendkim', `S=inet:8891\@localhost')\n";
        open(FH, '>', $sendmail_milters_opendkim);
        print FH $sample_milter_text;
        close(FH);
        system("chmod 0644 $sendmail_milters_opendkim");
        system("chown root:root $sendmail_milters_opendkim");
    }

    if (! -f $postfix_milters_opendkim) {
        my $sample_milter_text = "";
        $sample_milter_text .= "# BlueOnyx Postfix OpenDKIM Milter Configuration:\n";
        $sample_milter_text .= "# ===============================================\n";
        $sample_milter_text .= "#\n";
        $sample_milter_text .= "inet:127.0.0.1:8891\n";

        open(FH, '>', $postfix_milters_opendkim);
        print FH $sample_milter_text;
        close(FH);
        system("chmod 0644 $postfix_milters_opendkim");
        system("chown root:root $postfix_milters_opendkim");
    }
}
else {
    &debug_msg("Disabling and stopping OpenDKIM service.");
    Sauce::Service::service_run_init('opendkim', 'stop');
    Sauce::Service::service_set_init('opendkim', 0);
    if (-f $sendmail_milters_opendkim) {
        system("rm -f $sendmail_milters_opendkim");
    }
    if (-f $postfix_milters_opendkim) {
        system("rm -f $postfix_milters_opendkim");
    }
}

$cce->bye('SUCCESS');
exit 0;

#
### Subs:
#

sub edit_OpenDKIM_config {
    my $in  = shift;
    my $out = shift;
    my $obj = shift;

    my $saw_socket = 0;   # <— track presence

    &debug_msg("Editing $OpenDKIM_cfg");

    select $out;
    while (<$in>) {
        if ( /^Mode\b(.*)$/o ) {
            &debug_msg("Editing Mode");
            print "Mode\t" . $obj->{'OpenDKIM_Mode'} . "\n";
        }
        elsif ( /^SendReports\b(.*)$/o ) {
            my $OpenDKIM_SendReports = ($obj->{'OpenDKIM_SendReports'} eq '1') ? 'yes' : 'no';
            &debug_msg("Editing SendReports");
            print "SendReports\t$OpenDKIM_SendReports\n";
        }
        elsif ( /^KeyFile\b(.*)$/o ) {
            &debug_msg("Editing KeyFile");
            print "#KeyFile\t/etc/opendkim/keys/default.private\n";
        }
        elsif ( /^(?:#\s*)?KeyTable\b(.*)$/o ) {
            &debug_msg("Editing KeyTable");
            print "KeyTable\t/etc/opendkim/KeyTable\n";
        }
        elsif ( /^(?:#\s*)?SigningTable\b(.*)$/o ) {
            &debug_msg("Editing SigningTable");
            print "SigningTable refile:/etc/opendkim/SigningTable\n";
        }
        elsif ( /^(?:#\s*)?ExternalIgnoreList\b(.*)$/o ) {
            &debug_msg("Editing ExternalIgnoreList");
            print "ExternalIgnoreList refile:/etc/opendkim/TrustedHosts\n";
        }
        elsif ( /^(?:#\s*)?InternalHosts\b(.*)$/o ) {
            &debug_msg("Editing InternalHosts");
            print "InternalHosts refile:/etc/opendkim/TrustedHosts\n";
        }
        elsif ( /^\s*Socket\b(.*)$/o ) {
            if (! $saw_socket) {
                &debug_msg("Editing Socket");
                print "Socket\tinet:8891\@localhost\n";
                $saw_socket = 1;
            }
        }
        else {
            print $_;
        }
    }

    # If there was no Socket line at all, append it:
    if (! $saw_socket) {
        &debug_msg("Appending Socket");
        print "Socket\tinet:8891\@localhost\n";
    }

    &debug_msg("Editing Done!");
    return 1;
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

# 
# Copyright (c) 2008-2025 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2025 Team BlueOnyx, BLUEONYX.IT
# All Rights Reserved.
# 
# 1. Redistributions of source code must retain the above copyright 
#     notice, this list of conditions and the following disclaimer.
# 
# 2. Redistributions in binary form must reproduce the above copyright 
#     notice, this list of conditions and the following disclaimer in 
#     the documentation and/or other materials provided with the 
#     distribution.
# 
# 3. Neither the name of the copyright holder nor the names of its 
#     contributors may be used to endorse or promote products derived 
#     from this software without specific prior written permission.
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