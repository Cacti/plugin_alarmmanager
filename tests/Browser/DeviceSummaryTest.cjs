// SPDX-License-Identifier: GPL-2.0-or-later
// Disposable local fixture: saving summary settings preserves routing configuration.
const {chromium}=require(process.env.ALARMMANAGER_PLAYWRIGHT_MODULE||'playwright');
const assert=require('node:assert/strict');
(async()=>{
 const base=process.env.ALARMMANAGER_BROWSER_URL;assert(base?.startsWith('http://127.0.0.1:'));
 const browser=await chromium.launch({headless:true,executablePath:process.env.ALARMMANAGER_BROWSER_BINARY});
 const context=await browser.newContext(),page=await context.newPage(),errors=[];
 page.on('pageerror',error=>errors.push(error.message));
 try {
  await context.request.get(base+'/__fixture/login?user=1&theme=modern');
  await page.goto(base+'/plugins/alarmmanager/alarmmanager_admin.php?section=configuration');
  const original=JSON.parse(await page.locator('[name=configuration_json]').inputValue());
  await page.locator('[name=offline_summary]').fill('Device is Offline');
  await page.locator('[name=recovering_summary]').fill('Device is Recovering');
  await page.locator('[name=online_summary]').fill('Device is Online');
  await page.getByRole('button',{name:'Save device summaries',exact:true}).click();
  await page.waitForURL('**/alarmmanager_admin.php?section=configuration');
  assert.equal(await page.locator('[name=offline_summary]').inputValue(),'Device is Offline');
  assert.equal(await page.locator('[name=online_summary]').inputValue(),'Device is Online');
  const saved=JSON.parse(await page.locator('[name=configuration_json]').inputValue());
  assert.deepEqual(saved.device_summaries,{offline:'Device is Offline',recovering:'Device is Recovering',online:'Device is Online'});
  assert.deepEqual(saved.endpoints,original.endpoints);assert.deepEqual(saved.rules,original.rules);
  assert.deepEqual(errors,[]);console.log('PASS: device summary settings save, reload and preserve routing');
 } finally {await context.close();await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
