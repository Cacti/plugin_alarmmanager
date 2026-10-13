<?php
// SPDX-License-Identifier: GPL-2.0-or-later
if (PHP_SAPI !== 'cli' || getenv('ALARMMANAGER_TEST_ALLOW_CREATE') !== '1') { exit(2); }
require_once(__DIR__ . '/bootstrap.php');
require_once(__DIR__ . '/../fixtures/cacti_web_functions.php');
require_once(__DIR__ . '/../fixtures/web_schema.php');
require_once(__DIR__ . '/../../setup.php');
require_once(__DIR__ . '/../../includes/ingestion.php');
require_once(__DIR__ . '/../../includes/processor.php');
require_once(__DIR__ . '/../../includes/queue.php');
require_once(__DIR__ . '/../../includes/admin_view.php');
$checks = 0;
function wcheck(bool $condition, string $label): void { global $checks; if (!$condition) { throw new RuntimeException($label); } $checks++; }
function wreject(callable $action, string $label): void { try { $action(); } catch (Throwable $error) { wcheck(true, $label); return; } throw new RuntimeException($label); }
function csrf_check($fatal = true) { return ($_POST['__csrf_magic'] ?? '') === 'fixture-valid'; }
function csrf_get_tokens() { return 'fixture-valid'; }
function wuser(int $user): void { $_SESSION = ['sess_user_id' => $user]; }
function waction(array $input): string { $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = ['__csrf_magic' => 'fixture-valid']; return alarmmanager_admin_action($input); }
$alarmmanager_test_db = alarmmanager_test_connect(); $database = 'alarmmanager_test_' . bin2hex(random_bytes(6));
$alarmmanager_test_db->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4"); $alarmmanager_test_db->exec("USE `$database`");
try {
 alarmmanager_test_web_schema($alarmmanager_test_db); wcheck(plugin_alarmmanager_install(), 'Schema/install registration'); alarmmanager_test_web_grants($alarmmanager_test_db);
 $routes = json_decode(file_get_contents(__DIR__ . '/../../examples/routing.json'), true); $routes['endpoints'][0]['name'] = '<img src=x onerror=window.ALARM_BAD=1>';
 alarmmanager_apply_configuration($routes); $event = json_decode(file_get_contents(__DIR__ . '/../../examples/event.json'), true);
 for ($i=1; $i<=400; $i++) {
  $sample=$event; $sample['event_id']='web:'.$i; $sample['alarm_key']='web:'.$i; $sample['source']='fixture'; $sample['device']['id']=$i<=250?42:43; $sample['summary']=$i===1?'Visible <img src=x onerror=window.ALARM_BAD=1>':($i<=250?'Visible':'HIDDEN_FORBIDDEN_MARKER');
  $response=alarmmanager_submit(['contract_version'=>1,'status'=>'unhandled','mode'=>'centralized','event'=>$sample]); if($response['status']!=='accepted')throw new RuntimeException('Seed ingestion failed');
 }
 while($claim=alarmmanager_claim_processing()) { alarmmanager_process_job($claim); }
 $sample=$event;$sample['event_id']='web:global';$sample['alarm_key']='web:global';$sample['source']='fixture';unset($sample['device']);
 alarmmanager_submit(['contract_version'=>1,'status'=>'unhandled','mode'=>'centralized','event'=>$sample]); alarmmanager_process_job(alarmmanager_claim_processing());
 $sample=$event;$sample['event_id']='web:observe';$sample['alarm_key']='web:observe';$sample['source']='fixture';
 alarmmanager_submit(['contract_version'=>1,'status'=>'unhandled','mode'=>'observe','event'=>$sample]); alarmmanager_process_job(alarmmanager_claim_processing());
 $filters=alarmmanager_report_filters(['until'=>time()+1]);
 wuser(2); $scope=alarmmanager_web_scope(); wcheck(!$scope['admin'], 'Read-only scope');
 $started=microtime(true);$queries=$GLOBALS['alarmmanager_test_queries'];$dashboard=alarmmanager_dashboard_data($scope,$filters);$duration=(microtime(true)-$started)*1000;$query_count=$GLOBALS['alarmmanager_test_queries']-$queries;
 wcheck((int)$dashboard['historical']['events']['events']===250 && (int)$dashboard['current']['conditions']['active']===250, 'Scoped aggregates exclude hidden/global');
 $buckets=$dashboard['panels']['transitions'];$alarmmanager_test_db->exec("SET time_zone='+04:00'");wcheck(alarmmanager_dashboard_data($scope,$filters)['panels']['transitions']===$buckets,'UTC buckets independent of DB session timezone');$alarmmanager_test_db->exec("SET time_zone='+00:00'");
 wcheck(!$dashboard['can_admin'] && $dashboard['workers']===[], 'Read-only no worker details');
 wcheck(array_sum(array_column($dashboard['panels']['deliveries'],'count'))===500,'Scoped delivery totals');
 $events=alarmmanager_report_list('events',$scope,$filters);wcheck(count($events['rows'])===50&&$events['more'],'Bounded page');
 wcheck(count(array_unique(array_column($events['rows'],'device_id')))===1&&(int)$events['rows'][0]['device_id']===42,'Permissions before pagination');
 $next=$filters;$next['page']=2;$page2=alarmmanager_report_list('events',$scope,$next);wcheck(!array_intersect(array_column($events['rows'],'id'),array_column($page2['rows'],'id')),'Stable pagination');
 foreach(['conditions','deliveries','attempts','decisions','processing'] as $kind) { $report=alarmmanager_report_list($kind,$scope,$filters);wcheck(count($report['rows'])<=50&&!str_contains(json_encode($report),'HIDDEN_FORBIDDEN_MARKER'),'Scoped detail report'); }
 $selected=$filters;$selected['event_id']=(int)$events['rows'][0]['id'];wcheck(alarmmanager_event_detail($scope,$selected)['device']['id']===42,'Scoped selected event details');$hidden=alarmmanager_db_row('SELECT id FROM plugin_alarmmanager_events WHERE device_id=43 LIMIT 1');$selected['event_id']=(int)$hidden['id'];wcheck(alarmmanager_event_detail($scope,$selected)===null,'Hidden selected event detail denied');
 $specific=$filters;$specific['severity']='major';wcheck((int)alarmmanager_dashboard_data($scope,$specific)['historical']['events']['events']===250,'Filter aggregate');
 $specific['device_id']=43;wcheck(alarmmanager_report_list('events',$scope,$specific)['rows']===[],'Hidden explicit ID');
 $obs=$filters;$obs['mode']='observe';wcheck((int)alarmmanager_dashboard_data($scope,$obs)['historical']['events']['events']===1,'Observation separate counts');
 wreject(fn()=>waction(['action'=>'credential_remove','id'=>'1']),'Read-only mutation denied');
 wuser(3);wreject(fn()=>alarmmanager_web_scope(),'Unassigned realm denied');
 wuser(1);$admin=alarmmanager_web_scope();wcheck($admin['admin']&&(int)alarmmanager_dashboard_data($admin,$filters)['historical']['events']['events']===401,'Administrator scope includes global');
 $_SERVER['REQUEST_METHOD']='GET';$_POST=[];wreject(fn()=>alarmmanager_admin_action(['action'=>'credential_remove','id'=>'1']),'GET mutation denied');
 $_SERVER['REQUEST_METHOD']='POST';wreject(fn()=>alarmmanager_admin_action(['action'=>'credential_remove','id'=>'1']),'Missing CSRF denied');
 $_POST=['__csrf_magic'=>'bad'];wreject(fn()=>alarmmanager_admin_action(['action'=>'credential_remove','id'=>'1']),'Bad CSRF denied');
 foreach([['range'=>'999999'],['mode'=>'all'],['page'=>'201'],['until'=>'999999999999'],['source'=>"' OR 1=1"],['device_id'=>['42']]] as $bad) { wreject(fn()=>alarmmanager_report_filters($bad),'Invalid filter rejected'); }
 $state=alarmmanager_admin_configuration();$version=$state['revision'];
 waction(['action'=>'configuration_save','revision'=>$version,'configuration_json'=>json_encode($state['configuration'])]);
 wreject(fn()=>waction(['action'=>'configuration_save','revision'=>$version,'configuration_json'=>json_encode($state['configuration'])]),'Stale configuration rejected');
 wcheck((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_audit')===1,'Configuration audit');
 $record=['headers'=>['Authorization'=>'Bearer WEB_SECRET_MARKER']];
 waction(['action'=>'credential_save','id'=>'1','headers'=>json_encode($record['headers'])]);wcheck(alarmmanager_load_credential(1)===$record,'Admin credential save');
 ob_start();alarmmanager_admin_markup(alarmmanager_admin_configuration(),'credentials');$html=ob_get_clean();wcheck(!str_contains($html,'WEB_SECRET_MARKER')&&!str_contains($html,'am1:'),'Credential page never returns ciphertext/plaintext');
 ob_start();alarmmanager_endpoint_editor($routes['endpoints'][0],$version);$html=ob_get_clean();wcheck(str_contains($html,'&lt;img')&&!str_contains($html,'<img src=x'),'Endpoint editor escaping');
 $routes['endpoints'][0]['transport']=['url'=>'https://example.test/alarms','credential'=>true,'mapping'=>['default'=>[['to'=>'hostname','source'=>'device.id']]]]; alarmmanager_apply_configuration($routes);
 $native=['action'=>'endpoint_save','revision'=>alarmmanager_admin_configuration()['revision'],'id'=>'1','name'=>'Native REST','enabled'=>'yes','type'=>'restapi','url'=>'https://example.test/alarms','method'=>'POST','credential'=>'yes','mapping_json'=>json_encode(['default'=>[['to'=>'host','source'=>'device.id']]]),'transport_json'=>'{}'];
 waction($native);wcheck(alarmmanager_admin_configuration()['configuration']['endpoints'][0]['transport']['mapping']['default'][0]['to']==='host','Native endpoint form mapping save');
 $before=(int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_deliveries');$preview=waction(['action'=>'preview','id'=>'1','event_json'=>json_encode($event)]);
 wcheck(str_contains($preview,'REDACTED')&&!str_contains($preview,'WEB_SECRET_MARKER')&&(int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_deliveries')===$before,'Admin preview no enqueue/secrets');
 waction(['action'=>'test_send','id'=>'1','event_json'=>json_encode($event),'confirmed'=>'yes']);wcheck((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_deliveries')===$before+1,'Test queues single destination');
 wreject(fn()=>waction(['action'=>'test_send','id'=>'1','event_json'=>json_encode($event)]),'Test explicit confirmation');
 $claim=alarmmanager_claim_delivery(['restapi']);$id=$claim['id'];$key=$claim['idempotency_key'];alarmmanager_finish_delivery($claim,['outcome'=>'failure','code'=>'fixture_failure']);
 waction(['action'=>'replay','queue'=>'delivery','id'=>$id,'confirmed'=>'yes']);alarmmanager_db_write("UPDATE plugin_alarmmanager_endpoints SET last_claim_at = CASE id WHEN 1 THEN '1970-01-01' ELSE UTC_TIMESTAMP(6) END");
 $reclaimed=alarmmanager_claim_delivery(['restapi']);wcheck($reclaimed&&$reclaimed['id']===$id&&$reclaimed['idempotency_key']===$key,'Replay preserves identity/idempotency');
 alarmmanager_finish_delivery($reclaimed,['outcome'=>'success','code'=>'fixture_ok']);wcheck((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_attempts WHERE delivery_id=?',[$id])===2,'Replay keeps prior attempts and appends distinct number');
 wreject(fn()=>waction(['action'=>'replay','queue'=>'delivery','id'=>$id,'confirmed'=>'yes']),'Completed job cannot replay');
 $job=alarmmanager_db_row('SELECT id FROM plugin_alarmmanager_jobs LIMIT 1');alarmmanager_db_write("UPDATE plugin_alarmmanager_jobs SET status='failed', attempts=5 WHERE id=?",[$job['id']]);waction(['action'=>'replay','queue'=>'processing','id'=>$job['id'],'confirmed'=>'yes']);wcheck((int)db_fetch_cell_prepared('SELECT attempts FROM plugin_alarmmanager_jobs WHERE id=?',[$job['id']])===0,'Processing retry budget reset');
 $condition=alarmmanager_db_row('SELECT stream_key FROM plugin_alarmmanager_states WHERE active=1 LIMIT 1');waction(['action'=>'acknowledge','stream_key'=>$condition['stream_key'],'acknowledged'=>'yes']);wcheck((int)db_fetch_cell_prepared('SELECT acknowledged FROM plugin_alarmmanager_states WHERE stream_key=?',[$condition['stream_key']])===1,'Acknowledgment audited');
 $old=alarmmanager_secret_key();waction(['action'=>'key_rotate','confirmed'=>'yes']);wcheck(alarmmanager_secret_key()!==$old&&alarmmanager_load_credential(1)===$record,'Browser local key rotation');
 waction(['action'=>'credential_remove','id'=>'1']);wcheck((int)db_fetch_cell_prepared('SELECT COUNT(*) FROM plugin_alarmmanager_credentials')===0,'Credential remove');
 // Real Cacti policy generator: default-deny + group grants, deleted/disabled hosts and default-allow exclusions.
 $policy_filters=$filters;$policy_filters['source']='fixture';
 wuser(2);$alarmmanager_test_db->exec("INSERT INTO user_auth_group (id,name,enabled) VALUES (1,'Readers','on')");$alarmmanager_test_db->exec('INSERT INTO user_auth_group_members VALUES (2,1)');$alarmmanager_test_db->exec('INSERT INTO user_auth_group_perms VALUES (1,3,43)');
 wcheck((int)alarmmanager_dashboard_data(alarmmanager_web_scope(),$policy_filters)['historical']['events']['events']===400,'Group device grant union');
 $alarmmanager_test_db->exec("UPDATE host SET deleted='on' WHERE id=43");wcheck((int)alarmmanager_dashboard_data(alarmmanager_web_scope(),$policy_filters)['historical']['events']['events']===250,'Deleted host hidden');$alarmmanager_test_db->exec("UPDATE host SET deleted='' WHERE id=43");
 $alarmmanager_test_db->exec("UPDATE user_auth_group SET enabled='' WHERE id=1");$alarmmanager_test_db->exec('UPDATE user_auth SET policy_hosts=1 WHERE id=2');$alarmmanager_test_db->exec('DELETE FROM user_auth_perms WHERE user_id=2');$alarmmanager_test_db->exec('INSERT INTO user_auth_perms VALUES (2,3,43)');
 wcheck((int)alarmmanager_dashboard_data(alarmmanager_web_scope(),$policy_filters)['historical']['events']['events']===250,'Default allow respects explicit deny');
 $alarmmanager_test_db->exec("INSERT INTO settings_user VALUES (2,'hide_disabled','on')");$alarmmanager_test_db->exec("UPDATE host SET disabled='on' WHERE id=42");wcheck((int)alarmmanager_dashboard_data(alarmmanager_web_scope(),$policy_filters)['historical']['events']['events']===0,'Disabled host preference');
 [$where,$params]=alarmmanager_report_where($scope,$filters);$plan=alarmmanager_db_rows("EXPLAIN SELECT e.id FROM plugin_alarmmanager_events AS e WHERE $where ORDER BY e.id DESC LIMIT 51",$params);
 $measure=['events'=>401,'reader_visible_events'=>250,'dashboard_queries'=>$query_count,'dashboard_ms'=>round($duration,3),'php_peak_bytes'=>memory_get_peak_usage(true),'list_explain'=>$plan,'database'=>db_fetch_cell_prepared('SELECT VERSION()')];
 if(getenv('ALARMMANAGER_WEB_MEASUREMENT'))file_put_contents(getenv('ALARMMANAGER_WEB_MEASUREMENT'),json_encode($measure,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
 $deadline_start=hrtime(true);wreject(fn()=>alarmmanager_report_rows('SELECT SLEEP(10)'),'Per-statement report timeout');wcheck((hrtime(true)-$deadline_start)/1e9<9 && str_contains($GLOBALS['database_last_error'] ?? '', '1969'),'Report timeout bounded');
 wuser(2); wreject(fn()=>waction(['action'=>'producer_save','source'=>'thold','scope'=>'default','resource_id'=>0,'mode'=>'legacy']), 'reader producer save denied');
 wuser(1); $_SERVER['REQUEST_METHOD']='POST'; $_POST=['__csrf_magic'=>'invalid']; wreject(fn()=>alarmmanager_admin_action(['action'=>'producer_save','source'=>'thold','scope'=>'default','resource_id'=>0,'mode'=>'legacy']), 'producer invalid CSRF denied');
 $producer=['action'=>'producer_save','source'=>'thold','scope'=>'default','resource_id'=>0,'producer_revision'=>0,'mode'=>'centralized','profile_id'=>1,'channels'=>['email']];
 wreject(fn()=>waction($producer), 'central producer migration confirmation required');
 waction($producer+['migration_confirmed'=>'yes']);wcheck(AlarmManagerProducerBridge::configuration('thold',42)['mode']==='centralized','admin producer default saved');
 wreject(fn()=>waction($producer+['migration_confirmed'=>'yes']), 'producer stale editor denied');
 $override=['action'=>'producer_save','source'=>'thold','scope'=>'template','resource_id'=>10,'producer_revision'=>0,'mode'=>'observe','profile_id'=>0];
 waction($override);wcheck(AlarmManagerProducerBridge::configuration('thold',42,10)['mode']==='observe','template override saved');
 waction(['action'=>'producer_save','source'=>'thold','scope'=>'resource','resource_id'=>42,'producer_revision'=>0,'mode'=>'legacy','profile_id'=>0]);wcheck(AlarmManagerProducerBridge::configuration('thold',42,10)['mode']==='legacy','threshold override saved');
 waction(['action'=>'producer_save','source'=>'thold','scope'=>'resource','resource_id'=>42,'producer_revision'=>1,'mode'=>'legacy','profile_id'=>0,'inherit'=>'yes']);wcheck(AlarmManagerProducerBridge::configuration('thold',42,10)['mode']==='observe','override removal restores inheritance');
 wreject(fn()=>waction(['action'=>'producer_save','source'=>'syslog','scope'=>'template','resource_id'=>1,'mode'=>'observe']), 'syslog template rejected');
 ob_start();alarmmanager_admin_markup(alarmmanager_admin_configuration(),'producers');$html=ob_get_clean();wcheck(str_contains($html,'Producer integrations')&&str_contains($html,'migration_confirmed')&&str_contains($html,'__csrf_magic'), 'producer admin controls and CSRF rendered');
 wcheck((int)db_fetch_cell_prepared("SELECT COUNT(*) FROM plugin_alarmmanager_audit WHERE action LIKE 'producer_%'")===4,'producer changes audited');
 ob_start();alarmmanager_admin_markup(alarmmanager_admin_configuration(),'collectors');$html=ob_get_clean();wcheck(str_contains($html,'Collectors and remote history')&&str_contains($html,'Latest 50'),'bounded collector history controls rendered');
 print "PASS: $checks database/Cacti policy/admin/replay checks; dashboard $query_count queries in ".round($duration,2)." ms.\n";
} finally { if($alarmmanager_test_db->inTransaction())$alarmmanager_test_db->rollBack();$alarmmanager_test_db->exec("DROP DATABASE `$database`"); }
