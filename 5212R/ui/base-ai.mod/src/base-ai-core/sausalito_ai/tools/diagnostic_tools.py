"""Read-only BlueOnyx diagnostic tools."""

from __future__ import annotations

import glob
import gzip
import json
import logging
import os
import re
import subprocess
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path
from typing import Any

from .base import ToolDefinition, ToolResult

logger = logging.getLogger("sausalito_ai.tools.diagnostics")

SEARCH_WRAPPER = "/home/ai/wrappers/ai-search-logs"
JOURNAL_WRAPPER = "/home/ai/wrappers/ai-journalctl"
SSL_HELPER = "/usr/sausalito/sbin/ai-ssl-health.py"
PHPFPM_HELPER = "/usr/sausalito/sbin/ai-php-fpm-health.py"
ACTIVE_MONITOR_HELPER = "/usr/sausalito/sbin/ai-active-monitor-status.pl"
WEB_OWNER_REPAIR_SCRIPT = "/usr/sausalito/sbin/set_web_owner.pl"
WEB_OWNER_RELOAD_SCRIPT = "/usr/sausalito/sbin/reload_webservers.pl"
GET_QUOTAS = "/usr/sausalito/sbin/get_quotas.pl"

COMMON_LOG_GLOBS = [
    "/var/log/messages*",
    "/var/log/secure*",
    "/var/log/maillog*",
    "/var/log/httpd/*",
    "/var/log/sshd*",
    "/var/log/audit/audit.log*",
]

TIMELINE_PATTERNS = {
    "service": r"(?i)\b(started|stopping|stopped|restarted|reloading|failed|failure|shutdown|reload)\b",
    "auth": r"(?i)\b(failed password|accepted password|session opened|session closed|authentication failure|invalid user|sudo\[|pam_unix|preauth)\b",
    "mail": r"(?i)\b(postfix|dovecot|smtp|imap|pop3|delivery|defer|bounce|reject|queue)\b",
    "web": r"(?i)\b(httpd|apache|nginx|admserv|php-fpm|ssl|tls|certificate)\b",
    "monitor": r"(?i)\b(swatch|active monitor|am_)\b",
}

AI_NOISE_PATTERNS = (
    r"(?i)\bblueonyx_ai\b",
    r"(?i)\bblueonyx-ai\b",
    r"(?i)\bai_service\.py\b",
    r"(?i)\bai_helper\b",
    r"(?i)\bai-(?:search-logs|journalctl|mail-stats|service-status|system-info|memory-info|uname|ssl-health|php-fpm-health)\b",
    r"(?i)\bsausalito_ai\.(?:agent|tools)\.",
    r"(?i)\bExecuting (?:read|write) tool:\b",
    r"(?i)\bShort-circuiting\b",
    r"(?i)\btool call\b",
    r"(?i)\bsite_health_evidence\b",
    r"(?i)\bcommand=/home/ai/(?:wrappers/)?ai-",
    r"(?i)\bccewrap\[\d+\]:\s+running\s+\(/bin/(?:cat|grep)\)",
    r"(?i)\bsudo\[\d+\]:\s+blueonyx_ai\b",
    r"(?i)\bcced-api\[\d+\]:\s+\[IP:\s+127\.0\.0\.1\]\s+\[User:\s+admin\]\s+>>\s+Sending command:",
    r"(?i)\bcced-api\[\d+\]:\s+\[IP:\s+127\.0\.0\.1\]\s+\[User:\s+admin\]\s+CMD:\s+\".*\"\s+=>\s+201 OK",
    r"(?i)\bRequirement already satisfied\b",
)


def _run_command(cmd: list[str], timeout: int = 30) -> str:
    proc = subprocess.run(
        cmd,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        timeout=timeout,
        check=False,
    )
    if proc.returncode != 0:
        raise RuntimeError(proc.stderr.strip() or f"exit {proc.returncode}")
    return proc.stdout.strip()


def _run_sudo_command(cmd: list[str], timeout: int = 30) -> str:
    return _run_command(["sudo", "-n", *cmd], timeout=timeout)


def _is_ai_noise_line(line: str) -> bool:
    return any(re.search(pattern, line or "") for pattern in AI_NOISE_PATTERNS)


def _collect_vsite_rows() -> list[dict[str, str]]:
    try:
        output = _run_sudo_command(["/usr/sausalito/sbin/vsite_list.pl"], timeout=30)
    except Exception as exc:
        logger.warning("failed to read vsite list: %s", exc)
        return []

    rows: list[dict[str, str]] = []
    for line in output.splitlines():
        line = line.strip()
        if not line:
            continue
        m = re.match(r"^(\S+)\s+(\S+)\s+(\S+)\s+(.*)$", line)
        if not m:
            continue
        rows.append(
            {
                "name": m.group(1),
                "fqdn": m.group(2),
                "ipaddr": m.group(3),
                "php": m.group(4).strip(),
            }
        )
    return rows


def _collect_site_lookup() -> dict[str, dict[str, str]]:
    lookup: dict[str, dict[str, str]] = {}
    for row in _collect_vsite_rows():
        name = row.get("name", "").strip()
        fqdn = row.get("fqdn", "").strip()
        if name:
            lookup[name.lower()] = row
        if fqdn:
            lookup[fqdn.lower()] = row
            host = fqdn.split(".", 1)[0].lower()
            lookup[host] = row
    return lookup


def _resolve_site_identifier(identifier: str) -> dict[str, str] | None:
    candidate = (identifier or "").strip().lower()
    if not candidate:
        return None

    lookup = _collect_site_lookup()
    if candidate in lookup:
        return lookup[candidate]

    if candidate.startswith("/home/sites/"):
        candidate = candidate.rstrip("/").split("/", 3)[-1]
        if candidate in lookup:
            return lookup[candidate]

    match = re.search(r"([A-Za-z0-9][A-Za-z0-9._-]*(?:\.[A-Za-z0-9][A-Za-z0-9._-]*)+)", candidate)
    if match and match.group(1).lower() in lookup:
        return lookup[match.group(1).lower()]
    return None


def _format_vsite_inventory(rows: list[dict[str, str]], detail: str = "domains") -> str:
    if not rows:
        return "No Vsites found on this server."

    sorted_rows = sorted(
        rows,
        key=lambda row: (
            str(row.get("fqdn", "") or "").lower(),
            str(row.get("name", "") or "").lower(),
        ),
    )

    if detail == "full":
        lines = [f"Vsite inventory: {len(sorted_rows)} Vsite(s)"]
        for row in sorted_rows:
            fqdn = str(row.get("fqdn", "") or "?").strip() or "?"
            name = str(row.get("name", "") or "?").strip() or "?"
            ipaddr = str(row.get("ipaddr", "") or "?").strip() or "?"
            php = str(row.get("php", "") or "?").strip() or "?"
            lines.append(f"- {fqdn} (name={name}, ip={ipaddr}, php={php})")
        return "\n".join(lines)

    if detail == "names":
        lines = [f"Vsite names: {len(sorted_rows)} Vsite(s)"]
        for row in sorted_rows:
            name = str(row.get("name", "") or "?").strip() or "?"
            fqdn = str(row.get("fqdn", "") or "?").strip() or "?"
            lines.append(f"- {name} ({fqdn})")
        return "\n".join(lines)

    lines = [f"Vsite domains: {len(sorted_rows)} Vsite(s)"]
    for row in sorted_rows:
        fqdn = str(row.get("fqdn", "") or "?").strip() or "?"
        lines.append(f"- {fqdn}")
    return "\n".join(lines)


def _human_gib(mebibytes: float) -> str:
    return f"{(mebibytes / 1024.0):.1f}G"


def _collect_disk_overview() -> dict[str, Any]:
    try:
        output = _run_command(["df", "-hP"], timeout=15)
    except Exception as exc:
        return {"status": "warn", "error": str(exc), "mounts": []}

    rows: list[dict[str, Any]] = []
    for line in output.splitlines()[1:]:
        parts = line.split()
        if len(parts) < 6:
            continue
        try:
            pct_num = int(parts[4].rstrip("%"))
        except ValueError:
            pct_num = -1
        rows.append(
            {
                "filesystem": parts[0],
                "size": parts[1],
                "used": parts[2],
                "avail": parts[3],
                "use_pct": parts[4],
                "pct_num": pct_num,
                "mount": " ".join(parts[5:]),
            }
        )

    if not rows:
        return {"status": "warn", "error": "no filesystems parsed", "mounts": []}

    top = sorted(rows, key=lambda row: row["pct_num"], reverse=True)
    highest = top[0]["pct_num"]
    status = "ok"
    if highest >= 90:
        status = "bad"
    elif highest >= 80:
        status = "warn"

    return {
        "status": status,
        "mounts": top,
        "highest_pct": highest,
    }


def _collect_memory_overview() -> dict[str, Any]:
    try:
        output = _run_command(["free", "-m"], timeout=10)
    except Exception as exc:
        return {"status": "warn", "error": str(exc)}

    mem_line = ""
    swap_line = ""
    for line in output.splitlines():
        stripped = line.strip()
        if stripped.startswith("Mem:"):
            mem_line = stripped
        elif stripped.startswith("Swap:"):
            swap_line = stripped

    if not mem_line:
        return {"status": "warn", "error": "no memory row parsed"}

    mem_parts = mem_line.split()
    swap_parts = swap_line.split() if swap_line else ["Swap:", "0", "0", "0"]
    try:
        total_mib = float(mem_parts[1])
        used_mib = float(mem_parts[2])
        available_mib = float(mem_parts[6]) if len(mem_parts) > 6 else float(mem_parts[3])
        swap_total_mib = float(swap_parts[1]) if len(swap_parts) > 1 else 0.0
        swap_used_mib = float(swap_parts[2]) if len(swap_parts) > 2 else 0.0
    except (ValueError, IndexError) as exc:
        return {"status": "warn", "error": f"failed to parse memory output: {exc}"}

    mem_pct = int(round((used_mib / total_mib) * 100)) if total_mib > 0 else 0
    status = "ok"
    if mem_pct >= 90 or (swap_total_mib > 0 and swap_used_mib > 0):
        status = "bad" if mem_pct >= 95 else "warn"

    return {
        "status": status,
        "total_mib": total_mib,
        "used_mib": used_mib,
        "available_mib": available_mib,
        "mem_pct": mem_pct,
        "swap_total_mib": swap_total_mib,
        "swap_used_mib": swap_used_mib,
    }


def _collect_load_overview() -> dict[str, Any]:
    try:
        load1, load5, load15 = os.getloadavg()
        return {"status": "ok", "load1": load1, "load5": load5, "load15": load15}
    except Exception as exc:
        return {"status": "warn", "error": str(exc)}


def _collect_blueonyx_service_intent() -> dict[str, Any]:
    try:
        output = _run_sudo_command([ACTIVE_MONITOR_HELPER], timeout=30)
        data = json.loads(output)
        if isinstance(data, dict):
            return data
    except Exception as exc:
        logger.warning("failed to read BlueOnyx service intent: %s", exc)
        return {"error": str(exc), "system": {}, "active_monitor": {}}
    return {"error": "invalid BlueOnyx service intent response", "system": {}, "active_monitor": {}}


def _systemctl_state(service: str) -> str:
    try:
        output = _run_command(["systemctl", "is-active", service], timeout=10).strip()
        return output if output else "unknown"
    except Exception as exc:
        return f"error: {exc}"


def _as_enabled(value: Any) -> bool:
    return str(value).strip() in {"1", "true", "True", "yes", "on"}


def _am_status(enabled: bool, current_state: str) -> str:
    if not enabled:
        return "disabled"
    code = str(current_state or "").strip().upper()
    if not code:
        return "warn"
    if code == "G":
        return "ok"
    if code in {"Y", "O", "I"}:
        return "warn"
    if code in {"R", "B", "E", "F"}:
        return "bad"
    return "warn"


def _merge_service_status(am_status: str, unit_state: str | None) -> str:
    if am_status == "disabled":
        return "disabled"
    if unit_state is None:
        return am_status
    if unit_state != "active":
        return "bad"
    if am_status == "bad":
        return "bad"
    if am_status == "warn":
        return "warn"
    return "ok"


def _collect_service_overview() -> dict[str, Any]:
    intent = _collect_blueonyx_service_intent()
    system = intent.get("system") or {}
    am = intent.get("active_monitor") or {}

    service_defs = [
        {
            "key": "web",
            "label": "web",
            "enabled": True,
            "am_state": "",
            "use_am": False,
            "unit_states": {
                "httpd": _systemctl_state("httpd"),
                "php-fpm": _systemctl_state("php-fpm"),
            },
        },
        {
            "key": "email",
            "label": "email",
            "enabled": _as_enabled((am.get("Email") or {}).get("enabled")) or _as_enabled((system.get("Email") or {}).get("enabled")),
            "am_state": str((am.get("Email") or {}).get("currentState", "")),
            "use_am": True,
            "unit_states": {},
        },
        {
            "key": "ftp",
            "label": "ftp",
            "enabled": _as_enabled((am.get("FTP") or {}).get("enabled")) or _as_enabled((system.get("Ftp") or {}).get("enabled")),
            "am_state": str((am.get("FTP") or {}).get("currentState", "")),
            "use_am": True,
            "unit_states": {"proftpd": _systemctl_state("proftpd")},
        },
        {
            "key": "mysql",
            "label": "mysql",
            "enabled": _as_enabled((am.get("mysql") or {}).get("enabled")),
            "am_state": str((am.get("mysql") or {}).get("currentState", "")),
            "use_am": True,
            "unit_states": {"mariadb": _systemctl_state("mariadb")},
        },
        {
            "key": "dns",
            "label": "dns",
            "enabled": _as_enabled((am.get("DNS") or {}).get("enabled")) or _as_enabled((system.get("DNS") or {}).get("enabled")),
            "am_state": str((am.get("DNS") or {}).get("currentState", "")),
            "use_am": True,
            "unit_states": {"named-chroot": _systemctl_state("named-chroot")},
        },
        {
            "key": "ssh",
            "label": "ssh",
            "enabled": _as_enabled((am.get("SSH") or {}).get("enabled")) or _as_enabled((system.get("SSH") or {}).get("enabled")),
            "am_state": str((am.get("SSH") or {}).get("currentState", "")),
            "use_am": True,
            "unit_states": {"sshd": _systemctl_state("sshd")},
        },
        {
            "key": "fail2ban",
            "label": "fail2ban",
            "enabled": True,
            "am_state": "",
            "use_am": False,
            "unit_states": {"fail2ban": _systemctl_state("fail2ban")},
        },
    ]

    services: list[dict[str, Any]] = []
    bad_count = 0
    warn_count = 0

    for item in service_defs:
        enabled = bool(item["enabled"])
        am_state = str(item.get("am_state", ""))
        am_state_code = am_state.strip().upper()
        use_am = bool(item.get("use_am", True))
        am_service_status = _am_status(enabled, am_state) if use_am else ("ok" if enabled else "disabled")
        unit_states = item.get("unit_states") or {}
        unit_status = None
        if enabled and unit_states:
            unit_status = "ok"
            for state in unit_states.values():
                if state != "active":
                    unit_status = "bad"
                    break
        final_status = _merge_service_status(am_service_status, None if unit_status is None else ("active" if unit_status == "ok" else "inactive"))
        if final_status == "bad":
            bad_count += 1
        elif final_status == "warn":
            warn_count += 1
        services.append(
            {
                "service": item["label"],
                "enabled": enabled,
                "status": final_status,
                "am_state": am_state_code,
                "unit_states": unit_states,
            }
        )

    status = "ok"
    if bad_count:
        status = "bad"
    elif warn_count:
        status = "warn"

    return {
        "status": status,
        "services": services,
        "bad_count": bad_count,
        "warn_count": warn_count,
        "intent_error": intent.get("error"),
    }


def _collect_ssl_overview() -> dict[str, Any]:
    try:
        data = _run_helper(SSL_HELPER)
    except Exception as exc:
        return {"status": "warn", "error": str(exc), "total_vsites": 0, "valid_vsites": 0}

    total_vsites = 0
    valid_vsites = 0
    issue_vsites = 0
    missing_vsites = 0

    for site in data.get("vsites", []):
        if "error" in site:
            continue
        total_vsites += 1
        health = site.get("health") or {}
        cert = health.get("cert") or {}
        if cert.get("present"):
            valid_vsites += 1
        else:
            missing_vsites += 1
        if health.get("status") not in {"ok", "healthy"}:
            issue_vsites += 1

    adm = data.get("admserv") or {}
    adm_status = str(adm.get("status", "unknown") or "unknown")
    status = "ok"
    if adm_status not in {"ok", "healthy"} or issue_vsites > 0:
        status = "warn"

    return {
        "status": status,
        "admserv_status": adm_status,
        "total_vsites": total_vsites,
        "valid_vsites": valid_vsites,
        "missing_vsites": missing_vsites,
        "issue_vsites": issue_vsites,
    }


def _collect_server_health_summary() -> dict[str, Any]:
    disk = _collect_disk_overview()
    memory = _collect_memory_overview()
    load = _collect_load_overview()
    services = _collect_service_overview()
    ssl = _collect_ssl_overview()
    web_owner = _collect_web_owner_health()
    vsite_rows = _collect_vsite_rows()

    status = "ok"
    for section in (disk, memory, services, ssl):
        if section.get("status") == "bad":
            status = "bad"
            break
        if section.get("status") == "warn":
            status = "warn"

    return {
        "status": status,
        "disk": disk,
        "memory": memory,
        "load": load,
        "services": services,
        "ssl": ssl,
        "web_owner": web_owner,
        "vsites": vsite_rows,
    }


def _format_server_health_summary(data: dict[str, Any]) -> str:
    disk = data.get("disk") or {}
    memory = data.get("memory") or {}
    load = data.get("load") or {}
    services = data.get("services") or {}
    ssl = data.get("ssl") or {}
    web_owner = data.get("web_owner") or {}
    vsites = data.get("vsites") or []

    lines = [f"Server health: {data.get('status', 'unknown')}"]

    if disk.get("mounts"):
        top = disk["mounts"][:3]
        lines.append("Disk:")
        for row in top:
            lines.append(f"- {row['mount']}: {row['use_pct']} used ({row['avail']} free of {row['size']})")
    elif disk.get("error"):
        lines.append(f"Disk: {disk['error']}")

    if memory.get("total_mib"):
        lines.append(
            "Memory: "
            f"{_human_gib(memory['used_mib'])} used / {_human_gib(memory['total_mib'])} total "
            f"({memory['mem_pct']}%), available {_human_gib(memory['available_mib'])}, "
            f"swap {_human_gib(memory['swap_used_mib'])} used / {_human_gib(memory['swap_total_mib'])} total"
        )
    elif memory.get("error"):
        lines.append(f"Memory: {memory['error']}")

    if "load1" in load:
        lines.append(
            f"Load: {load['load1']:.2f}, {load['load5']:.2f}, {load['load15']:.2f}"
        )

    lines.append(f"Vsites: {len(vsites)} total")

    if services.get("services"):
        healthy = [item["service"] for item in services["services"] if item.get("enabled") and item.get("status") == "ok"]
        unhealthy = []
        for item in services["services"]:
            if not item.get("enabled") or item.get("status") == "ok":
                continue
            detail = item.get("status", "unknown")
            units = item.get("unit_states") or {}
            down_units = [name for name, state in units.items() if state != "active"]
            if down_units:
                detail = f"{detail} ({', '.join(down_units)} not active)"
            elif item.get("am_state"):
                detail = f"{detail} (Active Monitor state {item['am_state']})"
            unhealthy.append(f"{item['service']}={detail}")
        if healthy:
            lines.append(f"Managed services healthy: {', '.join(healthy)}")
        if unhealthy:
            lines.append(f"Managed services with issues: {', '.join(unhealthy)}")
        if services.get("intent_error"):
            lines.append(f"BlueOnyx service intent: {services['intent_error']}")

    if "total_vsites" in ssl:
        lines.append(
            f"SSL coverage: {ssl.get('valid_vsites', 0)} of {ssl.get('total_vsites', 0)} Vsites have certificates"
        )
        lines.append(f"AdmServ SSL: {ssl.get('admserv_status', 'unknown')}")
        if ssl.get("missing_vsites", 0):
            lines.append(f"Vsites without certificates: {ssl['missing_vsites']}")

    web_summary = web_owner.get("summary") or {}
    if web_summary:
        lines.append(
            "Web ownership: "
            f"{web_summary.get('ok', 0)} ok, {web_summary.get('bad', 0)} bad, "
            f"{web_summary.get('warn', 0)} warn, {web_summary.get('missing', 0)} missing"
        )

    lines.append("This summary is deterministic and based on BlueOnyx service intent, live resource checks, Vsite inventory, SSL helper output, and /web ownership inspection.")
    return "\n".join(lines)


def _site_webdir(site_row: dict[str, str]) -> Path:
    fqdn = site_row.get("fqdn", "").strip()
    if fqdn:
        symlink = Path("/home/sites") / fqdn
        if symlink.exists():
            try:
                return symlink.resolve(strict=True) / "wwwroot" / "web"
            except Exception:
                pass
    return Path("/home/.sites") / site_row.get("name", "") / "wwwroot" / "web"


def _iter_log_files(include_rotated_logs: bool = True) -> dict[str, list[Path]]:
    categories: dict[str, list[Path]] = {
        "messages": [],
        "secure": [],
        "mail": [],
        "web": [],
        "ssh": [],
        "audit": [],
    }
    for pattern in COMMON_LOG_GLOBS:
        for raw in sorted(glob.glob(pattern)):
            path = Path(raw)
            if not include_rotated_logs and path.suffix == ".gz":
                continue
            if "/messages" in pattern:
                categories["messages"].append(path)
            elif "/secure" in pattern:
                categories["secure"].append(path)
            elif "/maillog" in pattern:
                categories["mail"].append(path)
            elif "/httpd/" in pattern:
                categories["web"].append(path)
            elif "/sshd" in pattern:
                categories["ssh"].append(path)
            elif "/audit/" in pattern:
                categories["audit"].append(path)
    return categories


def _read_log_lines(path: Path) -> list[str]:
    try:
        if path.suffix == ".gz":
            with gzip.open(path, "rt", encoding="utf-8", errors="replace") as fh:
                return [line.rstrip("\n") for line in fh]
        with path.open("r", encoding="utf-8", errors="replace") as fh:
            return [line.rstrip("\n") for line in fh]
    except Exception as exc:
        logger.debug("failed to read log file %s: %s", path, exc)
        return []


def _collect_site_log_evidence(
    site_row: dict[str, str],
    *,
    include_rotated_logs: bool = True,
    limit: int = 24,
) -> dict[str, Any]:
    terms = [
        site_row.get("fqdn", ""),
        site_row.get("name", ""),
    ]
    webdir = _site_webdir(site_row)
    terms.append(str(webdir))
    terms = [term.strip().lower() for term in terms if term and term.strip()]
    if not terms:
        return {"status": "warn", "current": [], "rotated": [], "issues": ["no site terms available"]}

    categories = _iter_log_files(include_rotated_logs=include_rotated_logs)
    current_hits: list[str] = []
    rotated_hits: list[str] = []
    matched_files = 0
    pattern = re.compile("|".join(re.escape(term) for term in terms), re.IGNORECASE)

    for label, files in categories.items():
        for path in files:
            if len(current_hits) + len(rotated_hits) >= limit:
                break
            lines = _read_log_lines(path)
            if not lines:
                continue
            matched = False
            for idx, line in enumerate(lines, 1):
                if not pattern.search(line):
                    continue
                matched = True
                sample = f"{path}:{idx}: {line.strip()}"
                if path.suffix == ".gz":
                    rotated_hits.append(sample)
                else:
                    current_hits.append(sample)
                if len(current_hits) + len(rotated_hits) >= limit:
                    break
            if matched:
                matched_files += 1
        if len(current_hits) + len(rotated_hits) >= limit:
            break

    severity_hits = []
    severity_pattern = re.compile(r"(?i)\b(error|failed|failure|fatal|denied|timeout|refused|certificate|ssl|php[- ]?fpm|panic|segfault|broken)\b")
    for hit in current_hits + rotated_hits:
        if severity_pattern.search(hit):
            severity_hits.append(hit)

    status = "ok"
    issues: list[str] = []
    if current_hits or rotated_hits:
        status = "warn"
        issues.append(f"central logs mention the site in {matched_files} file(s)")
    if severity_hits:
        status = "bad"
        issues.append("central logs contain error/failure evidence for the site")

    return {
        "status": status,
        "current": current_hits,
        "rotated": rotated_hits,
        "issues": issues,
        "matched_files": matched_files,
    }


def _collect_site_quota(site_name: str, webdir: Path) -> dict[str, Any]:
    result: dict[str, Any] = {
        "status": "warn",
        "used": None,
        "quota": None,
        "source": "",
        "issues": [],
    }

    try:
        output = _run_sudo_command([GET_QUOTAS, "--sites"], timeout=60)
        for line in output.splitlines():
            parts = line.split("\t")
            if len(parts) < 3:
                continue
            if parts[0].strip() != site_name:
                continue
            result["used"] = parts[1].strip()
            result["quota"] = parts[2].strip()
            result["source"] = "get_quotas.pl --sites"
            result["status"] = "ok"
            return result
    except Exception as exc:
        result["issues"].append(f"quota helper unavailable: {exc}")

    try:
        du_output = _run_command(["du", "-sh", str(webdir)], timeout=20)
        result["used"] = du_output.split()[0] if du_output else None
        result["source"] = "du -sh"
        if result["used"]:
            result["status"] = "ok"
    except Exception as exc:
        result["issues"].append(f"disk usage unavailable: {exc}")
    return result


def _search_log_files(
    pattern: str,
    files: list[Path],
    *,
    context: int = 0,
    max_matches: int = 20,
) -> list[str]:
    if not files:
        return []
    cmd = [
        "sudo",
        "-n",
        SEARCH_WRAPPER,
        "--pattern",
        pattern,
        "--context",
        str(context),
        "--max-matches",
        str(max_matches),
        *[str(path) for path in files],
    ]
    try:
        output = _run_command(cmd, timeout=60)
    except Exception as exc:
        logger.debug("site log search failed: %s", exc)
        return [f"(search failed: {exc})"]
    lines = [line.strip() for line in output.splitlines() if line.strip()]
    return lines


def _split_current_rotated(files: list[Path]) -> tuple[list[Path], list[Path]]:
    current: list[Path] = []
    rotated: list[Path] = []
    for path in files:
        if path.suffix == ".gz":
            rotated.append(path)
        else:
            current.append(path)
    return current, rotated


def _collect_site_log_summary(site_row: dict[str, str], include_rotated_logs: bool = True) -> dict[str, Any]:
    terms = [
        site_row.get("fqdn", "").strip(),
        site_row.get("name", "").strip(),
        str(_site_webdir(site_row)),
    ]
    if not any(terms):
        return {"status": "warn", "evidence": [], "issues": ["no site terms available"]}

    pattern = "|".join(re.escape(term) for term in terms if term)
    categories = _iter_log_files(include_rotated_logs=include_rotated_logs)
    evidence: list[dict[str, str]] = []
    current_total = 0
    rotated_total = 0

    for category, files in categories.items():
        if not files:
            continue
        current, rotated = _split_current_rotated(files)
        if current:
            for line in _search_log_files(pattern, current, max_matches=8):
                if _is_ai_noise_line(line):
                    continue
                evidence.append({"source": f"{category}:current", "line": line})
                current_total += 1
        if include_rotated_logs and rotated:
            for line in _search_log_files(pattern, rotated, max_matches=8):
                if _is_ai_noise_line(line):
                    continue
                evidence.append({"source": f"{category}:rotated", "line": line})
                rotated_total += 1

    severity_pattern = re.compile(
        r"(?i)\b(error|failed|failure|fatal|denied|timeout|refused|certificate(?:\s+problem)?|panic|segfault|broken)\b"
    )
    severity_hits = [item for item in evidence if severity_pattern.search(item["line"])]
    status = "ok"
    issues: list[str] = []
    if evidence:
        status = "warn"
        issues.append(f"central logs mention the site in {len(evidence)} line(s)")
    if severity_hits:
        status = "bad"
        issues.append("central logs contain error/failure evidence for the site")

    return {
        "status": status,
        "issues": issues,
        "evidence": evidence[:24],
        "current_hits": current_total,
        "rotated_hits": rotated_total,
    }


def _parse_php_version(text: str) -> str:
    match = re.search(r"(\d+\.\d+)", text or "")
    return match.group(1) if match else ""


def _pick_site_ssl_health(site_row: dict[str, str], ssl_data: dict[str, Any]) -> dict[str, Any]:
    fqdn = site_row.get("fqdn", "").strip().lower()
    name = site_row.get("name", "").strip().lower()
    host = fqdn.split(".", 1)[0] if fqdn else name
    for item in ssl_data.get("vsites", []):
        item_fqdn = str(item.get("fqdn", "")).strip().lower()
        item_name = str(item.get("name", "")).strip().lower()
        item_host = item_fqdn.split(".", 1)[0] if item_fqdn else item_name
        if fqdn and item_fqdn == fqdn:
            return item
        if name and item_name == name:
            return item
        if host and item_host == host:
            return item
    return {"health": {"status": "not_configured", "issues": ["site SSL record not found"]}}


def _pick_site_php_health(site_row: dict[str, str], php_data: dict[str, Any]) -> dict[str, Any]:
    fqdn = site_row.get("fqdn", "").strip().lower()
    name = site_row.get("name", "").strip().lower()
    host = fqdn.split(".", 1)[0] if fqdn else name
    for pool in php_data.get("pools", []):
        for site in pool.get("sites") or []:
            label = str(site.get("fqdn") or site.get("name") or "").strip().lower()
            label_host = label.split(".", 1)[0] if label else ""
            if fqdn and label == fqdn:
                return pool
            if name and label == name:
                return pool
            if host and label_host == host:
                return pool
    return {}


def _format_site_health_evidence(data: dict[str, Any]) -> str:
    site = data.get("site") or {}
    lines: list[str] = []
    lines.append(f"Site: {site.get('fqdn') or site.get('name') or '?'} ({site.get('name') or '?'})")
    lines.append(f"Verdict: {data.get('verdict', 'unknown')}")

    web = data.get("web_owner") or {}
    lines.append("Web owner:")
    lines.append(f"- status: {web.get('status', 'unknown')}")
    if web.get("webdir"):
        lines.append(f"- web: {web.get('webdir')}")
    if web.get("owner") or web.get("group"):
        lines.append(f"- owner: {web.get('owner', '?')}:{web.get('group', '?')}")
    for item in web.get("issues") or []:
        lines.append(f"- {item}")

    ssl = data.get("ssl") or {}
    if ssl:
        lines.append("SSL:")
        lines.append(f"- status: {ssl.get('status', 'unknown')}")
        if ssl.get("cert_dir"):
            lines.append(f"- cert dir: {ssl.get('cert_dir')}")
        cert = ssl.get("cert") or {}
        if cert.get("present") is not None:
            if cert.get("present"):
                if isinstance(cert.get("days_left"), int):
                    lines.append(f"- expires in {cert['days_left']} day(s)")
                if cert.get("signature_algorithm"):
                    lines.append(f"- signature algorithm: {cert['signature_algorithm']}")
            else:
                lines.append("- certificate not present")
        for item in ssl.get("issues") or []:
            lines.append(f"- {item}")

    php = data.get("php") or {}
    if php:
        lines.append("PHP:")
        lines.append(f"- status: {php.get('status', 'unknown')}")
        if php.get("service"):
            lines.append(f"- service: {php.get('service')}")
        if php.get("version"):
            lines.append(f"- version: {php.get('version')}")
        if php.get("sites"):
            site_text = ", ".join(str(s) for s in php.get("sites") if s)
            if site_text:
                lines.append(f"- assigned sites: {site_text}")
        for item in php.get("issues") or []:
            lines.append(f"- {item}")

    logs = data.get("logs") or {}
    if logs:
        lines.append("Central logs:")
        lines.append(f"- status: {logs.get('status', 'unknown')}")
        if logs.get("current_hits") is not None:
            lines.append(f"- current hits: {logs.get('current_hits')}")
        if logs.get("rotated_hits") is not None:
            lines.append(f"- rotated hits: {logs.get('rotated_hits')}")
        for item in logs.get("evidence") or []:
            lines.append(f"- [{item.get('source', 'log')}] {item.get('line', '')}")
        for item in logs.get("issues") or []:
            lines.append(f"- {item}")

    quota = data.get("quota") or {}
    if quota:
        lines.append("Quota / disk:")
        lines.append(f"- status: {quota.get('status', 'unknown')}")
        if quota.get("used") is not None:
            if quota.get("quota") is not None:
                lines.append(f"- usage: {quota.get('used')} / {quota.get('quota')}")
            else:
                lines.append(f"- usage: {quota.get('used')}")
        if quota.get("source"):
            lines.append(f"- source: {quota.get('source')}")
        for item in quota.get("issues") or []:
            lines.append(f"- {item}")

    summary = data.get("summary") or {}
    if summary:
        lines.append(
            "Summary: "
            + ", ".join(f"{key}={value}" for key, value in sorted(summary.items()))
        )
    return "\n".join(lines)


def _collect_site_health_evidence(
    site_identifier: str,
    *,
    include_rotated_logs: bool = True,
    include_quota: bool = True,
    include_php: bool = True,
    include_ssl: bool = True,
) -> dict[str, Any]:
    site_row = _resolve_site_identifier(site_identifier)
    if not site_row:
        return {
            "site": {"name": site_identifier, "fqdn": site_identifier},
            "verdict": "bad",
            "summary": {"bad": 1, "ok": 0, "warn": 0},
            "web_owner": {"status": "bad", "issues": [f"unknown Vsite: {site_identifier}"]},
        }

    webdir = _site_webdir(site_row)
    site_name = site_row.get("name", "")
    fqdn = site_row.get("fqdn", "")
    php_text = site_row.get("php", "")
    php_version = _parse_php_version(php_text)
    php_mode = "FPM" if "(FPM)" in php_text else ("suPHP" if "(suPHP)" in php_text else ("mod_ruid2" if "(mod_ruid2)" in php_text else ("DSO" if "(DSO)" in php_text else "unknown")))

    web_owner = {"status": "warn", "webdir": str(webdir), "owner": "?", "group": "?", "issues": []}
    try:
        st = webdir.stat()
        import pwd
        import grp
        web_owner["owner"] = pwd.getpwuid(st.st_uid).pw_name
        web_owner["group"] = grp.getgrgid(st.st_gid).gr_name
        if web_owner["owner"] in {"", "nobody", "apache", "root"}:
            web_owner["status"] = "bad"
            web_owner["issues"].append("owner is a system account; BlueOnyx expects a real site admin user")
        elif web_owner["group"] != site_name:
            web_owner["status"] = "warn"
            web_owner["issues"].append(f"group should normally be the Vsite group name ({site_name})")
        else:
            web_owner["status"] = "ok"
    except FileNotFoundError:
        web_owner["status"] = "bad"
        web_owner["issues"].append("web directory is missing")
    except PermissionError as exc:
        web_owner["status"] = "warn"
        web_owner["issues"].append(f"permission denied while inspecting ownership: {exc}")
    except Exception as exc:
        web_owner["status"] = "warn"
        web_owner["issues"].append(f"failed to inspect ownership: {exc}")

    ssl_section: dict[str, Any] = {"status": "not_configured", "issues": []}
    if include_ssl:
        try:
            ssl_data = _run_helper(SSL_HELPER)
            site_ssl = _pick_site_ssl_health(site_row, ssl_data)
            health = site_ssl.get("health") or {}
            cert = health.get("cert") or {}
            ssl_section = {
                "status": health.get("status", "not_configured"),
                "cert_dir": health.get("cert_dir", ""),
                "cert": cert,
                "issues": list(health.get("issues") or []),
            }
        except Exception as exc:
            ssl_section = {"status": "warn", "issues": [f"SSL helper unavailable: {exc}"]}

    php_section: dict[str, Any] = {"status": "not_needed", "issues": []}
    if include_php:
        try:
            php_data = _run_helper(PHPFPM_HELPER)
            pool = _pick_site_php_health(site_row, php_data)
            if pool:
                php_section = {
                    "status": pool.get("status", "unknown"),
                    "service": pool.get("service", ""),
                    "version": pool.get("version", php_version),
                    "state": pool.get("state", ""),
                    "sites": [str(site.get("fqdn") or site.get("name") or "") for site in (pool.get("sites") or [])],
                    "issues": list(pool.get("issues") or []),
                }
            else:
                php_section = {
                    "status": "ok",
                    "service": "php-fpm",
                    "version": php_version,
                    "state": "not_required",
                    "sites": [],
                    "issues": [] if php_mode != "FPM" else ["site uses FPM but no matching pool was found"],
                }
                if php_mode == "FPM":
                    php_section["status"] = "bad"
        except Exception as exc:
            php_section = {"status": "warn", "issues": [f"PHP-FPM helper unavailable: {exc}"]}

    logs_section = _collect_site_log_summary(site_row, include_rotated_logs=include_rotated_logs)

    quota_section: dict[str, Any] = {"status": "not_checked", "issues": []}
    if include_quota:
        quota_section = _collect_site_quota(site_name, webdir)

    verdict_rank = {"ok": 0, "not_needed": 0, "not_checked": 0, "warn": 1, "bad": 2, "missing": 2, "not_configured": 0, "unknown": 1}
    overall = 0
    for section in (web_owner, ssl_section, php_section, logs_section, quota_section):
        status = str(section.get("status", "ok"))
        overall = max(overall, verdict_rank.get(status, 1))
    if overall >= 2:
        verdict = "bad"
    elif overall == 1:
        verdict = "warn"
    else:
        verdict = "ok"

    summary = {
        "ok": sum(1 for status in (web_owner.get("status"), ssl_section.get("status"), php_section.get("status"), logs_section.get("status"), quota_section.get("status")) if status in {"ok", "not_needed", "not_checked", "not_configured"}),
        "warn": sum(1 for status in (web_owner.get("status"), ssl_section.get("status"), php_section.get("status"), logs_section.get("status"), quota_section.get("status")) if status == "warn"),
        "bad": sum(1 for status in (web_owner.get("status"), ssl_section.get("status"), php_section.get("status"), logs_section.get("status"), quota_section.get("status")) if status == "bad"),
    }

    if verdict == "bad":
        if web_owner.get("status") == "bad":
            summary["next_step"] = "fix_web_owner"
        elif ssl_section.get("status") == "bad":
            summary["next_step"] = "fix_ssl"
        elif php_section.get("status") == "bad":
            summary["next_step"] = "fix_php_fpm"
        elif logs_section.get("status") == "bad":
            summary["next_step"] = "inspect_logs"

    return {
        "site": {
            "name": site_name,
            "fqdn": fqdn,
            "php": php_text,
            "php_mode": php_mode,
            "php_version": php_version,
            "webdir": str(webdir),
        },
        "web_owner": web_owner,
        "ssl": ssl_section,
        "php": php_section,
        "logs": logs_section,
        "quota": quota_section,
        "verdict": verdict,
        "summary": summary,
    }


def _expand_log_files() -> dict[str, list[str]]:
    groups = {
        "messages": [],
        "secure": [],
        "mail": [],
        "web": [],
        "ssh": [],
        "audit": [],
    }
    for pattern in COMMON_LOG_GLOBS:
        files = sorted(glob.glob(pattern))
        if "/messages" in pattern:
            groups["messages"].extend(files)
        elif "/secure" in pattern:
            groups["secure"].extend(files)
        elif "/maillog" in pattern:
            groups["mail"].extend(files)
        elif "/httpd/" in pattern:
            groups["web"].extend(files)
        elif "/sshd" in pattern:
            groups["ssh"].extend(files)
        elif "/audit/" in pattern:
            groups["audit"].extend(files)
    return groups


def _parse_timestamp(line: str) -> datetime | None:
    line = line.strip()
    if not line:
        return None

    m = re.match(r"^(?P<mon>[A-Z][a-z]{2})\s+(?P<day>\d{1,2})\s+(?P<h>\d{2}):(?P<m>\d{2}):(?P<s>\d{2})", line)
    if m:
        month = datetime.strptime(m.group("mon"), "%b").month
        now = datetime.now()
        year = now.year
        dt = datetime(year, month, int(m.group("day")), int(m.group("h")), int(m.group("m")), int(m.group("s")))
        if dt > now.replace(year=year) and (dt - now).days > 1:
            dt = dt.replace(year=year - 1)
        return dt

    m = re.match(r"^(?P<y>\d{4})-(?P<mo>\d{2})-(?P<d>\d{2})[ T](?P<h>\d{2}):(?P<mi>\d{2}):(?P<s>\d{2})", line)
    if m:
        return datetime(
            int(m.group("y")),
            int(m.group("mo")),
            int(m.group("d")),
            int(m.group("h")),
            int(m.group("mi")),
            int(m.group("s")),
        )

    return None


def _collect_matches(label: str, files: list[str], pattern: str, context: int = 0, max_matches: int = 200) -> list[dict[str, Any]]:
    if not files:
        return []
    cmd = [
        "sudo",
        "-n",
        SEARCH_WRAPPER,
        "--pattern",
        pattern,
        "--context",
        str(context),
        "--max-matches",
        str(max_matches),
        *files,
    ]
    try:
        output = _run_command(cmd, timeout=45)
    except Exception as exc:
        logger.warning("timeline log search failed for %s: %s", label, exc)
        return [{"source": label, "error": str(exc)}]

    events: list[dict[str, Any]] = []
    for line in output.splitlines():
        ts = _parse_timestamp(line)
        if ts is None:
            continue
        events.append({"source": label, "timestamp": ts, "line": line.strip()})
    return events


def _collect_journal_lines(since: str, until: str, limit: int = 300) -> list[dict[str, Any]]:
    cmd = [
        "sudo",
        "-n",
        JOURNAL_WRAPPER,
        "--since",
        since,
        "--until",
        until,
        "--lines",
        str(limit),
    ]
    try:
        output = _run_command(cmd, timeout=45)
    except Exception as exc:
        return [{"source": "journal", "error": str(exc)}]

    events: list[dict[str, Any]] = []
    for line in output.splitlines():
        ts = _parse_timestamp(line)
        if ts is None:
            continue
        lower = line.lower()
        if not any(re.search(pattern, lower) for pattern in TIMELINE_PATTERNS.values()):
            continue
        events.append({"source": "journal", "timestamp": ts, "line": line.strip()})
    return events


def _sort_events(events: list[dict[str, Any]]) -> list[dict[str, Any]]:
    filtered = [event for event in events if "timestamp" in event]
    filtered.sort(key=lambda item: item["timestamp"])
    return filtered


def _format_timeline(events: list[dict[str, Any]], limit: int) -> str:
    if not events:
        return "Incident timeline: no matching events found."

    lines = [f"Incident timeline: {min(len(events), limit)} event(s)"]
    for event in events[:limit]:
        stamp = event["timestamp"].strftime("%Y-%m-%d %H:%M:%S")
        lines.append(f"- {stamp} [{event['source']}] {event['line']}")
    if len(events) > limit:
        lines.append(f"... {len(events) - limit} more event(s) omitted")
    return "\n".join(lines)


def _run_helper(script: str) -> dict[str, Any]:
    output = _run_sudo_command([script], timeout=60)
    return json.loads(output)


def _format_ssl_health(data: dict[str, Any]) -> str:
    lines: list[str] = []
    adm = data.get("admserv") or {}
    lines.append(
        f"AdmServ SSL: {adm.get('status', 'unknown')} "
        f"({adm.get('cert_dir', '/etc/admserv/certs')})"
    )
    if adm.get("issues"):
        lines.extend(f"- {item}" for item in adm["issues"])
    cert = adm.get("cert") or {}
    if cert.get("present"):
        days_left = cert.get("days_left")
        if isinstance(days_left, int):
            lines.append(f"- expires in {days_left} day(s)")
        if cert.get("signature_algorithm"):
            lines.append(f"- signature algorithm: {cert['signature_algorithm']}")

    for site in data.get("vsites", []):
        if "error" in site:
            lines.append(f"- Vsite inventory error: {site['error']}")
            continue
        health = site.get("health") or {}
        lines.append(
            f"Vsite {site.get('fqdn', site.get('name', '?'))}: {health.get('status', 'unknown')}"
        )
        if health.get("issues"):
            lines.extend(f"  - {item}" for item in health["issues"])
        cert = health.get("cert") or {}
        if cert.get("present"):
            days_left = cert.get("days_left")
            if isinstance(days_left, int):
                lines.append(f"  - expires in {days_left} day(s)")

    summary = data.get("summary") or {}
    lines.append(
        "Summary: "
        + ", ".join(f"{key}={value}" for key, value in sorted(summary.items()))
    )
    return "\n".join(lines)


def _format_php_fpm_health(data: dict[str, Any]) -> str:
    lines: list[str] = []
    master = data.get("master") or {}
    lines.append(f"php-fpm master: {master.get('status', 'unknown')} ({master.get('state', 'unknown')})")
    if master.get("issues"):
        lines.extend(f"- {item}" for item in master["issues"])

    for pool in data.get("pools", []):
        sites = pool.get("sites") or []
        site_text = ", ".join(site.get("fqdn") or site.get("name") for site in sites) if sites else "unused"
        lines.append(
            f"{pool.get('service')}: {pool.get('status', 'unknown')} "
            f"({pool.get('state', 'unknown')}; sites: {site_text})"
        )
        if pool.get("issues"):
            lines.extend(f"- {item}" for item in pool["issues"])

    summary = data.get("summary") or {}
    lines.append(
        "Summary: "
        + ", ".join(f"{key}={value}" for key, value in sorted(summary.items()))
    )
    return "\n".join(lines)


def _fmt_owner(uid: int) -> str:
    try:
        import pwd
        return pwd.getpwuid(uid).pw_name
    except Exception:
        return str(uid)


def _fmt_group(gid: int) -> str:
    try:
        import grp
        return grp.getgrgid(gid).gr_name
    except Exception:
        return str(gid)


def _is_system_web_owner(owner: str) -> bool:
    owner = (owner or "").strip().lower()
    return owner in {"", "nobody", "apache", "root"}


def _format_web_owner_health(data: dict[str, Any]) -> str:
    lines: list[str] = []
    sites = data.get("sites") or []
    lines.append(f"Web owner health: {len(sites)} Vsite(s) checked")

    for site in sites:
        name = site.get("name", "?")
        fqdn = site.get("fqdn") or name
        status = site.get("status", "unknown")
        webdir = site.get("webdir", "")
        owner = site.get("owner", "?")
        group = site.get("group", "?")
        lines.append(f"Vsite {fqdn} ({name}): {status}")
        if webdir:
            lines.append(f"  - web: {webdir}")
        lines.append(f"  - owner: {owner}:{group}")
        for item in site.get("issues", []):
            lines.append(f"  - {item}")

    summary = data.get("summary") or {}
    lines.append(
        "Summary: "
        + ", ".join(f"{key}={value}" for key, value in sorted(summary.items()))
    )
    recommended_fix = str(summary.get("recommended_fix", "") or "").strip()
    if recommended_fix == "gui_per_site":
        lines.append(
            "Repair: this looks site-specific. Update the affected Vsite's PHP settings in the GUI so its preferred site admin is a real site admin, then save and let BlueOnyx regenerate the site."
        )
    elif recommended_fix == "set_web_owner.pl":
        lines.append(
            f"Repair: this looks systemic. Run {WEB_OWNER_REPAIR_SCRIPT} to normalize all Vsite web owners."
        )
        lines.append(
            "If the server still needs a forced rebuild afterward, use the normal webserver regeneration path."
        )
    else:
        lines.append(
            "Repair: inspect the affected Vsite in the GUI and correct its preferred site admin before assuming a bulk repair is needed."
        )
    return "\n".join(lines)


def _collect_web_owner_health() -> dict[str, Any]:
    base = Path("/home/sites")
    sites: list[dict[str, Any]] = []
    summary = {"ok": 0, "bad": 0, "warn": 0, "missing": 0}

    if not base.exists():
        return {"sites": [], "summary": {"ok": 0, "bad": 0, "warn": 0, "missing": 0, "error": "missing /home/sites"}}

    for entry in sorted(base.iterdir(), key=lambda item: item.name):
        if not entry.is_symlink():
            continue

        fqdn = entry.name
        site_name = fqdn
        try:
            target = entry.resolve(strict=True)
            site_name = target.name
        except Exception as exc:
            sites.append({
                "name": site_name,
                "fqdn": fqdn,
                "status": "bad",
                "webdir": "",
                "owner": "?",
                "group": "?",
                "issues": [f"broken symlink: {exc}"],
            })
            summary["bad"] += 1
            continue

        name = site_name
        webdir = target / "wwwroot" / "web"
        issues: list[str] = []
        status = "ok"
        owner = "?"
        group = "?"

        try:
            st = webdir.stat()
            owner = _fmt_owner(st.st_uid)
            group = _fmt_group(st.st_gid)
            if _is_system_web_owner(owner):
                issues.append("owner is a system account; BlueOnyx expects a real site admin user")
            if group != name:
                issues.append(f"group should normally be the Vsite group name ({name})")
            if issues:
                status = "bad"
        except FileNotFoundError:
            status = "missing"
            issues.append("web directory is missing")
        except PermissionError as exc:
            status = "warn"
            issues.append(f"permission denied while inspecting ownership: {exc}")
        except Exception as exc:
            status = "warn"
            issues.append(f"failed to inspect ownership: {exc}")

        if status == "ok":
            summary["ok"] += 1
        elif status == "missing":
            summary["missing"] += 1
        elif status == "bad":
            summary["bad"] += 1
        else:
            summary["warn"] += 1

        sites.append({
            "name": name,
            "fqdn": fqdn,
            "status": status,
            "webdir": str(webdir),
            "owner": owner,
            "group": group,
            "issues": issues,
        })

    problem_count = summary["bad"] + summary["missing"] + summary["warn"]
    if problem_count:
        bad_ratio = problem_count / max(len(sites), 1)
        if problem_count == 1 or bad_ratio <= 0.25:
            summary["recommended_fix"] = "gui_per_site"
        else:
            summary["recommended_fix"] = "set_web_owner.pl"
    return {"sites": sites, "summary": summary}


def register_tools(executor):
    executor.register_tool(
        ToolDefinition(
            name="incident_timeline",
            description=(
                "Build a single chronological incident timeline from the journal and the common admin logs. "
                "Use this when the user asks what changed before a failure or wants a cross-service timeline."
            ),
            properties={
                "since": {
                    "type": "string",
                    "description": "Start time for the incident window (e.g. 'yesterday', '24 hours ago')",
                    "default": "24 hours ago",
                },
                "until": {
                    "type": "string",
                    "description": "End time for the incident window",
                    "default": "now",
                },
                "limit": {
                    "type": "integer",
                    "description": "Maximum number of timeline entries to return",
                    "default": 60,
                },
            },
            required=[],
            category="diagnostics",
        ),
        handle_incident_timeline,
    )

    executor.register_tool(
        ToolDefinition(
            name="server_health_summary",
            description=(
                "Return a deterministic overall server health summary covering core resources, key services, "
                "Vsite inventory, SSL coverage, and /web ownership health."
            ),
            properties={},
            required=[],
            category="diagnostics",
        ),
        handle_server_health_summary,
    )

    executor.register_tool(
        ToolDefinition(
            name="ssl_health",
            description=(
                "Check SSL certificate health for AdmServ and all Vsites, including expiry, missing files, "
                "and obvious chain/signature problems."
            ),
            properties={},
            required=[],
            category="diagnostics",
        ),
        handle_ssl_health,
    )

    executor.register_tool(
        ToolDefinition(
            name="php_fpm_health",
            description=(
                "Check PHP-FPM health for the master pool and for any extra PHP-FPM pools that are actually in use "
                "by Vsites. Only flag a pool as bad when it should be running and is not."
            ),
            properties={},
            required=[],
            category="diagnostics",
        ),
        handle_php_fpm_health,
    )

    executor.register_tool(
        ToolDefinition(
            name="web_owner_health",
            description=(
                "Check the ownership state of every Vsite /web directory under /home/sites and flag obvious "
                "invalid owners such as nobody, apache, or root. Use this when the user asks about valid /web "
                "owners or when web uploads/FTP/file-manager ownership looks wrong."
            ),
            properties={},
            required=[],
            category="diagnostics",
        ),
        handle_web_owner_health,
    )

    executor.register_tool(
        ToolDefinition(
            name="site_health_evidence",
            description=(
                "Collect site-scoped health evidence for a single Vsite, including /web ownership, SSL state, "
                "PHP-FPM state, central log evidence, and quota/disk usage. Use this for site-level health "
                "troubleshooting where the assistant should explain what looks healthy, degraded, or broken."
            ),
            properties={
                "site": {
                    "type": "string",
                    "description": "Vsite FQDN or internal site name (for example b2.smd.net or site3)",
                },
                "include_rotated_logs": {
                    "type": "boolean",
                    "description": "Search rotated .gz logs under /var/log/ as well as current logs",
                    "default": True,
                },
                "include_quota": {
                    "type": "boolean",
                    "description": "Include quota/disk usage evidence for the site",
                    "default": True,
                },
                "include_php": {
                    "type": "boolean",
                    "description": "Include PHP-FPM evidence for the site",
                    "default": True,
                },
                "include_ssl": {
                    "type": "boolean",
                    "description": "Include SSL certificate evidence for the site",
                    "default": True,
                },
            },
            required=["site"],
            category="diagnostics",
        ),
        handle_site_health_evidence,
    )

    executor.register_tool(
        ToolDefinition(
            name="list_vsites",
            description=(
                "List the Vsites configured on this server. Use this when the user asks which Vsites, "
                "sites, or domain names the server hosts."
            ),
            properties={
                "detail": {
                    "type": "string",
                    "description": "How much Vsite detail to return",
                    "enum": ["domains", "names", "full"],
                    "default": "domains",
                },
            },
            required=[],
            category="diagnostics",
        ),
        handle_list_vsites,
    )


async def handle_incident_timeline(args: dict[str, Any], run_as: str) -> ToolResult:
    since = str(args.get("since", "24 hours ago") or "24 hours ago").strip()
    until = str(args.get("until", "now") or "now").strip()
    limit = max(1, min(int(args.get("limit", 60) or 60), 200))

    groups = _expand_log_files()
    events: list[dict[str, Any]] = []

    for label, files in groups.items():
        if not files:
            continue
        pattern = TIMELINE_PATTERNS["service"]
        if label in {"secure", "messages"}:
            pattern = f"({TIMELINE_PATTERNS['service']}|{TIMELINE_PATTERNS['auth']})"
        elif label == "mail":
            pattern = f"({TIMELINE_PATTERNS['service']}|{TIMELINE_PATTERNS['mail']})"
        elif label == "web":
            pattern = f"({TIMELINE_PATTERNS['service']}|{TIMELINE_PATTERNS['web']})"
        elif label == "audit":
            pattern = r"(?i)\b(audit|denied|avc|apparmor|selinux)\b"
        events.extend(_collect_matches(label, files, pattern, context=0, max_matches=200))

    events.extend(_collect_journal_lines(since, until, limit=400))
    events = _sort_events(events)

    lines = _format_timeline(events, limit).splitlines()
    lines.insert(0, f"Window: since={since}, until={until}")
    return ToolResult(success=True, output="\n".join(lines))


async def handle_server_health_summary(args: dict[str, Any], run_as: str) -> ToolResult:
    data = _collect_server_health_summary()
    return ToolResult(success=True, output=_format_server_health_summary(data), data=data)


async def handle_ssl_health(args: dict[str, Any], run_as: str) -> ToolResult:
    try:
        data = _run_helper(SSL_HELPER)
    except Exception as exc:
        return ToolResult(
            success=True,
            output=(
                "SSL health check could not be completed.\n"
                f"- helper error: {exc}"
            ),
        )
    return ToolResult(success=True, output=_format_ssl_health(data))


async def handle_php_fpm_health(args: dict[str, Any], run_as: str) -> ToolResult:
    try:
        data = _run_helper(PHPFPM_HELPER)
    except Exception as exc:
        return ToolResult(
            success=True,
            output=(
                "PHP-FPM health check could not be completed.\n"
                f"- helper error: {exc}"
        ),
    )
    return ToolResult(success=True, output=_format_php_fpm_health(data))


async def handle_web_owner_health(args: dict[str, Any], run_as: str) -> ToolResult:
    data = _collect_web_owner_health()
    return ToolResult(success=True, output=_format_web_owner_health(data))


async def handle_site_health_evidence(args: dict[str, Any], run_as: str) -> ToolResult:
    site = str(args.get("site", "") or "").strip()
    include_rotated_logs = bool(args.get("include_rotated_logs", True))
    include_quota = bool(args.get("include_quota", True))
    include_php = bool(args.get("include_php", True))
    include_ssl = bool(args.get("include_ssl", True))
    data = _collect_site_health_evidence(
        site,
        include_rotated_logs=include_rotated_logs,
        include_quota=include_quota,
        include_php=include_php,
        include_ssl=include_ssl,
    )
    return ToolResult(success=True, output=_format_site_health_evidence(data))


async def handle_list_vsites(args: dict[str, Any], run_as: str) -> ToolResult:
    detail = str(args.get("detail", "domains") or "domains").strip().lower()
    if detail not in {"domains", "names", "full"}:
        detail = "domains"
    rows = _collect_vsite_rows()
    return ToolResult(success=True, output=_format_vsite_inventory(rows, detail=detail))
