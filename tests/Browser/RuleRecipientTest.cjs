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
  const section=page.locator('[data-rule-template="1"]'),list=section.getByRole('group',{name:'Twilio recipients for this rule'});
  const numbers=section.locator('textarea[name="recipient_numbers[1]"]'),entry=list.getByLabel('Phone number to add'),add=list.getByRole('button',{name:'Add number'});
  const value=()=>numbers.evaluate(el=>new FormData(el.form).get('recipient_numbers[1]'));
  assert.equal(await numbers.isHidden(),true);assert.equal(await value(),'+15551234567');
  assert.equal(await section.locator('[data-recipient-count]').textContent(),'Recipients: 1');
  assert.equal(await section.locator('[data-template-state]').textContent(),'Default template');
  assert.equal(await page.getByLabel('Customize message for this rule',{exact:true}).isChecked(),false);
  await entry.fill('+1 (555) 765-4321');await add.click();
  await entry.fill('+15557654321');await entry.press('Enter');
  assert.match(await list.locator('.alarmmanagerPhoneError').textContent(),/already in the list/);
  await entry.fill('5551112222');await add.click();
  assert.match(await list.locator('.alarmmanagerPhoneError').textContent(),/international format/);
  assert.equal(await entry.getAttribute('aria-invalid'),'true');
  await entry.fill('');
  assert.equal(await value(),'+15551234567\n+15557654321');
  assert.equal(await section.locator('[data-recipient-count]').textContent(),'Recipients: 2');
  await list.getByRole('button',{name:'Remove +15551234567'}).click();
  assert.equal(await value(),'+15557654321');
  await list.getByRole('button',{name:'Remove +15557654321'}).click();
  assert.equal(await value(),'');assert.equal(await section.locator('[data-recipient-count]').textContent(),'Destination recipient');
  await page.getByLabel('Customize message for this rule',{exact:true}).check();
  assert.equal(await section.locator('[data-template-state]').textContent(),'Custom template');
  await page.locator('[data-alarm-destination][value="1"]').uncheck();assert.equal(await numbers.isEnabled(),false);
  await page.locator('[data-alarm-destination][value="1"]').check();assert.equal(await numbers.isEnabled(),true);assert.equal(await entry.isEnabled(),true);
  assert.equal(errors.length,0);console.log('PASS: rule recipient add/remove, validation, serialization, badges and selection checks');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
