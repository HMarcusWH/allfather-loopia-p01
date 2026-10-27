#!/usr/bin/env python3
"""R5-0 test fixtures: no credentials, network, snapshots or provider mutations."""
from datetime import datetime, timedelta, timezone
import importlib.util
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location("r5_inventory", Path(__file__).with_name("r5_readonly_inventory.py"))
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

PROJECT = "project-id"
ENV = "env-id"
ROLES = {
    "database": ("allfather-staging-mariadb-data", "db-service", "/var/lib/mysql"),
    "private_artifacts": ("allfather-staging-app-storage", "app-service", "/srv/allfather/storage"),
}
NOW = datetime(2026, 10, 27, 16, 0, tzinfo=timezone.utc)


def fixture_query(missing=None, malformed=False, wrong_service=False, paginated=False):
    nodes, instances = [], {}
    for i, (role, (name, service_id, mount)) in enumerate(ROLES.items()):
        vol_id, inst_id = f"volume-{i}", f"instance-{i}"
        nodes.append({"node": {
            "id": vol_id, "name": name,
            "volumeInstances": {
                "pageInfo": {"hasNextPage": False},
                "edges": [{"node": {
                    "id": inst_id, "environmentId": ENV,
                    "serviceId": "wrong-service" if wrong_service and role == "database" else service_id,
                }}],
            },
        }})
        instances[inst_id] = {
            "detail": {"volumeInstance": {
                "id": inst_id, "mountPath": mount, "currentSizeMB": 480 if role == "database" else 256,
                "state": "READY", "volume": {"id": vol_id, "name": name},
                "serviceInstance": {"serviceName": "fixture-service"},
            }},
            "backups": {
                "volumeInstanceBackupList": [] if role == missing else [{
                    "id": f"backup-private-{i}", "name": "fixture",
                    "createdAt": NOW.isoformat(), "expiresAt": (NOW + timedelta(days=3)).isoformat(),
                    "usedMB": 9, "referencedMB": 100,
                }],
                "volumeInstanceBackupScheduleList": [],
            },
        }

    def query(query_text, variables):
        if "R5Project" in query_text:
            return {"project": {"id": PROJECT, "volumes": {
                "pageInfo": {"hasNextPage": paginated}, "edges": nodes,
            }}}
        if "R5Instance" in query_text:
            return instances[variables["id"]]["detail"]
        if "R5Backups" in query_text:
            if malformed:
                return {"volumeInstanceBackupList": None, "volumeInstanceBackupScheduleList": []}
            return instances[variables["volumeInstanceId"]]["backups"]
        raise AssertionError("Unexpected query")

    return query


class R5ReadOnlyTests(unittest.TestCase):
    def test_two_listed_backups_never_qualify_restore(self):
        summary, private = module.collect(fixture_query(), PROJECT, ENV, ROLES, NOW)
        self.assertEqual(summary["status"], "R5_BACKUP_INVENTORY_ONLY_NOT_RESTORED")
        self.assertFalse(summary["all_backup_completions_verified"])
        self.assertFalse(summary["host_native_paired_restore_verified"])
        self.assertFalse(summary["u03_permissions_granted"])
        self.assertFalse(summary["migrations_270_280_290_activated"])
        self.assertNotIn("backup-private-", str(summary))
        self.assertIn("backup-private-", str(private))
        self.assertEqual(len(summary["volumes"]), 2)

    def test_one_missing_backup_stays_hold(self):
        summary, _ = module.collect(fixture_query(missing="database"), PROJECT, ENV, ROLES, NOW)
        self.assertEqual(summary["status"], "R5_HOLD_BACKUP_NOT_LISTED_FOR_BOTH")

    def test_expired_listings_stay_hold(self):
        summary, _ = module.collect(fixture_query(), PROJECT, ENV, ROLES, NOW + timedelta(days=10))
        self.assertEqual(summary["status"], "R5_HOLD_BACKUP_NOT_LISTED_FOR_BOTH")

    def test_wrong_service_does_not_adopt_volume(self):
        with self.assertRaisesRegex(module.Hold, "VOLUME_INSTANCE_SCOPE_MISMATCH"):
            module.collect(fixture_query(wrong_service=True), PROJECT, ENV, ROLES, NOW)

    def test_malformed_provider_result_stays_hold(self):
        with self.assertRaisesRegex(module.Hold, "BACKUP_LIST_OR_SCHEDULE_UNAVAILABLE"):
            module.collect(fixture_query(malformed=True), PROJECT, ENV, ROLES, NOW)

    def test_incomplete_page_stays_hold(self):
        with self.assertRaisesRegex(module.Hold, "PAGINATION_UNHANDLED"):
            module.collect(fixture_query(paginated=True), PROJECT, ENV, ROLES, NOW)

    def test_private_file_is_new_only_and_0600(self):
        with tempfile.TemporaryDirectory() as root:
            path = Path(root) / "receipt.json"
            module.write_private(str(path), {"hold": True})
            self.assertEqual(path.stat().st_mode & 0o777, 0o600)
            with self.assertRaisesRegex(module.Hold, "PRIVATE_OUTPUT_CREATE_REFUSED"):
                module.write_private(str(path), {"faked_pass": True})

    def test_all_provider_operations_are_read_only_queries(self):
        for query in (module.PROJECT_QUERY, module.INSTANCE_QUERY, module.BACKUP_QUERY):
            self.assertTrue(query.strip().startswith("query "))
            self.assertNotIn("mutation", query.lower())
        self.assertIn("volumeInstanceBackupList", module.BACKUP_QUERY)
        self.assertIn("volumeInstanceBackupScheduleList", module.BACKUP_QUERY)


if __name__ == "__main__":
    unittest.main()
