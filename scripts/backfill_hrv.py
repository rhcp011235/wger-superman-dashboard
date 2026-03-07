#!/usr/bin/env python3
"""
Backfill HRV Data for Past 265 Days

Fetches Sleep Number data for the past 265 days and updates WGER with HRV values.
Uses the updated asyncsleepiq library with fetch_sleep_data() method.

Output: Logs to backfill_hrv_TIMESTAMP.log and displays progress to stdout
"""

import sys
import json
import requests
import asyncio
import logging
from datetime import datetime, timedelta
from pathlib import Path

# Add asyncsleepiq from /tmp if testing new version
sys.path.insert(0, '/tmp/asyncsleepiq')

from asyncsleepiq import AsyncSleepIQ

# Import config from main script
from daily_health_sync import (
    SLEEPNUMBER_EMAIL,
    SLEEPNUMBER_PASSWORD,
    WGER_BASE,
    WGER_TOKEN,
    SLEEP_CACHE_DIR,
)

# Setup logging
SCRIPT_DIR = Path(__file__).parent
LOG_FILE = SCRIPT_DIR / f"backfill_hrv_{datetime.now().strftime('%Y%m%d_%H%M%S')}.log"

# Configure logging to both file and console
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(message)s',
    datefmt='%Y-%m-%d %H:%M:%S',
    handlers=[
        logging.FileHandler(LOG_FILE),
        logging.StreamHandler(sys.stdout)
    ]
)
logger = logging.getLogger(__name__)

def wger_headers():
    """Get WGER API headers"""
    return {
        'Authorization': f'Token {WGER_TOKEN}',
        'Content-Type': 'application/json'
    }

def wger_get_or_create_category(name, unit):
    """Get or create WGER measurement category"""
    r = requests.get(
        f"{WGER_BASE}/api/v2/measurement-category/",
        params={'limit': 1000},
        headers=wger_headers(),
        timeout=30
    )
    r.raise_for_status()

    for cat in r.json().get('results', []):
        if cat.get('name') == name and cat.get('unit') == unit:
            return cat['id']

    # Create new category
    data = {'name': name, 'unit': unit}
    r = requests.post(
        f"{WGER_BASE}/api/v2/measurement-category/",
        headers=wger_headers(),
        json=data,
        timeout=30
    )
    r.raise_for_status()
    return r.json()['id']

def wger_post_measurement(date, category_id, value, notes=""):
    """Post measurement to WGER (update if exists)"""
    date_str = date if isinstance(date, str) else date.strftime('%Y-%m-%d')

    # Check if measurement exists for this date and category
    r = requests.get(
        f"{WGER_BASE}/api/v2/measurement/",
        params={'limit': 1000},
        headers=wger_headers(),
        timeout=30
    )
    r.raise_for_status()

    existing_id = None
    for m in r.json().get('results', []):
        if m.get('date') == date_str and m.get('category') == category_id:
            existing_id = m['id']
            break

    data = {
        'category': category_id,
        'value': str(value),
        'date': date_str,
        'notes': notes
    }

    if existing_id:
        # Update existing
        r = requests.patch(
            f"{WGER_BASE}/api/v2/measurement/{existing_id}/",
            headers=wger_headers(),
            json=data,
            timeout=30
        )
        r.raise_for_status()
        return True, "updated"
    else:
        # Create new
        r = requests.post(
            f"{WGER_BASE}/api/v2/measurement/",
            headers=wger_headers(),
            json=data,
            timeout=30
        )
        r.raise_for_status()
        return True, "created"

async def fetch_sleep_data_for_date(api, sleeper, date):
    """Fetch sleep data for a specific date using new library method"""
    try:
        from datetime import datetime
        if isinstance(date, str):
            date = datetime.strptime(date[:10], "%Y-%m-%d")

        sleep_data = await sleeper.get_sleep_data(date)

        if not sleep_data:
            return None

        return {
            'duration_hours': round(sleep_data.duration / 3600, 2) if sleep_data.duration else None,
            'sleep_score': sleep_data.sleep_score,
            'sleep_score_recent': None,  # No longer separate recent values
            'heart_rate': sleep_data.heart_rate,
            'heart_rate_recent': None,  # No longer separate recent values
            'respiratory_rate': sleep_data.respiratory_rate,
            'respiratory_rate_recent': None,  # No longer separate recent values
            'hrv': sleep_data.hrv,
        }
    except Exception as e:
        logger.warning(f"      Error fetching data: {e}")
        return None

async def backfill_hrv(days_back=265):
    """Backfill HRV data for the past N days"""
    logger.info("\n" + "=" * 80)
    logger.info(f"🔄 BACKFILLING HRV DATA FOR PAST {days_back} DAYS")
    logger.info("=" * 80)

    # Login to Sleep Number
    logger.info("\n🔐 Logging in to Sleep Number...")
    api = AsyncSleepIQ()
    await api.login(SLEEPNUMBER_EMAIL, SLEEPNUMBER_PASSWORD)
    await api.init_beds()

    bed = list(api.beds.values())[0]
    sleeper = bed.sleepers[0]
    logger.info(f"   ✅ Connected to bed: {bed.name}")
    logger.info(f"   ✅ Sleeper: {sleeper.name}")

    # Get or create WGER categories
    logger.info("\n📊 Setting up WGER categories...")
    cat_sleep_duration = wger_get_or_create_category("Sleep Duration", "hours")
    cat_sleep_score = wger_get_or_create_category("Sleep Score", "score")
    cat_sleep_score_recent = wger_get_or_create_category("Sleep Score (Recent)", "score")
    cat_heart_rate = wger_get_or_create_category("Sleep Heart Rate", "bpm")
    cat_heart_rate_recent = wger_get_or_create_category("Sleep Heart Rate (Recent)", "bpm")
    cat_respiratory_rate = wger_get_or_create_category("Sleep Respiratory Rate", "brpm")
    cat_respiratory_rate_recent = wger_get_or_create_category("Sleep Respiratory Rate (Recent)", "brpm")
    cat_hrv = wger_get_or_create_category("Sleep HRV", "ms")
    logger.info("   ✅ Categories ready")

    # Process each day
    logger.info(f"\n🔄 Processing {days_back} days...")
    stats = {
        'total': days_back,
        'hrv_found': 0,
        'no_data': 0,
        'errors': 0,
        'updated': 0,
        'created': 0,
    }

    for i in range(days_back, -1, -1):  # Go backwards from oldest to newest
        date = datetime.now() - timedelta(days=i)
        date_str = date.strftime('%Y-%m-%d')

        logger.info(f"\n📅 {date_str} ({days_back - i + 1}/{days_back + 1})")

        try:
            # Check cache first
            cache_file = SLEEP_CACHE_DIR / f"{date_str}.json"

            # Fetch data
            logger.info(f"   🌐 Fetching from API...")
            sleep_data = await fetch_sleep_data_for_date(api, sleeper, date)

            if not sleep_data:
                logger.info(f"   ⚠️  No data available")
                stats['no_data'] += 1
                await asyncio.sleep(0.5)
                continue

            # Cache the data
            cache_data = {
                'date': date_str,
                'data': sleep_data,
                'fetched_at': datetime.now().isoformat()
            }
            with open(cache_file, 'w') as f:
                json.dump(cache_data, f, indent=2)

            # Post to WGER
            metrics_posted = 0

            if sleep_data['duration_hours']:
                success, action = wger_post_measurement(date_str, cat_sleep_duration, sleep_data['duration_hours'], "Sleep Number Backfill")
                if success:
                    metrics_posted += 1
                    stats[action] += 1

            if sleep_data['sleep_score']:
                success, action = wger_post_measurement(date_str, cat_sleep_score, sleep_data['sleep_score'], "Sleep Number Backfill")
                if success:
                    metrics_posted += 1
                    stats[action] += 1

            if sleep_data['sleep_score_recent']:
                success, action = wger_post_measurement(date_str, cat_sleep_score_recent, sleep_data['sleep_score_recent'], "Sleep Number Backfill")
                if success:
                    metrics_posted += 1
                    stats[action] += 1

            if sleep_data['heart_rate']:
                success, action = wger_post_measurement(date_str, cat_heart_rate, sleep_data['heart_rate'], "Sleep Number Backfill")
                if success:
                    metrics_posted += 1
                    stats[action] += 1

            if sleep_data['heart_rate_recent']:
                success, action = wger_post_measurement(date_str, cat_heart_rate_recent, sleep_data['heart_rate_recent'], "Sleep Number Backfill")
                if success:
                    metrics_posted += 1
                    stats[action] += 1

            if sleep_data['respiratory_rate']:
                success, action = wger_post_measurement(date_str, cat_respiratory_rate, sleep_data['respiratory_rate'], "Sleep Number Backfill")
                if success:
                    metrics_posted += 1
                    stats[action] += 1

            if sleep_data['respiratory_rate_recent']:
                success, action = wger_post_measurement(date_str, cat_respiratory_rate_recent, sleep_data['respiratory_rate_recent'], "Sleep Number Backfill")
                if success:
                    metrics_posted += 1
                    stats[action] += 1

            if sleep_data['hrv']:
                success, action = wger_post_measurement(date_str, cat_hrv, sleep_data['hrv'], "Sleep Number Backfill")
                if success:
                    logger.info(f"   ✅ HRV: {sleep_data['hrv']} ms ({action})")
                    metrics_posted += 1
                    stats['hrv_found'] += 1
                    stats[action] += 1
            else:
                logger.info(f"   ⚠️  No HRV data")

            logger.info(f"   📊 Posted {metrics_posted} metrics")

        except Exception as e:
            logger.error(f"   ❌ Error processing {date_str}: {e}")
            stats['errors'] += 1

        # Rate limiting
        await asyncio.sleep(0.5)

    # Cleanup
    try:
        if hasattr(api, 'stop_websocket'):
            await api.stop_websocket()
    except:
        pass

    # Summary
    logger.info("\n" + "=" * 80)
    logger.info("📊 BACKFILL COMPLETE")
    logger.info("=" * 80)
    logger.info(f"Total days processed: {stats['total']}")
    logger.info(f"Days with HRV data: {stats['hrv_found']}")
    logger.info(f"Days with no data: {stats['no_data']}")
    logger.info(f"Metrics created: {stats['created']}")
    logger.info(f"Metrics updated: {stats['updated']}")
    logger.info(f"\n✅ Backfill complete! HRV data is now in WGER.")

if __name__ == "__main__":
    days = 265
    if len(sys.argv) > 1:
        days = int(sys.argv[1])

    # Show log file location
    print(f"\n📝 Logging to: {LOG_FILE}")
    print(f"   You can monitor progress with: tail -f {LOG_FILE}")
    print()

    asyncio.run(backfill_hrv(days))

    print(f"\n📝 Complete log saved to: {LOG_FILE}")
