#!/usr/bin/perl -I /usr/sausalito/perl
#
# $Id: wizard_splash.pl
#
# copy in a default wizard welcome page based on the current productLanguage
#

use CCE;

# set umask properly, so files are readable
umask(002);

my $cce = new CCE;
$cce->connectfd();

# possibilities for splash screens
my @splashes = ({
            'dir' => '/usr/sausalito/ui/web/base/wizard',
            'file' => 'start.html'
        },
        {
            'dir' => '/usr/sausalito/ui/web',
            'file' => 'intro.html'
        });

# this never runs on DESTROY
my $system = $cce->event_object();

# the default is english
my $locale = 'en';

# use productLanguage if it contains something
if ($system->{productLanguage} ne '') {
    $locale = $system->{productLanguage};
}

# deal with all possibilities
for my $splash (@splashes) {
    # see if the file for the given locale exists
    my $loc_file = &find_locale($splash, $locale);
    if ($loc_file ne '') {
        Sauce::Util::copyfile($loc_file,
            "$splash->{dir}/$splash->{file}");
    }
    else {
        # just use english
        $loc_file = &find_locale($splash, 'en');
        if ($loc_file ne '') {
            Sauce::Util::copyfile($loc_file,
                "$splash->{dir}/$splash->{file}");
        }
    }
}

$cce->bye('SUCCESS');
exit(0);

#
### Subs:
#

sub find_locale {
    my ($splash, $locale) = @_;

    my $loc_file = '';

    opendir(SPLASH, $splash->{dir});
    while (my $entry = readdir(SPLASH)) {
        if ($entry =~ /^$splash->{file}\.(.+)$/) {
            # possible match, check locale
            my $file_locale = $1;
            
            if (($file_locale =~ /^$locale/i) ||
                ($locale =~ /^$file_locale/i)) {
                $loc_file = "$splash->{dir}/$entry";
                last;
            }
        }
    }
    closedir(SPLASH);

    return $loc_file;
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