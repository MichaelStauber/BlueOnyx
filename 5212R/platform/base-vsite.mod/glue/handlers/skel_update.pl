#!/usr/bin/perl -w -I/usr/sausalito/perl -I/usr/sausalito/handlers/base/vsite
# $Id: skel_update.pl
#
# This handler checks the skelleton files of Vsites and updates them
# to the new design introduced with 5212R.

use CCE;
use I18n;
use Vsite;
use File::Path;
use Sauce::Util;
use Sauce::Config;
use Base::HomeDir qw(homedir_get_group_dir homedir_create_group_link);
use Base::Group qw(groupadd group_add_members);

# debugging flag, set to 1 to turn on logging to STDERR
my $DEBUG = 0;
if ($DEBUG) { 
    use Data::Dumper; 
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

# set umask, otherwise directories get created with the wrong permissions
umask(002);

my $cce = new CCE('Domain' => 'base-vsite');
$cce->connectfd();

&debug_msg("skel_update.pl starting up.\n");

my ($ok, $vsite);
my ($sysoid) = $cce->find('System');

my $vsite = $cce->event_object();
my $group_name = $vsite->{'name'};

my $prefered_siteAdmin = 'nobody';
($ok, $PHP) = $cce->get($vsite->{'OID'}, 'PHP');
if ($ok) {
    $prefered_siteAdmin = $PHP->{'prefered_siteAdmin'};
}

&debug_msg("group_name: $group_name\n");
&debug_msg("prefered_siteAdmin: $prefered_siteAdmin\n");

my $site_dir = homedir_get_group_dir($group_name, $vsite->{volume});
my $site_web = $site_dir . '/wwwroot/' . Sauce::Config::webdir();
my $webindex = "$site_web/index.html";
my $errorsdir = $site_web . '/error';
my @html_files = ();

if (-f $webindex) {
    &debug_msg("webindex: $webindex - exists!\n");
    push @html_files, $webindex;
}

if (-d $errorsdir) {
    &debug_msg("errorsdir: $errorsdir - exists!\n");
    $page_401 = $errorsdir . '/401-authorization.html';
    $page_403 = $errorsdir . '/403-forbidden.html';
    $page_404 = $errorsdir . '/404-file-not-found.html';
    $page_500 = $errorsdir . '/500-internal-server-error.html';
    $page_502 = $errorsdir . '/502-bad-gateway.html';
    push @html_files, $page_401, $page_403, $page_404, $page_500, $page_502;
}

my $skel_base = '/etc/skel/vsite/en/web';

foreach my $file (@html_files) {
    next unless -f $file;

    my $has_libimage = file_has_libimage($file);
    my ($needs_jquery_refresh, $jquery_reason) = file_needs_jquery_refresh($file);

    if ($has_libimage) {
        &debug_msg("File '$file' contains 'libImage', replacing it.");
        my $rel_path = $file;
        $rel_path =~ s/^\Q$site_web\E\///;  # strip $site_web from full path
        my $skel_path = "$skel_base/$rel_path";

        replace_and_fix_file($skel_path, $file, $vsite, $prefered_siteAdmin, $group_name);
    }
    elsif ($needs_jquery_refresh) {
        &debug_msg("File '$file' requires jQuery template refresh ($jquery_reason), replacing it.");

        my $rel_path = $file;
        $rel_path =~ s/^\Q$site_web\E\///;  # strip $site_web from full path
        my $skel_path = "$skel_base/$rel_path";

        replace_and_fix_file($skel_path, $file, $vsite, $prefered_siteAdmin, $group_name);
    }
    else {
        &debug_msg("File '$file' does not contain 'libImage' and does not need jQuery refresh. Skipping.");
    }
}

$cce->bye('SUCCESS');
exit(0);

#
### Subs:
#

sub debug_msg {
    if ($DEBUG) {
        my $msg = shift;
        $DEBUG && print STDERR "$ARGV[0]: ", $msg, "\n";

        setlogsock('unix');
        openlog($0,'','user');
        syslog('info', "$msg");
        closelog;
    }
}

# Replace and fix the files:
sub replace_and_fix_file {
    my ($src, $dst, $vsite, $owner, $group) = @_;

    if (-f $src) {
        if (system("cp $src $dst") == 0) {
            Sauce::Util::editfile($dst, *edit_webindex, $vsite);
            Sauce::Util::chownfile((getpwnam($owner))[2], (getgrnam($group))[2], $dst);
            Sauce::Util::chmodfile(0664, $dst);
            &debug_msg("Replaced and updated: $dst");
            return 1;
        }
        else {
            &debug_msg("Failed to copy $src to $dst");
        }
    }
    else {
        &debug_msg("Source file $src does not exist!");
    }
    return 0;
}

# Checks if the file contains the string "libImage"
sub file_has_libimage {
    my $file = shift;
    open my $fh, '<', $file or return 0;
    while (<$fh>) {
        if (/libImage/) {
            close $fh;
            return 1;
        }
    }
    close $fh;
    return 0;
}

# Checks if the file still has the pre-jQuery-refresh template markers.
# Returns: (1, "reason text") or (0, "")
sub file_needs_jquery_refresh {
    my $file = shift;

    open my $fh, '<', $file or return (0, "");
    local $/ = undef;
    my $content = <$fh>;
    close $fh;

    return (0, "") if !defined($content);

    # Only care about pages that actually use the Elmer jQuery stack.
    if ($content !~ /\/\.elm\/vendors\/bower_components\/jquery\/dist\/jquery\.min\.js/i) {
        return (0, "");
    }

    my @reasons = ();
    if ($content !~ /jquery-migrate\.js/i) {
        push @reasons, "jquery-migrate include missing";
    }
    if ($content !~ /jquery-ui\.min\.js/i) {
        push @reasons, "jquery-ui.min.js include missing";
    }
    if ($content !~ /migrateMute\s*=\s*true/i) {
        push @reasons, "migrateMute pre-setting missing";
    }
    if ($content !~ /migrateDisablePatches\s*\(\s*["']event-old-patch["']\s*,\s*["']unique["']\s*\)/i) {
        push @reasons, "migrateDisablePatches(event-old-patch, unique) missing";
    }

    if (scalar(@reasons) > 0) {
        return (1, join("; ", @reasons));
    }

    return (0, "");
}

sub edit_webindex {
    my ($in, $out, $vsite) = @_;
    while (<$in>) {
        s/\[DOMAIN\]/$vsite->{fqdn}/g;
        print $out $_;
    }
    return 1;
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
