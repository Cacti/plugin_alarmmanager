// SPDX-License-Identifier: GPL-2.0-or-later
const {chromium}=require(process.env.ALARMMANAGER_PLAYWRIGHT_MODULE||'playwright');
const assert=require('node:assert/strict');
(async()=>{
 const base=process.env.ALARMMANAGER_BROWSER_URL;
 assert(base&&base.startsWith('http://127.0.0.1:'),'Disposable loopback fixture required');
 const browser=await chromium.launch({headless:true,executablePath:process.env.ALARMMANAGER_BROWSER_BINARY});
 const page=await browser.newPage(),errors=[];
 page.on('pageerror',error=>errors.push(error.message));
 try{
  await page.goto(base+'/__fixture/login?user=1');
  await page.goto(base+'/plugins/alarmmanager/alarmmanager_admin.php?section=queue');
  if(await page.getByRole('button',{name:'Resume delivery',exact:true}).isVisible()){
   await page.getByRole('button',{name:'Resume delivery',exact:true}).click();
   await page.getByRole('button',{name:'Pause delivery',exact:true}).waitFor();
  }
  assert(await page.getByRole('button',{name:'Pause delivery',exact:true}).isVisible());
  assert(await page.getByText('Waiting messages (first 100)',{exact:true}).isVisible());
  assert(await page.locator('input[name=confirmed]').evaluate(input=>input.required));
  await Promise.all([page.waitForURL('**/alarmmanager_admin.php?section=queue'),page.getByRole('button',{name:'Pause delivery',exact:true}).click()]);
  await page.getByRole('button',{name:'Resume delivery',exact:true}).waitFor();
  await page.goto(base+'/plugins/alarmmanager/alarmmanager.php');
  await page.getByText(' · Delivery paused',{exact:true}).waitFor();
  assert(await page.getByRole('link',{name:/Queued messages \d+/}).isVisible());
  await page.getByRole('link',{name:'Manage queue',exact:true}).click();
  await page.getByRole('button',{name:'Resume delivery',exact:true}).click();
  await page.getByRole('button',{name:'Pause delivery',exact:true}).waitFor();
  for(const width of [1440,390]){
   await page.setViewportSize({width,height:1000});
   assert(await page.getByRole('button',{name:'Clear queue',exact:true}).isVisible());
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Queue fits viewport');
  }
  await page.locator('input[name=confirmed]').check();
  await page.getByRole('button',{name:'Clear queue',exact:true}).click();
  await page.getByText('No waiting messages.',{exact:true}).waitFor();
  await page.goto(base+'/plugins/alarmmanager/alarmmanager.php');
  await page.getByRole('link',{name:'Queued messages 0',exact:true}).waitFor();
  assert.deepEqual(errors,[]);
  console.log('Queue browser checks passed: pause/resume, dashboard status/count, confirmation, clear, desktop/mobile.');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
