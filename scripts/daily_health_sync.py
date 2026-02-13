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

    Daily Constants (auto-added):
        - Protein shake: 240 kcal, 50g protein (from daily_constants.json)

    Optional Manual Tracking:
        - Digestion log (for scale correlation)

UNITS (locked for consistency):
    - Distance: ALWAYS km (never miles)
    - Weight: ALWAYS lbs (never kg)
    - Calories: ALWAYS kcal (never kJ)
    - Steps: ALWAYS ksteps in WGER (displayed as steps in API)

OUTPUT:
    All data posted to WGER and available at:
    https://weight.rhcp011235.com/weight.php?format=json
"""

import os
import sys
import re
import json
import time
import requests
import webbrowser
from pathlib import Path
from datetime import datetime
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
# CONFIGURATION (Hardcoded as requested)
# =============================================================================

# Feature Flags
SLEEPNUMBER_SYNC = os.getenv("SLEEPNUMBER_SYNC", "false").lower() == "true"

# Withings OAuth
WITHINGS_CLIENT_ID = os.getenv("WITHINGS_CLIENT_ID", "")
WITHINGS_CLIENT_SECRET = os.getenv("WITHINGS_CLIENT_SECRET", "")
WITHINGS_TOKEN_FILE = str(Path(__file__).parent / "withings_tokens.json")

# Sleep Number Credentials
SLEEPNUMBER_EMAIL = os.getenv("SLEEPNUMBER_EMAIL", "")
SLEEPNUMBER_PASSWORD = os.getenv("SLEEPNUMBER_PASSWORD", "")
SLEEPNUMBER_TOKEN_FILE = str(Path(__file__).parent / "sleepnumber_session.json")

# WGER
WGER_BASE = os.getenv('WGER_BASE_URL', 'https://localhost')
WGER_TOKEN = os.getenv('WGER_TOKEN', '')

# WGER Measurement Categories (created once)
CATEGORY_CALORIES = 18      # Daily Calories (kcal) - food
CATEGORY_PROTEIN = 19       # Daily Protein (g)
CATEGORY_CARBS = 20         # Daily Carbs (g)
CATEGORY_FAT = 21           # Daily Fat (g)
CATEGORY_EXERCISE = 22      # MFP Exercise Calories (kcal) - from MFP
CATEGORY_SODIUM = None      # Will be auto-created - Daily Sodium (mg)
CATEGORY_STEPS = None       # Will be auto-created from Withings
CATEGORY_DISTANCE = None    # Will be auto-created from Withings
CATEGORY_WITHINGS_CAL = None  # Withings exercise calories
CATEGORY_SLEEP_DURATION = None  # Sleep duration (hours)
CATEGORY_SLEEP_SCORE = None     # SleepIQ score (0-100)
CATEGORY_SLEEP_HR = None        # Average heart rate (bpm)
CATEGORY_SLEEP_HRV = None       # Heart rate variability
CATEGORY_SLEEP_RR = None        # Respiratory rate (breaths/min)

# Daily constants config
SCRIPT_DIR = Path(__file__).parent
DAILY_CONSTANTS_FILE = SCRIPT_DIR / 'daily_constants.json'

# Sync window (days back for Withings)
DAYS_BACK = 7

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
    try:
        api = AsyncSleepIQ()

        # Login
        await api.login(email, password)

        # Get beds
        beds = api.beds
        if not beds:
            print("   ⚠️  No Sleep Number beds found")
            return None

        bed = beds[0]  # Use first bed

        # Get sleep data for last night
        # asyncsleepiq provides sleeper data with sleep metrics
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
        }

        # Sleep Number tracks "sleepData" which includes:
        # - sleep session (in/out of bed times)
        # - sleep score (SleepIQ score)
        # - heart rate, HRV, respiratory rate

        if hasattr(sleeper, 'sleep_number'):
            sleep_data['sleep_score'] = sleeper.sleep_number

        if hasattr(sleeper, 'sleep_data'):
            data = sleeper.sleep_data
            if data:
                # Duration (convert to hours)
                if 'totalSleepSessionTime' in data:
                    sleep_data['duration_hours'] = round(data['totalSleepSessionTime'] / 3600, 2)

                # Heart rate
                if 'averageHeartRate' in data:
                    sleep_data['avg_heart_rate'] = round(data['averageHeartRate'], 1)

                # HRV
                if 'averageHeartRateVariability' in data:
                    sleep_data['avg_hrv'] = round(data['averageHeartRateVariability'], 1)

                # Respiratory rate
                if 'averageRespirationRate' in data:
                    sleep_data['avg_resp_rate'] = round(data['averageRespirationRate'], 1)

        await api.stop_websocket()
        return sleep_data

    except Exception as e:
        print(f"   ⚠️  Sleep Number API error: {e}")
        return None

def sync_sleepnumber():
    """Sync Sleep Number data"""
    if not SLEEPNUMBER_SYNC or not SLEEPNUMBER_AVAILABLE:
        return True  # Skip silently if disabled

    print("\n" + "=" * 60)
    print("😴 SYNCING SLEEP NUMBER DATA")
    print("=" * 60)

    if not SLEEPNUMBER_EMAIL or not SLEEPNUMBER_PASSWORD:
        print("⚠️  Sleep Number credentials not configured (skipping)")
        return True

    try:
        # Run async function
        sleep_data = asyncio.run(sleepnumber_get_sleep_data(SLEEPNUMBER_EMAIL, SLEEPNUMBER_PASSWORD))

        if not sleep_data:
            print("⚠️  No sleep data available")
            return True

        # Get or create categories
        global CATEGORY_SLEEP_DURATION, CATEGORY_SLEEP_SCORE, CATEGORY_SLEEP_HR
        global CATEGORY_SLEEP_HRV, CATEGORY_SLEEP_RR

        CATEGORY_SLEEP_DURATION = wger_get_or_create_category("Sleep Duration", "hours")
        CATEGORY_SLEEP_SCORE = wger_get_or_create_category("Sleep Score", "score")
        CATEGORY_SLEEP_HR = wger_get_or_create_category("Sleep Heart Rate", "bpm")
        CATEGORY_SLEEP_HRV = wger_get_or_create_category("Sleep HRV", "ms")
        CATEGORY_SLEEP_RR = wger_get_or_create_category("Sleep Respiratory Rate", "brpm")

        # Post to WGER (use yesterday's date since this is last night's sleep)
        from datetime import timedelta
        yesterday = (datetime.now() - timedelta(days=1)).strftime('%Y-%m-%d')

        success_count = 0

        if sleep_data['duration_hours']:
            success, action = wger_post_measurement(yesterday, CATEGORY_SLEEP_DURATION,
                                                   sleep_data['duration_hours'], "Sleep Number")
            if success:
                print(f"   ✅ Sleep duration: {sleep_data['duration_hours']} hours ({action})")
                success_count += 1

        if sleep_data['sleep_score']:
            success, action = wger_post_measurement(yesterday, CATEGORY_SLEEP_SCORE,
                                                   sleep_data['sleep_score'], "Sleep Number")
            if success:
                print(f"   ✅ SleepIQ score: {sleep_data['sleep_score']} ({action})")
                success_count += 1

        if sleep_data['avg_heart_rate']:
            success, action = wger_post_measurement(yesterday, CATEGORY_SLEEP_HR,
                                                   sleep_data['avg_heart_rate'], "Sleep Number")
            if success:
                print(f"   ✅ Avg heart rate: {sleep_data['avg_heart_rate']} bpm ({action})")
                success_count += 1

        if sleep_data['avg_hrv']:
            success, action = wger_post_measurement(yesterday, CATEGORY_SLEEP_HRV,
                                                   sleep_data['avg_hrv'], "Sleep Number")
            if success:
                print(f"   ✅ Avg HRV: {sleep_data['avg_hrv']} ms ({action})")
                success_count += 1

        if sleep_data['avg_resp_rate']:
            success, action = wger_post_measurement(yesterday, CATEGORY_SLEEP_RR,
                                                   sleep_data['avg_resp_rate'], "Sleep Number")
            if success:
                print(f"   ✅ Avg respiratory rate: {sleep_data['avg_resp_rate']} brpm ({action})")
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
    # Check if exists
    r = requests.get(
        f"{WGER_BASE}/api/v2/measurement-category/",
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
        'date': datetime.now().strftime('%Y-%m-%d'),
        'calories': 0,
        'protein_g': 0,
        'carbs_g': 0,
        'fat_g': 0,
        'sodium_mg': 0,
        'exercise_cals': 0,
    }

    # Parse: "1,500 - 690 + 804 = 1,614"
    #         Goal   Food  Exercise  Remaining
    pattern = r'(\d+,?\d*)\s*-\s*(\d+,?\d*)\s*\+\s*(\d+,?\d*)'
    match = re.search(pattern, text)
    if match:
        nutrition['calories'] = int(match.group(2).replace(',', ''))
        nutrition['exercise_cals'] = int(match.group(3).replace(',', ''))
    else:
        # Fallback: "690\nFood"
        food_match = re.search(r'(\d+,?\d*)\s*\n\s*Food', text, re.IGNORECASE)
        if food_match:
            nutrition['calories'] = int(food_match.group(1).replace(',', ''))

        exercise_match = re.search(r'(\d+,?\d*)\s*\n\s*Exercise', text, re.IGNORECASE)
        if exercise_match:
            nutrition['exercise_cals'] = int(exercise_match.group(1).replace(',', ''))

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
            'date': datetime.now().strftime('%Y-%m-%d'),
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
    """Add daily constants to nutrition"""
    combined = nutrition.copy()
    for c in constants:
        combined['calories'] += c.get('calories', 0)
        combined['protein_g'] += c.get('protein_g', 0)
        combined['carbs_g'] += c.get('carbs_g', 0)
        combined['fat_g'] += c.get('fat_g', 0)
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

            if "distance" in day and day["distance"] is not None and day["distance"] > 0:
                # Convert meters to km and round to 2 decimals
                distance_km = round(float(day["distance"]) / 1000, 2)
                distance_mi = round(distance_km * 0.621371, 2)  # km to miles
                success, action = wger_post_measurement(date, CATEGORY_DISTANCE, distance_km, "Withings")
                print(f"   {'✅' if success else '❌'} {date}: {distance_km} km ({distance_mi} mi) ({action})")
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
                        print(f"   {'✅' if success else '❌'} {date}: {weight_lb:.2f} lbs ({action})")
                        if success:
                            weight_count += 1
                            weight_posted = True

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
                    # Hydration comes as kg of water, convert to %
                    if weight_posted and value > 0:
                        hydration_pct = (value / (value / 2.20462)) * 100  # rough estimate
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

    # Add today's entry
    date = datetime.now().strftime('%Y-%m-%d')
    log[date] = log_data

    # Save
    with open(log_file, 'w') as f:
        json.dump(log, f, indent=2)

def sync_mfp_nutrition(arg):
    """Sync MFP nutrition data"""
    print("\n" + "=" * 60)
    print("🍎 SYNCING MFP NUTRITION")
    print("=" * 60)

    # Load daily constants
    constants = load_daily_constants()
    if constants:
        print("\n📋 Daily constants:")
        for c in constants:
            print(f"   • {c['name']}: {c['calories']} kcal")
        print()

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

    # Combine with constants
    if constants:
        combined = combine_with_constants(nutrition, constants)
        constants_cals = sum(c['calories'] for c in constants)
        print(f"\n➕ Added {constants_cals} kcal from daily constants")
    else:
        combined = nutrition

    print("\n" + "═" * 60)
    print("📊 TOTAL DAILY NUTRITION")
    print("═" * 60)
    print(f"   Calories (Food): {combined['calories']} kcal")
    print(f"   Exercise Burned: {combined.get('exercise_cals', 0)} kcal")
    net_cals = combined['calories'] - combined.get('exercise_cals', 0)
    print(f"   Net Calories:    {net_cals} kcal")
    print(f"   Protein:  {combined['protein_g']}g")
    print(f"   Carbs:    {combined['carbs_g']}g")
    print(f"   Fat:      {combined['fat_g']}g")
    print(f"   Sodium:   {combined['sodium_mg']}mg")
    print("═" * 60)

    # Post to WGER
    print("\n📤 Posting nutrition to WGER...")

    date = combined['date']

    # Get or create sodium category
    global CATEGORY_SODIUM
    if CATEGORY_SODIUM is None:
        CATEGORY_SODIUM = wger_get_or_create_category("Daily Sodium", "mg")

    measurements = [
        (CATEGORY_CALORIES, combined['calories'], "Daily Calories"),
        (CATEGORY_EXERCISE, combined.get('exercise_cals', 0), "MFP Exercise Calories"),
        (CATEGORY_PROTEIN, combined['protein_g'], "Daily Protein"),
        (CATEGORY_CARBS, combined['carbs_g'], "Daily Carbs"),
        (CATEGORY_FAT, combined['fat_g'], "Daily Fat"),
        (CATEGORY_SODIUM, combined['sodium_mg'], "Daily Sodium"),
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

    # Check arguments
    if len(sys.argv) < 2:
        print("Usage:")
        print("  daily_health_sync.py screenshot.png    # OCR mode")
        print("  daily_health_sync.py manual            # Manual entry")
        print()
        print("This script will:")
        print("  1. Sync Withings data (steps, calories, weight)")
        print("  2. Parse MFP nutrition from screenshot or manual entry")
        print("  3. Post everything to WGER")
        sys.exit(1)

    arg = sys.argv[1]

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
    if SLEEPNUMBER_SYNC and SLEEPNUMBER_AVAILABLE:
        print(f"   Sleep Number: {'✅ Success' if sleepnumber_ok else '❌ Failed'}")
    print(f"   Withings: {'✅ Success' if withings_ok else '❌ Failed'}")
    print(f"   Nutrition: {'✅ Success' if nutrition_ok else '❌ Failed'}")
    print("=" * 60)

    if withings_ok and nutrition_ok:
        print("\n🎉 ALL DATA SYNCED!")
        print(f"\nView at: {WGER_BASE}/weight_enhanced.php")
    elif withings_ok or nutrition_ok:
        print("\n⚠️  Partial sync completed")
    else:
        print("\n❌ Sync failed")
        sys.exit(1)

if __name__ == '__main__':
    main()
