#!/usr/bin/env python3
"""
Sleep Number Historical Data Backfill Script

Fetches ALL historical sleep data from Sleep Number API and syncs to WGER.

USAGE:
    # Backfill from specific date to today
    ./backfill_sleep_data.py --start 2024-01-01

    # Backfill last 365 days
    ./backfill_sleep_data.py --days 365

    # Backfill ALL available data
    ./backfill_sleep_data.py --all

FEATURES:
    - Fetches from Sleep Number API ONCE
    - Caches to local JSON (never lose data!)
    - Posts to WGER from cache (safe retries)
    - Debug logging for audit trail
"""

import os
import sys
import json
import time
import asyncio
import requests
import argparse
from pathlib import Path
from datetime import datetime, timedelta

# Sleep Number imports
try:
    from asyncsleepiq import AsyncSleepIQ
    SLEEPNUMBER_AVAILABLE = True
except ImportError:
    print("❌ asyncsleepiq not installed")
    print("   Install with: pip3 install asyncsleepiq")
    sys.exit(1)

# =============================================================================
# CONFIGURATION
# =============================================================================

# Debug Settings
DEBUG_SAVE_RAW_DATA = True
DEBUG_LOG_POSTS = True
RAW_DATA_DIR = Path(__file__).parent / "backfill_cache"
DEBUG_LOG_FILE = Path(__file__).parent / "backfill_sleep_debug.log"

# Sleep Number Credentials
SLEEPNUMBER_EMAIL = "john.b.hale@gmail.com"
SLEEPNUMBER_PASSWORD = "vjz@kqw!WMF*bpr7ufa"

# WGER
WGER_BASE = 'https://weight.rhcp011235.com'
WGER_TOKEN = 'e4aa72c36288c2c60105bca3977178c8b1d09836'

# =============================================================================
# WGER API
# =============================================================================

def wger_headers():
    return {
        'Authorization': f'Token {WGER_TOKEN}',
        'Content-Type': 'application/json'
    }

def debug_log(message):
    """Log to debug file if enabled"""
    if DEBUG_LOG_POSTS:
        with open(DEBUG_LOG_FILE, 'a') as f:
            timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
            f.write(f"[{timestamp}] {message}\n")

def wger_get_or_create_category(name, unit):
    r = requests.get(
        f"{WGER_BASE}/api/v2/measurement-category/",
        headers=wger_headers(),
        timeout=30
    )
    r.raise_for_status()

    for cat in r.json().get('results', []):
        if cat.get('name') == name and cat.get('unit') == unit:
            return cat['id']

    r = requests.post(
        f"{WGER_BASE}/api/v2/measurement-category/",
        headers=wger_headers(),
        json={'name': name, 'unit': unit},
        timeout=30
    )
    if r.status_code in [200, 201]:
        return r.json()['id']

    raise Exception(f"Failed to create category {name}")

def wger_post_measurement(date, category_id, value, notes=''):
    headers = wger_headers()

    debug_log(f"POST: date={date}, cat={category_id}, value={value}")

    try:
        r = requests.get(
            f"{WGER_BASE}/api/v2/measurement/",
            headers=headers,
            params={'category': category_id, 'date': date},
            timeout=30
        )
        existing_id = None
        if r.status_code == 200:
            results = r.json().get('results', [])
            if results:
                existing_id = results[0]['id']
    except:
        existing_id = None

    payload = {
        'category': category_id,
        'date': date,
        'value': value,
        'notes': notes[:100]
    }

    max_retries = 3
    for attempt in range(max_retries):
        try:
            if existing_id:
                r = requests.put(
                    f"{WGER_BASE}/api/v2/measurement/{existing_id}/",
                    headers=headers,
                    json=payload,
                    timeout=60
                )
                action = "Updated"
            else:
                r = requests.post(
                    f"{WGER_BASE}/api/v2/measurement/",
                    headers=headers,
                    json=payload,
                    timeout=60
                )
                action = "Posted"

            if r.status_code in [200, 201]:
                debug_log(f"  ✅ {action}")
                return True, action
            else:
                debug_log(f"  ❌ HTTP {r.status_code}")
                return False, f"Failed ({r.status_code})"

        except requests.exceptions.Timeout:
            debug_log(f"  ⏱️  Timeout (attempt {attempt + 1})")
            if attempt < max_retries - 1:
                print(f"      ⏱️  Timeout, retrying...")
                time.sleep(2)
                continue
            else:
                debug_log(f"  ❌ Timeout after retries")
                return False, "Timeout"
        except Exception as e:
            debug_log(f"  ❌ {str(e)[:100]}")
            return False, f"Error: {str(e)[:50]}"

    return False, "Unknown error"

# =============================================================================
# SLEEP NUMBER API
# =============================================================================

async def fetch_sleep_data_for_date(api, sleeper_id, date_obj):
    """Fetch sleep data for a specific date"""
    try:
        # Format date as ISO 8601 with time
        date_str = date_obj.strftime('%Y-%m-%dT%H:%M:%S')

        params = {
            'date': date_str,
            'interval': 'D1',
            'sleeper': sleeper_id,
            'includeSlices': 'false'
        }

        param_str = '&'.join(f"{k}={v}" for k, v in params.items())
        endpoint = f"sleepData?{param_str}"

        data = await api.get(endpoint)
        return data

    except Exception as e:
        error_msg = str(e)
        if '404' in error_msg:
            return None  # No data for this date
        else:
            print(f"      ⚠️  Error: {error_msg[:100]}")
            return None

async def fetch_all_sleep_data(start_date, end_date):
    """Fetch all sleep data from Sleep Number for date range"""
    print("\n🔐 Logging into Sleep Number...")
    api = AsyncSleepIQ()
    await api.login(SLEEPNUMBER_EMAIL, SLEEPNUMBER_PASSWORD)
    await api.init_beds()

    beds = api.beds
    if not beds:
        print("❌ No beds found")
        return None

    bed = list(beds.values())[0]
    sleepers = bed.sleepers
    if not sleepers:
        print("❌ No sleepers found")
        return None

    sleeper = sleepers[0]

    print(f"✅ Connected to bed: {bed.name if hasattr(bed, 'name') else bed.id}")
    print(f"✅ Sleeper: {sleeper.name} ({sleeper.side})")
    print(f"✅ Sleeper ID: {sleeper.sleeper_id}\n")

    # Calculate date range
    current = start_date
    total_days = (end_date - start_date).days + 1

    print(f"📅 Fetching sleep data for {total_days} days...")
    print(f"   From: {start_date.strftime('%Y-%m-%d')}")
    print(f"   To:   {end_date.strftime('%Y-%m-%d')}\n")

    all_sleep_data = {}
    successful = 0
    no_data = 0

    while current <= end_date:
        date_str = current.strftime('%Y-%m-%d')
        print(f"   {date_str}: ", end='', flush=True)

        data = await fetch_sleep_data_for_date(api, sleeper.sleeper_id, current)

        if data and data.get('sleepData'):
            all_sleep_data[date_str] = data
            print(f"✅ (Score: {data.get('avgSleepIQ', 'N/A')})")
            successful += 1
        else:
            print(f"⚠️  No data")
            no_data += 1

        current += timedelta(days=1)

        # Rate limiting - be nice to Sleep Number API
        await asyncio.sleep(0.5)

    print(f"\n✅ Fetch complete!")
    print(f"   Successful: {successful} days")
    print(f"   No data: {no_data} days")

    return {
        'fetch_date': datetime.now().isoformat(),
        'date_range': {
            'start': start_date.strftime('%Y-%m-%d'),
            'end': end_date.strftime('%Y-%m-%d')
        },
        'sleeper': {
            'id': sleeper.sleeper_id,
            'name': sleeper.name,
            'side': str(sleeper.side)
        },
        'bed': {
            'id': bed.id,
            'name': bed.name if hasattr(bed, 'name') else 'Unknown'
        },
        'sleep_data': all_sleep_data
    }

# =============================================================================
# MAIN BACKFILL LOGIC
# =============================================================================

def backfill_sleep_number(start_date, end_date):
    """Backfill Sleep Number data for date range"""
    print("\n" + "=" * 70)
    print("😴 BACKFILLING SLEEP NUMBER DATA")
    print("=" * 70)

    # Create cache directory
    if DEBUG_SAVE_RAW_DATA:
        RAW_DATA_DIR.mkdir(exist_ok=True)

    start_str = start_date.strftime('%Y-%m-%d')
    end_str = end_date.strftime('%Y-%m-%d')
    raw_data_file = RAW_DATA_DIR / f"sleepnumber_{start_str}_to_{end_str}.json"

    # Step 1: Fetch from Sleep Number API (or load from cache)
    if raw_data_file.exists():
        print(f"\n📂 Found cached data: {raw_data_file.name}")
        print(f"   Using cached data (prevents API rate limiting)")

        with open(raw_data_file, 'r') as f:
            cached_data = json.load(f)
        all_data = cached_data
        print(f"   ✅ Loaded from cache")
    else:
        print(f"\n🌐 Fetching from Sleep Number API...")
        print(f"   ⚠️  This will make API calls - data will be cached")

        all_data = asyncio.run(fetch_all_sleep_data(start_date, end_date))

        if not all_data:
            print("❌ Failed to fetch data")
            return

        # Save to cache
        if DEBUG_SAVE_RAW_DATA:
            with open(raw_data_file, 'w') as f:
                json.dump(all_data, f, indent=2)
            print(f"\n💾 Cached to: {raw_data_file}")

    # Step 2: Post to WGER from cached data
    print(f"\n" + "=" * 70)
    print(f"📤 POSTING TO WGER")
    print(f"=" * 70)

    # Get or create categories
    print(f"\n🏷️  Creating/fetching WGER categories...")
    cat_duration = wger_get_or_create_category("Sleep Duration", "hours")
    cat_score = wger_get_or_create_category("Sleep Score", "score")
    cat_hr = wger_get_or_create_category("Sleep Heart Rate", "bpm")
    cat_rr = wger_get_or_create_category("Sleep Respiratory Rate", "brpm")
    print(f"   ✅ Categories ready")

    # Post sleep data
    print(f"\n😴 Posting sleep data...")

    sleep_data = all_data.get('sleep_data', {})
    success_count = 0

    for date_str, day_data in sorted(sleep_data.items()):
        # Extract metrics
        duration_sec = day_data.get('totalSleepSessionTime', 0)
        duration_hours = round(duration_sec / 3600, 2) if duration_sec else None
        score = day_data.get('avgSleepIQ')
        hr = day_data.get('avgHeartRate')
        rr = day_data.get('avgRespirationRate')

        metrics_posted = 0

        if duration_hours and duration_hours > 0:
            success, action = wger_post_measurement(date_str, cat_duration, duration_hours, "Sleep Number")
            if success:
                metrics_posted += 1

        if score:
            success, action = wger_post_measurement(date_str, cat_score, score, "Sleep Number")
            if success:
                metrics_posted += 1

        if hr:
            success, action = wger_post_measurement(date_str, cat_hr, hr, "Sleep Number")
            if success:
                metrics_posted += 1

        if rr:
            success, action = wger_post_measurement(date_str, cat_rr, rr, "Sleep Number")
            if success:
                metrics_posted += 1

        if metrics_posted > 0:
            print(f"   ✅ {date_str}: {metrics_posted} metrics (Score: {score}, {duration_hours}h)")
            success_count += 1

    print(f"\n✅ Sleep Number backfill complete!")
    print(f"   Days posted: {success_count}")
    print(f"\n🎉 BACKFILL COMPLETE!")

def main():
    parser = argparse.ArgumentParser(
        description='Backfill Sleep Number historical data',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  # From specific date to today
  ./backfill_sleep_data.py --start 2024-01-01

  # Last 365 days
  ./backfill_sleep_data.py --days 365

  # ALL available data (recommended!)
  ./backfill_sleep_data.py --all
        """
    )

    parser.add_argument('--days', type=int, help='Number of days back to sync')
    parser.add_argument('--start', type=str, help='Start date (YYYY-MM-DD)')
    parser.add_argument('--all', action='store_true', help='Sync all available data (2 years)')

    args = parser.parse_args()

    # Calculate date range
    end_date = datetime.now()

    if args.all:
        # Go back 2 years (Sleep Number typically keeps ~2 years)
        start_date = end_date - timedelta(days=730)
        print("📅 Syncing ALL available data (2 years)")
    elif args.days:
        start_date = end_date - timedelta(days=args.days)
        print(f"📅 Syncing last {args.days} days")
    elif args.start:
        start_date = datetime.strptime(args.start, '%Y-%m-%d')
        print(f"📅 Syncing from {args.start} to today")
    else:
        parser.print_help()
        sys.exit(1)

    print(f"\n🚀 Starting Sleep Number backfill...")
    print(f"   Start: {start_date.strftime('%Y-%m-%d')}")
    print(f"   End:   {end_date.strftime('%Y-%m-%d')}")

    # Clear old debug log
    if DEBUG_LOG_FILE.exists():
        DEBUG_LOG_FILE.unlink()

    # Run backfill
    backfill_sleep_number(start_date, end_date)

    print("\n" + "=" * 70)
    print("✅ BACKFILL COMPLETE!")
    print("=" * 70)
    print(f"\nView your data at: {WGER_BASE}/weight_enhanced.php")
    print(f"Sleep data now available for ChatGPT analysis!")

if __name__ == '__main__':
    main()
