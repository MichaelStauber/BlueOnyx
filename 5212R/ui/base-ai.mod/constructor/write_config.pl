#!/usr/bin/perl -w -I/usr/sausalito/perl -I.
# $Id$

use strict;
use CCE;
use I18n;
use JSON::PP;

my $cce = new CCE;
$cce->connectuds();

# Default privileged tools (whitelist for ai_helper.pl)
my @default_priv_tools = (
    '/home/ai/wrappers/ai-read-log',
    '/home/ai/wrappers/ai-search-logs',
    '/home/ai/wrappers/ai-journalctl',
    '/home/ai/wrappers/ai-mail-stats',
    '/home/ai/wrappers/ai-service-status',
    '/home/ai/wrappers/ai-system-info',
    '/home/ai/wrappers/ai-uname',
    '/usr/sausalito/sbin/vsite_list.pl',
    '/usr/sausalito/sbin/ssl_get.pl',
    '/usr/sausalito/sbin/ai-ssl-health.py',
    '/usr/sausalito/sbin/ai-php-fpm-health.py'
);

# Config file location
my $config_file = '/home/ai/ai_config.json';

sub generate_service_api_key {
    my $bytes = '';
    if (open(my $urandom, '<', '/dev/urandom')) {
        binmode($urandom);
        read($urandom, $bytes, 32);
        close($urandom);
    }
    if (!defined($bytes) || length($bytes) < 16) {
        $bytes = pack('L!L!L!L!', time(), $$, rand(0xffffffff), rand(0xffffffff));
    }
    return unpack('H*', $bytes);
}

sub write_json_config {
    my ($file, $config) = @_;
    my $json = JSON::PP->new->utf8->pretty(1)->encode($config);
    umask(0027);
    open(my $fh, ">", $file) or die "Cannot open $file: $!";
    print $fh $json;
    close($fh);
    chown(scalar(getpwnam('blueonyx_ai')), scalar(getgrnam('blueonyx_ai')), $file);
    chmod(0640, $file);
}

my ($oid) = $cce->find('System');
my ($ok, $ai_config) = $cce->get($oid, 'AI');
my $write_json = 0;

if (!$ok) {
    $ai_config = {
        'enabled' => 0,
        'provider' => 'local',
        'openai_api_key' => '',
        'openrouter_api_key' => '',
        'ollama_api_key' => '',
        'custom_api_key' => '',
        'service_api_key' => generate_service_api_key(),
        'default_model' => 'SmolLM2-360M-Instruct-Q4_K_M.gguf',
        'models_cache' => [],
        'custom_endpoint' => '',
        'idle_timeout' => 5,
        'system_prompt' => '',
        'force_update' => time(),
        'tools_enabled' => 1,
        'read_only_tools_enabled' => 1,
        'diagnostics_tools_enabled' => 1,
        'actions_tools_enabled' => 1,
        'allow_generic_privileged_command' => 0,
        'priv_tools_available' => [@default_priv_tools]
    };
    $ai_config->{'force_update'} = time();
    my ($set_ok) = $cce->set($oid, 'AI', $ai_config);
    $write_json = 1;
}
elsif (!defined($ai_config->{'service_api_key'}) || $ai_config->{'service_api_key'} eq '') {
    $ai_config->{'service_api_key'} = generate_service_api_key();
    my ($set_ok) = $cce->set($oid, 'AI', {
        'service_api_key' => $ai_config->{'service_api_key'},
        'force_update' => time(),
    });
    $write_json = 1;
}

if (!-f $config_file || $write_json) {
    write_json_config($config_file, $ai_config);
}

$cce->bye('SUCCESS');
exit(0);

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
