<?php
/**
 * Enhanced WGER Data Feed - ChatGPT-Optimized Health Dashboard
 *
 * Data Contract (units are locked and guaranteed):
 * - Distance: ALWAYS miles (never km or meters)
 * - Weight: ALWAYS lbs (never kg)
 * - Calories: ALWAYS kcal (never kJ)
 * - Steps: ALWAYS whole number (converted from ksteps)
 * - Body composition: bodyfat_pct = %, mass values = lbs (converted from kg), hydration = %
 * - Hydration: ALWAYS ml when tracked
 *
 * Output formats: ?format=html (default), text, json, markdown
 * Date parameter: ?date=YYYY-MM-DD (defaults to today if synced, yesterday if not)
 * Multi-day export: ?export=json&days=30 or ?export=csv&days=30
 *   - export=json: Export multiple days as JSON array
 *   - export=csv: Export multiple days as downloadable CSV file
 *   - days=N: Number of days to export (default 30, counts backward from date parameter)
 */
declare(strict_types=1);

// Set timezone to Eastern Time for all date/time displays
date_default_timezone_set('America/New_York');

header('Content-Type: text/plain; charset=utf-8');

// PRIVATE VERSION - Hardcoded credentials (DO NOT commit to git!)
$WGER_BASE  = getenv('WGER_BASE') ?: 'https://your-wger-instance.com';
$WGER_TOKEN = getenv('WGER_TOKEN') ?: 'your_wger_api_token_here';

$export = $_GET['export'] ?? null; // json or csv for multi-day export
$days = isset($_GET['days']) ? (int)$_GET['days'] : 30; // Number of days to export (default 30)
$chart_days = isset($_GET['chart_days']) ? max(7, min(365, (int)$_GET['chart_days'])) : 30; // Days for weight chart (default 30, min 7, max 365)

// Smart default: use today if it has data (sync already ran), otherwise yesterday
if (isset($_GET['date'])) {
  $date = $_GET['date'];
} else {
  $today = date('Y-m-d');
  $check = wger_get($WGER_BASE, $WGER_TOKEN, '/api/v2/weightentry/', ['date' => $today, 'limit' => 1]);
  $date  = !empty($check['results']) ? $today : date('Y-m-d', strtotime('yesterday'));
}
$requestedDate = $date;
$format = $_GET['format'] ?? 'html'; // html (default), text, json, or markdown

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

function wger_get(string $base, string $token, string $path, array $query = []): array {
  $url = $base . $path . (!empty($query) ? ('?' . http_build_query($query)) : '');

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => [
      'Accept: application/json',
      'Authorization: Token ' . $token,
    ],
  ]);

  $body = curl_exec($ch);
  $err  = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  // curl_close() is deprecated in PHP 8.5+

  if ($body === false) throw new RuntimeException("cURL error: $err");
  if ($code < 200 || $code >= 300) throw new RuntimeException("HTTP $code for $path: " . substr((string)$body, 0, 200));

  $json = json_decode((string)$body, true);
  if (!is_array($json)) throw new RuntimeException("Invalid JSON from $path");
  return $json;
}

function try_number(mixed $v): ?float {
  if ($v === null) return null;
  if (is_int($v) || is_float($v)) return (float)$v;
  if (is_string($v) && is_numeric($v)) return (float)$v;
  return null;
}

function extract_date(string $datetime): string {
  // Extract YYYY-MM-DD from ISO 8601 datetime like "2026-02-11T07:00:00-05:00"
  if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $datetime, $m)) {
    return $m[1];
  }
  return $datetime;
}

function calculate_trend(array $entries, string $endDate, int $days, string $field = 'weight'): ?float {
  $endTs = strtotime($endDate . ' 23:59:59');
  if ($endTs === false) return null;

  $startTs = $endTs - ($days - 1) * 86400;
  $vals = [];

  foreach ($entries as $e) {
    if (!isset($e['date'], $e[$field])) continue;
    $entryDate = extract_date($e['date']);
    $ts = strtotime($entryDate . ' 12:00:00');
    if ($ts === false) continue;
    if ($ts >= $startTs && $ts <= $endTs) {
      $vals[] = (float)$e[$field];
    }
  }

  return !empty($vals) ? (array_sum($vals) / count($vals)) : null;
}

/**
 * Collect essential health data for a specific date (used for multi-day exports).
 * $categoryNameToIds: pre-built map of category name => [id, ...] (pass once, reuse per day)
 */
function collect_day_data(string $date, string $base, string $token, array $categoryNameToIds = []): array {
  $data = ['date' => $date];

  // Helper: fetch first measurement value for a category+date
  $getMeasVal = function(string $catName) use ($base, $token, $date, $categoryNameToIds): ?float {
    foreach ($categoryNameToIds[$catName] ?? [] as $catId) {
      try {
        $resp = wger_get($base, $token, '/api/v2/measurement/', [
          'category' => $catId, 'date' => $date, 'limit' => 1,
        ]);
        $val = try_number($resp['results'][0]['value'] ?? null);
        if ($val !== null) return $val;
      } catch (Throwable $e) {}
    }
    return null;
  };

  // --------------------------------------------------------------------------
  // Weight: exact date match, fall back to most recent
  // --------------------------------------------------------------------------
  try {
    $weightResp = wger_get($base, $token, '/api/v2/weightentry/', ['limit' => 365, 'ordering' => '-date']);
    $weightEntries = $weightResp['results'] ?? [];
    $weight_lb = null;
    $weight_date_actual = $date;
    foreach ($weightEntries as $w) {
      if (extract_date($w['date'] ?? '') === $date) {
        $weight_lb = try_number($w['weight']);
        $weight_date_actual = $date;
        break;
      }
    }
    if ($weight_lb === null && !empty($weightEntries)) {
      $weight_lb = try_number($weightEntries[0]['weight']);
      $weight_date_actual = extract_date($weightEntries[0]['date'] ?? $date);
    }
    $data['weight'] = ['current_lb' => $weight_lb, 'date_of_reading' => $weight_date_actual];
  } catch (Throwable $e) {
    $data['weight'] = ['current_lb' => null, 'date_of_reading' => null];
  }

  // --------------------------------------------------------------------------
  // Nutrition (all 18+ fields from MFP measurement categories)
  // --------------------------------------------------------------------------
  $nutritionFields = [
    'calories'               => 'Daily Calories',
    'exercise_cals'          => 'MFP Exercise Calories',
    'protein_g'              => 'Daily Protein',
    'carbs_g'                => 'Daily Carbs',
    'fat_g'                  => 'Daily Fat',
    'sodium_mg'              => 'Daily Sodium',
    'fiber_g'                => 'Daily Fiber',
    'sugar_g'                => 'Daily Sugar',
    'saturated_fat_g'        => 'Saturated Fat',
    'polyunsaturated_fat_g'  => 'Polyunsaturated Fat',
    'monounsaturated_fat_g'  => 'Monounsaturated Fat',
    'trans_fat_g'            => 'Trans Fat',
    'cholesterol_mg'         => 'Cholesterol',
    'potassium_mg'           => 'Potassium',
    'vitamin_a_iu'           => 'Vitamin A',
    'vitamin_c_mg'           => 'Vitamin C',
    'calcium_mg'             => 'Calcium',
    'iron_mg'                => 'Iron',
  ];
  $nutrition = [];
  foreach ($nutritionFields as $field => $catName) {
    $val = $getMeasVal($catName);
    $nutrition[$field] = ($val !== null && $val > 0) ? $val : 0;
  }
  $goal_cals = 1500;
  $nutrition['goal_calories']      = $goal_cals;
  $nutrition['remaining_calories'] = $goal_cals - $nutrition['calories'] + $nutrition['exercise_cals'];
  $data['nutrition'] = $nutrition;

  // --------------------------------------------------------------------------
  // Sleep (from WGER measurement categories)
  // --------------------------------------------------------------------------
  $sleepFields = [
    'duration_hours'            => 'Sleep Duration',
    'sleep_score'               => 'Sleep Score',
    'avg_heart_rate_bpm'        => 'Sleep Heart Rate',
    'avg_hrv_ms'                => 'Sleep HRV',
    'avg_respiratory_rate_brpm' => 'Sleep Respiratory Rate',
  ];
  $sleep = [];
  $hasSleep = false;
  foreach ($sleepFields as $field => $catName) {
    $val = $getMeasVal($catName);
    $sleep[$field] = $val;
    if ($val !== null) $hasSleep = true;
  }
  $data['sleep'] = $hasSleep ? $sleep : null;

  // --------------------------------------------------------------------------
  // Activity (steps + distance)
  // --------------------------------------------------------------------------
  $stepsKsteps = $getMeasVal('Steps');
  $distanceKm  = $getMeasVal('Distance');
  $data['activity'] = [
    'steps'       => $stepsKsteps !== null ? (int)round($stepsKsteps * 1000) : null,
    'distance_mi' => $distanceKm  !== null ? round($distanceKm * 0.621371, 2)  : null,
  ];

  // --------------------------------------------------------------------------
  // Body composition (from Withings measurement categories)
  // --------------------------------------------------------------------------
  $bodyFatPct   = $getMeasVal('Body Fat');
  $muscleMassKg = $getMeasVal('Muscle Mass');
  $boneMassKg   = $getMeasVal('Bone Mass');
  $bmrWithings  = $getMeasVal('Basal Metabolic Rate');
  $metabAge     = $getMeasVal('Metabolic Age');
  $visceralFat  = $getMeasVal('Visceral Fat');
  $hydration    = $getMeasVal('Hydration');
  $data['bodycomp'] = [
    'body_fat_pct'       => $bodyFatPct,
    'muscle_mass_lbs'    => $muscleMassKg !== null ? round($muscleMassKg * 2.20462, 2) : null,
    'bone_mass_lbs'      => $boneMassKg   !== null ? round($boneMassKg   * 2.20462, 2) : null,
    'bmr_withings_kcal'  => $bmrWithings,
    'metabolic_age'      => $metabAge,
    'visceral_fat_index' => $visceralFat,
    'hydration_pct'      => $hydration,
  ];

  // --------------------------------------------------------------------------
  // Vitals (from Apple Health / Health Auto Export)
  // --------------------------------------------------------------------------
  $data['vitals'] = [
    'resting_hr_bpm'       => $getMeasVal('Resting Heart Rate'),
    'active_calories_kcal' => $getMeasVal('AH Active Calories'),
  ];

  // --------------------------------------------------------------------------
  // Metabolism: calculated BMR / TDEE / deficit
  // --------------------------------------------------------------------------
  if ($data['weight']['current_lb']) {
    $weight_kg = $data['weight']['current_lb'] / 2.20462;
    $bmr  = 10 * $weight_kg + 6.25 * 172 - 5 * 44 + 5; // Mifflin-St Jeor, male age 44, 172cm
    $tdee = $bmr * 1.55;
    $deficit = $tdee - $nutrition['calories'];
    $data['metabolism'] = [
      'bmr_kcal'     => round($bmr, 0),
      'tdee_kcal'    => round($tdee, 0),
      'deficit_kcal' => round($deficit, 0),
    ];
  } else {
    $data['metabolism'] = ['bmr_kcal' => null, 'tdee_kcal' => null, 'deficit_kcal' => null];
  }

  return $data;
}

// ============================================================================
// DATA COLLECTION
// ============================================================================

try {
  $data = [
    'date' => $date,
    'generated_at' => date('Y-m-d H:i:s T'), // Local time with timezone
  ];

  // ---------------------------------------------------------------------------
  // 1. WEIGHT DATA
  // ---------------------------------------------------------------------------
  $weightResp = wger_get($WGER_BASE, $WGER_TOKEN, '/api/v2/weightentry/', [
    'limit' => 365,
    'ordering' => '-date',
  ]);
  $weightEntries = $weightResp['results'] ?? [];

  $weight_lb = null;
  $weight_date_actual = $date;

  foreach ($weightEntries as $w) {
    $wDate = extract_date($w['date'] ?? '');
    if ($wDate === $date) {
      $weight_lb = try_number($w['weight']);
      $weight_date_actual = $wDate;
      break;
    }
  }

  // Fallback to latest if no weight on requested date
  if ($weight_lb === null && !empty($weightEntries)) {
    $weight_lb = try_number($weightEntries[0]['weight']);
    $weight_date_actual = extract_date($weightEntries[0]['date'] ?? $date);
  }

  $data['weight'] = [
    'current_lb' => $weight_lb,
    'date_of_reading' => $weight_date_actual,
    'trend_7d_lb' => calculate_trend($weightEntries, $date, 7),
    'trend_30d_lb' => calculate_trend($weightEntries, $date, 30),
    'trend_90d_lb' => calculate_trend($weightEntries, $date, 90),
    'total_entries' => count($weightEntries),
  ];

  // Build weight chart data from already-fetched entries (no extra API call)
  $chart_cutoff = date('Y-m-d', strtotime("-{$chart_days} days", strtotime($date)));
  $weight_chart_points = [];
  foreach ($weightEntries as $w) {
    $wDate = extract_date($w['date'] ?? '');
    if ($wDate >= $chart_cutoff && $wDate <= $date) {
      $lb = try_number($w['weight']);
      if ($lb !== null) {
        $weight_chart_points[$wDate] = round($lb, 1); // already in lbs
      }
    }
  }
  ksort($weight_chart_points); // oldest → newest
  $data['weight_chart'] = $weight_chart_points;
  $data['weight_chart_days'] = $chart_days;

  // Calculate weight loss rate from chart window (lbs/week)
  $wc_vals  = array_values($weight_chart_points);
  $wc_dates = array_keys($weight_chart_points);
  $wc_n     = count($wc_vals);
  if ($wc_n >= 2) {
    $wc_days_span = (strtotime($wc_dates[$wc_n - 1]) - strtotime($wc_dates[0])) / 86400;
    if ($wc_days_span > 0) {
      // Positive = losing weight (start heavier than end)
      $wc_rate = ($wc_vals[0] - $wc_vals[$wc_n - 1]) / $wc_days_span * 7;
      $data['weight']['rate_lbs_per_week'] = round($wc_rate, 2);
    }
  }

  // Weight change calculations from fitness journey starting point
  // Starting point: August 12, 2025 at 265 lbs (decision to lose weight)
  $fitness_start_date = '2025-08-12';
  $fitness_start_weight = 265.0;

  if ($weight_lb !== null) {
    // Find actual weight entry on or near starting date
    $starting_weight = $fitness_start_weight;
    foreach ($weightEntries as $w) {
      $wDate = extract_date($w['date'] ?? '');
      if ($wDate === $fitness_start_date) {
        $found_weight = try_number($w['weight']);
        // Use the 265 lb entry if it exists, otherwise use the default
        if ($found_weight !== null && abs($found_weight - 265.0) < 1) {
          $starting_weight = $found_weight;
          break;
        }
      }
    }

    $data['weight']['total_change_lb'] = round($weight_lb - $starting_weight, 2);
    $data['weight']['starting_weight_lb'] = $starting_weight;
    $data['weight']['starting_date'] = $fitness_start_date;
  }

  // ---------------------------------------------------------------------------
  // 2. NUTRITION DATA (Calories + Macros + Sodium)
  // ---------------------------------------------------------------------------
  // Read nutrition data from WGER measurements (MFP CSV import)
  // Category IDs (checking both old and new):
  //   Old: 18, 19, 20, 21, 36, 22
  //   New: 78, 79, 80, 81, 82, 22
  //   Names: Daily Calories, Daily Protein, Daily Carbs, Daily Fat, Daily Sodium, MFP Exercise Calories

  $nutrition = [
    // Core macros (always displayed)
    'calories' => 0,
    'protein_g' => 0,
    'carbs_g' => 0,
    'fat_g' => 0,
    'sodium_mg' => 0,
    'exercise_cals' => 0,
    // Additional macros (displayed if > 0)
    'fiber_g' => 0,
    'sugar_g' => 0,
    'saturated_fat_g' => 0,
    'polyunsaturated_fat_g' => 0,
    'monounsaturated_fat_g' => 0,
    'trans_fat_g' => 0,
    'cholesterol_mg' => 0,
    'potassium_mg' => 0,
    // Vitamins & Minerals (displayed if > 0)
    'vitamin_a_iu' => 0,
    'vitamin_c_mg' => 0,
    'calcium_mg' => 0,
    'iron_mg' => 0,
    'source' => 'measurements',
  ];

  // Fetch nutrition measurements from WGER
  // Uses category NAMES to be more robust (finds categories created by CSV import)
  $nutritionCategoryNames = [
    'calories' => 'Daily Calories',
    'protein_g' => 'Daily Protein',
    'carbs_g' => 'Daily Carbs',
    'fat_g' => 'Daily Fat',
    'sodium_mg' => 'Daily Sodium',
    'exercise_cals' => 'MFP Exercise Calories',
    'fiber_g' => 'Daily Fiber',
    'sugar_g' => 'Daily Sugar',
    'saturated_fat_g' => 'Saturated Fat',
    'polyunsaturated_fat_g' => 'Polyunsaturated Fat',
    'monounsaturated_fat_g' => 'Monounsaturated Fat',
    'trans_fat_g' => 'Trans Fat',
    'cholesterol_mg' => 'Cholesterol',
    'potassium_mg' => 'Potassium',
    'vitamin_a_iu' => 'Vitamin A',
    'vitamin_c_mg' => 'Vitamin C',
    'calcium_mg' => 'Calcium',
    'iron_mg' => 'Iron',
  ];

  // Get all categories once (handles duplicate names by storing all IDs)
  try {
    $categoriesResp = wger_get($WGER_BASE, $WGER_TOKEN, '/api/v2/measurement-category/', ['limit' => 200]);
    $categoryNameToIds = [];
    foreach ($categoriesResp['results'] ?? [] as $cat) {
      $name = $cat['name'];
      if (!isset($categoryNameToIds[$name])) {
        $categoryNameToIds[$name] = [];
      }
      $categoryNameToIds[$name][] = $cat['id'];
    }
  } catch (Throwable $e) {
    $categoryNameToIds = [];
  }

  // Fetch each nutrition field by name (tries all matching category IDs)
  foreach ($nutritionCategoryNames as $field => $categoryName) {
    $categoryIds = $categoryNameToIds[$categoryName] ?? [];
    if (empty($categoryIds)) continue;

    // Try each category ID until we find data
    foreach ($categoryIds as $categoryId) {
      try {
        $measurementResp = wger_get($WGER_BASE, $WGER_TOKEN, '/api/v2/measurement/', [
          'category' => $categoryId,
          'date' => $date,
          'limit' => 1,
        ]);

        $results = $measurementResp['results'] ?? [];
        if (!empty($results)) {
          $value = try_number($results[0]['value'] ?? null) ?? 0;
          if ($value > 0) {
            $nutrition[$field] = $value;
            break; // Found data, stop checking other category IDs
          }
        }
      } catch (Throwable $e) {
        // Continue on error
      }
    }
  }

  $data['nutrition'] = $nutrition;

  // Get calorie goal
  // Get calorie goal (priority: env var > hardcoded 1500 > API)
  // This matches your MFP goal of 1500 kcal
  $cals_goal = try_number(getenv('CALS_GOAL_DEFAULT') ?: null);

  if ($cals_goal === null) {
    // Hardcoded to match your MFP goal
    // Change this value if your goal changes
    $cals_goal = 1500;
  }

  $data['nutrition']['goal_calories'] = $cals_goal;

  // Calculate remaining: Goal - Food + Exercise (matches MFP formula)
  // Example: 1,500 - 930 + 804 = 1,374 kcal remaining
  $data['nutrition']['remaining_calories'] = $data['nutrition']['goal_calories']
    ? ($data['nutrition']['goal_calories'] - $data['nutrition']['calories'] + $data['nutrition']['exercise_cals'])
    : null;

  // ---------------------------------------------------------------------------
  // 3. WORKOUT/EXERCISE DATA
  // ---------------------------------------------------------------------------
  try {
    $workoutResp = wger_get($WGER_BASE, $WGER_TOKEN, '/api/v2/workout/', ['limit' => 100]);
    $sessionResp = wger_get($WGER_BASE, $WGER_TOKEN, '/api/v2/workoutsession/', [
      'limit' => 100,
      'ordering' => '-date',
    ]);

    $sessions = $sessionResp['results'] ?? [];
    $todaySessions = array_filter($sessions, function($s) use ($date) {
      $sDate = extract_date($s['date'] ?? '');
      return $sDate === $date;
    });

    $workoutData = [
      'sessions_today' => count($todaySessions),
      'sessions' => [],
      'total_exercises' => 0,
      'total_sets' => 0,
      'total_reps' => 0,
      'total_volume_kg' => 0,
    ];

    foreach ($todaySessions as $session) {
      $sessionInfo = [
        'workout_id' => $session['workout'] ?? null,
        'time' => $session['time_start'] ?? null,
        'duration' => $session['time_end'] ?? null,
        'impression' => $session['impression'] ?? null,
        'notes' => $session['notes'] ?? null,
      ];

      // Try to get workout details
      if (isset($session['workout'])) {
        try {
          $workoutDetail = wger_get($WGER_BASE, $WGER_TOKEN, "/api/v2/workout/{$session['workout']}/", []);
          $sessionInfo['workout_name'] = $workoutDetail['name'] ?? 'Unnamed';
        } catch (Throwable $e) {
          $sessionInfo['workout_name'] = 'Unknown';
        }
      }

      $workoutData['sessions'][] = $sessionInfo;
    }

    $data['workouts'] = $workoutData;
  } catch (Throwable $e) {
    // Workout endpoint not available in this WGER instance - skip silently
    // Future structure when API is available:
    // "workouts": {
    //   "total_duration_min": 92,
    //   "sessions": [
    //     { "type": "walk", "duration_min": 77, "calories": 800 }
    //   ]
    // }
    $data['workouts'] = null;
  }

  // ---------------------------------------------------------------------------
  // 4. ALL BODY MEASUREMENTS
  // ---------------------------------------------------------------------------
  try {
    $categoryResp = wger_get($WGER_BASE, $WGER_TOKEN, '/api/v2/measurement-category/', ['limit' => 200]);
    $categories = $categoryResp['results'] ?? [];

    $measurementResp = wger_get($WGER_BASE, $WGER_TOKEN, '/api/v2/measurement/', [
      'limit' => 500,
      'ordering' => '-date',
    ]);
    $measurements = $measurementResp['results'] ?? [];

    // Filter for requested date
    $todayMeasurements = array_filter($measurements, function($m) use ($date) {
      $mDate = extract_date($m['date'] ?? '');
      return $mDate === $date;
    });

    $measurementData = [
      'date' => $date,
      'categories' => [],
    ];

    // Map category IDs to names and units
    $categoryMap = [];
    foreach ($categories as $cat) {
      $categoryMap[$cat['id']] = [
        'name' => $cat['name'] ?? 'Unknown',
        'unit' => $cat['unit'] ?? '',
      ];
    }

    foreach ($todayMeasurements as $m) {
      $catId = $m['category'] ?? null;
      $catInfo = $categoryMap[$catId] ?? ['name' => "Category_$catId", 'unit' => ''];
      $catName = $catInfo['name'];
      $unit = $catInfo['unit'];
      $value = try_number($m['value'] ?? null);

      if ($value !== null) {
        // Skip old "Steps" category with "steps" unit (use ksteps only)
        if ($catName === 'Steps' && $unit === 'steps') {
          continue;
        }

        // Convert ksteps to steps for better readability
        if ($catName === 'Steps' && $unit === 'ksteps') {
          $value = $value * 1000;
          $unit = 'steps';
        }

        // Convert metric to imperial units for American users
        if ($catName === 'Bone Mass' && $unit === 'kg') {
          $value = $value * 2.20462;
          $unit = 'lbs';
        }

        if ($catName === 'Muscle Mass' && $unit === 'kg') {
          $value = $value * 2.20462;
          $unit = 'lbs';
        }

        if ($catName === 'Distance' && $unit === 'km') {
          $value = round($value * 0.621371, 2);
          $unit = 'mi';
        }

        $measurementData['categories'][$catName] = [
          'value' => $value,
          'unit' => $unit,
        ];
      }
    }

    $data['measurements'] = $measurementData;
  } catch (Throwable $e) {
    $data['measurements'] = ['error' => $e->getMessage()];
  }

  // ---------------------------------------------------------------------------
  // 5. HYDRATION (if tracked in measurements)
  // ---------------------------------------------------------------------------
  // Unit: ALWAYS ml (milliliters)
  // Example: "hydration_ml": 2200 means 2.2 liters consumed
  $water_ml = null;
  if (isset($data['measurements']['categories'])) {
    foreach ($data['measurements']['categories'] as $name => $info) {
      if (stripos($name, 'water') !== false || stripos($name, 'hydration') !== false) {
        $water_ml = $info['value'];
        break;
      }
    }
  }
  $data['hydration_ml'] = $water_ml;

  // ---------------------------------------------------------------------------
  // SMART FALLBACK: If no nutrition data for requested date, use most recent
  // ---------------------------------------------------------------------------
  if ($requestedDate === date('Y-m-d') && $data['nutrition']['calories'] === 0) {
    // No data for today, look backwards for most recent date with data
    // Use the first matching Daily Calories category ID (dynamic lookup)
    $dailyCalsCatId = $categoryNameToIds['Daily Calories'][0] ?? null;
    $measurementResp = $dailyCalsCatId ? wger_get($WGER_BASE, $WGER_TOKEN, '/api/v2/measurement/', [
      'category' => $dailyCalsCatId,
      'limit' => 1,
      'ordering' => '-date',
    ]) : ['results' => []];

    $recentMeasurements = $measurementResp['results'] ?? [];
    if (!empty($recentMeasurements)) {
      $mostRecentDate = extract_date($recentMeasurements[0]['date'] ?? '');

      if ($mostRecentDate && $mostRecentDate !== $requestedDate) {
        // Re-fetch data for the most recent date
        $date = $mostRecentDate;

        // Re-fetch measurements for this date
        $measurementResp = wger_get($WGER_BASE, $WGER_TOKEN, '/api/v2/measurement/', [
          'limit' => 500,
          'ordering' => '-date',
        ]);
        $measurements = $measurementResp['results'] ?? [];

        $todayMeasurements = array_filter($measurements, function($m) use ($date) {
          $mDate = extract_date($m['date'] ?? '');
          return $mDate === $date;
        });

        $measurementData = [
          'date' => $date,
          'categories' => [],
        ];

        $categoryMap = [];
        foreach ($categories as $cat) {
          $categoryMap[$cat['id']] = [
            'name' => $cat['name'] ?? 'Unknown',
            'unit' => $cat['unit'] ?? '',
          ];
        }

        foreach ($todayMeasurements as $m) {
          $catId = $m['category'] ?? null;
          $catInfo = $categoryMap[$catId] ?? ['name' => "Category_$catId", 'unit' => ''];
          $catName = $catInfo['name'];
          $unit = $catInfo['unit'];
          $value = try_number($m['value'] ?? null);

          if ($value !== null) {
            // Skip old "Steps" category with "steps" unit (use ksteps only)
            if ($catName === 'Steps' && $unit === 'steps') {
              continue;
            }

            // Convert ksteps to steps for better readability
            if ($catName === 'Steps' && $unit === 'ksteps') {
              $value = $value * 1000;
              $unit = 'steps';
            }

            // Convert metric to imperial units for American users
            if ($catName === 'Bone Mass' && $unit === 'kg') {
              $value = $value * 2.20462;
              $unit = 'lbs';
            }

            if ($catName === 'Muscle Mass' && $unit === 'kg') {
              $value = $value * 2.20462;
              $unit = 'lbs';
            }

            if ($catName === 'Distance' && $unit === 'km') {
              $value = round($value * 0.621371, 2);
              $unit = 'mi';
            }

            $measurementData['categories'][$catName] = [
              'value' => $value,
              'unit' => $unit,
            ];
          }
        }

        $data['measurements'] = $measurementData;
        $data['date'] = $date;
        $data['using_most_recent'] = true;
        $data['requested_date'] = $requestedDate;

        // Update nutrition from measurements
        $data['nutrition']['calories'] = $measurementData['categories']['Daily Calories']['value'] ?? 0;
        $data['nutrition']['protein_g'] = $measurementData['categories']['Daily Protein']['value'] ?? 0;
        $data['nutrition']['carbs_g'] = $measurementData['categories']['Daily Carbs']['value'] ?? 0;
        $data['nutrition']['fat_g'] = $measurementData['categories']['Daily Fat']['value'] ?? 0;
        $data['nutrition']['exercise_cals'] = $measurementData['categories']['MFP Exercise Calories']['value'] ?? 0;

        // Recalculate remaining with exercise included
        $data['nutrition']['remaining_calories'] = $data['nutrition']['goal_calories']
          ? ($data['nutrition']['goal_calories'] - $data['nutrition']['calories'] + $data['nutrition']['exercise_cals'])
          : null;
      }
    }
  }

  // ---------------------------------------------------------------------------
  // CLEAN DATA STRUCTURE (ChatGPT-optimized)
  // ---------------------------------------------------------------------------

  // Calculate BMR and TDEE using Mifflin-St Jeor equation
  $weight_kg = $data['weight']['current_lb'] / 2.20462;
  $height_cm = 172;  // From profile
  $age = 44;         // From profile
  $gender = 1;       // 1=male, 2=female

  // BMR = 10×weight(kg) + 6.25×height(cm) - 5×age + 5 (male) or -161 (female)
  $bmr = 10 * $weight_kg + 6.25 * $height_cm - 5 * $age;
  $bmr += ($gender == 1) ? 5 : -161;

  // TDEE = BMR × activity factor (moderate activity = 1.55)
  $activity_factor = 1.55;  // Based on profile: moderate work + sport
  $tdee = $bmr * $activity_factor;

  // Extract activity data from measurements
  $steps = $data['measurements']['categories']['Steps']['value'] ?? 0;
  $distance_mi = $data['measurements']['categories']['Distance']['value'] ?? 0;
  $distance_km = round($distance_mi * 1.60934, 2); // Convert miles to km
  $active_device_kcal = $data['measurements']['categories']['Calories']['value'] ?? 0;

  // Extract body composition data from already-fetched measurement categories.
  // Uses dynamic name lookup (handles duplicate category IDs correctly).
  // Muscle/Bone already converted to lbs by the measurement loop above; convert back to kg for calculations.
  $bodyfat_pct    = $data['measurements']['categories']['Body Fat']['value'] ?? null;
  $muscle_mass_kg = isset($data['measurements']['categories']['Muscle Mass']['value'])
    ? $data['measurements']['categories']['Muscle Mass']['value'] / 2.20462
    : null;
  $bone_mass_kg   = isset($data['measurements']['categories']['Bone Mass']['value'])
    ? $data['measurements']['categories']['Bone Mass']['value'] / 2.20462
    : null;
  $hydration_pct  = $data['measurements']['categories']['Hydration']['value'] ?? null;

  // Extract energy and macro data
  $intake_kcal = $data['nutrition']['calories'] ?? 0;
  $exercise_mfp_kcal = $data['nutrition']['exercise_cals'] ?? 0;
  $goal_intake_kcal = $data['nutrition']['goal_calories'] ?? 1500;
  $remaining_mfp_kcal = $data['nutrition']['remaining_calories'] ?? 0;
  $protein_g = $data['nutrition']['protein_g'] ?? 0;
  $carbs_g = $data['nutrition']['carbs_g'] ?? 0;
  $fat_g = $data['nutrition']['fat_g'] ?? 0;
  $sodium_mg = $data['nutrition']['sodium_mg'] ?? 0;

  // Calculate net calories
  // Net = intake - (goal - exercise) = intake - goal + exercise
  $net_kcal_vs_goal = $intake_kcal - $goal_intake_kcal + $exercise_mfp_kcal;
  // Net vs TDEE = intake + exercise - TDEE (true deficit/surplus)
  $net_kcal_vs_tdee = $intake_kcal + $exercise_mfp_kcal - $tdee;

  // Add clean, ChatGPT-friendly structure
  // Units: ALWAYS kcal (kilocalories), never kJ
  $data['energy'] = [
    'intake_kcal' => $intake_kcal,              // Food consumed
    'exercise_mfp_kcal' => $exercise_mfp_kcal,  // MFP logged exercise
    'active_device_kcal' => round($active_device_kcal, 2), // Apple/Withings active calories
    'goal_intake_kcal' => $goal_intake_kcal,    // MFP daily goal
    'remaining_mfp_kcal' => $remaining_mfp_kcal, // MFP remaining (goal - intake + exercise)
    'net_kcal_vs_goal' => round($net_kcal_vs_goal, 0),     // Surplus/deficit vs MFP goal
    'net_kcal_vs_tdee' => round($net_kcal_vs_tdee, 0),     // TRUE deficit vs actual TDEE
  ];

  // Units: steps = count, distance = ALWAYS km (never miles)
  $data['activity'] = [
    'steps' => $steps,            // Daily step count
    'distance_km' => $distance_km, // ALWAYS km (converted from device)
  ];

  // Macros (for ChatGPT analysis)
  // Units: protein/carbs/fat = g, sodium = mg
  $data['macros'] = [
    'protein_g' => $protein_g,
    'carbs_g' => $carbs_g,
    'fat_g' => $fat_g,
    'sodium_mg' => $sodium_mg,
  ];

  // Units: ALWAYS kcal
  // Calculated using Mifflin-St Jeor equation
  // Calculate daily calorie deficit/surplus
  $calories_consumed = $data['nutrition']['calories'] ?? 0;
  $deficit = $tdee - $calories_consumed;

  $data['metabolism'] = [
    'bmr_kcal' => round($bmr, 0),      // Basal Metabolic Rate (at rest)
    'tdee_kcal' => round($tdee, 0),    // Total Daily Energy Expenditure (BMR × activity factor)
    'deficit_kcal' => round($deficit, 0), // Daily calorie deficit (positive = deficit, negative = surplus)
  ];

  // Calculate fat mass and lean mass from body composition
  $weight_kg = $data['weight']['current_lb'] / 2.20462;
  $fat_mass_kg = null;
  $lean_mass_kg = null;

  if ($bodyfat_pct !== null && $weight_kg > 0) {
    $fat_mass_kg = ($bodyfat_pct / 100) * $weight_kg;
    $lean_mass_kg = $weight_kg - $fat_mass_kg;
  }

  // Calculate body comp trends (30-day and 90-day averages)
  $bodyfat_trend_30d = null;
  $bodyfat_trend_90d = null;
  $lean_mass_trend_30d = null;
  $lean_mass_trend_90d = null;

  try {
    // Get last 90 days of body fat data from ALL matching category IDs (handles duplicates)
    $bodyfat_by_date = [];
    foreach ($categoryNameToIds['Body Fat'] ?? [] as $bfCatId) {
      $resp = wger_get($WGER_BASE, $WGER_TOKEN, '/api/v2/measurement/', [
        'category' => $bfCatId,
        'limit' => 100,
        'ordering' => '-date',
      ]);
      foreach ($resp['results'] ?? [] as $entry) {
        $eDate = extract_date($entry['date'] ?? '');
        if ($eDate && !isset($bodyfat_by_date[$eDate])) {
          $bodyfat_by_date[$eDate] = $entry; // first value per date wins
        }
      }
    }
    $bodyfat_entries = array_values($bodyfat_by_date);

    // Calculate 30-day average
    $recent_30d = array_filter($bodyfat_entries, function($e) use ($date) {
      $eDate = extract_date($e['date'] ?? '');
      $diffDays = (strtotime($date) - strtotime($eDate)) / 86400;
      return $diffDays >= 0 && $diffDays <= 30;
    });

    $bf_vals_30d = array_filter(array_column($recent_30d, 'value'), fn($v) => $v !== null);
    if (!empty($bf_vals_30d)) {
      $bodyfat_trend_30d = array_sum($bf_vals_30d) / count($bf_vals_30d);
    }

    // Calculate 90-day average
    $recent_90d = array_filter($bodyfat_entries, function($e) use ($date) {
      $eDate = extract_date($e['date'] ?? '');
      $diffDays = (strtotime($date) - strtotime($eDate)) / 86400;
      return $diffDays >= 0 && $diffDays <= 90;
    });

    $bf_vals_90d = array_filter(array_column($recent_90d, 'value'), fn($v) => $v !== null);
    if (!empty($bf_vals_90d)) {
      $bodyfat_trend_90d = array_sum($bf_vals_90d) / count($bf_vals_90d);
    }

    // Calculate lean mass trends from weight and body fat trends
    if ($bodyfat_trend_30d !== null) {
      $weight_trend_30d_kg = $data['weight']['trend_30d_lb'] / 2.20462;
      $fat_mass_30d = ($bodyfat_trend_30d / 100) * $weight_trend_30d_kg;
      $lean_mass_trend_30d = $weight_trend_30d_kg - $fat_mass_30d;
    }

    if ($bodyfat_trend_90d !== null) {
      $weight_trend_90d_kg = $data['weight']['trend_90d_lb'] / 2.20462;
      $fat_mass_90d = ($bodyfat_trend_90d / 100) * $weight_trend_90d_kg;
      $lean_mass_trend_90d = $weight_trend_90d_kg - $fat_mass_90d;
    }
  } catch (Throwable $e) {
    // Trends unavailable
  }

  // Body composition (from Withings smart scale or manual tracking)
  // Units: percentages = %, mass = kg
  // Auto-synced from Withings Body+/Body Comp scales
  $data['bodycomp'] = [
    'date' => $date,
    'weight_kg' => round($weight_kg, 2),
    'fat_mass_kg' => $fat_mass_kg ? round($fat_mass_kg, 2) : null,
    'lean_mass_kg' => $lean_mass_kg ? round($lean_mass_kg, 2) : null,
    'bodyfat_pct' => $bodyfat_pct ? round($bodyfat_pct, 2) : null,       // Body fat percentage
    'muscle_mass_kg' => $muscle_mass_kg ? round($muscle_mass_kg, 2) : null, // Muscle mass (from scale)
    'bone_mass_kg' => $bone_mass_kg ? round($bone_mass_kg, 2) : null,    // Bone mass in kg
    'hydration_pct' => $hydration_pct ? round($hydration_pct, 1) : null, // Hydration percentage
  ];

  // Body composition trends (for tracking muscle vs fat loss)
  $data['bodycomp_trend'] = [
    'bodyfat_pct_30d' => $bodyfat_trend_30d ? round($bodyfat_trend_30d, 2) : null,
    'bodyfat_pct_90d' => $bodyfat_trend_90d ? round($bodyfat_trend_90d, 2) : null,
    'lean_mass_30d_kg' => $lean_mass_trend_30d ? round($lean_mass_trend_30d, 2) : null,
    'lean_mass_90d_kg' => $lean_mass_trend_90d ? round($lean_mass_trend_90d, 2) : null,
  ];

  // Digestion log (for correlating scale fluctuations with digestive patterns)
  // Optional manual tracking - helps explain "stuck" scale days
  // Values: had_bm = boolean, bm_quality = "normal"|"small-hard"|"loose", notes = string
  $digestionFile = __DIR__ . '/digestion_log.json';
  $digestion = ['had_bm' => null, 'bm_quality' => null, 'notes' => null];

  if (file_exists($digestionFile)) {
    $digestionLog = json_decode(file_get_contents($digestionFile), true);
    if (isset($digestionLog[$date])) {
      $digestion = $digestionLog[$date];
    }
  }

  $data['digestion'] = $digestion;

  // Sleep data (from Sleep Number smart bed)
  // Auto-synced from Sleep Number I8 if SLEEPNUMBER_SYNC is enabled
  // Units: duration = hours, score = 0-100, heart rate = bpm, HRV = ms, respiratory rate = breaths/min
  $sleep_data = null;
  try {
    // Sleep data is logged for the previous night, so we look at yesterday's measurements
    // Categories created by daily_health_sync.py:
    // - Sleep Duration (hours)
    // - Sleep Score (0-100)
    // - Sleep Heart Rate (bpm)
    // - Sleep HRV (ms)
    // - Sleep Respiratory Rate (brpm)
    // - Sleep Restful (hours)
    // - Sleep Restless (hours)
    // - Sleep Out of Bed (hours)

    // Find category IDs for sleep metrics
    $sleep_categories = [];
    foreach ($categories as $cat) {
      $name = $cat['name'] ?? '';
      if (strpos($name, 'Sleep') === 0) {
        $sleep_categories[$name] = $cat['id'];
      }
    }

    if (!empty($sleep_categories)) {
      $sleep_duration = null;
      $sleep_score = null;
      $sleep_hr = null;
      $sleep_hrv = null;
      $sleep_rr = null;
      $sleep_restful = null;
      $sleep_restless = null;
      $sleep_out_of_bed = null;

      // Fetch each sleep metric
      foreach ($sleep_categories as $name => $cat_id) {
        $resp = wger_get($WGER_BASE, $WGER_TOKEN, '/api/v2/measurement/', [
          'category' => $cat_id,
          'date' => $date,
          'limit' => 1,
        ]);

        $value = ($resp['results'][0]['value'] ?? null);
        if ($value !== null) {
          if (strpos($name, 'Duration') !== false) {
            $sleep_duration = round($value, 2);
          } elseif (strpos($name, 'Score') !== false) {
            $sleep_score = round($value, 0);
          } elseif (strpos($name, 'Heart Rate') !== false && strpos($name, 'HRV') === false) {
            $sleep_hr = round($value, 1);
          } elseif (strpos($name, 'HRV') !== false) {
            $sleep_hrv = round($value, 1);
          } elseif (strpos($name, 'Respiratory') !== false) {
            $sleep_rr = round($value, 1);
          } elseif (strpos($name, 'Restful') !== false) {
            $sleep_restful = round($value, 2);
          } elseif (strpos($name, 'Restless') !== false) {
            $sleep_restless = round($value, 2);
          } elseif (strpos($name, 'Out of Bed') !== false) {
            $sleep_out_of_bed = round($value, 2);
          }
        }
      }

      $sleep_data = [
        'duration_hours' => $sleep_duration,
        'sleep_score' => $sleep_score,
        'avg_heart_rate_bpm' => $sleep_hr,
        'avg_hrv_ms' => $sleep_hrv,
        'avg_respiratory_rate_brpm' => $sleep_rr,
        'restful_hours' => $sleep_restful,
        'restless_hours' => $sleep_restless,
        'out_of_bed_hours' => $sleep_out_of_bed,
      ];
    }
  } catch (Throwable $e) {
    // Sleep data not available
  }

  $data['sleep'] = $sleep_data;

  // SLEEP FALLBACK: If no sleep data for today, show most recent available
  // Uses $categoryNameToIds (tries ALL IDs per name, handles duplicates correctly)
  $sleep_has_data = !empty(array_filter($data['sleep'] ?? [], fn($v) => $v !== null));
  if (!$sleep_has_data) {
    try {
      // Find the most recent date that has Sleep Score data (try all category IDs)
      $recent_sleep_date = null;
      foreach ($categoryNameToIds['Sleep Score'] ?? [] as $score_cat_id) {
        $recent_resp = wger_get($WGER_BASE, $WGER_TOKEN, '/api/v2/measurement/', [
          'category' => $score_cat_id,
          'limit'    => 1,
          'ordering' => '-date',
        ]);
        $candidate = extract_date($recent_resp['results'][0]['date'] ?? '');
        // Keep the most recent date found across all category IDs
        if ($candidate && ($recent_sleep_date === null || $candidate > $recent_sleep_date)) {
          $recent_sleep_date = $candidate;
        }
      }
      if ($recent_sleep_date && $recent_sleep_date !== $date) {
        // Re-fetch each sleep field for the most recent date, trying all category IDs
        $fb_fields = [
          'duration_hours'            => 'Sleep Duration',
          'sleep_score'               => 'Sleep Score',
          'avg_heart_rate_bpm'        => 'Sleep Heart Rate',
          'avg_hrv_ms'                => 'Sleep HRV',
          'avg_respiratory_rate_brpm' => 'Sleep Respiratory Rate',
        ];
        $fb_sleep = ['restful_hours' => null, 'restless_hours' => null, 'out_of_bed_hours' => null];
        foreach ($fb_fields as $field => $catName) {
          $fb_sleep[$field] = null;
          foreach ($categoryNameToIds[$catName] ?? [] as $catId) {
            $r = wger_get($WGER_BASE, $WGER_TOKEN, '/api/v2/measurement/', [
              'category' => $catId, 'date' => $recent_sleep_date, 'limit' => 1,
            ]);
            $v = try_number($r['results'][0]['value'] ?? null);
            if ($v !== null) {
              $fb_sleep[$field] = ($field === 'sleep_score') ? (int)round($v) : round($v, ($field === 'duration_hours' ? 2 : 1));
              break;
            }
          }
        }
        $data['sleep']              = $fb_sleep;
        $data['sleep_using_recent'] = true;
        $data['sleep_recent_date']  = $recent_sleep_date;
      }
    } catch (Throwable $e) {}
  }

  // Build sleep chart data (last $chart_days days)
  $sleep_chart = [];
  try {
    $sc_cutoff = date('Y-m-d', strtotime("-{$chart_days} days", strtotime($date)));
    $sc_cat_map = [
      'score'    => 'Sleep Score',
      'duration' => 'Sleep Duration',
      'hrv'      => 'Sleep HRV',
      'hr'       => 'Sleep Heart Rate',
      'rr'       => 'Sleep Respiratory Rate',
    ];
    foreach ($sc_cat_map as $field => $catName) {
      $catIds = $categoryNameToIds[$catName] ?? [];
      if (empty($catIds)) continue;
      foreach ($catIds as $catId) {
        $resp = wger_get($WGER_BASE, $WGER_TOKEN, '/api/v2/measurement/', [
          'category' => $catId,
          'limit'    => $chart_days + 5,
          'ordering' => '-date', // Newest first — only download what we need
        ]);
        $gotData = false;
        foreach ($resp['results'] ?? [] as $m) {
          $mDate = extract_date($m['date'] ?? '');
          if ($mDate >= $sc_cutoff && $mDate <= $date) {
            $val = try_number($m['value'] ?? null);
            if ($val !== null) {
              $sleep_chart[$mDate][$field] = ($field === 'score') ? (int)round($val) : round($val, 1);
              $gotData = true;
            }
          }
        }
        if ($gotData) break;
      }
    }
    ksort($sleep_chart);
  } catch (Throwable $e) {}
  $data['sleep_chart']      = !empty($sleep_chart) ? $sleep_chart : null;
  $data['sleep_chart_days'] = $chart_days;

  // ---------------------------------------------------------------------------
  // RECORDS & STREAKS (last 90 days of measurements + all-time weight)
  // ---------------------------------------------------------------------------
  $records = [];
  try {
    $rec_cutoff = date('Y-m-d', strtotime('-90 days'));
    $today_str  = date('Y-m-d');

    // Reuse category map if available, otherwise build it
    $recCatIds = [];
    $recCatResp = wger_get($WGER_BASE, $WGER_TOKEN, '/api/v2/measurement-category/', ['limit' => 200]);
    foreach ($recCatResp['results'] ?? [] as $cat) {
      $recCatIds[$cat['name']][] = (int)$cat['id'];
    }

    // Fetch last 90 days of a named measurement category → [date => value]
    $fetchHist = function(string $catName) use ($WGER_BASE, $WGER_TOKEN, $recCatIds, $rec_cutoff, $today_str): array {
      $pts = [];
      foreach ($recCatIds[$catName] ?? [] as $catId) {
        $resp = wger_get($WGER_BASE, $WGER_TOKEN, '/api/v2/measurement/', [
          'category' => $catId, 'limit' => 200, 'ordering' => '-date',
        ]);
        foreach ($resp['results'] ?? [] as $e) {
          $d = extract_date($e['date'] ?? '');
          if ($d < $rec_cutoff || $d > $today_str) continue;
          $v = try_number($e['value']);
          if ($v !== null && !isset($pts[$d])) $pts[$d] = $v;
        }
      }
      ksort($pts);
      return $pts;
    };

    // Streak calculator: walks date-keyed array oldest→newest.
    // Returns ['current' => int, 'best' => int]
    $streak = function(array $pts, callable $test) use ($today_str): array {
      if (empty($pts)) return ['current' => 0, 'best' => 0];
      $best = 0; $run = 0; $prev = null;
      foreach ($pts as $d => $v) {
        if ($prev !== null && (strtotime($d) - strtotime($prev)) / 86400 > 1) $run = 0;
        $run = $test($v) ? $run + 1 : 0;
        $best = max($best, $run);
        $prev = $d;
      }
      $lastDate  = array_key_last($pts);
      $lastVal   = $pts[$lastDate];
      $daysAgo   = round((strtotime($today_str) - strtotime($lastDate)) / 86400);
      $current   = ($daysAgo <= 1 && $test($lastVal)) ? $run : 0;
      return ['current' => $current, 'best' => $best];
    };

    // ── Weight (all-time from already-fetched entries) ────────────────────
    $allW = [];
    foreach ($weightEntries as $w) {
      $v = try_number($w['weight']);
      if ($v !== null) $allW[extract_date($w['date'] ?? '')] = $v;
    }
    ksort($allW);
    if (!empty($allW)) {
      $records['weight_all_time_low']  = round(min($allW), 1);
      $records['weight_all_time_high'] = round(max($allW), 1);
      $records['weight_start']         = round(reset($allW), 1);
      $records['weight_start_date']    = array_key_first($allW);
      // Consecutive days of loss streak
      $records['weight_loss_streak']   = $streak(
        $allW,
        function($v) use (&$allW, &$records) {
          static $prev = null;
          $out = ($prev !== null && $v < $prev);
          $prev = $v;
          return $out;
        }
      );
    }

    // ── Calories ≤ 1500 streak ────────────────────────────────────────────
    $calPts = $fetchHist('Daily Calories');
    if (!empty($calPts)) {
      $calActive = array_filter($calPts, fn($v) => $v > 0);
      $records['cal_streak'] = $streak($calActive, fn($v) => $v <= 1500);
      $records['cal_avg']    = !empty($calActive) ? (int)round(array_sum($calActive) / count($calActive)) : null;
      $records['cal_best']   = !empty($calActive) ? (int)round(min($calActive)) : null;
    }

    // ── Protein ≥ 150g streak ─────────────────────────────────────────────
    $protPts = $fetchHist('Daily Protein');
    if (!empty($protPts)) {
      $records['prot_streak'] = $streak($protPts, fn($v) => $v >= 150);
      $records['prot_avg']    = (int)round(array_sum($protPts) / count($protPts));
      $records['prot_best']   = (int)round(max($protPts));
    }

    // ── Steps ≥ 10,000 streak (stored as ksteps) ──────────────────────────
    $stepsPts = $fetchHist('Steps');
    if (!empty($stepsPts)) {
      $records['steps_streak'] = $streak($stepsPts, fn($v) => $v * 1000 >= 10000);
      $records['steps_best']   = (int)round(max($stepsPts) * 1000);
      $records['steps_avg']    = (int)round(array_sum($stepsPts) / count($stepsPts) * 1000);
    }

    // ── Sleep score ≥ 85 streak ───────────────────────────────────────────
    $sleepPts = $fetchHist('Sleep Score');
    if (!empty($sleepPts)) {
      $records['sleep_streak'] = $streak($sleepPts, fn($v) => $v >= 85);
      $records['sleep_best']   = (int)round(max($sleepPts));
      $records['sleep_avg']    = (int)round(array_sum($sleepPts) / count($sleepPts));
    }

  } catch (Throwable $e) {
    // Records are a bonus — never crash the page
  }
  $data['records'] = $records;

  // ---------------------------------------------------------------------------
  // MULTI-DAY EXPORT HANDLING
  // ---------------------------------------------------------------------------

  if ($export === 'json' || $export === 'csv') {
    $export_data = [];

    // Build category map once (avoids repeated API calls per day)
    $exportCategoryNameToIds = [];
    try {
      $catResp = wger_get($WGER_BASE, $WGER_TOKEN, '/api/v2/measurement-category/', ['limit' => 200]);
      foreach ($catResp['results'] ?? [] as $cat) {
        $exportCategoryNameToIds[$cat['name']][] = $cat['id'];
      }
    } catch (Throwable $e) {}

    // Collect data starting from today going backwards
    for ($i = 0; $i < $days; $i++) {
      $export_date = date('Y-m-d', strtotime("-$i days", strtotime($requestedDate)));
      $day_data = collect_day_data($export_date, $WGER_BASE, $WGER_TOKEN, $exportCategoryNameToIds);
      $export_data[] = $day_data;
    }

    if ($export === 'json') {
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode([
        'export_date' => date('Y-m-d H:i:s T'),
        'days_exported' => $days,
        'start_date' => end($export_data)['date'] ?? null,
        'end_date' => $export_data[0]['date'] ?? null,
        'data' => $export_data
      ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
      exit;
    } elseif ($export === 'csv') {
      header('Content-Type: text/csv; charset=utf-8');
      header('Content-Disposition: attachment; filename="health_data_export_' . date('Y-m-d') . '.csv"');

      $csv = fopen('php://output', 'w');

      // CSV Headers
      fputcsv($csv, [
        'Date', 'Weight (lbs)',
        'Calories', 'Goal Cals', 'Remaining Cals', 'Exercise Cals',
        'Protein (g)', 'Carbs (g)', 'Fat (g)', 'Sodium (mg)', 'Fiber (g)', 'Sugar (g)',
        'Sleep Duration (hrs)', 'Sleep Score', 'Sleep HR (bpm)', 'HRV (ms)', 'Resp Rate (brpm)',
        'Steps', 'Distance (mi)',
        'Body Fat (%)', 'Muscle Mass (lbs)', 'Bone Mass (lbs)', 'Metabolic Age', 'Visceral Fat',
        'Resting HR (bpm)', 'Active Calories (kcal)',
        'BMR (kcal)', 'TDEE (kcal)', 'Deficit (kcal)'
      ]);

      // CSV Data Rows
      foreach ($export_data as $day) {
        fputcsv($csv, [
          $day['date'],
          $day['weight']['current_lb'] ?? '',
          $day['nutrition']['calories'] ?? '',
          $day['nutrition']['goal_calories'] ?? '',
          $day['nutrition']['remaining_calories'] ?? '',
          $day['nutrition']['exercise_cals'] ?? '',
          $day['nutrition']['protein_g'] ?? '',
          $day['nutrition']['carbs_g'] ?? '',
          $day['nutrition']['fat_g'] ?? '',
          $day['nutrition']['sodium_mg'] ?? '',
          $day['nutrition']['fiber_g'] ?? '',
          $day['nutrition']['sugar_g'] ?? '',
          $day['sleep']['duration_hours'] ?? '',
          $day['sleep']['sleep_score'] ?? '',
          $day['sleep']['avg_heart_rate_bpm'] ?? '',
          $day['sleep']['avg_hrv_ms'] ?? '',
          $day['sleep']['avg_respiratory_rate_brpm'] ?? '',
          $day['activity']['steps'] ?? '',
          $day['activity']['distance_mi'] ?? '',
          $day['bodycomp']['body_fat_pct'] ?? '',
          $day['bodycomp']['muscle_mass_lbs'] ?? '',
          $day['bodycomp']['bone_mass_lbs'] ?? '',
          $day['bodycomp']['metabolic_age'] ?? '',
          $day['bodycomp']['visceral_fat_index'] ?? '',
          $day['vitals']['resting_hr_bpm'] ?? '',
          $day['vitals']['active_calories_kcal'] ?? '',
          $day['metabolism']['bmr_kcal'] ?? '',
          $day['metabolism']['tdee_kcal'] ?? '',
          $day['metabolism']['deficit_kcal'] ?? '',
        ]);
      }

      fclose($csv);
      exit;
    }
  }

  // ---------------------------------------------------------------------------
  // OUTPUT FORMATTING
  // ---------------------------------------------------------------------------

  if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  } elseif ($format === 'markdown') {
    header('Content-Type: text/plain; charset=utf-8');

    echo "# WGER Health & Fitness Data\n\n";
    echo "**Date:** {$data['date']}\n";
    echo "**Generated:** {$data['generated_at']}\n\n";

    echo "## Weight\n";
    echo "- **Current Weight:** " . ($data['weight']['current_lb'] ?? 'N/A') . " lbs\n";
    if (isset($data['weight']['starting_weight_lb'], $data['weight']['starting_date'])) {
      echo "- **Starting Weight:** " . $data['weight']['starting_weight_lb'] . " lbs (on {$data['weight']['starting_date']})\n";
    }
    echo "- **7-day Average:** " . (isset($data['weight']['trend_7d_lb']) ? round($data['weight']['trend_7d_lb'], 1) : 'N/A') . " lbs\n";
    echo "- **30-day Average:** " . (isset($data['weight']['trend_30d_lb']) ? round($data['weight']['trend_30d_lb'], 1) : 'N/A') . " lbs\n";
    if (isset($data['weight']['total_change_lb'])) {
      echo "- **Total Change:** " . ($data['weight']['total_change_lb'] > 0 ? '+' : '') . $data['weight']['total_change_lb'] . " lbs (from starting weight)\n";
    }
    echo "\n";

    echo "## Nutrition\n";
    echo "- **Calories:** {$data['nutrition']['calories']} / " . ($data['nutrition']['goal_calories'] ?? 'N/A') . " kcal";
    if ($data['nutrition']['remaining_calories'] !== null) {
      echo " (" . ($data['nutrition']['remaining_calories'] > 0 ? '' : '') . round($data['nutrition']['remaining_calories']) . " remaining)";
    }
    echo "\n";
    if (isset($data['nutrition']['protein_g']) && $data['nutrition']['protein_g'] !== null) {
        echo "- **Protein:** " . round($data['nutrition']['protein_g'], 1) . " g\n";
    }
    if (isset($data['nutrition']['carbs_g']) && $data['nutrition']['carbs_g'] !== null) {
        echo "- **Carbs:** " . round($data['nutrition']['carbs_g'], 1) . " g\n";
    }
    if (isset($data['nutrition']['fat_g']) && $data['nutrition']['fat_g'] !== null) {
        echo "- **Fat:** " . round($data['nutrition']['fat_g'], 1) . " g\n";
    }
    if (isset($data['nutrition']['sodium_mg']) && $data['nutrition']['sodium_mg'] > 0) {
        echo "- **Sodium:** " . round($data['nutrition']['sodium_mg']) . " mg\n";
    }
    if (($data['nutrition']['fiber_g'] ?? 0) > 0) {
        echo "- **Fiber:** " . round($data['nutrition']['fiber_g'], 1) . " g\n";
    }
    if (($data['nutrition']['sugar_g'] ?? 0) > 0) {
        echo "- **Sugar:** " . round($data['nutrition']['sugar_g'], 1) . " g\n";
    }
    if (($data['nutrition']['cholesterol_mg'] ?? 0) > 0) {
        echo "- **Cholesterol:** " . round($data['nutrition']['cholesterol_mg']) . " mg\n";
    }
    if (($data['nutrition']['potassium_mg'] ?? 0) > 0) {
        echo "- **Potassium:** " . round($data['nutrition']['potassium_mg']) . " mg\n";
    }
    if (($data['nutrition']['vitamin_a_iu'] ?? 0) > 0) {
        echo "- **Vitamin A:** " . round($data['nutrition']['vitamin_a_iu']) . " IU\n";
    }
    if (($data['nutrition']['vitamin_c_mg'] ?? 0) > 0) {
        echo "- **Vitamin C:** " . round($data['nutrition']['vitamin_c_mg']) . " mg\n";
    }
    if (($data['nutrition']['calcium_mg'] ?? 0) > 0) {
        echo "- **Calcium:** " . round($data['nutrition']['calcium_mg']) . " mg\n";
    }
    if (($data['nutrition']['iron_mg'] ?? 0) > 0) {
        echo "- **Iron:** " . round($data['nutrition']['iron_mg'], 1) . " mg\n";
    }
    if (isset($data['nutrition']['entries_count'])) {
        echo "- **Entries:** {$data['nutrition']['entries_count']}\n";
    }
    echo "\n";

    echo "## Metabolism\n";
    echo "- **BMR:** {$data['metabolism']['bmr_kcal']} kcal (Basal Metabolic Rate - calories burned at rest)\n";
    echo "- **TDEE:** {$data['metabolism']['tdee_kcal']} kcal (Total Daily Energy Expenditure)\n";
    $deficit_sign = $data['metabolism']['deficit_kcal'] >= 0 ? '+' : '';
    echo "- **Daily Deficit:** {$deficit_sign}{$data['metabolism']['deficit_kcal']} kcal";
    if ($data['metabolism']['deficit_kcal'] > 0) {
        echo " (deficit - losing weight)";
    } elseif ($data['metabolism']['deficit_kcal'] < 0) {
        echo " (surplus - gaining weight)";
    }
    echo "\n";
    echo "\n";

    if (!empty($data['sleep'])) {
      echo "## Sleep\n";
      if ($data['sleep']['duration_hours']) echo "- **Duration:** {$data['sleep']['duration_hours']} hours\n";
      if ($data['sleep']['sleep_score']) echo "- **Sleep Score:** {$data['sleep']['sleep_score']}\n";
      if ($data['sleep']['restful_hours']) echo "- **Restful:** {$data['sleep']['restful_hours']} hours\n";
      if ($data['sleep']['restless_hours']) echo "- **Restless:** {$data['sleep']['restless_hours']} hours\n";
      if ($data['sleep']['out_of_bed_hours']) echo "- **Out of Bed:** {$data['sleep']['out_of_bed_hours']} hours\n";
      if ($data['sleep']['avg_heart_rate_bpm']) echo "- **Avg Heart Rate:** {$data['sleep']['avg_heart_rate_bpm']} bpm\n";
      if ($data['sleep']['avg_hrv_ms']) echo "- **Avg HRV:** {$data['sleep']['avg_hrv_ms']} ms\n";
      if ($data['sleep']['avg_respiratory_rate_brpm']) echo "- **Avg Resp Rate:** {$data['sleep']['avg_respiratory_rate_brpm']} brpm\n";
      echo "\n";
    }

    if (!empty($data['measurements']['categories'])) {
      echo "## Measurements\n";
      foreach ($data['measurements']['categories'] as $name => $info) {
        $unit = $info['unit'] ?? '';
        echo "- **$name:** {$info['value']} $unit\n";
      }
      echo "\n";
    }

    if (isset($data['workouts']['sessions_today']) && $data['workouts']['sessions_today'] > 0) {
      echo "## Workouts\n";
      echo "- **Sessions:** {$data['workouts']['sessions_today']}\n";
      foreach ($data['workouts']['sessions'] as $i => $session) {
        $num = $i + 1;
        echo "\n### Session $num\n";
        if (isset($session['workout_name'])) echo "- Workout: {$session['workout_name']}\n";
        if (isset($session['time'])) echo "- Time: {$session['time']}\n";
        if (isset($session['notes']) && $session['notes']) echo "- Notes: {$session['notes']}\n";
      }
      echo "\n";
    }

  } elseif ($format === 'html') {
    // Matrix-themed HTML format
    header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Superman Health Matrix - <?php echo $data['date']; ?></title>
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      background: #000;
      color: #00FF41;
      font-family: 'Courier New', Courier, monospace;
      padding: 20px;
      line-height: 1.6;
      overflow-x: hidden;
    }

    .container {
      max-width: 1200px;
      margin: 0 auto;
      animation: fadeIn 0.5s ease-in;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(-10px); }
      to { opacity: 1; transform: translateY(0); }
    }

    h1 {
      color: #00FF41;
      text-align: center;
      font-size: 2em;
      margin-bottom: 20px;
      text-shadow: 0 0 10px #00FF41;
      letter-spacing: 2px;
      border-bottom: 2px solid #00FF41;
      padding-bottom: 10px;
    }

    .header {
      text-align: center;
      margin-bottom: 30px;
      opacity: 0.8;
    }

    .section {
      margin-bottom: 30px;
      border: 1px solid #00FF41;
      padding: 20px;
      background: rgba(0, 255, 65, 0.05);
      border-radius: 5px;
      box-shadow: 0 0 20px rgba(0, 255, 65, 0.2);
    }

    .section h2 {
      color: #00FF41;
      font-size: 1.5em;
      margin-bottom: 15px;
      text-shadow: 0 0 8px #00FF41;
      border-bottom: 1px solid rgba(0, 255, 65, 0.5);
      padding-bottom: 5px;
    }

    .data-row {
      display: flex;
      justify-content: space-between;
      padding: 8px 0;
      border-bottom: 1px solid rgba(0, 255, 65, 0.2);
    }

    .data-row:last-child {
      border-bottom: none;
    }

    .label {
      color: #00DD33;
      font-weight: bold;
    }

    .value {
      color: #00FF41;
      text-align: right;
    }

    .highlight {
      color: #00FF00;
      font-weight: bold;
      text-shadow: 0 0 5px #00FF00;
    }

    .grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 20px;
    }

    @media (max-width: 768px) {
      body {
        padding: 10px;
      }

      h1 {
        font-size: 1.5em;
      }

      .section {
        padding: 15px;
      }
    }

    .blink {
      animation: blink 1.5s ease-in-out infinite;
    }

    @keyframes blink {
      0%, 100% { opacity: 1; }
      50% { opacity: 0.3; }
    }
  </style>
</head>
<body>
  <div class="container">
    <h1>▌█ SUPERMAN HEALTH MATRIX █▐</h1>

    <!-- ═══════════════════════════════════════════════════════════ -->
    <!-- CONTROLS PANEL                                              -->
    <!-- ═══════════════════════════════════════════════════════════ -->
    <div id="ctrl-wrap" style="margin-bottom:18px; font-family:'Courier New',monospace; font-size:13px;">

      <!-- Toggle bar -->
      <div id="ctrl-toggle" onclick="toggleCtrl()" style="cursor:pointer; border:1px solid #00FF41; padding:8px 16px; color:#00DD33; background:rgba(0,255,65,0.04); display:flex; align-items:center; gap:10px; user-select:none; letter-spacing:1px;">
        <span id="ctrl-arrow" style="font-size:11px;">▶</span>
        <span>CONTROLS</span>
      </div>

      <!-- Expandable body -->
      <div id="ctrl-body" style="display:none; border:1px solid #00FF41; border-top:none; padding:16px; background:rgba(0,255,65,0.03);">

        <!-- Row 1: Date + Chart Range + Apply -->
        <div style="display:flex; flex-wrap:wrap; gap:20px; align-items:flex-end;">

          <label style="display:flex; flex-direction:column; gap:4px; color:#00CC28; letter-spacing:1px;">
            DATE
            <input type="date" id="ctrl-date" value="<?php echo $date; ?>"
              style="background:#000; color:#00FF41; border:1px solid #00FF41; padding:4px 8px; font-family:'Courier New',monospace; font-size:13px; outline:none;">
          </label>

          <label style="display:flex; flex-direction:column; gap:4px; color:#00CC28; letter-spacing:1px;">
            CHART RANGE
            <select id="ctrl-chart-days"
              style="background:#000; color:#00FF41; border:1px solid #00FF41; padding:4px 8px; font-family:'Courier New',monospace; font-size:13px; outline:none; cursor:pointer;">
              <?php foreach ([7, 14, 30, 60, 90, 180, 365] as $cd): ?>
              <option value="<?php echo $cd; ?>" <?php echo $cd == $chart_days ? 'selected' : ''; ?>>
                <?php echo $cd; ?> days
              </option>
              <?php endforeach; ?>
            </select>
          </label>

          <button onclick="applySettings()"
            style="background:#000; color:#00FF41; border:1px solid #00FF41; padding:6px 18px; font-family:'Courier New',monospace; font-size:13px; letter-spacing:1px; cursor:pointer;"
            onmouseover="this.style.background='rgba(0,255,65,0.12)'; this.style.boxShadow='0 0 8px rgba(0,255,65,0.4)';"
            onmouseout="this.style.background='#000'; this.style.boxShadow='none';">
            ▶ APPLY
          </button>

        </div>

        <!-- Row 2: Export -->
        <div style="margin-top:14px; display:flex; flex-wrap:wrap; gap:14px; align-items:flex-end;">

          <label style="display:flex; flex-direction:column; gap:4px; color:#00CC28; letter-spacing:1px;">
            EXPORT DAYS
            <input type="number" id="ctrl-export-days" value="30" min="1" max="365"
              style="background:#000; color:#00FF41; border:1px solid #00FF41; padding:4px 8px; font-family:'Courier New',monospace; font-size:13px; outline:none; width:72px;">
          </label>

          <button onclick="doExport('json')"
            style="background:#000; color:#00FF41; border:1px solid #00FF41; padding:6px 18px; font-family:'Courier New',monospace; font-size:13px; letter-spacing:1px; cursor:pointer;"
            onmouseover="this.style.background='rgba(0,255,65,0.12)'; this.style.boxShadow='0 0 8px rgba(0,255,65,0.4)';"
            onmouseout="this.style.background='#000'; this.style.boxShadow='none';">
            ⬇ JSON
          </button>

          <button onclick="doExport('csv')"
            style="background:#000; color:#00FF41; border:1px solid #00FF41; padding:6px 18px; font-family:'Courier New',monospace; font-size:13px; letter-spacing:1px; cursor:pointer;"
            onmouseover="this.style.background='rgba(0,255,65,0.12)'; this.style.boxShadow='0 0 8px rgba(0,255,65,0.4)';"
            onmouseout="this.style.background='#000'; this.style.boxShadow='none';">
            ⬇ CSV
          </button>

        </div>

        <!-- Row 3: Navigation -->
        <div style="margin-top:14px; display:flex; flex-wrap:wrap; gap:10px; align-items:center; padding-top:14px; border-top:1px solid rgba(0,255,65,0.2);">
          <span style="color:#00CC28; font-size:11px; letter-spacing:1px;">NAVIGATE:</span>
          <a href="charts.php"
             style="background:#000; color:#00FF41; border:1px solid #00FF41; padding:6px 18px; font-family:'Courier New',monospace; font-size:13px; letter-spacing:1px; text-decoration:none; display:inline-block;"
             onmouseover="this.style.background='rgba(0,255,65,0.12)'; this.style.boxShadow='0 0 8px rgba(0,255,65,0.4)';"
             onmouseout="this.style.background='#000'; this.style.boxShadow='none';">
            📊 All Charts
          </a>
          <a href="graph.php"
             style="background:#000; color:#00FF41; border:1px solid #00FF41; padding:6px 18px; font-family:'Courier New',monospace; font-size:13px; letter-spacing:1px; text-decoration:none; display:inline-block;"
             onmouseover="this.style.background='rgba(0,255,65,0.12)'; this.style.boxShadow='0 0 8px rgba(0,255,65,0.4)';"
             onmouseout="this.style.background='#000'; this.style.boxShadow='none';">
            📈 Graph Any Metric
          </a>
        </div>

      </div>
    </div>
    <!-- END CONTROLS PANEL -->

    <script>
    function toggleCtrl() {
      var body  = document.getElementById('ctrl-body');
      var arrow = document.getElementById('ctrl-arrow');
      var open  = body.style.display !== 'none';
      body.style.display  = open ? 'none' : 'block';
      arrow.textContent   = open ? '▶' : '▼';
      localStorage.setItem('ctrl-open', open ? '0' : '1');
    }
    // Restore state on load
    (function() {
      if (localStorage.getItem('ctrl-open') === '1') { toggleCtrl(); }
    })();

    function applySettings() {
      var date = document.getElementById('ctrl-date').value;
      var cd   = document.getElementById('ctrl-chart-days').value;
      var url  = '?format=html';
      if (date) url += '&date=' + encodeURIComponent(date);
      if (cd)   url += '&chart_days=' + encodeURIComponent(cd);
      window.location.href = url;
    }

    function doExport(fmt) {
      var days = document.getElementById('ctrl-export-days').value || '30';
      window.open('?export=' + fmt + '&days=' + encodeURIComponent(days), '_blank');
    }
    </script>

    <div class="header">
      <div>DATE: <span class="highlight"><?php echo $data['date']; ?></span></div>
      <div>GENERATED: <?php echo $data['generated_at']; ?></div>
      <?php if (!empty($data['using_most_recent'])): ?>
        <div style="color: #FFFF00;">⚠ SHOWING MOST RECENT DATA (requested <?php echo $data['requested_date']; ?> had none)</div>
      <?php endif; ?>
    </div>

    <div class="grid">
      <!-- WEIGHT SECTION -->
      <div class="section">
        <h2>▌WEIGHT DATA</h2>
        <div class="data-row">
          <span class="label">CURRENT:</span>
          <span class="value highlight"><?php echo $data['weight']['current_lb'] ?? 'N/A'; ?> lbs</span>
        </div>
        <div class="data-row">
          <span class="label">AS OF:</span>
          <span class="value"><?php echo $data['weight']['date_of_reading'] ?? 'N/A'; ?></span>
        </div>
        <?php if (isset($data['weight']['starting_weight_lb'])): ?>
        <div class="data-row">
          <span class="label">STARTING:</span>
          <span class="value"><?php echo $data['weight']['starting_weight_lb']; ?> lbs (<?php echo $data['weight']['starting_date']; ?>)</span>
        </div>
        <?php endif; ?>
        <div class="data-row">
          <span class="label">7-DAY TREND:</span>
          <span class="value"><?php echo isset($data['weight']['trend_7d_lb']) ? round($data['weight']['trend_7d_lb'], 1) : 'N/A'; ?> lbs</span>
        </div>
        <div class="data-row">
          <span class="label">30-DAY TREND:</span>
          <span class="value"><?php echo isset($data['weight']['trend_30d_lb']) ? round($data['weight']['trend_30d_lb'], 1) : 'N/A'; ?> lbs</span>
        </div>
        <div class="data-row">
          <span class="label">90-DAY TREND:</span>
          <span class="value"><?php echo isset($data['weight']['trend_90d_lb']) ? round($data['weight']['trend_90d_lb'], 1) : 'N/A'; ?> lbs</span>
        </div>
        <?php if (isset($data['weight']['total_change_lb'])): ?>
        <div class="data-row">
          <span class="label">TOTAL CHANGE:</span>
          <span class="value highlight"><?php echo ($data['weight']['total_change_lb'] > 0 ? '+' : '') . $data['weight']['total_change_lb']; ?> lbs</span>
        </div>
        <?php endif; ?>
        <?php if (isset($data['weight']['rate_lbs_per_week'])): ?>
        <div class="data-row">
          <span class="label">LOSS RATE:</span>
          <?php
            $wrate  = $data['weight']['rate_lbs_per_week'];
            $warrow = $wrate > 0.05 ? '↓' : ($wrate < -0.05 ? '↑' : '→');
            $wcolor = $wrate > 0.05 ? '#00FF41' : ($wrate < -0.05 ? '#FF4444' : '#888888');
            $wlabel = $wrate > 0.05 ? 'lbs/week' : ($wrate < -0.05 ? 'lbs/week (gaining)' : 'lbs/week (maintaining)');
          ?>
          <span class="value highlight" style="color:<?php echo $wcolor; ?>">
            <?php echo $warrow . ' ' . abs($wrate) . ' ' . $wlabel; ?>
            <span style="color:#555555;font-size:0.85em"> (<?php echo $chart_days; ?>d avg)</span>
          </span>
        </div>
        <?php endif; ?>
      </div>

      <!-- NUTRITION SECTION -->
      <div class="section">
        <h2>▌NUTRITION DATA</h2>
        <div class="data-row">
          <span class="label">CALORIES:</span>
          <span class="value highlight"><?php echo round($data['nutrition']['calories']); ?> / <?php echo $data['nutrition']['goal_calories'] ?? 'N/A'; ?> kcal</span>
        </div>
        <?php if ($data['nutrition']['exercise_cals'] > 0): ?>
        <div class="data-row">
          <span class="label">EXERCISE BURNED:</span>
          <span class="value"><?php echo round($data['nutrition']['exercise_cals']); ?> kcal</span>
        </div>
        <?php endif; ?>
        <?php if ($data['nutrition']['remaining_calories'] !== null): ?>
        <div class="data-row">
          <span class="label">REMAINING:</span>
          <span class="value"><?php echo round($data['nutrition']['remaining_calories']); ?> kcal</span>
        </div>
        <?php endif; ?>
        <div class="data-row">
          <span class="label">PROTEIN:</span>
          <span class="value"><?php echo round($data['nutrition']['protein_g'], 1); ?> g</span>
        </div>
        <div class="data-row">
          <span class="label">CARBS:</span>
          <span class="value"><?php echo round($data['nutrition']['carbs_g'], 1); ?> g</span>
        </div>
        <div class="data-row">
          <span class="label">FAT:</span>
          <span class="value"><?php echo round($data['nutrition']['fat_g'], 1); ?> g</span>
        </div>
        <?php if (isset($data['nutrition']['sodium_mg']) && $data['nutrition']['sodium_mg'] > 0): ?>
        <div class="data-row">
          <span class="label">SODIUM:</span>
          <span class="value"><?php echo round($data['nutrition']['sodium_mg']); ?> mg</span>
        </div>
        <?php endif; ?>

        <?php if (($data['nutrition']['fiber_g'] ?? 0) > 0): ?>
        <div class="data-row">
          <span class="label">FIBER:</span>
          <span class="value"><?php echo round($data['nutrition']['fiber_g'], 1); ?> g</span>
        </div>
        <?php endif; ?>

        <?php if (($data['nutrition']['sugar_g'] ?? 0) > 0): ?>
        <div class="data-row">
          <span class="label">SUGAR:</span>
          <span class="value"><?php echo round($data['nutrition']['sugar_g'], 1); ?> g</span>
        </div>
        <?php endif; ?>

        <?php if (($data['nutrition']['cholesterol_mg'] ?? 0) > 0): ?>
        <div class="data-row">
          <span class="label">CHOLESTEROL:</span>
          <span class="value"><?php echo round($data['nutrition']['cholesterol_mg']); ?> mg</span>
        </div>
        <?php endif; ?>

        <?php if (($data['nutrition']['potassium_mg'] ?? 0) > 0): ?>
        <div class="data-row">
          <span class="label">POTASSIUM:</span>
          <span class="value"><?php echo round($data['nutrition']['potassium_mg']); ?> mg</span>
        </div>
        <?php endif; ?>
      </div>

      <!-- METABOLISM SECTION -->
      <div class="section">
        <h2>▌METABOLISM DATA</h2>
        <div class="data-row">
          <span class="label">BMR:</span>
          <span class="value highlight"><?php echo $data['metabolism']['bmr_kcal']; ?> kcal</span>
        </div>
        <div class="data-row" style="font-size: 0.85em; color: #888;">
          <span class="label"></span>
          <span class="value">Basal Metabolic Rate (at rest)</span>
        </div>
        <div class="data-row">
          <span class="label">TDEE:</span>
          <span class="value highlight"><?php echo $data['metabolism']['tdee_kcal']; ?> kcal</span>
        </div>
        <div class="data-row" style="font-size: 0.85em; color: #888;">
          <span class="label"></span>
          <span class="value">Total Daily Energy Expenditure</span>
        </div>
        <div class="data-row">
          <span class="label">DAILY DEFICIT:</span>
          <span class="value highlight" style="color: <?php echo $data['metabolism']['deficit_kcal'] >= 0 ? '#00FF00' : '#FF4444'; ?>">
            <?php
              echo ($data['metabolism']['deficit_kcal'] >= 0 ? '+' : '') . $data['metabolism']['deficit_kcal'];
            ?> kcal
          </span>
        </div>
        <div class="data-row" style="font-size: 0.85em; color: #888;">
          <span class="label"></span>
          <span class="value">
            <?php
              if ($data['metabolism']['deficit_kcal'] > 0) {
                echo 'Deficit (losing weight)';
              } elseif ($data['metabolism']['deficit_kcal'] < 0) {
                echo 'Surplus (gaining weight)';
              } else {
                echo 'Maintenance';
              }
            ?>
          </span>
        </div>
      </div>
    </div>

    <?php if (!empty($data['weight_chart'])): ?>
    <!-- WEIGHT TREND CHART -->
    <div class="section">
      <h2>▌WEIGHT TREND — LAST <?php echo $data['weight_chart_days']; ?> DAYS</h2>
      <?php
        $pts       = $data['weight_chart'];
        $dates     = array_keys($pts);
        $vals      = array_values($pts);
        $n         = count($vals);
        $goal      = 175;

        $svgW = 800; $svgH = 260;
        $padL = 52;  $padR = 16; $padT = 20; $padB = 40;
        $plotW = $svgW - $padL - $padR;
        $plotH = $svgH - $padT - $padB;

        $minW = min(min($vals), $goal) - 2;
        $maxW = max($vals) + 2;
        $range = $maxW - $minW ?: 1;

        // map a weight → SVG y, a date index → SVG x
        $toX = fn(int $i) => $padL + ($n > 1 ? $i / ($n - 1) : 0.5) * $plotW;
        $toY = fn(float $w) => $padT + (1 - ($w - $minW) / $range) * $plotH;

        // Build smooth polyline points
        $polyPts = [];
        for ($i = 0; $i < $n; $i++) {
          $polyPts[] = round($toX($i), 1) . ',' . round($toY($vals[$i]), 1);
        }
        $poly = implode(' ', $polyPts);

        // Area fill path (close down to baseline)
        $areaPath = 'M' . $polyPts[0];
        for ($i = 1; $i < $n; $i++) $areaPath .= ' L' . $polyPts[$i];
        $areaPath .= ' L' . round($toX($n - 1), 1) . ',' . ($padT + $plotH);
        $areaPath .= ' L' . round($toX(0), 1) . ',' . ($padT + $plotH) . ' Z';

        // Goal line Y
        $goalY = round($toY($goal), 1);

        // X-axis labels: show ~5 evenly spaced dates
        $labelIdxs = [];
        $steps = min($n, 5);
        for ($s = 0; $s < $steps; $s++) $labelIdxs[] = (int)round($s * ($n - 1) / max($steps - 1, 1));

        // Y-axis grid lines & labels (5 lines)
        $yLines = [];
        for ($j = 0; $j <= 4; $j++) {
          $w = $minW + ($range * $j / 4);
          $yLines[] = ['y' => round($toY($w), 1), 'label' => round($w, 1)];
        }
      ?>
      <svg xmlns="http://www.w3.org/2000/svg"
           viewBox="0 0 <?php echo $svgW; ?> <?php echo $svgH; ?>"
           style="width:100%;max-width:<?php echo $svgW; ?>px;display:block;overflow:visible">
        <defs>
          <linearGradient id="wg" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%"   stop-color="#00FF41" stop-opacity="0.25"/>
            <stop offset="100%" stop-color="#00FF41" stop-opacity="0.02"/>
          </linearGradient>
          <filter id="glow">
            <feGaussianBlur stdDeviation="2" result="blur"/>
            <feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge>
          </filter>
        </defs>

        <!-- grid lines -->
        <?php foreach ($yLines as $yl): ?>
        <line x1="<?php echo $padL; ?>" y1="<?php echo $yl['y']; ?>"
              x2="<?php echo $padL + $plotW; ?>" y2="<?php echo $yl['y']; ?>"
              stroke="#00FF41" stroke-opacity="0.12" stroke-width="1"/>
        <text x="<?php echo $padL - 6; ?>" y="<?php echo $yl['y'] + 4; ?>"
              text-anchor="end" fill="#00DD33" font-family="Courier New,monospace"
              font-size="11"><?php echo $yl['label']; ?></text>
        <?php endforeach; ?>

        <!-- goal line (175 lbs) -->
        <line x1="<?php echo $padL; ?>" y1="<?php echo $goalY; ?>"
              x2="<?php echo $padL + $plotW; ?>" y2="<?php echo $goalY; ?>"
              stroke="#FFD700" stroke-opacity="0.7" stroke-width="1.5" stroke-dasharray="6,4"/>
        <text x="<?php echo $padL + $plotW + 4; ?>" y="<?php echo $goalY + 4; ?>"
              fill="#FFD700" font-family="Courier New,monospace" font-size="10">175</text>

        <!-- area fill -->
        <path d="<?php echo $areaPath; ?>" fill="url(#wg)"/>

        <!-- trend line -->
        <polyline points="<?php echo $poly; ?>"
                  fill="none" stroke="#00FF41" stroke-width="2.5"
                  stroke-linejoin="round" stroke-linecap="round" filter="url(#glow)"/>

        <!-- data points — larger invisible hit area + visible dot -->
        <?php for ($i = 0; $i < $n; $i++): ?>
        <g class="wpt"
           data-date="<?php echo $dates[$i]; ?>"
           data-val="<?php echo $vals[$i]; ?>">
          <circle cx="<?php echo round($toX($i), 1); ?>" cy="<?php echo round($toY($vals[$i]), 1); ?>"
                  r="10" fill="transparent"/>
          <circle cx="<?php echo round($toX($i), 1); ?>" cy="<?php echo round($toY($vals[$i]), 1); ?>"
                  r="3.5" fill="#00FF41" stroke="#000" stroke-width="1.5" class="wdot"/>
        </g>
        <?php endfor; ?>

        <!-- x-axis labels -->
        <?php foreach ($labelIdxs as $idx): ?>
        <text x="<?php echo round($toX($idx), 1); ?>" y="<?php echo $padT + $plotH + 18; ?>"
              text-anchor="middle" fill="#00DD33" font-family="Courier New,monospace"
              font-size="10"><?php echo substr($dates[$idx], 5); /* MM-DD */ ?></text>
        <?php endforeach; ?>

        <!-- axis box -->
        <rect x="<?php echo $padL; ?>" y="<?php echo $padT; ?>"
              width="<?php echo $plotW; ?>" height="<?php echo $plotH; ?>"
              fill="none" stroke="#00FF41" stroke-opacity="0.3" stroke-width="1"/>
      </svg>

      <!-- Floating tooltip -->
      <div id="wchart-tip" style="
        display:none;position:fixed;pointer-events:none;
        background:#000;border:1px solid #00FF41;
        color:#00FF41;font-family:'Courier New',monospace;font-size:13px;
        padding:6px 12px;border-radius:3px;
        box-shadow:0 0 12px rgba(0,255,65,0.5);
        white-space:nowrap;z-index:9999;">
      </div>

      <script>
      (function(){
        var tip = document.getElementById('wchart-tip');
        var pts = document.querySelectorAll('.wpt');
        pts.forEach(function(g){
          g.addEventListener('mouseenter', function(e){
            tip.textContent = g.dataset.date + '  →  ' + g.dataset.val + ' lbs';
            g.querySelector('.wdot').setAttribute('r','5.5');
            g.querySelector('.wdot').setAttribute('fill','#00FF00');
            tip.style.display = 'block';
          });
          g.addEventListener('mousemove', function(e){
            tip.style.left = (e.clientX + 14) + 'px';
            tip.style.top  = (e.clientY - 32) + 'px';
          });
          g.addEventListener('mouseleave', function(){
            tip.style.display = 'none';
            g.querySelector('.wdot').setAttribute('r','3.5');
            g.querySelector('.wdot').setAttribute('fill','#00FF41');
          });
        });
      })();
      </script>

      <div style="font-size:0.8em;color:#888;margin-top:6px;">
        ▬ <span style="color:#FFD700">- - -</span> Goal: 175 lbs &nbsp;|&nbsp;
        Hover dots for exact values &nbsp;|&nbsp;
        <code style="color:#555">?chart_days=N</code> to change range
        <?php if (isset($data['weight']['rate_lbs_per_week'])): ?>
        &nbsp;|&nbsp;
        <?php
          $wrate  = $data['weight']['rate_lbs_per_week'];
          $warrow = $wrate > 0.05 ? '↓' : ($wrate < -0.05 ? '↑' : '→');
          $wcolor = $wrate > 0.05 ? '#00FF41' : ($wrate < -0.05 ? '#FF4444' : '#888888');
          echo "<span style='color:{$wcolor}'>{$warrow} " . abs($wrate) . " lbs/wk</span>";
        ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($data['sleep'])): ?>
    <!-- SLEEP SECTION -->
    <div class="section">
      <h2>▌SLEEP DATA</h2>
      <?php if (!empty($data['sleep_using_recent'])): ?>
      <div style="color:#FFFF00;font-size:0.85em;margin-bottom:8px;">
        ⚠ SHOWING MOST RECENT DATA (no sleep data for <?php echo $date; ?> — showing <?php echo $data['sleep_recent_date']; ?>)
      </div>
      <?php endif; ?>
      <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
        <?php if ($data['sleep']['duration_hours']): ?>
        <div class="data-row">
          <span class="label">DURATION:</span>
          <span class="value highlight"><?php echo $data['sleep']['duration_hours']; ?> hours</span>
        </div>
        <?php endif; ?>
        <?php if ($data['sleep']['sleep_score']): ?>
        <div class="data-row">
          <span class="label">SLEEP SCORE:</span>
          <span class="value highlight"><?php echo $data['sleep']['sleep_score']; ?></span>
        </div>
        <?php endif; ?>
        <?php if ($data['sleep']['restful_hours']): ?>
        <div class="data-row">
          <span class="label">RESTFUL:</span>
          <span class="value"><?php echo $data['sleep']['restful_hours']; ?> hours</span>
        </div>
        <?php endif; ?>
        <?php if ($data['sleep']['restless_hours']): ?>
        <div class="data-row">
          <span class="label">RESTLESS:</span>
          <span class="value"><?php echo $data['sleep']['restless_hours']; ?> hours</span>
        </div>
        <?php endif; ?>
        <?php if ($data['sleep']['out_of_bed_hours']): ?>
        <div class="data-row">
          <span class="label">OUT OF BED:</span>
          <span class="value"><?php echo $data['sleep']['out_of_bed_hours']; ?> hours</span>
        </div>
        <?php endif; ?>
        <?php if ($data['sleep']['avg_heart_rate_bpm']): ?>
        <div class="data-row">
          <span class="label">AVG HEART RATE:</span>
          <span class="value"><?php echo $data['sleep']['avg_heart_rate_bpm']; ?> bpm</span>
        </div>
        <?php endif; ?>
        <?php if ($data['sleep']['avg_hrv_ms']): ?>
        <div class="data-row">
          <span class="label">AVG HRV:</span>
          <span class="value"><?php echo $data['sleep']['avg_hrv_ms']; ?> ms</span>
        </div>
        <?php endif; ?>
        <?php if ($data['sleep']['avg_respiratory_rate_brpm']): ?>
        <div class="data-row">
          <span class="label">AVG RESP RATE:</span>
          <span class="value"><?php echo $data['sleep']['avg_respiratory_rate_brpm']; ?> brpm</span>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($data['sleep_chart'])): ?>
    <!-- SLEEP TREND CHART -->
    <div class="section">
      <h2>▌SLEEP TREND — LAST <?php echo $data['sleep_chart_days']; ?> DAYS</h2>
      <?php
        $sc   = $data['sleep_chart'];
        $scD  = array_keys($sc);
        $scN  = count($scD);

        $svgW = 800; $svgH = 260;
        $padL = 52;  $padR = 44; $padT = 20; $padB = 40;
        $plotW = $svgW - $padL - $padR;
        $plotH = $svgH - $padT - $padB;

        // Score scale: fixed 0-100
        $scRangeS = 100;

        // HRV scale: dynamic (actual data range ± margin)
        $scHrvVals = array_column($sc, 'hrv');
        $scMinH = !empty($scHrvVals) ? max(0, min($scHrvVals) - 5) : 0;
        $scMaxH = !empty($scHrvVals) ? max($scHrvVals) + 5 : 100;
        $scRangeH = ($scMaxH - $scMinH) ?: 1;

        // Mapping functions
        $scToX  = fn(int $i)    => $padL + ($scN > 1 ? $i / ($scN - 1) : 0.5) * $plotW;
        $scToYS = fn(float $v)  => $padT + (1 - $v / $scRangeS) * $plotH;
        $scToYH = fn(float $v)  => $padT + (1 - ($v - $scMinH) / $scRangeH) * $plotH;

        // Build score polyline + area path
        $scScorePoly = [];
        for ($i = 0; $i < $scN; $i++) {
          $v = $sc[$scD[$i]]['score'] ?? null;
          if ($v !== null) $scScorePoly[] = ['i' => $i, 'x' => round($scToX($i), 1), 'y' => round($scToYS($v), 1)];
        }
        $scScorePolyStr = implode(' ', array_map(fn($p) => $p['x'] . ',' . $p['y'], $scScorePoly));
        $scScoreArea = '';
        if (!empty($scScorePoly)) {
          $f = $scScorePoly[0]; $l = $scScorePoly[count($scScorePoly) - 1];
          $scScoreArea = 'M' . $f['x'] . ',' . $f['y'];
          for ($i = 1; $i < count($scScorePoly); $i++) $scScoreArea .= ' L' . $scScorePoly[$i]['x'] . ',' . $scScorePoly[$i]['y'];
          $scScoreArea .= ' L' . $l['x'] . ',' . ($padT + $plotH) . ' L' . $f['x'] . ',' . ($padT + $plotH) . ' Z';
        }

        // Build HRV polyline
        $scHrvPts = [];
        for ($i = 0; $i < $scN; $i++) {
          $v = $sc[$scD[$i]]['hrv'] ?? null;
          if ($v !== null) $scHrvPts[] = round($scToX($i), 1) . ',' . round($scToYH($v), 1);
        }
        $scHrvPolyStr = implode(' ', $scHrvPts);

        // X-axis labels (~5 evenly spaced)
        $scLabelIdxs = [];
        $scSteps = min($scN, 5);
        for ($s = 0; $s < $scSteps; $s++) $scLabelIdxs[] = (int)round($s * ($scN - 1) / max($scSteps - 1, 1));

        // Left Y-axis labels (score: 0, 25, 50, 75, 100)
        $scYLinesS = [];
        for ($j = 0; $j <= 4; $j++) {
          $v = $scRangeS * $j / 4;
          $scYLinesS[] = ['y' => round($scToYS($v), 1), 'label' => (int)$v];
        }

        // Right Y-axis labels (HRV: 4 ticks)
        $scYLinesH = [];
        if (!empty($scHrvVals)) {
          for ($j = 0; $j <= 3; $j++) {
            $v = $scMinH + ($scRangeH * $j / 3);
            $scYLinesH[] = ['y' => round($scToYH($v), 1), 'label' => (int)round($v)];
          }
        }
      ?>
      <svg xmlns="http://www.w3.org/2000/svg"
           viewBox="0 0 <?php echo $svgW; ?> <?php echo $svgH; ?>"
           style="width:100%;max-width:<?php echo $svgW; ?>px;display:block;overflow:visible">
        <defs>
          <linearGradient id="sg" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%"   stop-color="#00BFFF" stop-opacity="0.25"/>
            <stop offset="100%" stop-color="#00BFFF" stop-opacity="0.02"/>
          </linearGradient>
          <filter id="sglow">
            <feGaussianBlur stdDeviation="2" result="blur"/>
            <feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge>
          </filter>
        </defs>

        <!-- Grid lines (aligned to score axis) -->
        <?php foreach ($scYLinesS as $yl): ?>
        <line x1="<?php echo $padL; ?>" y1="<?php echo $yl['y']; ?>"
              x2="<?php echo $padL + $plotW; ?>" y2="<?php echo $yl['y']; ?>"
              stroke="#00BFFF" stroke-opacity="0.12" stroke-width="1"/>
        <text x="<?php echo $padL - 6; ?>" y="<?php echo $yl['y'] + 4; ?>"
              text-anchor="end" fill="#00AACC" font-family="Courier New,monospace"
              font-size="11"><?php echo $yl['label']; ?></text>
        <?php endforeach; ?>

        <!-- Right axis labels (HRV ms) -->
        <?php foreach ($scYLinesH as $yl): ?>
        <text x="<?php echo $padL + $plotW + 6; ?>" y="<?php echo $yl['y'] + 4; ?>"
              text-anchor="start" fill="#FF8C00" font-family="Courier New,monospace"
              font-size="9"><?php echo $yl['label']; ?></text>
        <?php endforeach; ?>

        <!-- Score area fill -->
        <?php if ($scScoreArea): ?>
        <path d="<?php echo $scScoreArea; ?>" fill="url(#sg)"/>
        <?php endif; ?>

        <!-- Score trend line -->
        <?php if ($scScorePolyStr): ?>
        <polyline points="<?php echo $scScorePolyStr; ?>"
                  fill="none" stroke="#00BFFF" stroke-width="2.5"
                  stroke-linejoin="round" stroke-linecap="round" filter="url(#sglow)"/>
        <?php endif; ?>

        <!-- HRV line (dashed, orange) -->
        <?php if ($scHrvPolyStr): ?>
        <polyline points="<?php echo $scHrvPolyStr; ?>"
                  fill="none" stroke="#FF8C00" stroke-width="1.5"
                  stroke-dasharray="4,3"
                  stroke-linejoin="round" stroke-linecap="round"/>
        <?php endif; ?>

        <!-- Data points with tooltip data -->
        <?php for ($i = 0; $i < $scN; $i++):
          $pt = $sc[$scD[$i]];
          if (!isset($pt['score'])) continue;
        ?>
        <g class="slpt"
           data-date="<?php echo $scD[$i]; ?>"
           data-score="<?php echo $pt['score']; ?>"
           data-hrv="<?php echo $pt['hrv'] ?? ''; ?>"
           data-dur="<?php echo $pt['duration'] ?? ''; ?>"
           data-hr="<?php echo $pt['hr'] ?? ''; ?>"
           data-rr="<?php echo $pt['rr'] ?? ''; ?>">
          <circle cx="<?php echo round($scToX($i), 1); ?>" cy="<?php echo round($scToYS($pt['score']), 1); ?>"
                  r="10" fill="transparent"/>
          <circle cx="<?php echo round($scToX($i), 1); ?>" cy="<?php echo round($scToYS($pt['score']), 1); ?>"
                  r="3.5" fill="#00BFFF" stroke="#000" stroke-width="1.5" class="sldot"/>
        </g>
        <?php endfor; ?>

        <!-- X-axis labels -->
        <?php foreach ($scLabelIdxs as $idx): ?>
        <text x="<?php echo round($scToX($idx), 1); ?>" y="<?php echo $padT + $plotH + 18; ?>"
              text-anchor="middle" fill="#00AACC" font-family="Courier New,monospace"
              font-size="10"><?php echo substr($scD[$idx], 5); ?></text>
        <?php endforeach; ?>

        <!-- Axis labels -->
        <text x="<?php echo $padL - 6; ?>" y="<?php echo $padT - 6; ?>"
              text-anchor="end" fill="#00AACC" font-family="Courier New,monospace" font-size="9">score</text>
        <?php if (!empty($scHrvVals)): ?>
        <text x="<?php echo $padL + $plotW + 6; ?>" y="<?php echo $padT - 6; ?>"
              text-anchor="start" fill="#FF8C00" font-family="Courier New,monospace" font-size="9">HRV ms</text>
        <?php endif; ?>

        <!-- Axis border -->
        <rect x="<?php echo $padL; ?>" y="<?php echo $padT; ?>"
              width="<?php echo $plotW; ?>" height="<?php echo $plotH; ?>"
              fill="none" stroke="#00BFFF" stroke-opacity="0.3" stroke-width="1"/>
      </svg>

      <!-- Floating tooltip -->
      <div id="schart-tip" style="
        display:none;position:fixed;pointer-events:none;
        background:#000;border:1px solid #00BFFF;
        color:#00BFFF;font-family:'Courier New',monospace;font-size:12px;
        padding:6px 12px;border-radius:3px;
        box-shadow:0 0 12px rgba(0,191,255,0.5);
        white-space:pre;z-index:9999;"></div>

      <script>
      (function(){
        var tip = document.getElementById('schart-tip');
        document.querySelectorAll('.slpt').forEach(function(g){
          g.addEventListener('mouseenter', function(){
            var lines = [g.dataset.date];
            if (g.dataset.score) lines.push('Score:    ' + g.dataset.score);
            if (g.dataset.dur)   lines.push('Duration: ' + g.dataset.dur + ' hrs');
            if (g.dataset.hrv)   lines.push('HRV:      ' + g.dataset.hrv + ' ms');
            if (g.dataset.hr)    lines.push('Heart:    ' + g.dataset.hr + ' bpm');
            if (g.dataset.rr)    lines.push('Resp:     ' + g.dataset.rr + ' brpm');
            tip.textContent = lines.join('\n');
            g.querySelector('.sldot').setAttribute('r','5.5');
            g.querySelector('.sldot').setAttribute('fill','#00FFFF');
            tip.style.display = 'block';
          });
          g.addEventListener('mousemove', function(e){
            tip.style.left = (e.clientX + 14) + 'px';
            tip.style.top  = (e.clientY - 32) + 'px';
          });
          g.addEventListener('mouseleave', function(){
            tip.style.display = 'none';
            g.querySelector('.sldot').setAttribute('r','3.5');
            g.querySelector('.sldot').setAttribute('fill','#00BFFF');
          });
        });
      })();
      </script>

      <div style="font-size:0.8em;color:#888;margin-top:6px;">
        <span style="color:#00BFFF">——</span> Sleep Score (0-100) &nbsp;|&nbsp;
        <?php if (!empty($scHrvVals)): ?>
        <span style="color:#FF8C00">- - -</span> HRV (ms) &nbsp;|&nbsp;
        <?php endif; ?>
        Hover dots for all metrics
      </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($data['measurements']['categories'])): ?>
    <!-- MEASUREMENTS SECTION -->
    <div class="section">
      <h2>▌BODY MEASUREMENTS</h2>
      <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
        <?php foreach ($data['measurements']['categories'] as $name => $info): ?>
        <div class="data-row">
          <span class="label"><?php echo strtoupper($name); ?>:</span>
          <span class="value"><?php echo $info['value']; ?> <?php echo $info['unit'] ?? ''; ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if (isset($data['workouts']['sessions_today']) && $data['workouts']['sessions_today'] > 0): ?>
    <!-- WORKOUTS SECTION -->
    <div class="section">
      <h2>▌WORKOUT DATA</h2>
      <div class="data-row">
        <span class="label">SESSIONS TODAY:</span>
        <span class="value highlight"><?php echo $data['workouts']['sessions_today']; ?></span>
      </div>
      <?php foreach ($data['workouts']['sessions'] as $i => $session): ?>
        <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid rgba(0, 255, 65, 0.3);">
          <div class="highlight">SESSION <?php echo $i + 1; ?></div>
          <?php if (isset($session['workout_name'])): ?>
          <div class="data-row">
            <span class="label">WORKOUT:</span>
            <span class="value"><?php echo $session['workout_name']; ?></span>
          </div>
          <?php endif; ?>
          <?php if (isset($session['time'])): ?>
          <div class="data-row">
            <span class="label">TIME:</span>
            <span class="value"><?php echo $session['time']; ?></span>
          </div>
          <?php endif; ?>
          <?php if (isset($session['notes']) && $session['notes']): ?>
          <div class="data-row">
            <span class="label">NOTES:</span>
            <span class="value"><?php echo $session['notes']; ?></span>
          </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($data['records'])): ?>
    <?php $rec = $data['records']; ?>
    <div class="section">
      <h2>▌RECORDS &amp; STREAKS</h2>

      <?php
        // Helper: render a streak badge
        function streak_badge(int $current, int $best, string $label): string {
          $fire  = $current >= 7 ? ' 🔥' : '';
          $cbg   = $current > 0 ? 'rgba(0,255,65,0.15)' : 'transparent';
          $ccol  = $current > 0 ? '#00FF41' : '#555';
          return "
          <div style='border:1px solid rgba(0,255,65,0.3);padding:12px;'>
            <div style='color:#00CC28;font-size:0.72em;letter-spacing:1px;margin-bottom:6px;'>{$label}</div>
            <div style='display:flex;gap:14px;align-items:flex-end;'>
              <div style='background:{$cbg};padding:6px 12px;border:1px solid {$ccol};'>
                <div style='font-size:0.65em;color:#00AA22;letter-spacing:1px;'>CURRENT</div>
                <div style='font-size:1.4em;color:{$ccol};font-weight:bold;'>{$current}d{$fire}</div>
              </div>
              <div style='padding:6px 12px;border:1px solid rgba(0,255,65,0.2);'>
                <div style='font-size:0.65em;color:#00AA22;letter-spacing:1px;'>BEST</div>
                <div style='font-size:1.4em;color:#00DD33;font-weight:bold;'>{$best}d</div>
              </div>
            </div>
          </div>";
        }
      ?>

      <!-- All-time weight records -->
      <?php if (!empty($rec['weight_all_time_low']) || !empty($rec['weight_all_time_high'])): ?>
      <div style="margin-bottom:16px;">
        <div style="color:#00CC28;font-size:0.8em;letter-spacing:2px;margin-bottom:10px;">ALL-TIME WEIGHT</div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;">
          <?php if (isset($rec['weight_all_time_low'])): ?>
          <div style="border:1px solid rgba(0,255,65,0.3);padding:12px;">
            <div style="color:#00AA22;font-size:0.7em;letter-spacing:1px;">LOWEST EVER</div>
            <div style="color:#00FF41;font-size:1.4em;font-weight:bold;"><?php echo $rec['weight_all_time_low']; ?> lbs</div>
          </div>
          <?php endif; ?>
          <?php if (isset($rec['weight_all_time_high'])): ?>
          <div style="border:1px solid rgba(0,255,65,0.3);padding:12px;">
            <div style="color:#00AA22;font-size:0.7em;letter-spacing:1px;">HIGHEST EVER</div>
            <div style="color:#555;font-size:1.4em;font-weight:bold;"><?php echo $rec['weight_all_time_high']; ?> lbs</div>
          </div>
          <?php endif; ?>
          <?php if (isset($rec['weight_start'], $rec['weight_start_date'])): ?>
          <div style="border:1px solid rgba(0,255,65,0.3);padding:12px;">
            <div style="color:#00AA22;font-size:0.7em;letter-spacing:1px;">FIRST RECORDED</div>
            <div style="color:#888;font-size:1.4em;font-weight:bold;"><?php echo $rec['weight_start']; ?> lbs</div>
            <div style="color:#444;font-size:0.7em;"><?php echo $rec['weight_start_date']; ?></div>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Streak grid -->
      <div style="color:#00CC28;font-size:0.8em;letter-spacing:2px;margin-bottom:10px;">90-DAY STREAKS</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;margin-bottom:16px;">
        <?php if (!empty($rec['cal_streak'])): ?>
        <?php echo streak_badge($rec['cal_streak']['current'], $rec['cal_streak']['best'], 'CALORIES ≤ 1500 kcal'); ?>
        <?php endif; ?>
        <?php if (!empty($rec['prot_streak'])): ?>
        <?php echo streak_badge($rec['prot_streak']['current'], $rec['prot_streak']['best'], 'PROTEIN ≥ 150g'); ?>
        <?php endif; ?>
        <?php if (!empty($rec['steps_streak'])): ?>
        <?php echo streak_badge($rec['steps_streak']['current'], $rec['steps_streak']['best'], 'STEPS ≥ 10,000'); ?>
        <?php endif; ?>
        <?php if (!empty($rec['sleep_streak'])): ?>
        <?php echo streak_badge($rec['sleep_streak']['current'], $rec['sleep_streak']['best'], 'SLEEP SCORE ≥ 85'); ?>
        <?php endif; ?>
      </div>

      <!-- 90-day bests -->
      <div style="color:#00CC28;font-size:0.8em;letter-spacing:2px;margin-bottom:10px;">90-DAY BESTS &amp; AVERAGES</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;">
        <?php if (!empty($rec['cal_best'])): ?>
        <div style="border:1px solid rgba(0,255,65,0.25);padding:10px;">
          <div style="color:#00AA22;font-size:0.68em;letter-spacing:1px;">LOWEST CAL DAY</div>
          <div style="color:#00FF41;font-size:1.1em;"><?php echo number_format($rec['cal_best']); ?> kcal</div>
          <div style="color:#555;font-size:0.7em;">avg <?php echo number_format($rec['cal_avg'] ?? 0); ?></div>
        </div>
        <?php endif; ?>
        <?php if (!empty($rec['prot_best'])): ?>
        <div style="border:1px solid rgba(0,255,65,0.25);padding:10px;">
          <div style="color:#00AA22;font-size:0.68em;letter-spacing:1px;">BEST PROTEIN DAY</div>
          <div style="color:#00FF41;font-size:1.1em;"><?php echo $rec['prot_best']; ?>g</div>
          <div style="color:#555;font-size:0.7em;">avg <?php echo $rec['prot_avg'] ?? '—'; ?>g</div>
        </div>
        <?php endif; ?>
        <?php if (!empty($rec['steps_best'])): ?>
        <div style="border:1px solid rgba(0,255,65,0.25);padding:10px;">
          <div style="color:#00AA22;font-size:0.68em;letter-spacing:1px;">BEST STEP DAY</div>
          <div style="color:#00FF41;font-size:1.1em;"><?php echo number_format($rec['steps_best']); ?></div>
          <div style="color:#555;font-size:0.7em;">avg <?php echo number_format($rec['steps_avg'] ?? 0); ?></div>
        </div>
        <?php endif; ?>
        <?php if (!empty($rec['sleep_best'])): ?>
        <div style="border:1px solid rgba(0,255,65,0.25);padding:10px;">
          <div style="color:#00AA22;font-size:0.68em;letter-spacing:1px;">BEST SLEEP SCORE</div>
          <div style="color:#00FF41;font-size:1.1em;"><?php echo $rec['sleep_best']; ?>/100</div>
          <div style="color:#555;font-size:0.7em;">avg <?php echo $rec['sleep_avg'] ?? '—'; ?></div>
        </div>
        <?php endif; ?>
      </div>

    </div>
    <?php endif; ?>

    <div style="text-align: center; margin-top: 40px; opacity: 0.6;">
      <span class="blink">▌</span> END TRANSMISSION <span class="blink">▐</span>
    </div>
  </div>
</body>
</html>
<?php

  } else {
    // Plain text (ChatGPT-friendly format)
    echo "=== WGER HEALTH DATA ===\n";
    echo "Date: {$data['date']}\n";
    if (!empty($data['using_most_recent'])) {
      echo "Note: Showing most recent data (requested {$data['requested_date']} had none)\n";
    }
    echo "Generated: {$data['generated_at']}\n\n";

    echo "--- WEIGHT ---\n";
    echo "Current: " . ($data['weight']['current_lb'] ?? 'N/A') . " lbs (as of {$data['weight']['date_of_reading']})\n";
    if (isset($data['weight']['starting_weight_lb'], $data['weight']['starting_date'])) {
      echo "Starting: " . $data['weight']['starting_weight_lb'] . " lbs (on {$data['weight']['starting_date']})\n";
    }
    echo "7-day trend: " . (isset($data['weight']['trend_7d_lb']) ? round($data['weight']['trend_7d_lb'], 1) : 'N/A') . " lbs\n";
    echo "30-day trend: " . (isset($data['weight']['trend_30d_lb']) ? round($data['weight']['trend_30d_lb'], 1) : 'N/A') . " lbs\n";
    echo "90-day trend: " . (isset($data['weight']['trend_90d_lb']) ? round($data['weight']['trend_90d_lb'], 1) : 'N/A') . " lbs\n";
    if (isset($data['weight']['total_change_lb'])) {
      echo "Total change: " . ($data['weight']['total_change_lb'] > 0 ? '+' : '') . $data['weight']['total_change_lb'] . " lbs (from starting weight)\n";
    }
    echo "\n";

    echo "--- NUTRITION ---\n";
    echo "Calories (Food): " . round($data['nutrition']['calories']) . " / " . ($data['nutrition']['goal_calories'] ?? 'N/A') . " kcal\n";
    if ($data['nutrition']['exercise_cals'] > 0) {
      echo "Exercise Burned: " . round($data['nutrition']['exercise_cals']) . " kcal\n";
    }
    if ($data['nutrition']['remaining_calories'] !== null) {
      echo "Remaining: " . round($data['nutrition']['remaining_calories']) . " kcal\n";
    }
    echo "Protein: " . round($data['nutrition']['protein_g'], 1) . " g\n";
    echo "Carbs: " . round($data['nutrition']['carbs_g'], 1) . " g\n";
    echo "Fat: " . round($data['nutrition']['fat_g'], 1) . " g\n";
    if (isset($data['nutrition']['sodium_mg']) && $data['nutrition']['sodium_mg'] > 0) {
      echo "Sodium: " . round($data['nutrition']['sodium_mg']) . " mg\n";
    }

    // Additional nutrition details (only if > 0)
    if (($data['nutrition']['fiber_g'] ?? 0) > 0) {
      echo "Fiber: " . round($data['nutrition']['fiber_g'], 1) . " g\n";
    }
    if (($data['nutrition']['sugar_g'] ?? 0) > 0) {
      echo "Sugar: " . round($data['nutrition']['sugar_g'], 1) . " g\n";
    }
    if (($data['nutrition']['cholesterol_mg'] ?? 0) > 0) {
      echo "Cholesterol: " . round($data['nutrition']['cholesterol_mg']) . " mg\n";
    }
    if (($data['nutrition']['potassium_mg'] ?? 0) > 0) {
      echo "Potassium: " . round($data['nutrition']['potassium_mg']) . " mg\n";
    }
    if (($data['nutrition']['saturated_fat_g'] ?? 0) > 0) {
      echo "Saturated Fat: " . round($data['nutrition']['saturated_fat_g'], 1) . " g\n";
    }
    if (($data['nutrition']['trans_fat_g'] ?? 0) > 0) {
      echo "Trans Fat: " . round($data['nutrition']['trans_fat_g'], 1) . " g\n";
    }
    if (($data['nutrition']['vitamin_a_iu'] ?? 0) > 0) {
      echo "Vitamin A: " . round($data['nutrition']['vitamin_a_iu']) . " IU\n";
    }
    if (($data['nutrition']['vitamin_c_mg'] ?? 0) > 0) {
      echo "Vitamin C: " . round($data['nutrition']['vitamin_c_mg']) . " mg\n";
    }
    if (($data['nutrition']['calcium_mg'] ?? 0) > 0) {
      echo "Calcium: " . round($data['nutrition']['calcium_mg']) . " mg\n";
    }
    if (($data['nutrition']['iron_mg'] ?? 0) > 0) {
      echo "Iron: " . round($data['nutrition']['iron_mg'], 1) . " mg\n";
    }

    echo "\n";

    if (!empty($data['sleep'])) {
      echo "--- SLEEP ---\n";
      if ($data['sleep']['duration_hours']) echo "Duration: {$data['sleep']['duration_hours']} hours\n";
      if ($data['sleep']['sleep_score']) echo "Sleep Score: {$data['sleep']['sleep_score']}\n";
      if ($data['sleep']['restful_hours']) echo "Restful: {$data['sleep']['restful_hours']} hours\n";
      if ($data['sleep']['restless_hours']) echo "Restless: {$data['sleep']['restless_hours']} hours\n";
      if ($data['sleep']['out_of_bed_hours']) echo "Out of Bed: {$data['sleep']['out_of_bed_hours']} hours\n";
      if ($data['sleep']['avg_heart_rate_bpm']) echo "Avg Heart Rate: {$data['sleep']['avg_heart_rate_bpm']} bpm\n";
      if ($data['sleep']['avg_hrv_ms']) echo "Avg HRV: {$data['sleep']['avg_hrv_ms']} ms\n";
      if ($data['sleep']['avg_respiratory_rate_brpm']) echo "Avg Resp Rate: {$data['sleep']['avg_respiratory_rate_brpm']} brpm\n";
      echo "\n";
    }

    if (!empty($data['measurements']['categories'])) {
      echo "--- MEASUREMENTS ---\n";
      foreach ($data['measurements']['categories'] as $name => $info) {
        $unit = $info['unit'] ?? '';
        echo "$name: {$info['value']} $unit\n";
      }
      echo "\n";
    }

    if (isset($data['workouts']['sessions_today']) && $data['workouts']['sessions_today'] > 0) {
      echo "--- WORKOUTS ---\n";
      echo "Sessions today: {$data['workouts']['sessions_today']}\n";
      foreach ($data['workouts']['sessions'] as $i => $session) {
        $num = $i + 1;
        echo "\nSession $num:\n";
        if (isset($session['workout_name'])) echo "  Workout: {$session['workout_name']}\n";
        if (isset($session['time'])) echo "  Time: {$session['time']}\n";
        if (isset($session['notes']) && $session['notes']) echo "  Notes: {$session['notes']}\n";
      }
      echo "\n";
    }

    echo "--- END ---\n";
  }

} catch (Throwable $e) {
  http_response_code(500);
  echo "ERROR: " . $e->getMessage() . "\n";
  echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
