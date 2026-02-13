# 🚀 Quick Start Guide

Get up and running in 5 minutes!

## Step 1: Install Dependencies

```bash
# Install Python packages
pip3 install -r requirements.txt

# Install Tesseract (for OCR)
# macOS:
brew install tesseract

# Ubuntu/Debian:
sudo apt-get install tesseract-ocr

# Windows:
# Download from: https://github.com/UB-Mannheim/tesseract/wiki
```

## Step 2: Configure

```bash
# Copy environment template
cp .env.example .env

# Edit with your credentials
nano .env
```

Required values:
- `WGER_BASE_URL` - Your WGER instance URL
- `WGER_TOKEN` - Get from WGER → Settings → API Key
- `WITHINGS_CLIENT_ID` - Get from [Withings Developer Portal](https://developer.withings.com/)
- `WITHINGS_CLIENT_SECRET` - From Withings app

## Step 3: First Run

```bash
cd scripts

# Run with manual entry to set up Withings OAuth
./daily_health_sync.py manual
```

Follow the browser prompts to authorize Withings. This creates `withings_tokens.json`.

## Step 4: Daily Use

```bash
# Take screenshot of MyFitnessPal
# AirDrop to Mac or save locally

# Run sync
./daily_health_sync.py ~/Downloads/mfp_screenshot.png

# Enter macros when prompted
```

## Step 5: View Data

### Local PHP (for development)
```bash
php -S localhost:8000
# Visit: http://localhost:8000/weight_enhanced.php?format=json
```

### Upload to Server
```bash
# Upload PHP file to your web server
scp scripts/weight_enhanced.php user@server:/var/www/html/

# Visit: https://your-server.com/weight_enhanced.php
```

## Step 6: ChatGPT Analysis

1. Get your JSON:
```bash
curl "https://your-server.com/weight_enhanced.php?format=json" > health.json
```

2. Paste into ChatGPT and ask:
   - "Have I lost muscle or fat?"
   - "When will I hit 175 lbs?"
   - "Analyze my trends"

## Troubleshooting

**"No Withings tokens"**
→ Run `./daily_health_sync.py manual` first

**"OCR not finding calories"**
→ Make sure MFP screenshot is clear, or use manual mode

**"403 Forbidden on PHP"**
→ Check server permissions: `chmod 644 weight_enhanced.php`

**"Steps showing wrong"**
→ Old data conflict. Delete category 10 measurements in WGER

## Next Steps

- [ ] Set up daily cron job for automated sync
- [ ] Configure `daily_constants.json` for recurring meals
- [ ] Enable digestion logging for scale correlation
- [ ] Set up GitHub Actions for automated backups

---

**Need help?** Check the [full README](README.md) or open an [issue](https://github.com/yourusername/wger-superman-dashboard/issues).
