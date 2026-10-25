// SPDX-License-Identifier: GPL-2.0-or-later
// Run only against the disposable loopback Cacti fixture. No notifications are sent.
const {chromium}=require(process.env.ALARMMANAGER_PLAYWRIGHT_MODULE||'playwright');
const assert=require('node:assert/strict');
(async()=>{
 const base=process.env.ALARMMANAGER_BROWSER_URL;assert(base?.startsWith('http://127.0.0.1:'));
 const browser=await chromium.launch({headless:true,executablePath:process.env.ALARMMANAGER_BROWSER_BINARY});
 const context=await browser.newContext({viewport:{width:1280,height:1000}}),page=await context.newPage(),errors=[];
 page.on('pageerror',error=>errors.push(error.message));
 const download=async()=>await(await context.request.get(base+'/plugins/alarmmanager/alarmmanager_admin.php?download=configuration')).json();
 try{
  await context.request.get(base+'/__fixture/login?user=1&theme=modern');
  await page.goto(base+'/plugins/alarmmanager/alarmmanager_admin.php?section=rules');
  const original=await download();
  const rule=()=>page.locator('.alarmmanagerRule').filter({has:page.locator('input[name=id][value="1"]')});
  const open=async()=>{await rule().locator(':scope > summary').click();await rule().locator('[data-rule-template="1"] > summary').click();};
  await open();
  const section=()=>rule().locator('[data-rule-template="1"]');
  await section().getByLabel('Customize message for this rule',{exact:true}).check();
  await section().getByRole('button',{name:'Add field',exact:true}).click();
  assert.equal(await section().locator('.alarmmanagerMappingRow').count(),2);
  await section().getByRole('button',{name:'Remove field',exact:true}).last().click();
  assert.equal(await section().locator('.alarmmanagerMappingRow').count(),1);
  await section().locator('.alarmmanagerRawMapping > summary').click();
  const mapping={default:[{to:'text',template:'Admin: {{alarm.summary}}\n{{alarm.details}}',type:'string'}]};
  await section().locator('textarea[name="template_mapping[1]"]').fill(JSON.stringify(mapping));
  await rule().getByRole('button',{name:'Save rule',exact:true}).click();
  await page.waitForURL('**/alarmmanager_admin.php?section=rules');
  const saved=await download();assert.deepEqual(saved.endpoints,original.endpoints);
  assert.equal(saved.rules[0].policy.template_overrides[1].default[0].template,mapping.default[0].template);
  assert(!saved.rules[0].policy.template_overrides[2]);
  await open();assert(await section().getByLabel('Customize message for this rule',{exact:true}).isChecked());
  await page.setViewportSize({width:390,height:844});
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Rule editor must fit on mobile.');
  if(process.env.ALARMMANAGER_RULE_SCREENSHOT)await section().screenshot({path:process.env.ALARMMANAGER_RULE_SCREENSHOT});
  await section().getByLabel('Customize message for this rule',{exact:true}).uncheck();
  await rule().getByRole('button',{name:'Save rule',exact:true}).click();
  await page.waitForURL('**/alarmmanager_admin.php?section=rules');
  assert.deepEqual(await download(),original,'Use destination default removes the rule override.');
  await page.goto(base+'/plugins/alarmmanager/alarmmanager_admin.php?section=endpoints');
  assert(await page.getByRole('group',{name:'Default message template',exact:true,includeHidden:true}).count()>0);
  assert.deepEqual(errors,[]);console.log('PASS: rule template add/remove, save/reload, default fallback, destination isolation and mobile layout');
 }finally{await context.close();await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
