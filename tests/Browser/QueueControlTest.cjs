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
  const counter=page.locator('.alarmmanagerQueueCounter strong');
  const waiting=Number(await counter.textContent());
  assert(Number.isInteger(waiting)&&waiting>=0,'Queue counter shows a count');
  assert(await page.getByText('waiting messages',{exact:true}).isVisible());
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
   assert(await page.getByRole('button',{name:'Clear queue…',exact:true}).isVisible());
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Queue fits viewport');
  }
  const clear=page.getByRole('button',{name:'Clear queue…',exact:true});
  const dialog=page.locator('.ui-dialog');
  if(waiting===0){
   assert(await clear.isDisabled(),'Nothing waiting leaves clear disabled');
   assert.equal(await page.getByRole('button',{name:/View messages|Hide messages/}).count(),0,'No message list without waiting messages');
  }else{
   const toggle=page.getByRole('button',{name:/View messages|Hide messages/});
   const open=(await toggle.getAttribute('aria-expanded'))==='true';
   await toggle.click();
   assert.equal(await toggle.getAttribute('aria-expanded'),String(!open),'Toggle flips the message list');
   assert.equal(await page.locator('#alarmmanager_queue_messages').isVisible(),!open);
   await clear.click();
   await dialog.waitFor();
   assert(await dialog.getByText('Discard all '+waiting+' waiting notifications on this poller?').isVisible(),'Dialog states how many messages are discarded');
   await dialog.getByRole('button',{name:'Cancel',exact:true}).click();
   await dialog.waitFor({state:'detached'});
   assert.equal(Number(await counter.textContent()),waiting,'Cancel leaves the queue untouched');
   await clear.click();
   await dialog.waitFor();
   await Promise.all([page.waitForURL('**/alarmmanager_admin.php?section=queue'),dialog.getByRole('button',{name:'Clear queue',exact:true}).click()]);
   await counter.filter({hasText:/^0$/}).waitFor();
   assert(await clear.isDisabled(),'Cleared queue disables clear');
  }
  await page.goto(base+'/plugins/alarmmanager/alarmmanager.php');
  await page.getByRole('link',{name:/Queued messages \d+/}).waitFor();
  assert.deepEqual(errors,[]);
  console.log('Queue browser checks passed: pause/resume, dashboard status/count, counter, confirm dialog, clear, desktop/mobile.');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
