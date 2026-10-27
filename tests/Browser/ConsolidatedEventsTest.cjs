// SPDX-License-Identifier: GPL-2.0-or-later
// Read and replay only against the disposable loopback fixture.
const {chromium}=require(process.env.ALARMMANAGER_PLAYWRIGHT_MODULE||'playwright');
const assert=require('node:assert/strict');
(async()=>{
 const base=process.env.ALARMMANAGER_BROWSER_URL;
 assert(base&&base.startsWith('http://127.0.0.1:'));
 const browser=await chromium.launch({headless:true,executablePath:process.env.ALARMMANAGER_BROWSER_BINARY});
 const context=await browser.newContext(),page=await context.newPage(),errors=[];
 page.on('pageerror',e=>errors.push(e.message));let checks=0;
 const check=(ok,label)=>{assert(ok,label);checks++;};
 try {
  await context.request.get(base+'/__fixture/login?user=1&theme=modern');
  await page.goto(base+'/plugins/alarmmanager/alarmmanager.php?tab=events&clear=1');
  const nav=page.getByRole('navigation',{name:'Alarm Manager pages'});
  check(await nav.getByRole('link').count()===3,'Only Dashboard, Active conditions and Events remain; routing decisions are an Events column');
  check(await page.getByRole('columnheader',{name:'Integration status',exact:true}).count()===1,'Events has integration status column');
  check(await page.locator('.tableRow').count()===50,'Events keeps bounded pagination');
  const mixed=page.getByRole('cell',{name:'1 Delivered · 1 Failed',exact:true});
  check(await mixed.count()===1,'Multiple integration outcomes occupy one event row');
  const row=mixed.locator('..');await row.locator('a[href*="event_id="]').first().click();
  await page.getByText('Selected event details',{exact:true}).waitFor();
  check(await page.getByRole('cell',{name:'fixture_failure',exact:true}).count()>=1,'Details include delivery errors and attempt outcomes');
  check(await page.getByRole('cell',{name:'failure',exact:true}).count()===1,'Delivery attempts appear in event details');
  await context.request.get(base+'/__fixture/login?user=2&theme=modern');
  await page.reload();
  check(await page.getByRole('button',{name:'Replay failed job',exact:true}).count()===0,'Read-only users cannot replay an existing failed integration');
  await context.request.get(base+'/__fixture/login?user=1&theme=modern');
  await page.reload();
  const replay=page.locator('form').filter({has:page.getByRole('button',{name:'Replay failed job',exact:true})});
  check(await replay.count()===1,'Admin can replay the failed integration');
  await replay.locator('[name=confirmed]').check();await replay.getByRole('button',{name:'Replay failed job',exact:true}).click();
  await page.waitForURL(u=>u.searchParams.get('tab')==='events'&&Number(u.searchParams.get('event_id'))>0);
  check(await page.getByRole('cell',{name:'1 Delivered · 1 Pending',exact:true}).count()===1,'Replay returns to event and updates integration status');
  await context.request.get(base+'/__fixture/login?user=2&theme=modern');
  await page.reload();
  check(await page.getByRole('button',{name:'Replay failed job',exact:true}).count()===0,'Read-only details have no replay actions');
  await page.setViewportSize({width:390,height:844});
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Event details fit mobile viewport');
  check(!(await page.content()).includes('HIDDEN_FORBIDDEN_MARKER'),'Hidden devices are excluded');
  if(process.env.ALARMMANAGER_BROWSER_SCREENSHOTS)await page.screenshot({path:process.env.ALARMMANAGER_BROWSER_SCREENSHOTS+'/events-mobile.png',fullPage:true});
  check(errors.length===0,'No browser runtime errors');
  console.log(`PASS: ${checks} consolidated Events browser checks`);
 } finally {await context.close();await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
