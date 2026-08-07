#!/usr/bin/perl -w -I/usr/sausalito/perl
use strict;
use warnings;
use CCE;
use Text::ParseWords qw(shellwords);

# --- Configuration ---
my $DEBUG = 0;
# ------------------

# Arguments: full_command_string
my ($full_command) = @ARGV;

# Basic argument check
if (!$full_command) {
    print "ERROR: Missing arguments. Usage: ai_helper.pl <cmd>\n";
    exit 1;
}

my $ok = '';

# 1.) Connect to CCE:
my $cce = new CCE;
$cce->connectuds();

# 2.) Authenticate only if both environment variables are present and non-empty:
if (defined $ENV{'CCE_USERNAME'} && $ENV{'CCE_USERNAME'} ne '' && defined $ENV{'CCE_SESSIONID'} && $ENV{'CCE_SESSIONID'} ne '') {
    ($ok) = $cce->authkey($ENV{'CCE_USERNAME'}, $ENV{'CCE_SESSIONID'});
}
else {
    print "ERROR: Unauthorized access denied.\n";
    $cce->bye();
    exit 1;
}

# 3.) Check Auth-state:
my $whoami = $cce->whoami();
if ($whoami ne '1') {
    print "ERROR: Unauthenticated access denied.\n";
    $cce->bye();
    exit 1;
}

# 4.) Check Privileged Tools Whitelist
my ($sysoid) = $cce->find('System');
my ($sys_ok, $System) = $cce->get($sysoid);
my ($ai_ok, $AI) = $cce->get($sysoid, 'AI');

if ((!$sys_ok) || (!$ai_ok)) {
    print "ERROR: System object not found in CCE\n";
    $cce->bye();
    exit 1;
}

my @allowed_tools = $cce->scalar_to_array($AI->{'priv_tools_available'});

# 5.) Parse and Validate Command
my @cmd_parts = shellwords($full_command);
my $bin_path = $cmd_parts[0];

if (!$bin_path) {
    print "ERROR: Empty command\n";
    $cce->bye();
    exit 1;
}

# Check if the binary path is in the whitelist
my $is_allowed = 0;
foreach my $allowed (@allowed_tools) {
    $allowed =~ s/^\s+|\s+$//g; # trim
    next if $allowed eq '';
    # Exact match required (e.g. /usr/bin/systemctl)
    if ($allowed eq $bin_path) {
        $is_allowed = 1;
        last;
    }
}

if (!$is_allowed) {
    print "ERROR: Command '$bin_path' not in allowed wrapper whitelist\n";
    $cce->bye();
    exit 1;
}

# 6.) Execute Command (Securely)
# Using open with a pipe to avoid shell injection.
# Since we validated the binary path and we are passing the arguments as a list,
# shell metacharacters in arguments are treated as literals, not commands.

my $output = "";
# Using open with -| fork/exec style
if (my $pid = open(my $fh, '-|')) {
    # Parent process
    local $/; # Enable slurp mode
    $output = <$fh>;
    close($fh);
}
else {
    # Child process
    die "Cannot fork: $!" unless defined $pid;
    # Exec the command with arguments.
    # This replaces the perl process with the command, no shell involved.
    exec(@cmd_parts) or die "Cannot exec $bin_path: $!";
}

$cce->bye();

# Return output to Python
print $output;

exit 0;

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
