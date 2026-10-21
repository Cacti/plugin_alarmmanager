// SPDX-License-Identifier: GPL-2.0-or-later
// Local Cacti fixture only; validates plugin-wide Select2 controls without deliveries.
const {chromium}=require(process.env.ALARMMANAGER_PLAYWRIGHT_MODULE||'playwright');
const assert=require('node:assert/strict');
(async()=>{
 const base=process.env.ALARMMANAGER_BROWSER_URL;assert(base?.startsWith('http://127.0.0.1:'));
 const browser=await chromium.launch({headless:true,executablePath:process.env.ALARMMANAGER_BROWSER_BINARY});
 const context=await browser.newContext(),page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 let checks=0;const check=(ok,label)=>{assert(ok,label);checks++;};
 async function enhanced(label){check(await page.locator('#alarmmanager_app select:not(.select2-hidden-accessible)').count()===0,label);}
 try {
  await context.request.get(base+'/__fixture/login?user=1&theme=modern');
  for(const tab of ['dashboard','conditions','events','decisions']){
   const response=await page.goto(base+'/plugins/alarmmanager/alarmmanager.php?tab='+tab+'&clear=1');check(response.status()===200,tab+' loads');await enhanced(tab+' dropdowns use Select2');
  }
  for(const section of ['endpoints','rules','collectors','credentials','test','configuration','daemon','queue','health']){
   const response=await page.goto(base+'/plugins/alarmmanager/alarmmanager_admin.php?section='+section);check(response.status()===200,section+' loads');await enhanced(section+' dropdowns use Select2');
  }
  await page.goto(base+'/plugins/alarmmanager/alarmmanager_admin.php?section=rules');
  const builder=page.locator('#alarmmanager_new_rule .alarmmanagerQueryBuilder');
  await builder.locator('select[aria-label="Search field"] + .select2 .select2-selection').click();
  await page.getByRole('option',{name:'Device Template',exact:true}).click();
  check(await builder.locator('select[aria-label="Search value"].select2-hidden-accessible').count()===1,'A dynamically created template picker uses Select2');
  await builder.getByRole('button',{name:'Add AND',exact:true}).click();await enhanced('Added condition dropdowns use Select2');
  await builder.getByRole('button',{name:'Remove condition',exact:true}).last().click();
  check(await page.locator('.select2-dropdown').count()===0,'Rebuilding conditions removes old dropdown menus');
  const source=page.locator('#alarmmanager_new_rule [name="match_sources[]"]');
  await page.locator('#alarmmanager_new_rule').getByLabel('Filter by alarm source',{exact:true}).check();
  await source.locator('+ .select2 .select2-selection').click();
  await page.getByRole('option',{name:'Thold',exact:true}).click();
  check(await source.evaluate(e=>Array.from(e.selectedOptions,o=>o.value).includes('thold')),'Source multiselect selection works through the dropdown');
  await page.keyboard.press('Escape');
  await page.goto(base+'/plugins/alarmmanager/alarmmanager_admin.php?section=endpoints');
  const endpoint=page.locator('details.alarmmanagerDashboardCard').first();await endpoint.locator('summary').first().click();
  await endpoint.getByRole('button',{name:'Add field',exact:true}).click();await enhanced('Added mapping dropdowns use Select2');
  const state=endpoint.locator('.alarmmanagerMappingState');await state.locator('summary').click();
  await state.locator('select + .select2 .select2-selection').click();await page.getByRole('option',{name:'Recovery',exact:true}).click();
  await endpoint.getByRole('button',{name:'Add field',exact:true}).click();
  check(JSON.parse(await endpoint.locator('[name=mapping_json]').inputValue()).recovered.length===1,'Select2 change switches the message mapping state');
  await page.setViewportSize({width:390,height:844});
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Select2 controls fit the mobile viewport');
  if(process.env.ALARMMANAGER_BROWSER_SCREENSHOTS)await page.screenshot({path:process.env.ALARMMANAGER_BROWSER_SCREENSHOTS+'/select2-mobile.png',fullPage:true});
  check(errors.length===0,'No browser runtime errors: '+errors.join('; '));console.log(`PASS: ${checks} plugin-wide Select2 browser checks`);
 } finally {await context.close();await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
