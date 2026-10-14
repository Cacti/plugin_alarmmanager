// SPDX-License-Identifier: GPL-2.0-or-later
// Isolated rendered editor checks; no database, credentials, or delivery requests.
const {chromium}=require(process.env.ALARMMANAGER_PLAYWRIGHT_MODULE||'playwright');
const {execFileSync}=require('node:child_process');
const path=require('node:path'), assert=require('node:assert/strict');
const root=path.resolve(__dirname,'../..'), cacti=process.env.CACTI_PATH;
if(!cacti)throw Error('CACTI_PATH required for the local jQuery asset');
const render=endpoint=>execFileSync('php',['-r',`function __($s,...$a){return $s;} function __esc($s,...$a){return htmlspecialchars($s);} function html_escape($s){return htmlspecialchars((string)$s);} function csrf_get_tokens(){return 'fixture';} require $argv[1].'/includes/admin_view.php'; alarmmanager_endpoint_editor(json_decode($argv[2],true),0,!isset(json_decode($argv[2],true)['id']));`,root,JSON.stringify(endpoint)],{encoding:'utf8'});
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.ALARMMANAGER_BROWSER_BINARY});const page=await browser.newPage();let checks=0;const errors=[];page.on('pageerror',e=>errors.push(e.message));
 const check=(ok,label)=>{if(!ok)throw Error(label);checks++;};
 async function load(endpoint){await page.setContent('<div id="alarmmanager_app">'+render(endpoint)+'</div>');await page.addStyleTag({content:':root{--search-border:#bbb;--search-card:#fff;--search-tint:#eee}body{font:14px sans-serif;margin:20px}label{display:flex;gap:8px}button,input,select{padding:8px}[hidden]{display:none!important}'});await page.addStyleTag({path:path.join(root,'css/dashboard.css')});await page.addScriptTag({path:path.join(cacti,'include/js/jquery.js')});await page.addScriptTag({path:path.join(root,'js/dashboard.js')});}
 try{
  await load({name:'Operations',type:'restapi'});const form=page.locator('form');
  check(await form.locator('[name=type] option').count()===3,'Only supported delivery choices');
  check(!(await form.locator('[name=transport_json]').isVisible()),'Raw transport options collapsed');
  check(!(await form.locator('[name=mapping_json]').isVisible()),'Raw message configuration collapsed');
  check(!(await form.locator('[name=max_attempts]').isVisible()),'Retry controls collapsed');
  check(await form.locator('.alarmmanagerMappingRow').count()===4,'Useful new API message defaults');
  check(!(await form.locator('.alarmmanagerMappingRow').first().getByLabel('Custom value',{exact:true}).isVisible()),'Unused custom value hidden');
  await form.locator('.alarmmanagerMappingRow').first().locator('select').first().selectOption('constant');
  const custom=form.locator('.alarmmanagerMappingRow').first().locator('input').nth(1);await custom.fill('123');
  check(JSON.parse(await form.locator('[name=mapping_json]').inputValue()).default[0].constant==='123','Custom text retains its string type');
  await form.locator('[name=auth_type]').selectOption('headers');await form.getByRole('button',{name:'Add header',exact:true}).click();
  const header=form.locator('[data-auth-type=headers] .alarmmanagerMappingRow');await header.getByLabel('Header name').fill('X-Api-Key');await header.getByLabel('Secret value').fill('fixture-secret');
  check(JSON.parse(await form.locator('[name=headers]').inputValue())['X-Api-Key']==='fixture-secret','Header rows serialize to existing credential contract');
  check(await header.getByLabel('Secret value').getAttribute('type')==='password','Header secrets masked');
  await form.locator('[name=type]').selectOption('email');check(!(await form.locator('[name=auth_type]').isEnabled()),'Email disables HTTP credentials');
  check((await form.innerText()).includes('Subject')&&(await form.innerText()).includes('Message'),'Dedicated email subject and message');
  await form.getByRole('button',{name:'Add recipient',exact:true}).click();await form.getByLabel('Email address',{exact:true}).fill('ops@example.test');
  check(await form.locator('[name=email_to]').inputValue()==='ops@example.test','Recipient rows serialize');
  await form.locator('[name=type]').selectOption('script');check(await form.locator('[name=script_compatibility]').count()===0,'No legacy script mode selector');await form.getByRole('button',{name:'Add argument',exact:true}).click();await form.getByLabel('Argument',{exact:true}).fill('two words');
  check(JSON.parse(await form.locator('[name=script_arguments]').inputValue())[0]==='two words','Argument rows preserve spaces');
  await page.setViewportSize({width:390,height:844});check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Mobile editor fits viewport');
  await form.locator('[name=type]').selectOption('email');
  if(process.env.ALARMMANAGER_EDITOR_SCREENSHOT)await page.screenshot({path:process.env.ALARMMANAGER_EDITOR_SCREENSHOT,fullPage:true});
  const mapping={recovered:[{to:'subject',constant:'Recovered',type:'string',required:true},{to:'body',constant:'Done',type:'string'}]};
  await load({id:9,name:'Existing email',type:'email',transport:{to:{'ops@example.test':'Operations'},mapping}});await page.locator('.alarmmanagerDashboardCard > summary').click();
  check(JSON.stringify(JSON.parse(await page.locator('[name=mapping_json]').inputValue()))===JSON.stringify(mapping),'Existing state-specific email mappings remain untouched');
  check(JSON.parse(await page.locator('[name=email_to]').inputValue())['ops@example.test']==='Operations','Named recipients retained in advanced fallback');
  const existing={default:[{to:'host',source:'device.hostname',type:'string',default:'unknown',values:{old:'new'},required:true},{to:'literal',constant:'123',type:'identity',required:false}]};
  await load({id:3,name:'Existing API',type:'webhook',timeout_seconds:37,max_attempts:8,min_interval_ms:1250,transport:{url:'https://example.test',method:'PATCH',success_codes:[202],response_match:'accepted',mapping:existing}});await page.locator('.alarmmanagerDashboardCard > summary').click();
  await page.locator('.alarmmanagerMappingRow').first().getByLabel('Field name',{exact:true}).fill('hostname');existing.default[0].to='hostname';
  assert.deepEqual(JSON.parse(await page.locator('[name=mapping_json]').inputValue()),existing,'Editing a field preserves conversions, fallbacks, replacements and literal types');checks++;
  const posted=await page.locator('form').evaluate(form=>Object.fromEntries(new FormData(form)));
  check(posted.type==='webhook'&&posted.http_method==='PATCH'&&posted.timeout_seconds==='37'&&posted.max_attempts==='8'&&posted.min_interval_ms==='1250','Collapsed delivery values and saved webhook type submitted unchanged');
  await page.locator('.alarmmanagerAdvanced > summary').click();const interval=page.getByLabel('Minimum time between deliveries (seconds)',{exact:true});check(await interval.inputValue()==='1.25','Delivery interval shown in seconds');await interval.fill('2.5');check(await page.locator('[name=min_interval_ms]').inputValue()==='2500','Seconds converted to existing millisecond contract');
  check(JSON.parse(posted.transport_json).response_match==='accepted'&&JSON.parse(posted.transport_json).success_codes[0]===202,'Other transport options preserved');
  const templates=JSON.parse(execFileSync('php',['-r',`require $argv[1].'/includes/integrations.php'; print json_encode(alarmmanager_integration_templates());`,root],{encoding:'utf8'}));
  for(const template of Object.values(templates)) {
   await load({...template,enabled:false});
   check(!(await page.locator('[name=enabled]').isChecked()),'Template draft is disabled');
   check(await page.locator('[name=id]').count()===0,'Template draft has no saved identity');
   check((await page.locator('[name=name]').inputValue())===template.name,'Template fills integration name');
   assert.deepEqual(JSON.parse(await page.locator('[name=mapping_json]').inputValue()),template.transport.mapping,'Template retains its provider-specific mapping');checks++;
   check(await page.locator('[name=reference_id_path]').isVisible(),'Reference paths are editable for HTTP and scripts');
   await page.locator('[name=reference_id_path]').fill('ticket.id');
   await page.locator('[name=reference_url_path]').fill('ticket.url');
   const draft=await page.locator('form').evaluate(form=>Object.fromEntries(new FormData(form)));
   check(draft.reference_id_path==='ticket.id'&&draft.reference_url_path==='ticket.url','Reference paths submit through the endpoint form');
   const refs=page.locator('.alarmmanagerMappingRow').filter({has:page.locator('select option[value="integration.id"]:checked')});
   check(await refs.count()===(template.transport.mapping.default||[]).filter(field=>field.source==='integration.id').length,'External reference rows match the selected template');
  }
  await load({...templates.servicenow,enabled:false});
  const serviceForm=page.locator('form'), table=serviceForm.locator('[name=servicenow_table]');
  check(await table.inputValue()==='incident','ServiceNow starts with Incident selected');
  check(await serviceForm.getByLabel('Field name',{exact:true}).first().inputValue()==='short_description','Incident creation fields are shown');
  await serviceForm.locator('[name=url]').fill('https://custom.example.test/api/now/table/incident');
  await serviceForm.locator('[name=basic_username]').fill('fixture-user');await serviceForm.locator('[name=basic_password]').fill('fixture-password');
  await page.evaluate(()=>jQuery('[name=servicenow_table]').val('em_event').trigger('change'));
  check(await serviceForm.locator('[name=url]').inputValue()==='https://custom.example.test/api/now/table/em_event','Event choice retains the instance');
  check(await serviceForm.getByLabel('Field name',{exact:true}).first().inputValue()==='source','Event fields render immediately');
  const eventMapping=JSON.parse(await serviceForm.locator('[name=mapping_json]').inputValue());
  assert.deepEqual(eventMapping,templates.servicenow_events.transport.mapping);checks++;
  check(!JSON.parse(await serviceForm.locator('[name=transport_json]').inputValue()).transition_overrides,'Event choice removes incident PATCH targets');
  check(await serviceForm.locator('[name=reference_id_path]').inputValue()==='','Event choice clears incident response reference');
  await table.selectOption('incident');
  check(await serviceForm.locator('[name=url]').inputValue()==='https://custom.example.test/api/now/table/incident','Incident choice retains the instance');
  check(await serviceForm.getByLabel('Field name',{exact:true}).first().inputValue()==='short_description','Incident fields render after switching back');
  assert.deepEqual(JSON.parse(await serviceForm.locator('[name=mapping_json]').inputValue()),templates.servicenow.transport.mapping);checks++;
  check(JSON.parse(await serviceForm.locator('[name=transport_json]').inputValue()).transition_overrides.recovered.url==='https://custom.example.test/api/now/table/incident/{{integration.id}}','Incident PATCH target uses the chosen instance');
  check(await serviceForm.locator('[name=reference_id_path]').inputValue()==='result.sys_id','Incident choice restores response reference');
  check(await serviceForm.locator('[name=basic_username]').inputValue()==='fixture-user'&&await serviceForm.locator('[name=basic_password]').inputValue()==='fixture-password','Switching retains credentials');
  check(!(await serviceForm.locator('[name=enabled]').isChecked()),'Switching keeps draft disabled');
  check(errors.length===0,'No browser errors');console.log(`PASS: ${checks} rendered endpoint checks`);
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
