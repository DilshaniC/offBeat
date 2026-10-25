#!/usr/bin/env python3
"""Run API checks with ignored local admin credentials; never print secrets."""
import json
import os
from pathlib import Path
import subprocess
import sys
root = Path(__file__).resolve().parents[1]
access = json.loads((root/'storage/local-access.json').read_text())
env = dict(os.environ, OFFBEAT_ADMIN_EMAIL=access['email'], OFFBEAT_ADMIN_PASSWORD=access['password'])
subprocess.run([sys.executable,str(root/'tests/api_smoke.py')],cwd=root,env=env,check=True)
