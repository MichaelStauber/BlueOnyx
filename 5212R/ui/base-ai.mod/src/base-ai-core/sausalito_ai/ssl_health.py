#!/usr/bin/env python3
"""BlueOnyx AI helper for SSL certificate health checks.

Outputs JSON describing AdmServ and Vsite certificate health so the
AI service can present a concise, evidence-based summary.
"""

from __future__ import annotations

import json
import os
import re
import subprocess
import sys
import tempfile
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


VLIST = "/usr/sausalito/sbin/vsite_list.pl"
SSL_GET = "/usr/sausalito/sbin/ssl_get.pl"
CERT_ROOT = Path("/home/.sites")
ADM_CERT_DIR = Path("/etc/admserv/certs")
OPENSSL = "/usr/bin/openssl"


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


def _parse_openssl_date(value: str) -> datetime | None:
    value = value.strip()
    if "=" in value:
        value = value.split("=", 1)[1].strip()
    for fmt in ("%b %d %H:%M:%S %Y %Z", "%b %d %H:%M:%S %Y GMT"):
        try:
            dt = datetime.strptime(value, fmt)
            return dt.replace(tzinfo=timezone.utc)
        except ValueError:
            continue
    return None


def _cert_metadata(cert_path: Path) -> dict[str, Any]:
    meta: dict[str, Any] = {
        "path": str(cert_path),
        "present": cert_path.exists(),
        "valid": False,
        "subject": "",
        "issuer": "",
        "not_before": "",
        "not_after": "",
        "days_left": None,
        "signature_algorithm": "",
        "issues": [],
    }
    if not cert_path.exists():
        meta["issues"].append("missing certificate")
        return meta

    try:
        text = _run(
            [
                OPENSSL,
                "x509",
                "-in",
                str(cert_path),
                "-noout",
                "-subject",
                "-issuer",
                "-startdate",
                "-enddate",
                "-text",
            ],
            timeout=20,
        )
    except Exception as exc:
        meta["issues"].append(f"openssl parse failed: {exc}")
        return meta

    meta["valid"] = True
    for line in text.splitlines():
        line = line.strip()
        if line.startswith("subject="):
            meta["subject"] = line[len("subject=") :].strip()
        elif line.startswith("issuer="):
            meta["issuer"] = line[len("issuer=") :].strip()
        elif line.startswith("notBefore="):
            meta["not_before"] = line[len("notBefore=") :].strip()
        elif line.startswith("notAfter="):
            meta["not_after"] = line[len("notAfter=") :].strip()
        elif "Signature Algorithm:" in line and not meta["signature_algorithm"]:
            meta["signature_algorithm"] = line.split("Signature Algorithm:", 1)[1].strip()

    not_after = _parse_openssl_date(meta["not_after"])
    if not_after is not None:
        meta["days_left"] = int((not_after - datetime.now(timezone.utc)).total_seconds() // 86400)
        if meta["days_left"] is not None and meta["days_left"] < 0:
            meta["issues"].append("certificate expired")
    else:
        meta["issues"].append("could not parse expiry date")

    sig_alg = meta["signature_algorithm"].lower()
    if "sha1" in sig_alg:
        meta["issues"].append("signature algorithm uses SHA1")

    return meta


def _inspect_chain_file(chain_path: Path) -> list[dict[str, Any]]:
    if not chain_path.exists():
        return []

    text = chain_path.read_text(encoding="utf-8", errors="replace")
    blocks = re.findall(
        r"-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----",
        text,
        flags=re.S,
    )
    results: list[dict[str, Any]] = []
    with tempfile.TemporaryDirectory(prefix="ai-ssl-chain-") as tmpdir:
        tmpdir_path = Path(tmpdir)
        for idx, block in enumerate(blocks, 1):
            cert_path = tmpdir_path / f"chain-{idx}.pem"
            cert_path.write_text(block + "\n", encoding="utf-8")
            results.append(_cert_metadata(cert_path))
    return results


def _ssl_get_cert_block(site_name: str) -> Path | None:
    try:
        output = _run([SSL_GET, "cert", site_name], timeout=20)
    except Exception:
        return None

    match = re.search(
        r"-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----",
        output,
        flags=re.S,
    )
    if not match:
        return None

    tmp = tempfile.NamedTemporaryFile(prefix="ai-ssl-get-", suffix=".pem", delete=False)
    with tmp:
        tmp.write(match.group(0).encode("utf-8") + b"\n")
    return Path(tmp.name)


def _inspect_cert_dir(
    cert_dir: Path,
    *,
    required: bool,
    label: str,
    site_name: str = "",
    allow_ssl_get_fallback: bool = True,
) -> dict[str, Any]:
    result: dict[str, Any] = {
        "label": label,
        "cert_dir": str(cert_dir),
        "required": required,
        "status": "not_configured",
        "cert": {"present": False},
        "key": {"present": False},
        "chain": [],
        "issues": [],
    }

    if not cert_dir.exists():
        if site_name and allow_ssl_get_fallback:
            fallback = _ssl_get_cert_block(site_name)
            if fallback is not None:
                try:
                    cert_meta = _cert_metadata(fallback)
                    cert_meta["source"] = "ssl_get.pl"
                    result["cert"] = cert_meta
                    result["key"] = {
                        "path": str(cert_dir / "key"),
                        "present": True,
                    }
                    if cert_meta.get("issues"):
                        result["issues"].extend(cert_meta.get("issues", []))
                    days_left = cert_meta.get("days_left")
                    if required:
                        result["status"] = "bad" if result["issues"] else "ok"
                    else:
                        result["status"] = "ok" if not result["issues"] else (
                            "warn" if isinstance(days_left, int) and days_left <= 30 else "bad"
                        )
                    return result
                finally:
                    try:
                        fallback.unlink()
                    except OSError:
                        pass

        if required:
            result["status"] = "bad"
            result["issues"].append("certificate directory missing")
        else:
            result["status"] = "not_configured"
        return result

    cert_file = cert_dir / "certificate"
    key_file = cert_dir / "key"
    chain_file = cert_dir / "ca-certs"
    combined_file = cert_dir / "nginx_cert_ca_combined"

    cert_meta = _cert_metadata(cert_file)
    key_present = key_file.exists()

    if not cert_meta["present"] and site_name and allow_ssl_get_fallback:
        fallback = _ssl_get_cert_block(site_name)
        if fallback is not None:
            try:
                cert_meta = _cert_metadata(fallback)
                cert_meta["source"] = "ssl_get.pl"
                key_present = True
            finally:
                try:
                    fallback.unlink()
                except OSError:
                    pass

    result["cert"] = cert_meta
    result["key"] = {
        "path": str(key_file),
        "present": key_present,
    }
    if not key_present:
        result["issues"].append("missing private key")

    if not result["cert"]["present"]:
        result["issues"].append("missing certificate")
    else:
        result["issues"].extend(result["cert"].get("issues", []))

    result["chain"] = _inspect_chain_file(chain_file)
    for chain_cert in result["chain"]:
        result["issues"].extend(chain_cert.get("issues", []))

    if combined_file.exists():
        combined_meta = _cert_metadata(combined_file)
        if combined_meta["valid"] and combined_meta.get("days_left") is not None:
            result["combined"] = combined_meta
            result["issues"].extend(combined_meta.get("issues", []))

    days_left = result["cert"].get("days_left")
    if required:
        if result["issues"]:
            result["status"] = "bad"
        elif isinstance(days_left, int) and days_left <= 30:
            result["status"] = "warn"
            result["issues"].append("certificate expires within 30 days")
        else:
            result["status"] = "ok"
    else:
        if result["cert"]["present"] and not result["issues"]:
            result["status"] = "ok"
        elif result["cert"]["present"]:
            result["status"] = "warn" if days_left is not None and days_left <= 30 else "bad"
        else:
            result["status"] = "not_configured"

    return result


def main() -> int:
    output: dict[str, Any] = {
        "admserv": _inspect_cert_dir(ADM_CERT_DIR, required=True, label="AdmServ"),
        "vsites": [],
    }

    sites = _parse_vsites()
    for site in sites:
        if "error" in site:
            output["vsites"].append(site)
            continue
        cert_dir = CERT_ROOT / site["name"] / "wwwroot" / "certs"
        output["vsites"].append(
            {
                "name": site["name"],
                "fqdn": site["fqdn"],
                "php": site["php"],
                "health": _inspect_cert_dir(
                    cert_dir,
                    required=False,
                    label=site["fqdn"],
                    site_name=site["name"],
                    allow_ssl_get_fallback=False,
                ),
            }
        )

    # Add a small summary that the AI can quote directly.
    counts = {"ok": 0, "warn": 0, "bad": 0, "not_configured": 0}
    for bucket in [output["admserv"]] + [item["health"] for item in output["vsites"] if "health" in item]:
        counts[bucket["status"]] = counts.get(bucket["status"], 0) + 1
    output["summary"] = counts

    json.dump(output, sys.stdout, indent=2, sort_keys=True)
    sys.stdout.write("\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
