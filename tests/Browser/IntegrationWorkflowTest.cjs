// SPDX-License-Identifier: GPL-2.0-or-later
// Disposable Cacti UI fixture only; tests enqueue but do not run delivery workers.
const {chromium}=require(process.env.ALARMMANAGER_PLAYWRIGHT_MODULE||'playwright');
const assert=require('node:assert/strict');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.ALARMMANAGER_BROWSER_BINARY});
 const context=await browser.newContext(), page=await context.newPage();
 const base=process.env.ALARMMANAGER_BROWSER_URL||'http://127.0.0.1:33078';
 const errors=[],external=[];page.on('pageerror',e=>errors.push(e.message));page.on('request',r=>{if(!r.url().startsWith(base))external.push(r.url());});
 let checks=0;const check=(condition,label)=>{assert(condition,label);checks++;};
 try{
  await context.request.get(base+'/__fixture/login?user=1&theme=modern');
  const admin=base+'/plugins/alarmmanager/alarmmanager_admin.php';
  await page.goto(admin+'?section=endpoints');
  const draftName='Browser script draft '+Date.now();
  const initial=await page.locator('details.alarmmanagerDashboardCard').count();
  check(await page.getByText('New endpoint',{exact:true}).count()===0,'Loading the section shows no blank endpoint draft');
  const gallery=page.locator('details.alarmmanagerTemplateGallery');
  check(await gallery.count()===1&&await page.locator('[name=integration_template]').count()===0,'Templates use the card gallery instead of a dropdown');
  /** Open the gallery and choose a template card, as an administrator would. */
  const chooseTemplate=async(template)=>{
   if(!(await gallery.evaluate(el=>el.open)))await gallery.locator('summary').click();
   await gallery.locator('a.alarmmanagerTemplateCard[href$="integration_template='+template+'"]').click();
   await page.waitForURL('**integration_template='+template,{timeout:5000});
  };
  await gallery.locator('summary').click();
  await gallery.locator('[data-template-search]').fill('syslog');
  check(await gallery.locator('a.alarmmanagerTemplateCard:visible').count()===1,'Search narrows the gallery to matching cards');
  await gallery.locator('[data-template-search]').fill('no such integration');
  check(await gallery.locator('.alarmmanagerTemplateEmpty').isVisible(),'Search explains when nothing matches');
  await gallery.locator('[data-template-search]').fill('');
  for(const template of ['webhook','restapi','script']) {
   await chooseTemplate(template);
   const draft=page.locator('details.alarmmanagerDashboardCard').last();
   check(!(await draft.locator('[name=enabled]').isChecked()),'Template starts disabled');
   check(await page.locator('details.alarmmanagerDashboardCard').count()===initial+1,'Template selection shows exactly one draft and preserves saved integrations');
   check((await draft.locator('[name=type]').inputValue())===template,'Template selects the requested adapter');
   check((await draft.locator('[name=auth_type]').inputValue())==='none','Template copies no credentials');
  }
  const draft=page.locator('details.alarmmanagerDashboardCard').last();
  await draft.locator('[name=name]').fill(draftName);
  await draft.getByRole('button',{name:'Save endpoint',exact:true}).click();
  await page.waitForURL(admin);
  check(await page.locator('details.alarmmanagerDashboardCard').count()===initial+1,'Saving a template creates a new integration');
  const saved=page.locator('details.alarmmanagerDashboardCard').filter({has:page.getByText(draftName,{exact:true})});
  await saved.locator('summary').first().click();
  check(await saved.locator('[name=reference_id_path], [name=reference_url_path]').count()===0,'External reference controls are removed');
  check(!(await saved.locator('[name=enabled]').isChecked()),'Saved draft stays disabled');
  // A provider preset that preselects credential authentication saves the draft
  // before any secret exists; the credential record is attached afterwards.
  const presetName='Preset draft '+Date.now();
  await chooseTemplate('slack');
  const preset=page.locator('details.alarmmanagerDashboardCard').last();
  check((await preset.locator('[name=auth_type]').inputValue())==='bearer','Slack preset preselects bearer authentication');
  await preset.locator('[name=name]').fill(presetName);
  await preset.getByRole('button',{name:'Save endpoint',exact:true}).click();
  await page.waitForURL(admin);
  const presetSaved=page.locator('details.alarmmanagerDashboardCard').filter({has:page.getByText(presetName,{exact:true})});
  check(await presetSaved.count()===1,'Preset draft saves without credentials');
  check(!(await presetSaved.locator('[name=enabled]').isChecked()),'Preset draft stays disabled');
  // Choosing a template and then Cancel removes the unsaved draft entirely.
  const beforeCancel=await page.locator('details.alarmmanagerDashboardCard').count();
  await chooseTemplate('telegram');
  check(await page.locator('details.alarmmanagerDashboardCard').count()===beforeCancel+1,'Cancelled-template draft appears exactly once');
  await page.locator('details.alarmmanagerDashboardCard').last().getByRole('link',{name:'Cancel',exact:true}).click();
  await page.waitForURL(admin,{timeout:5000});
  check(await page.locator('details.alarmmanagerDashboardCard').count()===beforeCancel,'Cancel removes the unsaved draft');
  check(await page.getByText('New endpoint',{exact:true}).count()===0,'Cancel leaves no blank endpoint behind');
  await page.goto(admin);
  const current=page.locator('details.alarmmanagerDashboardCard').filter({has:page.locator('form [name=id][value="1"]')}).first();
  await current.locator('summary').first().click();
  const currentForm=current.locator('form').filter({has:page.locator('[name=action][value=endpoint_save]')});
  await currentForm.locator('[name=enabled]').check();
  await currentForm.getByRole('button',{name:'Test destination',exact:true}).click();
  await page.waitForURL(admin);
  check((await page.locator('#alarmmanager_app [role=status]').innerText()).includes('Test queued for delivery workers; event row'),'Inline test queues through delivery workers');
  check(await page.getByRole('link',{name:'View delivery results',exact:true}).getAttribute('href')==='alarmmanager.php?tab=events&job=delivery','Test links to existing delivery history');
  await page.getByRole('link',{name:'View delivery results',exact:true}).click();
  await page.waitForURL(u=>u.searchParams.get('tab')==='events');
  check(await page.getByRole('cell',{name:'1 Pending',exact:true}).count()>0,'Queued test is visible in delivery history');
  await page.setViewportSize({width:390,height:844});
  await page.goto(admin+'?section=endpoints&integration_template=webhook');
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Integration setup fits mobile viewport');
  check(errors.length===0,'No browser script errors: '+errors.join('; '));check(external.length===0,'No provider or external asset requests');
  console.log(`PASS: ${checks} integration workflow browser checks`);
 }finally{await context.close();await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
