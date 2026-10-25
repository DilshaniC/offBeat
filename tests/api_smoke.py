#!/usr/bin/env python3
"""Real HTTP/MySQL workflow test. Requires a local seeded instance; cleans test rows.

Run with OFFBEAT_ADMIN_EMAIL and OFFBEAT_ADMIN_PASSWORD in environment.
Credentials and session tokens are never printed. See README for setup.
"""
import json
import os
from pathlib import Path
import time
import requests

BASE = os.environ.get('OFFBEAT_URL', 'http://127.0.0.1:8088').rstrip('/')
ROOT = Path(__file__).resolve().parents[1]
checks = []

def check(condition, label):
    if not condition:
        raise AssertionError(label)
    checks.append(label)
    print('PASS', label)

class Client:
    def __init__(self):
        self.http = requests.Session()
        self.csrf = self.get('session')['csrf']

    def get(self, action, **params):
        r = self.http.get(BASE + '/api.php', params={'action': action, **params}, timeout=15)
        assert r.status_code == 200, (action, r.status_code, r.text[:300])
        body = r.json()
        assert body['ok'], (action, body)
        return body['data']

    def post(self, action, data=None, expected=200, files=None):
        opts = {'data': data, 'files': files} if files else {'json': data or {}}
        r = self.http.post(BASE + '/api.php?action=' + action,
                           headers={'X-CSRF-Token': self.csrf}, timeout=20, **opts)
        assert r.status_code in ([200, 201] if expected == 200 else [expected]), (action, r.status_code, r.text[:500])
        result = r.json()
        if expected < 300:
            assert result['ok'], (action, result)
            self.csrf = self.get('session')['csrf']
            return result['data']
        assert not result['ok'], action
        return result

def run():
    guest, traveller, stranger, admin = Client(), Client(), Client(), Client()
    stamp = str(time.time_ns())
    email = 'smoke-' + stamp + '@example.test'
    password = 'Test-Only-' + stamp
    page = guest.get('places')
    check(page['total'] >= 10, 'Catalogue has at least ten demonstration places')
    ids = [int(p['id']) for p in page['items'][:3]]
    detail = guest.get('place', id=ids[0])
    check(bool(detail['route_from_colombo']) and bool(detail['images']), 'Place includes images and Colombo route')
    check(guest.get('places', q='not-a-real-place-' + stamp)['total'] == 0, 'Empty search is valid')
    guest.post('save_toggle', {'place_id': ids[0]}, expected=401)
    check(True, 'Guest cannot save private favourites')
    bad = guest.http.post(BASE + '/api.php?action=register', json={'name': 'CSRF'}, timeout=15)
    check(bad.status_code == 403, 'Mutations reject missing CSRF')
    traveller.post('register', {'name': 'Smoke Traveller', 'email': email, 'password': password, 'role': 'admin'})
    if not traveller.get('session')['user']:
        traveller.post('login', {'email': email, 'password': password})
    user = traveller.get('session')['user']
    check(user['role'] == 'traveller', 'Registration cannot elevate role')
    r = traveller.http.get(BASE + '/api.php?action=admin_dashboard', timeout=15)
    check(r.status_code == 403, 'Traveller cannot read admin dashboard')
    stranger.post('register', {'name': 'Other Traveller', 'email': 'other-' + email, 'password': password})
    if not stranger.get('session')['user']:
        stranger.post('login', {'email': 'other-' + email, 'password': password})
    traveller.post('save_toggle', {'place_id': ids[0]})
    check(any(int(p['id']) == ids[0] for p in traveller.get('saved')), 'Favourites persist')
    itinerary = guest.post('plan_generate', {'place_ids': ids, 'start_lat': 6.9271, 'start_lng': 79.8612, 'start_time': '07:00'})
    check(len(itinerary['stops']) == len(ids) and itinerary['total_minutes'] > 0, 'Guest itinerary computes ordered stops and estimates')
    plan = traveller.post('plan_save', {'name': 'Smoke day', 'place_ids': ids, 'start_lat': 6.9271, 'start_lng': 79.8612, 'start_time': '07:00'})
    plans = traveller.get('plans')
    check(len(plans) >= 1, 'Itinerary saved to MySQL')
    plan_id = int(plan.get('id', plans[-1]['id']))
    stranger.post('plan_delete', {'id': plan_id}, expected=404)
    traveller.post('plan_delete', {'id': plan_id})
    fields = {'title': 'Smoke Grove ' + stamp, 'category': 'Nature', 'region': 'Makandura',
              'description': 'A test-only nature place for validating the complete moderated traveller workflow.',
              'latitude': '6.15', 'longitude': '80.56', 'route_from_colombo': 'Test-only directions from Colombo via the southern approach; verify access locally.',
              'conservation_notes': 'Carry all waste home, keep quiet, and stay on permitted paths.',
              'safety_notes': 'Test data only. Confirm weather and permission before travelling.',
              'visit_minutes': '60', 'difficulty': 'Easy', 'best_time': 'Morning',
              'services_json': json.dumps([{'type': 'guide', 'name': 'Smoke guide', 'description': 'Test record', 'phone': '', 'address': 'Test location', 'latitude': 6.15, 'longitude': 80.56}])}
    traveller.post('save_place', fields, expected=422)
    with open(ROOT / 'public/assets/images/forest.jpg', 'rb') as photo:
        created = traveller.post('save_place', fields, files={'images[]': ('grove.jpg', photo, 'image/jpeg')})
    place_id = int(created['id'])
    check(created['status'] == 'pending', 'Image contribution enters moderation')
    check(guest.http.get(BASE + '/api.php', params={'action': 'place', 'id': place_id}, timeout=15).status_code == 404, 'Pending place hidden from public URL')
    stranger.post('delete_place', {'id': place_id}, expected=404)
    check(True, 'Ownership blocks another traveller deleting place')
    admin.post('login', {'email': os.environ['OFFBEAT_ADMIN_EMAIL'], 'password': os.environ['OFFBEAT_ADMIN_PASSWORD']})
    check(admin.get('session')['user']['role'] == 'admin', 'Administrator signs in')
    admin.post('moderate_place', {'id': place_id, 'status': 'approved', 'moderation_note': 'Smoke reviewed'})
    check(guest.get('place', id=place_id)['status'] == 'approved', 'Approval publishes submitted place')
    service = admin.post('save_service', {'place_id': place_id, 'type': 'shop', 'name': 'Smoke store', 'description': 'Test store', 'phone': '', 'address': 'Test road', 'latitude': 6.15, 'longitude': 80.56, 'website': ''})
    service_id = int(service['id'])
    check(any(int(s['id']) == service_id for s in guest.get('services', place_id=place_id)), 'Admin service appears publicly')
    guest.post('advertise', {'business_name': 'Smoke store', 'contact_name': 'Smoke owner', 'email': email, 'phone': '+94770000000', 'message': 'Test inquiry about offline advertising.', 'place_id': place_id})
    check(not next(s for s in guest.get('services', place_id=place_id) if int(s['id']) == service_id)['is_sponsored'], 'Inquiry alone does not activate sponsorship')
    import datetime
    today = datetime.date.today()
    payment = admin.post('record_payment', {'service_id': service_id, 'amount': '2500', 'reference': 'SMOKE-' + stamp,
                 'paid_on': today.isoformat(), 'starts_on': today.isoformat(), 'ends_on': (today + datetime.timedelta(days=30)).isoformat(), 'notes': 'Automated test only'})
    check(bool(next(s for s in guest.get('services', place_id=place_id) if int(s['id']) == service_id)['is_sponsored']), 'Recorded offline payment activates dated sponsorship')
    admin.post('cancel_payment', {'id': payment['id']})
    check(not next(s for s in guest.get('services', place_id=place_id) if int(s['id']) == service_id)['is_sponsored'], 'Cancellation removes sponsorship')
    traveller.post('report', {'place_id': place_id, 'reason': 'Smoke report: please check this test detail.'})
    dashboard = admin.get('admin_dashboard')
    report = next(r for r in dashboard['reports'] if int(r['place_id']) == place_id)
    admin.post('resolve_report', {'id': report['id'], 'status': 'resolved'})
    admin.post('user_status', {'id': user['id'], 'status': 'suspended'})
    traveller.post('save_toggle', {'place_id': ids[0]}, expected=401)
    check(True, 'Suspension blocks existing authenticated session')
    admin.post('user_status', {'id': user['id'], 'status': 'active'})
    traveller.post('login', {'email': email, 'password': password})
    fields['id'] = str(place_id)
    traveller.post('save_place', fields)
    check(traveller.get('place', id=place_id)['status'] == 'pending', 'Editing an approved place requires review again')
    admin.post('delete_service', {'id': service_id})
    traveller.post('delete_place', {'id': place_id})
    traveller.post('logout')
    check(traveller.get('session')['user'] is None, 'Logout clears authentication')
    print(f'\n{len(checks)} assertions passed against real PHP/MySQL HTTP endpoints.')
    (ROOT / 'storage/api-test-result.json').write_text(json.dumps({'passed': len(checks), 'checks': checks, 'base': BASE, 'time': time.strftime('%Y-%m-%dT%H:%M:%S')}, indent=2))

if __name__ == '__main__':
    run()
