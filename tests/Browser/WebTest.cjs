// SPDX-License-Identifier: GPL-2.0-or-later
// Optional development harness; no Node.js dependency in the deployed plugin.
const {chromium}=require(process.env.ALARMMANAGER_PLAYWRIGHT_MODULE||'playwright');
const fs=require('node:fs'), path=require('node:path');
(async()=>{
 let checks=0;const check=(condition,label)=>{if(!condition)throw Error(label);checks++;};
 const base=process.env.ALARMMANAGER_BROWSER_URL;
 if(!base||!base.startsWith('http://127.0.0.1:'))throw Error('Loopback fixture URL required');
 const browser=await chromium.launch({executablePath:process.env.ALARMMANAGER_BROWSER_BINARY,headless:true,args:['--no-sandbox','--no-zygote','--single-process']});
 const context=await browser.newContext({viewport:{width:1440,height:1100}}), page=await context.newPage();
 const external=[],errors=[],responses=[];page.on('response',r=>responses.push([r.status(),r.url()]));page.on('request',request=>{if(!request.url().startsWith(base+'/'))external.push(request.url());});page.on('pageerror',error=>errors.push(error.message));
 const screenDir=process.env.ALARMMANAGER_BROWSER_SCREENSHOTS;if(screenDir)fs.mkdirSync(screenDir,{recursive:true});
 async function login(user,theme){await page.goto(`${base}/__fixture/login?user=${user}&theme=${theme}`);await page.waitForFunction(()=>document.querySelector('#alarmmanager_dashboard_status')?.textContent.startsWith('Updated'));await page.waitForFunction(()=>document.querySelectorAll('#alarmmanager_dashboard .bb').length>=6);}
 try {
  // Wait for the fixture listener without a fixed blocking sleep.
  for(let attempt=0;attempt<30;attempt++){try{await context.request.get(base+'/__fixture/login?user=1');break;}catch(error){if(attempt===29)throw error;await new Promise(resolve=>setTimeout(resolve,100));}}
  await login(1,'modern');check(await page.locator('#tab-alarmmanager').count()===1,'Alarm Manager top-level tab');check(await page.getByRole('link',{name:'Administration',exact:true}).count()===1,'Administrator return link');check(await page.locator('.bb').count()>=6,'Local Billboard charts rendered');
  check((await page.locator('#alarmmanager_historical').innerText()).includes('All 80'),'Administrator count');
  check(await page.locator('.alarmmanagerDashboardCard').count()===9,'Adapted panel grid');
  check(await page.locator('#alarmmanager_chart_transitions .bb-bar').evaluateAll(paths=>paths.some(p=>p.getBoundingClientRect().height>2)),'Transition chart contains nonzero UTC data');
  check(await page.evaluate(()=>window.ALARM_BAD===undefined),'Dashboard text escaped');
  if(screenDir)await page.screenshot({path:path.join(screenDir,'modern-dashboard.png'),fullPage:true});
  const toggle=page.locator('#alarmmanager_panel_source .alarmmanagerCardToggle');await toggle.click();check(await toggle.getAttribute('aria-expanded')==='false','Panel collapse');await toggle.click();
  await page.locator('#alarmmanager_legend_severity a').first().click();await page.waitForURL('**/alarmmanager.php?*tab=events*');check(await page.locator('.alarmmanagerTable tbody tr').count()>0,'Statistics links matching records');
  await page.goto(base+'/plugins/alarmmanager/alarmmanager_admin.php?section=credentials');
  check(await page.getByRole('link',{name:'View alarms',exact:true}).count()===1,'Administration links back to reports');
  check(await page.locator('a[href*="alarmmanager.php?tab="]').count()===0,'Report tabs excluded from administration');
  check(!(await page.content()).includes('WEB_BROWSER_SECRET')&&!(await page.content()).includes('am1:'),'Credential DOM redaction');
  check(await page.locator('input[type=password]').first().inputValue()==='','Existing password blank');
  await page.goto(base+'/plugins/alarmmanager/alarmmanager_admin.php?section=endpoints');
  check(await page.evaluate(()=>window.ALARM_BAD===undefined),'Endpoint name escaped');
  check(await page.locator('input[name=id]:not([type=hidden])').count()===0,'Record IDs are not editable');
  check(await page.locator('details').last().locator('input[name=id]').count()===0,'New endpoint does not submit an ID');
  const detail=page.locator('details').first();await detail.locator('summary').click();
  const auth=detail.locator('[name=auth_type]'), originalAuth=await auth.inputValue();
  for(const type of ['none','bearer','basic','headers']) {
   await auth.selectOption(type,{force:true});
   for(const group of ['bearer','basic','headers']) {
    check(await detail.locator(`[data-auth-type="${group}"]`).isVisible()===(group===type),`${type}: relevant authentication fields only`);
   }
  }
  await auth.selectOption(originalAuth,{force:true});
  const row=detail.locator('.alarmmanagerMappingRow').first();await row.locator('input').first().fill('host');
  check(JSON.parse(await detail.locator('[name=mapping_json]').inputValue()).default[0].to==='host','No-code field mapping editor');

  await detail.locator('button[type=submit]').click();await page.waitForURL('**/alarmmanager_admin.php');
  check((await page.locator('[role=status]').innerText()).includes('Configuration saved'),'Real CSRF protected endpoint save');
  const forbidden=await context.request.post(base+'/plugins/alarmmanager/alarmmanager_admin.php',{form:{action:'credential_remove',id:'1',__csrf_magic:'invalid'}});
  check((await forbidden.text()).includes('Action failed'),'Real CSRF rejects invalid token');
  await page.goto(base+'/plugins/alarmmanager/alarmmanager_admin.php?section=test');
  await page.locator('form.alarmmanagerEditor').first().locator('button[type=submit]').click();await page.waitForURL('**/alarmmanager_admin.php');
  check((await page.locator('[role=status]').innerText()).includes('REDACTED'),'Administrator traffic-free preview');
  await login(2,'modern');check((await page.locator('#alarmmanager_historical').innerText()).includes('All 60'),'Read-only scoped dashboard count');
  check(await page.getByRole('link',{name:'Administration',exact:true}).count()===0,'Read-only administration hidden');
  check(!(await page.locator('#alarmmanager_panel_workers').isVisible()),'Read-only worker details hidden');
  await page.goto(base+'/plugins/alarmmanager/alarmmanager.php?tab=events');
  check(await page.locator('.alarmmanagerTable tbody tr').count()===50,'Server page bound');check(!(await page.content()).includes('HIDDEN_FORBIDDEN_MARKER'),'Hidden alarms excluded from page');
  check(await page.evaluate(()=>window.ALARM_BAD===undefined),'Event summary escaping');
  await page.getByRole('link',{name:'Next',exact:true}).click();await page.getByText('Page 2',{exact:true}).waitFor();check(await page.locator('.alarmmanagerTable tbody tr').count()===10,'Second filtered page');
  await page.locator('.alarmmanagerTable tbody tr a').first().click();await page.getByText('Selected event details',{exact:true}).waitFor();check(await page.locator('.alarmmanagerEventDetail').count()===1&&await page.evaluate(()=>window.ALARM_BAD===undefined),'Scoped event detail and escaping');
  const deniedAdmin=await context.request.get(base+'/plugins/alarmmanager/alarmmanager_admin.php');check(deniedAdmin.status()===403,'Direct administration GET denied');
  const deniedPost=await context.request.post(base+'/plugins/alarmmanager/alarmmanager_admin.php',{form:{action:'credential_remove',id:'1'}});check(deniedPost.status()===403,'Direct administration POST denied');
  for(const theme of ['modern','dark','classic']){
   await page.setViewportSize({width:390,height:844});await login(2,theme);
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1),`${theme} narrow layout has no document overflow`);
   check(await page.locator('.bb').count()>=6,`${theme} narrow charts rendered`);
   if(screenDir)await page.screenshot({path:path.join(screenDir,theme+'-mobile.png'),fullPage:true});
  }
  await page.goto(base+'/__fixture/login?user=3&theme=modern');const denied=await context.request.get(base+'/plugins/alarmmanager/alarmmanager.php?action=dashboard_data');check(denied.status()===403,'Unassigned realm API denied');
  check(external.length===0,'No external/CDN asset requests');check(errors.length===0,'No browser runtime errors');
  console.log(`PASS: ${checks} browser checks; Chrome ${await browser.version()}; modern/dark/classic at desktop and 390px; all requests loopback.`);
 } catch(error) { console.error(JSON.stringify({url:page.url(),responses:responses.slice(-8),errors,body:(await page.locator('body').innerText()).slice(0,500)}));throw error; } finally {await context.close();await browser.close();}
})().catch(error=>{console.error(error.stack);process.exitCode=1;});
