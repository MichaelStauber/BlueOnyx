Name:           base-ai-core
Version:        1.0.8
Release:        29%{?dist}
Summary:        BlueOnyx AI core service (Python agent with systemd socket activation)

License:        SUN-modified-BSD
URL:            https://www.blueonyx.it/
Source:         %{name}.tar.gz

BuildRoot:      %{_tmppath}/%{name}-%{version}-root

%if 0%{?rhel} == 8
Requires:       python38
Requires:       python38-pip
%else
Requires:       python3
Requires:       python3-pip
%endif
Requires:       systemd
Requires:       base-ai-knowledge
Requires(pre):  shadow-utils

%description
The BlueOnyx AI module provides an AI-powered assistant that can read
system logs, check service status, perform service actions, and gather
system information. It uses systemd socket activation for on-demand
startup.

%prep
%setup -n %{name}

%build
# Nothing to build — pure Python scripts

%install
rm -rf %{buildroot}

# Python package and top-level files
install -d -m 0755 %{buildroot}/home/ai/sausalito_ai/agent
install -d -m 0755 %{buildroot}/home/ai/sausalito_ai/providers
install -d -m 0755 %{buildroot}/home/ai/sausalito_ai/tools
install -d -m 0755 %{buildroot}/home/ai/wrappers
install -d -m 0755 %{buildroot}/home/ai/.cache
install -d -m 0755 %{buildroot}/home/ai/.cache/tiktoken
install -d -m 0755 %{buildroot}/home/ai/sessions
install -d -m 0755 %{buildroot}/home/ai/logs
install -d -m 0755 %{buildroot}/var/lib/sausalito/ai/sessions
install -d -m 0755 %{buildroot}/etc/rsyslog.d
install -d -m 0755 %{buildroot}/etc/logrotate.d
install -d -m 0755 %{buildroot}/usr/lib/tmpfiles.d
install -d -m 0755 %{buildroot}/usr/sausalito/sbin

install -m 0755 sausalito_ai/__init__.py %{buildroot}/home/ai/sausalito_ai/__init__.py
install -m 0755 sausalito_ai/ai_service.py %{buildroot}/home/ai/ai_service.py
install -m 0755 sausalito_ai/requirements.txt %{buildroot}/home/ai/requirements.txt
install -m 0755 sausalito_ai/model_caps.py %{buildroot}/home/ai/sausalito_ai/model_caps.py
install -m 0755 sausalito_ai/capability_probe.py %{buildroot}/home/ai/sausalito_ai/capability_probe.py
install -m 0755 sausalito_ai/audit_log.py %{buildroot}/home/ai/sausalito_ai/audit_log.py
install -m 0755 sausalito_ai/agent/__init__.py %{buildroot}/home/ai/sausalito_ai/agent/__init__.py
install -m 0755 sausalito_ai/agent/agent.py %{buildroot}/home/ai/sausalito_ai/agent/agent.py
install -m 0755 sausalito_ai/agent/session.py %{buildroot}/home/ai/sausalito_ai/agent/session.py
install -m 0755 sausalito_ai/providers/__init__.py %{buildroot}/home/ai/sausalito_ai/providers/__init__.py
install -m 0755 sausalito_ai/providers/external_provider.py %{buildroot}/home/ai/sausalito_ai/providers/external_provider.py
install -m 0755 sausalito_ai/tools/__init__.py %{buildroot}/home/ai/sausalito_ai/tools/__init__.py
install -m 0755 sausalito_ai/tools/base.py %{buildroot}/home/ai/sausalito_ai/tools/base.py
install -m 0755 sausalito_ai/tools/knowledge_tools.py %{buildroot}/home/ai/sausalito_ai/tools/knowledge_tools.py
install -m 0755 sausalito_ai/tools/log_tools.py %{buildroot}/home/ai/sausalito_ai/tools/log_tools.py
install -m 0755 sausalito_ai/tools/file_tools.py %{buildroot}/home/ai/sausalito_ai/tools/file_tools.py
install -m 0755 sausalito_ai/tools/system_tools.py %{buildroot}/home/ai/sausalito_ai/tools/system_tools.py
install -m 0755 sausalito_ai/tools/service_tools.py %{buildroot}/home/ai/sausalito_ai/tools/service_tools.py
install -m 0755 sausalito_ai/tools/diagnostic_tools.py %{buildroot}/home/ai/sausalito_ai/tools/diagnostic_tools.py
install -m 0755 sausalito_ai/tools/tool_context.py %{buildroot}/home/ai/sausalito_ai/tools/tool_context.py
install -m 0755 sausalito_ai/ssl_health.py %{buildroot}/usr/sausalito/sbin/ai-ssl-health.py
install -m 0755 sausalito_ai/php_fpm_health.py %{buildroot}/usr/sausalito/sbin/ai-php-fpm-health.py
install -m 0755 sausalito_ai/active_monitor_status.pl %{buildroot}/usr/sausalito/sbin/ai-active-monitor-status.pl
install -m 0644 sausalito_ai/rsyslog.d/30-blueonyx-ai.conf %{buildroot}/etc/rsyslog.d/30-blueonyx-ai.conf
install -m 0644 sausalito_ai/logrotate.d/blueonyx-ai %{buildroot}/etc/logrotate.d/blueonyx-ai
install -m 0644 sausalito_ai/tmpfiles.d/base-ai.conf %{buildroot}/usr/lib/tmpfiles.d/base-ai.conf
install -m 0755 sausalito_ai/wrappers/ai-read-log %{buildroot}/home/ai/wrappers/ai-read-log
install -m 0755 sausalito_ai/wrappers/ai-search-logs %{buildroot}/home/ai/wrappers/ai-search-logs
install -m 0755 sausalito_ai/wrappers/ai-mail-stats %{buildroot}/home/ai/wrappers/ai-mail-stats
install -m 0755 sausalito_ai/wrappers/ai-journalctl %{buildroot}/home/ai/wrappers/ai-journalctl
install -m 0755 sausalito_ai/wrappers/ai-service-action %{buildroot}/home/ai/wrappers/ai-service-action
install -m 0755 sausalito_ai/wrappers/ai-service-status %{buildroot}/home/ai/wrappers/ai-service-status
install -m 0755 sausalito_ai/wrappers/ai-system-info %{buildroot}/home/ai/wrappers/ai-system-info
install -m 0755 sausalito_ai/wrappers/ai-memory-info %{buildroot}/home/ai/wrappers/ai-memory-info
install -m 0755 sausalito_ai/wrappers/ai-uname %{buildroot}/home/ai/wrappers/ai-uname
# Top-level scripts
install -d -m 0755 %{buildroot}/usr/sbin
install -m 0444 sausalito_ai/cce-get-system-ai %{buildroot}/usr/sbin/cce-get-system-ai

# sudoers
install -d -m 0750 %{buildroot}/etc/sudoers.d
install -m 0440 sausalito_ai/sudoers.d/99-blueonyx-ai %{buildroot}/etc/sudoers.d/99-blueonyx-ai

# Systemd units
install -d -m 0755 %{buildroot}/usr/lib/systemd/system
install -m 0644 sausalito-ai.service %{buildroot}/usr/lib/systemd/system/sausalito-ai.service
install -m 0644 sausalito-ai.socket %{buildroot}/usr/lib/systemd/system/sausalito-ai.socket

%if 0%{?rhel} == 8
# EL8 ships both Platform-Python 3.6 and the AppStream Python 3.8 runtime.
# Force the helper scripts that are executed directly by the OS to use 3.8.
/usr/bin/perl -0pi -e 's@^#!/usr/bin/env python3$@#!/usr/bin/python3.8@m' \
  %{buildroot}/usr/sausalito/sbin/ai-ssl-health.py \
  %{buildroot}/usr/sausalito/sbin/ai-php-fpm-health.py \
  %{buildroot}/home/ai/wrappers/ai-mail-stats
%endif

%files
%defattr(-,root,root)
%attr(0755,blueonyx_ai,blueonyx_ai) /home/ai
%attr(0755,blueonyx_ai,blueonyx_ai) /home/ai/.cache
%attr(0755,blueonyx_ai,blueonyx_ai) /home/ai/.cache/tiktoken
%attr(0755,blueonyx_ai,blueonyx_ai) /home/ai/sessions
%attr(0755,blueonyx_ai,blueonyx_ai) /home/ai/logs
%attr(0755,blueonyx_ai,blueonyx_ai) /home/ai/wrappers
/var/lib/sausalito
%attr(0755,blueonyx_ai,blueonyx_ai) /var/lib/sausalito/ai
%attr(0755,blueonyx_ai,blueonyx_ai) /var/lib/sausalito/ai/sessions
/home/ai/ai_service.py
/home/ai/requirements.txt
/home/ai/sausalito_ai/*
/home/ai/sausalito_ai/model_caps.py
/home/ai/sausalito_ai/capability_probe.py
/home/ai/sausalito_ai/agent/*
/home/ai/sausalito_ai/providers/*
/home/ai/sausalito_ai/tools/*
/usr/sausalito/sbin/ai-ssl-health.py
/usr/sausalito/sbin/ai-php-fpm-health.py
/usr/sausalito/sbin/ai-active-monitor-status.pl
/home/ai/wrappers/*
/usr/sbin/cce-get-system-ai
/etc/rsyslog.d/30-blueonyx-ai.conf
/etc/logrotate.d/blueonyx-ai
/usr/lib/tmpfiles.d/base-ai.conf
%attr(0440,root,root) /etc/sudoers.d/99-blueonyx-ai
/usr/lib/systemd/system/sausalito-ai.service
/usr/lib/systemd/system/sausalito-ai.socket

%pre
# Create blueonyx_ai system user if not exists
if ! id -u blueonyx_ai >/dev/null 2>&1; then
    useradd -r -s /sbin/nologin -d /home/ai blueonyx_ai
fi
# Create /home/ai and set ownership
mkdir -p /home/ai
chown blueonyx_ai:blueonyx_ai /home/ai

%post
# Create required directories and set ownership
mkdir -p /home/ai/sessions
chown blueonyx_ai:blueonyx_ai /home/ai/sessions || :

mkdir -p /home/ai/logs
chown blueonyx_ai:blueonyx_ai /home/ai/logs || :

mkdir -p /home/ai/wrappers
chown blueonyx_ai:blueonyx_ai /home/ai/wrappers || :

mkdir -p /home/ai/.cache/tiktoken
chown -R blueonyx_ai:blueonyx_ai /home/ai/.cache || :

# Recreate the Python 3 venv whenever the selected runtime minor version
# changes, so the AI service always uses the intended interpreter for the
# target platform.
%if 0%{?rhel} == 8
sys_py_mm=`/usr/bin/python3.8 -c 'import sys; print(f"{sys.version_info[0]}.{sys.version_info[1]}")'`
%else
sys_py_mm=`/usr/bin/python3 -c 'import sys; print(f"{sys.version_info[0]}.{sys.version_info[1]}")'`
%endif
venv_py_mm=`/home/ai/venv/bin/python -c 'import sys; print(f"{sys.version_info[0]}.{sys.version_info[1]}")' 2>/dev/null || :`
if [ ! -x /home/ai/venv/bin/python ] || [ "$venv_py_mm" != "$sys_py_mm" ]; then
    rm -rf /home/ai/venv
%if 0%{?rhel} == 8
    /usr/bin/python3.8 -m venv /home/ai/venv
%else
    /usr/bin/python3 -m venv /home/ai/venv
%endif
fi

# Install Python dependencies quietly, but still fail loud on real errors.
%if 0%{?rhel} == 8
/home/ai/venv/bin/pip install -q --disable-pip-version-check 'pip==24.3.1'
%endif
/home/ai/venv/bin/pip install -q --disable-pip-version-check -r /home/ai/requirements.txt

# Reload rsyslog so the dedicated BlueOnyx AI routing rule is active immediately.
systemctl reload rsyslog.service >/dev/null 2>&1 || systemctl restart rsyslog.service >/dev/null 2>&1 || :

# Refresh systemd's unit cache so new service changes are picked up on restart.
systemctl daemon-reload >/dev/null 2>&1 || :

# Condrestart equivalent for systemd: only restart if the service is active.
systemctl try-restart sausalito-ai.service >/dev/null 2>&1 || :

# Repair runtime ownership for session/log directories and ensure it persists.
systemd-tmpfiles --create /usr/lib/tmpfiles.d/base-ai.conf >/dev/null 2>&1 || :

%preun
%systemd_preun sausalito-ai.service
if [ $1 -eq 0 ]; then
    systemctl stop sausalito-ai.service >/dev/null 2>&1 || :
    systemctl disable sausalito-ai.service >/dev/null 2>&1 || :
    systemctl daemon-reload >/dev/null 2>&1 || :
fi

%postun
%systemd_postun_with_restart sausalito-ai.service

%changelog
* Sat Aug 08 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.8-29
- Added a dedicated broad deterministic suspicion check for unscoped
  "anything suspicious?" style questions so the agent now runs a cross-domain
  sweep instead of misrouting the request into one specific subsystem.

* Sat Aug 08 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.8-28
- Tightened deterministic intent routing so broad phrases such as "anything
  suspicious" no longer trigger a webroot integrity sweep unless the request
  also contains clear Vsite/site/webroot context.

* Fri Aug 07 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.8-27
- Removed fail2ban from the deterministic managed-service health summary so
  servers are no longer reported against an optional add-on that BlueOnyx
  does not universally install or manage through Active Monitor.
- Corrected the deterministic webroot integrity sweep to resolve Vsite
  document roots under /home/.sites/<site>/wwwroot/web and to accept both the
  hidden site path and the FQDN symlink path when an explicit webroot is
  provided.

* Fri Aug 07 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.8-26
- Corrected the cross-EL Python runtime consolidation for 5210R/EL8 so the
  AI service venv is recreated with Python 3.8 instead of the older platform
  python3 interpreter.
- Restore Python 3.8 shebangs at package build time for the EL8 helper
  scripts that are executed directly outside the service venv.

* Fri Aug 07 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.8-25
- Started consolidating the EL8/EL9/EL10 service packaging into one
  platform-aware spec by selecting the Python package requirements and pip
  bootstrap behavior from the target RHEL major version at build/install time.
- Switched the venv recreation logic to follow the host system python3 minor
  version instead of hardcoding the EL8-specific Python 3.8 runtime.

* Fri Aug 07 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.8-24
- Corrected Vsite SSL certificate discovery to read the real BlueOnyx site
  storage tree under /home/.sites/<vsite>/wwwroot/certs instead of the
  /home/sites symlink area, which caused false zero-certificate reporting.

* Fri Aug 07 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.8-23
- Tightened Vsite SSL coverage detection so the health summary counts only
  locally configured Vsite certificates instead of treating ssl_get.pl
  fallback output as proof that a Vsite has SSL enabled.

* Fri Aug 07 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.8-22
- Added a dedicated root-run Active Monitor helper so the AI service can read
  BlueOnyx service intent through a whitelisted script instead of attempting
  ad-hoc CCE access from the unprivileged runtime.
- Eliminated the server-health sudo password failure path and the resulting
  unauthenticated CCE noise when summarizing managed service health.

* Fri Aug 07 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.8-21
- Reworked the deterministic server-health summary to honor BlueOnyx
  service intent from CCE/ActiveMonitor instead of assuming every daemon
  should run on every server.
- Check DNS as `named-chroot` only when DNS is enabled in BlueOnyx and
  suppress false alarms for intentionally disabled managed services.

* Fri Aug 07 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.8-20
- Corrected the deterministic server-health summary to check BlueOnyx's FTP
  service as `proftpd` instead of `vsftpd`.

* Fri Aug 07 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.8-19
- Added a deterministic `server_health_summary` diagnostics tool and routed
  broad server-health questions to it so overall health answers use one
  reconciled data set for resources, key services, Vsite inventory, SSL
  coverage, and /web ownership checks.

* Fri Aug 07 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.8-18
- Added a deterministic `list_vsites` diagnostics tool backed by
  `vsite_list.pl` so hosted-site and domain inventory questions return the
  real BlueOnyx Vsite list instead of model guesses.
- Short-circuit Vsite/domain inventory questions to the new tool and keep it
  available in the restricted profile whitelist.

* Fri Aug 07 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.8-17
- Filter the known harmless Pydantic v2 warning emitted by LiteLLM's legacy
  integration config during import so local and external provider startup no
  longer clutters syslog.
- Lower LiteLLM, httpx, httpcore, and uvicorn access logger levels to
  WARNING for quieter production service logs.

* Fri Aug 07 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.8-16
- Set CUSTOM_TIKTOKEN_CACHE_DIR for sausalito-ai.service and the Python
  runtime because LiteLLM overrides TIKTOKEN_CACHE_DIR during import and only
  honors the custom variable for its tokenizer cache path.
- Seed the same safeguard in external_provider.py before importing litellm so
  provider startup cannot fall back to the read-only site-packages tree.

* Fri Aug 07 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.8-15
- Export a deterministic writable HOME/XDG cache environment for
  sausalito-ai.service so litellm/tiktoken never attempt to write inside the
  Python site-packages tree.
- Create and own /home/ai/.cache and /home/ai/.cache/tiktoken during package
  install, tmpfiles repair, and service startup.
- Seed the same cache defaults in ai_service.py before provider imports so the
  runtime remains stable even if the service environment is incomplete.

* Sat Aug 01 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.8-14
- Validate and wait for the on-demand local llama backend before chat.
- Expose local inference capability and running state in health output.


* Sat Aug 01 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.8-13
- Ported the service to AlmaLinux 8's Python 3.8 AppStream runtime instead of
  the system Platform-Python 3.6 interpreter.
- Recreate an existing venv when it uses the wrong Python minor version and
  update pip to the final release series supporting Python 3.8.
- Pin Uvicorn to 0.33.0, the last release line compatible with Python 3.8.
- Pin tokenizers to 0.20.3 because its newer CPython 3.8 wheel references a
  Python runtime symbol that is unavailable on AlmaLinux 8.
- Added postponed annotation evaluation where Python 3.8 cannot evaluate
  built-in generic annotations and replaced asyncio.to_thread with a
  run_in_executor compatibility helper.
- Run the installed Python helper scripts explicitly with Python 3.8.

* Sun Jul 20 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.8-12
- Added `from __future__ import annotations` (PEP 563) to agent.py,
  ai_service.py, tools/base.py, and tools/system_tools.py so PEP 604
  union type hints (e.g. `list[str] | None`) are treated as strings at
  runtime. Without it the production Python 3.9 venv raises
  `TypeError: unsupported operand type(s) for |: 'types.GenericAlias'
  and 'NoneType'` when the ToolExecutor class body executes, crashing
  sausalito-ai.service in a restart loop.
- Added the missing tool_context.py install line so the RPM ships it under
  /home/ai/sausalito_ai/tools/. Without it base.py's import of
  reset_tool_context/set_tool_context fails at startup with
  ModuleNotFoundError, crashing sausalito-ai.service in a loop.

* Sat Jul 18 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.8-11
- Added site_health_evidence: a single-Vsite health report that combines
  web ownership, SSL state, PHP-FPM state, quota/disk usage, and centralized
  log evidence, with support for rotated .gz log archives.
- Taught the agent to short-circuit site-health questions to the new
  deterministic evidence report when a Vsite or FQDN is provided.

* Sat Jul 18 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.8-10
- Added Vsite /web owner health diagnostics with a deterministic GUI vs bulk
  repair recommendation, so one-off bad sites point to the per-site Web
  Ownership GUI while systemic drift can still use set_web_owner.pl.
- Taught the runtime to recognize the new web-owner health path in the agent
  and BlueOnyx knowledge anchors.

* Sat Jul 18 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.8-9
- Added deterministic incident timeline, SSL health, and PHP-FPM health
  diagnostics for admin troubleshooting.
- Added root-run helper scripts for SSL and PHP-FPM health so the AI can
  inspect AdmServ and Vsite certificates and versioned PHP-FPM pools.
- Expanded the privileged-script whitelist and sudoers rules to cover the
  new read-only BlueOnyx helper scripts.
- Added a systemd try-restart in %post so upgrades behave like condrestart and
  only restart sausalito-ai.service when it is already active.

* Sat Jul 18 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.8-8
- Allow a fresh explicit service restart/reload request to clear an old
  pending confirmation when the user clearly pivots to a different service.

* Sat Jul 18 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.8-7
- Made service restart/reload confirmations return a short deterministic
  success message instead of echoing wrapper/systemd output.

* Sat Jul 18 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.8-6
- Tightened pending-confirmation recovery so it stops after any later assistant
  response and no longer resurrects already-completed write operations.

* Sat Jul 18 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.8-5
- Fixed the agent's tool-call session save so it no longer overwrites
  pending_confirmation after a write tool requests approval.

* Sat Jul 18 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.8-4
- Added a backend fallback that reconstructs pending confirmations from the
  stored conversation history if the explicit session flag is missing.
- Quieted the chat session id handling by keeping a stable browser-side
  session id in localStorage, so a restart confirmation survives reloads.

* Sat Jul 18 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.8-3
- Quieted the pip install step in %post so RPM upgrades stop spamming
  "Requirement already satisfied" output and pip self-update notices.

* Sat Jul 18 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.8-2
- Added systemd daemon-reload in %post so updated unit files take effect
  immediately after package install or upgrade.
- Kept the service-side ownership repair root-run and narrowed it to the
  runtime directories only, avoiding recursion into /home/ai/venv.

* Fri Jul 17 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.8-1
- Added comprehensive network diagnostics tools for live interfaces,
  counters, routes, sockets, DNS resolver state, and historical bandwidth.
- Added a deterministic network-usage shortcut so "network usage" questions
  go straight to the new summary tool instead of relying on model inference.
- Added package-owned runtime directories plus tmpfiles repair for the AI
  session/log/wrapper paths and /var/lib/sausalito/ai/sessions.
- Added ExecStartPre ownership repair in sausalito-ai.service so startup
  reasserts the expected blueonyx_ai ownership even if the live host drifts.

* Sun Jul 05 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.7-3
- Fixed indentation in system_tools.py so the service can import
  SystemMemoryTool and SystemDiskSpaceTool without startup failure.

* Sun Jul 05 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.7-2
- Added system_memory tool and ai-memory-info wrapper to report RAM and swap
  usage via free -h. Registered in ai_service.py alongside system_disk_space.
  Added to sudoers whitelist and RPM install/files lists.

* Sat Jul 04 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.7-1
- Added audit_log.py: structured JSON syslog logging (facility local6) for every
  user query, tool execution, confirmation request, model response, and error.
  Normal audit events now log at info-level so they land in /var/log/messages
  under standard rsyslog rules instead of falling through to the journal.
- Added package-managed rsyslog and logrotate snippets for a dedicated
  /var/log/blueonyx-ai.log file with daily rotation, gzip compression, and
  seven retained archives.
- Added a post-install rsyslog reload so the new local6/tag routing rule takes
  effect immediately after package installation or upgrade.
- Wired audit logging into ai_service.py for both /chat SSE stream and
  /function endpoint with automatic timing and sensitive-key redaction.
- Hardened run_privileged_command in system_tools.py against symlink traversal
  with _safe_realpath (double-resolve race detection), _path_is_under_root
  (jail to /home/ai/wrappers/ and /usr/sausalito/sbin/), and final os.stat()
  S_ISREG TOCTOU guard.

* Sat Jun 13 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.6-1
- Added spam_abuse tool and deterministic shortcut for identifying spam-abuse
  sources: which authenticated user is blasting spam, suspicious sender volumes,
  top offender IPs, and automatic CRITICAL/WARNING flags.
- Added render_spam_abuse_report() mode to ai-mail-stats wrapper with
  ABUSE_THRESHOLD=50 and CRITICAL_THRESHOLD=500 auto-flagging.
- Added _is_spam_abuse_request() pattern matcher in agent.py (English + German).
- Added _spam_abuse_args() to extract user and time-range from natural language.
- Added scan-all-vsites fallback for webroot integrity when no Vsite specified.
- Shortcuts for spam/abuse questions fire before mail_stats/mail_health to avoid
  mis-routing by simpler matchers.

* Fri Jun 12 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.5-1
- Added capability_probe.py for runtime model intelligence testing (instruction
  following, JSON formatting, tool calling) with automatic profile promotion.
- Added profile-dependent tool filtering: restricted models see only 9 whitelisted
  tools, guided/investigative/freeform see progressively more.
- Added profile-dependent system prompt compression: restricted gets a 75% shorter
  prompt with limited knowledge brief to avoid confusing small models.
- Added capability reminder in system prompt for restricted/guided profiles.
- Local models are always capped at restricted profile regardless of probe results.

* Sat May 23 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.4-1
- Added the model capability matrix and automatic profile classification so
  new models can start conservatively and be promoted as they prove capable.
- Split the curated BlueOnyx knowledge data into a separate noarch package
  and added the local knowledge/model-capability loaders.
- Added model-profile-aware read-only filesystem scoping so stronger models
  can inspect /usr/sausalito/ for diagnosis while /home/ai/ and the protected
  CCE subtrees remain off-limits.
- Improved webroot forensic output to separate strong hits, weak filename
  signals, and the overall assessment without forcing rigid templates on all
  model classes.

* Sun May 17 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.3-1
- BlueOnyx AI now starts sausalito-llama.service on demand for local-provider
  chat requests and stops it again when the AI service idles out. This works now.
- The GUI-to-AI transport now uses a CODB-stored service auth key
  (`System.AI.service_api_key`) and sends it as `X-BlueOnyx-AI-Auth` to
  protect `/chat` and `/function` from local loopback callers.

* Sun May 17 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.2-1
- Non-local providers now proactively stop sausalito-llama.service on startup
  so stale local llama processes do not linger when OpenRouter/OpenAI/Anthropic
  are selected.
- Updated provider handling for OpenRouter, Ollama Cloud, and Anthropic
  discovery behavior.
- Added/extended read-only admin tools for logs, mail statistics, disk usage,
  and system diagnostics.
- Improved AI chat UI rendering, copy controls, and fullscreen toggle.
- BlueOnyx AI now starts sausalito-llama.service on demand for local-provider
  chat requests and stops it again when the AI service idles out.
- The GUI-to-AI transport now uses a CODB-stored service auth key
  (`System.AI.service_api_key`) and sends it as `X-BlueOnyx-AI-Auth` to
  protect `/chat` and `/function` from local loopback callers.

* Thu May 14 2026 Michael Stauber <mstauber@solarspeed.net> 1.0.0-1
- Initial RPM release of BlueOnyx AI core service.
- Moved blueonyx_ai user creation from %%post to %%pre section
  to ensure user exists before directory ownership changes.
