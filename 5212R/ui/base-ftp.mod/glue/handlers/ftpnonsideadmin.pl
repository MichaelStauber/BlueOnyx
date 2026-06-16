#!/usr/bin/perl -I/usr/sausalito/handlers/base/ftp -I/usr/sausalito/perl
# $Id: ftpnonsiteadmin.pl
#
# This is triggered by changes to the Vsite FTP settings when FTP is enabled or 
# disabled for non siteAdmins. Then it creates or removes the .ftpaccess file 
# in their home directories. 
#
# Also: If a Vsite's 'Disk' NameSpace key 'vsite_over_quota' changes, it creates
# or removes .ftpaccess files that limit WRITE access through FTP on an as needed
# basis.
#

use CCE;
use Base::HomeDir qw(homedir_get_user_dir);

# Debugging switch:
$DEBUG = "0";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

my $cce = new CCE;
$cce->connectfd();

my $vsite = $cce->event_object();

($ok, my $vsite_disk) = $cce->get($cce->event_oid(), 'Disk');
if ($ok) {
    &manage_ftp_write;
}

($ok, my $userwebs) = $cce->get($cce->event_oid(), 'FTPNONADMIN');
if (not $ok) {
    &debug_msg("Can't read the 'FTPNONADMIN' item.");
    $cce->bye('FAIL', '[[base-ftp.cantReadFTPNONADMIN]]');
    exit(1);
}
else {
    &main;
}

$cce->bye('SUCCESS');
exit(0);

#
### Vsite is over-quota - manage FTP write-access for all users:
#

sub manage_ftp_write {

    # Check if FTPNONADMIN is enabled or disabled:
    $documentRoot = $vsite->{basedir};
    $siteNumber = $vsite->{name};
    @soids = $cce->find('Vsite', {'name' => $siteNumber});
    ($ok, $flag) = $cce->get($soids[0], 'FTPNONADMIN');
    $ftpnonadmin = $flag->{enabled};

    @allusers = ();
    @notsiteadmin = ();
    @siteadmin = ();

    &debug_msg("Working on Vsite $siteNumber to manage FTP access.");

    # Find all users who belong to the site in question:
    @alluseroids = $cce->find('User', {'site' => $siteNumber});

    # Walk through the OIDs and push the OIDs of all non-siteAdmin users to array @notsiteadmin:
    foreach $entry (@alluseroids) {
        ($ok, $user) = $cce->get($entry);
        push(@allusers, $entry);
        if ($user->{capabilities} =~ /siteAdmin/) {
            push(@siteadmin, $entry);
        }
        else {
            push(@notsiteadmin, $entry);
        }
    }

    if ($vsite_disk->{vsite_over_quota} eq '1') {
        &debug_msg("Vsite ftpnonadmin: $ftpnonadmin\n");
        if ($ftpnonadmin eq "0") {
            # FTP for non-siteAdmins is disabled anyway. Only limit write access for siteAdmins:
            foreach my $oid (@siteadmin) {
                ($ok, $user) = $cce->get($oid);
                $userhomedir = homedir_get_user_dir($user->{name}, $user->{site}, $user->{volume});
                &debug_msg("Processing siteAdmin " . $user->{name} . " with homeDir $userhomedir\n");
                if ($userhomedir =~ /^\/.+/) {
                    if (-d "$userhomedir") {
                        &debug_msg("Disabling FTP write access for siteAdmin " . $user->{name} . "\n");
                        system("/bin/echo '<Limit WRITE>\nDenyAll\n</Limit>\n<Limit DELE>\nAllowAll\n</Limit>\n' > $userhomedir/.ftpaccess");
                    }
                }
            }
        }
        else {
            # FTP for non-siteAdmins is enabled. Limit write access for everyone:
            foreach my $oid (@allusers) {
                ($ok, $user) = $cce->get($oid);
                $userhomedir = homedir_get_user_dir($user->{name}, $user->{site}, $user->{volume});
                &debug_msg("Processing User " . $user->{name} . " with homeDir $userhomedir\n");

                if ($userhomedir =~ /^\/.+/) {
                    if (-d "$userhomedir") {
                        &debug_msg("Disabling FTP write access for User " . $user->{name} . "\n");
                        system("/bin/echo '<Limit WRITE>\nDenyAll\n</Limit>\n<Limit DELE>\nAllowAll\n</Limit>\n' > $userhomedir/.ftpaccess");
                    }
                }
            }
        }

        # We exit here, because if the Vsite is over-quota, we're not handling FTPNONADMIN changes:
        &debug_msg("Exit from subroutine manage_ftp_write()\n");
        $cce->bye('SUCCESS');
        exit(0);
    }
    else {
        # Vsite is no longer over-quota. Remove .ftpaccess files which limit WRITE access (and only these!):
        &debug_msg("Vsite is not over-quota\n");

        # Define the content to search for
        my $target_pattern = qr/<Limit WRITE>.*/s;

        # Execute the find command and process the results
        $base_dir = $documentRoot . '/home/users/';
        &debug_msg("Vsite is not over-quota. Checking $base_dir for .ftpaccess files which limit WRITE access.\n");

        open my $find_fh, '-|', "find $base_dir -type f -name '.ftpaccess'" or die "Could not run find: $!";
        while (my $file = <$find_fh>) {
            chomp $file;
            # Read the file content
            open my $file_fh, '<', $file or next;
            &debug_msg("Reading: $file\n");
            my $file_content = do { local $/; <$file_fh> };
            close $file_fh;

            # Check if the content matches
            if ($file_content =~ $target_pattern) {
                unlink $file or warn "Could not delete file $file: $!";
                &debug_msg("Deleted file: $file\n");
            }
        }
        close $find_fh;
    }
}

#
## Main sub
#
sub main {

    # Check if FTPNONADMIN is enabled or disabled:
    $documentRoot = $vsite->{basedir};
    $siteNumber = $vsite->{name};
    @soids = $cce->find('Vsite', {'name' => $siteNumber});
    ($ok, $flag) = $cce->get($soids[0], 'FTPNONADMIN');
    $ftpnonadmin = $flag->{enabled};

    &debug_msg("Working on Vsite $siteNumber to set FTP access flag to $ftpnonadmin.");

    # Find all users who belong to the site in question:
    @alluseroids = $cce->find('User', {'site' => $siteNumber});

    # Walk through the OIDs and push the OIDs of all non-siteAdmin users to array @notsiteadmin:
    foreach $entry (@alluseroids) {
        ($ok, $user) = $cce->get($entry);
        unless ($user->{capabilities} =~ /siteAdmin/) {
            $func = push(@notsiteadmin, $entry);
        }
        # Delete all prviously set .ftpaccess files from ALL users of this site.
        # That way we cover the cases where the siteAdmin flag was previously not granted, but then got added.
        $alluserhomedir = '';
        if (!scalar(@pwent) || exists($new->{site})) {
            $alluserhomedir = homedir_get_user_dir($user->{name}, $user->{site}, $user->{volume});
            if ($alluserhomedir =~ /^\/.+/) {
                if (-d "$alluserhomedir") {
                    system("/bin/rm -f $alluserhomedir/.ftpaccess");    
                }
            }
        }
    }

    # Process all non-SiteAdmin users:
    foreach $entry (@notsiteadmin) {
        ($ok, $user) = $cce->get($entry);

        # Find the home directories of the respective users:
        $homedir = '';
        if (!scalar(@pwent) || exists($new->{site})) {
            $homedir = homedir_get_user_dir($user->{name}, $user->{site}, $user->{volume});
        }

        # Do the file actions. Either remove or add the .ftpaccess files to/from the respective user directories: 
        if ($ftpnonadmin eq "0") {  
            # Put the .ftpaccess files into the respective user directories: 
            if ($homedir =~ /^\/.+/) {
                if (-d "$homedir") {
                    &debug_msg("Working on Vsite $siteNumber to create $homedir/.ftpaccess");
                    system("/bin/echo '<Limit RAW LOGIN READ WRITE DIRS ALL>\nDenyAll\n</Limit>\n' > $homedir/.ftpaccess");
                }
            }
        }
        else {
            &debug_msg("Working on Vsite $siteNumber to delete $homedir/.ftpaccess");
            # Remove the .ftpaccess file from this user:
            system("/bin/rm -f $homedir/.ftpaccess");   
        }
    }
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
# Copyright (c) 2008-2024 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2024 Team BlueOnyx, BLUEONYX.IT
# Copyright (c) 2003 Sun Microsystems, Inc. 
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
