# BlueOnyx AI Module - Working Notes

## Date: 2026-05-16

## Current Status
Model Discovery, provider-specific API key handling, and the tool-policy controls are implemented and working in the GUI/runtime. The llama.cpp subpackage now builds with CPU + Vulkan best-effort support; RPM packaging is still being tightened for EL10 RPATH handling and CPU-variant library naming.

## Update 2026-08-08

The module is now materially further along than the early notes above suggest. The 5210R tree is the working source base that was used to bring 5211R and 5212R forward, and the current code has already been validated by real package builds on EL8, EL9, and EL10.

### Current shipping state
- Local inference works through `llama.cpp` and `sausalito-llama.service`.
- Remote inference works through external providers, including Ollama Cloud via its OpenAI-compatible endpoint.
- The GUI can discover provider models, save provider-specific credentials, and switch between local and remote providers correctly.
- Deterministic BlueOnyx diagnostics now cover server health, Vsite inventory, SSL coverage, mail statistics, disk usage, quotas, incident timelines, admin-log summaries, web ownership, PHP-FPM state, and webroot integrity checks.
- The AI runtime is protected by a service auth key and runs behind a loopback-only HTTP service with socket activation.
- The code now builds successfully on:
  - 5210R / EL8
  - 5211R / EL9
  - 5212R / EL10

### Cross-EL llama.cpp build direction
- The approved direction is one consistent `llama.cpp` build strategy across EL8, EL9, and EL10 using GCC 14 toolset builds instead of per-platform compiler drift.
- The goal is broadest realistic x86-64 hardware support within each OS baseline.
- EL8 and EL9 can still support older CPUs when `llama.cpp` is built portably.
- EL10 still carries the OS-level `x86-64-v3` floor, so older pre-v3 hardware remains unsupported there regardless of the module.
- Separate SVN trees still exist for packaging/build-root reasons, but the source logic and build settings were intentionally converged.

### Confirmed 5210R hardware incident and fix direction
- On an AlmaLinux 8 system with older Xeon E5520 CPUs, the first local-LLM port failed with `make_cpu_buft_list: no CPU backend found`.
- Root cause was not permissions, not the model file, and not missing shared libraries.
- Root cause was a non-portable CPU backend build that effectively required newer x86 features than the live server had.
- The packaging/build work was then reoriented toward a portable multi-backend `llama.cpp` build, using the newer compiler toolchain while still targeting wider CPU compatibility.
- This is paired with product behavior that should not blindly advertise local inference on hardware where it cannot actually run.

### Local inference GUI/runtime behavior
- The AI settings page now hides local-provider capability warnings unless the local provider is actually selected.
- When a remote provider such as Ollama Cloud is active, the user should not see misleading local-inference status noise.
- Remote model discovery now refreshes correctly for non-local providers on page load.
- The local provider is started on demand and idled back down instead of running permanently.

### Python/runtime packaging corrections
- EL8 required special handling because the live runtime is Python 3.8, while newer platforms use newer system Python.
- The service packaging was corrected so the AI venv on EL8 is recreated with Python 3.8 explicitly.
- Helper-script shebang handling was also corrected during package build so directly executed helper scripts on EL8 remain runnable.
- A separate runtime issue was fixed where `litellm` / `tiktoken` tried to write under the read-only virtualenv path and crashed the service with a permission error.

### Deterministic BlueOnyx health work
- Health reporting was moved away from model guesswork toward deterministic helper output plus live system checks.
- The health summary now understands BlueOnyx service intent instead of assuming that every common Linux daemon must always be active.
- The implementation was adjusted to read BlueOnyx intent through privileged helper paths instead of trying to query CCEd directly as the unprivileged AI account.
- `named-chroot` is treated as the actual DNS service on BlueOnyx, not plain `named`.
- `proftpd` is treated as the BlueOnyx FTP service, not `vsftpd`.
- Optional add-ons must not be treated as universal base services. In particular, `fail2ban` was removed from the deterministic managed-service assumptions because it is an optional add-on, not a guaranteed core component.
- The Active Monitor object and its namespaces are the right truth source for enabled/managed services, rather than hard-coded daemon assumptions.

### SSL coverage behavior
- SSL coverage is based on Vsite SSL state and helper output, not just the physical presence of certificate files.
- A Vsite may still have certificate material on disk while SSL is disabled in the GUI.
- AdmServ certificate status is reported separately from Vsite SSL coverage and should not be mixed into the Vsite count.

### Webroot integrity / forensic work
- Webroot compromise questions are short-circuited to deterministic inspections instead of letting the model improvise shell-like reasoning.
- The scanner now distinguishes between strong content hits, stronger filename signals, and weaker filename heuristics.
- Follow-up forensic prompts can reuse the last inspected webroot within a session for weaker models.
- The real BlueOnyx Vsite storage layout matters:
  - Actual webroots live under `/home/.sites/<site>/wwwroot/web/`
  - `/home/sites/<fqdn>` is the symlinked convenience path
- A defect was fixed where the agent invented invalid paths such as `/home/sites/site1/wwwroot/web/`.
- The deterministic scan path now resolves Vsite webroots from the actual hidden site storage layout and accepts both the real path and the FQDN symlink path when the user provides an explicit path.

### Vsite and support grounding work
- Vsite-list questions are now answered from deterministic BlueOnyx helper output and return actual hosted domain names.
- Earlier nonsense answers such as "WordPress, Joomla, and Drupal" were eliminated by grounding Vsite inventory in live data.
- The health and support answers are being pushed toward BlueOnyx-specific truth instead of generic hosting folklore.

### SmolLM2 model packaging
- The `base-ai-model-SmolLM2` source package no longer has to rely on the GGUF file being present in every checkout.
- Build logic was adjusted so that if `SmolLM2-360M-Instruct-Q4_K_M.gguf` is already present locally it is used as-is, and if it is absent it is downloaded at build time from the BlueOnyx-hosted source URL.
- This keeps SVN workflows intact while avoiding GitHub file-size problems for the large GGUF payload.

### Consolidation work across 5210R, 5211R, and 5212R
- Top-level module metadata and build behavior were consolidated significantly.
- The main `Makefile` now carries the canonical module version/release and platform detection.
- Package metadata placeholders are filled from the build logic instead of being manually edited per tree.
- A `make clean` path was introduced so generated metadata can be rolled back to placeholders.
- The 5210R tree was used as the canonical working source while the 5211R and 5212R trees were brought forward and kept in sync.
- Real-world rebuild testing confirmed that the consolidated source can produce working packages on all three supported BlueOnyx release lines.

### Current version markers
- Module version in the 5210R tree is now `1.0.5-1`.
- `base-ai-core` RPM release in the 5210R tree is now `1.0.8-27`.

### Most recent fixes as of 2026-08-08
1. Removed the hard-coded `fail2ban` assumption from the deterministic managed-service health summary.
2. Corrected Vsite webroot integrity scanning so it no longer invents `/home/sites/siteN/...` paths and instead resolves the real `/home/.sites/<site>/wwwroot/web/` layout.

### Practical product stance
- Local LLM support is now good enough to ship, but performance on older EL8-class hardware can be poor even when it works correctly.
- On such hardware, external providers are likely to remain the better default for real use.
- The product should therefore expose local inference only when the capability checks and backend reality support it, and otherwise communicate clearly why local inference is unavailable.

## Update 2026-05-16
The AI settings and runtime path were converted from a single shared `api_key` to provider-specific keys.

### Current Status
- Model Discovery in the GUI is working for the active provider.
- Provider-specific keys are now stored and used independently:
  - `openai_api_key`
  - `openrouter_api_key`
  - `ollama_api_key`
  - `custom_api_key`
- The old shared `api_key` was removed from the schema and locales.
- The runtime provider now resolves the key from the selected provider only.
- `local` provider does not require an API key.
- `tools_enabled` is a real runtime toggle for the tool set.
- `allow_generic_privileged_command` is an explicit advanced toggle for the generic privileged command tool.
- `priv_tools_available` is now treated as a wrapper-script whitelist, not a generic command list.
- `base-ai-llama` builds with Vulkan when the host has the Vulkan development stack available.
- The llama packaging install step strips build RPATHs with `chrpath`.
- The llama packaging is normalizing `libggml-cpu` variant libraries so the RPM manifest can match what CMake actually installs.

### Important Runtime Rule
When the assistant talks to a selected provider, it must use that provider's own API key only.
There is no cross-provider fallback anymore.

The generic privileged command tool must stay behind the advanced checkbox and wrapper whitelist. It is confirmation-gated and should never behave like a free-form shell escape hatch.

### Key Flow
1. GUI saves provider-specific keys in `AiSettings.php`
2. Glue writes them into `/home/ai/ai_config.json`
3. `ai_service.py` loads the provider-specific config
4. `external_provider.py` selects the active provider key at runtime

### Discovery Notes
- Discovery still uses the request parameter name `api_key` for transport in the GUI controller.
- This is only a transport name for the AJAX discovery call.
- It is not used as a persisted shared setting anymore.

### Files Updated in This Phase
- `ui/chorizo/web/Controllers/AiSettings.php`
- `glue/handlers/settings.pl`
- `glue/handlers/ai_config.pl`
- `glue/schemas/ai.schema`
- `src/base-ai-core/sausalito_ai/ai_service.py`
- `src/base-ai-core/sausalito_ai/cce-get-system-ai`
- `src/base-ai-core/sausalito_ai/providers/external_provider.py`
- locale `ai.po` files
- `src/base-ai-core/sausalito_ai/agent/agent.py`
- `src/base-ai-core/sausalito_ai/tools/system_tools.py`
- `glue/sbin/ai_helper.pl`
  - `constructor/write_config.pl`
    - bootstrap-only constructor
    - seeds `System.AI` defaults only when `/home/ai/ai_config.json` does not exist
    - now matches the current AI defaults and wrapper whitelist, including `tools_enabled`, the tool-group toggles, and the read-only wrapper set
  - `src/base-ai-llama/base-ai-llama.spec`
  - `src/base-ai-llama/Makefile`
  - `src/base-ai-core/sausalito_ai/tools/system_tools.py`
  - `src/base-ai-core/sausalito_ai/ai_service.py`
  - `src/base-ai-core/sausalito_ai/agent/agent.py`

### Remaining `api_key` References
- Discovery request parameter in the GUI
- LiteLLM parameter name in the provider wrapper
- Provider runtime variable names in Python

### Notes
- Legacy migration was intentionally not implemented because the module is not yet productive.
- Existing GUI tooltip/help text for password fields was fixed at the renderer level.
- Password field descriptions now render as masked hints and hover tooltips.
- The generic privileged command path is confirmation-gated and filtered against a whitelist of approved wrapper scripts.
- The default wrapper whitelist is intentionally narrow and does not include a broad shell-like command path.
- `uname -a` needed its own dedicated path; without that, the model answered from memory instead of executing a real command.
- `system_uname` is a dedicated read-only tool and now executes `uname -a` directly, so the model has an explicit, safe path for exact output.
- The llama RPM build still needs validation after the CPU-variant symlink packaging fix.

## Completed Work
1. ✅ Model Discovery JavaScript Event Listeners (Select2 support added)
2. ✅ Controller `get_models()` endpoint created
3. ✅ Provider normalization implemented (`"Local Provider"` → `"local-provider"`)
4. ✅ GET parameters instead of POST (to avoid CSRF filter issues)
13. ✅ Ollama.com API integration (fetches real models, no hardcoded lists)
14. ✅ Local model scanning via `scanLocalModels('/home/ai/models')`
15. ✅ Anthropic models list (predefined)
16. ✅ OpenAI/OpenRouter integration
17. ✅ **Ollama Cloud**: Provider `ollama` means Ollama Cloud via `https://api.ollama.com/v1/models`; self-hosted Ollama belongs under `custom` with a local endpoint
18. ✅ `tools_enabled` runtime toggle
19. ✅ Advanced generic privileged command option with wrapper whitelist
20. ✅ llama.cpp CPU + Vulkan build path with EL10 RPATH cleanup in RPM install
21. ✅ CPU variant library packaging fix in progress for `libggml-cpu.so*`

## Resolved Notes

### Note: Old browser error investigation is obsolete
The earlier `Error fetching data: error <empty string>` issue was part of the discovery debugging phase. The current implementation now works in the browser and no longer depends on the old shared `api_key` path.

### Note: Generic privileged command
The generic privileged command tool is intentionally opt-in, confirmation-gated, and restricted to approved wrapper scripts. It is not a free-form shell escape hatch.

### Note: uname output
The request for `uname -a` should use the dedicated `system_uname` tool. Earlier attempts via the generic wrapper path could still be ignored by the model or require context that was not always available.

### Note: dedicated uname tool
The runtime now exposes `system_uname` as a dedicated read-only tool for exact `uname -a` output, and it executes the command directly instead of relying on a generic privileged wrapper path.

### Note: tool groups
The tool layer is moving toward Hermes/OpenClaw-style groups:
- `read_only` for logs, files, search, hashes
- `diagnostics` for uname, system info, service status, journal queries
- `actions` for confirmation-gated writes and service operations
- `advanced` for the generic wrapper escape hatch only
These groups are controlled by config booleans and filtered before tool definitions reach the model.

### Note: AI service auth key
The AI HTTP endpoints are protected by a server-side service auth key stored in `System.AI.service_api_key` in CODB. The constructor seeds it when missing, the GUI backend reads it from CCE and adds it to requests to `127.0.0.1:1972`, and the Python service rejects `/chat` and `/function` unless the `X-BlueOnyx-AI-Auth` header matches. The JSON config file is only the runtime mirror for the Python service. This is the guard against local LCE on the loopback listener.

### Note: AI audit log
The AI service audit stream now goes to a dedicated `/var/log/blueonyx-ai.log` file instead of the journal-backed console path. `audit_log.py` emits structured JSON syslog events on `local6` at `info`/`warning` severity, and the package ships an rsyslog rule that routes only the `blueonyx-ai` tag into the dedicated file. The package also ships a logrotate policy that rotates that file daily, keeps 7 compressed archives, and reloads rsyslog after install so the rule takes effect immediately.

### Note: Vsite web owners
Bad Vsite `/web` ownership is usually a site-specific GUI fix: update the affected Vsite's PHP preferred site admin to a real site admin and let BlueOnyx regenerate the site. `set_web_owner.pl` is the bulk repair script when ownership drift is systemic; it walks all Vsites and updates `PHP.prefered_siteAdmin` to a real site admin when the current owner is empty, `nobody`, or `apache`.

### Note: network diagnostics
The AI service now has dedicated read-only network tools for interface state, live counters, routes, socket summaries, DNS resolver state, and historical bandwidth usage. The agent also short-circuits generic "network usage" questions to the comprehensive network summary tool so the model does not have to infer how to gather traffic data.

### Note: runtime session ownership
The base-ai-core RPM now owns and repairs the AI runtime directories with both package file ownership and `systemd-tmpfiles`. That is required because the chat confirmation state is persisted on disk, and the live host had `/home/ai/{sessions,logs,wrappers}` and `/var/lib/sausalito/ai/sessions` stuck at `nobody:nobody`. The package should now restore those paths to `blueonyx_ai:blueonyx_ai` on install and boot.

### Note: service-side ownership repair
`sausalito-ai.service` now runs `systemd-tmpfiles --create /usr/lib/tmpfiles.d/base-ai.conf` and a targeted root-run `chown` of only the runtime directories (`/home/ai`, `/home/ai/sessions`, `/home/ai/logs`, `/home/ai/wrappers`, `/var/lib/sausalito/ai`, `/var/lib/sausalito/ai/sessions`) before every start. The RPM also reloads systemd's unit cache in `%post`, and the chat UI now keeps a stable AI session id in `localStorage` while the backend can reconstruct a pending confirmation from stored message history if the explicit flag is missing. The agent's tool-call save path now reloads the current session before persisting messages so it does not overwrite `pending_confirmation` after a write tool asks for approval. The history fallback must stop as soon as a later assistant response exists, otherwise it will resurrect a completed write operation and trap the agent in an old confirmation loop. Service restart/reload confirmations now return a fixed short success message instead of echoing wrapper/systemd output. A fresh explicit service request can also clear an old pending confirmation when the user clearly pivots to a different service, so stale confirmations do not block unrelated actions. That double-whammy layer on top of the RPM `%post`/tmpfiles ownership repair applies immediately and does not recurse into `/home/ai/venv`.

### Note: llama services
- `sausalito-llama.service` is the only active packaged llama service now.
- The old `base-ai-llama.service` was a stale leftover and has been removed from the live system and source tree.
- The local provider now points to `http://127.0.0.1:8081`, which is the dedicated `sausalito-llama.service` port.
- The AI service now starts `sausalito-llama.service` on demand for local-provider chat requests and stops it again when the AI service idles out.
- `ExternalProvider` now honors `default_model` as the runtime model field and defaults OpenRouter to `https://openrouter.ai/api/v1` so provider selection does not fall back to the OpenAI endpoint with the wrong key.
- The AI HTTP endpoints are protected by a server-side service auth key stored in `System.AI.service_api_key` in CODB. The constructor seeds it when missing, the GUI backend reads it from CCE and adds it to requests to `127.0.0.1:1972`, and the Python service rejects `/chat` and `/function` unless the `X-BlueOnyx-AI-Auth` header matches. The JSON config file is only the runtime mirror for the Python service. This is the guard against local LCE on the loopback listener.
- A running `llama.cpp` on `127.0.0.1:8081` is still reachable by local processes while it is active. BlueOnyx only keeps that window short by starting it on demand and stopping it on idle; if a host has untrusted local users or scripts, that residual local access has to be accepted and documented rather than papered over by invasive upstream changes.

### Note: BlueOnyx knowledge base
- BlueOnyx truth data now lives in a separate `base-ai-knowledge` RPM.
- It installs the canonical registry and glossary under `/home/ai/knowledgebase/`.
- `base-ai-core` loads a compact local knowledge brief from that directory and exposes a `search_blueonyx_knowledge` read-only tool for anchored support answers.
- The goal is to keep BlueOnyx-specific answers tied to local canonical facts instead of generic model memory.

### Note: BlueOnyx health diagnostics
- The AI now has deterministic incident, SSL, and PHP-FPM diagnostics in addition to the generic log tools.
- `incident_timeline` correlates journal and common admin logs into one chronological report so the assistant can answer "what changed right before it broke?"
- `ssl_health` checks AdmServ and Vsite certificates for expiry, missing files, and obvious chain/signature problems.
- `php_fpm_health` checks the master PHP-FPM service plus per-version pools only when a Vsite actually uses that PHP version in FPM mode.
- `site_health_evidence` combines web ownership, SSL, PHP-FPM, quota/disk usage, and centralized log evidence for a single Vsite.
- The assistant should treat extra PHP-FPM services that are not needed as not necessarily broken.
- BlueOnyx helper scripts worth knowing about include `vsite_list.pl`, `ssl_get.pl`, `reload_webservers.pl`, `SSL_fixer.pl`, `toggle_ssl.pl`, `phptoggle.pl`, and the new AI health helpers. Read-only helpers are reasonable suggestions; write-side helpers should stay confirmation-gated.

### Note: model capability profiles
- Model selection now gets a capability profile: `restricted`, `guided`, `investigative`, or `freeform`.
- The classifier uses provider/model-name heuristics first and persists the result in a runtime cache so new models do not need manual hard-coding.
- The seed data lives in `base-ai-knowledge` as `/home/ai/knowledgebase/model_caps.json`; the writable runtime cache is `/home/ai/model_caps.runtime.json`.
- `restricted` models get tighter guidance and lower tool-loop limits, while stronger models get broader investigative freedom and more tool rounds.
- Unknown models start conservatively and are only promoted when the classifier is confident.

### Note: webroot integrity checks
- Questions asking whether a Vsite webroot has been hacked now short-circuit to a deterministic integrity scan instead of relying on the model to explore the tree forever.
- The scan checks the target `/home/sites/.../wwwroot/web/` path, lists the top-level entries, and searches for obvious webshell / obfuscation indicators such as `eval(`, `base64_decode(`, `gzinflate(`, `shell_exec(`, `passthru(`, `proc_open(`, `assert(`, `preg_replace(.../e)`, `system(`, and `chmod(`.
- The filename heuristic is intentionally conservative: it only treats names with stronger dropper/shell markers as suspicious, so ordinary PHP files are not flagged just because they exist.
- If the user asks for a forensic or detailed inspection, the scan can also show weak filename signals such as dotfiles, backup-style names, and other low-confidence dropped-file indicators separately from the strong content hits.
- For restricted and guided models, forensic follow-ups without an explicit path reuse the last webroot path from the current session so the model does not start inventing file types or other assumptions.
- Webroot scan output now separates strong content hits, strong filename hits, weak filename signals, and a short overall assessment so the result stays readable even for small models.
- Explicit paths outside `/home/sites/.../wwwroot/web/` are rejected instead of silently reusing the previous webroot path.
- The read-only file tools now use a model-profile-aware diagnostic scope: all profiles keep the webroot/log bases, stronger profiles may read `/usr/sausalito/` except for `/usr/sausalito/capcache/`, `/usr/sausalito/codb/`, `/usr/sausalito/license/`, and `/usr/sausalito/sessions/`, and `/home/ai/` remains blocked.
- The result is intentionally cautious: no obvious indicators is not proof that the site is clean, but it does prevent the agent from looping without a conclusion.

### Note: forensic tools
The read-only toolkit now includes dedicated file inspection tools for webroots and general integrity checks:
- `list_directory`
- `stat_path`
- `search_files`
- `hash_file`
They are restricted to approved roots and are intended to cover log/webroot analysis without shell access.

### Note: generic log search
There is now a broader `search_admin_logs` tool for common Linux admin logs. It searches typical `/var/log/` files such as `messages`, `secure`, `maillog`, `cron`, `httpd`, `dovecot`, `sshd`, and audit logs, which is a better default than only targeting `maillog`.

### Note: deterministic admin-log shortcut
Requests about failed logins, authentication failures, SSH/mail incidents, or similar admin log investigations are now short-circuited in the agent to `search_admin_logs` instead of waiting for the model to pick a tool. Grep exit code 1 is treated as a valid "no matches found" result so empty log investigations do not fall back into hallucinated summaries.

### Note: admin-log wrappers
The admin log search path now uses a dedicated `ai-search-logs` wrapper for both plain and rotated `*.gz` logs, and `journalctl_query` uses a dedicated `ai-journalctl` wrapper. The sudoers file now references the real installed wrapper path `/home/ai/wrappers` instead of the stale `/usr/sausalito/ai/wrappers` location.

### Note: maillog narrowing
When the user explicitly asks for `/var/log/maillog`, the deterministic shortcut now searches `maillog*` only instead of mixing in `secure*`. That avoids polluting the result with the assistant's own sudo noise from unrelated auth failures.

### Note: secure narrowing
When the user explicitly asks for `/var/log/secure` or SSH failed logins, the deterministic shortcut now uses a secure-only pattern focused on `sshd` authentication failures (`Failed password`, `Invalid user`, PAM auth failure) instead of the broad generic `failed` pattern. That prevents sudo auth chatter from being reported as a login failure.

### Note: secure self-noise filtering
The `ai-search-logs` wrapper now strips the AI's own sudo/session chatter and wrapper command lines before returning results. This keeps `/var/log/secure` investigations from being polluted by the assistant's own tool invocations.

### Note: failed logins vs auth attempts
The agent now distinguishes confirmed failed logins from broader auth/login attempts. For failed-login requests it searches for `Failed password`, `Invalid user`, and PAM auth failures first; if none are found, it may add a second section for auth attempts such as preauth disconnects or `authenticating user` lines. That keeps security analysis precise without hiding useful evidence.

### Note: login-attempt intent
The agent now treats `login attempts` as its own intent. `failed login` requests should only report failed authentications, while `login attempts` and `auth activity` should show preauth disconnects and similar evidence. The two cases are no longer conflated.

### Note: log response formatting
Admin-log answers are now formatted as short findings with a very small bullet list instead of raw grep dumps. The agent strips file/line prefixes, drops sudo noise, and separates confirmed failed logins from broader login-attempt evidence so the output stays focused on the actual event type. For `secure`/`maillog` lookups it now prefers the current file paths first so the reply stays short and does not time out on the full rotation set. `dovecot` is treated as part of `maillog`, and broad log sweeps can search the full default admin-log set instead of a single file family. Broad sweeps now default to a short admin summary with a top signal plus labeled signal bullets and one representative example per signal, and the chat UI now preserves the multiline layout via `white-space: pre-wrap` unless the prompt explicitly asks for evidence/details/forensics. Mail-heavy summaries are now split into more specific subcategories such as `Postfix`, `Spam filter`, and `Dovecot` instead of a single generic mail bucket.

### Note: mail statistics
A dedicated mail statistics path now exists for questions about inbound/outbound mail volume, spam hits, rejects, deferrals, bounces, and top senders/recipients. It now separates envelope senders from noisy SASL-auth artifacts, shows top recipients and remote clients, supports an optional recent-days filter, and can emit a `mail_health` summary with daily/weekly trends. Spam counts are tied to real spam-classification events instead of spamd warning noise. It is still log-based and approximate, but it is intended to be useful for admin reporting instead of only returning raw log excerpts.

### Note: disk space shortcut and context trimming
Requests about free disk space now short-circuit to a direct full `df -h` read-only system tool instead of going through the LLM, which avoids context blowups on long sessions. The agent also trims the message history before each provider call so old long log outputs do not overflow the model context again.

### Note: disk health summary
There is now a separate concise disk-health shortcut for questions about the healthiest or fullest filesystems. It also catches "disk space summary", "filesystem summary", "mount summary", and "disk usage summary" style requests. It summarizes the fullest mounts, flags 80%+/90%+ usage, and still uses the live `df -h` output so BlueOnyx systems with variable mount layouts are handled correctly.

### Note: user/vsite disk usage
For questions like "How much disk space does user admin use?" or "How much disk space does Vsite b1.smd.net use?" the live answer must come from `get_quotas.pl`, not from the cached CCE `Disk.used` field. The `Disk.used` value in CCE can lag or be stale for both `User` and `Vsite` objects. The reliable path is:
- user usage: `get_quotas.pl --users`
- vsite usage: resolve the vsite name from the hostname/FQDN, then use `get_quotas.pl --sites`
The vsite lookup currently resolves `b1.smd.net` via the `hostname` field to the site group name `site1` before matching the live quota line.
These account-disk requests are short-circuited in the agent so the model does not have to infer or paraphrase quota values.
The vsite quota lookup now prefers `/usr/sausalito/sbin/vsite_list.pl` with exact FQDN matching to resolve the internal site group name, then uses `get_quotas.pl --sites` on that group. This avoids ambiguous hostname-only lookups when multiple vsites share a hostname prefix such as `www` or `mail`.

Vsite creation questions should be answered from the BlueOnyx GUI and permission model: create Vsites under `Site Management` with the `+ Add` button. If the button is missing or creation is denied, check `manageSite`, `maxVsite`, and related allocation limits. Do not mention a fictional `/etc/system` toggle.

The AI chat UI now renders fenced code blocks as real `<pre><code>` blocks and shows a copy-to-clipboard button on user and assistant messages.
The AI chat UI also has a small fullscreen toggle button that overlays the page and a matching exit toggle to return to normal size.

For generic GUI failures or "why did this not work" questions, it is useful to search `/var/log/messages*` for failed CCEd CREATE/SET/FIND/DESTROY transactions and inspect the 10-20 lines before the failure for the handler or constructor error. If the GUI shows a 500 error, recommend setting `/usr/sausalito/ui/chorizo/ci4/.env` to `CI_ENVIRONMENT = development` temporarily so the browser shows the detailed error.

If the AI cannot determine a BlueOnyx GUI/config cause, it may append a one-time-per-session hint to use `Software Updates > Support > Support Request` for a support ticket. Do not spam this on every uncertain answer; only use it for clear BlueOnyx support-context failures when the answer is still uncertain.

Policy hierarchy for BlueOnyx support questions: local truth first (`truth_registry.json`, `blueonyx_knowledge.md`), then aggressive deterministic tools, then trusted external sources (`https://www.blueonyx.it/`, `https://wiki.blueonyx.it/userguide/start`, `https://mail.blueonyx.it/pipermail/blueonyx/`), then Michael Stauber emails as the highest-trust human source unless newer official docs or code contradict them, and finally the support channels fallback (`https://www.blueonyx.it/`, `https://wiki.blueonyx.it/userguide/start`, `https://mail.blueonyx.it/pipermail/blueonyx/`, `https://discord.gg/YJ2MHDvyrB`, `Software Updates > Support > Support Request`). Do not invent a chat support function or other GUI support widget unless it is explicitly present in the UI. Migration support should mention Easy-Migrate as the current CLI tool, CMU as deprecated, and Easy-Backup as an alternative when appropriate.

## Update 2026-08-01: 5210R llama.cpp hardware compatibility

### Incident and confirmed root cause
- The newly ported 5210R packages were installed on an AlmaLinux 8 BlueOnyx server and the GUI was configured for the local SmolLM2 provider.
- `sausalito-llama.service` then entered a five-second restart loop and flooded `/var/log/messages`.
- The repeating llama.cpp error was `make_cpu_buft_list: no CPU backend found`.
- The host has Intel Xeon E5520 (Nehalem) processors. They support SSE4.2 but do not support AVX, AVX2, FMA, F16C, or BMI2.
- The model and shared libraries were present and readable. `ldd` found no missing dependency.
- The installed `/home/ai/bin/libggml-cpu.so` loaded, but its exported `ggml_backend_score()` returned `0`, meaning that the module did not support the live CPU.
- Disassembly confirmed that the packaged CPU module required AVX, AVX2, FMA, F16C, BMI2, and SSE4.2. It was effectively a Haswell-class backend.
- The problem was caused by combining `GGML_BACKEND_DL=ON` with `GGML_CPU_ALL_VARIANTS=OFF`. With b9873 and the existing CMake configuration, the single non-native x86 backend still had the individual AVX/AVX2/FMA/F16C/BMI2 options enabled.
- This was not a damaged SmolLM2 model, GUI configuration error, permission failure, or missing-library failure.
- The log volume was a secondary service-policy defect: `Restart=on-failure`, `RestartSec=5`, and RPM boot enablement caused an unsupported backend to restart indefinitely.

### Approved product direction
The approved solution is the full portable multi-backend build (previously discussed as "Option B") using GCC Toolset 14 on the 5210R build host.

The intended llama.cpp CMake configuration is:
- `GGML_NATIVE=OFF`
- `GGML_BACKEND_DL=ON`
- `GGML_CPU_ALL_VARIANTS=ON`
- `GGML_VULKAN=ON`

The package must ship all upstream x86-64 CPU variants so llama.cpp can score them at runtime and select the best module supported by the client CPU. This includes the baseline `x64` module, `sse42`, AVX generations, AVX2/Haswell, AVX-512 generations, BF16, AVX-VNNI, and AMX variants. An old x86-64 CPU must be able to fall back to `x64`; this Xeon E5520 should select `sse42`. New hardware should automatically receive its best compatible optimized backend.

Vulkan remains optional acceleration. Absence of a usable Vulkan device must never make the local provider unavailable when a CPU backend works. A broken or unusable Vulkan path should fall back safely to CPU inference.

### Settled EL8/EL9/EL10 source and compiler policy
- `base-ai-llama` will use one common implementation and consistent llama.cpp settings across 5210R/EL8, 5211R/EL9, and 5212R/EL10.
- The same llama.cpp release tarball, common patches, Makefile logic, RPM packaging, capability checks, systemd behavior, and validation tests should be used on all three platforms.
- Each OS still builds its own native binary RPM (`.el8`, `.el9`, or `.el10`). The goal is one common source/SRPM implementation, not one binary RPM shared across different glibc/platform releases.
- GCC 14 is the required compiler across all three platforms:
  - EL8 uses GCC Toolset 14 from AppStream.
  - EL9 uses GCC Toolset 14 from AppStream.
  - EL10 uses its standard system GCC 14 compiler.
- The spec and Makefile should select the compiler based on the build platform. EL8/EL9 require the `gcc-toolset-14-*` build packages and compiler paths under `/opt/rh/gcc-toolset-14/`; EL10 requires the normal `gcc`, `gcc-c++`, and `binutils` build packages and `/usr/bin/gcc`/`g++`.
- Compiler validation should require GCC major version 14 or newer and fail instead of silently falling back to an older compiler.
- All three platforms use the same core configuration: `GGML_NATIVE=OFF`, `GGML_BACKEND_DL=ON`, `GGML_CPU_ALL_VARIANTS=ON`, and `GGML_VULKAN=ON`.
- Vulkan build behavior should be consistent. Because the development packages and RPM manifest require Vulkan, a missing Vulkan build should fail clearly rather than silently create a feature-reduced RPM. Runtime use of Vulkan remains optional and must fall back to CPU.
- Optional OpenCL and OpenBLAS detection may remain feature probes if desired, but the mandatory CPU/Vulkan configuration must not drift between releases.
- The GCC 8-only filesystem and CTAD patches are obsolete once EL8 uses GCC Toolset 14 and should not be part of the final common patch set. Keep only patches genuinely required by all supported builds, including the current Vulkan shader-generator compatibility patch while it remains necessary.
- Preserve and package all runtime-selected CPU backend modules on every platform. Never select a single optimized backend at package-build time.
- EL8 and EL9 must include the true baseline x86-64 backend plus all optimized variants, allowing old CPUs to run the best compatible backend.
- EL10 has an operating-system/compiler platform floor of x86-64-v3. Older processors that cannot satisfy x86-64-v3 cannot run EL10 and are therefore outside the 5212R support boundary. This limitation is accepted as an OS constraint, not treated as a `base-ai-llama` defect.
- The 5210R implementation will be completed and confirmed first. After validation, Michael will manually copy the finalized `base-ai-llama` implementation into the 5211R and 5212R SVN trees and keep those copies synchronized.
- The selectable/commented `DIRS` lines in each module's `src/Makefile` are intentional shortcuts for avoiding expensive full llama/model rebuilds and must remain under manual control.

### GCC Toolset 14 packaging notes
- GCC Toolset 14 is provided for AlmaLinux 8.10 through AppStream.
- Expected build requirements include `gcc-toolset-14-gcc`, `gcc-toolset-14-gcc-c++`, `gcc-toolset-14-binutils`, `gcc-toolset-14-runtime`, and `gcc-toolset-14-libstdc++-devel`.
- There is no package named `gcc-toolset-14-libstdc++`; the actual development package is `gcc-toolset-14-libstdc++-devel`.
- The toolset is to be activated only for `%build` and `%install`, preferably with explicit `PATH`, `CC`, and `CXX` values under `/opt/rh/gcc-toolset-14/`.
- The build must fail if it silently falls back to the system GCC 8 compiler.
- Do not assume that client systems need GCC Toolset runtime packages. Inspect the finished binary and RPM with `ldd`, `readelf --version-info`, and `rpm -qpR`. Add a runtime dependency only if the finished artifacts prove that one is necessary.
- Build-directory RPATHs must be removed, but RPATH cleanup must not strip a path that the finished package genuinely requires. The existing `/home/ai/bin` `LD_LIBRARY_PATH` may remain initially; an `$ORIGIN` RUNPATH can be evaluated later.

### Required build and RPM changes
- Change `src/base-ai-llama/Makefile` to enable all CPU variants and explicitly disable native build-host optimization.
- Preserve every generated `libggml-cpu-<variant>.so` filename exactly.
- Remove the current packaging logic that selects an arbitrary optimized CPU variant and makes `libggml-cpu.so` point to it as the effective backend.
- Keep the variant modules beside `llama-server`, where dynamic backend discovery searches for them.
- Ensure the RPM manifest includes all generated CPU variants and relevant Vulkan/shared libraries.
- Fail the RPM build when mandatory portable modules such as `libggml-cpu-x64.so` and `libggml-cpu-sse42.so` are absent.
- Add a staged-package test using `llama-server --list-devices` with the staged library directory and require it to report a CPU device.
- Validate `llama-server` and at least the baseline CPU module with `ldd`; no dependency may be unresolved.
- Inspect generated RPM requirements so GCC Toolset 14 remains a build-time dependency unless the resulting ELF files prove otherwise.

### Authoritative local-inference capability check
Add one read-only capability helper, owned by the llama package, such as `/home/ai/bin/blueonyx-llama-check`. It is the source of truth for the GUI, CCE handler, systemd preflight, AI service, and support diagnostics. Do not duplicate fragile CPUID rules in PHP or Perl.

The helper should return stable JSON and meaningful exit status after checking:
- `llama-server` exists and is executable.
- Required shared libraries resolve.
- `llama-server --list-devices` discovers a usable CPU device.
- The configured GGUF exists, resolves inside `/home/ai/models`, and is readable by `blueonyx_ai`.
- Available memory is sufficient or marginal.
- Vulkan devices are reported separately and remain optional.

Capability states should be:
- `supported`: local inference should work normally.
- `supported_with_warning`: inference is usable, but an old CPU, marginal memory, or missing accelerator may make it slow.
- `unavailable`: no loadable CPU backend, missing/broken model, unresolved libraries, unsupported architecture, clearly insufficient memory, or runtime self-test failure.

Lack of AVX is not an unavailability condition when `x64` or `sse42` works. Older CPUs should normally receive a performance warning rather than being rejected.

### GUI and CCE behavior
- `AiSettings.php` should query the authoritative capability helper when rendering and validating settings.
- When local inference is available, show the detected CPU backend and any performance warning.
- When unavailable, mark the Local provider unavailable, display the exact reason, and keep all external providers selectable.
- Reject a form submission that tries to save `enabled=1` with `provider=local` when the capability check fails.
- JavaScript may improve presentation, but enforcement must be server-side.
- If an existing installation is already configured as enabled/local and later becomes unavailable, show a prominent warning without silently switching providers.
- Add localized status, warning, and error messages to all `ai.po` files.
- The CCE settings handler must repeat the validation so direct CCE writes, constructors, upgrades, and API-originated changes cannot bypass the GUI.

### Service lifecycle and runtime readiness
- `sausalito-llama.service` is an on-demand backend and must not be enabled at boot by the RPM.
- Upgrades should disable stale boot-enabled instances.
- Add the capability helper as an `ExecStartPre` check.
- Remove the unbounded restart loop. Prefer `Restart=no` because `sausalito-ai` explicitly starts llama when needed; any later automatic recovery must use strict systemd rate limits.
- Changing AI settings should update the model symlink, validate it, and stop any stale llama instance. It should not immediately load the model. The next local chat request starts the backend on demand.
- `ensure_llama_running()` must do more than invoke `systemctl start`: run preflight, start the unit, poll llama's HTTP health endpoint for a bounded time, confirm that the model is ready, and stop the failed unit on error.
- Local startup failures must be returned as concise, actionable errors rather than generic LiteLLM connection failures.
- Extend the AI `/health` response with local availability, running state, selected CPU backend, optional GPU devices, and warnings.

### Compatibility test matrix
Test the shippable package across representative CPU/device profiles:
- Baseline x86-64/SSE2
- Nehalem/Westmere (including the Xeon E5520 incident host)
- Sandy Bridge
- Haswell
- AMD Zen generations
- AVX-512 server CPUs
- Alder Lake
- Sapphire Rapids
- No Vulkan device
- Working Vulkan device
- Vulkan loader present without a usable device

Every profile must pass backend discovery without an illegal-instruction failure. Representative old, middle, and new profiles should also load SmolLM2 and complete a minimal inference request.

### Approved implementation order
1. Switch the llama RPM build to GCC Toolset 14.
2. Enable, preserve, and package all CPU variants.
3. Add staged backend, ELF dependency, and RPM requirement validation.
4. Test the rebuilt RPM on the Xeon E5520 and at least one AVX2-era system.
5. Add the reusable local-capability helper.
6. Correct the on-demand systemd lifecycle and eliminate the restart storm.
7. Add bounded llama readiness checking and actionable runtime errors.
8. Add GUI and CCE enforcement plus localized warnings.
9. Establish the ongoing hardware compatibility test matrix.

### Implementation completed and validated on 2026-08-01
- The common EL8/EL9/EL10 build logic is implemented in the 5210R source tree. EL8/EL9 select GCC Toolset 14 explicitly; EL10 selects system GCC and requires version 14 or newer.
- The module root now provides explicit `check-build-deps` and `bootstrap-build-deps` targets. Normal module builds never install packages automatically.
- On EL8 the verified Toolset dependency set is `gcc-toolset-14-gcc`, `gcc-toolset-14-gcc-c++`, `gcc-toolset-14-binutils`, `gcc-toolset-14-runtime`, and `gcc-toolset-14-libstdc++-devel`. AlmaLinux AppStream has no separate `gcc-toolset-14-libstdc++-static` package; the static archive is supplied by the development package.
- GCC Toolset 14.2.1 was installed on the 5210R validation server and used for the build.
- All fourteen b9873 x86 CPU modules are packaged: `x64`, `sse42`, `sandybridge`, `ivybridge`, `piledriver`, `haswell`, `skylakex`, `cannonlake`, `cascadelake`, `icelake`, `cooperlake`, `zen4`, `alderlake`, and `sapphirerapids`.
- C++ and GCC runtimes are statically linked into the CMake executable, shared-library, and module targets. The finished RPM has no `libstdc++.so`, `libgcc_s.so`, `GLIBCXX`, CXXABI, or GCC Toolset runtime requirement. The `%check` stage rejects a backend module that regresses to a client compiler-runtime dependency.
- Staged validation requires all fourteen CPU modules, checks shared-library resolution, and uses verbose backend discovery to prove that a CPU module actually loads.
- `/home/ai/bin/blueonyx-llama-check` is packaged as the authoritative JSON/exit-status preflight for the model, shared libraries, runtime backend, memory, selected CPU backend, and optional accelerator devices.
- The GUI displays local capability and warnings, marks an unavailable local provider, and rejects attempts to enable it. The CCE handler independently repeats the same enforcement for non-GUI writes.
- `sausalito-llama.service` is disabled after install, starts only on demand, has no restart loop, and runs the capability helper in `ExecStartPre`. Its duplicate shutdown signal was removed; systemd now sends one SIGINT and llama exits cleanly.
- The AI service performs preflight, starts llama, waits up to 60 seconds for `/health`, stops a failed startup, reports the actionable error to chat, and exposes capability, backend, accelerator, warning, and running-state data through its health response.
- Final artifact: `base-ai-llama-1.0.3-b9873.3.el8.x86_64.rpm` and matching source RPM.
- The normal module RPM build also completed successfully with the intentional `src/Makefile` shortcut intact, producing `base-ai-core-1.0.8-14`, release-2 GUI/glue/capstone and all nine locale RPMs under `as_rpms/` (plus the unchanged knowledge package).
- Live E5520 validation passed: the runtime selected `libggml-cpu-sse42.so`, the helper returned `supported_with_warning`, SmolLM2 reached `{"status":"ok"}`, a minimal chat completion returned exactly `OK`, and shutdown ended with systemd `Result=success`, status 0, inactive/dead.
- The remaining product test obligation is the wider hardware matrix (baseline x64, AVX generations, AMD Zen, AVX-512, hybrid Intel, Sapphire Rapids, and real/no/broken Vulkan configurations) plus corresponding EL9 and EL10 native builds after the source is copied to those trees.

### Note: admin-log regex mode
The `ai-search-logs` wrapper now uses `grep -E` / `zgrep -E` so alternation patterns like `A|B|C` actually match. It also suppresses filenames and line numbers, which keeps the returned evidence clean.

## Files Modified
- `/usr/sausalito/ui/chorizo/ci4/Modules/Base/Ai/Controllers/AiSettings.php`
  - `get_models()` method: GET parameters, provider normalization
  - `case 'ollama'`: Real API fetch instead of hardcoded list
  - `case 'localprovider'`, `case 'local-provider'`: Additional provider variants
  - `case 'anthropic'`: Predefined models
  - provider-specific password fields and masked hints
- `/usr/sausalito/schemas/base/ai/ai.schema`
  - provider-specific API key properties added
- `/home/devel/BlueOnyx/BlueOnyx/5212R/ui/base-ai.mod/glue/schemas/ai.schema`
  - legacy shared `api_key` removed from module schema
- `/home/devel/BlueOnyx/BlueOnyx/5212R/ui/base-ai.mod/src/base-ai-llama/base-ai-llama.spec`
  - `chrpath` added to BuildRequires
- `/home/devel/BlueOnyx/BlueOnyx/5212R/ui/base-ai.mod/src/base-ai-llama/Makefile`
  - install step strips build RPATHs from installed ELF files
  - CPU variant symlink normalization added for `libggml-cpu.so`
- `/home/devel/BlueOnyx/BlueOnyx/5212R/ui/base-ai.mod/src/base-ai-core/sausalito_ai/tools/log_tools.py`
  - generic admin log search plus no-match handling
- `/home/devel/BlueOnyx/BlueOnyx/5212R/ui/base-ai.mod/src/base-ai-core/sausalito_ai/tools/diagnostic_tools.py`
  - incident timeline builder from journal + common admin logs
  - SSL health summary for AdmServ and Vsites
  - PHP-FPM health summary that only flags pools when they should be running
  - `web_owner_health` report for `/web` ownership across all Vsites
  - `site_health_evidence` report for single-Vsite health evidence
  - points to `set_web_owner.pl` and `reload_webservers.pl` when ownership is invalid
- `/home/devel/BlueOnyx/BlueOnyx/5212R/ui/base-ai.mod/src/base-ai-core/sausalito_ai/ssl_health.py`
  - root-run helper that inspects certs, expiry, and chain/signature issues
- `/home/devel/BlueOnyx/BlueOnyx/5212R/ui/base-ai.mod/src/base-ai-core/sausalito_ai/php_fpm_health.py`
  - root-run helper that checks master and per-version PHP-FPM pool state
- `/home/devel/BlueOnyx/BlueOnyx/5212R/ui/base-ai.mod/src/base-ai-core/sausalito_ai/agent/agent.py`
  - prompt guidance for exact command output
  - deterministic admin-log shortcut for auth/mail/SSH investigations
- `/home/devel/BlueOnyx/BlueOnyx/5212R/ui/base-ai.mod/src/base-ai-core/sausalito_ai/audit_log.py`
  - structured JSON syslog audit logging for chat/function events
  - normal events at `info` so they reach the AI audit log cleanly
  - no journal fallback for audit payloads
- `/home/devel/BlueOnyx/BlueOnyx/5212R/ui/base-ai.mod/src/base-ai-core/sausalito_ai/ai_service.py`
  - registers the network diagnostics tools per request
  - logs user-query, tool-execution, confirmation, response, and error audit events
- `/home/devel/BlueOnyx/BlueOnyx/5212R/ui/base-ai.mod/src/base-ai-core/sausalito_ai/tools/system_tools.py`
  - comprehensive network diagnostics tools for interfaces, counters, routes, sockets, DNS, and bandwidth
  - deterministic read-only command helpers with direct subprocess fallbacks
- `/home/devel/BlueOnyx/BlueOnyx/5212R/ui/base-ai.mod/src/base-ai-core/sausalito_ai/tmpfiles.d/base-ai.conf`
  - repairs ownership for AI runtime directories at install and boot
- `/home/devel/BlueOnyx/BlueOnyx/5212R/ui/base-ai.mod/src/base-ai-core/sausalito-ai.service`
  - repairs ownership on every service start before launching the AI daemon
- `/home/devel/BlueOnyx/BlueOnyx/5212R/ui/base-ai.mod/src/base-ai-core/sausalito_ai/rsyslog.d/30-blueonyx-ai.conf`
  - routes only `blueonyx-ai` `local6` events to `/var/log/blueonyx-ai.log`
- `/home/devel/BlueOnyx/BlueOnyx/5212R/ui/base-ai.mod/src/base-ai-core/sausalito_ai/logrotate.d/blueonyx-ai`
  - daily rotation, gzip compression, and 7 retained archives for the AI audit log
- `/home/devel/BlueOnyx/BlueOnyx/5212R/ui/base-ai.mod/src/base-ai-core/sausalito_ai/wrappers/ai-search-logs`
  - dedicated log-search wrapper with gzip support
- `/home/devel/BlueOnyx/BlueOnyx/5212R/ui/base-ai.mod/src/base-ai-core/sausalito_ai/wrappers/ai-journalctl`
  - dedicated journalctl wrapper for safe sudo use

## Next Steps

### Priority 1: Commit preparation
1. Review the next RPM build log to confirm the RPATH cleanup and CPU-variant packaging fix eliminated the EL10 failures.
2. Prepare SVN commit once the module is finalized.

### Priority 2: Runtime verification
1. Re-test the runtime provider mapping for OpenAI, OpenRouter, Ollama, Custom, and Local after packaging.
2. Confirm that each selected provider uses its own stored key and that Local continues to run without a key.
3. Verify that the generic privileged command tool only appears when the advanced checkbox is enabled and only executes whitelisted wrappers.
4. Verify that `system_uname` returns the exact `uname -a` output without requiring the model to guess a wrapper command.
5. Verify that the read-only and diagnostics groups can be toggled independently from action and advanced tools in the AI settings UI.
6. Verify that the local provider still targets `8081` after the llama service cleanup.
7. Verify that the new forensic tools work against `/home/sites/.../wwwroot/web/` and do not allow traversal outside approved roots.
8. Verify that `search_admin_logs` catches the usual auth/mail/service noise without requiring the user to specify the exact log file.
9. Verify the new network diagnostics against a real host: interfaces, counters, routes, sockets, DNS, and bandwidth history.
10. Verify that the runtime directories are owned by `blueonyx_ai` after RPM install and after reboot.
11. Verify `sausalito-ai.service` restores ownership at start if the live host drifts again.

## Technical Details

### Provider Normalization Flow
```
"Local Provider" → strtolower() → "local provider" → replace(' ', '-') → "local-provider"
"Ollama" → strtolower() → "ollama" → no change
```

### Provider Cases (switch statement)
- `case 'openai'` → OpenAI API
- `case 'openrouter'` → OpenRouter API  
- `case 'ollama'` → Ollama.com API (or self-hosted if custom_endpoint set)
- `case 'localprovider'` → Local models (NEW)
- `case 'local-provider'` → Local models (NEW)
- `case 'local'` → Local models (EXISTING)
- `case 'anthropic'` → Predefined Claude models

### API Endpoints
- Ollama Cloud: `https://api.ollama.com/v1/models`
- Self-hosted Ollama: use `Custom Provider` with the local endpoint and `/api/tags`
- `ollama` in the UI means Ollama Cloud only; local Ollama is handled via `custom` and is auto-detected on Ollama-style endpoints
- Ollama Cloud model discovery uses `https://ollama.com/api/tags`, not an OpenAI-compatible `/v1/models` endpoint
- Anthropic discovery uses live `/v1/models` when an API key is configured; without a key, the GUI shows no discovered models
- When a non-local provider is selected, the AI service now stops any lingering `sausalito-llama.service` at startup so old local-model sessions do not stay alive in the background
- Local: Scan `/home/ai/models/*.gguf`

### JavaScript Implementation
```javascript
// Event listener for Select2
$(providerSelect).on("select2:select", function(e) {
    fetchModels(e.params.data.id, modelField);
});

// Fetch models via GET
fetch("/ai/settings/get_models?provider=" + encodeURIComponent(provider), {
    method: "GET",
    headers: {"X-Requested-With": "XMLHttpRequest"}
})
```

## Known Constraints
- **CSRF Filter:** POST requests blocked, must use GET (or add to exceptions)
- **Select2:** Replaces native change events, must use `select2:select` event
- **Discovery transport param:** `api_key` is still used as a request parameter name in the GUI controller only; it is not a persisted shared setting
- **Generic privileged command:** must remain confirmation-gated and restricted to approved wrapper scripts
- **EL10 RPM checks:** installed ELF files must not retain build RPATHs

## Files to Review
- `/usr/sausalito/ui/chorizo/ci4/app/Config/Filters.php` - CSRF filter configuration
- `src/base-ai-llama/Makefile` and `base-ai-llama.spec` - verify RPATH cleanup stays in place
- `AiSettings.php` - UI labels and Model Discovery JavaScript in footer
- `src/base-ai-core/sausalito_ai/tools/system_tools.py` - dedicated `system_uname` tool and privileged wrapper handling
- `src/base-ai-core/sausalito_ai/tools/file_tools.py` - read-only filesystem and webroot inspection tools
- `src/base-ai-core/sausalito_ai/ai_service.py` - per-request registration of `system_uname`
- `src/base-ai-core/sausalito_ai/agent/agent.py` - prompt guidance for exact command output

## Update 2026-06-12: Profile-dependent Tool Policy and System Prompt Compression

### What changed

#### 1. PROFILE_TOOL_CATEGORIES — which tool categories each profile sees

Added to agent.py:

| Profile | Categories | Effective Tools |
|---|---|---|
| restricted | read_only + diagnostics (whitelist only) | 9 tools |
| guided | + actions | 15 tools |
| investigative | + advanced (minus run_privileged_command unless enabled) | 16 tools |
| freeform | all categories | 16 tools + run_privileged_command if enabled |

#### 2. PROFILE_TOOL_WHITELIST for restricted

Restricted models only see these tools:
- search_admin_logs, search_logs, mail_stats, mail_health
- system_uname, system_disk_space, service_status, journalctl_query
- search_blueonyx_knowledge

No free file browsing, no service_action, no run_privileged_command, no read_file.

#### 3. PROFILE_SYSTEM_PROMPTS — compressed prompt for restricted

- restricted: ultra-compact ~680 chars (~99 words) — 85% shorter than full prompt
  - Only the essential rules: use tools, don't guess, ask for confirmation on writes, use knowledge search for BlueOnyx specifics
  - No detailed tool descriptions, no Vsite creation instructions, no support channel hierarchy
- guided/investigative/freeform: full BASE_SYSTEM_PROMPT + knowledge brief

#### 4. Profile ceiling in Agent.__init__

The config-level allowed_tool_categories are intersected with the profile ceiling.
A guided model cannot get advanced tools even if the config permits them.

#### 5. Tool filtering in Agent.run()

After get_tool_definitions() filters by category, an additional whitelist filter
removes tools not in PROFILE_TOOL_WHITELIST when the profile is restricted.

### Design principle

"Dumb" models (restricted) still provide practical value through deterministic
shortcuts (disk space, log search, webroot integrity, quota, knowledge lookup)
and a minimal safe tool set. They never see tools they cannot safely use.
"Smart" models (investigative/freeform) get the full palette and can combine
tools autonomously for multi-step investigations.

### Absolute safety boundary (all profiles)

- run_privileged_command: always whitelist-filtered + confirmation-gated
- No destructive commands (rm -rf, shutdown, format filesystem, dd, etc.)
- File tools restricted to approved roots (webroot, logs, /usr/sausalito/)
- /home/ai/ is blocked from file tool access

### Files modified in this phase

- src/base-ai-core/sausalito_ai/agent/agent.py
  - Added PROFILE_TOOL_CATEGORIES dict
  - Added PROFILE_TOOL_WHITELIST dict
  - Added PROFILE_SYSTEM_PROMPTS dict with restricted ultra-compact prompt
  - Added _get_effective_tool_categories() method (profile ceiling intersect config)
  - Added _get_effective_tool_names() method (whitelist filter for restricted)
  - Modified _compose_system_prompt() to select prompt by profile
  - Modified run() to use profile-filtered tool definitions

### Update 2026-06-13: Capability Probe, Spam/Abuse Tool, Email Diagnostics, Webroot Scan All

#### 1. Capability Probe (capability_probe.py)

New file `src/base-ai-core/sausalito_ai/capability_probe.py` — automatic quick test for unknown models:

- 3 lightweight tests: instruction following (30%), JSON formatting (35%), tool calling (35%)
- Overall score → profile recommendation: ≥0.75 → investigative, ≥0.50 → guided, <0.50 → restricted
- Results cached in `/home/ai/model_caps.runtime.json`
- Only runs when heuristic confidence < 0.70 (PROBE_CONFIDENCE_THRESHOLD)
- Local models are ALWAYS capped at `restricted` regardless of probe results
- New fields in ModelCapabilityRecord: `instruction_score`, `format_score_probe`, `tool_calling_score`
- `update_from_probe()` method promotes profile if probe scores justify it (but local stays restricted)

Integration in `ai_service.py`:
- `_maybe_run_capability_probe()` called before `build_agent()` when confidence is low
- `_update_model_capability_from_probe()` writes results to runtime cache

#### 2. PROFILE_KNOWLEDGE_LIMITS — knowledge brief trimmed by profile

Added to agent.py:

| Profile | Max knowledge entries | Max knowledge chars |
|---|---|---|
| restricted | 3 | 400 |
| guided | 6 | 1200 |
| investigative | 8 | 1800 |
| freeform | 8 | 1800 |

`_build_profile_prompt()` now assembles the system prompt by profile:
- restricted: ultra-compact prompt + 3 knowledge entries (max 400 chars)
- others: full BASE_SYSTEM_PROMPT + profile-appropriate knowledge brief size

#### 3. Capability Reminder in system prompt

For restricted/guided profiles, a `[RESTRICTED MODE]` or `[GUIDED MODE]` reminder is appended to the system message before each LLM call:
- Reminds the model to only use available tools
- Warns not to guess or hallucinate commands
- Ephemeral — not stored in session history

#### 4. Spam/Abuse Tool Chain

New shortcut and tool for "who is sending spam?" questions:

**Pattern matcher** `_is_spam_abuse_request(message)`:
- Matches: "who is sending spam", "spam abuse", "compromised account", "welcher Account versendet Spam", etc.
- Excludes: pure stats questions, health questions, email problem questions, autoconfig questions

**Tool definition** `spam_abuse` in log_tools.py:
- Category: `read_only`
- Calls `/home/ai/wrappers/ai-mail-stats --mode spam_abuse`
- Parameters: `--days` (default 7), `--user` (optional), `--limit` (default 10)

**Wrapper mode** `--mode spam_abuse` in `ai-mail-stats`:
- Authenticated users by volume with threshold flags (⚠️ >50 = suspicious, 🔴 >500 = compromised)
- Top envelope senders (filtered: no MAILER-DAEMON, no root@)
- Top connecting client IPs with flags (🔴 >500 connections = likely brute-force/spam bot)
- Spam-classified messages (SpamAssassin/Amavis hits)
- Rejected/blocked message count
- Fixes from Michael's draft `maillog_analyzer.sh`:
  - Queue-ID correlation instead of `grep "status=sent" | grep "sasl_username"` (fixes multi-line log entries)
  - Time-span filtering via `--days`
  - User filtering via `--user`
  - No bounce inflation (filters MAILER-DAEMON and root@ from top senders)

**Added to `restricted` profile whitelist** — even the 360M model can trigger spam abuse reports.

#### 5. Email Problem Diagnostics

New shortcut `_is_email_problem_request(message)` + `_diagnose_email_problem(message)`:
- Matches: "why can't X send emails", "mail delivery problem", "email not working for user", "kann keine Mails senden", etc.
- Excludes: pure stats, health, spam abuse, autoconfig questions
- Combined deterministic diagnosis: calls `mail_health` (with user filter) + `search_admin_logs` (mail+user filter) + `service_status` (postfix + dovecot)
- Produces a structured report: service status → delivery errors → auth failures → recommendations

#### 6. Webroot Scan All Vsites

When "check if websites have been hacked" is asked without specifying a Vsite:
- `_scan_all_vsites()` automatically scans all Vsites found by `_get_vsite_list_output()`
- Returns combined summary: "X Vsite(s) scanned, no/compromise indicators found"
- Offers follow-up: "ask about a specific site for full details"
- Works for both `_is_webroot_integrity_request` and `_is_webroot_forensic_request` intents

Previously: returned error "A /home/sites/.../wwwroot/web/ path is required".

#### 7. Knowledge Base Updates

**Canonical-Truth-Registry.txt** — new entries:
- `blocking_ips_email`: do_not_claim rules for IP/host blocking (manual Postfix edits forbidden, use GUI or custom-postfix-confgen.sh)
- `postfix_configuration`: do_not_claim rules for Postfix customization (manual edits to main.cf/master.cf forbidden, use /usr/sausalito/bin/custom-postfix-confgen.sh)

**truth_registry.json** (installed on builder10) — same two entries in JSON format

**blueonyx_knowledge.md** (installed on builder10):
- Hard Rules: "Never manually edit /etc/postfix/access, main.cf, or other CCE-managed config files"
- Glossary: "IP/Host blocking" (3 GUI paths), "Postfix customization" (custom-postfix-confgen.sh)
- Common Support Topics: "Blocking spam IPs/hosts", "Customizing Postfix settings"

#### 8. Specfile Updates

`base-ai-core.spec` updated to version 1.0.6 with:
- capability_probe.py install line and %files entry
- Changelog entries for all new features

### Live test results (builder10, SmolLM2-360M local model)

| Question | Result |
|---|---|
| "How much disk space is available?" | ✅ system_disk_space shortcut, df -h output |
| "Check if any websites have been hacked" | ✅ _scan_all_vsites(), 6 Vsites scanned, no compromise |
| "Which account is sending spam?" | ✅ spam_abuse shortcut, full abuse report with threshold flags |
| "What is Swatch?" | ✅ Knowledge lookup, correct "monitoring side of Active Monitor" |

### Live test results (builder10, glm-5.1 via Ollama Cloud)

| Question | Result |
|---|---|
| "Check if any websites have been hacked" | ✅ Same scan-all-vsites result |
| "What is Swatch?" | ✅ More detailed correct answer with bullet points |

### Key architecture insight

The "dumb model + smart shortcut" pattern works: the 360M model provides practical admin value because deterministic shortcuts (disk space, webroot scan, spam abuse, knowledge lookup) do the real work and the model only interprets formatted results. The model doesn't need to understand the underlying system — it just needs to recognize the user's intent and call the right shortcut.

### Next steps

- Test capability_probe.py with a real unknown provider/model
- Email problem diagnostics live test ("Why can't user X send emails?")
- GUI: profile-dependent tool toggle warnings (show which tools are profile-limited)
- Extended shortcut coverage for more common admin questions
