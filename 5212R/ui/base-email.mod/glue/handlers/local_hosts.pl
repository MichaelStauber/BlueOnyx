#!/usr/bin/perl -w -I/usr/sausalito/perl/ -I/usr/sausalito/handlers/base/email/
# $Id: local_hosts.pl
#

use strict;
use CCE;
use Email;
use Sauce::Util;

my $Sendmail_cw = Email::SendmailCW;

my $cce = new CCE( Domain => 'base-email' );

$cce->connectfd();

my $sys_obj = $cce->event_object();
my $new_sys = $cce->event_new();
my ($ok, $email) = $cce->get($cce->event_oid(), 'Email');

if (not $ok) {
    $cce->bye('FAIL');
    exit(1);
}

if ($new_sys->{hostname} || $new_sys->{domainname} || 
    ($cce->event_property() eq 'acceptFor')) {
    if(!Sauce::Util::replaceblock($Sendmail_cw,
        '# Cobalt System Section Begin',
        &make_sendmail_cw($email, $sys_obj),
        '# Cobalt System Section End')
        ) {
        $cce->warn('[[base-email.cantEditFile]]', 
                { 'file' => Email::SendmailCW });
        $cce->bye('FAIL');
        exit(1);
    }
}

$cce->bye('SUCCESS');
exit(0);

sub make_sendmail_cw
{
    my $obj = shift;
    my $sys = shift;

    my @aliases;

    @aliases = $cce->scalar_to_array($obj->{acceptFor});
    
    # always accept email addressed to this machine
    # there is a flag for this in cce, but no ui widget
    # don't know why you would not want to accept mail to your fqdn
    push @aliases, $sys->{hostname} . "." . $sys->{domainname};

    return join("\n",@aliases);
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