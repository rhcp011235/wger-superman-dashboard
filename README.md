# 🦸‍♂️ WGER Superman Dashboard

> Your complete health tracking system with AI-powered insights

A comprehensive health data aggregation and analysis platform that syncs Withings devices, MyFitnessPal nutrition, and WGER fitness tracking into a unified, ChatGPT-optimized JSON dashboard.

## ✨ Features

- **📊 Unified Health Dashboard** - All your health metrics in one place
- **🔄 Auto-Sync from Multiple Sources**:
  - Withings smart scales (weight, body composition, steps)
  - MyFitnessPal (nutrition, macros via OCR)
  - WGER fitness tracker (workouts, measurements)
- **🤖 AI-Ready JSON Output** - Optimized for ChatGPT health analysis
- **📈 Trend Tracking** - 7/30/90-day trends for weight, body composition
- **💪 Muscle vs Fat Loss** - Track lean mass and fat mass separately
- **🎯 Smart Calculations**:
  - BMR/TDEE estimation (Mifflin-St Jeor equation)
  - True calorie deficit (vs TDEE, not just MFP goal)
  - Net calories after exercise
- **🔐 Privacy-First** - All data stays on your server

## 📸 What It Looks Like

### Daily Sync (15 seconds)
```bash
./daily_health_sync.py screenshot.png

✅ Withings sync complete: 24 activities, 13 weights, 7 body comp
✅ MFP nutrition posted successfully!
🎉 ALL DATA SYNCED!
```

### JSON Output (for ChatGPT)
```json
{
  "date": "2026-02-11",
  "weight": {
    "current_lb": 196.22,
    "trend_30d_lb": 201.12
  },
  "energy": {
    "intake_kcal": 930,
    "exercise_mfp_kcal": 804,
    "net_kcal_vs_tdee": -979
  },
  "bodycomp": {
    "bodyfat_pct": 28.24,
    "lean_mass_kg": 63.87,
    "fat_mass_kg": 25.13
  },
  "macros": {
    "protein_g": 170,
    "carbs_g": 91,
    "fat_g": 37,
    "sodium_mg": 2400
  }
}
```

## 🚀 Quick Start

### Prerequisites

- Python 3.8+
- PHP 7.4+ (for API endpoint)
- WGER instance (self-hosted or cloud)
- Withings account with developer app
- MyFitnessPal account (free tier OK)

### Installation

1. **Clone the repository**
```bash
git clone https://github.com/rhcp011235/wger-superman-dashboard.git
cd wger-superman-dashboard
```

2. **Install Python dependencies**
```bash
pip3 install -r requirements.txt
```

3. **Configure environment**
```bash
cp .env.example .env
# Edit .env with your credentials
```

4. **Set up daily constants** (optional)
```bash
cp config/daily_constants.json.example config/daily_constants.json
# Edit with your regular meals (e.g., protein shake)
```

5. **Initial Withings OAuth**
```bash
cd scripts
./daily_health_sync.py manual
# Follow browser prompts to authorize Withings
```

## ⚙️ Configuration

### Environment Variables

Create a `.env` file in the project root:

```bash
# WGER Configuration
WGER_BASE_URL=https://your-wger-instance.com
WGER_TOKEN=your_wger_api_token_here

# Withings OAuth
WITHINGS_CLIENT_ID=your_client_id
WITHINGS_CLIENT_SECRET=your_client_secret
WITHINGS_REDIRECT_URI=http://localhost:8080/callback

# Optional Settings
DAYS_BACK=7                # How many days to sync from Withings
CALS_GOAL_DEFAULT=1500     # Default calorie goal
```

### Daily Constants

Track recurring meals automatically (e.g., daily protein shake):

```json
{
  "daily_meals": [
    {
      "name": "Morning Protein Shake",
      "enabled": true,
      "calories": 240,
      "protein_g": 50,
      "carbs_g": 6,
      "fat_g": 2,
      "sodium_mg": 150
    }
  ]
}
```

## 🛏️ Sleep Number Integration

### Overview

Automatically sync sleep data from your Sleep Number smart bed to WGER:
- **Sleep Duration** - Total time in bed (hours)
- **Sleep Score** - SleepIQ quality score (0-100)
- **Heart Rate** - Average BPM during sleep
- **Respiratory Rate** - Average breaths per minute

### Setup

1. **Enable Sleep Number sync** in `.env`:
```bash
SLEEPNUMBER_SYNC=true
SLEEPNUMBER_EMAIL=your_sleepnumber_email@example.com
SLEEPNUMBER_PASSWORD=your_sleepnumber_password
```

2. **Ensure asyncsleepiq is installed** (included in `requirements.txt`):
```bash
pip3 install asyncsleepiq
```

3. **Test connection**:
```bash
cd scripts
./daily_health_sync.py manual
# You should see: "✅ Sleep Number: Connected to bed..."
```

### Daily Sync

Sleep data is automatically synced when you run `daily_health_sync.py`:
```bash
./daily_health_sync.py screenshot.png

🛏️ Sleep Number sync:
   - Sleep duration: 7.5 hours
   - Sleep score: 84
   - Heart rate: 63 bpm
   - Respiratory rate: 14 brpm
✅ Posted to WGER
```

### Data Caching

All sleep data is cached locally before posting to WGER:
- **Location**: `sleep_cache/YYYY-MM-DD.json`
- **Purpose**: Never lose data if WGER is down
- **Contents**: Raw API response, timestamps, sleeper info

To re-post cached data:
```bash
./repost_sleep_data.py --all          # Re-post all cached data
./repost_sleep_data.py 2026-02-14     # Re-post specific date
```

### Historical Backfill

Import ALL your historical sleep data (Sleep Number keeps ~2 years):

**From specific date to today:**
```bash
./backfill_sleep_data.py --start 2024-01-01
```

**Last 365 days:**
```bash
./backfill_sleep_data.py --days 365
```

**All available data:**
```bash
./backfill_sleep_data.py --all
```

**Features:**
- ✅ Fetches from Sleep Number API once
- ✅ Caches to local JSON (never lose data!)
- ✅ Posts to WGER from cache (safe retries)
- ✅ Debug logging for audit trail
- ✅ Rate limiting (0.5s between requests)
- ✅ Progress tracking for long backlogs

**Output:**
```
🚀 Starting Sleep Number backfill...
   Start: 2024-01-01
   End:   2026-02-13

🔐 Logging into Sleep Number...
✅ Connected to bed: Master Bed
✅ Sleeper: John (Side.LEFT)

📅 Fetching sleep data for 775 days...
   2024-07-22: ✅ (Score: 86)
   2024-07-23: ✅ (Score: 61)
   2024-07-24: ✅ (Score: 67)
   ...

💾 Cached to: backfill_cache/sleepnumber_2024-01-01_to_2026-02-13.json

📤 Posting to WGER...
   ✅ 2024-07-22: 4 metrics (Score: 86, 7.8h)
   ✅ 2024-07-23: 4 metrics (Score: 61, 8.2h)
   ...

✅ Sleep Number backfill complete!
   Days posted: 210
```

## 📊 Historical Data Backfill

### Withings Historical Sync

Import ALL your historical Withings data (body weight, activity, body composition):

```bash
./backfill_all_data.py --days 365    # Last 365 days
./backfill_all_data.py --start 2024-01-01  # From specific date
```

**What it syncs:**
- Weight measurements
- Body composition (fat %, muscle mass, bone mass, hydration)
- Activity data (steps, distance, calories, elevation)
- Heart rate (if tracked)

**Smart features:**
- Uses local cache to prevent API rate limiting
- Fetches from Withings API once
- Posts to WGER from cache (safe retries)
- Retry logic with timeouts for reliability

## 📱 Daily Workflow

### Evening Routine (15 seconds)

1. **Screenshot your MyFitnessPal daily summary**
2. **AirDrop to your Mac** (or save locally)
3. **Run the sync**:
```bash
./daily_health_sync.py ~/Downloads/mfp_screenshot.png
```

4. **Enter macros when prompted** (if not in screenshot):
```
⚠️  Macros not found in screenshot. Enter manually from MFP:
   Protein (g) [Enter to skip]: 120
   Carbs (g) [Enter to skip]: 85
   Fat (g) [Enter to skip]: 35
   Sodium (mg) [Enter to skip]: 2400
```

5. **Optional: Log digestion** (for scale correlation):
```
💩 Optional: Digestion log (for scale correlation)
Had BM today? (y/n): y
Quality (normal/small-hard/loose): normal
```

That's it! All data is synced to WGER.

## 🤖 ChatGPT Integration

### Get Your Health Data

```bash
curl "https://your-server.com/weight_enhanced.php?format=json&date=2026-02-11"
```

### Paste into ChatGPT and ask:

- **"Have I lost muscle or just fat?"**
- **"Am I eating enough protein?"**
- **"When will I hit 175 lbs at this rate?"**
- **"Why did the scale jump 2 lbs yesterday?"** (checks sodium, digestion)
- **"Is my deficit too aggressive?"**
- **"Show me my 30-day trends"**

ChatGPT can now analyze:
- Weight trends vs body composition
- True calorie deficit (vs TDEE, not just MFP)
- Muscle preservation
- Sodium/water retention patterns
- Digestion correlation with scale fluctuations

## 📊 Data Contract (Guaranteed Units)

All units are **locked and documented** for consistent ChatGPT analysis:

- **Distance**: ALWAYS `km` (never miles or meters)
- **Weight**: ALWAYS `lb` (never kg)
- **Calories**: ALWAYS `kcal` (never kJ)
- **Steps**: ALWAYS whole number (converted from ksteps)
- **Body composition**: `bodyfat_pct` = %, mass values = kg
- **Macros**: protein/carbs/fat = g, sodium = mg
- **Hydration**: ALWAYS `ml` when tracked

## 🏗️ Architecture

```
┌─────────────────┐
│  Withings Scale │ (weight, body fat%, steps, distance)
└────────┬────────┘
         │
         │  Auto-sync (last 7 days)
         ▼
┌─────────────────┐      ┌──────────────────┐
│ MyFitnessPal    │─OCR─▶│ daily_health_    │
│  Screenshot     │      │    sync.py       │
└─────────────────┘      └────────┬─────────┘
                                  │
                         Posts to WGER API
                                  │
                                  ▼
                         ┌─────────────────┐
                         │  WGER Instance  │
                         │  (Measurements) │
                         └────────┬────────┘
                                  │
                          Reads and enhances
                                  │
                                  ▼
                         ┌─────────────────┐
                         │ weight_enhanced │
                         │      .php       │
                         └────────┬────────┘
                                  │
                          Outputs JSON/Text
                                  │
                                  ▼
                         ┌─────────────────┐
                         │    ChatGPT      │
                         │   Analysis      │
                         └─────────────────┘
```

## 🔧 API Endpoints

### Get Health Data

**Endpoint**: `GET /weight_enhanced.php`

**Parameters**:
- `format`: `json`, `text`, or `markdown` (default: `text`)
- `date`: `YYYY-MM-DD` (default: today)

**Examples**:
```bash
# JSON for ChatGPT
curl "https://your-server.com/weight_enhanced.php?format=json&date=2026-02-11"

# Human-readable text
curl "https://your-server.com/weight_enhanced.php?format=text"

# Markdown for documentation
curl "https://your-server.com/weight_enhanced.php?format=markdown"
```

## 📦 Project Structure

```
wger-superman-dashboard/
├── scripts/
│   ├── daily_health_sync.py    # Main sync script
│   └── weight_enhanced.php     # API endpoint
├── config/
│   └── daily_constants.json    # Recurring meals config
├── docs/
│   └── API.md                  # API documentation
├── .env.example                # Environment template
├── .gitignore                  # Git ignore rules
├── requirements.txt            # Python dependencies
├── README.md                   # This file
└── LICENSE                     # MIT License
```

## 🛠️ Development

### Adding New Measurements

1. Create WGER measurement category:
```python
cat_id = wger_get_or_create_category("Vitamin D", "IU")
```

2. Post measurement:
```python
wger_post_measurement(date, cat_id, value, "Manual Entry")
```

3. Add to `weight_enhanced.php` output

### Extending OCR

To extract additional fields from MFP screenshots, update the regex in `parse_mfp_screenshot()`:

```python
# Example: Extract fiber
fiber_match = re.search(r'Fiber\s+(\d+)g', text, re.IGNORECASE)
if fiber_match:
    nutrition['fiber_g'] = int(fiber_match.group(1))
```

## 🤝 Contributing

Contributions welcome! Please:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 🙏 Acknowledgments

- **WGER** - Open-source fitness tracker
- **Withings** - Smart scale integration
- **MyFitnessPal** - Nutrition tracking
- **ChatGPT** - For calling this a "Superman Dashboard" 🦸‍♂️

## 🐛 Troubleshooting

### "No Withings tokens found"
Run the script once in manual mode to complete OAuth:
```bash
./daily_health_sync.py manual
```

### "Steps showing as 273 instead of 13,000"
The script auto-converts ksteps to steps. If you see wrong values, delete old measurements:
```bash
curl -X DELETE "https://your-wger.com/api/v2/measurement/{id}/" \
  -H "Authorization: Token YOUR_TOKEN"
```

### "Body composition shows null"
Make sure you're weighing on a Withings Body+ or Body Comp scale. Basic scales don't provide body composition data.

### "OCR not finding calories"
Make sure your MFP screenshot clearly shows the main calorie number. If OCR fails, the script will prompt for manual entry.

## 📞 Support

- **Issues**: [GitHub Issues](https://github.com/rhcp011235/wger-superman-dashboard/issues)
- **Discussions**: [GitHub Discussions](https://github.com/rhcp011235/wger-superman-dashboard/discussions)

---

**Built with ❤️ for data-driven health optimization**
