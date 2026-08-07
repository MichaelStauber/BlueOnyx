# BlueOnyx Knowledge Base

## Purpose
This file is the compact local anchor for BlueOnyx-specific answers.
It is not a full manual. It is a shortlist of facts, terms, and support
rules that help the assistant avoid generic Linux hallucinations.

## Hard Rules
- BlueOnyx configuration truth lives in CCE/CODB, but current runtime state must come from live tools.
- Use `get_quotas.pl --users` for current user quota usage.
- Use `get_quotas.pl --sites` for current Vsite quota usage.
- Resolve a Vsite FQDN with `vsite_list.pl` before using the internal site group name.
- `Disk.used` in CCE is a cache and can be stale.
- For GUI 500 errors, inspect failed CCE transactions in `/var/log/messages*` and temporarily use `CI_ENVIRONMENT = development` for debugging.
- BlueOnyx-specific support questions should prefer the local registry and deterministic tools before generic model memory.
- Vsites are created in the GUI under Site Management via the `+ Add` button.
- If creation is denied or the button is missing, check `manageSite`, `maxVsite`, and related allocation limits.
- `/chat` and `/function` on the AI service require the `X-BlueOnyx-AI-Auth` header.
- `llama.cpp` on `127.0.0.1:8081` is only protected from external access; while active, local processes can still reach it.
- BlueOnyx Active Monitor uses `swatch` as the monitoring side, so service status and swatch findings should be treated as one monitoring story.

## Glossary
- **BlueOnyx**: The server management platform built on Sausalito.
- **Sausalito**: The BlueOnyx GUI and configuration framework.
- **CCE / CODB**: The configuration engine and its object database.
- **Vsite**: A hosted domain or web site object with its own quota, logs, webroot, and services.
- **Vsite resolution**: Resolve the public FQDN to the internal site group before using quota or filesystem data.
- **manageSite**: Capability gate for Vsite creation.
- **maxVsite**: Common Vsite limit that can block creation.
- **Active Monitor**: BlueOnyx service monitoring and recovery framework.
- **Swatch**: The monitoring side of Active Monitor, not a file display command.
- **AdmServ**: The BlueOnyx administrative web server for the GUI.
- **Chorizo**: The modern CodeIgniter 4 based GUI.
- **Constructor**: A bootstrap script that seeds defaults on install or startup.
- **Handler**: A script that applies validated CCE changes to the system.
- **Schema**: The CCE object definition, validation, permissions, and trigger model.
- **Postfix**: SMTP transport and delivery logs.
- **Dovecot**: IMAP/POP3 mail access logs.
- **Mail statistics**: Log-based counts for delivery, rejects, deferrals, bounces, and spam hits.
- **Disk space**: Use live `df -h` output, not cached state.
- **AI transport auth**: The GUI sends `X-BlueOnyx-AI-Auth` from the CODB-stored `service_api_key`.
- **Local llama.cpp**: On-demand local model service that remains reachable to local processes while active.
- **Easy-Backup**: Backup and restore workflows for settings, services, Vsites, users, and remote targets.
- **CMU / GMU / Easy-Migrate / migrations**: CLI import/export workflows for moving sites and settings between servers. CMU is deprecated; Easy-Migrate is the current path and Easy-Backup can be an alternative for backup/restore style moves.
- **DNS for Email**: Mail-domain alignment, MX, SPF, and deliverability settings.
- **Email Autoconfiguration**: Validated IMAP/SMTP settings, ports, encryption, login format, and Outlook/Thunderbird/Apple Mail/iPhone/Android setup guidance.
- **Sender identity spoofing**: Mail identity, aliasing, and authenticated submission controls.
- **OpenDKIM**: DKIM enablement, key generation, and DNS TXT records.
- **2FA**: Time-based second-factor login hardening.
- **Multiple PHP versions**: Multiple PHP runtimes and delivery modes per site.
- **Incident timeline**: A chronological correlation of journalctl, auth, mail, web, and service restart evidence.
- **SSL health**: Certificate expiry, chain, and key/cert pairing checks for AdmServ and Vsites.
- **PHP-FPM health**: Master PHP-FPM and per-version pools, with extra pools only considered unhealthy when a Vsite actually needs them.
- **Site health evidence**: A single Vsite report that combines web ownership, SSL state, PHP-FPM state, quota/disk usage, and centralized log evidence.
- **BlueOnyx helper scripts**: Small root-run scripts such as `vsite_list.pl`, `ssl_get.pl`, `reload_webservers.pl`, `SSL_fixer.pl`, `toggle_ssl.pl`, and `phptoggle.pl` can be useful repair or evidence helpers when the problem matches their purpose.
- **Web owner repair**: For a single or small number of bad Vsite `/web` owners, inspect the affected Vsite in the GUI under `Site Management / <Vsite> / Services / Web Ownership` and correct its PHP preferred site admin to a real site admin. `set_web_owner.pl` is the canonical bulk repair script when ownership drift is systemic; it fixes `PHP.prefered_siteAdmin` to a real site admin when the current owner is empty, `nobody`, or `apache`.
- **Chrooted Jails**: Restricted command and filesystem access for SSH/SFTP users.
- **FTP File Manager**: GUI file manager for FTP-capable users under Programs.
- **Radicale**: CalDAV/CardDAV service for calendars and address books.
- **Web Alias Redirects**: Redirect policy for site aliases.
- **Site Prefix**: Hostname and site-prefix handling in hosting management.
- **GDPR / DSGVO**: Privacy, retention, and operational data protection topic.
- **Nginx SSL Proxy**: HTTPS termination in front of hosted web sites.
- **Sauce service**: A service topic that should be explained with local status and logs rather than guessed.

## Canonical Concepts
- BlueOnyx answers should usually anchor on local tools and local knowledge.
- Sausalito includes the GUI glue, schemas, handlers, constructors, and runtime scripts.
- CCE/CODB is the configuration layer. GUI changes land there first and only become live when handlers apply them.
- Vsites are not just directories. They are hosted objects with quota, webroot, logs, and service state.
- Current quota usage must be read from live quota tools. CCE cache fields can lag behind the truth.
- Current disk usage must be read from `df -h` or equivalent live diagnostics.
- Current log evidence should come from targeted log search, not from model memory.
- Support questions should prefer the local registry, then tools, then a cautious fallback if nothing fits.

## Common Support Topics
- **Vsite creation**: use Site Management and the `+ Add` button; check `manageSite`, `maxVsite`, and the GUI capability path if it is missing or denied.
- **GUI 500 error**: inspect `/var/log/messages*` for failed CCE transactions and temporarily use `CI_ENVIRONMENT = development` for debugging.
- **Mail delivery**: inspect `maillog`, Postfix, Dovecot, and spam/filter events.
- **Email client setup**: point users to Email Autoconfiguration first, not generic mail instructions.
- **Easy-Backup**: use the backup/restore workflow and remote storage settings for settings and site backups.
- **Migrations**: check whether the request is an import/export or site move, and use the dedicated Easy-Migrate CLI workflow. CMU is deprecated.
- **MariaDB**: Is administered via the BlueOnyx GUI, phpMyAdmin is also built in. Password changes for SQL user 'root' must change ALL occurences of the 'root' account.
- **DNS and BIND**: The Systemd Unit file for the DNS server is 'named-chroot'. The Bind config files and zone files are located under /var/named/chroot/

## Migration Answer
- Easy-Migrate is a CLI tool, not a GUI wizard.
- Run it on the target server as root and connect back to the source over SSH.
- Typical command: `/usr/sausalito/sbin/easy-migrate.pl --source <source-ip> -p 22`
- Use `--dnsonly` for DNS-only migrations.
- A single Vsite migration can carry the site, users, email, DNS, SQL, and related settings.
- Official docs: `https://www.blueonyx.it/easy-migrate.html`
- CMU docs: `https://www.blueonyx.it/cmu-migrations.html`
- Easy-Backup docs: `https://www.blueonyx.it/easy-backup.html`
- **DNS for Email**: verify MX, SPF, and mail-domain alignment before changing mail transport settings.
- **Email Autoconfiguration**: point users to the validated IMAP/SMTP values, encryption, and login format.
- **Sender identity spoofing**: check aliases, authenticated submission, and DKIM/SPF/DMARC policy.
- **OpenDKIM**: per-Vsite DKIM keys and DNS TXT records need to stay in sync.
- **2FA**: use the BlueOnyx login hardening flow rather than generic Linux OTP assumptions.
- **Multiple PHP versions**: confirm the site's PHP mode and version support before suggesting changes.
- **Incident timeline**: if the user asks what changed before something broke, prefer the incident timeline tool before guessing.
- **SSL health**: check AdmServ and Vsite certs for expiry and chain problems before assuming Apache or Nginx has a generic startup issue.
- **PHP-FPM health**: verify the master pool and only the extra pools that Vsites actually use.
- **Site health evidence**: combine ownership, SSL, PHP-FPM, quota, and central logs for a single Vsite instead of guessing from a single symptom.
- **Chrooted Jails**: remember that jailed users are restricted to a small command set and subtree.
- **FTP File Manager**: available to authenticated GUI users with FTP rights under Programs.
- **Radicale**: calendars and address books use CalDAV/CardDAV rather than plain web hosting paths.
- **Web Alias Redirects**: aliases may redirect to the main site name depending on GUI policy.
- **Site Prefix**: site naming and prefix behavior can affect URLs and GUI-managed site names.
- **GDPR / DSGVO**: treat this as a privacy and retention policy topic, not a technical command issue.
- **Nginx SSL Proxy**: verify whether HTTPS is handled by the proxy or by Apache before diagnosing headers.
- **Sauce service**: use local service status and logs before assuming what the service does.
- **Failed logins**: inspect `secure` for `Failed password`, `Invalid user`, and PAM failures.
- **Login attempts**: include preauth disconnects and similar auth activity.
- **Disk space**: use live `df -h` output for all mounts.
- **User/Vsite usage**: use live quota tools and resolve the Vsite name first.
- **BlueOnyx AI**: use the auth header, provider selection, and policy-gated tools.
- **BlueOnyx helper scripts**: `vsite_list.pl`, `ssl_get.pl`, `reload_webservers.pl`, `SSL_fixer.pl`, `toggle_ssl.pl`, and `phptoggle.pl` are worth suggesting when the user has a matching site/SSL/PHP problem.
- **Web owner repair**: For one-off bad Vsite `/web` ownership, use `Site Management / <Vsite> / Services / Web Ownership` to correct the site's PHP preferred site admin. Use `set_web_owner.pl` when the problem is systemic across many Vsites.

## Trusted External Sources
- Prefer the local registry and deterministic tools first.
- If local sources are not enough, use official BlueOnyx documentation from `https://www.blueonyx.it/` and `https://wiki.blueonyx.it/userguide/start`.
- The mailing list archive at `https://mail.blueonyx.it/pipermail/blueonyx/` is a useful secondary source when the official docs do not answer the question.
- Michael Stauber's emails are a top-trust human source unless newer official docs or code contradict them.

## Final Fallback
- If the answer is still uncertain after local knowledge, tools, and trusted external sources, say so plainly.
- Then point the user to `https://www.blueonyx.it/`, `https://wiki.blueonyx.it/userguide/start`, `https://mail.blueonyx.it/pipermail/blueonyx/`, `https://discord.gg/YJ2MHDvyrB`, or `Software Updates > Support > Support Request`.
- Do not invent a chat support button, chat function, or similar GUI element if one is not explicitly present.

## Email Autoconfiguration Answer
Use this when a user wants to set up Outlook, Thunderbird, Apple Mail, iPhone Mail, iPad Mail, Android Mail, Gmail app, or another mail client.

- Find the BlueOnyx Email Autoconfiguration values in the GUI first.
- Use the exact IMAP and SMTP server names shown there.
- Use the exact ports and encryption modes shown there.
- Use the correct login name format, usually the full email address or the format the GUI specifies.
- Do not invent generic mail settings if BlueOnyx has a validated autoconfig entry.
- If the user asks for the current supported mail settings, prefer the GUI-generated autoconfiguration data over memory.

## Operational Notes
- `search_blueonyx_knowledge` should be used for BlueOnyx terms that are not covered by deterministic shortcuts.
- The local knowledge base is intentionally compact.
- Model capability profiles are cached separately in `model_caps.json` plus a writable runtime cache. Unknown models start conservative and are promoted only when the classifier is confident.
- If the question is still uncertain, say so plainly instead of inventing details.
