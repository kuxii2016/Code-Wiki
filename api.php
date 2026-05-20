<?php
$docFile = __DIR__ . '/doc.json';
if (!file_exists($docFile)) {
    header('Location: generate.php');
    exit;
}
$data = json_decode(file_get_contents($docFile), true);

function e($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

$projects = [];
foreach ($data['classes'] as $key => $c) {
    $parts = explode('\\', $c['file']);
    $proj = $parts[0];
    if (!isset($projects[$proj])) $projects[$proj] = ['namespaces' => [], 'types' => 0];
    $projects[$proj]['types']++;
    $nss = $c['namespace'];
    if (!in_array($nss, $projects[$proj]['namespaces'])) $projects[$proj]['namespaces'][] = $nss;
}
ksort($projects);
foreach ($projects as &$p) { sort($p['namespaces']); }
unset($p);

$apiGroups = [];
foreach ($data['classes'] as $k => $c) {
    $fileLower = str_replace('\\', '/', strtolower($c['file']));
    if (strpos($fileLower, 'api') === false) continue;
    if (strpos($fileLower, 'serverpages') !== false || strpos($fileLower, 'assemblyinfo') !== false) continue;
    $nsParts = explode('.', $c['namespace']);
    $service = count($nsParts) >= 2 ? $nsParts[0] . '.' . $nsParts[1] : $nsParts[0];
    if (count($nsParts) >= 3 && $nsParts[1] === 'API') $service = $nsParts[0];
    if (!isset($apiGroups[$service])) $apiGroups[$service] = ['classes' => [], 'methods' => 0];
    $apiGroups[$service]['classes'][] = $c;
    $apiGroups[$service]['methods'] += count(array_filter($c['members'], fn($m) => $m['kind'] === 'method'));
}
ksort($apiGroups);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>API Endpoints — Code Documentation</title>
<link rel="stylesheet" href="style.css">
<style>
.live-search { position: relative; }
.live-search-results {
  position: absolute; top: 100%; left: 0; right: 0;
  background: #fff; border: 1px solid #e2e8f0; border-radius: 0 0 8px 8px;
  max-height: 320px; overflow-y: auto; display: none; z-index: 300;
  box-shadow: 0 8px 24px rgba(0,0,0,0.12);
}
.live-search-results.open { display: block; }
.live-search-item {
  display: block; padding: 0.5rem 0.75rem; border-bottom: 1px solid #f1f5f9;
  text-decoration: none; color: #1f2937; font-size: 0.85rem;
}
.live-search-item:hover { background: #f1f5f9; }
.live-search-item .kind-badge { font-size: 0.65rem; }
.live-search-item .name { font-weight: 600; }
.live-search-item .parent-name { color: #94a3b8; font-size: 0.78rem; }
.live-search-loading { padding: 0.75rem; text-align: center; color: #94a3b8; font-size: 0.85rem; }
</style>
</head>
<body>
<button class="sidebar-toggle" onclick="document.querySelector('.sidebar').classList.toggle('open')">☰</button>
<div class="sidebar">
  <div class="sidebar-header">
    <h2><a href="index.php">Code Wiki</a></h2>
    <small>Code Documentation</small>
  </div>
  <div class="search-box live-search">
    <input type="text" id="search-input" placeholder="Search classes, methods..." autocomplete="off">
    <div class="live-search-results" id="search-results"></div>
  </div>
  <div class="sidebar-section">Navigation</div>
  <nav style="padding:0 0 0.5rem 1.5rem;">
    <a href="api.php" style="display:block;padding:0.2rem 0;font-size:0.85rem;font-weight:700;color:#38bdf8;">⚡ API</a>
    <a href="stats.php" style="display:block;padding:0.2rem 0;font-size:0.85rem;">📊 Statistics</a>
    <a href="check.php" style="display:block;padding:0.2rem 0;font-size:0.85rem;">🔍 Check</a>
      <div style="padding:0.25rem 1.5rem;border-top:1px solid #1e293b;margin-top:0.5rem;padding-top:0.75rem;">
        <button onclick="toggleDark()" id="dark-toggle" style="width:100%;padding:0.4rem 0.75rem;border:1px solid #334155;border-radius:6px;background:#1e293b;color:#e2e8f0;cursor:pointer;font-size:0.8rem;">🌙 Dark Mode</button>
      </div>
  </nav>
  <div class="sidebar-section">Projects</div>
  <nav>
    <?php foreach ($projects as $proj => $info): ?>
    <div class="project-group">
      <div class="project-header" onclick="this.classList.toggle('open');this.nextElementSibling.classList.toggle('open')">
        <span class="arrow">▶</span>
        <?= e($proj) ?>
        <span class="proj-count"><?= count($info['namespaces']) ?></span>
      </div>
      <div class="project-namespaces">
        <?php foreach ($info['namespaces'] as $nsName): ?>
        <a href="index.php#ns-<?= urlencode($nsName) ?>"><?= e($nsName) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </nav>
</div>

<div class="main">
  <h1>⚡ API Endpoints</h1>
  <p class="subtitle">All API controllers grouped by service (<?= array_sum(array_map(fn($g) => count($g['classes']), $apiGroups)) ?> classes, <?= array_sum(array_map(fn($g) => $g['methods'], $apiGroups)) ?> methods)</p>

  <?php if (empty($apiGroups)): ?>
  <div class="empty-state"><p>No API classes found.</p></div>
  <?php else: ?>
    <?php foreach ($apiGroups as $service => $info): ?>
    <h2><?= e($service) ?></h2>
    <p style="font-size:0.8rem;color:#94a3b8;margin-top:-0.5rem;"><?= count($info['classes']) ?> classes, <?= $info['methods'] ?> methods</p>
    <table>
      <tr><th>Class</th><th>Namespace</th><th>Endpoints</th></tr>
      <?php
      usort($info['classes'], fn($a, $b) => strcmp($a['name'], $b['name']));
      foreach ($info['classes'] as $c):
          $endpoints = array_filter($c['members'], fn($m) => $m['kind'] === 'method');
      ?>
      <tr>
        <td>
          <a href="class.php?c=<?= urlencode($c['namespace'] . '\\' . $c['name']) ?>"><code><?= e($c['name']) ?></code></a>
          <?php if (!empty($c['doc'])): ?><div class="doc-text" style="font-size:0.75rem;margin-top:0.2rem;"><?= e($c['doc']) ?></div><?php endif; ?>
        </td>
        <td style="font-size:0.8rem;color:#64748b;"><?= e($c['namespace']) ?></td>
        <td style="font-size:0.8rem;">
          <?php foreach (array_slice($endpoints, 0, 5) as $m): ?>
          <div style="margin-bottom:0.25rem;">
            <span class="kind-badge method" style="font-size:0.6rem;"><?= e($m['name']) ?></span>
            <?php if (!empty($m['doc'])): ?><span style="color:#64748b;font-size:0.7rem;"><?= e(substr($m['doc'], 0, 80)) ?><?= strlen($m['doc']) > 80 ? '…' : '' ?></span><?php endif; ?>
          </div>
          <?php endforeach; ?>
          <?php if (count($endpoints) > 5): ?>
          <span style="color:#94a3b8;">+<?= count($endpoints) - 5 ?> more</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endforeach; ?>
  <?php endif; ?>

  <div class="footer">
    <p><a href="index.php">Back to overview</a></p>
  </div>
</div>

<script>
(function() {
  if (localStorage.getItem('darkMode') === 'true') document.body.classList.add('dark-mode');
})();
function toggleDark() {
  document.body.classList.toggle('dark-mode');
  localStorage.setItem('darkMode', document.body.classList.contains('dark-mode') ? 'true' : 'false');
  document.getElementById('dark-toggle').textContent = document.body.classList.contains('dark-mode') ? '☀️ Light Mode' : '🌙 Dark Mode';
}
</script>

<script>
(function() {
  const searchInput = document.getElementById('search-input');
  const resultsDiv = document.getElementById('search-results');
  let timer = null;
  if (!searchInput) return;
  searchInput.addEventListener('input', function() {
    clearTimeout(timer);
    const q = this.value.trim();
    if (q.length < 2) { resultsDiv.classList.remove('open'); resultsDiv.innerHTML = ''; return; }
    resultsDiv.innerHTML = '<div class="live-search-loading">Searching...</div>';
    resultsDiv.classList.add('open');
    timer = setTimeout(() => doSearch(q), 200);
  });
  document.addEventListener('click', function(e) {
    if (!searchInput.contains(e.target) && !resultsDiv.contains(e.target)) { resultsDiv.classList.remove('open'); }
  });
  function doSearch(q) {
    const xhr = new XMLHttpRequest();
    xhr.open('GET', 'search.php?q=' + encodeURIComponent(q) + '&ajax=1', true);
    xhr.onload = function() {
      if (xhr.status !== 200) return;
      try { const data = JSON.parse(xhr.responseText); renderResults(data.results || [], q); } catch(e) { resultsDiv.innerHTML = ''; resultsDiv.classList.remove('open'); }
    };
    xhr.send();
  }
  function renderResults(items) {
    if (!items.length) { resultsDiv.innerHTML = '<div class="live-search-loading">No results</div>'; return; }
    let html = '';
    items.slice(0, 20).forEach(function(r) {
      const url = r.type === 'class' ? 'class.php?c=' + encodeURIComponent(r.key) : 'class.php?c=' + encodeURIComponent(r.parentKey) + '#m-' + encodeURIComponent(r.name);
      html += '<a href="' + url + '" class="live-search-item"><span class="kind-badge ' + r.kind + '">' + r.kind + '</span> <span class="name">' + escapeHtml(r.name) + '</span>' + (r.parent ? ' <span class="parent-name">in ' + escapeHtml(r.parent) + '</span>' : '') + '</a>';
    });
    if (items.length > 20) html += '<a href="search.php?q=' + encodeURIComponent(q) + '" class="live-search-item" style="text-align:center;color:#2563eb;font-weight:600;">View all ' + items.length + ' results →</a>';
    resultsDiv.innerHTML = html;
    resultsDiv.classList.add('open');
  }
  function escapeHtml(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
})();
</script>
</body>
</html>
