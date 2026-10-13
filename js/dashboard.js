/* SPDX-License-Identifier: GPL-2.0-or-later
 * Dashboard grid/card/render conventions adapted from Cacti plugin_syslog
 * js/dashboard.js at be1a8151c21ff81684d0e8ca0f9f0a78a8d48f27.
 * Copyright (C) 2004-2026 The Cacti Group. Adaptation copyright 2026 Sean Mancini.
 * Uses Cacti's locally bundled d3/billboard; no syslog runtime dependency.
 */
(() => {
 'use strict';
 const sourceFields = ['event.id','event.source','event.source_instance','event.collector_id','event.occurred_at','alarm.resource_id','alarm.key','alarm.episode_id','alarm.sequence','alarm.transition','alarm.severity','alarm.summary','alarm.details','device.id','device.hostname','device.description','device.tags','context.rule_name','context.rule_type','context.metric','context.metric_value','context.threshold','context.suppression_reason','context.source_suppressed','context.source_notification_channels','context.ownership_epoch'];
 function node(tag, text, className) { const el=document.createElement(tag); if(text!==undefined) el.textContent=text; if(className) el.className=className; return el; }
 const palette=['#2278ad','#e67e22','#329c60','#d43f3a','#8e63aa','#19a2a5','#ad795b','#7b8994','#c4a132','#5d88b3'];
 const colorMaps={transitions:{raised:'#d43f3a',recovered:'#329c60',occurrence:'#2278ad',updated:'#8e63aa',reminder:'#e67e22'},severity:{critical:'#d43f3a',major:'#e67e22',minor:'#b89822',warning:'#c39b13',info:'#2278ad',clear:'#329c60'},deliveries:{completed:'#329c60',failed:'#d43f3a',pending:'#7b8994',leased:'#2278ad'},attempts:{success:'#329c60',failure:'#d43f3a',retry:'#e67e22',abandoned:'#7b8994',in_progress:'#2278ad'}};
 function color(panel,label,index){const value=colorMaps[panel]?.[label];return typeof value==='string'?value:palette[index%palette.length];}
 function initMappings(app) {
  app.querySelectorAll('.alarmmanagerMapping').forEach(fieldset => {
   const textarea=fieldset.querySelector('[name=mapping_json]'), holder=fieldset.querySelector('.alarmmanagerMappingBuilder');
   const transition=node('select'); ['default','raised','updated','reminder','recovered','occurrence'].forEach(key=>{const option=node('option',key); option.value=key; transition.append(option);});
   const bar=node('div'), add=node('button','Add field'), inherit=node('button','Use default payload'), status=node('p'); add.type=inherit.type='button';
   bar.append(node('label','Payload for '),transition,add,inherit); const rows=node('div'); holder.append(bar,status,rows);
   let mapping={};
   const parseValue=text=>{try{return JSON.parse(text);}catch{return text;}};
   const save=()=>{textarea.value=JSON.stringify(mapping,null,2);};
   function render() {
    rows.replaceChildren(); const selected=transition.value; inherit.disabled=selected==='default'; status.textContent=selected!=='default' && !(selected in mapping)?'Using default; adding a field creates an override.':'';
    (mapping[selected] || []).forEach((item,index)=>{
     const row=node('div',undefined,'alarmmanagerMappingRow');
     function control(label,element) { const wrap=node('label',label);wrap.append(element);row.append(wrap);return element; }
     const path=control('Destination path',node('input'));path.value=item.to||'';path.maxLength=191;
     const source=control('Source or constant',node('select'));['constant',...sourceFields].forEach(key=>{const o=node('option',key);o.value=key;source.append(o);});source.value=item.source||'constant';
     const constant=control('Constant',node('input')); constant.value='constant' in item ? (typeof item.constant==='string'?item.constant:JSON.stringify(item.constant)):'';
     const type=control('Convert',node('select'));['identity','string','integer','number','boolean','html'].forEach(key=>{const o=node('option',key);type.append(o);});type.value=item.type||'identity';
     const fallback=control('Missing default',node('input'));fallback.value='default' in item ? JSON.stringify(item.default):'';
     const values=control('Value map JSON',node('input'));values.value=item.values?JSON.stringify(item.values):'';
     const required=control('Required',node('input'));required.type='checkbox';required.checked=!!item.required;
     const remove=node('button','Remove field');remove.type='button';row.append(remove);
     function update() {
      const fresh={to:path.value,type:type.value,required:required.checked};
      if(source.value==='constant')fresh.constant=parseValue(constant.value);else fresh.source=source.value;
      if(fallback.value!=='')fresh.default=parseValue(fallback.value);
      if(values.value!=='') {try{fresh.values=JSON.parse(values.value); values.setCustomValidity('');}catch{values.setCustomValidity('Use valid JSON for the value map.');return;}}
      mapping[selected][index]=fresh;save();
     }
     [path,source,constant,type,fallback,values,required].forEach(el=>el.addEventListener('input',update));
     remove.addEventListener('click',()=>{mapping[selected].splice(index,1);save();render();});rows.append(row);
    });
   }
   function read() {try {const value=JSON.parse(textarea.value);if(!value||typeof value!=='object'||Array.isArray(value)||Object.values(value).some(v=>!Array.isArray(v)))throw Error();mapping=value;textarea.setCustomValidity('');render();}catch{status.textContent='Invalid mapping JSON. Use an object of transition lists.';textarea.setCustomValidity('Invalid mapping JSON.');}}
   add.addEventListener('click',()=>{const key=transition.value;mapping[key]??=[];if(mapping[key].length>=64){status.textContent='Maximum 64 fields per payload.';return;}mapping[key].push({to:'field_'+(mapping[key].length+1),source:'device.id',type:'identity',required:false});save();render();});
   inherit.addEventListener('click',()=>{if(transition.value!=='default'){delete mapping[transition.value];save();render();}});
   transition.addEventListener('change',render); textarea.addEventListener('input',read);read();
  });
 }
 function init() {
  const app=document.getElementById('alarmmanager_app'); if(!app||app.dataset.initialized)return;app.dataset.initialized='yes';initMappings(app);
  const dashboard=app.querySelector('#alarmmanager_dashboard');if(!dashboard)return;
  const form=app.querySelector('#alarmmanager_filters'), status=app.querySelector('#alarmmanager_dashboard_status'), refresh=app.querySelector('#alarmmanager_refresh');
  const charts={}; let inFlight=false, timer=null;
  function url(tab,extra={},filters={}) {const values=new URLSearchParams();for(const [key,value] of Object.entries(filters)){if(['range','until','mode','source','severity','transition','status','reason','device_id','endpoint_id','event_id','acknowledged'].includes(key))values.set(key,String(value));} values.set('tab',tab);for(const [key,value]of Object.entries(extra))values.set(key,String(value));return 'alarmmanager.php?'+values.toString();}
  function link(text,tab,extra,filters) {const a=node('a',text);a.href=url(tab,extra,filters);return a;}
  function legend(panel,rows,tab,field,filters) {const holder=app.querySelector('#alarmmanager_legend_'+panel);holder.replaceChildren();const list=node('ul');rows.forEach((row,index)=>{const li=node('li'),swatch=node('span',undefined,'alarmmanagerSwatch');swatch.style.backgroundColor=color(panel,row.label,index);swatch.setAttribute('aria-hidden','true');li.append(swatch,link(`${row.label}: ${Number(row.count).toLocaleString()}`,tab,{[field]:row.label},filters));list.append(li);});holder.append(list);if(!rows.length)holder.append(node('p','No matching records.'));}
  function chart(panel,rows) {
   if(charts[panel]){charts[panel].destroy();delete charts[panel];}
   app.querySelector('#alarmmanager_chart_'+panel).hidden=typeof window.bb==='undefined'||!rows.length;
   if(typeof window.bb==='undefined'||!rows.length)return;
   // Developer-owned series IDs; user labels appear only in textContent links.
   charts[panel]=window.bb.generate({render:{lazy:false},transition:{duration:0},bindto:'#alarmmanager_chart_'+panel,data:{columns:rows.map((r,i)=>['slice_'+i,Number(r.count)]),colors:Object.fromEntries(rows.map((r,i)=>['slice_'+i,color(panel,r.label,i)])),type:'donut'},donut:{label:{show:false}},legend:{show:false},tooltip:{show:false},size:{height:230},padding:{right:12}});
  }
  function summaries(result) {
   const f=result.filters,current=app.querySelector('#alarmmanager_current'),history=app.querySelector('#alarmmanager_historical');current.replaceChildren(node('strong','Current conditions: '));history.replaceChildren(node('strong','Historical events: '));
   current.append(link(`Active ${result.current.conditions.active}`,'conditions',{},f),link(`Acknowledged ${result.current.conditions.acknowledged}`,'conditions',{acknowledged:1},f));
   const events=result.historical.events;[['All',events.events,''],['Raised',events.raised,'raised'],['Recovered',events.recovered,'recovered'],['Occurrences',events.occurrences,'occurrence']].forEach(([title,count,transition])=>history.append(link(`${title} ${Number(count).toLocaleString()}`,'events',{transition},f)));
  }
  function render(result) {
   const f=result.filters;summaries(result);
   [['severity','events','severity'],['source','events','source'],['deliveries','deliveries','status'],['attempts','attempts','status'],['suppression','decisions','reason']].forEach(([panel,tab,field])=>{legend(panel,result.panels[panel],tab,field,f);chart(panel,result.panels[panel]);});
   const rows=result.panels.transitions, kinds=['raised','recovered','occurrence','updated','reminder'];
   legend('transitions',kinds.map(kind=>({label:kind,count:rows.filter(r=>r.label===kind).reduce((n,r)=>n+Number(r.count),0)})),'events','transition',f);
   if(charts.transitions){charts.transitions.destroy();delete charts.transitions;}
   app.querySelector('#alarmmanager_chart_transitions').hidden=typeof window.bb==='undefined'||!rows.length;
   if(typeof window.bb!=='undefined'&&rows.length){const buckets=[];for(let epoch=Math.floor((f.until-f.range)/result.bucket_seconds)*result.bucket_seconds;epoch<f.until;epoch+=result.bucket_seconds)buckets.push(epoch);const columns=[['x',...buckets.map(epoch=>(result.bucket_seconds>=86400?new Date(epoch*1000).toISOString().slice(5,10):new Date(epoch*1000).toISOString().slice(8,16).replace('T',' ')))],...kinds.map(kind=>[kind,...buckets.map(epoch=>rows.filter(r=>Number(r.bucket)===epoch&&r.label===kind).reduce((n,r)=>n+Number(r.count),0))])];charts.transitions=window.bb.generate({render:{lazy:false},transition:{duration:0},bindto:'#alarmmanager_chart_transitions',data:{x:'x',columns,type:'bar',groups:[kinds],colors:{raised:'#d43f3a',recovered:'#329c60',occurrence:'#2278ad',updated:'#8e63aa',reminder:'#e67e22'}},axis:{x:{type:'category',tick:{culling:{max:6},multiline:false}},y:{min:0,padding:{bottom:0}}},tooltip:{show:false},size:{height:230}});}
   const endpoints=app.querySelector('#alarmmanager_legend_endpoints');endpoints.replaceChildren();result.panels.endpoints.forEach(row=>endpoints.append(link(`${row.label}: ${row.successes}/${row.attempts} successful attempts; average ${row.average_ms} ms`,'attempts',{endpoint_id:row.endpoint_id},f),node('br')));if(!result.panels.endpoints.length)endpoints.append(node('p','No attempts in this window.'));
   const queues=app.querySelector('#alarmmanager_legend_queues');queues.replaceChildren();Object.entries(result.current.queues).forEach(([queue,items])=>{queues.append(node('h4',queue));items.forEach(row=>queues.append(link(`${row.label}: ${row.count}; oldest ${row.oldest_age_seconds}s`,queue==='processing'?'processing':'deliveries',{status:row.label,current:1},f),node('br')));});
   app.querySelector('#alarmmanager_panel_workers').hidden=!result.can_admin;
   const workers=app.querySelector('#alarmmanager_legend_workers');workers.replaceChildren();result.workers.forEach(row=>workers.append(node('p',`${row.role} · poller ${row.poller_id} · PID ${row.pid} · ${row.status} · heartbeat ${row.age_seconds}s ago${row.age_seconds>30?' (stale)':''}`)));if(!result.workers.length)workers.append(node('p','No recent workers.'));
   status.textContent=`Updated ${new Date().toLocaleTimeString()} · historical UTC window ${f.from} to ${f.to} · ${f.mode} mode`+(typeof window.bb==='undefined'?' · chart library unavailable; linked counts remain available.':'');
  }
  async function load() {if(inFlight||!app.isConnected)return;inFlight=true;status.textContent='Loading statistics…';const params=new URLSearchParams(new FormData(form));params.set('action','dashboard_data');try{const response=await fetch('alarmmanager.php?'+params,{credentials:'same-origin',cache:'no-store'});const result=await response.json();if(!response.ok||result.error)throw Error();if(app.isConnected)render(result);}catch{status.textContent='Statistics unavailable. Check permissions and refresh.';}finally{inFlight=false;}}
  form.addEventListener('submit',event=>{event.preventDefault();load();});refresh.addEventListener('change',()=>{if(timer)clearInterval(timer);const seconds=Number(refresh.value);if(seconds)timer=setInterval(()=>{if(!app.isConnected){clearInterval(timer);return;}load();},seconds*1000);});
  app.querySelectorAll('.alarmmanagerCardToggle').forEach(button=>button.addEventListener('click',()=>{const body=button.closest('article').querySelector('.alarmmanagerCardBody');body.hidden=!body.hidden;button.setAttribute('aria-expanded',String(!body.hidden));button.textContent=body.hidden?'Expand':'Collapse';if(!body.hidden)window.dispatchEvent(new Event('resize'));}));load();
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();

