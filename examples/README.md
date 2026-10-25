# Examples

- `event.json`: sample event envelope for producer integrations, `cli/submit_event.php`, and endpoint previews. Replace the event identity, timestamp, device, and collector with values for your installation before submitting.
- `process_alarm.py`: executable script integration example. Reads mapped JSON from stdin and uses `ALARM_TRANSITION` and `ALARM_IDEMPOTENCY_KEY`. Replace its print action with your delivery action and configure its absolute executable path in the integration.
- `workers.json`: optional daemon configuration for `php alarmmanager_daemon.php --config=/absolute/path/to/workers.json`. Normal installations manage these settings through **Administration → Daemon**.

Synthetic routing configurations used by automated tests live in `tests/fixtures/`.
