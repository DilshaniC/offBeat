#!/usr/bin/env python3
"""Repeatable local start. Stores generated admin credentials only in ignored storage."""
from pathlib import Path
import json
import os
import secrets
import subprocess
import sys

root = Path(__file__).resolve().parents[1]
os.chdir(root)
compose = ['docker', 'compose'] + (['-f', 'compose.compat.yaml'] if '--compat' in sys.argv else [])
subprocess.run([sys.executable, 'scripts/configure.py'], check=True)
subprocess.run(compose + ['up', '-d', '--wait'] + ([] if '--compat' in sys.argv else ['--build']), check=True)
# Allow PHP to save images while retaining access for the local project owner.
subprocess.run(compose + ['exec','-T','-u','root','web','sh','-c',
                f'chown -R www-data:{os.getgid()} public/uploads && chmod 2775 public/uploads'], check=True)
credentials = root / 'storage/local-access.json'
if credentials.exists():
    access = json.loads(credentials.read_text())
else:
    access = {'email':'admin@offbeat.local','password':secrets.token_urlsafe(20)}
    credentials.write_text(json.dumps(access, indent=2) + '\n')
    credentials.chmod(0o600)
environment = dict(os.environ, SEED_ADMIN_EMAIL=access['email'], SEED_ADMIN_PASSWORD=access['password'])
subprocess.run(compose + ['exec','-T','-e','SEED_ADMIN_EMAIL','-e','SEED_ADMIN_PASSWORD','web','php','scripts/seed.php'], env=environment, check=True)
port = next((line.split('=',1)[1] for line in (root/'.env').read_text().splitlines() if line.startswith('APP_PORT=')), '8088')
print(f'Offbeat is running at http://localhost:{port}. Administrator credentials: storage/local-access.json (private, ignored).')
