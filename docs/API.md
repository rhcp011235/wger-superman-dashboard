# API Documentation

## Overview

The WGER Superman Dashboard provides a RESTful API endpoint that returns comprehensive health data in multiple formats.

## Base Endpoint

```
GET /weight_enhanced.php
```

## Parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `format` | string | No | `text` | Output format: `json`, `text`, or `markdown` |
| `date` | string | No | today | Date in `YYYY-MM-DD` format |

## Response Formats

### JSON (for ChatGPT)

**Request:**
```bash
curl "https://your-server.com/weight_enhanced.php?format=json&date=2026-02-11"
```

**Response:**
```json
{
  "date": "2026-02-11",
  "generated_at": "2026-02-12 02:39:41 UTC",
  "weight": {
    "current_lb": 196.22,
    "date_of_reading": "2026-02-11",
    "trend_7d_lb": 197.29,
    "trend_30d_lb": 201.12,
    "trend_90d_lb": 207.09,
    "total_change_lb": -60.48,
    "earliest_date": "2025-07-27T13:43:00-04:00"
  },
  "energy": {
    "intake_kcal": 930,
    "exercise_mfp_kcal": 804,
    "active_device_kcal": 826.01,
    "goal_intake_kcal": 1500,
    "remaining_mfp_kcal": 1374,
    "net_kcal_vs_goal": 234,
    "net_kcal_vs_tdee": -979
  },
  "activity": {
    "steps": 13570,
    "distance_km": 10.41
  },
  "metabolism": {
    "bmr_kcal": 1750,
    "tdee_kcal": 2713
  },
  "bodycomp": {
    "date": "2026-02-11",
    "weight_kg": 89.0,
    "fat_mass_kg": 25.13,
    "lean_mass_kg": 63.87,
    "bodyfat_pct": 28.24,
    "muscle_mass_kg": 60.96,
    "bone_mass_kg": 3.18,
    "hydration_pct": 58.2
  },
  "bodycomp_trend": {
    "bodyfat_pct_30d": 28.44,
    "bodyfat_pct_90d": 28.44,
    "lean_mass_30d_kg": 65.28,
    "lean_mass_90d_kg": 67.28
  },
  "macros": {
    "protein_g": 170,
    "carbs_g": 91,
    "fat_g": 37,
    "sodium_mg": 2400
  },
  "digestion": {
    "had_bm": true,
    "bm_quality": "normal",
    "notes": null
  },
  "hydration_ml": 2200,
  "workouts": null
}
```

### Text (Human-Readable)

**Request:**
```bash
curl "https://your-server.com/weight_enhanced.php?format=text"
```

**Response:**
```
=== WGER HEALTH DATA ===
Date: 2026-02-11
Generated: 2026-02-12 02:39:41 UTC

--- WEIGHT ---
Current: 196.22 lbs (as of 2026-02-11)
7-day trend: 197.3 lbs
30-day trend: 201.1 lbs
90-day trend: 207.1 lbs
Total change: -60.48 lbs

--- NUTRITION ---
Calories (Food): 930 / 1500 kcal
Exercise Burned: 804 kcal
Remaining: 1374 kcal
Protein: 170 g
Carbs: 91 g
Fat: 37 g
Sodium: 2400 mg

--- MEASUREMENTS ---
Steps: 13570 steps
Distance: 10.41 km
Calories: 826.01 kcal

--- END ---
```

## Data Contract

All fields use **guaranteed units** for consistent analysis:

### Weight
- `current_lb`: Current weight in pounds (ALWAYS lb)
- `trend_Xd_lb`: X-day average weight in pounds
- `total_change_lb`: Total weight change since earliest entry

### Energy (ALWAYS kcal)
- `intake_kcal`: Food calories consumed
- `exercise_mfp_kcal`: Exercise calories from MyFitnessPal
- `active_device_kcal`: Active calories from Apple Watch/Withings
- `goal_intake_kcal`: Daily calorie goal
- `remaining_mfp_kcal`: Remaining calories (goal - intake + exercise)
- `net_kcal_vs_goal`: Surplus/deficit vs MFP goal
- `net_kcal_vs_tdee`: **True deficit** vs actual TDEE

### Activity
- `steps`: Daily step count (whole number)
- `distance_km`: Distance walked (ALWAYS km, never miles)

### Metabolism (ALWAYS kcal)
- `bmr_kcal`: Basal Metabolic Rate (Mifflin-St Jeor equation)
- `tdee_kcal`: Total Daily Energy Expenditure (BMR × activity factor)

### Body Composition
- `weight_kg`: Weight in kilograms
- `fat_mass_kg`: Fat mass calculated from bodyfat% × weight
- `lean_mass_kg`: Lean mass (weight - fat_mass)
- `bodyfat_pct`: Body fat percentage from scale
- `muscle_mass_kg`: Muscle mass from smart scale
- `bone_mass_kg`: Bone mass from smart scale
- `hydration_pct`: Hydration percentage

### Body Composition Trends
- `bodyfat_pct_30d`: 30-day average body fat %
- `bodyfat_pct_90d`: 90-day average body fat %
- `lean_mass_30d_kg`: 30-day average lean mass
- `lean_mass_90d_kg`: 90-day average lean mass

### Macros
- `protein_g`: Protein in grams
- `carbs_g`: Carbohydrates in grams
- `fat_g`: Fat in grams
- `sodium_mg`: Sodium in milligrams

### Digestion (optional)
- `had_bm`: Boolean - bowel movement today
- `bm_quality`: String - "normal", "small-hard", "loose", etc.
- `notes`: Optional notes

### Hydration
- `hydration_ml`: Water intake in milliliters (ALWAYS ml)

## Error Responses

### 404 Not Found
```json
{
  "error": "No data found for date 2026-01-01"
}
```

### 500 Internal Server Error
```json
{
  "error": "Failed to connect to WGER API"
}
```

## Rate Limits

No rate limits currently enforced. Recommended max: 60 requests/minute.

## Authentication

Currently no authentication required for read-only access. If deploying publicly, add authentication via:
- HTTP Basic Auth
- API key in header
- OAuth 2.0

## Changelog

### v1.0.0 (2026-02-11)
- Initial release
- JSON/text/markdown output formats
- Full body composition tracking
- Trend calculations
- ChatGPT-optimized structure
