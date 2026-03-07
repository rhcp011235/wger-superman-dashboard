# 🦸‍♂️ WGER Superman Dashboard

> Your complete health tracking system with AI-powered insights and a Matrix-themed web dashboard

A comprehensive health data aggregation and analysis platform that syncs Withings devices, MyFitnessPal nutrition, Sleep Number smart beds, and WGER fitness tracking into a unified dashboard — with a full SVG chart suite and ChatGPT-optimized JSON API.

## ✨ Features

- **📊 Matrix-Themed Web Dashboard** — All health metrics visualised in green-on-black SVG charts
- **📈 Per-Metric Graph Pages** — Deep-dive any of 20 tracked variables with custom date ranges
- **🔄 Auto-Sync from Multiple Sources**:
  - Withings smart scales (weight, body composition, steps, distance)
  - MyFitnessPal (nutrition, macros via OCR screenshot)
  - Sleep Number smart bed (sleep score, HRV, HR, respiratory rate)
  - WGER fitness tracker (workouts, measurements)
- **🤖 AI-Ready JSON Output** — Optimized for ChatGPT health analysis
- **📈 Trend Tracking** — 7/30/90-day trends for weight, body composition
- **💪 Muscle vs Fat Loss** — Track lean mass and fat mass separately
- **🎯 Smart Calculations**:
  - BMR/TDEE estimation (Mifflin-St Jeor equation)
  - True calorie deficit (vs TDEE, not just MFP goal)
  - Net calories after exercise
- **🔐 Privacy-First** — All data stays on your own server

---

## 📸 Web Dashboard

Four pages served from your own PHP-capable web server:

| Page | URL | Description |
|------|-----|-------------|
| **Landing** | `/index.php` | Matrix rain landing page with live weight stat |
| **Daily Dashboard** | `/weight.php` | Full health snapshot for any date |
| **All Charts** | `/charts.php?days=90` | 20 metrics in a grid, 30/60/90/365-day ranges |
| **Graph Metric** | `/graph.php?metric=muscle_mass&days=90` | Single-metric deep-dive |

### Available Metrics for `graph.php`

`weight` · `body_fat` · `muscle_mass` · `bone_mass` · `hydration` · `bmr` · `metabolic_age` · `visceral_fat` · `steps` · `distance` · `calories` · `protein` · `carbs` · `fat` · `exercise_calories` · `sleep_score` · `sleep_duration` · `sleep_hrv` · `sleep_hr` · `sleep_rr`

### Web Setup

1. Copy `web/` to your PHP-capable web root (Apache, Nginx, Caddy, etc.)
2. Edit the two config lines at the top of **each** PHP file:
```php
$WGER_BASE  = 'https://your-wger-instance.com'; // ← your WGER URL
$WGER_TOKEN = 'your_wger_api_token_here';        // ← WGER > Account > API Key
```
3. `matrix-voice.mp3` is included — place it alongside the PHP files for the landing page audio

---

## 🚀 Quick Start

### Prerequisites

- Python 3.8+
- PHP 7.4+ with cURL extension (for web dashboard)
- WGER instance (self-hosted or cloud)
- Withings account with developer app
- MyFitnessPal account (free tier OK)
- Sleep Number smart bed (optional)

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

6. **Deploy web dashboard** (optional)
```bash
cp web/* /your/web/root/
# Edit $WGER_BASE and $WGER_TOKEN in each PHP file
```

---

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

# Sleep Number (optional)
SLEEPNUMBER_SYNC=true
SLEEPNUMBER_EMAIL=your_sleepnumber_email@example.com
SLEEPNUMBER_PASSWORD=your_sleepnumber_password

# Optional Settings
DAYS_BACK=7                # How many days to sync from Withings
CALS_GOAL_DEFAULT=1500     # Default calorie goal
```

### PHP Web Dashboard Config

Each PHP file has two lines to set at the top — no `.env` involved, credentials live server-side only:

```php
$WGER_BASE  = 'https://your-wger-instance.com';
$WGER_TOKEN = 'your_wger_api_token_here';
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

---

## 🛏️ Sleep Number Integration

Automatically syncs sleep data from your Sleep Number smart bed to WGER:
- **Sleep Duration** — Total time in bed (hours)
- **Sleep Score** — SleepIQ quality score (0–100)
- **Heart Rate** — Average BPM during sleep
- **HRV** — Heart rate variability (ms)
- **Respiratory Rate** — Average breaths per minute

### Setup

Enable in `.env`:
```bash
SLEEPNUMBER_SYNC=true
SLEEPNUMBER_EMAIL=your_sleepnumber_email@example.com
SLEEPNUMBER_PASSWORD=your_sleepnumber_password
```

### Historical Backfill

```bash
./scripts/backfill_sleep_data.py --start 2024-01-01   # From date
./scripts/backfill_sleep_data.py --days 365            # Last N days
./scripts/backfill_sleep_data.py --all                 # All available
```

### HRV Backfill

If you have existing sleep data in WGER without HRV, backfill it separately:
```bash
./scripts/backfill_hrv.py --days 90    # Last 90 days from Sleep Number
./scripts/backfill_hrv.py --all        # All available
```

---

## 📊 Historical Data Backfill

### Withings Historical Sync

```bash
./scripts/backfill_all_data.py --days 365
./scripts/backfill_all_data.py --start 2024-01-01
```

**What it syncs:**
- Weight measurements
- Body composition (fat %, muscle mass, bone mass, hydration)
- Activity data (steps, distance, calories)
- Heart rate

---

## 📱 Daily Workflow

### Evening Routine (~15 seconds)

1. Screenshot your MyFitnessPal daily summary
2. AirDrop to Mac (or save locally)
3. Run:
```bash
./scripts/daily_health_sync.py ~/Downloads/mfp_screenshot.png
```

Output:
```
✅ Withings sync complete: 24 activities, 13 weights, 7 body comp
🛏️ Sleep Number: Score 84 · 7.5h · HR 63bpm · HRV 28ms
✅ MFP nutrition posted!
🎉 ALL DATA SYNCED!

View at: https://your-wger-instance.com/weight.php
```

---

## 🤖 ChatGPT Integration

```bash
curl "https://your-server.com/weight.php?format=json&date=2026-02-11"
```

Paste the output into ChatGPT and ask:

- *"Have I lost muscle or just fat?"*
- *"Am I eating enough protein?"*
- *"When will I hit 175 lbs at this rate?"*
- *"Why did the scale jump 2 lbs yesterday?"*
- *"Is my deficit too aggressive?"*

---

## 📊 Data Contract (Guaranteed Units)

| Metric | Unit | Notes |
|--------|------|-------|
| Distance | km | Never miles or meters |
| Weight | lbs | Never kg |
| Calories | kcal | Never kJ |
| Steps | whole number | Converted from ksteps |
| Body fat | % | — |
| Mass (muscle, bone) | lbs | Converted from kg |
| Hydration | % | — |
| Macros | g | protein/carbs/fat |
| Sodium | mg | — |
| HRV | ms | — |

---

## 🏗️ Architecture

```
┌──────────────────┐   ┌─────────────────┐   ┌──────────────────┐
│  Withings Scale  │   │  MFP Screenshot │   │  Sleep Number    │
│  (body comp,     │   │  (OCR nutrition │   │  (sleep score,   │
│   steps, weight) │   │   + macros)     │   │   HRV, HR, RR)   │
└────────┬─────────┘   └────────┬────────┘   └────────┬─────────┘
         │                      │                      │
         └──────────────────────┼──────────────────────┘
                                │
                    daily_health_sync.py
                                │
                         WGER API (REST)
                                │
                    ┌───────────┴───────────┐
                    │                       │
             weight.php               JSON/text API
           charts.php              (ChatGPT analysis)
            graph.php
            index.php
```

## 📦 Project Structure

```
wger-superman-dashboard/
├── web/
│   ├── index.php               # Matrix landing page
│   ├── weight.php              # Daily health dashboard
│   ├── charts.php              # All-metrics chart grid
│   ├── graph.php               # Single-metric deep-dive
│   └── matrix-voice.mp3        # Landing page ambient audio
├── scripts/
│   ├── daily_health_sync.py    # Main sync script
│   ├── backfill_all_data.py    # Withings historical backfill
│   ├── backfill_sleep_data.py  # Sleep Number historical backfill
│   ├── backfill_hrv.py         # HRV-specific backfill
│   ├── repost_sleep_data.py    # Re-post cached sleep data
│   └── favicon.svg             # Dashboard favicon
├── config/
│   └── daily_constants.json.example
├── docs/
│   └── API.md
├── .env.example
├── .gitignore
├── requirements.txt
├── QUICKSTART.md
├── README.md
└── LICENSE
```

---

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

3. Add the slug to the `$METRICS` registry in `graph.php` and the group in `charts.php`

---

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes
4. Push and open a Pull Request

## 📄 License

MIT License — see [LICENSE](LICENSE)

## 🙏 Acknowledgments

- **WGER** — Open-source fitness tracker
- **Withings** — Smart scale integration
- **MyFitnessPal** — Nutrition tracking
- **asyncsleepiq** — Sleep Number API client
- **ChatGPT** — For calling this a "Superman Dashboard" 🦸‍♂️

---

## 🐛 Troubleshooting

**"No Withings tokens found"**
```bash
./scripts/daily_health_sync.py manual
```

**"Steps showing as 273 instead of 13,000"**
The script stores ksteps in WGER (4-digit limit). The web dashboard converts back to full steps for display.

**"Body composition shows null"**
Requires a Withings Body+ or Body Comp scale. Basic scales don't provide body composition data.

**"OCR not finding calories"**
Make sure the MFP screenshot clearly shows the main calorie number. The script will prompt for manual entry if OCR fails.

**Issues / Discussions**: [GitHub Issues](https://github.com/rhcp011235/wger-superman-dashboard/issues)

---

*Built with ❤️ for data-driven health optimization*
