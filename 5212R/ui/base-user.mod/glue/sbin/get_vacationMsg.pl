#!/usr/bin/perl -I/usr/sausalito/perl -I.

use CCE;
use Getopt::Long;

# Command line options
my $username;

GetOptions(
    'name=s' => \$username,
);

unless ($username) {
    print "Please provide a username using the --name option.\n";
    exit 1;
}

my $cce = new CCE;
$cce->connectuds();

# Find User:
my (@UserOID) = $cce->findx('User', { 'name' => $username });

if (@UserOID) {
    my ($ok, $User) = $cce->get($UserOID[0]);
    if ($ok) {
        ($ok, $User_Email) = $cce->get($UserOID[0] , 'Email');
    }
    else {
        print "No such User!\n";
        $cce->bye('FAIL');
        exit 1;
    }
    print $User_Email->{'vacationMsg'};
}
else {
    print "User not found!\n";
    $cce->bye('FAIL');
    exit 1;
}

# Tell CCE everything is okay
$cce->bye('SUCCESS');
exit(0);

# 
# Copyright (c) 2008-2023 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2023 Team BlueOnyx, BLUEONYX.IT
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