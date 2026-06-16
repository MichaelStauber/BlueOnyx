#!/usr/bin/perl -w -I/usr/sausalito/perl -I/usr/sausalito/handlers/base/email
# Author: Brian N. Smith
#
# use Sauce::Util::editfile by Hisao

use strict;
use CCE;
use Email;
use Sauce::Service;
use Sauce::Util;

sub nonDuplicatedArray {
  my @Duplicated=@_;
  my %seen=();
  my (@NonDuplicatedArray,@Unique);
  @Unique = grep {! $seen{$_}++} @Duplicated;
  @NonDuplicatedArray = sort(@Unique);
  return @NonDuplicatedArray;
}

my $Access = $Email::ACCESS;

my $cce = new CCE;
$cce->connectfd();

my $oid = $cce->event_oid();

my ($ok, $domain) = $cce->get($oid);

my $virtualsite = $domain->{'fqdn'};
my @server_aliases = $cce->scalar_to_array($domain->{'mailAliases'});
push(@server_aliases, $virtualsite);
my $access_list;
my @emailList;
if ( $domain->{'emailDisabled'} eq "1" ) {
  foreach my $server(@server_aliases) {
    if ($server) {
      push(@emailList, $server);
    }
  }
}

@emailList = nonDuplicatedArray(@emailList);
foreach my $entry(@emailList) {
  $access_list .= $entry . "\t\tERROR:5.1.1:550 User unknown\n";
}

if (!Sauce::Util::replaceblock($Access,
    "### Start Block Email for Virtual Site: $virtualsite ###", $access_list,
    "### END Block Email for Virtual Site: $virtualsite ###")) {
    $cce->warn('[[base-email.cantEditFile]]', { 'file' => $Access });
    $cce->bye('FAIL');
    exit(1);
}

$cce->bye("SUCCESS");
exit(0);

# 
# Copyright (c) 2008-2022 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2008-2022 Team BlueOnyx, BLUEONYX.IT
# Copyright (c) 2006      NuOnce Networks, Inc.
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