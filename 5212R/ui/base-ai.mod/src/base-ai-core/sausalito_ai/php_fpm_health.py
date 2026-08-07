#!/usr/bin/env python3
"""BlueOnyx AI helper for PHP-FPM health checks."""

from __future__ import annotations

import json
import glob
import re
import subprocess
import sys
from pathlib import Path
from typing import Any


VLIST = "/usr/sausalito/sbin/vsite_list.pl"
SYSTEMCTL = "/usr/bin/systemctl"
PHP_ROOT = Path("/home/solarspeed")
KNOWN_PHP_VERSIONS = (
    "5.6",
    "7.0",
    "7.1",
    "7.2",
    "7.3",
    "7.4",
    "8.0",
    "8.1",
    "8.2",
    "8.3",
    "8.4",
    "8.5",
    "8.6",
    "9.0",
    "9.1",
    "9.2",
    "9.3",
    "9.4",
)


def _run(cmd: list[str], timeout: int = 20) -> str:
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


def _parse_vsites() -> list[dict[str, str]]:
    try:
        output = _run([VLIST], timeout=30)
    except Exception as exc:
        return [{"error": f"failed to read vsite list: {exc}"}]

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


def _discover_pool_sites() -> dict[str, list[str]]:
    pools: dict[str, list[str]] = {}
    for version in KNOWN_PHP_VERSIONS:
        confs = sorted(glob.glob(f"/etc/php-fpm-{version}.d/site*.conf"))
        if confs:
            pools[version] = confs
    return pools


def _collect_site_labels() -> dict[str, str]:
    """Map internal site directories (site1, site2, ...) to Vsite hostnames.

    BlueOnyx exposes the canonical Vsite names as symlinks under /home/sites,
    for example:
        /home/sites/b1.smd.net -> ../.sites/site1

    The symlink basename is the user-facing Vsite label and the resolved
    destination basename is the pool/site identifier we want to report.
    """
    labels: dict[str, str] = {}
    sites_root = Path("/home/sites")
    if not sites_root.exists():
        return labels

    for entry in sorted(sites_root.iterdir()):
        if not entry.is_symlink():
            continue
        try:
            target = entry.resolve(strict=True)
        except OSError:
            continue
        labels[target.name] = entry.name
    return labels


def _service_active(service: str) -> str:
    proc = subprocess.run(
        [SYSTEMCTL, "is-active", f"{service}.service"],
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        check=False,
    )
    state = (proc.stdout or proc.stderr or "").strip()
    return state or "unknown"


def main() -> int:
    sites = _parse_vsites()
    pool_files = _discover_pool_sites()
    site_labels = _collect_site_labels()
    output: dict[str, Any] = {
        "master": {},
        "pools": [],
    }

    master_state = _service_active("php-fpm")
    output["master"] = {
        "service": "php-fpm",
        "required": True,
        "package_present": True,
        "state": master_state,
        "status": "ok" if master_state == "active" else "bad",
        "sites": [],
        "issues": [] if master_state == "active" else ["master PHP-FPM is not active"],
    }

    pools: dict[str, dict[str, Any]] = {}
    for version, confs in pool_files.items():
        pool = pools.setdefault(
            version,
            {
                "service": f"php-fpm-{version}",
                "version": version,
                "package_present": (PHP_ROOT / f"php-{version}").is_dir(),
                "required": False,
                "sites": [],
                "pool_files": [],
            },
        )
        pool["pool_files"] = confs
        for conf in confs:
            pool_name = Path(conf).stem
            site_label = site_labels.get(pool_name, pool_name)
            pool["sites"].append(
                {
                    "name": site_label,
                    "pool": pool_name,
                    "conf": conf,
                }
            )

    for version, pool in sorted(pools.items(), key=lambda item: [int(part) for part in item[0].split(".")]):
        state = _service_active(pool["service"])
        required = bool(pool["sites"]) or bool(pool.get("pool_files"))
        pool["required"] = required
        pool["state"] = state

        issues: list[str] = []
        if required and not pool["package_present"]:
            issues.append("PHP package directory missing for required pool")
        if required and not pool.get("pool_files"):
            issues.append("missing php-fpm site config(s) for required pool")
        if required and state != "active":
            issues.append("required pool is not active")
        if not required and pool["package_present"] and state == "active":
            issues.append("pool is active but no Vsite currently needs it")

        if required and not issues:
            verdict = "ok"
        elif required and issues:
            verdict = "bad"
        elif not required and pool["package_present"]:
            verdict = "warn" if state == "active" else "not_needed"
        else:
            verdict = "not_needed"

        pool["status"] = verdict
        pool["issues"] = issues
        output["pools"].append(pool)

    counts = {"ok": 0, "warn": 0, "bad": 0, "not_needed": 0}
    for item in [output["master"]] + output["pools"]:
        counts[item["status"]] = counts.get(item["status"], 0) + 1
    output["summary"] = counts
    if any("error" in site for site in sites):
        output["site_inventory_error"] = [site["error"] for site in sites if "error" in site]

    json.dump(output, sys.stdout, indent=2, sort_keys=True)
    sys.stdout.write("\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
