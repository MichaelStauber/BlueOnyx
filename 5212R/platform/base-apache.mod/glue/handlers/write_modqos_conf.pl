#!/usr/bin/perl -I/usr/sausalito/perl

use strict;
use warnings;
use CCE;
use File::Temp qw(tempfile);
use File::Copy qw(copy);
use Sauce::Service;
use Sys::Syslog qw(:DEFAULT setlogsock);

my $cce = new CCE;
$cce->connectfd();

my ($sys_oid, $state) = load_modqos_state($cce);
my @rules = load_rules($cce);

log_msg("Regenerating /etc/httpd/conf.d/00-mod_qos.conf");

my $config = build_config($state, \@rules);
my ($target, $backup, $had_backup) = stage_config($config);

my ($ok, $output) = validate_httpd();
if (!$ok) {
    log_msg("httpd -t failed, restoring previous config.");
    log_msg($output) if $output;
    restore_previous($target, $backup, $had_backup);
    $cce->bye('FAIL', '[[base-apache.modQosConfigValidationFailed]]');
    exit(1);
}

log_msg("httpd -t succeeded.");

if (int($state->{reload} // 0)) {
    log_msg("Reloading Apache via systemctl reload httpd");
    if (!reload_httpd()) {
        log_msg("Apache reload failed.");
        $cce->bye('FAIL', '[[base-apache.modQosReloadFailed]]');
        exit(1);
    }
    log_msg("Apache reload succeeded.");
}

$cce->bye('SUCCESS');
exit(0);

#
### Subs:
#

sub log_msg {
    my ($msg) = @_;
    setlogsock('unix');
    openlog($0, '', 'user');
    syslog('info', "$ARGV[0]: $msg");
    closelog;
}

sub fail {
    my ($msg, $cce_msg) = @_;
    log_msg($msg);
    $cce->bye('FAIL', $cce_msg || '[[base-apache.modQosWriteFailed]]');
    exit(1);
}

sub load_modqos_state {
    my ($cce) = @_;
    my ($sys_oid) = $cce->find('System');
    if (!$sys_oid) {
        fail('System object not found.', '[[base-apache.systemObjectNotFound]]');
    }

    my ($ok, $state) = $cce->get($sys_oid, 'modQos');
    $state ||= {};

    return ($sys_oid, $state);
}

sub load_rules {
    my ($cce) = @_;
    my @rows;
    my @oids = $cce->find('ModQosRule');

    for my $oid (@oids) {
        my ($ok, $rule) = $cce->get($oid);
        next if (!$ok || ref($rule) ne 'HASH' || !%$rule);

        push @rows, {
            oid         => $oid,
            enabled     => int($rule->{enabled} // 0),
            description => $rule->{description} // '',
            regex       => $rule->{regex} // '',
            weight      => int($rule->{weight} // 1),
            eventRequest => int($rule->{eventRequest} // 0),
            sortOrder   => int($rule->{sortOrder} // 10),
        };
    }

    @rows = sort {
        ($a->{sortOrder} <=> $b->{sortOrder})
            || ($a->{description} cmp $b->{description})
    } @rows;

    return @rows;
}

sub build_config {
    my ($state, $rules) = @_;

    if (!int($state->{enabled} // 0)) {
        return "# Managed by BlueOnyx. Apache request throttling disabled.\n";
    }

    my @out;
    push @out, "# Managed by BlueOnyx. Manual changes inside this file may be overwritten.";
    push @out, "<IfModule qos_module>";
    push @out, sprintf("    QS_ClientEntries %d", int($state->{clientEntries} // 0));
    push @out, sprintf(
        "    QS_SrvMaxConnPerIP %d %d",
        int($state->{srvMaxConnPerIP} // 0),
        int($state->{srvMaxConnBusyThreshold} // 0)
    );
    push @out, sprintf(
        "    QS_SrvMinDataRate %d %d %d",
        int($state->{minDataRate} // 0),
        int($state->{maxDataRate} // 0),
        int($state->{minDataRateBusyThreshold} // 0)
    );
    push @out, '';

    if (int($state->{dynamicEnabled} // 0)) {
        for my $rule (@{$rules}) {
            next if !int($rule->{enabled} // 0);
            my $regex = $rule->{regex} // '';
            my $weight = int($rule->{weight} // 1);
            push @out, sprintf('    SetEnvIf Request_URI "%s" QS_Limit=%d', $regex, $weight);
            if (int($rule->{eventRequest} // 0)) {
                push @out, sprintf('    SetEnvIf Request_URI "%s" QS_EventRequest=1', $regex);
            }
            push @out, '';
        }
        push @out, sprintf("    QS_ClientEventRequestLimit %d", int($state->{eventRequestLimit} // 0));
        push @out, sprintf(
            "    QS_ClientEventLimitCount %d %d QS_Limit",
            int($state->{eventLimitCount} // 0),
            int($state->{eventLimitSeconds} // 0)
        );
        push @out, '';
    }

    if (int($state->{blockEnabled} // 0)) {
        push @out, sprintf(
            "    QS_ClientEventBlockCount %d %d",
            int($state->{blockCount} // 0),
            int($state->{blockSeconds} // 0)
        );
        push @out, '';
        push @out, sprintf("    QS_SetEnvIfStatus 400 QS_Block=%d", int($state->{weight400} // 1))
            if int($state->{count400} // 0);
        push @out, sprintf("    QS_SetEnvIfStatus 403 QS_Block=%d", int($state->{weight403} // 1))
            if int($state->{count403} // 0);
        push @out, sprintf("    QS_SetEnvIfStatus 404 QS_Block=%d", int($state->{weight404} // 1))
            if int($state->{count404} // 0);
        push @out, sprintf("    QS_SetEnvIfStatus 408 QS_Block=%d", int($state->{weight408} // 1))
            if int($state->{count408} // 0);
        push @out, sprintf("    QS_SetEnvIfStatus 500 QS_Block=%d", int($state->{weight500} // 0))
            if int($state->{count500} // 0);
        push @out, "    QS_SetEnvIfStatus BrokenConnection QS_Block" if int($state->{countBrokenConnection} // 0);
        push @out, "    QS_SetEnvIfStatus QS_SrvMinDataRate QS_Block" if int($state->{countMinDataRate} // 0);
        push @out, "    QS_SetEnvIfStatus QS_SrvMaxConnPerIP QS_Block" if int($state->{countMaxConnPerIP} // 0);
    }

    my $extra = $state->{extraDirectives} // '';
    $extra =~ s/\r\n/\n/g;
    $extra =~ s/\r/\n/g;
    $extra =~ s/\n+\z/\n/;
    if ($extra ne '') {
        push @out, '';
        for my $line (split(/\n/, $extra, -1)) {
            next if $line eq '';
            push @out, '    ' . $line;
        }
    }

    push @out, "</IfModule>";

    return join("\n", @out) . "\n";
}

sub stage_config {
    my ($config) = @_;
    my $target = '/etc/httpd/conf.d/00-mod_qos.conf';
    my $backup = $target . '.bak';
    my ($fh, $tmpfile) = tempfile('/tmp/modqosXXXX', SUFFIX => '.conf', UNLINK => 0);

    if (!$fh || !$tmpfile) {
        fail('Unable to create temporary file.', '[[base-apache.modQosWriteFailed]]');
    }

    print {$fh} $config;
    close($fh) or fail('Unable to flush temporary file.', '[[base-apache.modQosWriteFailed]]');

    my $had_backup = -f $target ? 1 : 0;
    if ($had_backup) {
        copy($target, $backup) or fail('Unable to create backup file.', '[[base-apache.modQosWriteFailed]]');
    }

    copy($tmpfile, $target) or do {
        unlink($tmpfile);
        if ($had_backup) {
            copy($backup, $target);
        }
        fail('Unable to write generated configuration.', '[[base-apache.modQosWriteFailed]]');
    };

    unlink($tmpfile);
    return ($target, $backup, $had_backup);
}

sub validate_httpd {
    my $output = `/usr/sbin/httpd -t 2>&1`;
    my $rc = $? >> 8;
    chomp($output);
    if ($rc != 0) {
        return (0, $output || 'httpd -t failed');
    }

    return (1, $output);
}

sub restore_previous {
    my ($target, $backup, $had_backup) = @_;
    if ($had_backup && -f $backup) {
        copy($backup, $target);
        return 1;
    }

    unlink($target);
    return 1;
}

sub reload_httpd {
    my $rc = system('/usr/bin/systemctl', 'reload', 'httpd');
    return ($rc == 0);
}

# 
# Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
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