#!/usr/bin/env python3
"""
C Tech Fix — SFTP Deploy Script
Run this from your computer to upload all CRM files to Namecheap.

Requirements: pip install paramiko
Usage:        python deploy.py [--dry-run] [--path public/CRM/modules/bookings]
"""

import os, sys, argparse, configparser, fnmatch, pathlib
import paramiko

# ── Config ──────────────────────────────────────────────────────────────────
CFG_FILE = os.path.join(os.path.dirname(__file__), 'deploy.cfg')

# Files/folders to never upload
IGNORE_PATTERNS = [
    '.git', '.git/*', '*.pyc', '__pycache__',
    'deploy.py', 'deploy.cfg', 'debug.php',
    'docs', 'logs', '.DS_Store', 'Thumbs.db',
    '*.sql',          # run migrations manually in phpMyAdmin
]

# Only upload these top-level folders
UPLOAD_ROOTS = ['config', 'core', 'cron', 'public']

# ── Helpers ──────────────────────────────────────────────────────────────────
def load_cfg():
    raw = {}
    with open(CFG_FILE) as f:
        for line in f:
            line = line.strip()
            if '=' in line and not line.startswith('#'):
                k, v = line.split('=', 1)
                raw[k.strip()] = v.strip()
    return raw

def should_ignore(rel_path: str) -> bool:
    parts = pathlib.PurePosixPath(rel_path).parts
    for pat in IGNORE_PATTERNS:
        if fnmatch.fnmatch(rel_path, pat):
            return True
        if any(fnmatch.fnmatch(p, pat) for p in parts):
            return True
    return False

def sftp_mkdir_p(sftp, remote_dir):
    """Recursively create remote directories."""
    dirs = []
    path = remote_dir
    while path not in ('/', ''):
        dirs.append(path)
        path = os.path.dirname(path)
    for d in reversed(dirs):
        try:
            sftp.stat(d)
        except FileNotFoundError:
            sftp.mkdir(d)

def collect_files(local_root: str, filter_path: str = None):
    """Yield (local_abs, relative) pairs for everything to upload."""
    base = pathlib.Path(local_root)
    roots = UPLOAD_ROOTS
    if filter_path:
        # Only upload files under the given sub-path
        roots = [filter_path]

    for root_name in roots:
        root_path = base / root_name
        if not root_path.exists():
            continue
        if root_path.is_file():
            rel = root_name
            if not should_ignore(rel):
                yield str(root_path), rel
        else:
            for dirpath, dirnames, filenames in os.walk(root_path):
                # Prune ignored dirs in-place
                dirnames[:] = [d for d in dirnames
                                if not should_ignore(os.path.relpath(
                                    os.path.join(dirpath, d), local_root).replace('\\', '/'))]
                for fname in filenames:
                    abs_path = os.path.join(dirpath, fname)
                    rel = os.path.relpath(abs_path, local_root).replace('\\', '/')
                    if not should_ignore(rel):
                        yield abs_path, rel

# ── Main ─────────────────────────────────────────────────────────────────────
def main():
    parser = argparse.ArgumentParser(description='Deploy C Tech Fix CRM to Namecheap')
    parser.add_argument('--dry-run', action='store_true', help='Show what would be uploaded, no actual transfer')
    parser.add_argument('--path', default=None, metavar='SUB_PATH',
                        help='Only upload a sub-path, e.g. public/CRM/modules/bookings')
    args = parser.parse_args()

    cfg = load_cfg()
    host        = cfg['SSH_HOST']
    port        = int(cfg['SSH_PORT'])
    username    = cfg['SSH_USER']
    password    = cfg['SSH_PASS']
    remote_base = cfg['SSH_REMOTE_PATH']   # e.g. /home/ctrecfkn/public_html/CRM
    local_root  = os.path.dirname(os.path.abspath(__file__))

    # Collect file list
    files = list(collect_files(local_root, args.path))
    print(f"{'[DRY RUN] ' if args.dry_run else ''}Deploying {len(files)} files to {host}:{remote_base}\n")

    if args.dry_run:
        for _, rel in files:
            print(f"  WOULD UPLOAD → {rel}")
        return

    # Connect
    print(f"Connecting to {host}:{port} as {username}...")
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(host, port=port, username=username, password=password,
                look_for_keys=False, allow_agent=False, timeout=30)
    sftp = ssh.open_sftp()
    print("Connected.\n")

    ok = 0
    errors = []
    for local_abs, rel in files:
        remote_path = remote_base + '/' + rel
        remote_dir  = os.path.dirname(remote_path)
        try:
            sftp_mkdir_p(sftp, remote_dir)
            sftp.put(local_abs, remote_path)
            print(f"  ✓ {rel}")
            ok += 1
        except Exception as e:
            print(f"  ✗ {rel}  ({e})")
            errors.append((rel, str(e)))

    sftp.close()
    ssh.close()

    print(f"\n{'='*50}")
    print(f"Done. {ok} uploaded, {len(errors)} failed.")
    if errors:
        print("\nFailed files:")
        for rel, err in errors:
            print(f"  {rel}: {err}")

if __name__ == '__main__':
    main()
