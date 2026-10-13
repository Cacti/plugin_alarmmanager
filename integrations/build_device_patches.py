#!/usr/bin/env python3
"""Generate separate pinned core/device patches; never edit reference checkouts."""
import pathlib, argparse, subprocess, difflib
ROOT = pathlib.Path(__file__).resolve().parent
PINS = {'cacti': 'd6d230f712c0af1a0391646f65364950ebf131af', 'thold': '6bbfb58492b1e8ce2aeee4690659e54ceb4d1226'}
def diff(old, new, path):
    return ''.join(difflib.unified_diff(old.splitlines(True), new.splitlines(True), fromfile='a/'+path if old else '/dev/null', tofile='b/'+path))
def main():
    p=argparse.ArgumentParser();p.add_argument('--cacti',type=pathlib.Path,required=True);p.add_argument('--thold',type=pathlib.Path,required=True);p.add_argument('--output',type=pathlib.Path,default=ROOT/'patches');a=p.parse_args();a.output.mkdir(parents=True,exist_ok=True)
    for repo,pin in PINS.items():
        if subprocess.check_output(['git','-C',str(getattr(a,repo)),'rev-parse','HEAD'],text=True).strip()!=pin:raise SystemExit('Mismatched '+repo+' pin')
    old=subprocess.check_output(['git','-C',str(a.cacti),'show',PINS['cacti']+':lib/functions.php'],text=True)
    if (a.cacti/'lib/functions.php').read_text()!=old:raise SystemExit('Modified core source')
    start=old.index('function update_host_status(');end=old.index('\nfunction ',start+10);body=old[start:end]
    body=body.replace("\t$issue_log_message   = false;", "\tstatic $alarmmanager_bridge_checked = false;\n\tif (!$alarmmanager_bridge_checked) { $alarmmanager_bridge_checked = true; if (is_file(__DIR__ . '/alarmmanager_device_bridge.php')) { require_once(__DIR__ . '/alarmmanager_device_bridge.php'); } }\n\tif (function_exists('alarmmanager_device_bridge_poll_allowed') && !alarmmanager_device_bridge_poll_allowed((int) $host_id)) { return; }\n\t$issue_log_message   = false;",1)
    body=body.replace("\t/* initialize fail", "\t$alarmmanager_previous_status = (int) $host['status'];\n\n\t/* initialize fail",1)
    at=body.index("\tdb_execute_prepared('UPDATE host SET")
    last=body.index('\n\t);',at)+len('\n\t);')
    write=body[at:last].replace("\tdb_execute_prepared(","\t\treturn db_execute_prepared(",1)
    new="\t$alarmmanager_status_write = static function () use ($host) {\n"+write+"\n\t};\n\tif ($issue_log_message && is_file(__DIR__ . '/alarmmanager_device_bridge.php')) {\n\t\trequire_once(__DIR__ . '/alarmmanager_device_bridge.php');\n\t\t$alarmmanager_safe = array_intersect_key($host, array_flip(array('id', 'status', 'hostname', 'description', 'status_fail_date', 'status_rec_date')));\n\t\talarmmanager_device_bridge_core($alarmmanager_safe, $alarmmanager_previous_status, $alarmmanager_status_write);\n\t} else {\n\t\t$alarmmanager_status_write();\n\t}"
    body=body[:at]+new+body[last:];transformed=old[:start]+body+old[end:]
    core_diff = diff(old,transformed,'lib/functions.php')+diff('',(ROOT/'device_bridge.php').read_text(),'lib/alarmmanager_device_bridge.php')
    poller = subprocess.check_output(['git','-C',str(a.cacti),'show',PINS['cacti']+':lib/poller.php'],text=True)
    if (a.cacti/'lib/poller.php').read_text()!=poller:raise SystemExit('Modified core poller source')
    line = "\t\treplicate_out_table($rcnn_id, $data, 'host', $remote_poller_id);"
    assert poller.count(line)==1
    replacement = "\t\tif (is_file(__DIR__ . '/alarmmanager_device_bridge.php')) { require_once(__DIR__ . '/alarmmanager_device_bridge.php'); }\n\t\tif (!function_exists('alarmmanager_device_bridge_replicate_hosts') || !alarmmanager_device_bridge_replicate_hosts($rcnn_id, $data, (int) $remote_poller_id)) {\n" + '\t'+line+"\n\t\t}"
    core_diff += diff(poller,poller.replace(line,replacement),'lib/poller.php')
    (a.output/'cacti-device.patch').write_text(core_diff)
    old=subprocess.check_output(['git','-C',str(a.thold),'show',PINS['thold']+':includes/polling.php'],text=True)
    if (a.thold/'includes/polling.php').read_text()!=old:raise SystemExit('Modified thold source')
    start=old.index('function thold_update_host_status(');end=old.find('\nfunction ',start+10);end=len(old) if end<0 else end;body=old[start:end]
    body=body.replace('\tglobal $config;',"\tglobal $config;\n\tif (is_file($config['base_path'] . '/lib/alarmmanager_device_bridge.php')) { require_once($config['base_path'] . '/lib/alarmmanager_device_bridge.php'); }",1)
    import re
    for hook,kind in [('thold_device_recovering','recovered'),('thold_device_down','raised')]:
        marker=re.search(r"(?m)^(\t+)api_plugin_hook_function\(\n\t+'"+hook+"'",body);assert marker
        indent=marker.group(1); insert=indent+"$alarmmanager_device_delivery = function_exists('alarmmanager_device_bridge_decision') ? alarmmanager_device_bridge_decision($host, '"+kind+"') : [];\n\n"
        body=body[:marker.start()]+insert+body[marker.start():]
    body,n=re.subn(r'(?m)^(\t+)(thold_mail\([^\n]+;)',r"\1if (!function_exists('alarmmanager_device_bridge_owns') || !alarmmanager_device_bridge_owns($alarmmanager_device_delivery, 'email')) { \2 }",body);assert n==2,n
    body,n=re.subn(r"if \(\$command != ''\) \{", "if ($command != '' && (!function_exists('alarmmanager_device_bridge_owns') || !alarmmanager_device_bridge_owns($alarmmanager_device_delivery, 'script'))) {",body);assert n==2,n
    (a.output/'thold-device.patch').write_text(diff(old,old[:start]+body+old[end:],'includes/polling.php'))
    print('Generated pinned Cacti and thold device patches.')
if __name__=='__main__':main()
