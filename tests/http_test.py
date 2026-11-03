#!/usr/bin/env python3
"""Exercise the local HTTP workflow against a fresh synthetic SQLite database."""
from __future__ import annotations
import copy
import datetime as dt
import http.cookiejar
import os
from pathlib import Path
import re
import secrets
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
php = os.environ.get('DOCWRIGHT_PHP') or 'php'
base = os.environ.get('DOCWRIGHT_TEST_BASE') or None
if base:
    Path(base).mkdir(parents=True, exist_ok=True)
work = Path(tempfile.mkdtemp(prefix='docwright-check-', dir=base))
password = secrets.token_urlsafe(24)
env = os.environ.copy()
env['DOCWRIGHT_DB_PATH'] = str(work / 'test.sqlite')
env['DOCWRIGHT_DEMO_PASSWORD'] = password
creationflags = getattr(subprocess, 'CREATE_NO_WINDOW', 0)
seed = subprocess.run([php, 'seed.php'], cwd=ROOT, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, creationflags=creationflags)
if seed.returncode:
    raise SystemExit('Synthetic database initialization failed.')
with socket.socket() as reservation:
    reservation.bind(('127.0.0.1', 0))
    port = reservation.getsockname()[1]
server = subprocess.Popen([php, '-S', f'127.0.0.1:{port}', '-t', 'public/'], cwd=ROOT, env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, creationflags=creationflags)
base_url = f'http://127.0.0.1:{port}'
cookies = http.cookiejar.CookieJar()
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies))

def request(path, fields=None, extra_headers=None):
    body = urllib.parse.urlencode(fields).encode() if fields is not None else None
    req = urllib.request.Request(base_url + path, data=body, headers=extra_headers or {})
    try:
        response = client.open(req, timeout=4)
    except urllib.error.HTTPError as error:
        response = error
    return response.code, response.geturl(), response.headers, response.read().decode('utf-8', 'replace')

def verify(condition, description):
    if not condition:
        raise AssertionError(description)
    print('PASS:', description)

def csrf(page):
    match = re.search(r'name="csrf_token"\s+value="([^"]+)"', page)
    if not match:
        raise AssertionError('Expected an anti-forgery form field')
    return match.group(1)

def create_share(doc_id):
    status, _, _, page = request(f'/share.php?doc={doc_id}')
    verify(status == 200, 'Share form is available to signed-in staff')
    status, _, _, page = request(f'/share.php?doc={doc_id}', {'csrf_token': csrf(page), 'email': 'reader@example.test'}, {'Host': 'untrusted.example.test'})
    verify('href="/view.php?token=' in page and 'untrusted.example.test' not in page, 'Share link uses a safe relative URL')
    match = re.search(r'view\.php\?token=([0-9a-f]+)', page)
    verify(status == 200 and match is not None, 'Share link is created')
    readable = re.search(r'view\.php\?rid=([A-Za-z0-9_-]+)', page)
    verify(readable is not None, 'Readable share link is created')
    return match.group(1), readable.group(1)

try:
    for _ in range(60):
        try:
            status, _, _, _ = request('/login.php')
            if status == 200:
                break
        except (OSError, urllib.error.URLError):
            time.sleep(0.1)
    else:
        raise AssertionError('Local server did not become ready')
    status, final_url, headers, _ = request('/admin.php')
    verify(status == 200 and urllib.parse.urlsplit(final_url).path == '/login.php', 'Anonymous access redirects to login')
    verify(headers.get('X-Content-Type-Options') == 'nosniff' and headers.get('X-Frame-Options') == 'DENY', 'Security headers are present')
    status, _, _, page = request('/login.php', {'email': 'editor@example.test', 'password': secrets.token_urlsafe(24)})
    verify(status == 200 and 'Invalid' in page, 'Incorrect credentials are rejected')
    status, final_url, _, page = request('/login.php', {'email': 'editor@example.test', 'password': password})
    verify(status == 200 and urllib.parse.urlsplit(final_url).path == '/admin.php', 'Valid local sign-in succeeds')
    saved_cookies = [copy.copy(cookie) for cookie in cookies]
    status, _, _, _ = request('/admin.php', {'title': 'Rejected item', 'body': 'Synthetic content'})
    verify(status == 419, 'Missing anti-forgery value is rejected')
    status, _, _, page = request('/admin.php')
    status, created_url, _, page = request('/admin.php', {'csrf_token': csrf(page), 'title': 'Alpha guide', 'body': 'Synthetic guide content'})
    doc_id = urllib.parse.parse_qs(urllib.parse.urlsplit(created_url).query).get('created', [''])[0]
    verify(status == 200 and doc_id.isdigit() and 'Alpha guide' in page, 'A document can be created')
    status, _, _, page = request('/admin.php?search=' + urllib.parse.quote('Alpha'))
    verify(status == 200 and 'Alpha guide' in page, 'Title prefix search returns the document')
    share_token, readable_id = create_share(doc_id)
    status, _, _, page = request('/view.php?token=' + share_token)
    verify(status == 200 and 'Synthetic guide content' in page, 'Shared document opens using its link')
    status, _, _, page = request('/view.php?rid=' + readable_id)
    verify(status == 200 and 'Synthetic guide content' in page, 'Readable share link opens the document')
    status, _, _, page = request('/admin.php')
    xss_title = 'Example<script>'
    status, _, _, page = request('/admin.php', {'csrf_token': csrf(page), 'title': xss_title, 'body': 'Synthetic content'})
    status, _, _, page = request('/admin.php?search=' + urllib.parse.quote(xss_title))
    verify(status == 200 and 'Example&lt;script&gt;' in page and 'Example<script>' not in page, 'Search output is HTML escaped')
    for title in ('Price 100% Ready', 'Price 100 Percent', 'Ticket_A', 'TicketXA'):
        status, _, _, page = request('/admin.php')
        status, _, _, _ = request('/admin.php', {'csrf_token': csrf(page), 'title': title, 'body': 'Synthetic content'})
        if status != 200:
            raise AssertionError('Could not create a search fixture')
    status, _, _, page = request('/admin.php?search=' + urllib.parse.quote('Price 100%'))
    verify(status == 200 and 'Price 100% Ready' in page and 'Price 100 Percent' not in page, 'Percent is treated literally in prefix search')
    status, _, _, page = request('/admin.php?search=' + urllib.parse.quote('Ticket_'))
    verify(status == 200 and 'Ticket_A' in page and 'TicketXA' not in page, 'Underscore is treated literally in prefix search')
    status, _, _, page = request('/admin.php')
    future = (dt.datetime.now() + dt.timedelta(days=2)).strftime('%Y-%m-%dT%H:%M')
    status, created_url, _, page = request('/admin.php', {'csrf_token': csrf(page), 'title': 'Future guide', 'body': 'Synthetic later content', 'scheduled_at': future})
    future_id = urllib.parse.parse_qs(urllib.parse.urlsplit(created_url).query).get('created', [''])[0]
    verify(status == 200 and future_id.isdigit(), 'A scheduled document can be created')
    future_token, _ = create_share(future_id)
    status, _, _, page = request('/view.php?token=' + future_token)
    verify(status == 200 and 'not yet available' in page and 'Synthetic later content' not in page, 'A scheduled document is withheld before its time')
    request('/logout.php')
    status, final_url, _, _ = request('/admin.php')
    verify(status == 200 and urllib.parse.urlsplit(final_url).path == '/login.php', 'Logging out removes staff access')
    replay_cookies = http.cookiejar.CookieJar()
    for cookie in saved_cookies:
        replay_cookies.set_cookie(cookie)
    client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(replay_cookies))
    status, final_url, _, _ = request('/admin.php')
    verify(status == 200 and urllib.parse.urlsplit(final_url).path == '/login.php', 'Logged-out session cannot be replayed')
    print('HTTP checks completed using synthetic records.')
finally:
    server.terminate()
    try:
        server.wait(timeout=5)
    except subprocess.TimeoutExpired:
        server.kill()
        server.wait(timeout=5)
