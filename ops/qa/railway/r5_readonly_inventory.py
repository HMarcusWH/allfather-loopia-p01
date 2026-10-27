#!/usr/bin/env python3
"""Read-only Railway R5-0 volume-backup metadata collector; not a restore proof.

Uses only three documented GraphQL *queries* through the operator's existing
Railway CLI OAuth context. Never calls a mutation or accesses service variables.
"""
from __future__ import annotations

import argparse
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
from typing import Any, Callable

PROJECT_QUERY = """query R5Project($id: String!) {
  project(id: $id) {
    id
    volumes {
      pageInfo { hasNextPage }
      edges { node {
        id name
        volumeInstances {
          pageInfo { hasNextPage }
          edges { node { id environmentId serviceId } }
        }
      } }
    }
  }
}"""
INSTANCE_QUERY = """query R5Instance($id: String!) {
  volumeInstance(id: $id) {
    id mountPath currentSizeMB state
    volume { id name }
    serviceInstance { serviceName }
  }
}"""
BACKUP_QUERY = """query R5Backups($volumeInstanceId: String!) {
  volumeInstanceBackupList(volumeInstanceId: $volumeInstanceId) {
    id name createdAt expiresAt usedMB referencedMB
  }
  volumeInstanceBackupScheduleList(volumeInstanceId: $volumeInstanceId) {
    id name cron kind retentionSeconds createdAt
  }
}"""


class Hold(Exception):
    """Fail-closed inventory disposition; never a provider backup PASS."""


def checked(value: bool, code: str) -> None:
    if not value:
        raise Hold(code)


def opaque_digest(value: str) -> str:
    return hashlib.sha256(value.encode("utf-8")).hexdigest()


def utc_time(value: Any) -> datetime | None:
    if not isinstance(value, str) or not value:
        return None
    try:
        dt = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        return None
    return dt.astimezone(timezone.utc) if dt.tzinfo else None


def railway_query(query: str, variables: dict[str, str]) -> dict[str, Any]:
    checked(query.lstrip().startswith("query ") and "mutation" not in query.lower(), "MUTATION_FORBIDDEN")
    args = ["railway", "api", query, "--variables", json.dumps(variables, separators=(",", ":")), "--compact"]
    try:
        result = subprocess.run(args, capture_output=True, text=True, timeout=40, check=False)
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise Hold("RAILWAY_CLI_UNAVAILABLE_OR_TIMED_OUT") from exc
    checked(result.returncode == 0, "RAILWAY_QUERY_FAILED")
    checked(len(result.stdout) < 2_000_000, "RAILWAY_QUERY_TOO_LARGE")
    try:
        payload = json.loads(result.stdout)
    except json.JSONDecodeError as exc:
        raise Hold("RAILWAY_QUERY_NOT_JSON") from exc
    checked(isinstance(payload, dict) and not payload.get("errors"), "RAILWAY_GRAPHQL_ERRORS")
    checked(isinstance(payload.get("data"), dict), "RAILWAY_GRAPHQL_DATA_MISSING")
    return payload["data"]


def list_rows(connection: Any, code: str) -> list[dict[str, Any]]:
    checked(isinstance(connection, dict), code)
    page_info = connection.get("pageInfo")
    checked(page_info is None or isinstance(page_info, dict), "PAGINATION_INVALID")
    checked(not (page_info or {}).get("hasNextPage", False), "PAGINATION_UNHANDLED")
    edges = connection.get("edges")
    checked(isinstance(edges, list), code)
    rows = []
    for edge in edges:
        checked(isinstance(edge, dict) and isinstance(edge.get("node"), dict), code)
        rows.append(edge["node"])
    return rows


def collect(
    query_fn: Callable[[str, dict[str, str]], dict[str, Any]],
    project_id: str,
    environment_id: str,
    roles: dict[str, tuple[str, str, str]],
    now: datetime | None = None,
) -> tuple[dict[str, Any], dict[str, Any]]:
    """Return (sanitized public report, sensitive private provider metadata)."""
    now = now or datetime.now(timezone.utc)
    checked(now.tzinfo is not None, "CLOCK_NOT_UTC_AWARE")
    p = query_fn(PROJECT_QUERY, {"id": project_id})
    project = p.get("project")
    checked(isinstance(project, dict) and project.get("id") == project_id, "PROJECT_ID_MISMATCH")
    volumes = list_rows(project.get("volumes"), "PROJECT_VOLUMES_MISSING")
    public_roles: list[dict[str, Any]] = []
    private: dict[str, Any] = {"project_id": project_id, "environment_id": environment_id, "volumes": {}}
    used_instances: set[str] = set()
    for role, (volume_name, service_id, mount_path) in roles.items():
        hits = [v for v in volumes if v.get("name") == volume_name]
        checked(len(hits) == 1, "VOLUME_NAME_NOT_UNIQUE_OR_MISSING")
        v = hits[0]
        instances = list_rows(v.get("volumeInstances"), "VOLUME_INSTANCES_MISSING")
        matches = [i for i in instances if i.get("environmentId") == environment_id and i.get("serviceId") == service_id]
        checked(len(matches) == 1, "VOLUME_INSTANCE_SCOPE_MISMATCH")
        instance_id = matches[0].get("id")
        checked(isinstance(instance_id, str) and instance_id and instance_id not in used_instances, "VOLUME_INSTANCE_ID_INVALID")
        used_instances.add(instance_id)
        details = query_fn(INSTANCE_QUERY, {"id": instance_id}).get("volumeInstance")
        checked(isinstance(details, dict) and details.get("id") == instance_id, "VOLUME_INSTANCE_READBACK_INVALID")
        checked(isinstance(details.get("volume"), dict), "VOLUME_INSTANCE_PARENT_MISSING")
        checked(details["volume"].get("id") == v.get("id") and details["volume"].get("name") == volume_name, "VOLUME_INSTANCE_PARENT_MISMATCH")
        checked(details.get("mountPath") == mount_path, "VOLUME_MOUNT_MISMATCH")
        backed = query_fn(BACKUP_QUERY, {"volumeInstanceId": instance_id})
        backups = backed.get("volumeInstanceBackupList")
        schedules = backed.get("volumeInstanceBackupScheduleList")
        checked(isinstance(backups, list) and isinstance(schedules, list), "BACKUP_LIST_OR_SCHEDULE_UNAVAILABLE")
        checked(all(isinstance(b, dict) and isinstance(b.get("id"), str) and b["id"] for b in backups), "BACKUP_ID_INVALID")
        checked(len({b["id"] for b in backups}) == len(backups), "DUPLICATE_BACKUP_ID")
        # A listed, unexpired backup is *not* a demonstrated completed or restorable backup.
        usable = [b for b in backups if utc_time(b.get("createdAt")) and (e := utc_time(b.get("expiresAt"))) and e > now]
        newest = max(backups, key=lambda b: utc_time(b.get("createdAt")) or datetime.min.replace(tzinfo=timezone.utc), default=None)
        service_instance = details.get("serviceInstance")
        public_roles.append({
            "role": role,
            "volume_name": volume_name,
            "mounted_service_name": service_instance.get("serviceName") if isinstance(service_instance, dict) else None,
            "mount_path": mount_path,
            "volume_state": details.get("state"),
            "reported_capacity_mb_not_used_bytes": details.get("currentSizeMB"),
            "provider_listed_backups": len(backups),
            "provider_listed_unexpired_backups": len(usable),
            "latest_listed_created_at": newest.get("createdAt") if newest else None,
            "latest_listed_expires_at": newest.get("expiresAt") if newest else None,
            "provider_schedule_count": len(schedules),
            "provider_backup_id_sha256": sorted(opaque_digest(b["id"]) for b in backups),
            "backup_completion_verified": False,
            "physical_restore_verified": False,
        })
        private["volumes"][role] = {"volume": v, "instance": details, "backup_list": backups, "schedule_list": schedules}
    has_both = all(r["provider_listed_unexpired_backups"] > 0 for r in public_roles)
    receipt = {
        "schema_version": "r5.railway-backup-list-operator-readback.v1",
        "evidence_class": "OPERATOR_CLI_PROVIDER_QUERY_SELF_REPORTED_NOT_INDEPENDENT",
        "observed_at_utc": now.isoformat(),
        "project_id_sha256": opaque_digest(project_id),
        "environment_id_sha256": opaque_digest(environment_id),
        "status": "R5_BACKUP_INVENTORY_ONLY_NOT_RESTORED" if has_both else "R5_HOLD_BACKUP_NOT_LISTED_FOR_BOTH",
        "all_backup_completions_verified": False,
        "isolated_restoration_supported": "UNKNOWN",
        "paired_consistency_verified": False,
        "host_native_paired_restore_verified": False,
        "u03_permissions_granted": False,
        "migrations_270_280_290_activated": False,
        "volumes": public_roles,
        "note": "Provider lists are metadata, not restored bytes, proven completion, snapshot coherence or source-use authority. Confirm independently with Railway Backups and Ops."
    }
    return receipt, private


# Stable independently of process cwd: r5_readonly_inventory.py lives at
# <checkout>/ops/qa/railway/r5_readonly_inventory.py.
REPOSITORY_ROOT = Path(__file__).resolve().parents[3]


def validate_private_path(path: str) -> Path:
    """Resolve checkout/symlink paths before querying Railway or creating files."""
    checked(
        (REPOSITORY_ROOT / "PROJECT_BOUNDARY.md").is_file()
        and (REPOSITORY_ROOT / ".github").is_dir(),
        "REPOSITORY_ROOT_UNVERIFIED",
    )
    p = Path(path).expanduser().resolve(strict=False)
    checked(p != REPOSITORY_ROOT and REPOSITORY_ROOT not in p.parents, "OUTPUT_MUST_BE_OUTSIDE_REPOSITORY")
    checked("public_html" not in p.parts and ".git" not in p.parts, "OUTPUT_PATH_NOT_PRIVATE")
    checked(p.parent.is_dir(), "PRIVATE_PARENT_MISSING")
    # A world/group-accessible parent can leak a 0600 file name and ease
    # replacing paths while an operator is producing private backup metadata.
    checked(p.parent.stat().st_mode & 0o077 == 0, "PRIVATE_PARENT_PERMISSIONS")
    checked(not p.exists() and not p.is_symlink(), "PRIVATE_OUTPUT_MUST_BE_NEW")
    return p


def write_private(path: str, payload: dict[str, Any]) -> None:
    p = validate_private_path(path)
    flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL
    if hasattr(os, "O_NOFOLLOW"):
        flags |= os.O_NOFOLLOW
    created = False
    try:
        fd = os.open(p, flags, 0o600)
        created = True
        with os.fdopen(fd, "w", encoding="utf-8") as out:
            json.dump(payload, out, indent=2, sort_keys=True)
            out.write("\n")
    except (OSError, TypeError, ValueError) as exc:
        if created:
            p.unlink(missing_ok=True)
        raise Hold("PRIVATE_OUTPUT_CREATE_REFUSED") from exc


def write_pair(private_path: str, private: dict[str, Any],
               summary_path: str, summary: dict[str, Any]) -> None:
    # BOTH destinations must be admissible *before* the first write. The two
    # resolved targets may not alias one another by symlink or relative path.
    private_target = validate_private_path(private_path)
    summary_target = validate_private_path(summary_path)
    checked(private_target != summary_target, "OUTPUT_PATH_COLLISION")
    private_created = False
    try:
        write_private(str(private_target), private)
        private_created = True
        write_private(str(summary_target), summary)
    except Hold:
        # Never leave behind an apparently complete private inventory if the
        # sanitized companion could not be filed. Existing files are untouched.
        if private_created:
            private_target.unlink(missing_ok=True)
        raise

def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--project-id", required=True)
    parser.add_argument("--environment-id", required=True)
    parser.add_argument("--db-service-id", required=True)
    parser.add_argument("--app-service-id", required=True)
    parser.add_argument("--private-out", required=True, help="New private 0600 file OUTSIDE the repository; no overwrite")
    parser.add_argument("--summary-out", required=True, help="New private 0600 sanitized summary OUTSIDE the repository")
    args = parser.parse_args()
    roles = {
        "database": ("allfather-staging-mariadb-data", args.db_service_id, "/var/lib/mysql"),
        "private_artifacts": ("allfather-staging-app-storage", args.app_service_id, "/srv/allfather/storage"),
    }
    try:
        # Fail closed before contacting the provider when either destination is
        # unprotected, invalid, pre-existing or aliases another destination.
        private_target = validate_private_path(args.private_out)
        summary_target = validate_private_path(args.summary_out)
        checked(private_target != summary_target, "OUTPUT_PATH_COLLISION")
        summary, private = collect(railway_query, args.project_id, args.environment_id, roles)
        write_pair(str(private_target), private, str(summary_target), summary)
    except Hold as exc:
        print(f"R5-0 HOLD: {exc}", file=sys.stderr)
        return 2
    print(f"{summary['status']} | database + artifact backup records queried; no restore/authority claim")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
