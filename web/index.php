<?php
declare(strict_types=1);
date_default_timezone_set('America/New_York');

$WGER_BASE  = 'https://your-wger-instance.com'; // ← your WGER URL
$WGER_TOKEN = 'your_wger_api_token_here'; // ← WGER > Account > API Key

// Grab a few quick stats for the hero display
function quick_get(string $base, string $token, string $path, array $q = []): array {
  $url = $base . $path . (!empty($q) ? '?' . http_build_query($q) : '');
  $ch  = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
    CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Token ' . $token],
  ]);
  $body = curl_exec($ch);
  if ($body === false || (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) >= 300) return [];
  return json_decode((string)$body, true) ?? [];
}

$weight = null;
$weightDate = null;
try {
  $wr = quick_get($WGER_BASE, $WGER_TOKEN, '/api/v2/weightentry/', ['limit' => 1, 'ordering' => '-date']);
  if (!empty($wr['results'][0])) {
    $weight     = round((float)$wr['results'][0]['weight'], 1);
    $weightDate = substr($wr['results'][0]['date'], 0, 10);
  }
} catch (Throwable) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Superman Health Matrix</title>
  <link rel="icon" type="image/svg+xml" href="favicon.svg">
  <style>
    *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

    body {
      background:#000;
      color:#00FF41;
      font-family:'Courier New', Courier, monospace;
      min-height:100vh;
      overflow-x:hidden;
    }

    /* ── Matrix rain canvas ── */
    #matrix-canvas {
      position:fixed; top:0; left:0;
      width:100%; height:100%;
      z-index:0; opacity:0.18;
      pointer-events:none;
    }

    /* ── Layout ── */
    .page {
      position:relative; z-index:1;
      min-height:100vh;
      display:flex; flex-direction:column;
      align-items:center; justify-content:center;
      padding:40px 20px;
    }

    /* ── Hero logo ── */
    .logo {
      text-align:center;
      margin-bottom:10px;
    }
    .logo-s {
      font-size:clamp(3rem, 10vw, 6rem);
      font-weight:bold;
      color:#00FF41;
      text-shadow:0 0 30px #00FF41, 0 0 60px #00FF41, 0 0 4px #fff;
      letter-spacing:4px;
      display:block;
      animation:pulse 3s ease-in-out infinite;
    }
    @keyframes pulse {
      0%,100% { text-shadow:0 0 30px #00FF41, 0 0 60px #00FF41, 0 0 4px #fff; }
      50%      { text-shadow:0 0 60px #00FF41, 0 0 120px #00FF41, 0 0 8px #fff; }
    }
    .logo-sub {
      font-size:clamp(0.7rem, 2vw, 0.95rem);
      letter-spacing:6px;
      color:#00CC28;
      margin-top:6px;
      text-transform:uppercase;
    }

    /* ── Live stat bar ── */
    .stat-bar {
      margin:28px 0 40px;
      display:flex; gap:32px; flex-wrap:wrap; justify-content:center;
      font-size:0.8rem; letter-spacing:2px; color:#00AA22;
    }
    .stat-bar span { white-space:nowrap; }
    .stat-bar strong { color:#00FF41; font-size:1rem; }

    /* ── Nav cards ── */
    .cards {
      display:grid;
      grid-template-columns:repeat(3,1fr);
      gap:20px;
      width:100%; max-width:860px;
    }
    @media(max-width:660px) { .cards { grid-template-columns:1fr; max-width:360px; } }

    .card {
      border:1px solid #00FF41;
      background:rgba(0,255,65,0.04);
      padding:28px 20px 24px;
      text-decoration:none;
      color:#00FF41;
      display:flex; flex-direction:column; align-items:center; gap:12px;
      text-align:center;
      transition:background 0.2s, box-shadow 0.2s, transform 0.2s;
      cursor:pointer;
    }
    .card:hover {
      background:rgba(0,255,65,0.10);
      box-shadow:0 0 24px rgba(0,255,65,0.45), inset 0 0 12px rgba(0,255,65,0.05);
      transform:translateY(-3px);
    }
    .card-icon { font-size:2.4rem; line-height:1; }
    .card-title {
      font-size:1rem; letter-spacing:2px; font-weight:bold;
      color:#00FF41; text-transform:uppercase;
    }
    .card-desc { font-size:0.75rem; color:#00AA22; line-height:1.6; letter-spacing:0.5px; }
    .card-arrow {
      margin-top:6px; font-size:0.75rem; color:#555; letter-spacing:2px;
      transition:color 0.2s;
    }
    .card:hover .card-arrow { color:#00FF41; }

    /* ── Footer ── */
    .footer {
      margin-top:48px; text-align:center;
      font-size:0.7rem; color:#1a4d1a; letter-spacing:2px;
    }
    .footer a { color:#1a4d1a; text-decoration:none; }
    .footer a:hover { color:#00FF41; }

    /* ── Sound toggle button ── */
    #sound-btn {
      position:fixed; bottom:20px; right:20px; z-index:10;
      background:#000; border:1px solid #00FF41; color:#00FF41;
      font-family:'Courier New',monospace; font-size:11px; letter-spacing:2px;
      padding:7px 14px; cursor:pointer;
      transition:background 0.15s, box-shadow 0.15s;
      user-select:none;
    }
    #sound-btn:hover { background:rgba(0,255,65,0.12); box-shadow:0 0 10px rgba(0,255,65,0.4); }
    #sound-btn.muted  { color:#333; border-color:#333; }

    /* ── Scanline overlay ── */
    body::after {
      content:'';
      position:fixed; top:0; left:0; width:100%; height:100%;
      background:repeating-linear-gradient(
        0deg, transparent, transparent 2px,
        rgba(0,0,0,0.04) 2px, rgba(0,0,0,0.04) 4px
      );
      pointer-events:none; z-index:2;
    }
  </style>
</head>
<body>

<canvas id="matrix-canvas"></canvas>
<button id="sound-btn" title="Toggle sound">♪ SOUND ON</button>
<audio id="matrix-voice" src="matrix-voice.mp3" loop preload="auto"></audio>

<div class="page">

  <!-- Logo -->
  <div class="logo">
    <span class="logo-s">▌S▐</span>
    <div class="logo-sub">Superman Health Matrix</div>
  </div>

  <!-- Live stats -->
  <div class="stat-bar">
    <?php if ($weight !== null): ?>
    <span>WEIGHT &nbsp;<strong><?php echo $weight; ?> lbs</strong></span>
    <span style="color:#333;">|</span>
    <?php endif; ?>
    <span>STATUS &nbsp;<strong style="color:#00FF41;">● ONLINE</strong></span>
    <span style="color:#333;">|</span>
    <span>SYNC &nbsp;<strong><?php echo date('Y-m-d'); ?></strong></span>
  </div>

  <!-- Navigation cards -->
  <div class="cards">

    <a class="card" href="weight.php">
      <div class="card-icon">🩺</div>
      <div class="card-title">Daily Dashboard</div>
      <div class="card-desc">
        Full health snapshot for any date.<br>
        Weight · Sleep · Nutrition · Activity · Body composition.
      </div>
      <div class="card-arrow">ENTER →</div>
    </a>

    <a class="card" href="charts.php">
      <div class="card-icon">📊</div>
      <div class="card-title">All Charts</div>
      <div class="card-desc">
        Every metric over time in one view.<br>
        30 / 60 / 90 / 365 day ranges.
      </div>
      <div class="card-arrow">ENTER →</div>
    </a>

    <a class="card" href="graph.php">
      <div class="card-icon">📈</div>
      <div class="card-title">Graph Metric</div>
      <div class="card-desc">
        Deep-dive any single metric.<br>
        20 tracked variables, custom range.
      </div>
      <div class="card-arrow">ENTER →</div>
    </a>

  </div>

  <div class="footer">
    <p><?php echo date('Y-m-d H:i:s T'); ?> &nbsp;·&nbsp; WGER HEALTH STACK &nbsp;·&nbsp; PRIVATE</p>
  </div>

</div>

<script>
// ── Matrix rain ──────────────────────────────────────────────────────────────
(function(){
  var canvas = document.getElementById('matrix-canvas');
  var ctx    = canvas.getContext('2d');
  var cols, drops;
  var chars  = '01アイウエオカキクケコサシスセソタチツテトナニヌネノ'.split('');
  var fs     = 14;

  function resize(){
    canvas.width  = window.innerWidth;
    canvas.height = window.innerHeight;
    cols  = Math.floor(canvas.width / fs);
    drops = Array(cols).fill(1);
  }

  function draw(){
    ctx.fillStyle = 'rgba(0,0,0,0.05)';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = '#00FF41';
    ctx.font = fs + 'px Courier New';
    for(var i = 0; i < drops.length; i++){
      var ch = chars[Math.floor(Math.random() * chars.length)];
      ctx.fillText(ch, i * fs, drops[i] * fs);
      if(drops[i] * fs > canvas.height && Math.random() > 0.975) drops[i] = 0;
      drops[i]++;
    }
  }

  resize();
  window.addEventListener('resize', resize);
  setInterval(draw, 50);
})();

// ── Matrix sound engine (Web Audio API, no external files) ───────────────────
(function(){
  var AC = window.AudioContext || window.webkitAudioContext;
  if (!AC) return; // browser doesn't support it

  var ac          = null;   // AudioContext (created on first interaction)
  var muted       = false;
  var ambStart    = false;
  var noiseBuffer = null;
  var masterGain  = null;
  var voice       = document.getElementById('matrix-voice');

  var btn = document.getElementById('sound-btn');

  // ── Build a 2-second white-noise buffer ──────────────────────────────────
  function makeNoise() {
    var buf  = ac.createBuffer(1, ac.sampleRate * 2, ac.sampleRate);
    var data = buf.getChannelData(0);
    for (var i = 0; i < data.length; i++) data[i] = Math.random() * 2 - 1;
    return buf;
  }

  // ── Boot sequence: ascending sweep + two confirmation pings ─────────────
  function playBoot() {
    var t = ac.currentTime;

    // Rising sweep
    var osc  = ac.createOscillator();
    var gain = ac.createGain();
    osc.type = 'sawtooth';
    osc.frequency.setValueAtTime(60, t);
    osc.frequency.exponentialRampToValueAtTime(900, t + 0.6);
    gain.gain.setValueAtTime(0, t);
    gain.gain.linearRampToValueAtTime(0.18, t + 0.05);
    gain.gain.exponentialRampToValueAtTime(0.001, t + 0.65);
    osc.connect(gain); gain.connect(masterGain);
    osc.start(t); osc.stop(t + 0.7);

    // Ping 1
    ping(t + 0.55, 1200, 0.12, 0.18);
    // Ping 2 (higher confirm tone)
    ping(t + 0.78, 1800, 0.10, 0.22);
  }

  // ── Short sine ping ──────────────────────────────────────────────────────
  function ping(t, freq, vol, dur) {
    var osc  = ac.createOscillator();
    var gain = ac.createGain();
    osc.type = 'sine';
    osc.frequency.value = freq;
    gain.gain.setValueAtTime(vol, t);
    gain.gain.exponentialRampToValueAtTime(0.001, t + dur);
    osc.connect(gain); gain.connect(masterGain);
    osc.start(t); osc.stop(t + dur + 0.02);
  }

  // ── Ambient: looping voice clip + occasional glitch ticks ───────────────
  function startAmbient() {
    if (ambStart) return;
    ambStart = true;

    // Play the voice clip (looped via the <audio> element)
    voice.volume = 0.72;
    voice.play().catch(function(){});

    // Occasional random glitch ticks (every 2–7 seconds)
    function scheduleGlitch() {
      if (muted) { setTimeout(scheduleGlitch, 2000); return; }
      var delay = 2000 + Math.random() * 5000;
      setTimeout(function(){
        playGlitch();
        scheduleGlitch();
      }, delay);
    }
    scheduleGlitch();
  }

  // ── Glitch: short burst of detuned noise ────────────────────────────────
  function playGlitch() {
    if (!ac || muted) return;
    var t    = ac.currentTime;
    var freq = 400 + Math.random() * 3000;
    var dur  = 0.04 + Math.random() * 0.08;

    var ns   = ac.createBufferSource();
    ns.buffer = noiseBuffer;
    var bp   = ac.createBiquadFilter();
    bp.type  = 'bandpass';
    bp.frequency.value = freq;
    bp.Q.value = 2 + Math.random() * 8;
    var g    = ac.createGain();
    g.gain.setValueAtTime(0.08, t);
    g.gain.exponentialRampToValueAtTime(0.001, t + dur);
    ns.connect(bp); bp.connect(g); g.connect(masterGain);
    ns.start(t); ns.stop(t + dur + 0.01);
  }

  // ── Hover blip: quick downward chirp ────────────────────────────────────
  function playHover() {
    if (!ac || muted) return;
    var t    = ac.currentTime;
    var freq = 1200 + Math.random() * 800;
    var osc  = ac.createOscillator();
    var gain = ac.createGain();
    osc.type = 'square';
    osc.frequency.setValueAtTime(freq, t);
    osc.frequency.exponentialRampToValueAtTime(freq * 0.35, t + 0.09);
    gain.gain.setValueAtTime(0.09, t);
    gain.gain.exponentialRampToValueAtTime(0.001, t + 0.1);
    osc.connect(gain); gain.connect(masterGain);
    osc.start(t); osc.stop(t + 0.11);
  }

  // ── Click: descending sawtooth sweep ────────────────────────────────────
  function playClick() {
    if (!ac || muted) return;
    var t = ac.currentTime;

    var osc  = ac.createOscillator();
    var gain = ac.createGain();
    osc.type = 'sawtooth';
    osc.frequency.setValueAtTime(1400, t);
    osc.frequency.exponentialRampToValueAtTime(70, t + 0.22);
    gain.gain.setValueAtTime(0.22, t);
    gain.gain.exponentialRampToValueAtTime(0.001, t + 0.23);
    osc.connect(gain); gain.connect(masterGain);
    osc.start(t); osc.stop(t + 0.24);

    // Accompany with a noise burst
    var ns  = ac.createBufferSource();
    ns.buffer = noiseBuffer;
    var lp  = ac.createBiquadFilter();
    lp.type = 'lowpass'; lp.frequency.value = 600;
    var ng  = ac.createGain();
    ng.gain.setValueAtTime(0.12, t);
    ng.gain.exponentialRampToValueAtTime(0.001, t + 0.18);
    ns.connect(lp); lp.connect(ng); ng.connect(masterGain);
    ns.start(t); ns.stop(t + 0.2);
  }

  // ── Init on first interaction ────────────────────────────────────────────
  function init() {
    if (ac) return;
    ac = new AC();
    masterGain = ac.createGain();
    masterGain.gain.value = 1.0;
    masterGain.connect(ac.destination);
    noiseBuffer = makeNoise();
    playBoot();
    startAmbient();
  }

  // ── Try to autoplay immediately; if blocked, retry on first interaction ──
  function tryAutoplay() {
    // Attempt to play voice right away
    voice.volume = 0.72;
    voice.play().then(function(){
      // Browser allowed it — init Web Audio for effects too
      init();
    }).catch(function(){
      // Autoplay blocked — show "click anywhere" hint on button
      btn.textContent = '♪ CLICK TO ENABLE';
      btn.classList.add('muted');

      var initEvents = ['click','keydown','touchstart','pointerdown'];
      function onFirstInteraction() {
        init();
        btn.textContent = '♪ SOUND ON';
        btn.classList.remove('muted');
        initEvents.forEach(function(ev){ document.removeEventListener(ev, onFirstInteraction); });
      }
      initEvents.forEach(function(ev){ document.addEventListener(ev, onFirstInteraction, {once:true}); });
    });
  }

  // ── Toggle button ────────────────────────────────────────────────────────
  btn.addEventListener('click', function(e){
    e.stopPropagation();
    if (!ac) { init(); return; }
    muted = !muted;
    masterGain.gain.setTargetAtTime(muted ? 0 : 1, ac.currentTime, 0.1);
    if (muted) { voice.pause(); } else { voice.play().catch(function(){}); }
    btn.textContent = muted ? '♪ SOUND OFF' : '♪ SOUND ON';
    btn.classList.toggle('muted', muted);
  });

  // ── Wire up cards ────────────────────────────────────────────────────────
  document.querySelectorAll('.card').forEach(function(card){
    card.addEventListener('mouseenter', function(){
      if (!ac) return;
      playHover();
    });
    card.addEventListener('click', function(){
      if (!ac) return;
      playClick();
    });
  });

  // ── Kick off autoplay attempt when DOM is ready ──────────────────────────
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', tryAutoplay);
  } else {
    tryAutoplay();
  }

})();
</script>

</body>
</html>
