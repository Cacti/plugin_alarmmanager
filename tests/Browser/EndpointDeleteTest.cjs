// SPDX-License-Identifier: GPL-2.0-or-later
const {chromium}=require(process.env.ALARMMANAGER_PLAYWRIGHT_MODULE||'playwright');
const assert=require('node:assert/strict');
(async()=>{
 const base=process.env.ALARMMANAGER_BROWSER_URL;
 if(!base?.startsWith('http://127.0.0.1:'))throw Error('Loopback fixture URL required');
 const browser=await chromium.launch({headless:true,executablePath:process.env.ALARMMANAGER_BROWSER_BINARY});
 try {
  const context=await browser.newContext(),page=await context.newPage(),errors=[];
  page.on('pageerror',e=>errors.push(e.message));
  await context.request.get(base+'/__fixture/login?user=1&theme=modern');
  const url=base+'/plugins/alarmmanager/alarmmanager_admin.php?section=endpoints';
  await page.goto(url);
  const cards=page.locator('.alarmmanagerDashboardCard'),before=await cards.count();
  const draft=cards.last(),save=draft.locator('form:has([name=action][value=endpoint_save])');
  await save.locator('[name=name]').fill('Deletion browser fixture');
  await save.locator('[name=url]').fill('https://delete.example.test');
  await save.getByRole('button',{name:'Save endpoint',exact:true}).click();
  await page.waitForLoadState('networkidle');
  const saved=cards.filter({has:page.locator('summary').filter({hasText:'Deletion browser fixture'})});
  assert.equal(await saved.count(),1);
  await saved.locator('summary').first().click();
  const deletion=saved.locator('form:has([name=action][value=endpoint_delete])');
  assert.equal(await deletion.locator('[name=confirmed]').getAttribute('required'),'');
  await deletion.locator('[name=confirmed]').check();
  await deletion.getByRole('button',{name:'Delete endpoint',exact:true}).click();
  await page.waitForLoadState('networkidle');
  assert.equal(await saved.count(),0);
  assert.equal(await cards.count(),before);
  assert.deepEqual(errors,[]);
  console.log('Endpoint create and confirmed deletion passed in browser; existing endpoints retained.');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exit(1);});
