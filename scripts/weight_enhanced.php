<?php
/**
 * Enhanced WGER Data Feed - ChatGPT-Optimized Health Dashboard
 *
 * Data Contract (units are locked and guaranteed):
 * - Distance: ALWAYS km (never miles or meters)
 * - Weight: ALWAYS lb (never kg)
 * - Calories: ALWAYS kcal (never kJ)
 * - Steps: ALWAYS whole number (converted from ksteps)
 * - Body composition: bodyfat_pct = %, mass values = kg, hydration = %
 * - Hydration: ALWAYS ml when tracked
 *
 * Output formats: ?format=text (default), json, markdown
 * Date parameter: ?date=YYYY-MM-DD (defaults to today)
 */
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

$WGER_BASE  = rtrim(getenv('WGER_BASE_URL') ?: 'https://localhost');
$WGER_TOKEN = getenv('WGER_TOKEN') ?: '';

if ($WGER_TOKEN === '') {
  http_response_code(500);
  echo "ERROR: missing WGER_TOKEN\n";
  exit;
}

$date = $_GET['date'] ?? gmdate('Y-m-d');
$requestedDate = $date;
$format = $_GET['format'] ?? 'text'; // text, json, or markdown

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
    'generated_at' => gmdate('Y-m-d H:i:s') . ' UTC',
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

  // Weight change calculations
  if ($weight_lb !== null && count($weightEntries) > 1) {
    $earliest = end($weightEntries);
    $earliest_weight = try_number($earliest['weight']);
    if ($earliest_weight !== null) {
      $data['weight']['total_change_lb'] = round($weight_lb - $earliest_weight, 2);
      $data['weight']['earliest_date'] = $earliest['date'] ?? null;
    }
  }

  // ---------------------------------------------------------------------------
  // 2. NUTRITION DATA (Calories + Macros)
  // ---------------------------------------------------------------------------
  // Read nutrition data from WGER measurements (posted by sync_mfp_wger.py)
  // Category IDs:
  //   18 = Daily Calories (kcal)
  //   19 = Daily Protein (g)
  //   20 = Daily Carbs (g)
  //   21 = Daily Fat (g)
  //   22 = MFP Exercise Calories (kcal)

  $nutrition = [
    'calories' => 0,
    'protein_g' => 0,
    'carbs_g' => 0,
    'fat_g' => 0,
    'exercise_cals' => 0,
    'source' => 'measurements',
  ];

  // Fetch nutrition measurements from WGER
  $nutritionCategoryIds = [18, 19, 20, 21, 22]; // Calories, Protein, Carbs, Fat, Exercise

  foreach ($nutritionCategoryIds as $categoryId) {
    try {
      $measurementResp = wger_get($WGER_BASE, $WGER_TOKEN, '/api/v2/measurement/', [
        'category' => $categoryId,
        'date' => $date,
        'limit' => 1,
      ]);

      $results = $measurementResp['results'] ?? [];
      if (!empty($results)) {
        $value = try_number($results[0]['value'] ?? null) ?? 0;

        // Map category ID to nutrition field
        switch ($categoryId) {
          case 18: $nutrition['calories'] = $value; break;
          case 19: $nutrition['protein_g'] = $value; break;
          case 20: $nutrition['carbs_g'] = $value; break;
          case 21: $nutrition['fat_g'] = $value; break;
          case 22: $nutrition['exercise_cals'] = $value; break;
        }
      }
    } catch (Throwable $e) {
      // Continue on error
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
  if ($requestedDate === gmdate('Y-m-d') && $data['nutrition']['calories'] === 0) {
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
  $distance_km = $data['measurements']['categories']['Distance']['value'] ?? 0;
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
    echo "- **7-day Average:** " . (isset($data['weight']['trend_7d_lb']) ? round($data['weight']['trend_7d_lb'], 1) : 'N/A') . " lbs\n";
    echo "- **30-day Average:** " . (isset($data['weight']['trend_30d_lb']) ? round($data['weight']['trend_30d_lb'], 1) : 'N/A') . " lbs\n";
    if (isset($data['weight']['total_change_lb'])) {
      echo "- **Total Change:** " . ($data['weight']['total_change_lb'] > 0 ? '+' : '') . $data['weight']['total_change_lb'] . " lbs (since {$data['weight']['earliest_date']})\n";
    }
    echo "\n";

    echo "## Nutrition\n";
    echo "- **Calories:** {$data['nutrition']['calories']} / " . ($data['nutrition']['goal_calories'] ?? 'N/A') . " kcal";
    if ($data['nutrition']['remaining_calories'] !== null) {
      echo " (" . ($data['nutrition']['remaining_calories'] > 0 ? '' : '') . round($data['nutrition']['remaining_calories']) . " remaining)";
    }
    echo "\n";
    echo "- **Protein:** " . round($data['nutrition']['protein_g'], 1) . " g\n";
    echo "- **Carbs:** " . round($data['nutrition']['carbs_g'], 1) . " g\n";
    echo "- **Fat:** " . round($data['nutrition']['fat_g'], 1) . " g\n";
    echo "- **Fiber:** " . round($data['nutrition']['fiber_g'], 1) . " g\n";
    echo "- **Entries:** {$data['nutrition']['entries_count']}\n\n";

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
    echo "7-day trend: " . (isset($data['weight']['trend_7d_lb']) ? round($data['weight']['trend_7d_lb'], 1) : 'N/A') . " lbs\n";
    echo "30-day trend: " . (isset($data['weight']['trend_30d_lb']) ? round($data['weight']['trend_30d_lb'], 1) : 'N/A') . " lbs\n";
    echo "90-day trend: " . (isset($data['weight']['trend_90d_lb']) ? round($data['weight']['trend_90d_lb'], 1) : 'N/A') . " lbs\n";
    if (isset($data['weight']['total_change_lb'])) {
      echo "Total change: " . ($data['weight']['total_change_lb'] > 0 ? '+' : '') . $data['weight']['total_change_lb'] . " lbs\n";
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
    echo "\n";

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
