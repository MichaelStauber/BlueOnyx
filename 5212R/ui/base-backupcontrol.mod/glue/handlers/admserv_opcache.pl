#!/usr/bin/perl -I/usr/sausalito/perl
# $Id: admserv_opcache.pl
#
# Write /home/solarspeed/admserv-php/etc/php.d/opcache.ini and restart
# admserv-php-fpm via Sauce::Service (delayed).

use CCE;
use Sauce::Service;
use File::Find;

my $ini = '/home/solarspeed/admserv-php/etc/php.d/opcache.ini';
my $php_root = '/home/solarspeed/admserv-php';

my $cce = new CCE;
$cce->connectfd();

my $oid = $cce->event_oid();
my ($ok, $obj) = $cce->get($oid, 'DesktopControl');
if (!$ok || !defined($obj)) {
    $obj = $cce->event_object() || {};
}
my $delta = $cce->event_new();
if (ref($delta) eq 'HASH') {
    foreach my $k (keys %{$delta}) {
        next unless (defined($delta->{$k}));
        $obj->{$k} = $delta->{$k};
    }
}

my $so = &find_opcache_so($php_root);
if ($so eq '') {
    $cce->bye('FAIL', '[[base-backupcontrol.admserv_opcache_so_missing]]');
    exit(1);
}

my $dir = $ini;
$dir =~ s/\/opcache\.ini$//;
if (! -d $dir) {
    $cce->bye('FAIL', '[[base-backupcontrol.admserv_opcache_ini_dir_missing]]');
    exit(1);
}

my $body = &ini_body($so, $obj);
my $old = '';
if ((-f $ini) && open(my $in, '<', $ini)) {
    local $/;
    $old = <$in>;
    close($in);
}
if ($old eq $body) {
    $cce->bye('SUCCESS');
    exit(0);
}
if (open(my $fh, '>', $ini)) {
    print $fh $body;
    close($fh);
    chmod 0644, $ini;
}
else {
    $cce->bye('FAIL', '[[base-backupcontrol.admserv_opcache_ini_write_fail]]');
    exit(1);
}

Sauce::Service::service_run_init('admserv-php-fpm', 'restart');

$cce->bye('SUCCESS');
exit(0);

sub find_opcache_so {
    my $root = shift;
    my $found = '';
    return $found unless (-d $root);
    foreach my $cand (glob("$root/lib/php/*/opcache.so"), glob("$root/lib64/php/*/opcache.so")) {
        if (-f $cand) {
            return $cand;
        }
    }
    File::Find::find({
        wanted => sub {
            return if ($found ne '');
            return unless (-f $_);
            return unless (($_ eq 'opcache.so') || ($File::Find::name =~ /\/opcache\.so$/));
            $found = $File::Find::name;
        },
        no_chdir => 0,
    }, $root);
    return $found;
}

sub bflag {
    my $v = shift;
    return ($v eq '0') ? 0 : 1;
}

sub irange {
    my ($v, $min, $max, $def) = @_;
    if (!defined($v) || ($v !~ /^-?\d+$/)) {
        $v = $def;
    }
    $v = int($v);
    $v = $min if ($v < $min);
    $v = $max if ($v > $max);
    return $v;
}

sub is_explicit_off {
    my $v = shift;
    return 0 if (!defined($v));
    return 1 if ($v eq '0' || $v eq 'false' || $v eq 'Off');
    return 0;
}

sub is_explicit_on {
    my $v = shift;
    return 0 if (!defined($v));
    return 1 if ($v eq '1' || $v eq 'true' || $v eq 'On' || $v eq 'on');
    return 0;
}

sub ini_body {
    my ($so, $obj) = @_;
    my $enable = &is_explicit_off($obj->{'admserv_opcache'}) ? 0 : 1;
    my $cli = &is_explicit_on($obj->{'opcache_enable_cli'}) ? 1 : 0;
    my $mem = &irange($obj->{'opcache_memory_consumption'}, 64, 2048, 256);
    my $interned = &irange($obj->{'opcache_interned_strings_buffer'}, 4, 128, 16);
    my $maxfiles = &irange($obj->{'opcache_max_accelerated_files'}, 2000, 1000000, 30000);
    my $validate = &is_explicit_off($obj->{'opcache_validate_timestamps'}) ? 0 : 1;
    my $refreq = &irange($obj->{'opcache_revalidate_freq'}, 0, 86400, 60);
    my $comments = &is_explicit_off($obj->{'opcache_save_comments'}) ? 0 : 1;
    my $waste = &irange($obj->{'opcache_max_wasted_percentage'}, 1, 50, 5);
    my $fast = &is_explicit_off($obj->{'opcache_fast_shutdown'}) ? 0 : 1;

    my $txt = "; Enable Opcache (managed by BlueOnyx GUI /backupcontrol/desktopcontrol)\n";
    $txt .= "zend_extension=$so\n";
    $txt .= "\n";
    $txt .= "opcache.enable=$enable\n";
    $txt .= "opcache.enable_cli=$cli\n";
    $txt .= "\n";
    $txt .= "; Memory sizing (AdmServ is usually fine with this)\n";
    $txt .= "opcache.memory_consumption=$mem\n";
    $txt .= "opcache.interned_strings_buffer=$interned\n";
    $txt .= "opcache.max_accelerated_files=$maxfiles\n";
    $txt .= "\n";
    $txt .= "; Safe update behavior for packaged GUI code\n";
    $txt .= "opcache.validate_timestamps=$validate\n";
    $txt .= "opcache.revalidate_freq=$refreq\n";
    $txt .= "\n";
    $txt .= "; Good defaults\n";
    $txt .= "opcache.save_comments=$comments\n";
    $txt .= "opcache.max_wasted_percentage=$waste\n";
    $txt .= "opcache.fast_shutdown=$fast\n";
    return $txt;
}

# 
# Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
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