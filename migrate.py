#!/usr/bin/env python3
"""
migrate.py — Import sms_csv/ and twilio_csv/ into RDS.

Usage:
  python migrate.py                 # migrate both
  python migrate.py --sms-only      # SMS only
  python migrate.py --twilio-only   # Twilio only
  python migrate.py --dry-run       # preview row counts, no DB writes

Already-imported files are skipped automatically (safe to re-run).
"""

import csv
import os
import sys
import argparse
from pathlib import Path

try:
    import pymysql
    import pymysql.cursors
except ImportError:
    print("pymysql not installed. Run:  pip install pymysql")
    sys.exit(1)

# ── DB config ─────────────────────────────────────────────────────────────────
DB_HOST = os.getenv('DB_HOST', 'ghl-credits-db.cr0yeukuujnk.ap-southeast-1.rds.amazonaws.com')
DB_NAME = os.getenv('DB_NAME', 'ghlcredits')
DB_USER = os.getenv('DB_USER', 'admin')
DB_PASS = os.getenv('DB_PASS', 'ghlcredits123')

BASE_DIR = Path(__file__).parent


def get_conn():
    return pymysql.connect(
        host=DB_HOST, database=DB_NAME, user=DB_USER, password=DB_PASS,
        charset='utf8mb4', cursorclass=pymysql.cursors.DictCursor,
        autocommit=False,
    )


def already_imported(cur, table: str, filename: str) -> bool:
    cur.execute(f"SELECT COUNT(*) AS n FROM `{table}` WHERE source_file = %s", (filename,))
    return cur.fetchone()['n'] > 0


# ── SMS ───────────────────────────────────────────────────────────────────────
# Columns (by index — header row 10 is malformed in source CSV):
#  0  Message ID        1  Date/Time         2  From
#  3  Send to           4  Event Title       5  Message Content
#  6  No of SMS         7  Country           8  Telco
#  9  Message Status   10  Undelivered reason 11 Event ID

def migrate_sms(conn, dry_run: bool = False, limit: int = None):
    with conn.cursor() as cur:
        cur.execute("""
            CREATE TABLE IF NOT EXISTS sms_messages (
                id                 INT AUTO_INCREMENT PRIMARY KEY,
                message_id         VARCHAR(50),
                sent_at            DATETIME,
                sender             VARCHAR(100),
                recipient          VARCHAR(50),
                event_title        VARCHAR(255),
                message_content    TEXT,
                sms_count          INT,
                country            VARCHAR(100),
                telco              VARCHAR(100),
                status             VARCHAR(50),
                undelivered_reason VARCHAR(255),
                event_id           VARCHAR(50),
                source_file        VARCHAR(255),
                created_at         DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        """)
        conn.commit()

    sms_dir = BASE_DIR / 'sms_csv'
    files   = sorted(sms_dir.glob('*.csv'))
    total   = 0

    for filepath in files:
        fname = filepath.name
        with conn.cursor() as cur:
            if not dry_run and already_imported(cur, 'sms_messages', fname):
                print(f"  [SKIP] sms_csv/{fname} — already imported")
                continue

        rows = []
        # Files are UTF-16 LE (BOM) + tab-separated
        with open(filepath, encoding='utf-16', newline='') as f:
            reader = csv.DictReader(f, delimiter='\t')
            for row in reader:
                if limit and len(rows) >= limit:
                    break
                if not any(v.strip() for v in row.values()):
                    continue

                def g(k): return (row.get(k) or '').strip()

                sms_count = g('No of SMS')
                rows.append((
                    g('Message ID'),
                    g('Date/Time') or None,
                    g('From'),
                    g('Send to'),
                    g('Event Title'),
                    g('Message Content'),
                    int(sms_count) if sms_count.isdigit() else None,
                    g('Country'),
                    g('Telco'),
                    g('Message Status'),
                    g('Undelivered reason'),
                    g('Event ID'),
                    fname,
                ))

        if not dry_run and rows:
            with conn.cursor() as cur:
                cur.executemany("""
                    INSERT INTO sms_messages
                    (message_id, sent_at, sender, recipient, event_title, message_content,
                     sms_count, country, telco, status, undelivered_reason, event_id, source_file)
                    VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
                """, rows)
            conn.commit()

        tag = '[DRY] ' if dry_run else ''
        print(f"  {tag}sms_csv/{fname}: {len(rows)} rows")
        total += len(rows)

    print(f"SMS total: {total} rows\n")


# ── Twilio ────────────────────────────────────────────────────────────────────
# Columns (by header name — clean CSV):
#   Project, Company Name, Item Category, Item Group, Item Country, Item,
#   Quantity, Amount (USD), Account Sid, Account Type, Parent SID,
#   Month, Year, Invoice Number

def migrate_twilio(conn, dry_run: bool = False):
    with conn.cursor() as cur:
        cur.execute("""
            CREATE TABLE IF NOT EXISTS twilio_usage (
                id             INT AUTO_INCREMENT PRIMARY KEY,
                project        VARCHAR(255),
                company_name   VARCHAR(255),
                item_category  VARCHAR(100),
                item_group     VARCHAR(100),
                item_country   VARCHAR(100),
                item           VARCHAR(255),
                quantity       INT,
                amount_usd     DECIMAL(10,4),
                account_sid    VARCHAR(100),
                account_type   VARCHAR(100),
                parent_sid     VARCHAR(100),
                month          TINYINT,
                year           SMALLINT,
                invoice_number VARCHAR(100),
                source_file    VARCHAR(255),
                created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        """)
        conn.commit()

    twilio_dir = BASE_DIR / 'twilio_csv'
    files      = sorted(twilio_dir.glob('*.csv'))
    total      = 0

    for filepath in files:
        fname = filepath.name
        with conn.cursor() as cur:
            if not dry_run and already_imported(cur, 'twilio_usage', fname):
                print(f"  [SKIP] twilio_csv/{fname} — already imported")
                continue

        rows = []
        with open(filepath, encoding='utf-8-sig', newline='') as f:
            reader = csv.DictReader(f)
            for row in reader:
                if not any(row.values()):
                    continue

                def g(k): return (row.get(k) or '').strip()

                raw_amt = g('Amount (USD)').replace('$', '').replace(',', '')
                raw_qty = g('Quantity').replace(',', '')
                raw_mon = g('Month')
                raw_yr  = g('Year')

                rows.append((
                    g('Project'),
                    g('Company Name'),
                    g('Item Category'),
                    g('Item Group'),
                    g('Item Country'),
                    g('Item'),
                    int(raw_qty)   if raw_qty.lstrip('-').isdigit() else None,
                    float(raw_amt) if raw_amt else None,
                    g('Account Sid'),
                    g('Account Type'),
                    g('Parent SID'),
                    int(raw_mon)   if raw_mon.isdigit() else None,
                    int(raw_yr)    if raw_yr.isdigit()  else None,
                    g('Invoice Number'),
                    fname,
                ))

        if not dry_run and rows:
            with conn.cursor() as cur:
                cur.executemany("""
                    INSERT INTO twilio_usage
                    (project, company_name, item_category, item_group, item_country, item,
                     quantity, amount_usd, account_sid, account_type, parent_sid,
                     month, year, invoice_number, source_file)
                    VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
                """, rows)
            conn.commit()

        tag = '[DRY] ' if dry_run else ''
        print(f"  {tag}twilio_csv/{fname}: {len(rows)} rows")
        total += len(rows)

    print(f"Twilio total: {total} rows\n")


# ── Entry point ───────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description='Migrate sms_csv/ and twilio_csv/ to RDS')
    parser.add_argument('--dry-run',      action='store_true', help='Preview only, no DB writes')
    parser.add_argument('--sms-only',     action='store_true', help='Only migrate sms_csv/')
    parser.add_argument('--twilio-only',  action='store_true', help='Only migrate twilio_csv/')
    parser.add_argument('--limit',        type=int, default=None, help='Only import first N rows (for testing)')
    args = parser.parse_args()

    if args.dry_run:
        print("DRY RUN — no data will be written to the database\n")

    try:
        conn = get_conn()
    except Exception as e:
        print(f"DB connection failed: {e}")
        sys.exit(1)

    try:
        if not args.twilio_only:
            print("── sms_csv/ ─────────────────────────────────")
            migrate_sms(conn, dry_run=args.dry_run, limit=args.limit)

        if not args.sms_only:
            print("── twilio_csv/ ──────────────────────────────")
            migrate_twilio(conn, dry_run=args.dry_run)

        print("Done.")
    except Exception as e:
        conn.rollback()
        print(f"ERROR: {e}")
        sys.exit(1)
    finally:
        conn.close()


if __name__ == '__main__':
    main()
