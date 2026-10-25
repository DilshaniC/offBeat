#!/usr/bin/env python3
"""Create ignored local database secrets; never overwrites an existing config."""
from pathlib import Path
import secrets
root = Path(__file__).resolve().parents[1]
path = root / '.env'
if path.exists():
    print('.env already exists; preserved.')
else:
    path.write_text('APP_PORT=8088\nAPP_ENV=local\nDB_DATABASE=offbeat\nDB_USERNAME=offbeat\n'
                    f'DB_PASSWORD={secrets.token_hex(24)}\nDB_ROOT_PASSWORD={secrets.token_hex(24)}\n')
    path.chmod(0o600)
    print('Created .env with random local database secrets.')

