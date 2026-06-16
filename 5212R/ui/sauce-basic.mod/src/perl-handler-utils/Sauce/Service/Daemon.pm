#
# $Id: Daemon.pm
#
# Client and server definition for an init script daemon to ensure
# init script runs don't overlap and end up killing off a service
# when trying to restart/reload the service many times during the same
# transaction.
#
# Designed with httpd in mind, but could work for any service provided
# the init script supports the status target.
# 

package Sauce::Service::Daemon;

$DEBUG = "1";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

use lib qw(/usr/sausalito/perl);
use POSIX qw(setsid);
use File::Path qw(make_path);
use File::Basename;
use JSON;

my $MTA = 'postfix';

my $QUEUE_DIR = '/usr/sausalito/services/';
my $REPORT_FILE = '/usr/sausalito/sessions/.sauce_serviced_daemon.state';

# Frequency with which we check the queue:
my $QUEUE_CHECK_INTERVAL = 15;

# How long we wait before a service will be restarted:
my $QUEUE_WAIT_TIME = 10; 

# Maximum allowed age of queue-files:
my $QUEUE_PURGE_TIME = 300;

sub new {
    my ($class) = @_;
    my $self = {};
    bless $self, $class;
    return $self;
}

sub _daemonize {
    my ($self) = @_;
    my $pid = fork();
    if (!defined($pid)) {
        die("Fork failed: $!\n");
    } elsif ($pid != 0) {
        exit(0);
    }
    setsid();
    $0 = 'sauce_serviced';
    close(STDIN);
    close(STDERR);
    close(STDOUT);
    open(STDOUT, '>', '/dev/null');
}

sub process_queue {

    my $file_count = `ls -k1 $QUEUE_DIR`;

    opendir(my $dh, $QUEUE_DIR) || do {
        &_logmsg("Can't open $QUEUE_DIR: $!");
        die "Can't open $QUEUE_DIR: $!";
    };

    # &_logmsg("Processing service events in $QUEUE_DIR");

    # Count the number of files in the directory
    my $file_count = 0;
    my @event_files = ();
    while (my $file = readdir($dh)) {
        next if $file =~ /^\./; # Skip hidden files and directories
        my $event_file = basename($file);
        push(@event_files, $event_file);
        $file_count++;
    }

    # Prepare data to be JSON-encoded
    my %data = (
        file_count => $file_count,
        event_files => \@event_files,
    );

    # JSON-encode the data
    my $json = encode_json(\%data);

    # Write the JSON data to the file
    open(my $fh, '>', $REPORT_FILE) or die "Could not open file '$REPORT_FILE' $!";
    print $fh $json;
    close($fh);

    # Reset the directory handle to the beginning
    rewinddir($dh);

    while (my $file = readdir($dh)) {
        next if $file =~ /^\./;
        my $path = "$QUEUE_DIR$file";

        #&_logmsg("Processing service event file: $path");

        open(my $fh, '<', $path) || do {
            &_logmsg("Can't open $path: $!");
            next;
        };

        my $event = <$fh>;
        my $timestamp = <$fh>;

        close($fh);

        chomp($event);
        chomp($timestamp);

        my $current_time = time();
        my $time_difference = $current_time - $timestamp;

        # Stop gets executed (more or less) right away. Anything else must wait for $QUEUE_WAIT_TIME seconds: 
        if ($event eq 'stop' || (($event eq 'reload' || $event eq 'restart' || $event eq 'condrestart' || $event eq 'condreload') && $time_difference >= $QUEUE_WAIT_TIME)) {
            my $service = basename($file);

            # Special MTA provisions:
            if (($service eq "sendmail") || ($service eq "postfix")) {
                # Check which MTA is actually configured to run:
                if (-f '/etc/sysconfig/bxmta') {
                    $MTA=`cat /etc/sysconfig/bxmta |grep ^MTA=|cut -d = -f2`;
                    chomp($MTA);
                    $MTA = lc $MTA;
                }
                else {
                    $MTA = 'sendmail';
                }
                if (($MTA eq 'postfix') && ($service eq 'sendmail')) {
                    &_logmsg("Request to manage 'sendmail', but we have Postfix configured as MTA. Deleting 'sendmail' cache file.");
                    unlink($path);
                    last;
                }
                if (($MTA eq 'sendmail') && ($service eq 'postfix')) {
                    &_logmsg("Request to manage 'postfix', but we have Sendmail configured as MTA. Deleting 'postfix' cache file.");
                    unlink($path);
                    last;
                }
            }

            # Special case YUM/DNF:
            if (($service eq "yum") || ($service eq "dnf")) {
                &_logmsg("Request to manage 'yum/dnf' - YUM/DNF Update is running.");
            }
            else {
                # Real service? Check if the service exists:
                my $service_status = `LC_ALL=C /usr/bin/systemctl status $service.service 2>&1`;
                if ($service_status !~ /Unit $service.service could not be found/) {

                    my $system_call = "/usr/bin/systemctl $event $service.service";
                    my $exit_code = system($system_call);

                    # If reload fails, attempt restart instead:
                    if ($exit_code != 0 && $event eq 'reload') {
                        &_logmsg("Reload failed for $service. Attempting restart instead.");
                        $event = 'restart';
                        $system_call = "/usr/bin/systemctl $event $service.service";
                        $exit_code = system($system_call);
                    }

                    if ($exit_code == 0) {
                        # Give it a moment to settle (e.g., 2 seconds)
                        sleep(2);
                        my $post_status = `LC_ALL=C /usr/bin/systemctl is-active $service.service 2>/dev/null`;
                        chomp($post_status);

                        if (
                            (($event eq 'stop')     && $post_status eq 'inactive') ||
                            (($event ne 'stop')     && $post_status eq 'active')
                        ) {
                            &_logmsg("$system_call: Transaction successful, service is in expected state: ($post_status)");
                            unlink($path);
                        }
                        else {
                            &_logmsg("$system_call: Transaction succeeded but service is in unexpected state (status: $post_status)");
                            # Leave the queue file for retry or investigation
                        }
                    }
                }
                else {
                    unlink($path);
                    &_logmsg("Service $service does not exist. Cache file removed.");
                }
            }
        }
        # Check if the queue file is older than $QUEUE_PURGE_TIME (five minutes or 300 seconds):
        if ($time_difference >= $QUEUE_PURGE_TIME) {
            unlink($path);
            &_logmsg("Queue file $file is older than allowed. Removing it as managing that service has obviously failed.");
            if (($file eq 'yum') || ($file eq 'dnf')) {
                if (-f '/tmp/yum.updating') {
                    &_logmsg("GUI-Lockfile for $file present: /tmp/yum.updating - Removing it.");
                    unlink('/tmp/yum.updating');
                }
            }
        }
    }
    closedir($dh);
}

sub run {
    my ($self) = @_;
    $self->_daemonize();

    # Set up signal handlers
    $SIG{ALRM} = sub {
        local $!; # Clear errno to prevent "Interrupted system call" error
        process_queue();
        alarm($QUEUE_CHECK_INTERVAL);
    };

    $SIG{INT} = $SIG{TERM} = sub {
        terminate(1);
    };

    make_path($QUEUE_DIR) unless -d $QUEUE_DIR;
    alarm($QUEUE_CHECK_INTERVAL);

    &_logmsg("${0}[${$}] Ready to accept requests");

    while (1) {
        sleep($QUEUE_CHECK_INTERVAL);
    }
}


sub _logmsg {
    my $msg = shift;
    &debug_msg("$msg \n");
}

sub debug_msg {
    if ($DEBUG) {
        my $msg = shift;
        setlogsock('unix');
        openlog($0, '', 'user');
        syslog('info', "Daemon.pm: $msg");
        closelog;
    }
}

1;

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