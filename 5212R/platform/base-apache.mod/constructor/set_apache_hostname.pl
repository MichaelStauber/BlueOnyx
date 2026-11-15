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
# Vsite Include lines are rehydrated from CODB (VirtualHost ∩ Vsite) plus FS
# gates (vhost file, basedir, documentRoot; SSL cert/key if SSL enabled).
# Orphan on-disk siteN configs without CODB must NEVER gain an Include.

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

# Rehydrate Vsite + preview Include lines from CODB-eligible sites only.
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

# CODB allowlist: VirtualHost.name must have a matching Vsite.name.
# FS gates: vhost conf file, Vsite.basedir, VirtualHost.documentRoot;
# if SSL enabled on the Vsite, certificate + key must exist;
# any SSLCACertificateFile path in the vhost conf must exist non-empty
# (even if SSL is off - stale directives still break httpd -t).
sub _warn_rehydrate
{
  my ($msg) = @_;
  print STDERR "set_apache_hostname.pl: $msg\n";
  eval {
    open(my $log, '|-', '/usr/bin/logger', '-t', 'set_apache_hostname', '-p', 'daemon.warning');
    print $log $msg if $log;
    close($log) if $log;
  };
}

# Default vhosts dir even when Base::Httpd is odd / dir not yet created.
sub _vhost_dir
{
  my $dir = $Base::Httpd::vhost_dir;
  $dir = '/etc/httpd/conf/vhosts' if (!defined $dir || $dir eq '');
  return $dir;
}

sub _vhost_conf_ssl_paths_ok
{
  my ($name, $path) = @_;
  open(my $vcf, '<', $path) or return 1;
  while (<$vcf>) {
    next unless (/^\s*SSLCACertificateFile\s+(\S+)/);
    my $ca = $1;
    $ca =~ s/^"//;
    $ca =~ s/"$//;
    unless (-f $ca && -s $ca) {
      close($vcf);
      _warn_rehydrate("skip Include for $name: SSLCACertificateFile missing or empty ($ca)");
      return 0;
    }
  }
  close($vcf);
  return 1;
}

sub _codb_eligible_site_names
{
  my $dir = _vhost_dir();
  my %vsite_by_name;
  my %seen;
  my @eligible;

  for my $oid ($cce->find('Vsite')) {
    my ($ok, $vs) = $cce->get($oid);
    next unless ($ok && $vs && defined $vs->{name} && $vs->{name} =~ /^site\d+$/);
    $vsite_by_name{ $vs->{name} } = { oid => $oid, obj => $vs };
  }

  for my $oid ($cce->find('VirtualHost')) {
    my ($ok, $vh) = $cce->get($oid);
    next unless ($ok && $vh && defined $vh->{name} && $vh->{name} =~ /^site\d+$/);
    my $name = $vh->{name};
    next if $seen{$name}++;

    my $vs_info = $vsite_by_name{$name};
    unless ($vs_info) {
      _warn_rehydrate("skip Include for $name: VirtualHost without matching Vsite in CODB");
      next;
    }

    my $vs = $vs_info->{obj};
    my $basedir = $vs->{basedir} || '';
    my $docroot = $vh->{documentRoot} || '';

    unless (-d $dir && -f "$dir/$name") {
      _warn_rehydrate("skip Include for $name: missing vhost conf $dir/$name");
      next;
    }
    unless ($basedir ne '' && -d $basedir) {
      _warn_rehydrate("skip Include for $name: missing Vsite.basedir ($basedir)");
      next;
    }

    # DocumentRoot gate: CODB VirtualHost.documentRoot is often stale
    # (…/siteN/web) while live trees use …/wwwroot/web. Accept any real
    # BX web root, and prefer DocumentRoot from the generated vhost conf.
    my $doc_ok = 0;
    my $doc_checked = '';
    if (open(my $vcf, '<', "$dir/$name")) {
      while (<$vcf>) {
        if (/^\s*DocumentRoot\s+(\S+)/) {
          my $p = $1;
          $p =~ s/^"//;
          $p =~ s/"$//;
          $doc_checked = $p;
          if ($p ne '' && -d $p) {
            $doc_ok = 1;
          }
          last;
        }
      }
      close($vcf);
    }
    unless ($doc_ok) {
      for my $cand ($docroot, "$basedir/wwwroot/web", "$basedir/web") {
        next if (!defined $cand || $cand eq '');
        $doc_checked = $cand if ($doc_checked eq '');
        if (-d $cand) {
          $doc_ok = 1;
          $doc_checked = $cand;
          last;
        }
      }
    }
    unless ($doc_ok) {
      _warn_rehydrate("skip Include for $name: missing documentRoot (tried conf/CODB/wwwroot: $doc_checked)");
      next;
    }

    my ($sok, $ssl) = $cce->get($vs_info->{oid}, 'SSL');
    my $ssl_on = ($sok && $ssl && defined $ssl->{enabled} && $ssl->{enabled} eq '1') ? 1 : 0;

    # Only enforce cert/key when the generated vhost conf actually has SSL
    # (CODB SSL=1 alone is common while conf is HTTP-only / certs not yet issued).
    my $conf_has_ssl = 0;
    if (open(my $vcf2, '<', "$dir/$name")) {
      while (<$vcf2>) {
        if (/^\s*SSL(Engine|CertificateFile|CertificateKeyFile)\b/i || /^\s*SSLengine\b/i) {
          $conf_has_ssl = 1;
          last;
        }
      }
      close($vcf2);
    }
    if ($ssl_on && $conf_has_ssl) {
      my $cert = "$basedir/wwwroot/certs/certificate";
      my $key  = "$basedir/wwwroot/certs/key";
      unless (-f $cert && -s $cert && -f $key && -s $key) {
        _warn_rehydrate("skip Include for $name: SSL conf present but cert/key missing under $basedir/wwwroot/certs");
        next;
      }
    }

    # Stale SSLCACertificateFile in the conf file breaks httpd regardless of SSL flag.
    next unless _vhost_conf_ssl_paths_ok($name, "$dir/$name");

    push @eligible, $name;
  }

  return sort { ($a =~ /(\d+)/)[0] <=> ($b =~ /(\d+)/)[0] || $a cmp $b } @eligible;
}

sub _rewrite_httpd_lines
{
  my ($lines_ref) = @_;
  open(my $out, '>', "$httpd_conf~") or return 0;
  print $out @$lines_ref or return 0;
  close($out) or return 0;
  chmod(0644, "$httpd_conf~");
  move("$httpd_conf~", $httpd_conf) or return 0;
  chmod(0644, $httpd_conf);
  return 1;
}

sub _strip_and_read_httpd_site_includes
{
  # Returns (ok, lines_ref) after removing siteN + preview Includes.
  my ($dir) = @_;
  open(my $in, '<', $httpd_conf) or return (0, undef);
  my @lines = <$in>;
  close($in);
  @lines = grep {
    $_ !~ m{^\s*#?\s*Include\s+\Q$dir\E/site\d+\s*$}
  } @lines;
  @lines = grep {
    $_ !~ m{^\s*#?\s*Include\s+\Q$dir\E/preview\s*$}
  } @lines;
  return (1, \@lines);
}

sub _vhost_includes_ok
{
  my $dir = _vhost_dir();

  open(my $fh, '<', $httpd_conf) or return 0;
  my @inc;
  while (<$fh>) {
    push @inc, $1 if (/^Include\s+(\S+)\s*$/);
  }
  close($fh);

  # Even if vhosts dir is missing, no active siteN Include may remain.
  for my $path (@inc) {
    if ($path =~ m{^\Q$dir\E/site\d+$}) {
      return 0 unless (-d $dir);
    }
  }

  my @sites = (-d $dir) ? _codb_eligible_site_names() : ();
  my %allow = map {; "$dir/$_" => 1 } @sites;

  for my $path (@inc) {
    next unless ($path =~ m{^\Q$dir\E/site\d+$});
    return 0 unless $allow{$path};
  }

  for my $s (@sites) {
    my $want = "$dir/$s";
    my @hits = grep { $_ eq $want } @inc;
    return 0 unless (@hits == 1);
  }

  if (-d $dir && -f "$dir/preview") {
    my $want = "$dir/preview";
    my @preview = grep { $_ eq $want } @inc;
    return 0 unless (@preview == 1 && $inc[-1] eq $want);
  } else {
    # No preview file: must not still Include preview.
    my @preview = grep { $_ eq "$dir/preview" } @inc;
    return 0 if @preview;
  }
  return 1;
}

sub _rehydrate_vhost_includes
{
  my $dir = _vhost_dir();

  # Always strip siteN/preview Includes from httpd.conf first (orphan cleanup),
  # even when the vhosts directory is missing - stale lines would still break httpd.
  my ($ok, $lines_ref) = _strip_and_read_httpd_site_includes($dir);
  return 0 unless $ok;
  my @lines = @$lines_ref;

  my @sites = ();
  if (-d $dir) {
    @sites = _codb_eligible_site_names();
    for my $s (@sites) {
      push @lines, "Include $dir/$s\n";
    }
    if (-f "$dir/preview") {
      push @lines, "Include $dir/preview\n";
    }
  } else {
    _warn_rehydrate("vhosts dir missing ($dir): stripped site Includes, none re-added");
  }

  return 0 unless _rewrite_httpd_lines(\@lines);
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
