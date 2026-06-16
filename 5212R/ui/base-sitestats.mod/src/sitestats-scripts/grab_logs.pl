#!/usr/bin/perl
#
# $Id: grab_logs.pl
#
# given the log type, group name, and begin and end dates, returns all logged
# activity in the specified date range
#
# Command line options:
#   --type=(ftp|mail|web|net)
#   --group=site2   This is only needed for per-site logs not server-wide.
#   --begin="Day/Month/Year"
#   --end="Day/Month/Year"
#
#   begin and end ar the first day of log activity to output and the last
#   day of activity respectively.  Day, month, and year should all be
#   in their numeric form.  Begin or end are optional specifying only one
#   will result in everything before/after the given date.  If neither is
#   specified, all available logs of the given type will be output.
#

use strict;
use lib qw(/usr/sausalito/perl);
use Getopt::Long;
use POSIX qw(mktime);
use Base::HomeDir qw(homedir_get_group_dir);

my $DEBUG = 0;

my $type = '';
my $group = '';
my $begin = '';
my $end = '';

GetOptions('type=s' => \$type,
       'group:s' => \$group,
       'begin:s' => \$begin,
       'end:s' => \$end);

my @GUNZIP = ('/bin/gunzip', '-c');
my %MONTHS = ('1' => 'Jan', '2' => 'Feb', '3' => 'Mar', '4' => 'Apr',
          '5' => 'May', '6' => 'Jun', '7' => 'Jul', '8' => 'Aug',
          '9' => 'Sep', '10' => 'Oct', '11' => 'Nov', '12' => 'Dec');

# for date comparison
my %REVERSE_MONTHS = ();
for my $month (keys(%MONTHS)) {
    $REVERSE_MONTHS{$MONTHS{$month}} = $month;
}

# put dates in usable form for comparisons
if ($begin) {
    $begin = &clean_date(split('/', $begin));
}
if ($end) {
    $end = &clean_date(split('/', $end));
    # add a day so comparison is a small bit quicker
    $end += (24 * 60 * 60);
}

$DEBUG && print STDERR "options: type=$type group=$group begin=$begin end=$end\n";

my $LOGDIR = '/var/log';
my %SERVER_LOGS = ('web' => 'access',
           'mail' => 'maillog',
           'ftp' => 'xferlog',
           'net' => 'ipacct');

# regexs to match dates
my %SERVER_MATCH = ('web' => '\[(\d+)\/(\w+)\/(\d+)',
            'net' => '\[(\d+)\/(\w+)\/(\d+)',
            'ftp' => '^\w+\s+(\w+)\s+(\d+)\s+\S+\s+(\d+)',
            'mail' => '^(\w+)\s+(\d+)');

# web logs are in another directory for server
if ($type eq 'web') {
    $LOGDIR .= '/httpd';
}

# see if this is a site to get logs for
if ($group ne '') {
    $LOGDIR = homedir_get_group_dir($group) . '/var/logs';
}

# get the names of all posible log files to search
my $search_name = '';
if ($group ne '') {
    $search_name = '^' . $type . '\.log[\.]*';
} else {
    $search_name = '^' . $SERVER_LOGS{$type} . '[\.]*';
}

$DEBUG && print STDERR "log dir=$LOGDIR search string=$search_name\n";
my @log_files = ();
my $latest_mtime = 0;
opendir(LOGS, $LOGDIR);
while (my $entry = readdir(LOGS)) {
    if ($entry !~ /$search_name/) {
        next;
    }

    # found a log save it in the list
    my $file_name = "$LOGDIR/$entry";
    my $file_mtime = (stat($file_name))[9];
    push @log_files, { 
                'name' => $file_name,
                'mtime' => $file_mtime
             };

    # check if this file is later than the most recent mtime
    if ($file_mtime > $latest_mtime) {
        $latest_mtime = $file_mtime;
    }
}
closedir(LOGS);

# see if the daily log should be included for a site
if (($group ne '') && (-f "$LOGDIR/$type.daily")) {
    my $daily_mtime = (stat("$LOGDIR/$type.daily"))[9];
    if ($daily_mtime > $latest_mtime) {
        push @log_files, {
                    'name' => "$LOGDIR/$type.daily",
                    'mtime' => $daily_mtime
                 };
    }
}

# sort by modify time
@log_files = sort { $a->{mtime} <=> $b->{mtime} } @log_files;

# now, actually process each file
for my $file (@log_files) {
    $DEBUG && print STDERR "processing $file->{name}\n";
    $DEBUG && print STDERR "with modify stamp $file->{mtime}\n";

    # instantiate LOGFILE in this scope
    # local(*LOGFILE);

    # deal with gziped files
    if ($file->{name} =~ /\.gz$/) {
        my @cmd = @GUNZIP;
        push @cmd, $file->{name};
        # safe pipe read, so we bypass the shell
        open(LOGFILE, "-|") || exec(@cmd);
    } else {
        # regular file
        open(LOGFILE, $file->{name}) or die "$!\n";
    }

    my ($day, $month, $year, $previous_year);
    while (my $log_entry = <LOGFILE>) {
        # grab the date
        if ($group ne '') {
            $log_entry =~ /\[(\d+)\/(\w+)\/(\d+)/;
            ($day, $month, $year) = ($1, $2, $3);
        } else {
            $log_entry =~ /$SERVER_MATCH{$type}/;
            if ($type eq 'mail') {
                ($day, $month) = ($2, $1);
                #
                # try to figure out the year using the
                # knowledge that maillog for server is rotated
                # every day
                #
                my ($fday, $fmon, $fyear) =
                    (localtime($file->{mtime}))[3, 4, 5];
                $fyear += 1900;
                # check for end of year rollover
                if (($month eq 'Dec') && ($fmon == 0) &&
                    ($fday == 1)) {
                    $year = $fyear - 1;
                } else {
                    $year = $fyear;
                }
            } elsif ($type eq 'ftp') {
                ($day, $month, $year) = ($2, $1, $3);
            } else {
                ($day, $month, $year) = ($1, $2, $3);
            }
        }
        my $log_time = &clean_date($day, $REVERSE_MONTHS{$month},
                       $year);

        ($DEBUG > 1) && print STDERR "log time is $log_time\n";

        # does it fall in the range?
        if ($begin && ($log_time < $begin)) {
            # too early, skip
            next;
        } elsif ($end && ($log_time >= $end)) {
            # too late, skip
            next;
        }

        # in range output the line
        ($DEBUG > 1) && print STDERR "in range for $log_entry";
        print $log_entry;
    }

    close(LOGFILE);
}

# that's it
exit(0);

# takes list of day, month, year
sub clean_date
{
    my @parts = @_;
    
    # make sure there are no leading zeros
    for (my $i = 0; $i < scalar(@parts); $i++) {
        $parts[$i] =~ s/^0*//;
    }

    return mktime(0, 0, 0, $parts[0], ($parts[1] - 1), ($parts[2] - 1900));
}

# 
# Copyright (c) 2008-2022 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2022 Team BlueOnyx, BLUEONYX.IT
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