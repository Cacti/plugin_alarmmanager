// SPDX-License-Identifier: GPL-2.0-or-later
const {chromium}=require(process.env.ALARMMANAGER_PLAYWRIGHT_MODULE||'playwright');
const {execFileSync}=require('node:child_process');
const path=require('node:path'), assert=require('node:assert/strict');
const root=path.resolve(__dirname,'../..'), cacti=process.env.CACTI_PATH;
(async()=>{
 const html=execFileSync('/usr/bin/php',['-r',`function __($s,...$a){return $s;} function __esc($s,...$a){return htmlspecialchars($s);} function html_escape($s){return htmlspecialchars((string)$s);} function csrf_get_tokens(){return 'fixture';} require $argv[1].'/includes/admin_view.php'; $endpoint=['id'=>1,'name'=>'Twilio SMS','enabled'=>true]+alarmmanager_integration_templates()['twilio'];$endpoint['transport']['provider']='twilio'; alarmmanager_rule_editor(['id'=>1,'name'=>'Recipient rule','enabled'=>true,'priority'=>1,'endpoints'=>[1],'policy'=>['recipient_overrides'=>[1=>['+15551234567']]]],1,['endpoints'=>[$endpoint]],['sources'=>['sample'=>'Sample']]);`,root],{encoding:'utf8'});
 assert.match(html,/Twilio recipients for this rule/);
 const browser=await chromium.launch({headless:true,executablePath:process.env.ALARMMANAGER_BROWSER_BINARY});
 const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
 try{
  await page.setContent('<div id="alarmmanager_app">'+html+'</div>');await page.addStyleTag({path:path.join(root,'css/dashboard.css')});await page.addScriptTag({path:path.join(cacti,'include/js/jquery.js')});await page.addScriptTag({path:path.join(root,'js/dashboard.js')});
  await page.locator('.alarmmanagerRule > summary').click();await page.locator('[data-rule-template="1"] > summary').click();
  const numbers=page.getByLabel('Twilio recipients for this rule');
  assert.equal(await numbers.inputValue(),'+15551234567');assert.equal(await numbers.isEnabled(),true);
  assert.equal(await page.getByLabel('Customize message for this rule',{exact:true}).isChecked(),false);
  await numbers.fill('+15551234567\n+15557654321');
  assert.equal(await numbers.evaluate(el=>new FormData(el.form).get('recipient_numbers[1]')),'+15551234567\n+15557654321');
  await page.locator('[data-alarm-destination][value="1"]').uncheck();assert.equal(await numbers.isEnabled(),false);
  await page.locator('[data-alarm-destination][value="1"]').check();assert.equal(await numbers.isEnabled(),true);
  assert.equal(errors.length,0);console.log('PASS: rule recipient edit, serialization and selection checks');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
