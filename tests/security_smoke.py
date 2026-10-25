#!/usr/bin/env python3
"""Bounded real HTTP security regression suite; local seeded demo instance only.
Reads private admin credentials without printing them. Creates one suspended test
account and two labelled ledger records; cleans disposable services/places.
"""
from pathlib import Path
import base64
import datetime as dt
import json
import os
import time
import requests

ROOT = Path(__file__).resolve().parents[1]
BASE = os.environ.get('OFFBEAT_URL', 'http://127.0.0.1:8088').rstrip('/')
CHECKS = []


def check(condition, label):
    assert condition, label
    CHECKS.append(label)
    print('PASS', label)


class Client:
    def __init__(self):
        self.http = requests.Session()
        self.csrf = self.call('session')['csrf']

    def call(self, action, data=None, *, expected=200, method=None, files=None):
        method = method or ('GET' if data is None else 'POST')
        options = {'params': {'action': action}, 'timeout': 20}
        if method == 'POST':
            options['headers'] = {'X-CSRF-Token': self.csrf}
            options.update({'data': data, 'files': files} if files else {'json': data})
        elif data:
            options['params'].update(data)
        r = self.http.request(method, BASE + '/api.php', **options)
        allowed = [200, 201] if expected == 200 else [expected]
        assert r.status_code in allowed, (action, r.status_code, r.text[:300])
        result = r.json()
        assert bool(result['ok']) == (expected == 200), action
        if expected == 200:
            if isinstance(result['data'], dict) and 'csrf' in result['data']:
                self.csrf = result['data']['csrf']
            return result['data']
        return result


def run():
    admin, traveller, guest = Client(), Client(), Client()
    credentials = json.loads((ROOT / 'storage/local-access.json').read_text())
    admin.call('login', credentials)
    stamp = str(time.time_ns())
    email = 'security-' + stamp + '@example.test'
    traveller.call('register', {'name': 'Security Test Traveller', 'email': email,
                               'password': ' password with spaces ' + stamp, 'role': 'admin', 'status': 'active'})
    user = traveller.call('session')['user']
    check(user['role'] == 'traveller', 'Registration rejects caller-controlled privilege escalation')
    traveller.call('logout', {})
    traveller.call('login', {'email': email, 'password': ' password with spaces ' + stamp})
    check(True, 'Password whitespace is preserved across registration and login')
    place = guest.call('places')['items'][0]
    traveller.call('delete_place', {'id': place['id']}, expected=403)
    traveller.call('moderate_place', {'id': place['id'], 'status': 'approved'}, expected=403)
    traveller.call('user_status', {'id': user['id'], 'status': 'suspended'}, expected=403)
    guest.call('admin_dashboard', expected=401)
    check(True, 'Owner boundaries and administrator-only mutations are enforced server-side')
    guest.call('places', {'q': "' OR 1=1 --"}, method='GET')
    guest.call('places', {'sort': 'name; DROP TABLE users'}, method='GET', expected=422)
    guest.call('places', {'lat': 999, 'lng': 0}, method='GET', expected=422)
    guest.call('place', {'id[]': 1}, method='GET', expected=422)
    check(True, 'Search injection remains data and invalid sort/coordinates/IDs are rejected')
    guest.call('delete_place', {'id': place['id']}, method='GET', expected=405)
    guest.call('places', {}, method='POST', expected=405)
    response = guest.http.post(BASE + '/api.php?action=logout', json={}, timeout=15)
    check(response.status_code == 403, 'All mutations require CSRF and correct HTTP methods')
    response = guest.http.post(BASE + '/api.php?action=plan_generate', data='{"broken":',
                headers={'Content-Type': 'application/json', 'X-CSRF-Token': guest.csrf}, timeout=15)
    check(response.status_code == 400, 'Malformed JSON returns a controlled client error')
    guest.call('plan_generate', {'place_ids': [place['id']], 'start_time': '29:99'}, expected=422)
    guest.call('plan_generate', {'place_ids': list(range(1, 15))}, expected=422)
    check(True, 'Invalid and unbounded itinerary requests are rejected')
    fields = {'title': 'Security temporary place ' + stamp, 'category': 'Nature', 'region': 'Makandura',
              'description': '<script>alert("test")</script> Plain text description.',
              'latitude': '6.15', 'longitude': '80.56', 'route_from_colombo': 'Verify route before travel.',
              'conservation_notes': 'Leave no trace.', 'safety_notes': 'Test data only.', 'accessibility': 'Unknown',
              'visit_minutes': '60', 'services_json': '[]'}
    traveller.call('save_place', fields, files={'images[]': ('evil.php', b'<?php echo "bad";', 'image/jpeg')}, expected=422)
    check(True, 'Executable content disguised as an image is rejected')
    image = (ROOT / 'public/assets/place-1.jpg').read_bytes() if (ROOT / 'public/assets/place-1.jpg').exists() else None
    if image is None:
        candidates = list((ROOT / 'public/assets').rglob('*.jpg'))
        assert candidates, 'A seeded JPEG is needed for the upload test'
        image = candidates[0].read_bytes()
    created = traveller.call('save_place', fields, files={'images[]': ('nature.jpg', image, 'image/jpeg')})
    pid = created['id']
    guest.call('place', {'id': pid}, method='GET', expected=404)
    check(created['description'] == fields['description'], 'User text is stored as data; pending content stays private')
    image_id = created['images'][0]['id']
    foreign_image = guest.call('place', {'id': place['id']}, method='GET')['images'][0]['id']
    traveller.call('save_place', dict(fields, id=pid, remove_image_ids=[foreign_image]), expected=403)
    traveller.call('save_place', dict(fields, id=pid, remove_image_ids=[image_id]), expected=422)
    check(True, 'Image ownership and minimum-image requirement cannot be bypassed')
    admin.call('moderate_place', {'id': pid, 'status': 'approved', 'moderation_note': ''})
    service = admin.call('save_service', {'place_id': pid, 'name': 'Security temporary store', 'type': 'shop',
                                        'description': 'Test data', 'website': 'https://example.test'})
    sid = service['id']
    admin.call('save_service', {'id': sid, 'name': 'X', 'type': 'shop', 'website': 'javascript:alert(1)'}, expected=422)
    check(True, 'Service links reject script schemes')
    today = dt.datetime.now(dt.timezone(dt.timedelta(hours=5, minutes=30))).date()
    payment = {'service_id': sid, 'amount': '100.00', 'reference': 'SECURITY-' + stamp,
               'paid_on': today.isoformat(), 'starts_on': today.isoformat(),
               'ends_on': (today + dt.timedelta(days=1)).isoformat(), 'notes': 'Automated security test only'}
    admin.call('record_payment', dict(payment, paid_on=(today + dt.timedelta(days=1)).isoformat()), expected=422)
    admin.call('record_payment', dict(payment, amount='-1'), expected=422)
    check(True, 'Offline payment records reject future payment dates and negative amounts')
    future = admin.call('record_payment', dict(payment, starts_on=(today + dt.timedelta(days=1)).isoformat(), ends_on=(today + dt.timedelta(days=2)).isoformat()))
    expired = admin.call('record_payment', dict(payment, starts_on=(today - dt.timedelta(days=2)).isoformat(), ends_on=(today - dt.timedelta(days=1)).isoformat()))
    public_service = next(s for s in guest.call('services') if s['id'] == sid)
    check(not public_service['is_sponsored'], 'Future and expired sponsorship records do not activate public promotion')
    check('reference' not in public_service and 'amount' not in public_service, 'Public service response excludes private payment information')
    admin.call('delete_service', {'id': sid})
    ledger = admin.call('admin_dashboard')['payments']
    retained = [p for p in ledger if p['id'] in [future['id'], expired['id']]]
    check(len(retained) == 2 and all(p['service_id'] is None and p['service_name'] == 'Security temporary store' for p in retained),
          'Deleting a service retains historical payment records and service names')
    traveller.call('delete_place', {'id': pid})
    admin.call('user_status', {'id': user['id'], 'status': 'suspended'})
    traveller.call('profile', {'name': 'Changed', 'email': email}, expected=401)
    check(True, 'Suspension invalidates existing sessions before profile mutation')
    report = {'passed': len(CHECKS), 'checks': CHECKS, 'base': BASE, 'time': dt.datetime.now().isoformat()}
    (ROOT / 'storage/security-test-result.json').write_text(json.dumps(report, indent=2))
    print(f'\n{len(CHECKS)} security assertions passed.')


if __name__ == '__main__':
    run()
