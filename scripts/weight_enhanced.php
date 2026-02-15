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
 * Date parameter: ?date=YYYY-MM-DD (defaults to today)
 */
declare(strict_types=1);

// Set timezone to Eastern Time for all date/time displays
date_default_timezone_set('America/New_York');

header('Content-Type: text/plain; charset=utf-8');

// PRIVATE VERSION - Hardcoded credentials (DO NOT commit to git!)
$WGER_BASE  = 'https://your-wger-instance.com';
$WGER_TOKEN = 'your_wger_api_token_here';

$date = $_GET['date'] ?? date('Y-m-d'); // Use LOCAL date, not UTC
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
          $value = $value * 0.621371;
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
    $measurementResp = wger_get($WGER_BASE, $WGER_TOKEN, '/api/v2/measurement/', [
      'category' => 18, // Daily Calories category
      'limit' => 1,
      'ordering' => '-date',
    ]);

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
              $value = $value * 0.621371;
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

  // Extract body composition data from WGER measurements
  // These are posted by daily_health_sync.py from Withings scale
  try {
    $bodyfat_resp = wger_get($WGER_BASE, $WGER_TOKEN, '/api/v2/measurement/', [
      'category' => 23, // Body Fat %
      'date' => $date,
      'limit' => 1,
    ]);
    $bodyfat_pct = ($bodyfat_resp['results'][0]['value'] ?? null);

    $muscle_resp = wger_get($WGER_BASE, $WGER_TOKEN, '/api/v2/measurement/', [
      'category' => 24, // Muscle Mass (kg)
      'date' => $date,
      'limit' => 1,
    ]);
    $muscle_mass_kg = ($muscle_resp['results'][0]['value'] ?? null);

    $bone_resp = wger_get($WGER_BASE, $WGER_TOKEN, '/api/v2/measurement/', [
      'category' => 25, // Bone Mass (kg)
      'date' => $date,
      'limit' => 1,
    ]);
    $bone_mass_kg = ($bone_resp['results'][0]['value'] ?? null);

    $hydration_resp = wger_get($WGER_BASE, $WGER_TOKEN, '/api/v2/measurement/', [
      'category' => 26, // Hydration %
      'date' => $date,
      'limit' => 1,
    ]);
    $hydration_pct = ($hydration_resp['results'][0]['value'] ?? null);
  } catch (Throwable $e) {
    $bodyfat_pct = null;
    $muscle_mass_kg = null;
    $bone_mass_kg = null;
    $hydration_pct = null;
  }

  // Extract energy and macro data
  $intake_kcal = $data['nutrition']['calories'] ?? 0;
  $exercise_mfp_kcal = $data['nutrition']['exercise_cals'] ?? 0;
  $goal_intake_kcal = $data['nutrition']['goal_calories'] ?? 1500;
  $remaining_mfp_kcal = $data['nutrition']['remaining_calories'] ?? 0;
  $protein_g = $data['nutrition']['protein_g'] ?? 0;
  $carbs_g = $data['nutrition']['carbs_g'] ?? 0;
  $fat_g = $data['nutrition']['fat_g'] ?? 0;
  $sodium_mg = $data['measurements']['categories']['Daily Sodium']['value'] ?? 0;

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
  $data['metabolism'] = [
    'bmr_kcal' => round($bmr, 0),   // Basal Metabolic Rate (at rest)
    'tdee_kcal' => round($tdee, 0), // Total Daily Energy Expenditure (BMR × activity factor)
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
    // Get last 90 days of body fat data
    $bodyfat_history = wger_get($WGER_BASE, $WGER_TOKEN, '/api/v2/measurement/', [
      'category' => 23,
      'limit' => 100,
      'ordering' => '-date',
    ]);

    $bodyfat_entries = $bodyfat_history['results'] ?? [];

    // Calculate 30-day average
    $recent_30d = array_filter($bodyfat_entries, function($e) use ($date) {
      $eDate = extract_date($e['date'] ?? '');
      $diffDays = (strtotime($date) - strtotime($eDate)) / 86400;
      return $diffDays >= 0 && $diffDays <= 30;
    });

    if (count($recent_30d) > 0) {
      $bodyfat_trend_30d = array_sum(array_column($recent_30d, 'value')) / count($recent_30d);
    }

    // Calculate 90-day average
    $recent_90d = array_filter($bodyfat_entries, function($e) use ($date) {
      $eDate = extract_date($e['date'] ?? '');
      $diffDays = (strtotime($date) - strtotime($eDate)) / 86400;
      return $diffDays >= 0 && $diffDays <= 90;
    });

    if (count($recent_90d) > 0) {
      $bodyfat_trend_90d = array_sum(array_column($recent_90d, 'value')) / count($recent_90d);
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
    </div>

    <?php if (!empty($data['sleep'])): ?>
    <!-- SLEEP SECTION -->
    <div class="section">
      <h2>▌SLEEP DATA</h2>
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
