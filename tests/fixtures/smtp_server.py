#!/usr/bin/env python3
# SPDX-License-Identifier: GPL-2.0-or-later
"""Disposable loopback SMTP fixture; captures synthetic test messages only."""
import email.parser
import email.policy
import json
import socketserver
import sys
import threading

class Handler(socketserver.StreamRequestHandler):
    def handle(self):
        self.wfile.write(b'220 localhost fixture\r\n')
        recipients = []
        while True:
            command = self.rfile.readline()
            if not command:
                return
            verb = command.split(b' ', 1)[0].strip().upper()
            if verb in (b'EHLO', b'HELO'):
                self.wfile.write(b'250-localhost\r\n250 SIZE 2000000\r\n')
            elif verb == b'RCPT':
                recipients.append(command.decode().strip())
                self.wfile.write(b'250 OK\r\n')
            elif verb == b'DATA':
                self.wfile.write(b'354 send data\r\n')
                lines = []
                while True:
                    line = self.rfile.readline()
                    if line in (b'.\r\n', b''):
                        break
                    lines.append(line[1:] if line.startswith(b'..') else line)
                message = email.parser.BytesParser(policy=email.policy.default).parsebytes(b''.join(lines))
                parts = [{'type': part.get_content_type(), 'filename': part.get_filename(),
                          'data': part.get_payload(decode=True).decode(errors='replace')}
                         for part in message.walk() if not part.is_multipart()]
                with open(sys.argv[1], 'w') as output:
                    json.dump({'recipients': recipients, 'subject': str(message['Subject']),
                               'idempotency': str(message['X-AlarmManager-Idempotency-Key']), 'parts': parts}, output)
                self.wfile.write(b'250 accepted\r\n')
            elif verb == b'QUIT':
                self.wfile.write(b'221 bye\r\n')
                return
            else:
                self.wfile.write(b'250 OK\r\n')

server = socketserver.ThreadingTCPServer(('127.0.0.1', 0), Handler)
threading.Thread(target=server.serve_forever, daemon=True).start()
print(server.server_address[1], flush=True)
threading.Event().wait()
