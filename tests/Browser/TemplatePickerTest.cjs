// SPDX-License-Identifier: GPL-2.0-or-later
const {chromium}=require(process.env.ALARMMANAGER_PLAYWRIGHT_MODULE||'playwright');
const path=require('node:path'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'../..'),cacti=process.env.CACTI_PATH;
if(!cacti)throw Error('CACTI_PATH required for the local jQuery asset');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.ALARMMANAGER_BROWSER_BINARY});
 const page=await browser.newPage();const check=(ok,label)=>assert(ok,label);
 try{
  await page.setContent('<div id="alarmmanager_app"><form class="alarmmanagerTemplatePicker"><select name="integration_template"><option value="">Blank</option><option value="script">Script</option></select><button type="submit">Prepare</button></form></div>');
  await page.addScriptTag({path:path.join(cacti,'include/js/jquery.js')});
  await page.evaluate(()=>{window.templateSubmissions=0;document.querySelector('form').submit=()=>window.templateSubmissions++;});
  await page.addScriptTag({path:path.join(root,'js/dashboard.js')});
  await page.evaluate(()=>jQuery('[name=integration_template]').val('script').trigger('change'));
  check(await page.evaluate(()=>window.templateSubmissions)===1,'Select2 change prepares the selected template exactly once');
  await page.locator('[name=integration_template]').selectOption('');
  check(await page.evaluate(()=>window.templateSubmissions)===2,'Native change prepares the blank template exactly once');
  console.log('PASS: Select2 and native template selection submit exactly once');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
