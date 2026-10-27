// SPDX-License-Identifier: GPL-2.0-or-later
const {chromium}=require(process.env.ALARMMANAGER_PLAYWRIGHT_MODULE||'playwright');
const {execFileSync}=require('node:child_process');
const path=require('node:path'), assert=require('node:assert/strict');
const root=path.resolve(__dirname,'../..'), cacti=process.env.CACTI_PATH;
function render(endpoint){return execFileSync('php',['-r',`function __($s,...$a){return $s;} function __esc($s,...$a){return htmlspecialchars($s);} function html_escape($s){return htmlspecialchars((string)$s);} function csrf_get_tokens(){return 'fixture';} require $argv[1].'/includes/admin_view.php'; $endpoint=json_decode($argv[2],true); if(isset($endpoint['integration_provider'])){$endpoint+=alarmmanager_integration_templates()[$endpoint['integration_provider']];} alarmmanager_endpoint_editor($endpoint,1,!isset($endpoint['id']));`,root,JSON.stringify(endpoint)],{encoding:'utf8'});}
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.ALARMMANAGER_BROWSER_BINARY});
 const page=await browser.newPage(); const errors=[];page.on('pageerror',e=>errors.push(e.message));let accept=true,checks=0;
 page.on('dialog',d=>accept?d.accept():d.dismiss());
 const check=(ok,msg)=>{assert.ok(ok,msg);checks++;};
 async function load(endpoint){await page.setContent('<div id="alarmmanager_app">'+render(endpoint)+'</div>');await page.addStyleTag({content:'body{font:14px sans-serif;margin:20px}[hidden]{display:none!important}'});await page.addStyleTag({path:path.join(root,'css/dashboard.css')});await page.addScriptTag({path:path.join(cacti,'include/js/jquery.js')});await page.addScriptTag({path:path.join(root,'js/dashboard.js')});}
 try{
  await load({name:'Twilio',type:'restapi',enabled:false,integration_provider:'twilio'});
  const form=page.locator('.alarmmanagerEndpointEditor');
  check(await form.getByLabel('Account SID (AC…)',{exact:true}).isVisible(),'Twilio asks for Account SID');
  check(await form.getByLabel('API Key SID (SK…)',{exact:true}).isVisible(),'Twilio asks for API Key SID');
  check(await form.getByLabel('API Key Secret',{exact:true}).isVisible(),'Twilio asks for secret');
  check(!await form.getByLabel('URL',{exact:true}).isVisible(),'Twilio URL generated automatically');
  check(await form.getByLabel('Sender phone number',{exact:true}).isVisible(),'Twilio sender field');
  check(await form.getByLabel('Recipient phone number',{exact:true}).isVisible(),'Twilio recipient field');
  await form.getByLabel('Account SID (AC…)',{exact:true}).fill('AC'+'a'.repeat(32));
  await form.locator('[name=twilio_auth_mode]').selectOption('account');
  check(await form.getByLabel('Auth Token',{exact:true}).isVisible(),'Auth Token mode explicit');
  check(await form.locator('[name=basic_username]').inputValue()==='AC'+'a'.repeat(32),'Auth Token mode reuses Account SID');
  await form.locator('[name=provider]').selectOption('slack');
  check(await form.getByLabel('Slack bot token',{exact:true}).isVisible(),'Slack token field');
  check(!await form.getByLabel('Account SID (AC…)',{exact:true}).isVisible(),'Twilio fields hidden on Slack');
  check(await form.getByLabel('Slack channel ID',{exact:true}).isVisible(),'Slack channel field');
  check(await form.locator('.alarmmanagerMappingRow').count()===2,'Slack standard payload has channel and text');
  await form.getByLabel('Slack channel ID',{exact:true}).fill('C12345');
  const saved=JSON.parse(await form.locator('[name=mapping_json]').inputValue());
  check(saved.default.find(i=>i.to==='channel').constant==='C12345','Channel enters standard Slack payload');
  accept=false;await form.locator('[name=provider]').selectOption('twilio');
  check(await form.locator('[name=provider]').inputValue()==='slack','Cancelled switch preserves provider');
  check(await form.getByLabel('Slack channel ID',{exact:true}).inputValue()==='C12345','Cancelled switch preserves message');
  accept=true;await form.locator('[name=provider]').selectOption('twilio_whatsapp');
  check(await form.getByLabel('Approved Template SID (HX…)',{exact:true}).isVisible(),'WhatsApp template field');
  check(await form.getByLabel('Messaging Service SID (MG…)',{exact:true}).isVisible(),'WhatsApp service field');
  check(!await form.locator('[name=bearer_token]').isEnabled(),'Twilio bearer input disabled');
  for(const provider of ['telegram','pagerduty','servicenow','servicenow_events','teams']){
   await form.locator('[name=provider]').selectOption(provider);
   check(await form.locator('[name=provider]').inputValue()===provider,'Switch to '+provider);
   if(provider==='telegram')check(await form.getByLabel('Telegram bot token',{exact:true}).isVisible(),'Telegram token field');
   if(provider==='pagerduty'){
    check(await form.getByLabel('PagerDuty integration key',{exact:true}).isVisible(),'PagerDuty key field');
    check(!JSON.stringify(JSON.parse(await form.locator('[name=mapping_json]').inputValue())).includes('routing_key'),'PagerDuty key excluded from editable mapping');
   }
  }
  await load({id:7,name:'Saved Slack',type:'webhook',enabled:false,transport:{provider:'slack',url:'https://slack.com/api/chat.postMessage',credential:true,mapping:saved}});
  await page.locator('.alarmmanagerDashboardCard > summary').first().click();
  check(await form.locator('[name=provider]').inputValue()==='slack','Saved provider reopens');
  check(await form.getByLabel('Slack channel ID',{exact:true}).inputValue()==='C12345','Saved message reopens');
  check(await form.locator('[name=auth_type]').inputValue()==='keep','Saved secrets stay hidden');
  await page.setViewportSize({width:390,height:844});
  check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Provider editor fits mobile');
  check(errors.length===0,'No browser errors');
  console.log('PASS: '+checks+' provider editor checks');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
