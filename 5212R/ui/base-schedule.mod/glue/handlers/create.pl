#!/usr/bin/perl
# $Id: create.pl
#
use Time::Local;
use CCE;
use Schedule;

$DEBUG = 0;

my $ok = 1;

my $cce = new CCE;
$cce->connectfd();

# Get information on the object being created.
my $namespace = $cce->event_namespace();
my $oid = $cce->event_oid();
my $obj = $cce->event_object();

$DEBUG && print STDERR 'create handler received object: ', Dumper($obj);

#
# Step 1: Make sure private parameters are not specified
#
my $changed = $cce->event_new();
if (defined $changed{'filename'}) {
        #
        # The user is not permitted to change the filename property.  Fail
        # with bad data.
        #
        $cce->baddata($oid, 'filename', '[[base-schedule.filename_private]]');
        $cce->bye('FAIL');
        exit(1);
}

#
# Step 2: Check the new objects parameters
#
$ok = Schedule::check_parameters($cce, $obj);
if (! $ok) {
        $cce->bye('FAIL');
        exit(1);
}

#
# Step 2: Determine if the schedule is active and if it should be added
# to the timer if it is.  A new file is created to store the data if
# necessary.
#
if ($obj->{'enabled'}) {
        $ok = Schedule::timer_add($cce, $obj);
        if (! $ok) {
                # Failed to remove the previous version of this schedule
                $cce->bye('FAIL', '[[base-schedule.timer_add_failed]]');
                exit(1);
        }
}

$cce->bye('SUCCESS');
exit(0);

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