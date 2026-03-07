<?php
/**
 * charts.php — All-metrics health dashboard
 * Usage: charts.php  or  charts.php?days=90
 */
declare(strict_types=1);
date_default_timezone_set('America/New_York');

$WGER_BASE  = 'https://your-wger-instance.com'; // ← your WGER URL
$WGER_TOKEN = 'your_wger_api_token_here'; // ← WGER > Account > API Key

$days = max(7, min(730, (int)($_GET['days'] ?? 90)));

// ============================================================================
// METRIC GROUPS (for display layout)
// ============================================================================
$GROUPS = [
  'BODY COMPOSITION' => [
    'weight'       => ['label' => 'Weight',       'unit' => 'lbs',      'source' => 'weightentry', 'category' => null,                   'factor' => 1.0],
    'body_fat'     => ['label' => 'Body Fat',      'unit' => '%',        'source' => 'measurement', 'category' => 'Body Fat',             'factor' => 1.0],
    'muscle_mass'  => ['label' => 'Muscle Mass',   'unit' => 'lbs',      'source' => 'measurement', 'category' => 'Muscle Mass',          'factor' => 2.20462],
    'bone_mass'    => ['label' => 'Bone Mass',     'unit' => 'lbs',      'source' => 'measurement', 'category' => 'Bone Mass',            'factor' => 2.20462],
  ],
  'METABOLISM' => [
    'bmr'          => ['label' => 'BMR',           'unit' => 'kcal/day', 'source' => 'measurement', 'category' => 'Basal Metabolic Rate', 'factor' => 1.0],
    'metabolic_age'=> ['label' => 'Metabolic Age', 'unit' => 'yrs',      'source' => 'measurement', 'category' => 'Metabolic Age',        'factor' => 1.0],
    'visceral_fat' => ['label' => 'Visceral Fat',  'unit' => 'index',    'source' => 'measurement', 'category' => 'Visceral Fat',         'factor' => 1.0],
  ],
  'ACTIVITY' => [
    'steps'        => ['label' => 'Steps',         'unit' => 'steps',    'source' => 'measurement', 'category' => 'Steps',                'factor' => 1000.0],
    'distance'     => ['label' => 'Distance',      'unit' => 'mi',       'source' => 'measurement', 'category' => 'Distance',             'factor' => 0.621371],
    'hydration'    => ['label' => 'Hydration',     'unit' => '%',        'source' => 'measurement', 'category' => 'Hydration',            'factor' => 1.0],
  ],
  'NUTRITION' => [
    'calories'          => ['label' => 'Food Calories',  'unit' => 'kcal', 'source' => 'measurement', 'category' => 'Daily Calories',        'factor' => 1.0],
    'protein'           => ['label' => 'Protein',        'unit' => 'g',    'source' => 'measurement', 'category' => 'Daily Protein',         'factor' => 1.0],
    'carbs'             => ['label' => 'Carbs',          'unit' => 'g',    'source' => 'measurement', 'category' => 'Daily Carbs',           'factor' => 1.0],
    'fat'               => ['label' => 'Fat',            'unit' => 'g',    'source' => 'measurement', 'category' => 'Daily Fat',             'factor' => 1.0],
    'exercise_calories' => ['label' => 'Exercise Cal',   'unit' => 'kcal', 'source' => 'measurement', 'category' => 'MFP Exercise Calories', 'factor' => 1.0],
  ],
  'SLEEP' => [
    'sleep_score'    => ['label' => 'Sleep Score',    'unit' => '/100', 'source' => 'measurement', 'category' => 'Sleep Score',            'factor' => 1.0],
    'sleep_duration' => ['label' => 'Sleep Duration', 'unit' => 'hrs',  'source' => 'measurement', 'category' => 'Sleep Duration',         'factor' => 1.0],
    'sleep_hrv'      => ['label' => 'HRV',            'unit' => 'ms',   'source' => 'measurement', 'category' => 'Sleep HRV',              'factor' => 1.0],
    'sleep_hr'       => ['label' => 'Sleep HR',       'unit' => 'bpm',  'source' => 'measurement', 'category' => 'Sleep Heart Rate',       'factor' => 1.0],
    'sleep_rr'       => ['label' => 'Resp Rate',      'unit' => 'brpm', 'source' => 'measurement', 'category' => 'Sleep Respiratory Rate', 'factor' => 1.0],
  ],
];

// Flat list for fetching
$ALL_METRICS = [];
foreach ($GROUPS as $groupName => $metrics) {
  foreach ($metrics as $slug => $cfg) {
    $ALL_METRICS[$slug] = $cfg;
  }
}

// ============================================================================
// HELPERS
// ============================================================================
function wger_get_c(string $base, string $token, string $path, array $query = []): array {
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

function extract_date_c(string $dt): string {
  return preg_match('/^(\d{4}-\d{2}-\d{2})/', $dt, $m) ? $m[1] : $dt;
}

function try_num_c(mixed $v): ?float {
  if ($v === null) return null;
  if (is_numeric($v)) return (float)$v;
  return null;
}

/**
 * Generate a mini SVG chart (280×180) for the dashboard grid.
 */
function generate_mini_svg(array $points, string $unit, string $chartId): string {
  $n = count($points);
  if ($n === 0) {
    return '<div style="height:140px;display:flex;align-items:center;justify-content:center;color:#333;font-size:12px;">NO DATA</div>';
  }

  $vals  = array_column($points, 'value');
  $dates = array_column($points, 'date');

  $svgW = 280; $svgH = 140;
  $padL = 44; $padR = 8; $padT = 10; $padB = 22;
  $plotW = $svgW - $padL - $padR;
  $plotH = $svgH - $padT - $padB;

  $minV  = min($vals);
  $maxV  = max($vals);
  $range = $maxV - $minV;
  $pad   = $range > 0 ? $range * 0.05 : max(abs($minV) * 0.05, 1);
  $yMin  = $minV - $pad;
  $yMax  = $maxV + $pad;
  $yRange = $yMax - $yMin ?: 1;

  $toX = fn(int $i) => $padL + ($n > 1 ? $i / ($n - 1) : 0.5) * $plotW;
  $toY = fn(float $v) => $padT + $plotH - (($v - $yMin) / $yRange) * $plotH;

  $polyPts = [];
  for ($i = 0; $i < $n; $i++) {
    $polyPts[] = round($toX($i), 1) . ',' . round($toY($vals[$i]), 1);
  }
  $poly = implode(' ', $polyPts);

  $areaPath = 'M' . $polyPts[0];
  for ($i = 1; $i < $n; $i++) $areaPath .= ' L' . $polyPts[$i];
  $areaPath .= ' L' . round($toX($n - 1), 1) . ',' . ($padT + $plotH);
  $areaPath .= ' L' . round($toX(0), 1) . ',' . ($padT + $plotH) . ' Z';

  // Y labels (3 lines for mini)
  $yLines = [];
  for ($j = 0; $j <= 2; $j++) {
    $v = $yMin + ($yRange * $j / 2);
    $yLines[] = ['y' => round($toY($v), 1), 'label' => round($v, 1)];
  }

  // X labels (3)
  $labelIdxs = [];
  $steps = min($n, 3);
  for ($s = 0; $s < $steps; $s++) {
    $labelIdxs[] = (int)round($s * ($n - 1) / max($steps - 1, 1));
  }

  $gradId   = 'g_' . $chartId;
  $glowId   = 'gl_' . $chartId;
  $tipId    = 'tip_' . $chartId;
  $dotClass = 'd_' . $chartId;
  $ptClass  = 'pt_' . $chartId;

  ob_start();
  ?>
  <svg xmlns="http://www.w3.org/2000/svg"
       viewBox="0 0 <?php echo $svgW; ?> <?php echo $svgH; ?>"
       style="width:100%;display:block;overflow:visible">
    <defs>
      <linearGradient id="<?php echo $gradId; ?>" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0%"   stop-color="#00FF41" stop-opacity="0.22"/>
        <stop offset="100%" stop-color="#00FF41" stop-opacity="0.01"/>
      </linearGradient>
      <filter id="<?php echo $glowId; ?>">
        <feGaussianBlur stdDeviation="1.5" result="blur"/>
        <feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge>
      </filter>
    </defs>
    <?php foreach ($yLines as $yl): ?>
    <line x1="<?php echo $padL; ?>" y1="<?php echo $yl['y']; ?>"
          x2="<?php echo $padL + $plotW; ?>" y2="<?php echo $yl['y']; ?>"
          stroke="#00FF41" stroke-opacity="0.1" stroke-width="0.8"/>
    <text x="<?php echo $padL - 4; ?>" y="<?php echo $yl['y'] + 3; ?>"
          text-anchor="end" fill="#00AA22" font-family="Courier New,monospace"
          font-size="8"><?php echo $yl['label']; ?></text>
    <?php endforeach; ?>
    <path d="<?php echo $areaPath; ?>" fill="url(#<?php echo $gradId; ?>)"/>
    <polyline points="<?php echo $poly; ?>"
              fill="none" stroke="#00FF41" stroke-width="1.8"
              stroke-linejoin="round" stroke-linecap="round"
              filter="url(#<?php echo $glowId; ?>)"/>
    <?php for ($i = 0; $i < $n; $i++): ?>
    <g class="<?php echo $ptClass; ?>"
       data-date="<?php echo htmlspecialchars($dates[$i]); ?>"
       data-val="<?php echo round($vals[$i], 2); ?>"
       data-unit="<?php echo htmlspecialchars($unit); ?>">
      <circle cx="<?php echo round($toX($i), 1); ?>" cy="<?php echo round($toY($vals[$i]), 1); ?>"
              r="7" fill="transparent"/>
      <circle cx="<?php echo round($toX($i), 1); ?>" cy="<?php echo round($toY($vals[$i]), 1); ?>"
              r="2.5" fill="#00FF41" stroke="#000" stroke-width="1"
              class="<?php echo $dotClass; ?>"/>
    </g>
    <?php endfor; ?>
    <?php foreach ($labelIdxs as $idx): ?>
    <?php $mmdd = date('m/d', strtotime($dates[$idx])); ?>
    <text x="<?php echo round($toX($idx), 1); ?>" y="<?php echo $padT + $plotH + 14; ?>"
          text-anchor="middle" fill="#00AA22" font-family="Courier New,monospace"
          font-size="8"><?php echo $mmdd; ?></text>
    <?php endforeach; ?>
    <rect x="<?php echo $padL; ?>" y="<?php echo $padT; ?>"
          width="<?php echo $plotW; ?>" height="<?php echo $plotH; ?>"
          fill="none" stroke="#00FF41" stroke-opacity="0.25" stroke-width="0.8"/>
  </svg>
  <div id="<?php echo $tipId; ?>" style="
    display:none;position:fixed;pointer-events:none;
    background:#000;border:1px solid #00FF41;
    color:#00FF41;font-family:'Courier New',monospace;font-size:12px;
    padding:5px 10px;border-radius:3px;
    box-shadow:0 0 10px rgba(0,255,65,0.5);
    white-space:nowrap;z-index:9999;"></div>
  <script>
  (function(){
    var tip=document.getElementById('<?php echo $tipId; ?>');
    document.querySelectorAll('.<?php echo $ptClass; ?>').forEach(function(g){
      var dot=g.querySelector('.<?php echo $dotClass; ?>');
      g.addEventListener('mouseenter',function(){
        tip.textContent=g.dataset.date+' \u2192 '+g.dataset.val+' '+g.dataset.unit;
        dot.setAttribute('r','4');dot.setAttribute('fill','#00FF00');
        tip.style.display='block';
      });
      g.addEventListener('mousemove',function(e){
        tip.style.left=(e.clientX+12)+'px';tip.style.top=(e.clientY-28)+'px';
      });
      g.addEventListener('mouseleave',function(){
        tip.style.display='none';
        dot.setAttribute('r','2.5');dot.setAttribute('fill','#00FF41');
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
$fetchError = null;
$allData    = []; // slug => array of ['date','value']

try {
  $cutoff = date('Y-m-d', strtotime("-{$days} days"));
  $today  = date('Y-m-d');

  // 1. Build category name → IDs map (one request)
  $catResp = wger_get_c($WGER_BASE, $WGER_TOKEN, '/api/v2/measurement-category/', ['limit' => 200]);
  $categoryNameToIds = [];
  foreach ($catResp['results'] ?? [] as $cat) {
    $name = $cat['name'] ?? '';
    $categoryNameToIds[$name][] = (int)$cat['id'];
  }

  // 2. Fetch weight entries (used for 'weight' slug)
  $weightResp = wger_get_c($WGER_BASE, $WGER_TOKEN, '/api/v2/weightentry/', ['limit' => 400, 'ordering' => '-date']);
  $weightPts  = [];
  foreach ($weightResp['results'] ?? [] as $e) {
    $d = extract_date_c($e['date'] ?? '');
    if ($d < $cutoff || $d > $today) continue;
    $v = try_num_c($e['weight']);
    if ($v !== null) $weightPts[$d] = round($v, 1);
  }
  ksort($weightPts);
  $allData['weight'] = [];
  foreach ($weightPts as $d => $v) $allData['weight'][] = ['date' => $d, 'value' => $v];

  // 3. Fetch each measurement category
  $fetched = []; // catId => results, avoid duplicate fetches
  foreach ($ALL_METRICS as $slug => $cfg) {
    if ($cfg['source'] !== 'measurement') continue;
    $catIds = $categoryNameToIds[$cfg['category']] ?? [];
    $pts    = [];
    foreach ($catIds as $catId) {
      if (!isset($fetched[$catId])) {
        $resp = wger_get_c($WGER_BASE, $WGER_TOKEN, '/api/v2/measurement/', [
          'category' => $catId, 'limit' => 400, 'ordering' => '-date',
        ]);
        $fetched[$catId] = $resp['results'] ?? [];
      }
      foreach ($fetched[$catId] as $e) {
        $d = extract_date_c($e['date'] ?? '');
        if ($d < $cutoff || $d > $today) continue;
        $v = try_num_c($e['value']);
        if ($v !== null && !isset($pts[$d])) {
          $pts[$d] = round($v * $cfg['factor'], 2);
        }
      }
    }
    ksort($pts);
    $allData[$slug] = [];
    foreach ($pts as $d => $v) $allData[$slug][] = ['date' => $d, 'value' => $v];
  }

} catch (Throwable $e) {
  $fetchError = $e->getMessage();
}

// ============================================================================
// COMPUTE TREND DELTAS
// ============================================================================
function trend_delta(array $points): ?array {
  $n = count($points);
  if ($n < 2) return null;
  $first = $points[0]['value'];
  $last  = $points[$n - 1]['value'];
  $delta = round($last - $first, 2);
  return ['delta' => $delta, 'first' => $first, 'last' => $last];
}

header('Content-Type: text/html; charset=utf-8');
$chartCounter = 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Health Matrix — All Charts</title>
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body {
      background:#000; color:#00FF41;
      font-family:'Courier New',Courier,monospace;
      padding:20px; line-height:1.6; overflow-x:hidden;
    }
    .container { max-width:1100px; margin:0 auto; animation:fadeIn 0.5s ease-in; }
    @keyframes fadeIn { from{opacity:0;transform:translateY(-10px)} to{opacity:1;transform:translateY(0)} }
    h1 {
      color:#00FF41; text-align:center; font-size:1.8em; margin-bottom:16px;
      text-shadow:0 0 10px #00FF41; letter-spacing:2px;
      border-bottom:2px solid #00FF41; padding-bottom:10px;
    }
    .nav-bar { margin-bottom:20px; display:flex; gap:12px; flex-wrap:wrap; align-items:center; }
    .nav-link {
      background:#000; color:#00FF41; border:1px solid #00FF41;
      padding:6px 14px; font-family:'Courier New',monospace; font-size:13px;
      letter-spacing:1px; text-decoration:none; display:inline-block;
    }
    .nav-link:hover { background:rgba(0,255,65,0.12); box-shadow:0 0 8px rgba(0,255,65,0.4); }
    .day-selector { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:24px; align-items:center; }
    .day-btn {
      background:#000; color:#00FF41; border:1px solid #00FF41;
      padding:5px 14px; font-family:'Courier New',monospace; font-size:12px;
      text-decoration:none; letter-spacing:1px;
    }
    .day-btn:hover { background:rgba(0,255,65,0.12); }
    .day-btn.active { background:rgba(0,255,65,0.15); color:#00FF00; border-color:#00FF00; box-shadow:0 0 6px rgba(0,255,65,0.4); }
    .group-header {
      color:#00FF41; font-size:1em; letter-spacing:2px; margin:28px 0 14px;
      padding-bottom:6px; border-bottom:1px solid rgba(0,255,65,0.3);
    }
    .chart-grid {
      display:grid;
      grid-template-columns:repeat(3,1fr);
      gap:16px;
    }
    @media(max-width:860px) { .chart-grid { grid-template-columns:repeat(2,1fr); } }
    @media(max-width:540px) { .chart-grid { grid-template-columns:1fr; } }
    .chart-card {
      border:1px solid rgba(0,255,65,0.35); padding:14px;
      background:rgba(0,255,65,0.03);
      border-radius:3px;
    }
    .card-header {
      display:flex; justify-content:space-between; align-items:baseline;
      margin-bottom:8px;
    }
    .card-title { font-size:0.85em; color:#00DD33; letter-spacing:1px; }
    .card-link {
      font-size:11px; color:#555; text-decoration:none;
      padding:2px 6px; border:1px solid #333;
    }
    .card-link:hover { color:#00FF41; border-color:#00FF41; }
    .card-footer { margin-top:8px; display:flex; justify-content:space-between; align-items:baseline; }
    .current-val { font-size:1.1em; color:#00FF41; font-weight:bold; }
    .trend-delta { font-size:11px; }
    .trend-pos { color:#00FF41; }
    .trend-neg { color:#FF4444; }
    .trend-neu { color:#888; }
    .no-data { height:120px; display:flex; align-items:center; justify-content:center; color:#333; font-size:12px; }
    .error-box { border:1px solid #FF4444; padding:20px; color:#FF4444; margin-bottom:20px; }
    .gen-time { text-align:center; color:#333; font-size:11px; margin-top:24px; padding-top:10px; border-top:1px solid #111; }
  </style>
</head>
<body>
<div class="container">

  <h1>▌HEALTH MATRIX — ALL CHARTS▐</h1>

  <div class="nav-bar">
    <a class="nav-link" href="weight.php">← Dashboard</a>
    <a class="nav-link" href="graph.php">📈 Graph Metric</a>
  </div>

  <!-- Day Selector -->
  <div class="day-selector">
    <span style="color:#00CC28; font-size:12px; letter-spacing:1px;">RANGE:</span>
    <?php foreach ([30, 60, 90, 365] as $d): ?>
    <a class="day-btn <?php echo $d === $days ? 'active' : ''; ?>"
       href="charts.php?days=<?php echo $d; ?>">
      <?php echo $d; ?>d
    </a>
    <?php endforeach; ?>
    <span style="color:#555; font-size:11px; margin-left:8px;">
      <?php echo date('Y-m-d', strtotime("-{$days} days")); ?> → <?php echo date('Y-m-d'); ?>
    </span>
  </div>

  <?php if ($fetchError): ?>
  <div class="error-box">
    <strong>FETCH ERROR:</strong> <?php echo htmlspecialchars($fetchError); ?>
  </div>
  <?php else: ?>

  <?php foreach ($GROUPS as $groupName => $metrics): ?>
  <div class="group-header">▌<?php echo $groupName; ?></div>
  <div class="chart-grid">
    <?php foreach ($metrics as $slug => $cfg): ?>
    <?php
      $points  = $allData[$slug] ?? [];
      $n       = count($points);
      $current = $n > 0 ? $points[$n - 1]['value'] : null;
      $delta   = trend_delta($points);
      $chartId = 'c' . (++$chartCounter);
    ?>
    <div class="chart-card">
      <div class="card-header">
        <span class="card-title"><?php echo strtoupper(htmlspecialchars($cfg['label'])); ?></span>
        <a class="card-link"
           href="graph.php?metric=<?php echo urlencode($slug); ?>&days=<?php echo $days; ?>">
          full →
        </a>
      </div>

      <?php if ($n === 0): ?>
        <div class="no-data">NO DATA</div>
      <?php else: ?>
        <?php echo generate_mini_svg($points, $cfg['unit'], $chartId); ?>
      <?php endif; ?>

      <div class="card-footer">
        <span class="current-val">
          <?php echo $current !== null ? $current . ' ' . htmlspecialchars($cfg['unit']) : '—'; ?>
        </span>
        <?php if ($delta !== null): ?>
        <?php
          $dval   = $delta['delta'];
          $dcls   = $dval < 0 ? 'trend-neg' : ($dval > 0 ? 'trend-pos' : 'trend-neu');
          $darrow = $dval < 0 ? '↓' : ($dval > 0 ? '↑' : '→');
          $dstr   = $darrow . ' ' . abs($dval) . ' ' . htmlspecialchars($cfg['unit']);
        ?>
        <span class="trend-delta <?php echo $dcls; ?>"><?php echo $dstr; ?> in <?php echo $days; ?>d</span>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>

  <?php endif; ?>

  <div class="gen-time">
    Generated <?php echo date('Y-m-d H:i:s T'); ?>
    &nbsp;|&nbsp;
    <a class="nav-link" style="font-size:11px; padding:3px 8px;" href="weight.php">← Dashboard</a>
    &nbsp;
    <a class="nav-link" style="font-size:11px; padding:3px 8px;" href="graph.php">📈 Graph</a>
  </div>

</div>
</body>
</html>
