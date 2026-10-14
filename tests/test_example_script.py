"""Run with: python3 -m unittest discover -s tests -p 'test_example_script.py'."""

import json
import os
from pathlib import Path
import subprocess
import sys
import unittest


class ExampleScriptTest(unittest.TestCase):
    def run_script(self, payload, environment=None):
        return subprocess.run(
            [sys.executable, str(Path(__file__).resolve().parents[1] / "examples/process_alarm.py")],
            input=payload, text=True, capture_output=True,
            env={"PATH": os.defpath, **(environment or {})}, check=False,
        )

    def test_reads_payload_and_delivery_environment(self):
        payload = {"alarm": {"summary": "Device down", "severity": "critical"}}
        result = self.run_script(json.dumps(payload), {
            "ALARM_TRANSITION": "raised", "ALARM_IDEMPOTENCY_KEY": "delivery-123",
        })
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(result.stdout), {
            "transition": "raised", "idempotency_key": "delivery-123", "payload": payload,
        })

    def test_rejects_invalid_payload(self):
        for payload in ("{", "[]", "null"):
            with self.subTest(payload=payload):
                result = self.run_script(payload)
                self.assertEqual(result.returncode, 1)
                self.assertEqual(result.stdout, "")

    def test_requires_delivery_environment(self):
        result = self.run_script("{}")
        self.assertEqual(result.returncode, 1)
        self.assertIn("Missing alarm delivery environment", result.stderr)
