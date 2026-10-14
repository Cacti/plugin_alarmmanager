#!/usr/bin/env python3
"""Read a mapped alarm from stdin; replace the print with your delivery action."""

import json
import os
import sys


def main():
    try:
        payload = json.load(sys.stdin)
    except (ValueError, UnicodeError):
        print("Invalid alarm JSON", file=sys.stderr)
        return 1

    if not isinstance(payload, dict):
        print("Alarm payload must be a JSON object", file=sys.stderr)
        return 1

    transition = os.environ.get("ALARM_TRANSITION")
    idempotency_key = os.environ.get("ALARM_IDEMPOTENCY_KEY")
    if not transition or not idempotency_key:
        print("Missing alarm delivery environment", file=sys.stderr)
        return 1

    # Use idempotency_key to deduplicate your action at the receiving system.
    # Return 75 for a temporary delivery failure; other nonzero exits fail.
    print(json.dumps({"transition": transition, "idempotency_key": idempotency_key,
                      "payload": payload}, ensure_ascii=True))
    return 0


if __name__ == "__main__":
    sys.exit(main())
