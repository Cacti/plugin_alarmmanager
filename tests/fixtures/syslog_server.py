#!/usr/bin/env python3
# SPDX-License-Identifier: GPL-2.0-or-later
"""Loopback-only TLS syslog fixture: prints its port, then one base64 line per received connection."""
import base64
import socket
import ssl
import sys

context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain(sys.argv[1], sys.argv[2])
listener = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
listener.bind(('127.0.0.1', 0))
listener.listen(4)
listener.settimeout(10)
print(listener.getsockname()[1], flush=True)

while True:
    try:
        client, _ = listener.accept()
    except socket.timeout:
        break
    data = b''
    try:
        with context.wrap_socket(client, server_side=True) as tls:
            tls.settimeout(5)
            while chunk := tls.recv(65536):
                data += chunk
    except (ssl.SSLError, OSError):
        # A client that rejects the certificate closes during the handshake.
        pass
    print(base64.b64encode(data).decode(), flush=True)
