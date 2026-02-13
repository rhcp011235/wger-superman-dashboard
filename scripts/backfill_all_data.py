#!/usr/bin/env python3
"""
Historical Data Backfill Script - Pull ALL Data from Withings & Sleep Number

This script pulls ALL historical data for any date range and syncs to WGER.
Use this to:
  - Backfill historical data (e.g., go back 1+ years)
  - Re-sync all data (full refresh)
  - Custom date range sync
  - View trends over longer periods

USAGE:
    # Backfill last 365 days
    ./backfill_all_data.py --days 365

    # Backfill specific date range
    ./backfill_all_data.py --start 2024-01-01 --end 2026-02-11

    # Backfill ALL available data (max range)
    ./backfill_all_data.py --all

    # Re-sync specific date (update existing)
    ./backfill_all_data.py --start 2026-01-15 --end 2026-01-15

WHAT IT SYNCS:
    - Withings: Weight, steps, distance, calories, body composition
    - Sleep Number: Sleep duration, SleepIQ score, heart rate, HRV, respiratory rate
    - WGER: Updates existing entries or creates new ones

IMPORTANT:
    - This does NOT sync MFP nutrition (requires screenshots)
    - Only syncs Withings + Sleep Number data
    - Safe to run multiple times (won't create duplicates)
"""

import os
import sys
import json
import time
import requests
import argparse
from pathlib import Path
from datetime import datetime, timedelta

# Import from daily_health_sync
sys.path.insert(0, str(Path(__file__).parent))

# Sleep Number imports
SLEEPNUMBER_SYNC = True
if SLEEPNUMBER_SYNC:
    try:
        from asyncsleepiq import AsyncSleepIQ
        import asyncio
        SLEEPNUMBER_AVAILABLE = True
    except ImportError:
        print("⚠️  Sleep Number sync disabled: asyncsleepiq not installed")
        SLEEPNUMBER_AVAILABLE = False
else:
    SLEEPNUMBER_AVAILABLE = False

# =============================================================================
# CONFIGURATION
# =============================================================================

# Debug Settings
DEBUG_SAVE_RAW_DATA = True  # Save raw Withings data to JSON file (recommended)
DEBUG_LOG_POSTS = True       # Log all WGER posts to debug file
RAW_DATA_DIR = Path(__file__).parent / "backfill_cache"
DEBUG_LOG_FILE = Path(__file__).parent / "backfill_debug.log"

# Withings OAuth
WITHINGS_CLIENT_ID = "68ef068bec7ad9efd797c54fe27283de22bb8a7d0d4b4fa542a011c37746b533"
WITHINGS_CLIENT_SECRET = "b23d6b02660a419388f618f67f37241299a21eb22109feff3fbbd8ee3b266cb5"
WITHINGS_TOKEN_FILE = str(Path(__file__).parent / "withings_tokens.json")

# Sleep Number
SLEEPNUMBER_EMAIL = "john.b.hale@gmail.com"
SLEEPNUMBER_PASSWORD = "vjz@kqw!WMF*bpr7ufa"

# WGER
WGER_BASE = 'https://weight.rhcp011235.com'
WGER_TOKEN = 'e4aa72c36288c2c60105bca3977178c8b1d09836'

# =============================================================================
# WITHINGS API (from daily_health_sync.py)
# =============================================================================

def load_withings_tokens():
    if not os.path.exists(WITHINGS_TOKEN_FILE):
        return None
    with open(WITHINGS_TOKEN_FILE, 'r') as f:
        return json.load(f)

def save_withings_tokens(tokens):
    with open(WITHINGS_TOKEN_FILE, 'w') as f:
        json.dump(tokens, f, indent=2)
    os.chmod(WITHINGS_TOKEN_FILE, 0o600)

def refresh_withings_token(refresh_token):
    url = "https://wbsapi.withings.net/v2/oauth2"
    data = {
        "action": "requesttoken",
        "grant_type": "refresh_token",
        "client_id": WITHINGS_CLIENT_ID,
        "client_secret": WITHINGS_CLIENT_SECRET,
        "refresh_token": refresh_token,
    }
    r = requests.post(url, data=data, timeout=30)
    r.raise_for_status()
    j = r.json()
    if j.get("status") != 0:
        return None
    body = j["body"]
    body["obtained_at"] = int(time.time())
    return body

def get_withings_access_token():
    tokens = load_withings_tokens()
    if not tokens:
        print("❌ No Withings tokens found!")
        print("   Run: ./daily_health_sync.py manual")
        print("   This will complete OAuth setup.")
        sys.exit(1)

    obtained = tokens.get("obtained_at", 0)
    expires_in = int(tokens.get("expires_in", 0))

    if int(time.time()) > obtained + max(expires_in - 60, 0):
        print("🔄 Refreshing Withings token...")
        refreshed = refresh_withings_token(tokens["refresh_token"])
        if refreshed:
            tokens = refreshed
            save_withings_tokens(tokens)
            return tokens["access_token"]
        else:
            print("❌ Token refresh failed")
            sys.exit(1)

    return tokens["access_token"]

def withings_get_activity(access_token, start_ymd, end_ymd):
    url = "https://wbsapi.withings.net/v2/measure"
    data = {
        "action": "getactivity",
        "access_token": access_token,
        "startdateymd": start_ymd,
        "enddateymd": end_ymd,
    }
    r = requests.post(url, data=data, timeout=60)
    r.raise_for_status()
    j = r.json()
    if j.get("status") != 0:
        raise Exception(f"Withings getactivity failed: {j}")
    return j["body"]

def withings_get_weight(access_token, start_ts, end_ts):
    url = "https://wbsapi.withings.net/measure"
    data = {
        "action": "getmeas",
        "access_token": access_token,
        "startdate": str(start_ts),
        "enddate": str(end_ts),
        "category": "1",
    }
    r = requests.post(url, data=data, timeout=60)
    r.raise_for_status()
    j = r.json()
    if j.get("status") != 0:
        raise Exception(f"Withings getmeas failed: {j}")
    return j["body"]

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

def debug_log(message):
    """Log to debug file if enabled"""
    if DEBUG_LOG_POSTS:
        with open(DEBUG_LOG_FILE, 'a') as f:
            timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
            f.write(f"[{timestamp}] {message}\n")

def wger_post_measurement(date, category_id, value, notes=''):
    headers = wger_headers()

    debug_log(f"POST MEASUREMENT: date={date}, cat={category_id}, value={value}, notes={notes}")

    try:
        r = requests.get(
            f"{WGER_BASE}/api/v2/measurement/",
            headers=headers,
            params={'category': category_id, 'date': date},
            timeout=30  # Increased timeout for backfill
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

    # Retry logic for timeouts
    max_retries = 3
    for attempt in range(max_retries):
        try:
            if existing_id:
                r = requests.put(
                    f"{WGER_BASE}/api/v2/measurement/{existing_id}/",
                    headers=headers,
                    json=payload,
                    timeout=30  # Increased timeout
                )
                action = "Updated"
            else:
                r = requests.post(
                    f"{WGER_BASE}/api/v2/measurement/",
                    headers=headers,
                    json=payload,
                    timeout=30  # Increased timeout
                )
                action = "Posted"

            if r.status_code in [200, 201]:
                debug_log(f"  ✅ SUCCESS: {action}")
                return True, action
            else:
                debug_log(f"  ❌ FAILED: HTTP {r.status_code}")
                return False, f"Failed ({r.status_code})"

        except requests.exceptions.Timeout:
            debug_log(f"  ⏱️  TIMEOUT (attempt {attempt + 1})")
            if attempt < max_retries - 1:
                print(f"      ⏱️  Timeout, retrying ({attempt + 1}/{max_retries})...")
                time.sleep(2)
                continue
            else:
                debug_log(f"  ❌ TIMEOUT after {max_retries} retries")
                return False, "Timeout after 3 retries"
        except Exception as e:
            error_msg = str(e)[:100]
            debug_log(f"  ❌ EXCEPTION: {error_msg}")
            return False, f"Error: {str(e)[:50]}"

    return False, "Unknown error"

def wger_post_weight(date, weight_lb):
    headers = wger_headers()

    try:
        r = requests.get(
            f"{WGER_BASE}/api/v2/weightentry/",
            headers=headers,
            params={'date': date},
            timeout=60  # Increased for backfill
        )
        existing_id = None
        if r.status_code == 200:
            results = r.json().get('results', [])
            if results:
                existing_id = results[0]['id']
    except:
        existing_id = None

    payload = {"date": date, "weight": round(weight_lb, 2)}

    max_retries = 3
    for attempt in range(max_retries):
        try:
            if existing_id:
                r = requests.put(
                    f"{WGER_BASE}/api/v2/weightentry/{existing_id}/",
                    headers=headers,
                    json=payload,
                    timeout=60  # Increased timeout
                )
                action = "Updated"
            else:
                r = requests.post(
                    f"{WGER_BASE}/api/v2/weightentry/",
                    headers=headers,
                    json=payload,
                    timeout=60  # Increased timeout
                )
                action = "Posted"

            if r.status_code in (200, 201):
                return True, action
            return False, f"Failed ({r.status_code})"

        except requests.exceptions.Timeout:
            if attempt < max_retries - 1:
                print(f"      ⏱️  Timeout, retrying ({attempt + 1}/{max_retries})...")
                time.sleep(2)
                continue
            return False, "Timeout"
        except Exception as e:
            return False, f"Error: {str(e)[:50]}"

    return False, "Unknown error"

# =============================================================================
# SLEEP NUMBER API
# =============================================================================

async def sleepnumber_get_sleep_data_for_date(email, password, target_date):
    """Fetch Sleep Number sleep data for a specific date"""
    try:
        api = AsyncSleepIQ()
        await api.login(email, password)

        # Initialize beds (REQUIRED!)
        await api.init_beds()

        beds = api.beds
        if not beds:
            return None

        # Get first bed (dict of beds)
        bed = list(beds.values())[0] if beds else None
        if not bed:
            return None

        sleepers = bed.sleepers
        if not sleepers:
            return None

        sleeper = sleepers[0]

        # TODO: The asyncsleepiq library doesn't support fetching historical data by date
        # This is a limitation of the library - it only provides "last night's" sleep data
        # For now, we'll return None for historical dates
        # You may need to enhance this with direct API calls if historical data is needed

        sleep_data = {
            'duration_hours': None,
            'sleep_score': None,
            'avg_heart_rate': None,
            'avg_hrv': None,
            'avg_resp_rate': None,
        }

        if hasattr(sleeper, 'sleep_number'):
            sleep_data['sleep_score'] = sleeper.sleep_number

        # Note: sleep_data is only available after a complete sleep session
        if hasattr(sleeper, 'sleep_data') and sleeper.sleep_data:
            data = sleeper.sleep_data
            if data and isinstance(data, dict):
                if 'totalSleepSessionTime' in data:
                    sleep_data['duration_hours'] = round(data['totalSleepSessionTime'] / 3600, 2)
                if 'averageHeartRate' in data:
                    sleep_data['avg_heart_rate'] = round(data['averageHeartRate'], 1)
                if 'averageHeartRateVariability' in data:
                    sleep_data['avg_hrv'] = round(data['averageHeartRateVariability'], 1)
                if 'averageRespirationRate' in data:
                    sleep_data['avg_resp_rate'] = round(data['averageRespirationRate'], 1)

        # Clean up
        try:
            if hasattr(api, 'stop_websocket'):
                await api.stop_websocket()
        except:
            pass

        return sleep_data

    except Exception as e:
        return None

# =============================================================================
# MAIN BACKFILL LOGIC
# =============================================================================

def backfill_withings(start_date, end_date):
    """Backfill Withings data for date range"""
    print("\n" + "=" * 70)
    print("📊 BACKFILLING WITHINGS DATA")
    print("=" * 70)
    print(f"Date range: {start_date} to {end_date}")

    # Create cache directory
    if DEBUG_SAVE_RAW_DATA:
        RAW_DATA_DIR.mkdir(exist_ok=True)

    raw_data_file = RAW_DATA_DIR / f"withings_{start_date}_to_{end_date}.json"

    # Step 1: Fetch from Withings API (or load from cache)
    if raw_data_file.exists():
        print(f"\n📂 Found cached data: {raw_data_file.name}")
        print(f"   Using cached data (prevents API rate limiting)")
        use_cache = True

        if use_cache:
            print(f"   Loading from cache...")
            with open(raw_data_file, 'r') as f:
                cached_data = json.load(f)
            activity = cached_data.get('activity', {})
            weight_data = cached_data.get('weight', {})
            print(f"   ✅ Loaded from cache")
        else:
            activity = None
            weight_data = None
    else:
        activity = None
        weight_data = None

    if not activity or not weight_data:
        print(f"\n🌐 Fetching from Withings API...")
        print(f"   ⚠️  This will make API calls - data will be cached for future use")

        access_token = get_withings_access_token()
        start_ts = int(datetime.strptime(start_date, '%Y-%m-%d').timestamp())
        end_ts = int(datetime.strptime(end_date, '%Y-%m-%d').timestamp()) + 86399

        # Fetch activity data
        print(f"\n🚶 Fetching activity data from Withings...")
        activity = withings_get_activity(access_token, start_date, end_date)
        print(f"   ✅ Found {len(activity.get('activities', []))} days of activity")

        # Fetch weight data
        print(f"\n⚖️  Fetching weight data from Withings...")
        weight_data = withings_get_weight(access_token, start_ts, end_ts)
        print(f"   ✅ Found {len(weight_data.get('measuregrps', []))} weight measurements")

        # Save to cache
        if DEBUG_SAVE_RAW_DATA:
            cache_data = {
                'fetch_date': datetime.now().isoformat(),
                'date_range': {'start': start_date, 'end': end_date},
                'activity': activity,
                'weight': weight_data
            }
            with open(raw_data_file, 'w') as f:
                json.dump(cache_data, f, indent=2)
            print(f"\n💾 Cached raw data to: {raw_data_file}")

    # Step 2: Post to WGER from cached data
    print(f"\n" + "=" * 70)
    print(f"📤 POSTING TO WGER")
    print(f"=" * 70)

    # Get or create categories
    print(f"\n🏷️  Creating/fetching WGER categories...")
    cat_steps = wger_get_or_create_category("Steps", "ksteps")
    cat_distance = wger_get_or_create_category("Distance", "km")
    cat_calories = wger_get_or_create_category("Calories", "kcal")
    cat_bodyfat = wger_get_or_create_category("Body Fat", "%")
    cat_muscle = wger_get_or_create_category("Muscle Mass", "kg")
    cat_bone = wger_get_or_create_category("Bone Mass", "kg")
    cat_hydration = wger_get_or_create_category("Hydration", "%")
    print(f"   ✅ Categories ready")

    # Post activity data
    print(f"\n🚶 Posting activity data...")

    activity_count = 0
    for day in activity.get("activities", []):
        date = day.get("date")
        if not date:
            continue

        if "steps" in day and day["steps"] is not None and day["steps"] > 0:
            steps_k = round(float(day["steps"]) / 1000, 2)
            success, action = wger_post_measurement(date, cat_steps, steps_k, "Withings Backfill")
            if success:
                activity_count += 1

        if "distance" in day and day["distance"] is not None and day["distance"] > 0:
            distance_km = round(float(day["distance"]) / 1000, 2)
            success, action = wger_post_measurement(date, cat_distance, distance_km, "Withings Backfill")
            if success:
                activity_count += 1

        if "calories" in day and day["calories"] is not None and day["calories"] > 0:
            calories = round(float(day["calories"]), 2)
            success, action = wger_post_measurement(date, cat_calories, calories, "Withings Backfill")
            if success:
                activity_count += 1

    print(f"   ✅ Activity data: {activity_count} entries posted to WGER")

    # Post weight data (already fetched and cached)
    print(f"\n⚖️  Posting weight data...")
    weight_count = 0
    bodycomp_count = 0

    for grp in weight_data.get("measuregrps", []):
        ts = int(grp.get("date", 0))
        if not ts:
            continue

        date = time.strftime("%Y-%m-%d", time.localtime(ts))
        weight_posted = False
        measures = grp.get("measures", [])

        for m in measures:
            mtype = m.get("type")
            raw = m.get("value")
            expo = m.get("unit")

            if raw is None or expo is None:
                continue

            value = float(raw) * (10 ** int(expo))

            # Type 1: Weight
            if mtype == 1 and not weight_posted:
                weight_lb = value * 2.20462
                if 30 <= weight_lb <= 350:
                    success, action = wger_post_weight(date, weight_lb)
                    if success:
                        weight_count += 1
                        weight_posted = True

            # Type 6: Body Fat %
            elif mtype == 6:
                success, action = wger_post_measurement(date, cat_bodyfat, round(value, 2), "Withings Backfill")
                if success:
                    bodycomp_count += 1

            # Type 76: Muscle Mass
            elif mtype == 76:
                success, action = wger_post_measurement(date, cat_muscle, round(value, 2), "Withings Backfill")
                if success:
                    bodycomp_count += 1

            # Type 77: Hydration
            elif mtype == 77:
                if weight_posted and value > 0:
                    hydration_pct = (value / (value / 2.20462)) * 100
                    success, action = wger_post_measurement(date, cat_hydration, round(hydration_pct, 1), "Withings Backfill")
                    if success:
                        bodycomp_count += 1

            # Type 88: Bone Mass
            elif mtype == 88:
                success, action = wger_post_measurement(date, cat_bone, round(value, 2), "Withings Backfill")
                if success:
                    bodycomp_count += 1

    print(f"✅ Weight data: {weight_count} entries synced")
    print(f"✅ Body composition: {bodycomp_count} entries synced")
    print(f"\n🎉 Withings backfill complete!")

def backfill_sleepnumber(start_date, end_date):
    """Backfill Sleep Number data for date range"""
    if not SLEEPNUMBER_AVAILABLE:
        print("\n⚠️  Sleep Number sync disabled (asyncsleepiq not installed)")
        return

    if not SLEEPNUMBER_EMAIL or SLEEPNUMBER_EMAIL == "your_email@example.com":
        print("\n⚠️  Sleep Number credentials not configured (skipping)")
        return

    print("\n" + "=" * 70)
    print("😴 BACKFILLING SLEEP NUMBER DATA")
    print("=" * 70)
    print(f"Date range: {start_date} to {end_date}")
    print("\n⚠️  NOTE: Sleep Number API limitations:")
    print("   The asyncsleepiq library only provides 'last night' data.")
    print("   Historical backfill may not be supported by the API.")
    print("   For best results, run daily_health_sync.py daily going forward.\n")

    # Get or create categories
    cat_duration = wger_get_or_create_category("Sleep Duration", "hours")
    cat_score = wger_get_or_create_category("Sleep Score", "score")
    cat_hr = wger_get_or_create_category("Sleep Heart Rate", "bpm")
    cat_hrv = wger_get_or_create_category("Sleep HRV", "ms")
    cat_rr = wger_get_or_create_category("Sleep Respiratory Rate", "brpm")

    # For now, only fetch "yesterday's" sleep data
    # TODO: Enhance with direct API calls if historical data endpoint exists
    yesterday = (datetime.now() - timedelta(days=1)).strftime('%Y-%m-%d')

    try:
        sleep_data = asyncio.run(sleepnumber_get_sleep_data_for_date(
            SLEEPNUMBER_EMAIL, SLEEPNUMBER_PASSWORD, yesterday
        ))

        if sleep_data:
            success_count = 0
            if sleep_data['duration_hours']:
                success, _ = wger_post_measurement(yesterday, cat_duration, sleep_data['duration_hours'], "Sleep Number Backfill")
                if success: success_count += 1
            if sleep_data['sleep_score']:
                success, _ = wger_post_measurement(yesterday, cat_score, sleep_data['sleep_score'], "Sleep Number Backfill")
                if success: success_count += 1
            if sleep_data['avg_heart_rate']:
                success, _ = wger_post_measurement(yesterday, cat_hr, sleep_data['avg_heart_rate'], "Sleep Number Backfill")
                if success: success_count += 1
            if sleep_data['avg_hrv']:
                success, _ = wger_post_measurement(yesterday, cat_hrv, sleep_data['avg_hrv'], "Sleep Number Backfill")
                if success: success_count += 1
            if sleep_data['avg_resp_rate']:
                success, _ = wger_post_measurement(yesterday, cat_rr, sleep_data['avg_resp_rate'], "Sleep Number Backfill")
                if success: success_count += 1

            print(f"✅ Sleep data for {yesterday}: {success_count} entries synced")
        else:
            print(f"⚠️  No sleep data available")

    except Exception as e:
        print(f"❌ Sleep Number backfill failed: {e}")

    print(f"\n🎉 Sleep Number backfill complete!")

def main():
    parser = argparse.ArgumentParser(
        description='Backfill ALL historical health data from Withings and Sleep Number',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  # Last 365 days
  ./backfill_all_data.py --days 365

  # Specific range
  ./backfill_all_data.py --start 2024-01-01 --end 2026-02-11

  # ALL available data (max supported by API)
  ./backfill_all_data.py --all

  # Single date re-sync
  ./backfill_all_data.py --start 2026-01-15 --end 2026-01-15
        """
    )

    parser.add_argument('--days', type=int, help='Number of days back to sync')
    parser.add_argument('--start', type=str, help='Start date (YYYY-MM-DD)')
    parser.add_argument('--end', type=str, help='End date (YYYY-MM-DD)')
    parser.add_argument('--all', action='store_true', help='Sync ALL available data (max range)')
    parser.add_argument('--withings-only', action='store_true', help='Only sync Withings data')
    parser.add_argument('--sleep-only', action='store_true', help='Only sync Sleep Number data')

    args = parser.parse_args()

    # Calculate date range
    if args.all:
        # Max range supported by Withings API (typically 1 year)
        end_date = datetime.now().strftime('%Y-%m-%d')
        start_date = (datetime.now() - timedelta(days=365)).strftime('%Y-%m-%d')
        print("📅 Syncing maximum range: 365 days")
    elif args.days:
        end_date = datetime.now().strftime('%Y-%m-%d')
        start_date = (datetime.now() - timedelta(days=args.days)).strftime('%Y-%m-%d')
        print(f"📅 Syncing last {args.days} days")
    elif args.start and args.end:
        start_date = args.start
        end_date = args.end
        print(f"📅 Syncing custom range: {start_date} to {end_date}")
    else:
        parser.print_help()
        sys.exit(1)

    print(f"\n🚀 Starting historical backfill...")
    print(f"   Start: {start_date}")
    print(f"   End:   {end_date}")

    # Run backfill
    if not args.sleep_only:
        backfill_withings(start_date, end_date)

    if not args.withings_only:
        backfill_sleepnumber(start_date, end_date)

    print("\n" + "=" * 70)
    print("✅ BACKFILL COMPLETE!")
    print("=" * 70)
    print(f"\nView your data at: {WGER_BASE}/weight_enhanced.php")
    print(f"Check trends in ChatGPT with JSON export!")

if __name__ == '__main__':
    main()
