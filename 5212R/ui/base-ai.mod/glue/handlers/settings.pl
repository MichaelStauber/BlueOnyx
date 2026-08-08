#!/usr/bin/perl -I/usr/sausalito/perl
# $Id: settings.pl
#
# Handler for AI settings changes.
# Writes AI config from CCE to /home/ai/ai_config.json
# Also serves as constructor (when called with $whatami = "constructor")

use CCE;
$cce = new CCE;

use Sauce::Util;
use JSON::PP;

$DEBUG = "1";
if ($DEBUG) { 
    use Sys::Syslog qw( :DEFAULT setlogsock);
}

# Config file location
$config_file = '/home/ai/ai_config.json';

# Main logic
$cce->connectfd();

# Write config
&write_ai_config();

$cce->bye('SUCCESS');
exit(0);

#
### Subs:
#

# Debugging
sub debug_msg {
    if ($DEBUG) {
        my $msg = shift;
        setlogsock('unix');
        openlog($0,'','user');
        syslog('info', "$ARGV[0]: $msg");
        closelog;
    }
}

# Function to write AI config
sub write_ai_config {
    # Get System object
    @sysoids = $cce->find('System');
    if ($#sysoids < 0) {
        &debug_msg("No System object found!\n");
        $cce->bye('FAILURE');
        exit(1);
    }
    
    # Get AI properties (from AI namespace)
    my $old_ai = $cce->event_old();
    $old_ai = {} if (!defined($old_ai) || ref($old_ai) ne 'HASH');
    ($ok, $ai_config) = $cce->get($sysoids[0], 'AI');
    
    if (!$ok) {
        &debug_msg("Failed to get AI properties from System object\n");
        # Write defaults
        $ai_config = {
            'enabled' => 0,
            'provider' => 'local',
            'openai_api_key' => '',
            'openrouter_api_key' => '',
            'ollama_api_key' => '',
            'anthropic_api_key' => '',
            'custom_api_key' => '',
            'service_api_key' => '',
            'default_model' => '',
            'custom_endpoint' => '',
            'idle_timeout' => 5,
            'system_prompt' => '',
            'tools_enabled' => 1,
            'read_only_tools_enabled' => 1,
            'diagnostics_tools_enabled' => 1,
            'actions_tools_enabled' => 1,
            'allow_generic_privileged_command' => 0,
            'priv_tools_available' => [],
        };
    }

    # Refuse an enabled local-provider configuration unless the packaged
    # runtime can load a CPU backend and the selected model is usable.
    if (config_value($ai_config, 'enabled') eq '1' && config_value($ai_config, 'provider') eq 'local') {
        my ($local_ok, $local_reason) = check_local_capability(config_value($ai_config, 'default_model'));
        if (!$local_ok) {
            $local_reason = 'Local inference is unavailable.' if ($local_reason eq '');
            &debug_msg("Rejecting local provider configuration: $local_reason\n");
            $cce->warn('local_provider_unavailable', { 'reason' => $local_reason });
            $cce->bye('FAILURE');
            exit(1);
        }
    }
    
    # Add metadata
    $ai_config->{'_updated'} = time();
    $ai_config->{'_source'} = 'CCE';

    # Convert to JSON
    my $json = JSON::PP->new->utf8->pretty(1)->encode($ai_config);
    
    # Write to file
    umask(0027);
    open(my $fh, ">", $config_file) or die "Cannot open $config_file: $!";
    print $fh $json;
    close($fh);
    
    # Set ownership
    chown(scalar(getpwnam('blueonyx_ai')), scalar(getgrnam('blueonyx_ai')), $config_file);
    chmod(0640, $config_file);
    
    &debug_msg("AI config written to $config_file\n");

    &restart_runtime_services($old_ai, $ai_config);
    
    return 1;
}

sub config_value {
    my ($config, $key) = @_;
    return '' if (!defined($config) || ref($config) ne 'HASH');
    return '' if (!defined($config->{$key}));

    my $value = $config->{$key};
    if (ref($value) eq 'ARRAY') {
        return join('&', @{$value});
    }
    elsif (ref($value) eq 'HASH') {
        return JSON::PP->new->canonical(1)->encode($value);
    }

    $value =~ s/^\s+|\s+$//g;
    return $value;
}

sub changed_value {
    my ($old, $new, $key) = @_;
    return config_value($old, $key) ne config_value($new, $key);
}

sub check_local_capability {
    my ($model) = @_;
    $model = '' if (!defined($model));
    $model =~ s/^\s+|\s+$//g;
    return (0, 'No local model is selected.') if ($model eq '');

    my $helper = '/home/ai/bin/blueonyx-llama-check';
    return (0, 'The local inference capability checker is not installed.') if (!-x $helper);

    my $output = '';
    if (open(my $fh, '-|', $helper, '--model', $model)) {
        local $/;
        $output = <$fh>;
        close($fh);
        my $rc = $? >> 8;
        my $payload;
        eval { $payload = JSON::PP->new->utf8->decode($output || '{}'); };
        if ($rc == 0 && ref($payload) eq 'HASH' && $payload->{'available'}) {
            return (1, '');
        }
        if (ref($payload) eq 'HASH' && defined($payload->{'reason'})) {
            return (0, $payload->{'reason'});
        }
    }

    return (0, 'The local inference capability check failed.');
}

sub update_local_model_symlink {
    my ($model) = @_;
    $model = '' if (!defined($model));
    $model =~ s/^\s+|\s+$//g;
    return 0 if ($model eq '');

    my $target = "/home/ai/models/$model";
    my $link = "/home/ai/models/default.gguf";

    if (!-e $target) {
        &debug_msg("Local model target does not exist: $target\n");
        return 0;
    }

    if (-e $link || -l $link) {
        unlink($link) or do {
            &debug_msg("Failed to remove existing default.gguf link: $!\n");
            return 0;
        };
    }

    if (!symlink($target, $link)) {
        &debug_msg("Failed to create default.gguf symlink to $target: $!\n");
        return 0;
    }

    &debug_msg("Updated default.gguf symlink to $target\n");
    return 1;
}

sub restart_runtime_services {
    my ($old_ai, $new_ai) = @_;
    $old_ai = {} if (!defined($old_ai) || ref($old_ai) ne 'HASH');
    $new_ai = {} if (!defined($new_ai) || ref($new_ai) ne 'HASH');

    my $provider_changed = changed_value($old_ai, $new_ai, 'provider');
    my $enabled_changed = changed_value($old_ai, $new_ai, 'enabled');
    my $model_changed = changed_value($old_ai, $new_ai, 'default_model');
    my $endpoint_changed = changed_value($old_ai, $new_ai, 'custom_endpoint');
    my $key_changed =
        changed_value($old_ai, $new_ai, 'openai_api_key') ||
        changed_value($old_ai, $new_ai, 'openrouter_api_key') ||
        changed_value($old_ai, $new_ai, 'ollama_api_key') ||
        changed_value($old_ai, $new_ai, 'anthropic_api_key') ||
        changed_value($old_ai, $new_ai, 'custom_api_key');
    my $prompt_changed = changed_value($old_ai, $new_ai, 'system_prompt');
    my $tools_changed = changed_value($old_ai, $new_ai, 'tools_enabled');
    my $read_only_tools_changed = changed_value($old_ai, $new_ai, 'read_only_tools_enabled');
    my $diagnostics_tools_changed = changed_value($old_ai, $new_ai, 'diagnostics_tools_enabled');
    my $actions_tools_changed = changed_value($old_ai, $new_ai, 'actions_tools_enabled');
    my $generic_privileged_changed = changed_value($old_ai, $new_ai, 'allow_generic_privileged_command');
    my $priv_tools_changed = changed_value($old_ai, $new_ai, 'priv_tools_available');

    my $restart_ai = $enabled_changed || $provider_changed || $model_changed || $endpoint_changed || $key_changed || $prompt_changed || $tools_changed || $read_only_tools_changed || $diagnostics_tools_changed || $actions_tools_changed || $generic_privileged_changed || $priv_tools_changed;
    my $restart_llama = 0;

    if ($restart_ai) {
        if (system('/usr/bin/systemctl', 'restart', 'sausalito-ai.service') != 0) {
            &debug_msg("Failed to restart sausalito-ai.service\n");
        }
        else {
            &debug_msg("Restarted sausalito-ai.service\n");
        }
    }

    my $new_provider = config_value($new_ai, 'provider');
    my $old_provider = config_value($old_ai, 'provider');
    my $new_model = config_value($new_ai, 'default_model');

    if ($new_provider eq 'local' && ($provider_changed || $model_changed || $endpoint_changed || $old_provider ne 'local')) {
        $restart_llama = update_local_model_symlink($new_model);
    }

    # Configuration changes never load the model. Stop any stale instance and
    # let the next local chat request start it after capability preflight.
    if ($provider_changed || $model_changed || $enabled_changed || $new_provider ne 'local') {
        if (system('/usr/bin/systemctl', 'stop', 'sausalito-llama.service') != 0) {
            &debug_msg("Failed to stop stale sausalito-llama.service\n");
        }
        else {
            &debug_msg("Stopped stale sausalito-llama.service\n");
        }
    }

    return ($restart_ai || $restart_llama) ? 1 : 0;
}

# 
# Copyright (c) 2026 Michael Stauber, SOLARSPEED.NET
# Copyright (c) 2026 Team BlueOnyx, BLUEONYX.IT
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
