/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/
const {chromium}=require(process.env.ALARMMANAGER_PLAYWRIGHT_MODULE||'playwright');
const {execFileSync}=require('node:child_process');
const path=require('node:path'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'../..'),fixture=path.join(root,'tests/fixtures/rule_editor.php');
const env={...process.env,CACTI_TEST_BOOTSTRAP:'1'},cacti=process.env.CACTI_PATH;
if(!cacti)throw Error('CACTI_PATH required for the Cacti jQuery and Select2 assets');
const render=()=>execFileSync('php',[fixture],{env,encoding:'utf8'});
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.ALARMMANAGER_BROWSER_BINARY});
 const page=await browser.newPage({viewport:{width:1280,height:1000}}),errors=[];
 page.on('pageerror',error=>errors.push(error.message));let checks=0;
 const check=(ok,label)=>{assert(ok,label);checks++;};
 try{
  await page.setContent(render());
  await page.addStyleTag({path:path.join(cacti,'include/themes/modern/main.css')});
  await page.addStyleTag({path:path.join(cacti,'include/themes/modern/select2.css')});
  await page.addStyleTag({content:'html,body{height:auto;overflow:visible}body{font:14px Arial,sans-serif;margin:0;padding:16px;box-sizing:border-box}'});
  await page.addStyleTag({path:path.join(root,'css/dashboard.css')});
  await page.addScriptTag({path:path.join(cacti,'include/js/jquery.js')});
  await page.addScriptTag({path:path.join(cacti,'include/js/select2.js')});
  await page.addScriptTag({path:path.join(root,'js/dashboard.js')});
  await page.evaluate(()=>jQuery('select').select2({width:'100%'}));
  const form=page.locator('#alarmmanager_new_rule form');
  check(await form.locator('[name=policy_json]').count()===0,'Creating a rule requires no policy JSON');
  check(await form.locator('[name=schedule_start]').isHidden(),'Schedules start collapsed');
  await form.getByLabel('Rule name',{exact:true}).fill('Critical network alarms');
  check(!(await form.evaluate(f=>f.checkValidity())),'Destination must be chosen explicitly');
  await form.getByLabel('Operations webhook',{exact:true}).check();
  check(await form.getByRole('group',{name:'Alarm Destination',exact:true}).count()===1,'Destination uses a labeled checkbox group');
  await form.getByLabel('Slack',{exact:true}).check();await form.getByLabel('Operations webhook',{exact:true}).uncheck();
  check(await form.evaluate(f=>f.checkValidity()),'Any selected destination satisfies validation');
  await form.getByLabel('Slack',{exact:true}).uncheck();
  check(!(await form.evaluate(f=>f.checkValidity())),'Clearing all destinations prevents submission');
  await form.getByLabel('Operations webhook',{exact:true}).check();
  check(await form.evaluate(f=>f.checkValidity()),'Default rule can be created without optional settings');
  check(await form.getByLabel('Applies to all alarm sources',{exact:true}).isChecked(),'New rules match all alarm sources');
  const alarm=form.locator('.alarmmanagerQueryBuilder').filter({has:page.locator('[name=alarm_query]')});
  check(await alarm.isHidden(),'All alarm types hides the builder');
  await form.getByLabel('Applies to all alarm sources',{exact:true}).uncheck();
  check(await alarm.isVisible(),'Alarm conditions appear when all alarm sources is unchecked');
  await alarm.getByLabel('Search value').selectOption('syslog',{force:true});
  await alarm.getByRole('button',{name:'Add AND',exact:true}).click();
  await alarm.getByLabel('Search field').nth(1).selectOption('severity',{force:true});
  await alarm.getByLabel('Search value').nth(1).selectOption('critical',{force:true});
  await alarm.getByRole('button',{name:'Add AND',exact:true}).click();
  await alarm.getByLabel('Search field').nth(2).selectOption('device_id',{force:true});
  await alarm.getByLabel('Search value').nth(2).selectOption('42',{force:true});
  await form.getByLabel('Only notify during these hours',{exact:true}).check();
  await form.locator('[name="schedule_days[]"]').selectOption(['1','5'],{force:true});
  await form.locator('[name=schedule_timezone]').selectOption('America/Toronto',{force:true});
  await form.locator('[name=schedule_start]').fill('22:00');
  await form.locator('[name=schedule_end]').fill('06:00');
  await form.getByLabel('Pause notifications during a maintenance window',{exact:true}).check();
  await form.locator('[name="maintenance_schedule_days[]"]').selectOption(['7'],{force:true});
  await form.locator('[name=maintenance_schedule_timezone]').selectOption('UTC',{force:true});
  await form.locator('[name=cooldown_seconds]').fill('60');
  await form.locator('[name=duplicate_seconds]').fill('120');
  check(await form.evaluate(f=>f.checkValidity()),'A filtered rule with schedules is valid');
  const encoded=await form.evaluate(f=>new URLSearchParams(new FormData(f)).toString());
  const policy=JSON.parse(execFileSync('php',[fixture,'--parse'],{input:encoded,env,encoding:'utf8'}));
  const conditions=JSON.parse(policy.alarm_query).conditions;
  check(conditions[0].value==='syslog'&&conditions[1].field==='severity'&&conditions[1].value==='critical'&&conditions[2].field==='device_id'&&conditions[2].value==='42','Alarm builder round trips through PHP validation');
  check(policy.schedule.timezone==='America/Toronto'&&policy.schedule.start==='22:00'&&policy.schedule.end==='06:00','Notification schedule round trips');
  check(policy.maintenance_schedule.days[0]===7&&policy.cooldown_seconds===60&&policy.duplicate_seconds===120,'Suppression controls round trip');
  await form.getByLabel('Applies to all alarm sources',{exact:true}).check();
  await form.getByLabel('Only notify during these hours',{exact:true}).uncheck();
  const off=JSON.parse(execFileSync('php',[fixture,'--parse'],{input:await form.evaluate(f=>new URLSearchParams(new FormData(f)).toString()),env,encoding:'utf8'}));
  check(off.alarm_query===''&&off.schedule===null,'Turning off a filter or schedule clears the restriction');
  await form.getByLabel('Applies to all alarm sources',{exact:true}).uncheck();
  const saved=page.locator('.alarmmanagerRule').nth(1);await saved.locator('summary').first().click();
  check((await saved.locator('[name=alarm_query]').inputValue()).includes('retired_source'),'Saved source remains selectable after its history is gone');
  check((await saved.locator('[name=alarm_query]').inputValue()).includes('999'),'Saved devices outside the picker remain selected');
  check(await page.locator('img').count()===0,'Saved rule names are escaped');
  for(const theme of ['modern','dark']){
   await page.locator('#alarmmanager_app').evaluate((app,value)=>app.dataset.theme=value,theme);
   for(const width of [1280,390]){
    await page.setViewportSize({width,height:1000});
    if(!(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1))){console.log(await page.evaluate(()=>({width:innerWidth,scroll:document.documentElement.scrollWidth,nodes:[...document.querySelectorAll('*')].filter(e=>e.getBoundingClientRect().right>innerWidth+1).slice(0,12).map(e=>({tag:e.tagName,cls:e.className,width:e.getBoundingClientRect().width,right:e.getBoundingClientRect().right}))})));await page.screenshot({path:'/tmp/am-rule-overflow.png',fullPage:true});}
    check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),theme+' fits '+width+'px');
   }
  }
  await saved.locator('summary').first().click();
  await page.evaluate(()=>window.scrollTo(0,0));
  await page.screenshot({path:'/tmp/alarmmanager-rule-editor-mobile.png',fullPage:true});
  await page.setViewportSize({width:1280,height:1000});
  await page.locator('#alarmmanager_app').evaluate(app=>app.dataset.theme='modern');
  await page.evaluate(()=>window.scrollTo(0,0));
  await page.screenshot({path:'/tmp/alarmmanager-rule-editor-desktop.png',fullPage:true});
  await page.setContent('<main id="alarmmanager_app">'+execFileSync('php',[fixture,'--empty'],{env,encoding:'utf8'})+'</main>');
  check(await page.getByText('No alarm sources detected yet. Sources appear after their first alarm.',{exact:true}).count()===1,'Empty history explains why no source choices are available');
  check(await page.getByRole('link',{name:'Create integration',exact:true}).count()===1,'Empty rules page points to the missing prerequisite');
  check(await page.getByRole('button',{name:'Create rule',exact:true}).isDisabled(),'Cannot create a rule without an integration');
  check(errors.length===0,'No browser runtime errors');
  console.log(`PASS: ${checks} rule-editor browser checks, including browser-to-PHP form parsing`);
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
