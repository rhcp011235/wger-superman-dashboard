#!/usr/bin/env python3
"""
weekly_report.py — Weekly health digest via Prowl push notification.

Fetches the last 7 days of data from WGER, computes weekly averages,
deltas, best/worst days, and sends a concise summary to your iPhone.

Usage:
    ./weekly_report.py            # Sends report for last 7 days
    ./weekly_report.py --dry-run  # Print report to stdout only

Cron (every Sunday 9am):
    0 9 * * 0 cd /path/to/scripts && ./weekly_report.py >> weekly_report.log 2>&1
"""

import sys
import os
import json
import subprocess
import urllib.parse
from datetime import datetime, timedelta

# ── Config ────────────────────────────────────────────────────────────────────
WGER_BASE  = os.environ.get('WGER_BASE', 'https://your-wger-instance.com')
WGER_TOKEN = os.environ.get('WGER_TOKEN', 'your_wger_api_token_here')
PROWL_API_KEY = os.environ.get('PROWL_API_KEY', '')
PROWL_APP     = "Health Sync"

DAYS = 7

# Goals (must match dashboard)
GOAL_WEIGHT   = 175
GOAL_CALORIES = 1500
GOAL_PROTEIN  = 150
GOAL_STEPS    = 10000
GOAL_SLEEP    = 85

# ── Helpers ───────────────────────────────────────────────────────────────────
def _curl_get(url: str, headers: list = []) -> str:
    cmd = ['curl', '-s', '--max-time', '20']
    for h in headers:
        cmd += ['-H', h]
    cmd.append(url)
    result = subprocess.run(cmd, capture_output=True, text=True)
    if result.returncode != 0:
        raise RuntimeError(f"curl failed: {result.stderr.strip()}")
    return result.stdout

def wger_get(path: str, params: dict = {}) -> dict:
    url = f"{WGER_BASE}{path}"
    if params:
        url += '?' + urllib.parse.urlencode(params)
    raw = _curl_get(url, headers=[
        f'Authorization: Token {WGER_TOKEN}',
        'Accept: application/json',
    ])
    return json.loads(raw)

def send_prowl(event: str, description: str, priority: int = 0) -> None:
    if not PROWL_API_KEY:
        return
    try:
        data = urllib.parse.urlencode({
            'apikey':      PROWL_API_KEY,
            'application': PROWL_APP,
            'event':       event[:1024],
            'description': description[:10000],
            'priority':    priority,
        })
        cmd = [
            'curl', '-s', '--max-time', '10',
            '-d', data,
            'https://api.prowlapp.com/publicapi/add',
        ]
        result = subprocess.run(cmd, capture_output=True, text=True)
        if result.returncode != 0:
            print(f"[Prowl] curl error: {result.stderr.strip()}")
    except Exception as e:
        print(f"[Prowl] Error: {e}")

def date_range(days: int) -> list:
    today = datetime.now().date()
    return [(today - timedelta(days=i)).isoformat() for i in range(days - 1, -1, -1)]

def avg(values: list):
    vals = [v for v in values if v is not None]
    return round(sum(vals) / len(vals), 1) if vals else None

def pct_of_days(hits: int, total: int) -> str:
    if total == 0: return "0%"
    return f"{round(hits / total * 100)}%"

# ── Data Fetching ─────────────────────────────────────────────────────────────
def fetch_weight_by_date(dates: list) -> dict:
    resp = wger_get('/api/v2/weightentry/', {'limit': 30, 'ordering': '-date'})
    result = {}
    for e in resp.get('results', []):
        d = e['date'][:10]
        if d in dates:
            result[d] = float(e['weight'])
    return result

def fetch_category_ids() -> dict:
    resp = wger_get('/api/v2/measurement-category/', {'limit': 200})
    ids: dict[str, list[int]] = {}
    for cat in resp.get('results', []):
        ids.setdefault(cat['name'], []).append(cat['id'])
    return ids

def fetch_measurement_by_date(cat_ids: list, dates: list) -> dict:
    result = {}
    for cat_id in cat_ids:
        resp = wger_get('/api/v2/measurement/', {'category': cat_id, 'limit': 60, 'ordering': '-date'})
        for e in resp.get('results', []):
            d = e['date'][:10]
            if d in dates and d not in result:
                result[d] = float(e['value'])
    return result

# ── Report Builder ────────────────────────────────────────────────────────────
def build_report(dry_run: bool = False) -> None:
    today = datetime.now().date()
    week_start = today - timedelta(days=DAYS - 1)
    dates = date_range(DAYS)

    print(f"Fetching data for {week_start} → {today}...")

    cat_ids = fetch_category_ids()

    def cat(name: str) -> list[int]:
        return cat_ids.get(name, [])

    # Fetch all metrics
    weights    = fetch_weight_by_date(dates)
    calories   = fetch_measurement_by_date(cat('Daily Calories'),    dates)
    protein    = fetch_measurement_by_date(cat('Daily Protein'),     dates)
    steps_k    = fetch_measurement_by_date(cat('Steps'),             dates)
    sleep_sc   = fetch_measurement_by_date(cat('Sleep Score'),       dates)
    sleep_dur  = fetch_measurement_by_date(cat('Sleep Duration'),    dates)
    sleep_hrv  = fetch_measurement_by_date(cat('Sleep HRV'),         dates)

    # Convert steps from ksteps → steps
    steps = {d: round(v * 1000) for d, v in steps_k.items()}

    # ── Compute summaries ─────────────────────────────────────────────────
    # Sort by date ascending so [0]=oldest (week start), [-1]=newest (current)
    wt_sorted = [weights[d] for d in sorted(weights)]
    wt_start  = wt_sorted[0]  if len(wt_sorted) >= 2 else None
    wt_end    = wt_sorted[-1] if len(wt_sorted) >= 2 else None
    wt_delta  = round(wt_end - wt_start, 1) if wt_start and wt_end else None

    cal_vals  = list(calories.values())
    prot_vals = list(protein.values())
    step_vals = list(steps.values())
    sleep_vals = list(sleep_sc.values())
    hrv_vals  = list(sleep_hrv.values())
    dur_vals  = list(sleep_dur.values())

    cal_days_hit  = sum(1 for v in cal_vals  if v > 0 and v <= GOAL_CALORIES)
    prot_days_hit = sum(1 for v in prot_vals if v >= GOAL_PROTEIN)
    step_days_hit = sum(1 for v in step_vals if v >= GOAL_STEPS)
    sleep_days_hit= sum(1 for v in sleep_vals if v >= GOAL_SLEEP)

    # Best / worst days
    best_sleep_day  = max(sleep_sc,  key=sleep_sc.get)  if sleep_sc  else None
    worst_sleep_day = min(sleep_sc,  key=sleep_sc.get)  if sleep_sc  else None
    best_step_day   = max(steps,     key=steps.get)     if steps     else None
    best_prot_day   = max(protein,   key=protein.get)   if protein   else None

    # ── Build message ─────────────────────────────────────────────────────
    lines = [
        f"📅 {week_start} → {today}",
        "",
    ]

    # Weight
    if wt_end is not None:
        arrow = "↓" if (wt_delta or 0) < 0 else ("↑" if (wt_delta or 0) > 0 else "→")
        color = "✅" if (wt_delta or 0) <= 0 else "⚠️"
        lines.append(f"⚖️  WEIGHT")
        lines.append(f"  Current: {wt_end} lbs  (goal {GOAL_WEIGHT})")
        if wt_delta is not None:
            lines.append(f"  Week: {arrow} {abs(wt_delta)} lbs  {color}")
        lines.append(f"  To goal: {round(wt_end - GOAL_WEIGHT, 1)} lbs")
        lines.append("")

    # Nutrition
    if cal_vals or prot_vals:
        lines.append(f"🥗 NUTRITION (7-day avg)")
        if cal_vals:
            lines.append(f"  Calories: {avg(cal_vals)} kcal  (goal ≤{GOAL_CALORIES})  {pct_of_days(cal_days_hit, len(cal_vals))} on target")
        if prot_vals:
            lines.append(f"  Protein:  {avg(prot_vals)}g  (goal ≥{GOAL_PROTEIN}g)  {pct_of_days(prot_days_hit, len(prot_vals))} on target")
        if best_prot_day:
            lines.append(f"  Best prot day: {best_prot_day} ({round(protein[best_prot_day])}g)")
        lines.append("")

    # Activity
    if step_vals:
        lines.append(f"🏃 ACTIVITY")
        lines.append(f"  Steps avg: {round(avg(step_vals) or 0):,}  (goal {GOAL_STEPS:,})  {pct_of_days(step_days_hit, len(step_vals))} on target")
        if best_step_day:
            lines.append(f"  Best day: {best_step_day} ({steps[best_step_day]:,} steps)")
        lines.append("")

    # Sleep
    if sleep_vals:
        lines.append(f"🛏️  SLEEP")
        lines.append(f"  Score avg: {avg(sleep_vals)}/100  (goal ≥{GOAL_SLEEP})  {pct_of_days(sleep_days_hit, len(sleep_vals))} on target")
        if dur_vals:
            lines.append(f"  Duration avg: {avg(dur_vals)} hrs")
        if hrv_vals:
            lines.append(f"  HRV avg: {avg(hrv_vals)} ms")
        if best_sleep_day:
            lines.append(f"  Best night: {best_sleep_day} (score {sleep_sc[best_sleep_day]})")
        if worst_sleep_day and worst_sleep_day != best_sleep_day:
            lines.append(f"  Worst night: {worst_sleep_day} (score {sleep_sc[worst_sleep_day]})")
        lines.append("")

    lines.append(f"📊 {WGER_BASE}/charts.php")

    report = "\n".join(lines)

    print("\n" + "=" * 50)
    print(report)
    print("=" * 50 + "\n")

    if not dry_run:
        send_prowl(
            event=f"📋 Weekly Health Report — {today}",
            description=report,
            priority=0,
        )
        print("✅ Sent via Prowl.")
    else:
        print("(dry-run — not sent)")

# ── Entry point ───────────────────────────────────────────────────────────────
if __name__ == '__main__':
    dry_run = '--dry-run' in sys.argv
    try:
        build_report(dry_run=dry_run)
    except Exception as e:
        msg = f"Weekly report failed: {e}"
        print(f"❌ {msg}")
        if not dry_run:
            send_prowl(event="❌ Weekly Report Failed", description=msg, priority=1)
        sys.exit(1)
