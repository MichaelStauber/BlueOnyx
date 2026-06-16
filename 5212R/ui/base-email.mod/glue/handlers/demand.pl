#!/usr/bin/perl -I/usr/sausalito/perl -I/usr/sausalito/handlers/base/email
# $Id: demand.pl

use CCE;
use Email;
use Sauce::Util;

my $SendmailCF = &Email::SendmailCF;

my $cce = new CCE( Namespace => 'Modem',
                      Domain => 'base-email' );

$cce->connectfd();

$obj = $cce->event_object();

my $ret = 
    Sauce::Util::editfile($SendmailCF, *set_demand, $cce,
        $obj->{connMode} eq 'demand' );
if(! $ret ) {
    $cce->warn("couldnt_write_sendmailcf");
    $cce->bye("FAIL");
} else {
    $cce->bye("SUCCESS");
}

exit(0);

sub set_demand {
    my $in = shift;
    my $out = shift;
    my $cce = shift;
    my $demand = shift;
    my $line;
    if( $demand ) {
        $line = "DialDelay=20s\n";
    } else {
        $line = "# DialDelay=20s\n";
    }
    
    while( <$in> ) {
        if( /DialDelay=/o ) {
            print $out $line;
            last;
        } else {
            print $out $_;
        }
    }

    while( <$in> ) {
        print $out $_;
    }

    return 1;
}

1;
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