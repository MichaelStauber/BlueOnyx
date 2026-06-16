#!/usr/bin/perl

use strict;
use warnings;
use lib qw(/usr/sausalito/perl);

use Sauce::Service::Daemon;

my $QUEUE_DIR = '/usr/sausalito/services/';

if (! -d $QUEUE_DIR) {
    system("/bin/mkdir $QUEUE_DIR");
    system("/bin/chmod 700 $QUEUE_DIR");
    system("/bin/chown root:root -R $QUEUE_DIR");
}

if (-f '/etc/sysconfig/sauce_serviced_proc_daemon') {

    #
    ### Configured to use Proc::Daemon:
    #

    use Proc::Daemon;

    # Initialize Proc::Daemon
    my $daemon = Proc::Daemon->new(
        pid_file => '/var/run/sauce_serviced.pid'
    );

    # Fork the process
    my $pid = $daemon->Init;

    if ($pid) {
        # Parent process
        exit(0);
    }

    # Child process
    my $service = Sauce::Service::Daemon->new();
    $service->run();

    # Clean up the PID file when the process exits
    END {
        unlink $daemon->{pid_file} if defined $daemon->{pid_file};
    }
}
else {

    #
    ### Not configured to use Proc::Daemon, run directly:
    #

    my $service = Sauce::Service::Daemon->new();
    $service->run();
}

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
