<?php
require_once __DIR__ . '/auth.php';
$user = auth_require_setup();

$theme       = user_theme($user);
$wger_base   = WGER_BASE_URL;
$wger_token  = $user['wger_token'] ?? '';
$date        = $_GET['date'] ?? date('Y-m-d');

// ── WGER helper ────────────────────────────────────────────────────────────
function wger(string $path, array $params = []): array {
    global $wger_base, $wger_token;
    if (!$wger_token) return [];
    $url = $wger_base . $path . ($params ? '?' . http_build_query($params) : '');
    $ctx = stream_context_create(['http' => [
        'header'  => "Authorization: Token $wger_token\r\nAccept: application/json\r\n",
        'timeout' => 10,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    return $raw ? (json_decode($raw, true) ?? []) : [];
}

$no_token = empty($wger_token);

// ── Data fetching ──────────────────────────────────────────────────────────
$weight_today  = null;
$measurements  = [];
$sessions      = [];
$totals        = [];
$nutrition     = ['calories' => 0, 'protein_g' => 0, 'carbs_g' => 0, 'fat_g' => 0];

if (!$no_token) {
    // Weight
    $wr = wger('/api/v2/weightentry/', ['limit' => 10, 'ordering' => '-date']);
    foreach ($wr['results'] ?? [] as $w) {
        if (substr($w['date'], 0, 10) === $date) {
            $weight_today = round((float)$w['weight'] * 2.20462, 2);
            break;
        }
    }
    if (!$weight_today && !empty($wr['results'])) {
        $weight_today = round((float)$wr['results'][0]['weight'] * 2.20462, 2);
    }

    // Categories
    $cats_raw = wger('/api/v2/measurement-category/', ['limit' => 200]);
    $cat_map  = [];
    foreach ($cats_raw['results'] ?? [] as $c) {
        $cat_map[$c['id']] = ['name' => $c['name'], 'unit' => $c['unit'] ?? ''];
    }

    // Measurements for today
    $meas_raw = wger('/api/v2/measurement/', ['limit' => 200, 'ordering' => '-date']);
    $skip_in_body = ['Steps', 'Distance', 'Total Distance', 'Daily Active Calories'];
    foreach ($meas_raw['results'] ?? [] as $m) {
        if (substr($m['date'], 0, 10) !== $date) continue;
        $cid  = $m['category'];
        $info = $cat_map[$cid] ?? ['name' => "Cat $cid", 'unit' => ''];
        $name = $info['name'];
        $val  = (float)$m['value'];
        $unit = $info['unit'];

        // Unit conversions
        if ($name === 'Steps' && $unit === 'ksteps')         { $val *= 1000; $unit = 'steps'; }
        if (in_array($name, ['Distance','Total Distance']) && $unit === 'km') { $val = round($val * 0.621371, 2); $unit = 'mi'; }
        if (in_array($name, ['Muscle Mass','Bone Mass']) && $unit === 'kg')   { $val = round($val * 2.20462, 2); $unit = 'lbs'; }

        $measurements[$name] = ['value' => $val, 'unit' => $unit, 'in_body' => !in_array($name, $skip_in_body)];
    }

    // Workout sessions
    $sess_raw = wger('/api/v2/workoutsession/', ['limit' => 20, 'ordering' => '-date']);
    foreach ($sess_raw['results'] ?? [] as $s) {
        if (substr($s['date'], 0, 10) !== $date) continue;
        $stats = json_decode($s['notes'] ?? '{}', true) ?: [];
        if (empty($stats['withings_id'])) continue; // skip non-Withings sessions
        $ts = $s['time_start'] ?? null;
        $te = $s['time_end']   ?? null;
        $display_time = null;
        $dur = null;
        if ($ts) {
            $t = DateTime::createFromFormat('H:i:s', $ts);
            $display_time = $t ? $t->format('g:i A') : $ts;
        }
        if ($ts && $te) {
            $t1 = DateTime::createFromFormat('H:i:s', $ts);
            $t2 = DateTime::createFromFormat('H:i:s', $te);
            if ($t1 && $t2) $dur = (int)round(abs($t2->getTimestamp() - $t1->getTimestamp()) / 60);
        }
        $sessions[] = [
            'type'     => $stats['type']       ?? 'Activity',
            'time'     => $display_time,
            'dur'      => $dur,
            'dist_mi'  => $stats['dist_mi']    ?? null,
            'cal'      => $stats['active_cal'] ?? null,
            'steps'    => $stats['steps']      ?? null,
        ];
    }

    // Totals
    $totals = [
        'dist_mi' => round(array_sum(array_column($sessions, 'dist_mi')), 2),
        'cal'     => array_sum(array_column($sessions, 'cal')),
        'steps'   => array_sum(array_column($sessions, 'steps')),
        'dur'     => array_sum(array_column($sessions, 'dur')),
    ];
}

// ── BMR / TDEE / Deficit ───────────────────────────────────────────────────
$weight_kg   = ($weight_today ?? ($user['current_weight_lb'] ?? 180)) / 2.20462;
$height_cm   = (int)$user['height_cm'];
$age         = (int)$user['age'];
$gender      = (int)$user['gender'];
$bmr         = round(10 * $weight_kg + 6.25 * $height_cm - 5 * $age + ($gender === 1 ? 5 : -161));
$bmr_device  = $measurements['Basal Metabolic Rate']['value'] ?? null;
$bmr_used    = $bmr_device ?? $bmr;
$active_cal  = $measurements['Daily Active Calories']['value'] ?? 0;
$tdee        = $active_cal > 0 ? round($bmr_used + $active_cal) : round($bmr_used * (float)$user['activity_level']);
$tdee_src    = $active_cal > 0 ? 'device' : 'estimated';
$food_cal    = $measurements['Daily Calories']['value'] ?? 0;
$goal_cal    = (int)$user['goal_calories'];
$deficit     = $food_cal > 0 ? round($tdee - $food_cal) : null;

// Journey progress
$start_wt    = (float)($user['journey_start_weight_lb'] ?? 0);
$goal_wt     = (float)($user['goal_weight_lb'] ?? 0);
$lost_so_far = $start_wt > 0 && $weight_today ? round($start_wt - $weight_today, 1) : null;
$total_to_lose = $start_wt > 0 && $goal_wt > 0 ? round($start_wt - $goal_wt, 1) : null;
$progress_pct  = ($total_to_lose > 0 && $lost_so_far !== null)
    ? min(100, max(0, round($lost_so_far / $total_to_lose * 100)))
    : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo APP_NAME; ?> — <?php echo htmlspecialchars($user['name']); ?></title>
  <?php echo theme_head($theme); ?>
</head>
<body>

<nav class="nav">
  <a href="dashboard.php" class="nav-brand"><?php echo APP_NAME; ?></a>
  <div class="nav-links">
    <a href="dashboard.php">Today</a>
    <a href="charts.php">Charts</a>
    <a href="profile.php">Profile</a>
    <?php if (auth_is_admin($user)): ?><a href="admin.php">Admin</a><?php endif; ?>
    <a href="logout.php">Sign Out</a>
  </div>
  <div class="nav-user"><?php echo htmlspecialchars($user['name']); ?></div>
</nav>

<div class="page">

  <?php if ($no_token): ?>
  <!-- ── No token yet ─────────────────────────────────────────────────── -->
  <div class="card" style="text-align:center; padding:48px;">
    <h2>Your account is being set up</h2>
    <p>Your data connection will be active shortly. Check back soon.</p>
  </div>

  <?php else: ?>

  <!-- ── Date nav ─────────────────────────────────────────────────────── -->
  <?php
    $prev = date('Y-m-d', strtotime($date . ' -1 day'));
    $next = date('Y-m-d', strtotime($date . ' +1 day'));
    $isToday = $date === date('Y-m-d');
  ?>
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:28px;">
    <div>
      <div class="eyebrow"><?php echo $isToday ? 'Today' : 'History'; ?></div>
      <h1 style="font-size:32px;margin-bottom:0;"><?php echo date('l, F j', strtotime($date)); ?></h1>
    </div>
    <div style="display:flex;gap:8px;align-items:center;">
      <a href="?date=<?php echo $prev; ?>" class="btn btn-secondary btn-sm">←</a>
      <?php if (!$isToday): ?>
        <a href="dashboard.php" class="btn btn-secondary btn-sm">Today</a>
      <?php endif; ?>
      <?php if (!$isToday): ?>
        <a href="?date=<?php echo $next; ?>" class="btn btn-secondary btn-sm">→</a>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Top stats ────────────────────────────────────────────────────── -->
  <div class="grid grid-4" style="margin-bottom:20px;">
    <div class="stat">
      <div class="stat-label">Weight</div>
      <div class="stat-value">
        <?php echo $weight_today ?? '—'; ?>
        <span class="stat-unit">lbs</span>
      </div>
      <?php if ($lost_so_far !== null && $lost_so_far > 0): ?>
        <div class="stat-delta down">↓ <?php echo $lost_so_far; ?> lbs lost</div>
      <?php endif; ?>
    </div>
    <div class="stat">
      <div class="stat-label">Goal</div>
      <div class="stat-value">
        <?php echo $goal_wt ?: '—'; ?>
        <span class="stat-unit">lbs</span>
      </div>
      <?php if ($weight_today && $goal_wt): ?>
        <div class="stat-delta <?php echo $weight_today > $goal_wt ? 'up' : 'down'; ?>">
          <?php echo abs(round($weight_today - $goal_wt, 1)); ?> lbs to go
        </div>
      <?php endif; ?>
    </div>
    <div class="stat">
      <div class="stat-label">Calories In</div>
      <div class="stat-value">
        <?php echo $food_cal ? number_format($food_cal) : '—'; ?>
        <span class="stat-unit">kcal</span>
      </div>
      <?php if ($goal_cal): ?>
        <div class="stat-delta" style="color:var(--text-muted);">Goal: <?php echo number_format($goal_cal); ?></div>
      <?php endif; ?>
    </div>
    <div class="stat">
      <div class="stat-label">Deficit <?php echo $tdee_src === 'device' ? '' : '<span style="font-size:10px;color:var(--text-muted);">(est.)</span>'; ?></div>
      <div class="stat-value <?php echo ($deficit ?? 0) > 0 ? '' : ''; ?>">
        <?php if ($deficit !== null): ?>
          <?php echo $deficit > 0 ? '+' : ''; ?><?php echo number_format($deficit); ?>
          <span class="stat-unit">kcal</span>
        <?php else: ?>—<?php endif; ?>
      </div>
      <div class="stat-delta" style="color:var(--text-muted);">TDEE: <?php echo number_format($tdee); ?></div>
    </div>
  </div>

  <!-- ── Journey progress ─────────────────────────────────────────────── -->
  <?php if ($total_to_lose > 0): ?>
  <div class="card" style="margin-bottom:20px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
      <div>
        <div class="eyebrow">Journey Progress</div>
        <span style="font-size:15px;font-weight:600;"><?php echo $lost_so_far ?? 0; ?> of <?php echo $total_to_lose; ?> lbs</span>
      </div>
      <div class="badge <?php echo $progress_pct >= 100 ? 'badge-green' : 'badge-blue'; ?>" style="font-size:16px;padding:6px 14px;">
        <?php echo $progress_pct; ?>%
      </div>
    </div>
    <div class="progress">
      <div class="progress-fill" style="width:<?php echo $progress_pct; ?>%;"></div>
    </div>
    <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-muted);margin-top:6px;">
      <span><?php echo $start_wt; ?> lbs start</span>
      <span><?php echo $goal_wt; ?> lbs goal</span>
    </div>
  </div>
  <?php endif; ?>

  <div class="grid grid-2" style="align-items:start;">

    <!-- ── Left column ──────────────────────────────────────────────── -->
    <div>

      <!-- Workouts -->
      <?php
        $act_steps    = $measurements['Steps']['value']          ?? null;
        $act_total_mi = $measurements['Total Distance']['value'] ?? null;
        $act_cal      = $measurements['Daily Active Calories']['value'] ?? null;
        $has_sessions = !empty($sessions);
        $has_activity = $act_steps !== null || $act_total_mi !== null;
      ?>
      <?php if ($has_sessions || $has_activity): ?>
      <div class="card">
        <div class="section-header"><h2>Workouts</h2></div>

        <?php if ($has_sessions): ?>
          <?php foreach ($sessions as $i => $s): ?>
          <div class="session-card">
            <div style="display:flex;justify-content:space-between;align-items:start;">
              <div>
                <div class="session-title"><?php echo htmlspecialchars($s['type']); ?></div>
                <div class="session-meta">
                  <?php echo $s['time'] ?? ''; ?>
                  <?php if ($s['dur']): ?> · <?php echo $s['dur']; ?> min<?php endif; ?>
                </div>
              </div>
              <?php if ($s['cal']): ?>
                <div class="badge badge-orange"><?php echo number_format($s['cal']); ?> kcal</div>
              <?php endif; ?>
            </div>
            <?php if ($s['dist_mi'] || $s['steps']): ?>
            <div style="display:flex;gap:16px;margin-top:10px;flex-wrap:wrap;">
              <?php if ($s['dist_mi']): ?>
              <div><div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;">Distance</div>
                <div style="font-weight:600;"><?php echo $s['dist_mi']; ?> mi</div></div>
              <?php endif; ?>
              <?php if ($s['steps']): ?>
              <div><div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;">Steps</div>
                <div style="font-weight:600;"><?php echo number_format($s['steps']); ?></div></div>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>

          <?php if (count($sessions) > 1): ?>
          <div class="totals-row">
            <?php if ($totals['dist_mi']): ?>
              <div class="totals-item"><div class="tval"><?php echo $totals['dist_mi']; ?> mi</div><div class="tlbl">Total dist.</div></div>
            <?php endif; ?>
            <?php if ($totals['cal']): ?>
              <div class="totals-item"><div class="tval"><?php echo number_format($totals['cal']); ?></div><div class="tlbl">Active cal</div></div>
            <?php endif; ?>
            <?php if ($totals['steps']): ?>
              <div class="totals-item"><div class="tval"><?php echo number_format($totals['steps']); ?></div><div class="tlbl">Steps</div></div>
            <?php endif; ?>
            <?php if ($totals['dur']): ?>
              <div class="totals-item"><div class="tval"><?php echo $totals['dur']; ?> min</div><div class="tlbl">Duration</div></div>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <div class="divider"></div>
        <?php endif; ?>

        <!-- All-day activity -->
        <?php if ($act_steps !== null || $act_total_mi !== null || $act_cal !== null): ?>
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:var(--text-muted);margin-bottom:8px;">All-day</div>
        <?php if ($act_steps !== null): ?>
        <div class="data-row">
          <span class="label">Total Steps</span>
          <span class="value <?php echo $act_steps >= 10000 ? 'success' : ''; ?>">
            <?php echo number_format($act_steps); ?>
            <?php echo $act_steps >= 10000 ? ' ✓' : ''; ?>
          </span>
        </div>
        <?php endif; ?>
        <?php if ($act_total_mi !== null): ?>
        <div class="data-row">
          <span class="label">Total Distance</span>
          <span class="value"><?php echo $act_total_mi; ?> mi</span>
        </div>
        <?php endif; ?>
        <?php if ($act_cal): ?>
        <div class="data-row">
          <span class="label">Active Calories</span>
          <span class="value"><?php echo number_format($act_cal, 1); ?> kcal</span>
        </div>
        <?php endif; ?>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Metabolism -->
      <div class="card">
        <div class="section-header"><h2>Metabolism</h2></div>
        <div class="data-row">
          <span class="label">BMR <?php echo $bmr_device ? '<span style="font-size:11px;color:var(--text-muted);">(device)</span>' : '<span style="font-size:11px;color:var(--text-muted);">(calculated)</span>'; ?></span>
          <span class="value"><?php echo number_format($bmr_used); ?> kcal</span>
        </div>
        <div class="data-row">
          <span class="label">TDEE <span style="font-size:11px;color:var(--text-muted);">(<?php echo $tdee_src; ?>)</span></span>
          <span class="value"><?php echo number_format($tdee); ?> kcal</span>
        </div>
        <div class="data-row">
          <span class="label">Calorie Goal</span>
          <span class="value"><?php echo number_format($goal_cal); ?> kcal</span>
        </div>
        <?php if ($deficit !== null): ?>
        <div class="data-row">
          <span class="label">Today's Deficit</span>
          <span class="value <?php echo $deficit > 0 ? 'success' : 'danger'; ?>">
            <?php echo $deficit > 0 ? '+' : ''; ?><?php echo number_format($deficit); ?> kcal
          </span>
        </div>
        <?php endif; ?>
      </div>

    </div>

    <!-- ── Right column ──────────────────────────────────────────────── -->
    <div>

      <!-- Body Composition -->
      <?php
        $body_cats = ['Body Fat', 'Muscle Mass', 'Bone Mass', 'Hydration', 'Basal Metabolic Rate', 'Metabolic Age', 'Visceral Fat'];
        $body_data = array_filter($measurements, fn($k) => in_array($k, $body_cats), ARRAY_FILTER_USE_KEY);
      ?>
      <?php if (!empty($body_data)): ?>
      <div class="card">
        <div class="section-header"><h2>Body Composition</h2></div>
        <?php foreach ($body_data as $name => $info): ?>
        <div class="data-row">
          <span class="label"><?php echo htmlspecialchars($name); ?></span>
          <span class="value"><?php echo round($info['value'], 1); ?> <?php echo $info['unit']; ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Nutrition -->
      <?php
        $nut_cats = ['Daily Calories', 'Daily Protein', 'Daily Carbs', 'Daily Fat', 'MFP Exercise Calories'];
        $nut_data = array_filter($measurements, fn($k) => in_array($k, $nut_cats), ARRAY_FILTER_USE_KEY);
      ?>
      <?php if (!empty($nut_data) || $goal_cal): ?>
      <div class="card">
        <div class="section-header"><h2>Nutrition</h2></div>
        <?php if ($goal_cal && $food_cal): ?>
          <?php $nut_pct = min(100, round($food_cal / $goal_cal * 100)); ?>
          <div style="margin-bottom:14px;">
            <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px;">
              <span style="color:var(--text-muted);">Calories used</span>
              <span style="font-weight:600;"><?php echo number_format($food_cal); ?> / <?php echo number_format($goal_cal); ?></span>
            </div>
            <div class="progress">
              <div class="progress-fill" style="width:<?php echo $nut_pct; ?>%;background:<?php echo $nut_pct > 100 ? 'var(--danger)' : 'var(--accent)'; ?>;"></div>
            </div>
          </div>
        <?php endif; ?>
        <?php foreach ($nut_data as $name => $info): ?>
        <div class="data-row">
          <span class="label"><?php echo str_replace('Daily ', '', htmlspecialchars($name)); ?></span>
          <span class="value"><?php echo round($info['value'], 0); ?> <?php echo $info['unit']; ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Sleep -->
      <?php
        $sleep_cats = ['Sleep Duration', 'Sleep Score', 'Sleep Heart Rate', 'Sleep HRV', 'Sleep Respiratory Rate'];
        $sleep_data = array_filter($measurements, fn($k) => in_array($k, $sleep_cats), ARRAY_FILTER_USE_KEY);
      ?>
      <?php if (!empty($sleep_data)): ?>
      <div class="card">
        <div class="section-header"><h2>Sleep</h2></div>
        <?php foreach ($sleep_data as $name => $info): ?>
        <div class="data-row">
          <span class="label"><?php echo str_replace('Sleep ', '', htmlspecialchars($name)); ?></span>
          <span class="value <?php echo ($name === 'Sleep Score' && $info['value'] >= 80) ? 'success' : ''; ?>">
            <?php echo round($info['value'], 1); ?> <?php echo $info['unit']; ?>
          </span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

    </div>
  </div>
  <?php endif; // end no_token check ?>

</div><!-- .page -->

<!-- Theme switcher (floating) -->
<div style="position:fixed;bottom:20px;right:20px;z-index:50;">
  <details style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-md);padding:12px;box-shadow:var(--shadow-lg);">
    <summary style="cursor:pointer;font-size:13px;color:var(--text-secondary);user-select:none;list-style:none;">🎨 Theme</summary>
    <div class="theme-picker" style="margin-top:10px;flex-direction:column;">
      <?php foreach (THEMES as $slug => $label): ?>
        <a href="?theme=<?php echo $slug; ?>&date=<?php echo $date; ?>"
           class="theme-option <?php echo $slug === $theme ? 'active' : ''; ?>"
           style="text-align:center;"
           onclick="saveTheme('<?php echo $slug; ?>')">
          <?php echo $label; ?>
        </a>
      <?php endforeach; ?>
    </div>
  </details>
</div>

<script>
function saveTheme(slug) {
  fetch('api/set_theme.php?theme=' + encodeURIComponent(slug));
}

// Apply theme from URL param (quick preview)
<?php if (isset($_GET['theme']) && array_key_exists($_GET['theme'], THEMES)): ?>
document.addEventListener('DOMContentLoaded', function() {
  // Theme was applied via URL — save it
  saveTheme('<?php echo $_GET['theme']; ?>');
});
<?php endif; ?>
</script>

</body>
</html>
<?php
// Apply theme from URL param and save to user profile
if (isset($_GET['theme']) && array_key_exists($_GET['theme'], THEMES)) {
    user_update($user['id'], ['theme' => $_GET['theme']]);
}
?>
