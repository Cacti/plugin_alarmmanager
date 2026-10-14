#!/usr/bin/env python3
"""Build reproducible integration patches; refuse mismatched/dirty source files."""
import argparse, difflib, pathlib, subprocess
PINS = {'thold': '6bbfb58492b1e8ce2aeee4690659e54ceb4d1226', 'syslog': 'be1a8151c21ff81684d0e8ca0f9f0a78a8d48f27'}
ROOT = pathlib.Path(__file__).resolve().parent

def patch_thold(original):
    import re
    start = original.index('function thold_check_threshold(')
    end = original.index('\nfunction ', start + 10)
    body = original[start:end]
    matches = list(re.finditer(r"(?m)^(\t+)\$save = \[\n\t+'class'\s*=> '([^']+)'", body))
    assert len(matches) == 11, len(matches)
    for match in reversed(matches):
        indent, cls = match.groups()
        before = body[:match.start()]
        openings = list(re.finditer(r'(?m)^' + re.escape(indent[:-1]) + r'(?:if|} elseif) .*\{\n', before))
        assert openings, cls
        opening = openings[-1].end()
        segment = body[opening:match.start()]
        # Every interception starts before mail, commands, traps and source logging.
        segment = re.sub(r'(?m)^(\t+)(thold_mail\([^\n]+;)', r"\1if (!AlarmManagerProducerBridge::owns($alarmmanager_delivery, 'email')) { \2 }", segment)
        segment = re.sub(r'(?m)^(\t+)(thold_command_execution\([^\n]+;)', r"\1if (!AlarmManagerProducerBridge::owns($alarmmanager_delivery, 'script')) { \2 }", segment)
        segment = re.sub(r'(thold_mail_notification\([^\n]+)(\);)', r'\1, $alarmmanager_delivery\2', segment)
        begin = indent + "$alarmmanager_delivery = alarmmanager_thold_begin($thold_data, $h, '" + cls + "', $subject, " + ("(bool) $ra" if "'repeat_alert'" in body[match.start():body.index("api_plugin_hook_function('thold_action'", match.start())] else 'false') + ", ($thold_data['acknowledgment'] ?? '') !== '', 'source_acknowledged');\n\n"
        body = body[:opening] + begin + segment + body[match.start():]
    # Publish source-suppressed notifications only where the source actually logs them.
    body = body.replace('thold_log([', 'alarmmanager_thold_log([')
    # Balanced brackets identify complete call arguments; skip strings/comments using PHP token_get_all in tests.
    needle = 'alarmmanager_thold_log(['
    pos = 0
    while True:
        i = body.find(needle, pos)
        if i < 0: break
        # All original log calls end with a stand-alone ); immediately after their array.
        close = re.search(r'(?m)^\t+\);', body[i:])
        assert close
        j = i + close.start()
        # insert arguments after the closing array bracket preceding the );
        k = body.rfind(']', i, j)
        body = body[:k+1] + ', $thold_data, $h, $maint_dev' + body[k+1:]
        pos = j + 50
    # A source may deliberately disable its recovery notification. Still close the
    # centrally recorded condition with a suppressed recovery, without changing legacy sends.
    tail = """
	if (!isset($alarmmanager_delivery)) {
		$alarmmanager_normal = (int) $thold_data['thold_type'] === 1
			? (isset($bl_alert_prev) && $bl_alert_prev > 0 && (int) $thold_data['bl_alert'] === 0)
			: ($alertstat != 0 && !($breach_up ?? false) && !($breach_down ?? false) && !($warning_breach_up ?? false) && !($warning_breach_down ?? false));
		if ($alarmmanager_normal) {
			alarmmanager_thold_begin($thold_data, $h, (int) $thold_data['thold_type'] === 1 ? 'blnormal' : 'alert2normal', $subject ?? 'Threshold recovered', false, true, 'source_recovery_notification_disabled');
		}
	}
"""
    closing = body.rfind('\n}')
    assert closing > 0
    body = body[:closing] + tail + body[closing:]
    result = original[:start] + body + original[end:]
    result = result.replace('function thold_mail_notification($recipients, $bcc, $subject, $text_type, $list_id, $file_array, &$thold_data, &$h, $timespan = 7)', 'function thold_mail_notification($recipients, $bcc, $subject, $text_type, $list_id, $file_array, &$thold_data, &$h, $timespan = 7, $alarmmanager_delivery = [])')
    call = "\tthold_mail($recipients, $bcc, '', $subject, $message, $file_array, '', $list_id, $h, $format_file, $timespan);"
    assert result.count(call) == 1
    result = result.replace(call, "\tif (!AlarmManagerProducerBridge::owns($alarmmanager_delivery, 'email')) {\n" + '\t' + call + '\n\t}')
    return result

def patch_syslog(original):
    start = original.index('function syslog_process_alert(')
    end = original.index('\nfunction ', start + 10)
    body = original[start:end]
    marker = '\t\t\tif ($alert[\'method\'] == \'0\') {'
    assert body.count(marker) == 1
    body = body.replace(marker, "\t\t\t$alarmmanager_delivery = alarmmanager_syslog_begin($alert, $at, $hostname, $message, (bool) $send, (bool) $found);\n\n" + marker)
    import re
    for func, channel in [('syslog_sendemail', 'email'), ('syslog_execute_ticket_command', 'ticket'), ('syslog_execute_alert_command', 'script')]:
        body, count = re.subn(r'(?m)^(\t+)(' + func + r'\([^\n]+;)', r"\1if (!AlarmManagerProducerBridge::owns($alarmmanager_delivery, '" + channel + r"')) { \2 }", body)
        assert count == 2, (func, count)
    return original[:start] + body + original[end:]

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--thold', type=pathlib.Path, required=True)
    parser.add_argument('--syslog', type=pathlib.Path, required=True)
    parser.add_argument('--output', type=pathlib.Path, default=ROOT / 'patches')
    args = parser.parse_args(); args.output.mkdir(parents=True, exist_ok=True)
    for source in PINS:
        repo = getattr(args, source).resolve()
        head = subprocess.check_output(['git', '-C', str(repo), 'rev-parse', 'HEAD'], text=True).strip()
        if head != PINS[source]: raise SystemExit(f'{source}: expected {PINS[source]}, got {head}')
        original = subprocess.check_output(['git', '-C', str(repo), 'show', head + ':includes/functions.php'], text=True)
        if (repo / 'includes/functions.php').read_text() != original: raise SystemExit(f'{source}: modified source file')
        transformed = (patch_thold if source == 'thold' else patch_syslog)(original)
        transformed = transformed.replace('*/\n', "*/\n\nrequire_once(__DIR__ . '/alarmmanager_adapter.php');\n", 1)
        diff = ''.join(difflib.unified_diff(original.splitlines(True), transformed.splitlines(True), fromfile='a/includes/functions.php', tofile='b/includes/functions.php'))
        for filename, content in [('alarmmanager_bridge.php', (ROOT / 'producer_bridge.php').read_text()), ('api_client.php', (ROOT / 'api_client.php').read_text()), ('alarmmanager_adapter.php', (ROOT / (source + '_adapter.php')).read_text())]:
            diff += ''.join(difflib.unified_diff([], content.splitlines(True), fromfile='/dev/null', tofile='b/includes/' + filename))
        diff += ''.join(difflib.unified_diff([], (ROOT.parent / 'examples' / 'producer_api_cli.php').read_text().splitlines(True), fromfile='/dev/null', tofile='b/cli/alarmmanager_api.php'))
        (args.output / (source + '.patch')).write_text(diff)
        print(source, 'patch generated', len(diff), 'bytes')
if __name__ == '__main__': main()
