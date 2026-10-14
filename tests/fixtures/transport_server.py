#!/usr/bin/env python3
# SPDX-License-Identifier: GPL-2.0-or-later
"""Loopback-only fixture. Never log request bodies or authentication headers."""
import collections
import http.server
import json
import ssl
import sys
import threading
import time

counts = collections.Counter()
keys = collections.defaultdict(list)
lock = threading.Lock()

class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def do_POST(self):
        with lock:
            counts[self.path] += 1
            count = counts[self.path]
            keys[self.path].append(self.headers.get('Idempotency-Key'))
        data = self.rfile.read(int(self.headers.get('Content-Length', '0')))
        status, body, headers = 201, {'accepted': True}, {}
        if self.path == '/retry' and count == 1:
            status, headers = 503, {'Retry-After': '2'}
        elif self.path == '/bad':
            status, body = 400, {'error': 'fake-secret-do-not-log'}
        elif self.path == '/redirect':
            status, headers = 302, {'Location': '/redirect-target'}
        elif self.path == '/large':
            body = 'x' * 70000
        elif self.path == '/slow':
            time.sleep(2)
        elif self.path == '/mismatch':
            body = {'accepted': False}
        elif self.path == '/invalid':
            body = b'not-json'
        elif self.path in ['/ticket', '/ticket2']:
            request = json.loads(data)
            body = {'ticket': {'id': request.get('external_id') or ('CASE-1' if self.path == '/ticket' else 'CASE-2'),
                               'url': 'https://tickets.example.invalid/case'}, 'request': request}
        elif self.path == '/echo':
            body = {'accepted': True, 'request': json.loads(data), 'method': self.command,
                    'authenticated': self.headers.get('Authorization') == 'Bearer fixture-token'}
        elif self.path == '/stats':
            with lock:
                body = {'counts': dict(counts), 'keys': dict(keys)}
        if not isinstance(body, bytes):
            body = json.dumps(body).encode()
        try:
            self.send_response(status)
            self.send_header('Content-Length', str(len(body)))
            for name, value in headers.items():
                self.send_header(name, value)
            self.end_headers()
            self.wfile.write(body)
        except (BrokenPipeError, ConnectionResetError, ssl.SSLError):
            pass

    do_PUT = do_POST
    do_PATCH = do_POST

plain = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler)
secure = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler)
context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain(sys.argv[1], sys.argv[2])
secure.socket = context.wrap_socket(secure.socket, server_side=True)
threading.Thread(target=plain.serve_forever, daemon=True).start()
threading.Thread(target=secure.serve_forever, daemon=True).start()
print(json.dumps({'http': plain.server_port, 'https': secure.server_port}), flush=True)
threading.Event().wait()
