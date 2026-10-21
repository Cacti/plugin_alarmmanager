// SPDX-License-Identifier: GPL-2.0-or-later
// Saves rules only in the disposable loopback Cacti fixture; no external delivery.
const {chromium}=require(process.env.ALARMMANAGER_PLAYWRIGHT_MODULE||'playwright');
const assert=require('node:assert/strict');
(async()=>{
 const base=process.env.ALARMMANAGER_BROWSER_URL;
 assert(base&&base.startsWith('http://127.0.0.1:'));
 const browser=await chromium.launch({headless:true,executablePath:process.env.ALARMMANAGER_BROWSER_BINARY});
 const context=await browser.newContext(),page=await context.newPage(),errors=[];
 page.on('pageerror',error=>errors.push(error.message));let checks=0;
 const check=(ok,label)=>{assert(ok,label);checks++;};
 try {
  await context.request.get(base+'/__fixture/login?user=1&theme=modern');
  await page.goto(base+'/plugins/alarmmanager/alarmmanager_admin.php?section=rules');
  const form=page.locator('#alarmmanager_new_rule form'),builder=form.locator('.alarmmanagerQueryBuilder');
  check(await builder.getByLabel('Search field').locator('option').count()===7,'All seven requested Cacti host fields are offered');
  const name='Device routing browser '+Date.now();await form.getByLabel('Rule name',{exact:true}).fill(name);
  await form.locator('[name="endpoints[]"][value="1"]').check();
  await form.locator('[name="endpoints[]"][value="2"]').check();
  const field=()=>builder.getByLabel('Search field'),operator=()=>builder.getByLabel('Search operator'),value=()=>builder.getByLabel('Search value');
  await field().nth(0).selectOption('location',{force:true});await operator().nth(0).selectOption('=',{force:true});await value().nth(0).fill('hello');
  await builder.getByRole('button',{name:'Add AND',exact:true}).click();await field().nth(1).selectOption('poller_id',{force:true});
  await value().nth(1).selectOption('1',{force:true});
  check((await value().nth(1).locator('option[value="1"]').innerText()).includes('Main collector'),'Collector picker shows a name and ID');
  await builder.getByRole('button',{name:'Add AND',exact:true}).click();await field().nth(2).selectOption('host_template_id',{force:true});
  check(!(await form.evaluate(f=>f.checkValidity())),'A template condition requires an explicit template choice');
  await value().nth(2).selectOption('1',{force:true});
  check((await value().nth(2).locator('option[value="1"]').innerText()).includes('Test template'),'Template picker shows template names');
  await builder.getByRole('button',{name:'Add OR',exact:true}).click();await field().nth(3).selectOption('description',{force:true});await value().nth(3).fill('test');
  const query=JSON.parse(await form.locator('[name=device_query]').inputValue());
  check(query.conditions[1].join==='AND'&&query.conditions[3].join==='OR','AND and OR connectors serialize');
  check(query.conditions[2].value==='1'&&query.conditions[3].operator==='contains','Named template and contains conditions serialize');
  check(await form.evaluate(f=>f.checkValidity()),'A complete device rule with multiple integrations is valid');
  await form.getByRole('button',{name:'Create rule',exact:true}).click();await page.waitForURL('**section=rules');
  const saved=page.locator('details.alarmmanagerRule').filter({hasText:name});
  check(await saved.count()===1,'Rule saves successfully through Cacti CSRF handling');
  await saved.locator('summary').first().click();
  check(JSON.stringify(JSON.parse(await saved.locator('[name=device_query]').inputValue()))===JSON.stringify(query),'Device conditions survive save and reload');
  check(JSON.stringify(await saved.locator('[name="endpoints[]"]').first().evaluate(e=>Array.from(e.form.querySelectorAll('[name="endpoints[]"]:checked'),o=>o.value)))===JSON.stringify(['1','2']),'Both integration endpoints survive save and reload');
  check(await saved.getByLabel('Search field').count()===4,'Saved rule builder initializes alongside the new-rule builder');
  await saved.getByRole('button',{name:'Add Group',exact:true}).click();
  const group=saved.locator('.alarmmanagerQueryGroup');await group.getByLabel('Search field').selectOption('site_id',{force:true});await group.getByLabel('Search value').selectOption('1',{force:true});
  check((await group.getByLabel('Search value').locator('option[value="1"]').innerText()).includes('Test site'),'Site picker shows site names');
  await saved.getByRole('button',{name:'Save rule',exact:true}).click();await page.waitForURL('**section=rules');
  const grouped=page.locator('details.alarmmanagerRule').filter({hasText:name});await grouped.locator('summary').first().click();
  check(JSON.parse(await grouped.locator('[name=device_query]').inputValue()).conditions[4].rows[0].field==='site_id','Nested groups survive save and reload');
  await page.setViewportSize({width:390,height:844});
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Rule builder fits mobile viewport');
  if(process.env.ALARMMANAGER_BROWSER_SCREENSHOTS)await grouped.screenshot({path:process.env.ALARMMANAGER_BROWSER_SCREENSHOTS+'/device-rule-mobile.png'});
  check(errors.length===0,'No browser script errors: '+errors.join('; '));
  console.log(`PASS: ${checks} device rule browser checks`);
 } finally {await context.close();await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
