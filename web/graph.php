<?php
/**
 * graph.php — Single-metric SVG line chart
 * Usage: graph.php?metric=muscle_mass&days=90
 */
declare(strict_types=1);
date_default_timezone_set('America/New_York');

$WGER_BASE  = getenv('WGER_BASE') ?: 'https://your-wger-instance.com';
$WGER_TOKEN = getenv('WGER_TOKEN') ?: 'your_wger_api_token_here';

// ============================================================================
// METRIC REGISTRY
// ============================================================================
$METRICS = [
  'weight'          => ['label' => 'Weight',              'unit' => 'lbs',     'source' => 'weightentry', 'category' => null,                      'factor' => 1.0],
  'body_fat'        => ['label' => 'Body Fat',            'unit' => '%',       'source' => 'measurement', 'category' => 'Body Fat',                 'factor' => 1.0],
  'muscle_mass'     => ['label' => 'Muscle Mass',         'unit' => 'lbs',     'source' => 'measurement', 'category' => 'Muscle Mass',              'factor' => 2.20462],
  'bone_mass'       => ['label' => 'Bone Mass',           'unit' => 'lbs',     'source' => 'measurement', 'category' => 'Bone Mass',                'factor' => 2.20462],
  'hydration'       => ['label' => 'Hydration',           'unit' => '%',       'source' => 'measurement', 'category' => 'Hydration',                'factor' => 1.0],
  'bmr'             => ['label' => 'BMR',                 'unit' => 'kcal/day','source' => 'measurement', 'category' => 'Basal Metabolic Rate',     'factor' => 1.0],
  'metabolic_age'   => ['label' => 'Metabolic Age',       'unit' => 'yrs',     'source' => 'measurement', 'category' => 'Metabolic Age',            'factor' => 1.0],
  'visceral_fat'    => ['label' => 'Visceral Fat',        'unit' => 'index',   'source' => 'measurement', 'category' => 'Visceral Fat',             'factor' => 1.0],
  'steps'           => ['label' => 'Steps',               'unit' => 'steps',   'source' => 'measurement', 'category' => 'Steps',                    'factor' => 1000.0],
  'distance'        => ['label' => 'Distance',            'unit' => 'mi',      'source' => 'measurement', 'category' => 'Distance',                 'factor' => 0.621371],
  'calories'        => ['label' => 'Food Calories',       'unit' => 'kcal',    'source' => 'measurement', 'category' => 'Daily Calories',           'factor' => 1.0],
  'protein'         => ['label' => 'Protein',             'unit' => 'g',       'source' => 'measurement', 'category' => 'Daily Protein',            'factor' => 1.0],
  'carbs'           => ['label' => 'Carbs',               'unit' => 'g',       'source' => 'measurement', 'category' => 'Daily Carbs',              'factor' => 1.0],
  'fat'             => ['label' => 'Fat',                 'unit' => 'g',       'source' => 'measurement', 'category' => 'Daily Fat',                'factor' => 1.0],
  'exercise_calories' => ['label' => 'Exercise Cal',      'unit' => 'kcal',    'source' => 'measurement', 'category' => 'MFP Exercise Calories',    'factor' => 1.0],
  'sleep_score'     => ['label' => 'Sleep Score',         'unit' => '/100',    'source' => 'measurement', 'category' => 'Sleep Score',              'factor' => 1.0],
  'sleep_duration'  => ['label' => 'Sleep Duration',      'unit' => 'hrs',     'source' => 'measurement', 'category' => 'Sleep Duration',           'factor' => 1.0],
  'sleep_hrv'       => ['label' => 'HRV',                 'unit' => 'ms',      'source' => 'measurement', 'category' => 'Sleep HRV',                'factor' => 1.0],
  'sleep_hr'        => ['label' => 'Sleep HR',            'unit' => 'bpm',     'source' => 'measurement', 'category' => 'Sleep Heart Rate',         'factor' => 1.0],
  'sleep_rr'        => ['label' => 'Resp Rate',           'unit' => 'brpm',    'source' => 'measurement', 'category' => 'Sleep Respiratory Rate',   'factor' => 1.0],
];

// ============================================================================
// GOALS  — set to null to hide the goal line for that metric
// ============================================================================
$GOALS = [
  'weight'            => 175,    // lbs
  'body_fat'          => 15,     // %
  'muscle_mass'       => null,
  'bone_mass'         => null,
  'hydration'         => null,
  'bmr'               => null,
  'metabolic_age'     => null,
  'visceral_fat'      => null,
  'steps'             => 10000,  // steps/day
  'distance'          => null,
  'calories'          => 1500,   // kcal/day
  'protein'           => 150,    // g/day
  'carbs'             => null,
  'fat'               => null,
  'exercise_calories' => null,
  'sleep_score'       => 85,     // /100
  'sleep_duration'    => 8,      // hrs
  'sleep_hrv'         => null,
  'sleep_hr'          => null,
  'sleep_rr'          => null,
];

// ============================================================================
// PARAMS
// ============================================================================
$metric_slug = trim($_GET['metric'] ?? '');
$days        = max(7, min(730, (int)($_GET['days'] ?? 90)));

// ============================================================================
// HELPERS
// ============================================================================
function wger_get_g(string $base, string $token, string $path, array $query = []): array {
  $url = $base . $path . (!empty($query) ? ('?' . http_build_query($query)) : '');
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Token ' . $token],
  ]);
  $body = curl_exec($ch);
  $err  = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  if ($body === false) throw new RuntimeException("cURL error: $err");
  if ($code < 200 || $code >= 300) throw new RuntimeException("HTTP $code for $path");
  $json = json_decode((string)$body, true);
  if (!is_array($json)) throw new RuntimeException("Invalid JSON from $path");
  return $json;
}

function extract_date_g(string $dt): string {
  return preg_match('/^(\d{4}-\d{2}-\d{2})/', $dt, $m) ? $m[1] : $dt;
}

function try_num_g(mixed $v): ?float {
  if ($v === null) return null;
  if (is_numeric($v)) return (float)$v;
  return null;
}

/**
 * Generate an SVG line chart and return as HTML string.
 * $points: array of ['date'=>'YYYY-MM-DD','value'=>float], oldest→newest
 */
function generate_svg_chart(array $points, string $label, string $unit, int $days, string $chartId = 'chart', ?float $goal = null): string {
  $n = count($points);
  if ($n === 0) return '<p style="color:#888;font-style:italic">No data to chart.</p>';

  $vals  = array_column($points, 'value');
  $dates = array_column($points, 'date');

  $svgW = 900; $svgH = 280;
  $padL = 56; $padR = 56; $padT = 20; $padB = 30;
  $plotW = $svgW - $padL - $padR;
  $plotH = $svgH - $padT - $padB;

  $minV  = min($vals);
  $maxV  = max($vals);
  $range = $maxV - $minV;
  // 5% padding on each side
  $pad   = $range > 0 ? $range * 0.05 : max(abs($minV) * 0.05, 1);
  $yMin  = $minV - $pad;
  $yMax  = $maxV + $pad;

  // Expand Y scale to include goal line if outside data range
  if ($goal !== null) {
    if ($goal < $yMin) $yMin = $goal - $pad;
    if ($goal > $yMax) $yMax = $goal + $pad;
  }
  $yRange = $yMax - $yMin ?: 1;

  $toX = fn(int $i) => $padL + ($n > 1 ? $i / ($n - 1) : 0.5) * $plotW;
  $toY = fn(float $v) => $padT + $plotH - (($v - $yMin) / $yRange) * $plotH;

  // Polyline points
  $polyPts = [];
  for ($i = 0; $i < $n; $i++) {
    $polyPts[] = round($toX($i), 1) . ',' . round($toY($vals[$i]), 1);
  }
  $poly = implode(' ', $polyPts);

  // Area fill path
  $areaPath = 'M' . $polyPts[0];
  for ($i = 1; $i < $n; $i++) $areaPath .= ' L' . $polyPts[$i];
  $areaPath .= ' L' . round($toX($n - 1), 1) . ',' . ($padT + $plotH);
  $areaPath .= ' L' . round($toX(0), 1) . ',' . ($padT + $plotH) . ' Z';

  // Y-axis grid lines (5 lines)
  $yLines = [];
  for ($j = 0; $j <= 4; $j++) {
    $v = $yMin + ($yRange * $j / 4);
    $yLines[] = ['y' => round($toY($v), 1), 'label' => round($v, 2)];
  }

  // X-axis labels (6 evenly spaced)
  $labelIdxs = [];
  $steps = min($n, 6);
  for ($s = 0; $s < $steps; $s++) {
    $labelIdxs[] = (int)round($s * ($n - 1) / max($steps - 1, 1));
  }

  $gradId   = 'grad_' . $chartId;
  $glowId   = 'glow_' . $chartId;
  $tipId    = 'tip_' . $chartId;
  $dotClass = 'dot_' . $chartId;
  $ptClass  = 'pt_' . $chartId;

  ob_start();
  ?>
  <svg xmlns="http://www.w3.org/2000/svg"
       viewBox="0 0 <?php echo $svgW; ?> <?php echo $svgH; ?>"
       style="width:100%;max-width:<?php echo $svgW; ?>px;display:block;overflow:visible">
    <defs>
      <linearGradient id="<?php echo $gradId; ?>" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0%"   stop-color="#00FF41" stop-opacity="0.25"/>
        <stop offset="100%" stop-color="#00FF41" stop-opacity="0.02"/>
      </linearGradient>
      <filter id="<?php echo $glowId; ?>">
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

    <!-- goal line -->
    <?php if ($goal !== null): ?>
    <?php $goalY = round($toY($goal), 1); ?>
    <line x1="<?php echo $padL; ?>" y1="<?php echo $goalY; ?>"
          x2="<?php echo $padL + $plotW; ?>" y2="<?php echo $goalY; ?>"
          stroke="#FFD700" stroke-opacity="0.8" stroke-width="1.5" stroke-dasharray="6,4"/>
    <text x="<?php echo $padL + $plotW + 6; ?>" y="<?php echo $goalY + 4; ?>"
          fill="#FFD700" font-family="Courier New,monospace" font-size="10">
      GOAL <?php echo $goal; ?></text>
    <?php endif; ?>

    <!-- area fill -->
    <path d="<?php echo $areaPath; ?>" fill="url(#<?php echo $gradId; ?>)"/>

    <!-- trend line -->
    <polyline points="<?php echo $poly; ?>"
              fill="none" stroke="#00FF41" stroke-width="2.5"
              stroke-linejoin="round" stroke-linecap="round"
              filter="url(#<?php echo $glowId; ?>)"/>

    <!-- data points -->
    <?php for ($i = 0; $i < $n; $i++): ?>
    <g class="<?php echo $ptClass; ?>"
       data-date="<?php echo htmlspecialchars($dates[$i]); ?>"
       data-val="<?php echo round($vals[$i], 2); ?>"
       data-unit="<?php echo htmlspecialchars($unit); ?>">
      <circle cx="<?php echo round($toX($i), 1); ?>" cy="<?php echo round($toY($vals[$i]), 1); ?>"
              r="10" fill="transparent"/>
      <circle cx="<?php echo round($toX($i), 1); ?>" cy="<?php echo round($toY($vals[$i]), 1); ?>"
              r="3.5" fill="#00FF41" stroke="#000" stroke-width="1.5"
              class="<?php echo $dotClass; ?>"/>
    </g>
    <?php endfor; ?>

    <!-- x-axis labels -->
    <?php foreach ($labelIdxs as $idx): ?>
    <?php $mmdd = date('m/d', strtotime($dates[$idx])); ?>
    <text x="<?php echo round($toX($idx), 1); ?>" y="<?php echo $padT + $plotH + 18; ?>"
          text-anchor="middle" fill="#00DD33" font-family="Courier New,monospace"
          font-size="10"><?php echo $mmdd; ?></text>
    <?php endforeach; ?>

    <!-- axis box -->
    <rect x="<?php echo $padL; ?>" y="<?php echo $padT; ?>"
          width="<?php echo $plotW; ?>" height="<?php echo $plotH; ?>"
          fill="none" stroke="#00FF41" stroke-opacity="0.3" stroke-width="1"/>
  </svg>

  <!-- Floating tooltip -->
  <div id="<?php echo $tipId; ?>" style="
    display:none;position:fixed;pointer-events:none;
    background:#000;border:1px solid #00FF41;
    color:#00FF41;font-family:'Courier New',monospace;font-size:13px;
    padding:6px 12px;border-radius:3px;
    box-shadow:0 0 12px rgba(0,255,65,0.5);
    white-space:nowrap;z-index:9999;">
  </div>

  <script>
  (function(){
    var tip = document.getElementById('<?php echo $tipId; ?>');
    var pts = document.querySelectorAll('.<?php echo $ptClass; ?>');
    pts.forEach(function(g){
      var dot = g.querySelector('.<?php echo $dotClass; ?>');
      g.addEventListener('mouseenter', function(){
        tip.textContent = g.dataset.date + '  \u2192  ' + g.dataset.val + ' ' + g.dataset.unit;
        dot.setAttribute('r','5.5');
        dot.setAttribute('fill','#00FF00');
        tip.style.display = 'block';
      });
      g.addEventListener('mousemove', function(e){
        tip.style.left = (e.clientX + 14) + 'px';
        tip.style.top  = (e.clientY - 32) + 'px';
      });
      g.addEventListener('mouseleave', function(){
        tip.style.display = 'none';
        dot.setAttribute('r','3.5');
        dot.setAttribute('fill','#00FF41');
      });
    });
  })();
  </script>
  <?php
  return ob_get_clean();
}

// ============================================================================
// DATA FETCHING
// ============================================================================
$error   = null;
$points  = [];
$metaInfo = null;

if (!array_key_exists($metric_slug, $METRICS)) {
  $error = 'unknown_metric';
} else {
  $metaInfo = $METRICS[$metric_slug];
  $cutoff   = date('Y-m-d', strtotime("-{$days} days"));
  $today    = date('Y-m-d');

  try {
    if ($metaInfo['source'] === 'weightentry') {
      // Fetch weight entries
      $resp = wger_get_g($WGER_BASE, $WGER_TOKEN, '/api/v2/weightentry/', ['limit' => 400, 'ordering' => '-date']);
      foreach ($resp['results'] ?? [] as $e) {
        $d = extract_date_g($e['date'] ?? '');
        if ($d < $cutoff || $d > $today) continue;
        $v = try_num_g($e['weight']);
        if ($v !== null) $points[$d] = round($v * $metaInfo['factor'], 2);
      }
    } else {
      // Build category name → IDs map
      $catResp = wger_get_g($WGER_BASE, $WGER_TOKEN, '/api/v2/measurement-category/', ['limit' => 200]);
      $categoryNameToIds = [];
      foreach ($catResp['results'] ?? [] as $cat) {
        $name = $cat['name'] ?? '';
        $categoryNameToIds[$name][] = (int)$cat['id'];
      }

      $targetCat = $metaInfo['category'];
      $catIds    = $categoryNameToIds[$targetCat] ?? [];

      if (empty($catIds)) {
        $error = 'no_category';
      } else {
        foreach ($catIds as $catId) {
          $mResp = wger_get_g($WGER_BASE, $WGER_TOKEN, '/api/v2/measurement/', [
            'category' => $catId, 'limit' => 400, 'ordering' => '-date',
          ]);
          foreach ($mResp['results'] ?? [] as $e) {
            $d = extract_date_g($e['date'] ?? '');
            if ($d < $cutoff || $d > $today) continue;
            $v = try_num_g($e['value']);
            if ($v !== null && !isset($points[$d])) {
              $points[$d] = round($v * $metaInfo['factor'], 2);
            }
          }
        }
      }
    }

    if ($error === null && empty($points)) {
      $error = 'no_data';
    }
  } catch (Throwable $e) {
    $error = 'fetch_error';
    $errorMsg = $e->getMessage();
  }
}

// Sort oldest → newest
ksort($points);
$pointsArr = [];
foreach ($points as $d => $v) {
  $pointsArr[] = ['date' => $d, 'value' => $v];
}

// Stats
$current   = !empty($pointsArr) ? $pointsArr[count($pointsArr) - 1]['value'] : null;
$oldest    = !empty($pointsArr) ? $pointsArr[0]['value'] : null;
$change    = ($current !== null && $oldest !== null) ? round($current - $oldest, 2) : null;
$peak      = !empty($pointsArr) ? max(array_column($pointsArr, 'value')) : null;
$low       = !empty($pointsArr) ? min(array_column($pointsArr, 'value')) : null;
$count     = count($pointsArr);

// Date range labels
$rangeStart = !empty($pointsArr) ? $pointsArr[0]['date'] : date('Y-m-d', strtotime("-{$days} days"));
$rangeEnd   = !empty($pointsArr) ? $pointsArr[$count - 1]['date'] : date('Y-m-d');

// 30-day change
$change30 = null;
if (!empty($pointsArr)) {
  $cutoff30 = date('Y-m-d', strtotime("-30 days"));
  $pts30    = array_filter($pointsArr, fn($p) => $p['date'] >= $cutoff30);
  if (count($pts30) >= 2) {
    $pts30sorted = array_values($pts30);
    $change30    = round($pts30sorted[count($pts30sorted) - 1]['value'] - $pts30sorted[0]['value'], 2);
  }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo $metaInfo ? htmlspecialchars($metaInfo['label']) : 'Graph'; ?> — Health Matrix</title>
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body {
      background:#000; color:#00FF41;
      font-family:'Courier New',Courier,monospace;
      padding:20px; line-height:1.6; overflow-x:hidden;
    }
    .container { max-width:1000px; margin:0 auto; animation:fadeIn 0.5s ease-in; }
    @keyframes fadeIn { from{opacity:0;transform:translateY(-10px)} to{opacity:1;transform:translateY(0)} }
    h1 {
      color:#00FF41; text-align:center; font-size:1.8em; margin-bottom:16px;
      text-shadow:0 0 10px #00FF41; letter-spacing:2px;
      border-bottom:2px solid #00FF41; padding-bottom:10px;
    }
    .section {
      margin-bottom:24px; border:1px solid #00FF41; padding:20px;
      background:rgba(0,255,65,0.05); border-radius:5px;
      box-shadow:0 0 20px rgba(0,255,65,0.2);
    }
    .section h2 { color:#00FF41; font-size:1.1em; margin-bottom:14px; letter-spacing:1px; }
    .nav-bar { margin-bottom:20px; display:flex; gap:12px; flex-wrap:wrap; align-items:center; }
    .nav-link {
      background:#000; color:#00FF41; border:1px solid #00FF41;
      padding:6px 14px; font-family:'Courier New',monospace; font-size:13px;
      letter-spacing:1px; text-decoration:none; display:inline-block;
      transition:background 0.15s;
    }
    .nav-link:hover { background:rgba(0,255,65,0.12); box-shadow:0 0 8px rgba(0,255,65,0.4); }
    .stats-grid {
      display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:14px;
      margin-top:16px;
    }
    .stat-box { border:1px solid rgba(0,255,65,0.3); padding:12px; }
    .stat-label { color:#00CC28; font-size:0.75em; letter-spacing:1px; margin-bottom:4px; }
    .stat-val { color:#00FF41; font-size:1.3em; font-weight:bold; }
    .stat-val.pos { color:#00FF41; }
    .stat-val.neg { color:#FF4444; }
    .stat-val.neu { color:#888; }
    .error-box { border:1px solid #FF4444; padding:20px; color:#FF4444; margin-bottom:20px; }
    .metric-list { margin-top:12px; display:flex; flex-wrap:wrap; gap:8px; }
    .metric-chip {
      border:1px solid rgba(0,255,65,0.4); padding:4px 10px; font-size:12px;
      text-decoration:none; color:#00DD33;
    }
    .metric-chip:hover { background:rgba(0,255,65,0.1); }
    .subtext { color:#555; font-size:0.8em; margin-top:8px; }
    .day-selector { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:20px; }
    .day-btn {
      background:#000; color:#00FF41; border:1px solid #00FF41;
      padding:5px 14px; font-family:'Courier New',monospace; font-size:12px;
      text-decoration:none; letter-spacing:1px;
    }
    .day-btn:hover, .day-btn.active { background:rgba(0,255,65,0.15); box-shadow:0 0 6px rgba(0,255,65,0.4); }
    .day-btn.active { color:#00FF00; border-color:#00FF00; }
  </style>
</head>
<body>
<div class="container">

  <h1>▌HEALTH MATRIX — GRAPH▐</h1>

  <div class="nav-bar">
    <a class="nav-link" href="weight.php">← Dashboard</a>
    <a class="nav-link" href="charts.php">📊 All Charts</a>
    <?php if ($metaInfo): ?>
    <span style="color:#555; margin-left:8px; font-size:12px;">
      <?php echo htmlspecialchars($metaInfo['label']); ?> /
      <?php echo $rangeStart; ?> → <?php echo $rangeEnd; ?>
    </span>
    <?php endif; ?>
  </div>

<?php if ($error === 'unknown_metric'): ?>
  <div class="error-box">
    <strong>UNKNOWN METRIC:</strong> "<?php echo htmlspecialchars($metric_slug); ?>"
    <br><br>
    Valid metrics:
    <div class="metric-list">
      <?php foreach ($METRICS as $slug => $cfg): ?>
      <a class="metric-chip" href="graph.php?metric=<?php echo $slug; ?>&days=<?php echo $days; ?>">
        <?php echo htmlspecialchars($slug); ?>
      </a>
      <?php endforeach; ?>
    </div>
    <div class="subtext">Usage: graph.php?metric=muscle_mass&days=90</div>
  </div>

<?php elseif ($error === 'no_category'): ?>
  <div class="error-box">
    <strong>ERROR:</strong> Category "<?php echo htmlspecialchars($metaInfo['category']); ?>"
    not found in WGER measurement categories.
  </div>

<?php elseif ($error === 'no_data'): ?>
  <div class="error-box">
    <strong>NO DATA:</strong> No data found for
    <strong><?php echo htmlspecialchars($metaInfo['label']); ?></strong>
    in the last <?php echo $days; ?> days.
    <br><br>
    Try a longer range:
    <?php foreach ([30, 60, 90, 180, 365] as $d): ?>
    <a class="nav-link" style="font-size:11px; padding:3px 8px;"
       href="graph.php?metric=<?php echo urlencode($metric_slug); ?>&days=<?php echo $d; ?>">
      <?php echo $d; ?>d
    </a>
    <?php endforeach; ?>
  </div>

<?php elseif ($error === 'fetch_error'): ?>
  <div class="error-box">
    <strong>FETCH ERROR:</strong> <?php echo htmlspecialchars($errorMsg ?? 'Unknown error'); ?>
  </div>

<?php else: ?>

  <!-- Day Selector -->
  <div class="day-selector">
    <span style="color:#00CC28; font-size:12px; letter-spacing:1px; align-self:center;">RANGE:</span>
    <?php foreach ([14, 30, 60, 90, 180, 365] as $d): ?>
    <a class="day-btn <?php echo $d === $days ? 'active' : ''; ?>"
       href="graph.php?metric=<?php echo urlencode($metric_slug); ?>&days=<?php echo $d; ?>">
      <?php echo $d; ?>d
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Chart Section -->
  <div class="section">
    <h2>▌<?php echo strtoupper(htmlspecialchars($metaInfo['label'])); ?>
       (<?php echo htmlspecialchars($metaInfo['unit']); ?>)
       — Last <?php echo $days; ?> Days</h2>

    <?php $metricGoal = $GOALS[$metric_slug] ?? null; ?>
    <?php echo generate_svg_chart($pointsArr, $metaInfo['label'], $metaInfo['unit'], $days, 'main', $metricGoal); ?>

    <div class="subtext">
      Hover dots for exact values &nbsp;|&nbsp; <?php echo $count; ?> data points
      <?php if ($metricGoal !== null): ?>
      &nbsp;|&nbsp; <span style="color:#FFD700">- - -</span>
      Goal: <?php echo $metricGoal; ?> <?php echo htmlspecialchars($metaInfo['unit']); ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Stats -->
  <div class="section">
    <h2>▌STATISTICS</h2>
    <div class="stats-grid">
      <div class="stat-box">
        <div class="stat-label">CURRENT</div>
        <div class="stat-val"><?php echo $current !== null ? $current . ' ' . htmlspecialchars($metaInfo['unit']) : '—'; ?></div>
        <div class="subtext"><?php echo $rangeEnd; ?></div>
      </div>
      <div class="stat-box">
        <div class="stat-label"><?php echo $days; ?>-DAY CHANGE</div>
        <?php
          $changeClass = 'neu';
          $changeStr   = '—';
          if ($change !== null) {
            $changeClass = $change < 0 ? 'neg' : ($change > 0 ? 'pos' : 'neu');
            $changeStr   = ($change > 0 ? '+' : '') . $change . ' ' . htmlspecialchars($metaInfo['unit']);
          }
        ?>
        <div class="stat-val <?php echo $changeClass; ?>"><?php echo $changeStr; ?></div>
        <div class="subtext"><?php echo $rangeStart; ?> → <?php echo $rangeEnd; ?></div>
      </div>
      <?php if ($change30 !== null): ?>
      <div class="stat-box">
        <div class="stat-label">30-DAY CHANGE</div>
        <?php
          $c30class = $change30 < 0 ? 'neg' : ($change30 > 0 ? 'pos' : 'neu');
          $c30str   = ($change30 > 0 ? '+' : '') . $change30 . ' ' . htmlspecialchars($metaInfo['unit']);
        ?>
        <div class="stat-val <?php echo $c30class; ?>"><?php echo $c30str; ?></div>
      </div>
      <?php endif; ?>
      <div class="stat-box">
        <div class="stat-label">PEAK</div>
        <div class="stat-val"><?php echo $peak !== null ? $peak . ' ' . htmlspecialchars($metaInfo['unit']) : '—'; ?></div>
      </div>
      <div class="stat-box">
        <div class="stat-label">LOW</div>
        <div class="stat-val"><?php echo $low !== null ? $low . ' ' . htmlspecialchars($metaInfo['unit']) : '—'; ?></div>
      </div>
      <div class="stat-box">
        <div class="stat-label">DATA POINTS</div>
        <div class="stat-val"><?php echo $count; ?></div>
        <div class="subtext">in <?php echo $days; ?> days</div>
      </div>
    </div>
  </div>

  <!-- Browse other metrics -->
  <div class="section">
    <h2>▌OTHER METRICS</h2>
    <div class="metric-list">
      <?php foreach ($METRICS as $slug => $cfg): ?>
      <?php if ($slug === $metric_slug) continue; ?>
      <a class="metric-chip"
         href="graph.php?metric=<?php echo $slug; ?>&days=<?php echo $days; ?>">
        <?php echo htmlspecialchars($cfg['label']); ?>
        <span style="color:#555; font-size:10px;"><?php echo htmlspecialchars($cfg['unit']); ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

<?php endif; ?>

  <div style="text-align:center; color:#333; font-size:11px; margin-top:20px; padding-top:10px; border-top:1px solid #111;">
    Generated <?php echo date('Y-m-d H:i:s T'); ?>
    &nbsp;|&nbsp;
    <a class="nav-link" style="font-size:11px; padding:3px 8px;" href="charts.php">📊 All Charts</a>
    &nbsp;
    <a class="nav-link" style="font-size:11px; padding:3px 8px;" href="weight.php">← Dashboard</a>
  </div>

</div>
</body>
</html>
