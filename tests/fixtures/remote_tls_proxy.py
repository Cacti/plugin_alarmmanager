#!/usr/bin/env python3
"""Loopback TLS fixture: real service proxy, controlled outages/acks, notification counters."""
import argparse, http.server, http.client, ssl, json, pathlib, threading
p=argparse.ArgumentParser();p.add_argument('--port',type=int,required=True);p.add_argument('--backend',type=int,required=True);p.add_argument('--cert',required=True);p.add_argument('--key',required=True);p.add_argument('--state',required=True);a=p.parse_args()
state={'alarms':{},'sync':0};lock=threading.Lock()
class Handler(http.server.BaseHTTPRequestHandler):
 def log_message(self,*args):pass
 def do_POST(self):
  data=self.rfile.read(min(int(self.headers.get('Content-Length','0')),1048577))
  if self.path.startswith('/alarm/'):
   with lock:
    state['alarms'][self.path]=state['alarms'].get(self.path,0)+1
    pathlib.Path(a.state).write_text(json.dumps(state))
   self.send_response(200);self.end_headers();self.wfile.write(b'{"accepted":true}');return
  if self.path=='/offline':self.send_response(503);self.end_headers();return
  if self.path=='/redirect':self.send_response(302);self.send_header('Location',f'https://127.0.0.1:{a.port}/plugins/alarmmanager/alarmmanager_sync.php');self.end_headers();return
  try:
   backend=http.client.HTTPConnection('127.0.0.1',a.backend,timeout=5)
   headers={k:v for k,v in self.headers.items() if k.lower() not in ('host','connection','content-length')}
   backend.request('POST','/plugins/alarmmanager/alarmmanager_sync.php',body=data,headers=headers);response=backend.getresponse();reply=response.read(8193)
   with lock:state['sync']+=1;pathlib.Path(a.state).write_text(json.dumps(state))
   if self.path=='/dropack':self.close_connection=True;return
   self.send_response(response.status)
   for key,value in response.getheaders():
    if key.lower() not in ('transfer-encoding','connection','content-length'):self.send_header(key,value)
   self.end_headers()
   if self.path=='/badack':reply+=b' '
   self.wfile.write(reply);backend.close()
  except (ConnectionError,OSError):self.send_response(503);self.end_headers()
server=http.server.ThreadingHTTPServer(('127.0.0.1',a.port),Handler)
ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(a.cert,a.key);server.socket=ctx.wrap_socket(server.socket,server_side=True)
server.serve_forever()
