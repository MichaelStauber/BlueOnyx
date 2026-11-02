#
# $Id: Client.pm
#
# Client API for the Sauce::Service::Daemon just allows you to talk to
# the daemon programmatically
#

package Sauce::Service::Client;

# Debugging switch:
$DEBUG = "1";
if ($DEBUG) {
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

use lib qw(/usr/sausalito/perl);

my $QUEUE_DIR = '/usr/sausalito/services/';

# The events a queue entry may carry: the five Daemon.pm executes, plus 'start', which callers
# already queue. Anything else would be written and then never executed, so it would sit in the
# queue until it aged out and made /gui/service report a restart in progress for five minutes.
# Skip the write for those, and keep returning success: that is what this call did before (it
# wrote nothing for an unknown event), and the calls that pass '0'/'1' - base-ftp's proftpd
# toggle - have already had the real work done by service_toggle_init().
my %QUEUE_EVENTS = map { $_ => 1 } qw(stop reload start restart condrestart condreload);

sub new {
    my $self = shift;
    my $class = ref($self) || $self;
    $self = bless({}, $class);
    $self->init(@_);
    return $self;
}

sub init {
    my ($self, @args) = @_;
    $self->{connected} = 0;
}

sub register_event
{
    my ($self, $service, $event) = @_;

    if ($service eq '' || $event eq '') {
        debug_msg("Missing \$service or \$event - doing nothing.");
        return(0);
    }

    if (!$QUEUE_EVENTS{$event}) {
        debug_msg("Not queueing '$event' for '$service' - the daemon cannot execute it.");
        return(1);
    }

    if (! -d $QUEUE_DIR) {
        debug_msg("Creating directory: $QUEUE_DIR");
        system("/bin/mkdir $QUEUE_DIR");
        system("/bin/chmod 700 $QUEUE_DIR");
        system("/bin/chown root:root -R $QUEUE_DIR");
    }

    my $queue_file = $QUEUE_DIR . $service;
    my $timestamp = time();

    debug_msg("Using queue file: $queue_file");

    # Write the event to a dot-prefixed temporary file in the same directory and rename it
    # over the queue file. rename(2) inside one filesystem is atomic, so a reader sees either
    # the previous contents or the new ones - never a truncated file. Daemon.pm already skips
    # dot-prefixed entries and does not count them, so the temporary file is invisible to it.
    # chmod runs before the rename, so the file is 0600 from the moment it becomes visible.
    my $tmp_file = $QUEUE_DIR . '.' . $service . '.' . $$ . '.new';

    open(my $fh, '>', $tmp_file) or do {
        debug_msg("Failed to open $tmp_file for writing: $!");
        return(0);
    };
    print $fh "$event\n$timestamp\n";
    close($fh) or do {
        debug_msg("Failed to close $tmp_file: $!");
        unlink($tmp_file);
        return(0);
    };
    chmod(0600, $tmp_file);

    if (!rename($tmp_file, $queue_file)) {
        debug_msg("Failed to rename $tmp_file to $queue_file: $!");
        unlink($tmp_file);
        return(0);
    }

    debug_msg("Updated $queue_file with $event request");
    return(1);
}

sub debug_msg {
    if ($DEBUG) {
        my $msg = shift;
        setlogsock('unix');
        openlog($0,'','user');
        syslog('info', "Client.pm: $msg");
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