#!/usr/bin/env python3
"""
Daily Health Sync - Complete Unified Script
Syncs Withings (steps, exercise, weight, body composition) + MFP (nutrition) + Sleep Number to WGER

USAGE:
    ./daily_health_sync.py screenshot.png    # OCR mode (recommended)
    ./daily_health_sync.py manual            # Manual entry

WHAT IT SYNCS:
    From Withings (auto):
        - Weight (converted to lbs for WGER)
        - Steps (converted to ksteps for WGER 4-digit limit)
        - Distance (in km)
        - Active calories (kcal)
        - Body composition: fat %, muscle mass, bone mass, hydration

    From Sleep Number (auto, if enabled):
        - Sleep duration (hours)
        - SleepIQ score (0-100)
        - Average heart rate (bpm)
        - Heart rate variability (HRV)
        - Respiratory rate (breaths/min)

    From MFP Screenshot (OCR):
        - Food calories (kcal)
        - Exercise calories (kcal)
        - Macros: protein, carbs, fat (if visible in screenshot)

    Optional Manual Tracking:
        - Digestion log (for scale correlation)

UNITS (locked for consistency):
    - Distance: ALWAYS km (never miles)
    - Weight: ALWAYS lbs (never kg)
    - Calories: ALWAYS kcal (never kJ)
    - Steps: ALWAYS ksteps in WGER (displayed as steps in API)

OUTPUT:
    All data posted to WGER and available at:
    https://your-wger-instance.com/weight.php?format=json
"""

import os
try:
    from dotenv import load_dotenv
    load_dotenv()
except ImportError:
    pass  # dotenv optional; set env vars manually or hardcode below
import sys
import re
import json
import time
import requests
import webbrowser
from pathlib import Path
from datetime import datetime, timedelta
from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import urlparse, parse_qs

# OCR imports
try:
    from PIL import Image
    import pytesseract
except ImportError:
    print("⚠️  OCR not available. Install: pip3 install pillow pytesseract")
    print("   You can still run manual mode without OCR")

# Sleep Number imports
SLEEPNUMBER_SYNC = True  # Set to False to disable Sleep Number sync
if SLEEPNUMBER_SYNC:
    try:
        from asyncsleepiq import AsyncSleepIQ
        import asyncio
        SLEEPNUMBER_AVAILABLE = True
    except ImportError:
        print("⚠️  Sleep Number sync disabled: asyncsleepiq not installed")
        print("   Install with: pip3 install asyncsleepiq")
        SLEEPNUMBER_AVAILABLE = False
else:
    SLEEPNUMBER_AVAILABLE = False

# =============================================================================
# CONFIGURATION (Hardcoded - Private Version)
# =============================================================================

# Feature Flags
SLEEPNUMBER_SYNC_ENABLED = SLEEPNUMBER_SYNC and SLEEPNUMBER_AVAILABLE

# Withings OAuth
WITHINGS_CLIENT_ID     = os.environ.get("WITHINGS_CLIENT_ID",     "your_withings_client_id_here")
WITHINGS_CLIENT_SECRET = os.environ.get("WITHINGS_CLIENT_SECRET", "your_withings_client_secret_here")
WITHINGS_TOKEN_FILE = str(Path(__file__).parent / "withings_tokens.json")

# Sleep Number Credentials
SLEEPNUMBER_EMAIL = "john.b.hale@gmail.com"
SLEEPNUMBER_PASSWORD = "vjz@kqw!WMF*bpr7ufa"
SLEEPNUMBER_TOKEN_FILE = str(Path(__file__).parent / "sleepnumber_session.json")

# WGER
WGER_BASE  = os.environ.get('WGER_BASE_URL',  'https://your-wger-instance.com')
WGER_TOKEN = os.environ.get('WGER_TOKEN',   'your_wger_api_token_here')

# WGER Measurement Categories (created once)
CATEGORY_CALORIES = None    # Daily Calories (kcal) - food
CATEGORY_PROTEIN = None     # Daily Protein (g)
CATEGORY_CARBS = None       # Daily Carbs (g)
CATEGORY_FAT = None         # Daily Fat (g)
CATEGORY_EXERCISE = None    # MFP Exercise Calories (kcal) - from MFP
CATEGORY_SODIUM = None      # Daily Sodium (mg)
CATEGORY_STEPS = None       # Steps from Withings
CATEGORY_DISTANCE = None    # Distance from Withings
CATEGORY_WITHINGS_CAL = None  # Withings exercise calories
CATEGORY_SLEEP_DURATION = None  # Sleep duration (hours)
CATEGORY_SLEEP_SCORE = None     # SleepIQ score (0-100)
CATEGORY_SLEEP_HR = None        # Average heart rate (bpm)
CATEGORY_SLEEP_HRV = None       # Heart rate variability
CATEGORY_SLEEP_RR = None        # Respiratory rate (breaths/min)
CATEGORY_SLEEP_RESTFUL = None   # Restful sleep (hours)
CATEGORY_SLEEP_RESTLESS = None  # Restless sleep (hours)
CATEGORY_SLEEP_OUT_OF_BED = None  # Out of bed time (hours)

# Daily constants config
SCRIPT_DIR = Path(__file__).parent
DAILY_CONSTANTS_FILE = SCRIPT_DIR / 'daily_constants.json'

# Sleep data cache directory
SLEEP_CACHE_DIR = SCRIPT_DIR / 'sleep_cache'
SLEEP_CACHE_DIR.mkdir(exist_ok=True)

# Sync window (days back for Withings) - overridden by --backfill=N CLI arg
DAYS_BACK = 1  # Daily sync window (1 = yesterday + today only)

# Specific date to sync - overridden by --date=YYYY-MM-DD CLI arg (None = use today/yesterday)
SYNC_DATE = None

# =============================================================================
# WITHINGS OAUTH & API
# =============================================================================

def load_withings_tokens():
    """Load Withings OAuth tokens from file"""
    if not os.path.exists(WITHINGS_TOKEN_FILE):
        return None
    with open(WITHINGS_TOKEN_FILE, 'r') as f:
        return json.load(f)

def save_withings_tokens(tokens):
    """Save Withings OAuth tokens to file"""
    with open(WITHINGS_TOKEN_FILE, 'w') as f:
        json.dump(tokens, f, indent=2)
    os.chmod(WITHINGS_TOKEN_FILE, 0o600)

def oauth_authorize_withings():
    """Start OAuth flow with local callback server"""
    redirect_uri = "http://localhost:8080/callback"
    port = 8080
    path = "/callback"
    code_holder = {"code": None, "error": None}

    class Handler(BaseHTTPRequestHandler):
        def do_GET(self):
            if urlparse(self.path).path != path:
                self.send_response(404)
                self.end_headers()
                self.wfile.write(b"Not found")
                return
            qs = parse_qs(urlparse(self.path).query)
            if "error" in qs:
                code_holder["error"] = qs["error"][0]
            if "code" in qs:
                code_holder["code"] = qs["code"][0]

            self.send_response(200)
            self.send_header("Content-Type", "text/html; charset=utf-8")
            self.end_headers()
            self.wfile.write(b"<h2>OK! You can close this tab.</h2>")

        def log_message(self, format, *args):
            return  # quiet

    server = HTTPServer(("127.0.0.1", port), Handler)

    params = {
        "response_type": "code",
        "client_id": WITHINGS_CLIENT_ID,
        "redirect_uri": redirect_uri,
        "scope": "user.metrics,user.activity",
        "state": "xyz",
    }
    url = requests.Request("GET", "https://account.withings.com/oauth2_user/authorize2", params=params).prepare().url

    print("\n🔐 Opening browser for Withings authorization...")
    print(f"   If browser doesn't open, go to: {url}\n")
    webbrowser.open(url)

    print(f"⏳ Waiting for callback on {redirect_uri}...")
    while code_holder["code"] is None and code_holder["error"] is None:
        server.handle_request()

    if code_holder["error"]:
        raise Exception(f"OAuth error: {code_holder['error']}")

    # Exchange code for tokens
    data = {
        "action": "requesttoken",
        "grant_type": "authorization_code",
        "client_id": WITHINGS_CLIENT_ID,
        "client_secret": WITHINGS_CLIENT_SECRET,
        "code": code_holder["code"],
        "redirect_uri": redirect_uri,
    }
    r = requests.post("https://wbsapi.withings.net/v2/oauth2", data=data, timeout=30)
    r.raise_for_status()
    j = r.json()
    if j.get("status") != 0:
        raise Exception(f"Token exchange failed: {j}")

    body = j["body"]
    body["obtained_at"] = int(time.time())
    return body

def refresh_withings_token(refresh_token):
    """Refresh Withings access token"""
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
        # Refresh failed - need to re-authenticate
        return None

    body = j["body"]
    body["obtained_at"] = int(time.time())
    return body

def get_withings_access_token():
    """Get valid Withings access token (refresh or re-auth if needed)"""
    tokens = load_withings_tokens()

    # Check if we need to refresh
    if tokens:
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
                print("⚠️  Refresh failed, need to re-authenticate")
                tokens = None

    # No tokens or refresh failed - do OAuth
    if not tokens:
        print("\n🔐 No valid tokens - starting OAuth authorization...")
        tokens = oauth_authorize_withings()
        save_withings_tokens(tokens)
        print("✅ Authorization successful!\n")

    return tokens["access_token"]

def withings_get_activity(access_token, start_ymd, end_ymd):
    """Fetch Withings daily activity (steps, distance, calories)"""
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

def withings_get_workouts(access_token, start_ymd, end_ymd):
    """Fetch Withings workout sessions (walking, running, etc.)"""
    url = "https://wbsapi.withings.net/v2/measure"
    data = {
        "action": "getworkouts",
        "access_token": access_token,
        "startdateymd": start_ymd,
        "enddateymd": end_ymd,
        # No data_fields filter — fetch all fields including manual_distance
    }
    r = requests.post(url, data=data, timeout=60)
    r.raise_for_status()
    j = r.json()
    if j.get("status") != 0:
        raise Exception(f"Withings getworkouts failed: {j}")
    return j["body"]

def withings_get_weight(access_token, start_ts, end_ts):
    """Fetch Withings weight measurements"""
    url = "https://wbsapi.withings.net/measure"
    data = {
        "action": "getmeas",
        "access_token": access_token,
        "startdate": str(start_ts),
        "enddate": str(end_ts),
        "category": "1",  # Real measurements
    }
    r = requests.post(url, data=data, timeout=60)
    r.raise_for_status()
    j = r.json()
    if j.get("status") != 0:
        raise Exception(f"Withings getmeas failed: {j}")
    return j["body"]

# =============================================================================
# SLEEP NUMBER API
# =============================================================================

async def sleepnumber_get_sleep_data(email, password):
    """Fetch Sleep Number sleep data from last night"""
    api = None
    try:
        from datetime import timedelta

        api = AsyncSleepIQ()

        # Login
        await api.login(email, password)

        # Initialize beds (REQUIRED!)
        await api.init_beds()

        # Get beds
        beds = api.beds
        if not beds:
            print("   ⚠️  No Sleep Number beds found")
            return None

        # Get first bed (dict of beds)
        bed = list(beds.values())[0] if beds else None
        if not bed:
            print("   ⚠️  No beds available")
            return None

        # Get sleep data for last night
        sleepers = bed.sleepers
        if not sleepers:
            print("   ⚠️  No sleepers found")
            return None

        sleeper = sleepers[0]  # Primary sleeper

        # Get sleep data
        sleep_data = {
            'duration_hours': None,
            'sleep_score': None,
            'avg_heart_rate': None,
            'avg_hrv': None,
            'avg_resp_rate': None,
            'restful_hours': None,
            'restless_hours': None,
            'out_of_bed_hours': None,
            'message': None,
            'tip': None,
            'sessions': [],
            'tags': [],
        }

        # FETCH SLEEP DATA FROM API using new library method
        # Note: fetch_sleep_data() gets the most recent sleep session (last night)
        try:
            # Use the new fetch_sleep_data() method from updated asyncsleepiq library
            # This method does NOT take a date parameter - it gets the most recent sleep data
            await sleeper.fetch_sleep_data()

            # Extract data from sleeper.sleep_data
            # Duration (convert seconds to hours; must be > 1 hour to be valid)
            if sleeper.sleep_data.duration and sleeper.sleep_data.duration > 3600:
                sleep_data["duration_hours"] = round(sleeper.sleep_data.duration / 3600, 2)

            # Sleep score (aggregate average)
            if sleeper.sleep_data.sleep_score:
                sleep_data["sleep_score"] = int(sleeper.sleep_data.sleep_score)

            # Heart rate (aggregate average)
            if sleeper.sleep_data.heart_rate:
                sleep_data["avg_heart_rate"] = int(sleeper.sleep_data.heart_rate)

            # HRV (from most recent/longest session)
            if sleeper.sleep_data.hrv:
                sleep_data["avg_hrv"] = int(sleeper.sleep_data.hrv)

            # Respiratory rate (aggregate average)
            if sleeper.sleep_data.respiratory_rate:
                sleep_data["avg_resp_rate"] = int(sleeper.sleep_data.respiratory_rate)

            # Note: restful/restless/out_of_bed data not available in new library method
            # These would require additional API calls to get session details

        except Exception as e:
            print(f"   ⚠️  Error fetching sleep data: {e}")
            import traceback
            traceback.print_exc()
            # Return empty data rather than None

        return sleep_data

    except Exception as e:
        print(f"   ⚠️  Sleep Number API error: {e}")
        return None

    finally:
        # Always close the aiohttp session to avoid "Unclosed client session" warnings
        try:
            await api.close_session()
        except:
            pass

def save_sleep_to_cache(date, sleep_data, sleeper_name="John"):
    """Save sleep data to local cache"""
    cache_file = SLEEP_CACHE_DIR / f"{date}.json"

    cache_entry = {
        'date': date,
        'fetched_at': datetime.now().isoformat(),
        'sleeper': sleeper_name,
        'data': sleep_data
    }

    with open(cache_file, 'w') as f:
        json.dump(cache_entry, f, indent=2)

    print(f"   💾 Cached to: {cache_file.name}")
    return cache_file

def sync_sleepnumber():
    """Sync Sleep Number data with local caching"""
    if not SLEEPNUMBER_SYNC_ENABLED:
        return True  # Skip silently if disabled

    print("\n" + "=" * 60)
    print("😴 SYNCING SLEEP NUMBER DATA")
    print("=" * 60)

    if not SLEEPNUMBER_EMAIL or not SLEEPNUMBER_PASSWORD or SLEEPNUMBER_EMAIL == "your_email@example.com":
        print("⚠️  Sleep Number credentials not configured (skipping)")
        return True

    try:
        # Sleep data is stored for the night before the given date
        # If --date=YYYY-MM-DD is set, use that date; otherwise default to yesterday
        from datetime import timedelta
        if SYNC_DATE:
            yesterday = SYNC_DATE
        else:
            yesterday = (datetime.now() - timedelta(days=1)).strftime('%Y-%m-%d')

        # ALWAYS fetch fresh data from Sleep Number API (never use cache for live syncs)
        # Cache is only used during backfill operations
        print(f"🌐 Fetching fresh sleep data from Sleep Number...")
        sleep_data = asyncio.run(sleepnumber_get_sleep_data(SLEEPNUMBER_EMAIL, SLEEPNUMBER_PASSWORD))

        if not sleep_data:
            print("⚠️  No sleep data available (might not have slept yet)")
            return True

        # Get or create categories
        global CATEGORY_SLEEP_DURATION, CATEGORY_SLEEP_SCORE, CATEGORY_SLEEP_HR
        global CATEGORY_SLEEP_HRV, CATEGORY_SLEEP_RR, CATEGORY_SLEEP_RESTFUL
        global CATEGORY_SLEEP_RESTLESS, CATEGORY_SLEEP_OUT_OF_BED

        CATEGORY_SLEEP_DURATION = wger_get_or_create_category("Sleep Duration", "hours")
        CATEGORY_SLEEP_SCORE = wger_get_or_create_category("Sleep Score", "score")
        CATEGORY_SLEEP_HR = wger_get_or_create_category("Sleep Heart Rate", "bpm")
        CATEGORY_SLEEP_HRV = wger_get_or_create_category("Sleep HRV", "ms")
        CATEGORY_SLEEP_RR = wger_get_or_create_category("Sleep Respiratory Rate", "brpm")
        CATEGORY_SLEEP_RESTFUL = wger_get_or_create_category("Sleep Restful", "hours")
        CATEGORY_SLEEP_RESTLESS = wger_get_or_create_category("Sleep Restless", "hours")
        CATEGORY_SLEEP_OUT_OF_BED = wger_get_or_create_category("Sleep Out of Bed", "hours")

        # Post to WGER from cached data
        print(f"\n📤 Posting to WGER...")
        success_count = 0

        if sleep_data.get('duration_hours'):
            success, action = wger_post_measurement(yesterday, CATEGORY_SLEEP_DURATION,
                                                   sleep_data['duration_hours'], "Sleep Number")
            if success:
                print(f"   ✅ Sleep duration: {sleep_data['duration_hours']} hours ({action})")
                success_count += 1

        if sleep_data.get('sleep_score'):
            success, action = wger_post_measurement(yesterday, CATEGORY_SLEEP_SCORE,
                                                   sleep_data['sleep_score'], "Sleep Number")
            if success:
                print(f"   ✅ SleepIQ score: {sleep_data['sleep_score']} ({action})")
                success_count += 1

        if sleep_data.get('avg_heart_rate'):
            success, action = wger_post_measurement(yesterday, CATEGORY_SLEEP_HR,
                                                   sleep_data['avg_heart_rate'], "Sleep Number")
            if success:
                print(f"   ✅ Avg heart rate: {sleep_data['avg_heart_rate']} bpm ({action})")
                success_count += 1

        if sleep_data.get('avg_hrv'):
            success, action = wger_post_measurement(yesterday, CATEGORY_SLEEP_HRV,
                                                   sleep_data['avg_hrv'], "Sleep Number")
            if success:
                print(f"   ✅ Avg HRV: {sleep_data['avg_hrv']} ms ({action})")
                success_count += 1

        if sleep_data.get('avg_resp_rate'):
            success, action = wger_post_measurement(yesterday, CATEGORY_SLEEP_RR,
                                                   sleep_data['avg_resp_rate'], "Sleep Number")
            if success:
                print(f"   ✅ Avg respiratory rate: {sleep_data['avg_resp_rate']} brpm ({action})")
                success_count += 1

        if sleep_data.get('restful_hours'):
            success, action = wger_post_measurement(yesterday, CATEGORY_SLEEP_RESTFUL,
                                                   sleep_data['restful_hours'], "Sleep Number")
            if success:
                print(f"   ✅ Restful sleep: {sleep_data['restful_hours']} hours ({action})")
                success_count += 1

        if sleep_data.get('restless_hours'):
            success, action = wger_post_measurement(yesterday, CATEGORY_SLEEP_RESTLESS,
                                                   sleep_data['restless_hours'], "Sleep Number")
            if success:
                print(f"   ✅ Restless sleep: {sleep_data['restless_hours']} hours ({action})")
                success_count += 1

        if sleep_data.get('out_of_bed_hours'):
            success, action = wger_post_measurement(yesterday, CATEGORY_SLEEP_OUT_OF_BED,
                                                   sleep_data['out_of_bed_hours'], "Sleep Number")
            if success:
                print(f"   ✅ Out of bed: {sleep_data['out_of_bed_hours']} hours ({action})")
                success_count += 1

        print(f"\n✅ Sleep Number sync complete: {success_count} metrics")
        return True

    except Exception as e:
        print(f"\n⚠️  Sleep Number sync failed (non-critical): {e}")
        import traceback
        traceback.print_exc()
        return True  # Don't fail the whole script

# =============================================================================
# WGER API
# =============================================================================

def wger_headers():
    """Get WGER API headers"""
    return {
        'Authorization': f'Token {WGER_TOKEN}',
        'Content-Type': 'application/json'
    }

def wger_get_or_create_category(name, unit):
    """Get or create WGER measurement category"""
    # Check if exists (use limit to get all categories, avoiding pagination issues)
    r = requests.get(
        f"{WGER_BASE}/api/v2/measurement-category/",
        params={'limit': 1000},  # Get all categories at once
        headers=wger_headers(),
        timeout=30
    )
    r.raise_for_status()

    for cat in r.json().get('results', []):
        if cat.get('name') == name and cat.get('unit') == unit:
            return cat['id']

    # Create new category
    r = requests.post(
        f"{WGER_BASE}/api/v2/measurement-category/",
        headers=wger_headers(),
        json={'name': name, 'unit': unit},
        timeout=30
    )
    if r.status_code in [200, 201]:
        return r.json()['id']

    raise Exception(f"Failed to create category {name}: {r.status_code} {r.text}")

def wger_post_measurement(date, category_id, value, notes=''):
    """Post or update WGER measurement"""
    headers = wger_headers()

    # Check if exists for this category AND date
    try:
        r = requests.get(
            f"{WGER_BASE}/api/v2/measurement/",
            headers=headers,
            params={'category': category_id, 'date': date},
            timeout=10
        )
        existing_id = None
        if r.status_code == 200:
            results = r.json().get('results', [])
            if results:
                existing_id = results[0]['id']
    except Exception as e:
        existing_id = None

    payload = {
        'category': category_id,
        'date': date,
        'value': value,
        'notes': notes[:100]
    }

    if existing_id:
        # Update existing
        r = requests.put(
            f"{WGER_BASE}/api/v2/measurement/{existing_id}/",
            headers=headers,
            json=payload,
            timeout=10
        )
        action = "Updated"
    else:
        # Create new
        r = requests.post(
            f"{WGER_BASE}/api/v2/measurement/",
            headers=headers,
            json=payload,
            timeout=10
        )
        action = "Posted"

    if r.status_code in [200, 201]:
        return True, action
    else:
        # Return detailed error
        try:
            error_detail = r.json()
        except:
            error_detail = r.text[:100]
        return False, f"Failed ({r.status_code}): {error_detail}"

def wger_post_weight(date, weight_lb):
    """Post or update weight in WGER weightentry API"""
    headers = wger_headers()

    # Check if weight entry exists for this date
    try:
        r = requests.get(
            f"{WGER_BASE}/api/v2/weightentry/",
            headers=headers,
            params={'date': date},
            timeout=30
        )
        existing_id = None
        if r.status_code == 200:
            results = r.json().get('results', [])
            if results:
                existing_id = results[0]['id']
    except:
        existing_id = None

    payload = {"date": date, "weight": round(weight_lb, 2)}

    if existing_id:
        # Update existing
        r = requests.put(
            f"{WGER_BASE}/api/v2/weightentry/{existing_id}/",
            headers=headers,
            json=payload,
            timeout=30
        )
        action = "Updated"
    else:
        # Create new
        r = requests.post(
            f"{WGER_BASE}/api/v2/weightentry/",
            headers=headers,
            json=payload,
            timeout=30
        )
        action = "Posted"

    if r.status_code in (200, 201):
        return True, action
    return False, f"Failed ({r.status_code})"

# =============================================================================
# MFP OCR PARSING
# =============================================================================

def parse_mfp_screenshot(image_path):
    """Parse MFP screenshot using OCR"""
    print(f"📸 Reading: {Path(image_path).name}")

    img = Image.open(image_path)
    print("🔍 Running OCR...")

    text = pytesseract.image_to_string(img)

    nutrition = {
        'date': SYNC_DATE or datetime.now().strftime('%Y-%m-%d'),
        'calories': 0,
        'protein_g': 0,
        'carbs_g': 0,
        'fat_g': 0,
        'sodium_mg': 0,
        'exercise_cals': 0,
    }

    # Try multiple patterns to extract data
    # Pattern 1: "1,500 - 690 + 804 = 1,614"
    #              Goal   Food  Exercise  Remaining
    pattern1 = r'(\d+,?\d*)\s*-\s*(\d+,?\d*)\s*\+\s*(\d+,?\d*)\s*=\s*(\d+,?\d*)'
    match = re.search(pattern1, text)
    if match:
        nutrition['calories'] = int(match.group(2).replace(',', ''))
        nutrition['exercise_cals'] = int(match.group(3).replace(',', ''))
        print(f"   ✅ Formula match: Food={nutrition['calories']}, Exercise={nutrition['exercise_cals']}")
    else:
        # Pattern 2: Look for Goal/Food/Exercise/Remaining labels with flexible matching
        # Try to find numbers near these labels (more flexible)
        goal_match = re.search(r'(\d+,?\d*)\s*\n?\s*Goal', text, re.IGNORECASE)
        food_match = re.search(r'(\d+,?\d*)\s*\n?\s*Food', text, re.IGNORECASE)
        exercise_match = re.search(r'(\d+,?\d*)\s*\n?\s*Exercise', text, re.IGNORECASE)
        remaining_match = re.search(r'(\d+,?\d*)\s*\n?\s*Remaining', text, re.IGNORECASE)

        # Try to calculate Food using the formula: Goal - Food + Exercise = Remaining
        # Rearranged: Food = Goal + Exercise - Remaining
        if goal_match and exercise_match and remaining_match:
            goal = int(goal_match.group(1).replace(',', ''))
            exercise = int(exercise_match.group(1).replace(',', ''))
            remaining = int(remaining_match.group(1).replace(',', ''))

            # Calculate Food
            calculated_food = goal + exercise - remaining

            # If we found Food via OCR, verify it matches the calculation
            if food_match:
                ocr_food = int(food_match.group(1).replace(',', ''))
                if abs(ocr_food - calculated_food) > 10:
                    # OCR is wrong, use calculation
                    print(f"   ⚠️  OCR Food={ocr_food} doesn't match calculation={calculated_food}, using calculation")
                    nutrition['calories'] = calculated_food
                else:
                    nutrition['calories'] = ocr_food
            else:
                # No Food OCR, use calculation
                nutrition['calories'] = calculated_food
                print(f"   ℹ️  Calculated Food from Goal+Exercise-Remaining: {nutrition['calories']}")

            nutrition['exercise_cals'] = exercise
        elif food_match:
            # Found Food but couldn't verify via calculation
            nutrition['calories'] = int(food_match.group(1).replace(',', ''))
            if exercise_match:
                nutrition['exercise_cals'] = int(exercise_match.group(1).replace(',', ''))
            print(f"   ⚠️  Using OCR Food={nutrition['calories']} (couldn't verify via calculation)")

    return nutrition

def manual_nutrition_entry():
    """Manual nutrition entry"""
    print("\n📝 Manual Entry:")
    try:
        calories = int(input("Calories (Food): "))
        exercise = input("Exercise calories [Enter to skip]: ")
        protein = input("Protein (g) [Enter to skip]: ")
        carbs = input("Carbs (g) [Enter to skip]: ")
        fat = input("Fat (g) [Enter to skip]: ")
        sodium = input("Sodium (mg) [Enter to skip]: ")

        return {
            'date': SYNC_DATE or datetime.now().strftime('%Y-%m-%d'),
            'calories': calories,
            'exercise_cals': int(exercise) if exercise else 0,
            'protein_g': int(protein) if protein else 0,
            'carbs_g': int(carbs) if carbs else 0,
            'fat_g': int(fat) if fat else 0,
            'sodium_mg': int(sodium) if sodium else 0,
        }
    except:
        return None

def optional_digestion_log():
    """Optional digestion/BM tracking for scale correlation"""
    print("\n💩 Optional: Digestion log (for scale correlation)")
    print("   [Enter to skip]")
    had_bm = input("Had BM today? (y/n): ").lower()

    if had_bm not in ['y', 'yes']:
        return None

    quality = input("Quality (normal/small-hard/loose): ").lower()
    notes = input("Notes [Enter to skip]: ")

    return {
        'had_bm': True,
        'bm_quality': quality if quality else 'normal',
        'notes': notes if notes else None,
    }

def load_daily_constants():
    """Load daily constants (protein shake, etc.)"""
    if not DAILY_CONSTANTS_FILE.exists():
        return []

    with open(DAILY_CONSTANTS_FILE) as f:
        config = json.load(f)
    return [m for m in config.get('daily_meals', []) if m.get('enabled', True)]

def combine_with_constants(nutrition, constants):
    """Add daily constants to nutrition, but only for macros that weren't manually entered"""
    combined = nutrition.copy()
    for c in constants:
        # Always add calories (shake calories count as food)
        combined['calories'] += c.get('calories', 0)

        # Only add macros if they weren't manually entered (i.e., are 0)
        # If user entered a value, use that instead (override)
        if combined['protein_g'] == 0:
            combined['protein_g'] += c.get('protein_g', 0)
        if combined['carbs_g'] == 0:
            combined['carbs_g'] += c.get('carbs_g', 0)
        if combined['fat_g'] == 0:
            combined['fat_g'] += c.get('fat_g', 0)
        if combined['sodium_mg'] == 0:
            combined['sodium_mg'] += c.get('sodium_mg', 0)
    return combined

# =============================================================================
# MAIN SYNC LOGIC
# =============================================================================

def banner():
    print("╔═══════════════════════════════════════════════════════════╗")
    print("║          DAILY HEALTH SYNC - Withings + MFP → WGER       ║")
    print("╚═══════════════════════════════════════════════════════════╝")
    print()

def sync_withings():
    """Sync Withings data (steps, distance, calories, weight)"""
    print("=" * 60)
    print("📊 SYNCING WITHINGS DATA")
    print("=" * 60)

    try:
        access_token = get_withings_access_token()
        now = int(time.time())
        start_ts = now - DAYS_BACK * 86400

        # Get date range
        start_ymd = time.strftime("%Y-%m-%d", time.localtime(start_ts))
        end_ymd = time.strftime("%Y-%m-%d", time.localtime(now))

        print(f"\n⏰ Pulling last {DAYS_BACK} days: {start_ymd} to {end_ymd}")

        # Get activity data
        print("\n🚶 Fetching activity data (steps, distance, calories)...")
        activity = withings_get_activity(access_token, start_ymd, end_ymd)

        # DEBUG: Show count
        print(f"   Found {len(activity.get('activities', []))} days of activity data")

        # Get workout data (for accurate distance)
        print("🏃 Fetching workout data (for accurate distance)...")
        workouts_data = withings_get_workouts(access_token, start_ymd, end_ymd)

        # Build map of date -> workout distance (in meters)
        # Use 'distance' (GPS) if available, fall back to 'manual_distance' (manually entered in app)
        workout_distances = {}
        for workout in workouts_data.get('series', []):
            workout_date = workout.get('date')
            data = workout.get('data', {})
            workout_dist = data.get('manual_distance') or data.get('distance')
            if workout_date and workout_dist:
                # Sum up all workouts for the day
                workout_distances[workout_date] = workout_distances.get(workout_date, 0) + workout_dist

        print(f"   Found {len(workout_distances)} days with workout distance")

        # Get or create categories (use ksteps to avoid 5-digit limitation)
        global CATEGORY_STEPS, CATEGORY_DISTANCE, CATEGORY_WITHINGS_CAL
        CATEGORY_STEPS = wger_get_or_create_category("Steps", "ksteps")  # Must use ksteps (max 4 digits)
        CATEGORY_DISTANCE = wger_get_or_create_category("Distance", "km")
        CATEGORY_WITHINGS_CAL = wger_get_or_create_category("Calories", "kcal")

        # Post activity data
        activity_count = 0
        for day in activity.get("activities", []):
            date = day.get("date")
            if not date:
                continue

            if "steps" in day and day["steps"] is not None and day["steps"] > 0:
                # Convert to ksteps and round to 2 decimals (WGER limits)
                steps_k = round(float(day["steps"]) / 1000, 2)
                success, action = wger_post_measurement(date, CATEGORY_STEPS, steps_k, "Withings")
                print(f"   {'✅' if success else '❌'} {date}: {day['steps']:,} steps ({steps_k} ksteps) ({action})")
                if success:
                    activity_count += 1

            # Only store distance from explicit workouts (Withings app exercise sessions)
            # Activity distance includes all-day passive movement and doesn't match Withings app display
            distance_meters = None
            source = "workout"

            if date in workout_distances:
                distance_meters = workout_distances[date]

            if distance_meters:
                # Convert meters to km and round to 2 decimals
                distance_km = round(float(distance_meters) / 1000, 2)
                distance_mi = round(distance_km * 0.621371, 2)  # km to miles
                success, action = wger_post_measurement(date, CATEGORY_DISTANCE, distance_km, "Withings")
                print(f"   {'✅' if success else '❌'} {date}: {distance_km} km ({distance_mi} mi) from {source} ({action})")
                if success:
                    activity_count += 1

            if "calories" in day and day["calories"] is not None and day["calories"] > 0:
                # Round to 2 decimals
                calories = round(float(day["calories"]), 2)
                success, action = wger_post_measurement(date, CATEGORY_WITHINGS_CAL, calories, "Withings")
                print(f"   {'✅' if success else '❌'} {date}: {calories} kcal burned ({action})")
                if success:
                    activity_count += 1

        # Get weight data
        print("\n⚖️  Fetching weight data...")
        weight_data = withings_get_weight(access_token, start_ts, now)

        # DEBUG: Show count
        print(f"   Found {len(weight_data.get('measuregrps', []))} weight measurements")

        # Parse weight measurements and body composition
        weight_count = 0
        bodycomp_count = 0

        # Get or create body composition categories
        cat_bodyfat = wger_get_or_create_category("Body Fat", "%")
        cat_muscle = wger_get_or_create_category("Muscle Mass", "kg")
        cat_bone = wger_get_or_create_category("Bone Mass", "kg")
        cat_hydration = wger_get_or_create_category("Hydration", "%")

        # NEW: Additional health metrics
        cat_visceral_fat = wger_get_or_create_category("Visceral Fat", "index")
        cat_bmr = wger_get_or_create_category("Basal Metabolic Rate", "kcal")
        cat_metabolic_age = wger_get_or_create_category("Metabolic Age", "years")

        # Track dates already posted to avoid overwriting with older readings
        # Withings returns groups newest-first, so first weight per date wins
        weight_posted_dates = set()

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

                # Type 1: Weight (only post the most recent reading per date)
                if mtype == 1 and not weight_posted and date not in weight_posted_dates:
                    weight_lb = value * 2.20462
                    if 30 <= weight_lb <= 350:
                        success, action = wger_post_weight(date, weight_lb)
                        print(f"   {'✅' if success else '❌'} {date}: {weight_lb:.2f} lbs ({action})")
                        if success:
                            weight_count += 1
                            weight_posted = True
                            weight_posted_dates.add(date)
                elif mtype == 1 and date in weight_posted_dates:
                    weight_lb = value * 2.20462
                    print(f"   ⏭️  {date}: {weight_lb:.2f} lbs (skipped older reading)")

                # Type 6: Body Fat %
                elif mtype == 6:
                    success, action = wger_post_measurement(date, cat_bodyfat, round(value, 2), "Withings")
                    if success:
                        print(f"   ✅ {date}: {value:.1f}% body fat ({action})")
                        bodycomp_count += 1

                # Type 76: Muscle Mass (kg)
                elif mtype == 76:
                    success, action = wger_post_measurement(date, cat_muscle, round(value, 2), "Withings")
                    if success:
                        print(f"   ✅ {date}: {value:.2f} kg muscle ({action})")
                        bodycomp_count += 1

                # Type 77: Hydration (convert to %)
                elif mtype == 77:
                    # Hydration comes as kg of water; need body weight in kg to get %
                    if weight_posted and value > 0:
                        body_weight_kg = weight_lb / 2.20462
                        hydration_pct = (value / body_weight_kg) * 100
                        success, action = wger_post_measurement(date, cat_hydration, round(hydration_pct, 1), "Withings")
                        if success:
                            print(f"   ✅ {date}: {hydration_pct:.1f}% hydration ({action})")
                            bodycomp_count += 1

                # Type 88: Bone Mass (kg)
                elif mtype == 88:
                    success, action = wger_post_measurement(date, cat_bone, round(value, 2), "Withings")
                    if success:
                        print(f"   ✅ {date}: {value:.2f} kg bone ({action})")
                        bodycomp_count += 1

                # Type 170: Visceral Fat Index
                elif mtype == 170:
                    success, action = wger_post_measurement(date, cat_visceral_fat, round(value, 1), "Withings")
                    if success:
                        print(f"   ✅ {date}: {value:.1f} visceral fat index ({action})")
                        bodycomp_count += 1

                # Type 226: Basal Metabolic Rate (BMR) in kcal/day
                elif mtype == 226:
                    success, action = wger_post_measurement(date, cat_bmr, int(round(value)), "Withings")
                    if success:
                        print(f"   ✅ {date}: {int(value)} kcal/day BMR ({action})")
                        bodycomp_count += 1

                # Type 227: Metabolic Age in years
                elif mtype == 227:
                    success, action = wger_post_measurement(date, cat_metabolic_age, int(round(value)), "Withings")
                    if success:
                        print(f"   ✅ {date}: {int(value)} years metabolic age ({action})")
                        bodycomp_count += 1

        print(f"\n✅ Withings sync complete: {activity_count} activities, {weight_count} weights, {bodycomp_count} body comp")
        return True

    except Exception as e:
        print(f"\n❌ Withings sync failed: {e}")
        import traceback
        traceback.print_exc()
        return False

def save_digestion_log(log_data):
    """Save digestion log to local JSON file"""
    log_file = SCRIPT_DIR / 'digestion_log.json'

    # Load existing log
    if log_file.exists():
        with open(log_file) as f:
            log = json.load(f)
    else:
        log = {}

    date = SYNC_DATE or datetime.now().strftime('%Y-%m-%d')
    log[date] = log_data

    # Save
    with open(log_file, 'w') as f:
        json.dump(log, f, indent=2)

def sync_mfp_nutrition(arg):
    """Sync MFP nutrition data"""
    print("\n" + "=" * 60)
    print("🍎 SYNCING MFP NUTRITION")
    print("=" * 60)
    print("\nNote: MFP is the source of truth. Enter all food (including shakes) in MFP.\n")

    # Get nutrition data
    if arg == 'manual':
        nutrition = manual_nutrition_entry()
        if not nutrition:
            print("❌ Cancelled")
            return False
    elif os.path.exists(arg):
        nutrition = parse_mfp_screenshot(arg)

        if nutrition['calories'] == 0:
            print("⚠️  OCR couldn't find calories, switching to manual entry")
            nutrition = manual_nutrition_entry()
            if not nutrition:
                return False
    else:
        print(f"❌ File not found: {arg}")
        return False

    # If macros weren't extracted from OCR, prompt for them
    if nutrition['protein_g'] == 0 and nutrition['carbs_g'] == 0 and nutrition['fat_g'] == 0:
        print("\n⚠️  Macros not found in screenshot. Enter manually from MFP:")
        try:
            protein = input("   Protein (g) [Enter to skip]: ")
            carbs = input("   Carbs (g) [Enter to skip]: ")
            fat = input("   Fat (g) [Enter to skip]: ")
            sodium = input("   Sodium (mg) [Enter to skip]: ")

            if protein:
                nutrition['protein_g'] = int(protein)
            if carbs:
                nutrition['carbs_g'] = int(carbs)
            if fat:
                nutrition['fat_g'] = int(fat)
            if sodium:
                nutrition['sodium_mg'] = int(sodium)
        except:
            pass

    print("\n✅ From MFP:")
    print(f"   Calories (Food): {nutrition['calories']}")
    print(f"   Exercise: {nutrition.get('exercise_cals', 0)} kcal burned")
    print(f"   Protein:  {nutrition['protein_g']}g")
    print(f"   Carbs:    {nutrition['carbs_g']}g")
    print(f"   Fat:      {nutrition['fat_g']}g")
    print(f"   Sodium:   {nutrition['sodium_mg']}mg")

    # MFP is the source of truth - use data as-is (no daily constants added)
    # User enters everything in MFP daily, including protein shakes
    print("\n" + "═" * 60)
    print("📊 TOTAL DAILY NUTRITION (from MFP)")
    print("═" * 60)
    print(f"   Calories (Food): {nutrition['calories']} kcal")
    print(f"   Exercise Burned: {nutrition.get('exercise_cals', 0)} kcal")
    net_cals = nutrition['calories'] - nutrition.get('exercise_cals', 0)
    print(f"   Net Calories:    {net_cals} kcal")
    print(f"   Protein:  {nutrition['protein_g']}g")
    print(f"   Carbs:    {nutrition['carbs_g']}g")
    print(f"   Fat:      {nutrition['fat_g']}g")
    print(f"   Sodium:   {nutrition['sodium_mg']}mg")
    print("═" * 60)

    # Post to WGER
    print("\n📤 Posting nutrition to WGER...")

    date = nutrition['date']

    # Get or create nutrition categories (finds existing ones from CSV import)
    global CATEGORY_CALORIES, CATEGORY_PROTEIN, CATEGORY_CARBS, CATEGORY_FAT, CATEGORY_EXERCISE, CATEGORY_SODIUM
    if CATEGORY_CALORIES is None:
        CATEGORY_CALORIES = wger_get_or_create_category("Daily Calories", "kcal")
    if CATEGORY_PROTEIN is None:
        CATEGORY_PROTEIN = wger_get_or_create_category("Daily Protein", "g")
    if CATEGORY_CARBS is None:
        CATEGORY_CARBS = wger_get_or_create_category("Daily Carbs", "g")
    if CATEGORY_FAT is None:
        CATEGORY_FAT = wger_get_or_create_category("Daily Fat", "g")
    if CATEGORY_EXERCISE is None:
        CATEGORY_EXERCISE = wger_get_or_create_category("MFP Exercise Calories", "kcal")
    if CATEGORY_SODIUM is None:
        CATEGORY_SODIUM = wger_get_or_create_category("Daily Sodium", "mg")

    measurements = [
        (CATEGORY_CALORIES, nutrition['calories'], "Daily Calories"),
        (CATEGORY_EXERCISE, nutrition.get('exercise_cals', 0), "MFP Exercise Calories"),
        (CATEGORY_PROTEIN, nutrition['protein_g'], "Daily Protein"),
        (CATEGORY_CARBS, nutrition['carbs_g'], "Daily Carbs"),
        (CATEGORY_FAT, nutrition['fat_g'], "Daily Fat"),
        (CATEGORY_SODIUM, nutrition['sodium_mg'], "Daily Sodium"),
    ]

    success_count = 0
    for category_id, value, name in measurements:
        if value == 0:
            continue

        success, action = wger_post_measurement(date, category_id, value, "MFP Import")
        if success:
            print(f"   ✅ {name}: {value} ({action})")
            success_count += 1
        else:
            print(f"   ⚠️  {name}: {action}")

    if success_count > 0:
        print("\n✅ MFP nutrition posted successfully!")

        # Optional: Digestion log
        digestion = optional_digestion_log()
        if digestion:
            save_digestion_log(digestion)
            print("   ✅ Digestion log saved")

        return True
    else:
        print("\n❌ Failed to post nutrition")
        return False

def main():
    banner()

    # Check arguments and parse flags
    global DAYS_BACK, SYNC_DATE

    args = sys.argv[1:]
    backfill_days = None
    positional = []

    for a in args:
        if a.startswith('--backfill='):
            try:
                backfill_days = int(a.split('=', 1)[1])
            except ValueError:
                print(f"❌ Invalid --backfill value: {a}")
                sys.exit(1)
        elif a.startswith('--date='):
            raw = a.split('=', 1)[1]
            try:
                datetime.strptime(raw, '%Y-%m-%d')
                SYNC_DATE = raw
            except ValueError:
                print(f"❌ Invalid --date value: {raw} (use YYYY-MM-DD)")
                sys.exit(1)
        else:
            positional.append(a)

    # Default to manual if no positional arg given
    arg = positional[0] if positional else 'manual'

    # Always ask for date interactively if not provided via --date flag
    if not SYNC_DATE and not backfill_days:
        today = datetime.now().strftime('%Y-%m-%d')
        raw = input(f"📅 Date to sync [{today}]: ").strip()
        if raw:
            try:
                datetime.strptime(raw, '%Y-%m-%d')
                SYNC_DATE = raw
            except ValueError:
                print(f"❌ Invalid date: {raw} (use YYYY-MM-DD)")
                sys.exit(1)
        else:
            SYNC_DATE = today
        print(f"   Syncing: {SYNC_DATE}")

    if SYNC_DATE:
        # Calculate how many days back the target date is so Withings fetch covers it
        days_diff = (datetime.now() - datetime.strptime(SYNC_DATE, '%Y-%m-%d')).days
        DAYS_BACK = max(days_diff + 1, 1)
        print(f"📅 Date mode: syncing {SYNC_DATE} (DAYS_BACK={DAYS_BACK})")
    elif backfill_days is not None:
        DAYS_BACK = backfill_days
        print(f"📅 Backfill mode: syncing last {DAYS_BACK} days")

    # Step 1: Sync Sleep Number (last night's data)
    sleepnumber_ok = sync_sleepnumber()

    # Step 2: Sync Withings
    withings_ok = sync_withings()

    # Step 3: Sync MFP nutrition
    nutrition_ok = sync_mfp_nutrition(arg)

    # Summary
    print("\n" + "=" * 60)
    print("📈 SYNC SUMMARY")
    print("=" * 60)
    if SLEEPNUMBER_SYNC_ENABLED:
        print(f"   Sleep Number: {'✅ Success' if sleepnumber_ok else '❌ Failed'}")
    print(f"   Withings: {'✅ Success' if withings_ok else '❌ Failed'}")
    print(f"   Nutrition: {'✅ Success' if nutrition_ok else '❌ Failed'}")
    print("=" * 60)

    if withings_ok and nutrition_ok:
        print("\n🎉 ALL DATA SYNCED!")
        print(f"\nView at: {WGER_BASE}/weight.php")
    elif withings_ok or nutrition_ok:
        print("\n⚠️  Partial sync completed")
    else:
        print("\n❌ Sync failed")
        sys.exit(1)

if __name__ == '__main__':
    main()
