#!/usr/bin/perl -w -I/usr/sausalito/perl/ -I/usr/sausalito/handlers/base/email/
# $Id: $

use strict;
use CCE;
use Email;
use Sauce::Util;

# For now we don't need this:
#
#my $Postfix_cf = Email::PostfixMainCF;
#
#my $cce = new CCE( Domain => 'base-email' );
#
#$cce->connectfd();
#
#my $obj = $cce->event_object();
#
#if ($obj->{hostname} || $obj->{domainname} || 
#   ($cce->event_property() eq 'acceptFor')) {
#   Sauce::Util::editfile($Postfix_cf, *make_main_cf, $obj );
#}

$cce->bye('SUCCESS');
exit(0);

sub make_main_cf
{
    my $in  = shift;
    my $out = shift;

    my $obj = shift;

    my $hostname = $obj->{hostname} . "." . $obj->{domainname};

    select $out;
    while(<$in>) {
        if (/^myhostname = /o) {
            print "myhostname = $hostname\n";
        } else {
            print $_;
        }
    }
    return 1;
}

# 
# Copyright (c) 2008-2022 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2022 Team BlueOnyx, BLUEONYX.IT
# Copyright (c) 2003-2008 BlueQuartz
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
