#!/usr/bin/perl -I/usr/sausalito/perl
# $Id: php_ioncube.pl
#
# Enable or disable the ionCube Loader per installed PHP version.
# A version is only touched when the matching NTS loader .so exists.
# The php.d ioncube.ini always exists: either a zend_extension line, or a
# comment that the GUI disabled the loader. Missing INIs are recreated.

$DEBUG = "0";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

$extra_PHP_basepath = '/home/solarspeed/';
$ioncube_dir = '/home/solarspeed/ioncube/';

use CCE;
use Sauce::Service;

my $cce = new CCE;
$cce->connectfd();

my %known_php_versions = (
    'PHP53' => '5.3',
    'PHP54' => '5.4',
    'PHP55' => '5.5',
    'PHP56' => '5.6',
    'PHP70' => '7.0',
    'PHP71' => '7.1',
    'PHP72' => '7.2',
    'PHP73' => '7.3',
    'PHP74' => '7.4',
    'PHP80' => '8.0',
    'PHP81' => '8.1',
    'PHP82' => '8.2',
    'PHP83' => '8.3',
    'PHP84' => '8.4',
    'PHP85' => '8.5',
    'PHP86' => '8.6',
    'PHP90' => '9.0',
    'PHP91' => '9.1',
    'PHP92' => '9.2',
    'PHP93' => '9.3',
    'PHP94' => '9.4',
);

my @sysoids = $cce->find('PHP');
if (!defined($sysoids[0])) {
    $cce->bye('SUCCESS');
    exit(0);
}
my $PHP_OID = $sysoids[0];
my ($ok, $PHP) = $cce->get($PHP_OID);

&apply_os_php($PHP);
for my $phpVer (sort keys %known_php_versions) {
    my ($okns, $ns) = $cce->get($PHP_OID, $phpVer);
    next unless $okns;
    next unless ($ns->{'present'} eq '1');
    next unless ($ns->{'ioncube_present'} eq '1');
    my $majmin = $known_php_versions{$phpVer};
    my $so = $ioncube_dir . 'ioncube_loader_lin_' . $majmin . '.so';
    my $ini_dir = $extra_PHP_basepath . 'php-' . $majmin . '/etc/php.d';
    my $want = ($ns->{'ioncube'} eq '1') ? 1 : 0;
    my $changed = &write_ini($ini_dir, $so, $want, 'ioncube.ini');
    if ($changed) {
        my $fpm = 'php-fpm-' . $majmin;
        if (-f $extra_PHP_basepath . 'php-' . $majmin . '/sbin/php-fpm') {
            Sauce::Service::service_run_init($fpm, 'restart');
        }
    }
}

$cce->bye('SUCCESS');
exit(0);

sub apply_os_php {
    my $PHP = shift;
    return unless ($PHP->{'ioncube_present'} eq '1');
    my $os = $PHP->{'PHP_version_os'} || '';
    my $majmin = '';
    if ($os =~ /^(\d+\.\d+)/) {
        $majmin = $1;
    }
    return unless ($majmin ne '');
    my $so = $ioncube_dir . 'ioncube_loader_lin_' . $majmin . '.so';
    my $want = ($PHP->{'ioncube'} eq '1') ? 1 : 0;
    my $changed = &write_ini('/etc/php.d', $so, $want, '00-ioncube.ini');
    if ($changed && (-f '/usr/sbin/php-fpm')) {
        Sauce::Service::service_run_init('php-fpm', 'restart');
    }
}

sub write_ini {
    my ($dir, $so, $want, $preferred) = @_;
    return 0 unless ((defined($dir)) && (-d $dir));
    return 0 unless ((defined($so)) && (-f $so));

    my $path = &canonical_ini_path($dir, $preferred);
    my $body;
    if ($want) {
        $body = "zend_extension = $so\n";
    }
    else {
        $body = "; ionCube Loader disabled via BlueOnyx GUI (/vsite/phpconfig)\n";
        $body .= "; zend_extension = $so\n";
    }

    my $old = '';
    if (-f $path) {
        if (open(my $in, '<', $path)) {
            local $/;
            $old = <$in>;
            close($in);
        }
    }
    return 0 if ($old eq $body);

    if (open(my $fh, '>', $path)) {
        print $fh $body;
        close($fh);
        chmod 0644, $path;
    }
    else {
        return 0;
    }

    # Drop leftover rename-style disable files from the previous approach.
    opendir(my $dh, $dir) || return 1;
    while (my $f = readdir($dh)) {
        next unless ($f =~ /ioncube.*\.ini\.disabled$/);
        unlink("$dir/$f");
    }
    closedir($dh);
    return 1;
}

sub canonical_ini_path {
    my ($dir, $preferred) = @_;
    # Prefer an existing ioncube*.ini (not .disabled) so we do not create a second file.
    if (opendir(my $dh, $dir)) {
        my @ini = grep { $_ =~ /ioncube.*\.ini$/ && $_ !~ /\.disabled$/ } readdir($dh);
        closedir($dh);
        if (scalar(@ini) > 0) {
            return "$dir/" . $ini[0];
        }
    }
    return "$dir/$preferred";
}

sub debug_msg {
    if ($DEBUG) {
        my $msg = shift;
        $msg =~ s/\n/ /g;
        setlogsock('unix');
        openlog('php_ioncube.pl', '', 'user');
        syslog('info', "$msg");
        closelog();
    }
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
