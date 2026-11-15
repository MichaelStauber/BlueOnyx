#!/usr/bin/perl -w -I/usr/sausalito/perl -I.
# $Id: set_apache_hostname.pl
#
# This constructor sets the ServerAdmin and ServerName in the Apache config of
# both the public Apache and AdmServ. It does NOT restart both services by intent,
# so the changes will only take effect during the next restart of these services.
#
# Depends on:
#   System.hostname
#   System.domainname
#
# IMPORTANT (all product trees / EL8+ httpd layout):
# Never rebuild httpd.conf from replaceblock fragments alone. If the main conf
# is missing required includes (conf.modules.d / conf.d), restore a stock
# skeleton from the package that owns httpd.conf (httpd-core on EL9/EL10,
# httpd on EL8) first, then re-apply BX deltas. Otherwise firstboot can leave
# a stump conf and httpd dies with "No MPM loaded".
#
# Required public httpd.conf invariants after this constructor:
#   - Include conf.modules.d/*.conf
#   - IncludeOptional conf.d/*.conf  (or Include conf.d/*.conf)
#   - PerlConfigRequire /etc/httpd/conf.perl/00-default-vsite.pl
# Vsite Include lines are rehydrated from /etc/httpd/conf/vhosts/site* + preview.

my $confdir = '/etc/httpd/conf';
my $aconfdir = '/etc/admserv/conf';
my $httpd_conf = "$confdir/httpd.conf";
my $PERL_CFG_REQUIRE = 'PerlConfigRequire /etc/httpd/conf.perl/00-default-vsite.pl';

use Sauce::Config;
use Sauce::Util;
use FileHandle;
use File::Copy;
use CCE;
use Base::Httpd qw(httpd_add_include httpd_remove_include);
use Base::Httpd;

my $cce = new CCE;
$cce->connectuds();

my ($oid) = $cce->find("System");
my ($ok, $obj) = $cce->get($oid);
my ($status, $web) = $cce->get($oid, "Web");

my $hostname = $obj->{hostname};
my $domain = $obj->{domainname};
$hostname =~ s/\.${domain}$//;
my $fqdn = $hostname . '.' . $domain;
my $httpPort = $web->{'httpPort'};

if ($httpPort eq "") {
  $httpPort = '80';
}

#
# If public httpd.conf is missing critical includes, restore stock from the
# installed httpd-core RPM before applying any BX edits.
#
if (!_httpd_conf_ok($httpd_conf)) {
  if (!_restore_httpd_conf_stock($httpd_conf)) {
    $cce->bye('FAIL', "httpd.conf missing required includes and stock restore failed");
    exit(1);
  }
}

# Line-oriented safe edits for public httpd (no whole-file replaceblock).
umask(0077);
my $stage = "$confdir/httpd.conf~";
unless (open(HTTPD, $httpd_conf)) {
  $cce->bye('FAIL', "Cannot read $httpd_conf: $!");
  exit(1);
}
unlink($stage);
unless (sysopen(STAGE, $stage, 1|O_CREAT|O_EXCL, 0600)) {
  close(HTTPD);
  $cce->bye('FAIL', "Cannot create $stage: $!");
  exit(1);
}
while (<HTTPD>) {
  s|^ServerTokens OS|ServerTokens ProductOnly|g;
  s/^ServerAdmin\s.+$/ServerAdmin admin\@$fqdn/;
  s/^ServerName\s.+$/ServerName $fqdn/;
  s/^#ServerName\s.+$/ServerName $fqdn/;
  s/^Listen\s.+$/Listen $httpPort/;
  # Stock conf uses relative logs/ under ServerRoot; BX wants absolute path.
  # Comment stock ErrorLog/CustomLog, then ensure absolute ErrorLog below.
  s|^ErrorLog|#ErrorLog|g;
  s|^CustomLog|#CustomLog|g;

  print STAGE;
}
close(STAGE);
close(HTTPD);

chmod(0644, $stage);
my $apache_ok = "0";
if (-s $stage) {
  move($stage, $httpd_conf);
  chmod(0644, $httpd_conf);
  $apache_ok = "1";
} else {
  unlink($stage) if (-e $stage);
}

# Absolute ErrorLog (replaces old replaceblock log_config fragment intent).
unless (_ensure_error_log($httpd_conf, '/var/log/httpd/error_log')) {
  $cce->bye('FAIL', "Error while ensuring ErrorLog in $httpd_conf");
  exit(1);
}

# Handle admserv:
umask(0077);
my $astage = "$aconfdir/httpd.conf~";
my $admserv_ok = "0";
if (open(HTTPD, "$aconfdir/httpd.conf")) {
  unlink($astage);
  if (sysopen(STAGE, $astage, 1|O_CREAT|O_EXCL, 0600)) {
    while (<HTTPD>) {
      s|^ServerTokens OS|ServerTokens ProductOnly|g;
      s/^ServerAdmin\s.+$/ServerAdmin admin\@$fqdn/;
      s/^ServerName\s.+$/ServerName $fqdn/;
      s/^#ServerName\s.+$/ServerName $fqdn/;
      s|^AddDefaultCharset\s.+$|AddDefaultCharset UTF-8|g;

      print STAGE;
    }
    close(STAGE);
    close(HTTPD);

    chmod(0644, $astage);
    if (-s $astage) {
      move($astage, "$aconfdir/httpd.conf");
      chmod(0644, "$aconfdir/httpd.conf");
      $admserv_ok = "1";
    } else {
      unlink($astage) if (-e $astage);
    }
  } else {
    close(HTTPD);
  }
}

# Ensure PerlConfigRequire sits immediately before conf.d include (idempotent).
unless (_ensure_perl_config_require($httpd_conf, $PERL_CFG_REQUIRE)) {
  $cce->bye('FAIL', "Error while ensuring PerlConfigRequire in $httpd_conf");
  exit(1);
}

# Rehydrate Vsite + preview Include lines from on-disk vhost configs.
unless (_rehydrate_vhost_includes()) {
  $cce->bye('FAIL', "Error while rehydrating vhost Include lines in $httpd_conf");
  exit(1);
}

# Final invariant check - never leave a stump conf behind.
unless (_httpd_conf_ok($httpd_conf)
    && _has_line($httpd_conf, qr/^\Q$PERL_CFG_REQUIRE\E\s*$/)
    && _has_active_error_log($httpd_conf)
    && _vhost_includes_ok()) {
  $cce->bye('FAIL', "$httpd_conf failed post-edit invariant check");
  exit(1);
}

# Fix GID and permissions on /etc/httpd/alias/ for new mod_nss:
if ( -d "/etc/httpd/alias" ) {
  system('find /etc/httpd/alias -user root -name "*.db" -exec /bin/chgrp apache {} \;');
  system('find /etc/httpd/alias -user root -name "*.db" -exec /bin/chmod g+r {} \;');
}

if (($apache_ok eq "1") && ($admserv_ok eq "1")) {
  $cce->bye("SUCCESS");
  exit(0);
}

# Public conf is the critical path; admserv may be absent on odd images.
if ($apache_ok eq "1") {
  $cce->bye("SUCCESS");
  exit(0);
}

$cce->bye("FAILURE");
exit(1);

# -----------------------------------------------------------------------------
sub _has_line
{
  my ($file, $re) = @_;
  open(my $fh, '<', $file) or return 0;
  while (<$fh>) {
    return 1 if ($_ =~ $re);
  }
  close($fh);
  return 0;
}

sub _httpd_conf_ok
{
  my ($file) = @_;
  return 0 unless (-f $file && -s $file);
  return 0 unless _has_line($file, qr|^Include\s+conf\.modules\.d/\*\.conf\s*$|);
  return 0 unless (
    _has_line($file, qr|^IncludeOptional\s+conf\.d/\*\.conf\s*$|)
    || _has_line($file, qr|^Include\s+conf\.d/\*\.conf\s*$|)
  );
  return 1;
}

sub _httpd_conf_provider_nvr
{
  # Prefer the package that owns the live conf file.
  my $owner = `rpm -qf --queryformat '%{NAME}-%{VERSION}-%{RELEASE}.%{ARCH}' /etc/httpd/conf/httpd.conf 2>/dev/null`;
  chomp($owner);
  return $owner if ($owner ne '' && $owner !~ /not /);

  # EL9/EL10: httpd-core. EL8: httpd (no httpd-core).
  for my $pkg (qw(httpd-core httpd)) {
    my $nvr = `rpm -q --queryformat '%{NAME}-%{VERSION}-%{RELEASE}.%{ARCH}' $pkg 2>/dev/null`;
    chomp($nvr);
    next if ($nvr eq '' || $nvr =~ /not installed/);
    return $nvr;
  }
  return '';
}

sub _restore_httpd_conf_stock
{
  my ($dest) = @_;

  # Local caches first (ISO/lab often leave these after a prior recovery).
  # Accept cache only if sidecar NVR matches installed httpd provider.
  my $inst_nvr = _httpd_conf_provider_nvr();
  my $cache = '/etc/httpd/conf/httpd.conf.stock-from-rpm';
  my $cache_nvr_file = '/etc/httpd/conf/httpd.conf.stock-from-rpm.nvr';
  if ($inst_nvr ne ''
      && -f $cache && -s $cache && _httpd_conf_ok($cache)
      && -f $cache_nvr_file) {
    open(my $nf, '<', $cache_nvr_file);
    my $cn = <$nf>;
    close($nf);
    chomp($cn) if defined $cn;
    if (defined $cn && $cn eq $inst_nvr) {
      copy($cache, $dest) or return 0;
      chmod(0644, $dest);
      return 1;
    }
  }
  if (-f '/usr/share/blueonyx/httpd/httpd.conf.stock'
      && -s '/usr/share/blueonyx/httpd/httpd.conf.stock'
      && _httpd_conf_ok('/usr/share/blueonyx/httpd/httpd.conf.stock')) {
    copy('/usr/share/blueonyx/httpd/httpd.conf.stock', $dest) or return 0;
    chmod(0644, $dest);
    return 1;
  }

  # Extract stock httpd.conf from the installed httpd-core (EL9+) or httpd (EL8).
  my $nvr = $inst_nvr;
  return 0 if ($nvr eq '');

  my $pkgname = 'httpd-core';
  $pkgname = 'httpd' if ($nvr =~ /^httpd-/ && $nvr !~ /^httpd-core-/);

  my $tmpdir = `mktemp -d /tmp/httpd-conf-restore.XXXXXX 2>/dev/null`;
  chomp($tmpdir);
  return 0 unless ($tmpdir && -d $tmpdir);

  my $pkg = '';
  if (open(my $find, '-|', 'bash', '-lc',
      "find /var/cache /tmp -name '$nvr.rpm' 2>/dev/null | head -1")) {
    $pkg = <$find>;
    close($find);
    chomp($pkg) if defined $pkg;
  }
  if (!$pkg || ! -f $pkg) {
    system('bash', '-lc', "dnf download -q --destdir='$tmpdir' $pkgname >/dev/null 2>&1");
    if (open(my $ls, '-|', 'bash', '-lc', "ls -1 $tmpdir/${pkgname}*.rpm 2>/dev/null | head -1")) {
      $pkg = <$ls>;
      close($ls);
      chomp($pkg) if defined $pkg;
    }
  }

  # Refuse a downloaded RPM that is not the installed NVR (avoid wrong-generation conf).
  if ($pkg && -f $pkg) {
    my $base = $pkg;
    $base =~ s{.*/}{};
    $base =~ s/\.rpm$//;
    if ($base ne $nvr) {
      $pkg = '';
    }
  }

  my $ok = 0;
  if ($pkg && -f $pkg) {
    system('bash', '-lc',
      "cd '$tmpdir' && rpm2cpio '$pkg' | cpio -idmu ./etc/httpd/conf/httpd.conf >/dev/null 2>&1");
    my $stock = "$tmpdir/etc/httpd/conf/httpd.conf";
    if (-f $stock && -s $stock && _httpd_conf_ok($stock)) {
      # Keep a local copy for offline/firstboot re-runs without network.
      copy($stock, '/etc/httpd/conf/httpd.conf.stock-from-rpm');
      chmod(0644, '/etc/httpd/conf/httpd.conf.stock-from-rpm');
      if (open(my $nf, '>', '/etc/httpd/conf/httpd.conf.stock-from-rpm.nvr')) {
        print $nf "$nvr\n";
        close($nf);
        chmod(0644, '/etc/httpd/conf/httpd.conf.stock-from-rpm.nvr');
      }
      copy($stock, $dest);
      chmod(0644, $dest);
      $ok = _httpd_conf_ok($dest) ? 1 : 0;
    }
  }

  system('rm', '-rf', $tmpdir);
  return $ok;
}

sub _ensure_perl_config_require
{
  my ($file, $line) = @_;

  open(my $in, '<', $file) or return 0;
  my @lines = <$in>;
  close($in);

  # Already present exactly once is fine; strip duplicates and re-place.
  @lines = grep { $_ !~ /^\Q$line\E\s*$/ } @lines;

  my $out = '';
  my $inserted = 0;
  for my $l (@lines) {
    if (!$inserted && $l =~ /^(IncludeOptional|Include)\s+conf\.d\/\*\.conf\s*$/) {
      $out .= "$line\n";
      $inserted = 1;
    }
    $out .= $l;
  }
  if (!$inserted) {
    # Append before any trailing vhost Includes if present, else at EOF.
    if ($out =~ /^(Include\s+\/etc\/httpd\/conf\/vhosts\/)/m) {
      $out =~ s/^(Include\s+\/etc\/httpd\/conf\/vhosts\/)/$line\n$1/m;
      $inserted = 1;
    } else {
      $out .= "$line\n";
      $inserted = 1;
    }
  }

  open(my $fh, '>', "$file~") or return 0;
  print $fh $out;
  close($fh);
  chmod(0644, "$file~");
  if (-s "$file~") {
    move("$file~", $file);
    chmod(0644, $file);
    return 1;
  }
  unlink("$file~") if (-e "$file~");
  return 0;
}

sub _has_active_error_log
{
  my ($file) = @_;
  # Require the BX absolute path, not any leftover relative/conditional ErrorLog.
  return _has_line($file, qr|^ErrorLog\s+"/var/log/httpd/error_log"\s*$|);
}

sub _ensure_error_log
{
  my ($file, $path) = @_;
  return 1 if _has_active_error_log($file);

  my $line = "ErrorLog \"$path\"";
  open(my $in, '<', $file) or return 0;
  my @lines = <$in>;
  close($in);

  # Drop other active ErrorLog lines (stock relative logs/, odd leftovers).
  @lines = grep { $_ !~ /^ErrorLog\s+/ } @lines;

  # Insert global ErrorLog before conf.d include (works outside IfModule).
  my $out = '';
  my $inserted = 0;
  for my $l (@lines) {
    if (!$inserted && $l =~ /^(IncludeOptional|Include)\s+conf\.d\/\*\.conf\s*$/) {
      $out .= "$line\n";
      $inserted = 1;
    }
    $out .= $l;
  }
  if (!$inserted) {
    $out .= "$line\n";
  }

  open(my $fh, '>', "$file~") or return 0;
  print $fh $out or return 0;
  close($fh) or return 0;
  chmod(0644, "$file~");
  if (-s "$file~") {
    move("$file~", $file) or return 0;
    chmod(0644, $file);
    return _has_active_error_log($file) ? 1 : 0;
  }
  unlink("$file~") if (-e "$file~");
  return 0;
}

sub _vhost_includes_ok
{
  my $dir = $Base::Httpd::vhost_dir;
  return 1 unless ($dir && -d $dir);

  open(my $fh, '<', $httpd_conf) or return 0;
  my @inc;
  while (<$fh>) {
    push @inc, $1 if (/^Include\s+(\S+)\s*$/);
  }
  close($fh);

  opendir(my $dh, $dir) or return 0;
  my @sites = sort grep { /^site\d+$/ && -f "$dir/$_" } readdir($dh);
  closedir($dh);

  for my $s (@sites) {
    my $want = "$dir/$s";
    my @hits = grep { $_ eq $want } @inc;
    return 0 unless (@hits == 1);
  }

  if (-f "$dir/preview") {
    my $want = "$dir/preview";
    my @preview = grep { $_ eq $want } @inc;
    return 0 unless (@preview == 1 && $inc[-1] eq $want);
  }
  return 1;
}

sub _rehydrate_vhost_includes
{
  my $dir = $Base::Httpd::vhost_dir;
  return 1 unless ($dir && -d $dir);

  opendir(my $dh, $dir) or return 0;
  my @sites = sort grep { /^site\d+$/ && -f "$dir/$_" } readdir($dh);
  closedir($dh);

  for my $s (@sites) {
    my $want = "$dir/$s";
    # Ensure exactly one Include line for this site (httpd_add_include is
    # idempotent when present once; strip dupes then add).
    open(my $in, '<', $httpd_conf) or return 0;
    my @lines = <$in>;
    close($in);
    my $had = scalar grep { $_ =~ m|^Include\s+\Q$want\E\s*$| } @lines;
    if ($had != 1) {
      @lines = grep { $_ !~ m|^Include\s+\Q$want\E\s*$| } @lines;
      open(my $out, '>', "$httpd_conf~") or return 0;
      print $out @lines or return 0;
      close($out) or return 0;
      chmod(0644, "$httpd_conf~");
      move("$httpd_conf~", $httpd_conf) or return 0;
      chmod(0644, $httpd_conf);
      return 0 unless httpd_add_include($want);
    }
  }

  # preview must be exactly once and last Include line
  if (-f "$dir/preview") {
    my $want = "$dir/preview";
    # Strip any existing preview Include lines directly (idempotent, no dupes).
    open(my $in, '<', $httpd_conf) or return 0;
    my @lines = <$in>;
    close($in);
    @lines = grep { $_ !~ m|^Include\s+\Q$want\E\s*$| } @lines;
    open(my $out, '>', "$httpd_conf~") or return 0;
    print $out @lines or return 0;
    print $out "Include $want\n" or return 0;
    close($out) or return 0;
    chmod(0644, "$httpd_conf~");
    move("$httpd_conf~", $httpd_conf) or return 0;
    chmod(0644, $httpd_conf);
  }
  return 1;
}

#
# Copyright (c) 2008-2026 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2026 Team BlueOnyx, BLUEONYX.IT
# All Rights Reserved.
#
# 1. Redistributions of source code must retain the above copyright
#   notice, this list of conditions and the following disclaimer.
#
# 2. Redistributions in binary form must reproduce the above copyright
#   notice, this list of conditions and the following disclaimer in
#   the documentation and/or other materials provided with the
#   distribution.
#
# 3. Neither the name of the copyright holder nor the names of its
#   contributors may be used to endorse or promote products derived
#   from this software without specific prior written permission.
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
