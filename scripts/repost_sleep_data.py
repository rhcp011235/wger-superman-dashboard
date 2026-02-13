#!/usr/bin/env python3
"""
Re-post Sleep Number data from local cache to WGER

This script reads cached sleep data and posts it to WGER.
Useful when:
  - WGER was down during initial sync
  - You want to re-sync all sleep data
  - WGER categories were changed/recreated

USAGE:
    # Re-post specific date
    ./repost_sleep_data.py 2026-02-14

    # Re-post all cached sleep data
    ./repost_sleep_data.py --all

    # Re-post date range
    ./repost_sleep_data.py --start 2026-02-01 --end 2026-02-14
"""

import os
import sys
import json
import requests
import argparse
from pathlib import Path
from datetime import datetime, timedelta

# =============================================================================
# CONFIGURATION
# =============================================================================

# WGER
WGER_BASE = 'https://weight.rhcp011235.com'
WGER_TOKEN = 'e4aa72c36288c2c60105bca3977178c8b1d09836'

# Sleep cache directory
SCRIPT_DIR = Path(__file__).parent
SLEEP_CACHE_DIR = SCRIPT_DIR / 'sleep_cache'

# =============================================================================
# WGER API
# =============================================================================

def wger_headers():
    return {
        'Authorization': f'Token {WGER_TOKEN}',
        'Content-Type': 'application/json'
    }

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

    if existing_id:
        r = requests.put(
            f"{WGER_BASE}/api/v2/measurement/{existing_id}/",
            headers=headers,
            json=payload,
            timeout=30
        )
        action = "Updated"
    else:
        r = requests.post(
            f"{WGER_BASE}/api/v2/measurement/",
            headers=headers,
            json=payload,
            timeout=30
        )
        action = "Posted"

    if r.status_code in [200, 201]:
        return True, action
    else:
        return False, f"Failed ({r.status_code})"

# =============================================================================
# SLEEP DATA RE-POST
# =============================================================================

def repost_sleep_file(cache_file):
    """Re-post a single sleep data cache file to WGER"""
    with open(cache_file, 'r') as f:
        cached = json.load(f)

    date = cached['date']
    sleep_data = cached.get('data', {})
    sleeper = cached.get('sleeper', 'Unknown')

    print(f"\n📅 {date} ({sleeper})")

    # Get or create categories
    cat_duration = wger_get_or_create_category("Sleep Duration", "hours")
    cat_score = wger_get_or_create_category("Sleep Score", "score")
    cat_hr = wger_get_or_create_category("Sleep Heart Rate", "bpm")
    cat_hrv = wger_get_or_create_category("Sleep HRV", "ms")
    cat_rr = wger_get_or_create_category("Sleep Respiratory Rate", "brpm")

    success_count = 0

    if sleep_data.get('duration_hours'):
        success, action = wger_post_measurement(date, cat_duration,
                                               sleep_data['duration_hours'], "Sleep Number")
        if success:
            print(f"   ✅ Duration: {sleep_data['duration_hours']} hours ({action})")
            success_count += 1

    if sleep_data.get('sleep_score'):
        success, action = wger_post_measurement(date, cat_score,
                                               sleep_data['sleep_score'], "Sleep Number")
        if success:
            print(f"   ✅ Score: {sleep_data['sleep_score']} ({action})")
            success_count += 1

    if sleep_data.get('avg_heart_rate'):
        success, action = wger_post_measurement(date, cat_hr,
                                               sleep_data['avg_heart_rate'], "Sleep Number")
        if success:
            print(f"   ✅ Heart Rate: {sleep_data['avg_heart_rate']} bpm ({action})")
            success_count += 1

    if sleep_data.get('avg_hrv'):
        success, action = wger_post_measurement(date, cat_hrv,
                                               sleep_data['avg_hrv'], "Sleep Number")
        if success:
            print(f"   ✅ HRV: {sleep_data['avg_hrv']} ms ({action})")
            success_count += 1

    if sleep_data.get('avg_resp_rate'):
        success, action = wger_post_measurement(date, cat_rr,
                                               sleep_data['avg_resp_rate'], "Sleep Number")
        if success:
            print(f"   ✅ Resp Rate: {sleep_data['avg_resp_rate']} brpm ({action})")
            success_count += 1

    return success_count

def main():
    parser = argparse.ArgumentParser(
        description='Re-post cached Sleep Number data to WGER',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  # Re-post specific date
  ./repost_sleep_data.py 2026-02-14

  # Re-post all cached data
  ./repost_sleep_data.py --all

  # Re-post date range
  ./repost_sleep_data.py --start 2026-02-01 --end 2026-02-14
        """
    )

    parser.add_argument('date', nargs='?', help='Specific date (YYYY-MM-DD)')
    parser.add_argument('--all', action='store_true', help='Re-post all cached sleep data')
    parser.add_argument('--start', type=str, help='Start date (YYYY-MM-DD)')
    parser.add_argument('--end', type=str, help='End date (YYYY-MM-DD)')

    args = parser.parse_args()

    if not SLEEP_CACHE_DIR.exists():
        print(f"❌ Sleep cache directory not found: {SLEEP_CACHE_DIR}")
        print(f"   No cached sleep data available.")
        sys.exit(1)

    print("🛏️  Sleep Number Data Re-Post Tool")
    print("=" * 60)

    # Determine which files to process
    cache_files = []

    if args.all:
        cache_files = sorted(SLEEP_CACHE_DIR.glob("*.json"))
        print(f"📂 Found {len(cache_files)} cached sleep files")
    elif args.start and args.end:
        start_date = datetime.strptime(args.start, '%Y-%m-%d')
        end_date = datetime.strptime(args.end, '%Y-%m-%d')

        current = start_date
        while current <= end_date:
            date_str = current.strftime('%Y-%m-%d')
            cache_file = SLEEP_CACHE_DIR / f"{date_str}.json"
            if cache_file.exists():
                cache_files.append(cache_file)
            current += timedelta(days=1)

        print(f"📂 Found {len(cache_files)} files in date range")
    elif args.date:
        cache_file = SLEEP_CACHE_DIR / f"{args.date}.json"
        if cache_file.exists():
            cache_files = [cache_file]
        else:
            print(f"❌ No cached data found for {args.date}")
            sys.exit(1)
    else:
        parser.print_help()
        sys.exit(1)

    if not cache_files:
        print("❌ No cache files to process")
        sys.exit(1)

    # Process files
    total_metrics = 0
    for cache_file in cache_files:
        try:
            metrics = repost_sleep_file(cache_file)
            total_metrics += metrics
        except Exception as e:
            print(f"   ❌ Error: {e}")

    print("\n" + "=" * 60)
    print(f"✅ Re-post complete!")
    print(f"   Files processed: {len(cache_files)}")
    print(f"   Metrics posted: {total_metrics}")
    print("=" * 60)

if __name__ == '__main__':
    main()
