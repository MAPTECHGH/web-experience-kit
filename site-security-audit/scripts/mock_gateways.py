#!/usr/bin/env python3
"""Fake payment gateways for testing fixes locally — never test against real money.

Serves (HTTPS on 127.0.0.1:443):
  GET  /transaction/verify/<ref>   Paystack verify       -> state["paystack"][ref]
  POST /transaction/initialize     Paystack init          -> echoes the reference
  POST /query.php  (token=...)     ExpressPay query      -> state["ep"][token]
  POST /open/transact/status       Moolre status (id)    -> state["moolre"][id]
  POST /open/transact/payment      Moolre prompt          -> "prompt sent"

Edit the JSON state file (default /tmp/mock_gateways.json) from your test script, e.g.
  {"paystack": {"PAY_SMALL": {"status": "success", "amount": 500}}}   # amount in pesewas

One-time setup (so PHP's curl trusts it and the real hostnames point here):
  mkdir -p /tmp/mockca && cd /tmp/mockca
  openssl req -x509 -newkey rsa:2048 -nodes -keyout ca.key -out ca.crt -days 30 -subj "/CN=TestCA"
  printf '[req]\\ndistinguished_name=dn\\n[dn]\\n[ext]\\nsubjectAltName=DNS:api.paystack.co,DNS:expresspaygh.com,DNS:sandbox.expresspaygh.com,DNS:api.moolre.com\\n' > san.cnf
  openssl req -newkey rsa:2048 -nodes -keyout srv.key -out srv.csr -subj "/CN=mock" -config san.cnf
  openssl x509 -req -in srv.csr -CA ca.crt -CAkey ca.key -CAcreateserial -out srv.crt -days 30 -extfile san.cnf -extensions ext
  cp ca.crt /usr/local/share/ca-certificates/mockca.crt && update-ca-certificates
  echo "127.0.0.1 api.paystack.co expresspaygh.com sandbox.expresspaygh.com api.moolre.com" >> /etc/hosts
Start the PHP test server WITHOUT proxy variables (unset HTTPS_PROXY https_proxy ...),
otherwise curl goes to the proxy instead of this mock. Add more providers the same way.
Usage: sudo python3 mock_gateways.py [state.json] [certdir]
"""
import json, ssl, sys, urllib.parse
from http.server import ThreadingHTTPServer, BaseHTTPRequestHandler

STATE = sys.argv[1] if len(sys.argv) > 1 else '/tmp/mock_gateways.json'
CERTS = sys.argv[2] if len(sys.argv) > 2 else '/tmp/mockca'

def state():
    try: return json.load(open(STATE))
    except Exception: return {}

class H(BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def send(self, obj, code=200):
        b = json.dumps(obj).encode()
        self.send_response(code); self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(b))); self.end_headers(); self.wfile.write(b)
    def do_GET(self):
        p = urllib.parse.urlparse(self.path).path
        if p.startswith('/transaction/verify/'):
            ref = urllib.parse.unquote(p.rsplit('/', 1)[1]); d = state().get('paystack', {}).get(ref)
            if d is None: return self.send({'status': False, 'message': 'Transaction reference not found'}, 400)
            dd = {'reference': ref, 'currency': 'GHS', 'channel': 'mobile_money', 'paid_at': '2026-01-01T00:00:00Z'}; dd.update(d)
            return self.send({'status': True, 'data': dd})
        self.send({'status': False}, 404)
    def do_POST(self):
        raw = self.rfile.read(int(self.headers.get('Content-Length') or 0)).decode()
        p = urllib.parse.urlparse(self.path).path
        if p.endswith('/query.php'):
            tok = urllib.parse.parse_qs(raw).get('token', [''])[0]
            return self.send(state().get('ep', {}).get(tok) or {'result': 3, 'result-text': 'not found'})
        if p == '/open/transact/status':
            d = state().get('moolre', {}).get(json.loads(raw or '{}').get('id', ''))
            return self.send({'status': 1, 'data': d} if d else {'status': 0, 'message': 'not found'})
        if p == '/open/transact/payment':
            return self.send({'status': 1, 'code': 'TR099', 'message': 'prompt sent', 'data': {'transactionid': 'MOCK1'}})
        if p == '/transaction/initialize':
            j = json.loads(raw or '{}')
            return self.send({'status': True, 'data': {'authorization_url': 'https://checkout.paystack.com/mock', 'access_code': 'ac', 'reference': j.get('reference')}})
        self.send({'status': False}, 404)

srv = ThreadingHTTPServer(('127.0.0.1', 443), H)
ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); ctx.load_cert_chain(f'{CERTS}/srv.crt', f'{CERTS}/srv.key')
srv.socket = ctx.wrap_socket(srv.socket, server_side=True)
print('mock gateways on https://127.0.0.1:443, state file', STATE); srv.serve_forever()
