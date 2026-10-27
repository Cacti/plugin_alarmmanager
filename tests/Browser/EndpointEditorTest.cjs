// SPDX-License-Identifier: GPL-2.0-or-later
// Isolated rendered editor checks; no database, credentials, or delivery requests.
const {chromium}=require(process.env.ALARMMANAGER_PLAYWRIGHT_MODULE||'playwright');
const {execFileSync}=require('node:child_process');
const path=require('node:path'), assert=require('node:assert/strict');
const fs=require('node:fs'),os=require('node:os'),shots=fs.mkdtempSync(path.join(os.tmpdir(),'alarmmanager-'));
const root=path.resolve(__dirname,'../..'), cacti=process.env.CACTI_PATH;
if(!cacti)throw Error('CACTI_PATH required for the local jQuery asset');
const render=endpoint=>execFileSync('php',['-r',`function __($s,...$a){return $s;} function __esc($s,...$a){return htmlspecialchars($s);} function html_escape($s){return htmlspecialchars((string)$s);} function csrf_get_tokens(){return 'fixture';} require $argv[1].'/includes/admin_view.php'; alarmmanager_endpoint_editor(json_decode($argv[2],true),0,!isset(json_decode($argv[2],true)['id']),['offline'=>'Custom device offline']);`,root,JSON.stringify(endpoint)],{encoding:'utf8'});
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.ALARMMANAGER_BROWSER_BINARY});const page=await browser.newPage();let checks=0;const errors=[];page.on('pageerror',e=>errors.push(e.message));
 const check=(ok,label)=>{if(!ok)throw Error(label);checks++;};
 async function load(endpoint){await page.setContent('<div id="alarmmanager_app">'+render(endpoint)+'</div>');await page.addStyleTag({content:':root{--search-border:#bbb;--search-card:#fff;--search-tint:#eee}body{font:14px sans-serif;margin:20px}label{display:flex;gap:8px}button,input,select{padding:8px}[hidden]{display:none!important}'});await page.addStyleTag({path:path.join(root,'css/dashboard.css')});await page.addScriptTag({path:path.join(cacti,'include/js/jquery.js')});await page.addScriptTag({path:path.join(root,'js/dashboard.js')});}
 try{
  await load({name:'Operations',type:'restapi'});const form=page.locator('form:has([name=action][value=endpoint_save])');
  check(await form.locator('[name=type] option').count()===3,'Only supported delivery choices');
  check(!(await form.locator('[name=transport_json]').isVisible()),'Raw transport options collapsed');
  check(!(await form.locator('[name=mapping_json]').isVisible()),'Raw message configuration collapsed');
  check(!(await form.locator('[name=max_attempts]').isVisible()),'Retry controls collapsed');
  check(await form.locator('.alarmmanagerMappingRow').count()===14,'Useful new API message defaults');
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
  await page.locator('.alarmmanagerMappingRow').first().getByLabel('Destination Field Name',{exact:true}).fill('hostname');existing.default[0].to='hostname';
  assert.deepEqual(JSON.parse(await page.locator('[name=mapping_json]').inputValue()),existing,'Editing a field preserves conversions, fallbacks, replacements and literal types');checks++;
  const posted=await page.locator('form:has([name=action][value=endpoint_save])').evaluate(form=>Object.fromEntries(new FormData(form)));
  check(posted.type==='webhook'&&posted.http_method==='PATCH'&&posted.timeout_seconds==='37'&&posted.max_attempts==='8'&&posted.min_interval_ms==='1250','Collapsed delivery values and saved webhook type submitted unchanged');
  await page.locator('.alarmmanagerAdvanced > summary').click();const interval=page.getByLabel('Minimum time between deliveries (seconds)',{exact:true});check(await interval.inputValue()==='1.25','Delivery interval shown in seconds');await interval.fill('2.5');check(await page.locator('[name=min_interval_ms]').inputValue()==='2500','Seconds converted to existing millisecond contract');
  check(JSON.parse(posted.transport_json).response_match==='accepted'&&JSON.parse(posted.transport_json).success_codes[0]===202,'Other transport options preserved');
  const templates=JSON.parse(execFileSync('php',['-r',`require $argv[1].'/includes/integrations.php'; print json_encode(alarmmanager_integration_templates());`,root],{encoding:'utf8'}));
  for(const template of Object.values(templates)) {
   await load({...template,enabled:false});
   check(!(await page.locator('[name=enabled]').isChecked()),'Template draft is disabled');
   check(await page.getByText('Customize messages by alarm state',{exact:true}).count()===0,'Alarm state customization controls are removed');
   check(await page.locator('[name=id]').count()===0,'Template draft has no saved identity');
   check((await page.locator('[name=name]').inputValue())===template.name,'Template fills integration name');
   assert.deepEqual(JSON.parse(await page.locator('[name=mapping_json]').inputValue()),template.transport.mapping,'Template retains its provider-specific mapping');checks++;
   check(await page.locator('[name=reference_id_path], [name=reference_url_path]').count()===0,'External reference controls are removed');
   const refs=page.locator('.alarmmanagerMappingRow').filter({has:page.locator('select option[value="integration.id"]:checked')});
   check(await refs.count()===(template.transport.mapping.default||[]).filter(field=>field.source==='integration.id').length,'External reference rows match the selected template');
  }
  await load({...templates.teams,enabled:false});
  check(await page.locator('[name=auth_type]').inputValue()==='teams','Teams selects encrypted workflow URL authentication');
  const teamsSecret=page.locator('[name=teams_webhook_url]');
  check(await teamsSecret.isVisible(),'Teams secret control is visible');
  check(await teamsSecret.getAttribute('type')==='password','Workflow URL uses a password control');
  check(await teamsSecret.inputValue()==='','Saved workflow URL is never rendered');
  check(await page.locator('[data-integration-hint]').innerText().then(text=>text.includes('Anyone')&&text.includes('co-owner')),'Teams setup explains authentication and ownership');
  const teamsData=await page.locator('form:has([name=action][value=endpoint_save])').evaluate(form=>Object.fromEntries(new FormData(form)));
  check(teamsData.auth_type==='teams'&&JSON.parse(teamsData.transport_json).teams_workflow,'Draft preserves Teams transport and authentication');
  await load({...templates.servicenow,enabled:false});
  const serviceForm=page.locator('form:has([name=action][value=endpoint_save])'), table=serviceForm.locator('[name=servicenow_table]');
  check(await table.inputValue()==='incident','ServiceNow starts with Incident selected');
  check(await serviceForm.getByLabel('Destination Field Name',{exact:true}).first().inputValue()==='short_description','Incident creation fields are shown');
  await serviceForm.locator('[name=url]').fill('https://custom.example.test/api/now/table/incident');
  await serviceForm.locator('[name=basic_username]').fill('fixture-user');await serviceForm.locator('[name=basic_password]').fill('fixture-password');
  await page.evaluate(()=>jQuery('[name=servicenow_table]').val('em_event').trigger('change'));
  check(await serviceForm.locator('[name=url]').inputValue()==='https://custom.example.test/api/now/table/em_event','Event choice retains the instance');
  check(await serviceForm.getByLabel('Destination Field Name',{exact:true}).first().inputValue()==='source','Event fields render immediately');
  const eventMapping=JSON.parse(await serviceForm.locator('[name=mapping_json]').inputValue());
  assert.deepEqual(eventMapping,templates.servicenow_events.transport.mapping);checks++;
  check(!JSON.parse(await serviceForm.locator('[name=transport_json]').inputValue()).transition_overrides,'Event choice removes incident PATCH targets');
  check(!JSON.parse(await serviceForm.locator('[name=transport_json]').inputValue()).response_reference,'Event choice clears incident response reference');
  await table.selectOption('incident');
  check(await serviceForm.locator('[name=url]').inputValue()==='https://custom.example.test/api/now/table/incident','Incident choice retains the instance');
  check(await serviceForm.getByLabel('Destination Field Name',{exact:true}).first().inputValue()==='short_description','Incident fields render after switching back');
  assert.deepEqual(JSON.parse(await serviceForm.locator('[name=mapping_json]').inputValue()),templates.servicenow.transport.mapping);checks++;
  check(JSON.parse(await serviceForm.locator('[name=transport_json]').inputValue()).transition_overrides.recovered.url==='https://custom.example.test/api/now/table/incident/{{integration.id}}','Incident PATCH target uses the chosen instance');
  check(JSON.parse(await serviceForm.locator('[name=transport_json]').inputValue()).response_reference.id==='result.sys_id','Incident choice restores response reference');
  check(await serviceForm.locator('[name=basic_username]').inputValue()==='fixture-user'&&await serviceForm.locator('[name=basic_password]').inputValue()==='fixture-password','Switching retains credentials');
  check(!(await serviceForm.locator('[name=enabled]').isChecked()),'Switching keeps draft disabled');
  await load({name:'Combined API',type:'restapi'});
  const combinedRow=page.locator('.alarmmanagerMappingRow').first();
  await combinedRow.getByLabel('Destination Field Name',{exact:true}).fill('summary');
  await combinedRow.getByLabel('Cacti Value',{exact:true}).selectOption('template');
  const combined=combinedRow.getByLabel('Combined text',{exact:true});
  check(await combined.isVisible(),'Combined text editor is available');
  await combined.fill('Device ');
  await combined.press('End');
  await combinedRow.getByText('Insert field',{exact:true}).click();
  await combinedRow.getByRole('searchbox',{name:'Search message fields'}).fill('Hostname');
  await combinedRow.getByRole('button',{name:'Hostname',exact:true}).click();
  check(await combined.locator('[data-field="device.hostname"]').count()===1,'Friendly field inserts a chip');
  check(JSON.parse(await page.locator('[name=mapping_json]').inputValue()).default[0].template==='Device {{device.hostname}}','Chips serialize using existing syntax');
  check((await combinedRow.getByLabel('Sample message preview').textContent())==='Device router.example.test','Sample preview substitutes example values');
  await combined.getByRole('button').focus();await combined.getByRole('button').press('Delete');
  check(JSON.parse(await page.locator('[name=mapping_json]').inputValue()).default[0].template==='Device ','Keyboard deletion removes the whole chip');
  await combined.fill('Device  is down');await combined.press('Home');for(let n=0;n<7;n++)await combined.press('ArrowRight');
  await combinedRow.getByText('Insert field',{exact:true}).click();
  await combinedRow.getByRole('searchbox',{name:'Search message fields'}).fill('location');
  await combinedRow.getByRole('button',{name:'SNMP location',exact:true}).click();
  check(JSON.parse(await page.locator('[name=mapping_json]').inputValue()).default[0].template==='Device {{device.location}} is down','Insertion preserves a cursor in the middle of text');
  await combined.press('End');await combined.press('Enter');await combined.pressSequentially('Second line');
  assert.equal(JSON.parse(await page.locator('[name=mapping_json]').inputValue()).default[0].template,'Device {{device.location}} is down\nSecond line','Newlines serialize as plain text');checks++;
  await combinedRow.getByText('Insert field',{exact:true}).click();await combinedRow.getByRole('searchbox',{name:'Search message fields'}).fill('Device tags');
  check(await combinedRow.getByText('No matching fields',{exact:true}).isVisible(),'List fields are excluded from text chips');
  await combinedRow.getByText('Insert field',{exact:true}).click();
  for(const name of ['Cacti location','Collector name','Device external ID','Last known SNMP uptime (ticks)','Last known SNMP uptime','Last failed datetime','Last restored datetime','Last status error']) {
   await combinedRow.getByText('Insert field',{exact:true}).click();await combinedRow.getByRole('searchbox',{name:'Search message fields'}).fill(name);
   await combinedRow.getByRole('button',{name,exact:true}).click();
  }
  check(await combined.locator('.alarmmanagerMessageChip').count()===9,'All additional Cacti context fields insert as chips');
  const sentence='Device {{device.id}} with description {{device.description}} went down at {{event.occurred_at}}';
  await combined.fill(sentence);
  const combinedMapping=JSON.parse(await page.locator('[name=mapping_json]').inputValue());
  check(combinedMapping.default[0].template===sentence&&!('source' in combinedMapping.default[0])&&!('constant' in combinedMapping.default[0]),'Combined text serializes without conflicting modes');
  await load({id:4,name:'Saved combined',type:'restapi',transport:{url:'https://example.test',mapping:combinedMapping}});
  check(await page.locator('.alarmmanagerMappingRow').first().getByLabel('Template syntax',{exact:true}).inputValue()===sentence,'Saved combined text round trips');
  check(await page.locator('.alarmmanagerMappingRow').first().locator('.alarmmanagerMessageChip').count()===3,'Saved syntax reopens as friendly chips');
  await page.locator('.alarmmanagerDashboardCard > summary').click();
  await page.locator('.alarmmanagerMappingRow').first().screenshot({path:path.join(shots,'alarmmanager-message-chips.png')});
  await load({name:'Combined email',type:'email'});
  const emailSubject=page.locator('.alarmmanagerMappingRow').first();await emailSubject.getByLabel('Cacti Value',{exact:true}).selectOption('template');await emailSubject.getByLabel('Combined text',{exact:true}).fill(sentence);
  check(JSON.parse(await page.locator('[name=mapping_json]').inputValue()).default.find(field=>field.to==='subject').template===sentence,'Email subject accepts combined text');
  await page.setViewportSize({width:390,height:844});check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Combined editor fits mobile');
  await emailSubject.getByRole('button',{name:'Use device message',exact:true}).click();
  check(await emailSubject.locator('.alarmmanagerMessageChip').count()===9,'Shared device message inserts chips on mobile');
  check((await page.locator('.alarmmanagerMessagePreview').first().textContent()).includes('Custom device offline'),'Alarm Summary preview uses the configured device summary');
  await emailSubject.screenshot({path:path.join(shots,'alarmmanager-message-chips-mobile.png')});
  await load({id:10,name:'Deletable draft',type:'restapi',enabled:false});
  await page.locator('.alarmmanagerDashboardCard > summary').click();
  const deletion=page.locator('form:has([name=action][value=endpoint_delete])');
  check(await deletion.getByRole('button',{name:'Delete endpoint',exact:true}).isVisible(),'Saved endpoint has a delete action');
  check(await deletion.locator('[name=confirmed]').getAttribute('required')!==null,'Deletion requires confirmation');
  check(await deletion.locator('[name=id]').inputValue()==='10','Delete form targets the saved endpoint');
  check(await deletion.locator('[name=mapping_json]').count()===0,'Delete is independent of editor validation');
  for(const width of [390,1280]) {
   await page.setViewportSize({width,height:844});
   const positions=await deletion.evaluate(form=>{const rect=selector=>form.querySelector(selector).getBoundingClientRect();return {warning:rect('p').bottom,confirmation:rect('label').top,confirmationBottom:rect('label').bottom,button:rect('button').top};});
   check(positions.confirmation>=positions.warning&&positions.button>=positions.confirmationBottom,'Deletion warning, confirmation and button occupy separate rows');
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Endpoint deletion fits the viewport');
  }
  await load({name:'Unsaved draft',type:'restapi'});check(await page.getByRole('button',{name:'Delete endpoint',exact:true}).count()===0,'Unsaved drafts have no delete action');
  const intents=await page.locator('.alarmmanagerEndpointEditor').evaluate(form=>{
   const submissions=[];
   form.noValidate=true;
   form.addEventListener('submit',event=>{
    event.preventDefault();
    // Reproduce Cacti applySkin's submit debounce before form serialization.
    form.querySelectorAll('button[type=submit]').forEach(button=>button.disabled=true);
    submissions.push(new FormData(form).getAll('test_destination'));
   });
   const buttons=[form.querySelector('button[name=test_destination]'),form.querySelector('button[type=submit]'),null];
   for(const button of buttons){
    form.querySelectorAll('button[type=submit]').forEach(item=>item.disabled=false);
    if(button)form.requestSubmit(button);else form.requestSubmit();
   }
   return submissions;
  });
  assert.deepEqual(intents,[['yes'],[],[]]);checks++;
  check(errors.length===0,'No browser errors');console.log(`PASS: ${checks} rendered endpoint checks`);
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
