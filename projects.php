<?php
$docFile = __DIR__ . '/doc.json';
if (!file_exists($docFile)) {
    header('Location: generate.php');
    exit;
}
$data = json_decode(file_get_contents($docFile), true);

function e($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

$s = $data['stats'];
$projectInfo = $data['projectInfo'] ?? [];

$projects = [];
foreach ($data['classes'] as $key => $c) {
    $parts = explode('/', str_replace('\\', '/', $c['file']));
    $proj = $parts[0];
    if (!isset($projects[$proj])) $projects[$proj] = ['namespaces' => [], 'types' => 0];
    $projects[$proj]['types']++;
    $ns = $c['namespace'];
    if (!in_array($ns, $projects[$proj]['namespaces'])) $projects[$proj]['namespaces'][] = $ns;
}
ksort($projects);
foreach ($projects as &$p) { sort($p['namespaces']); }
unset($p);

$projectStats = [];
foreach ($data['classes'] as $c) {
    $proj = explode('/', str_replace('\\', '/', $c['file']))[0];
    if (!isset($projectStats[$proj])) $projectStats[$proj] = ['types' => 0, 'fields' => 0, 'methods' => 0, 'properties' => 0, 'constructors' => 0, 'destructors' => 0, 'files' => [], 'lines' => 0];
    $projectStats[$proj]['types']++;
    $projectStats[$proj]['files'][$c['file']] = true;
    foreach ($c['members'] as $m) {
        $key = $m['kind'] === 'property' ? 'properties' : $m['kind'] . 's';
        if (isset($projectStats[$proj][$key])) $projectStats[$proj][$key]++;
    }
}
foreach ($projectStats as &$ps) {
    $ps['files'] = count($ps['files']);
}
unset($ps);
ksort($projectStats);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Projects — Code Documentation</title>
<link rel="stylesheet" href="style.css">
<style>
.project-card {
  background: var(--card-bg); border: 1px solid var(--border); border-radius: 10px;
  padding: 1.25rem; margin-bottom: 1rem;
}
.project-card:hover { border-color: var(--hover-border); }
.project-card h2 { font-size: 1.1rem; margin: 0 0 0.25rem; }
.project-card .meta { font-size: 0.8rem; color: var(--secondary); margin-bottom: 0.75rem; }
.project-card .meta span { margin-right: 1rem; }
.project-stats { display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 0.75rem; }
.project-stats .stat { text-align: center; min-width: 50px; }
.project-stats .stat .num { font-size: 1.1rem; font-weight: 700; color: var(--heading); }
.project-stats .stat .label { font-size: 0.65rem; color: var(--secondary); text-transform: uppercase; }
.package-list { display: flex; flex-wrap: wrap; gap: 0.3rem; }
.package-tag {
  display: inline-block; padding: 0.15rem 0.5rem; border-radius: 4px;
  background: var(--code-bg); border: 1px solid var(--border);
  font-size: 0.72rem; font-family: monospace; color: var(--text);
}
.output-badge {
  display: inline-block; padding: 0.1rem 0.4rem; border-radius: 3px;
  font-size: 0.6rem; font-weight: 700; text-transform: uppercase;
  background: var(--code-bg); border: 1px solid var(--border); color: var(--secondary);
  vertical-align: middle;
}
</style>
</head>
<body>
<script>if(localStorage.getItem('darkMode')==='true')document.body.classList.add('dark-mode');</script>
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
    <a href="index.php" style="display:block;padding:0.2rem 0;font-size:0.85rem;">← Overview</a>
    <a href="api.php" style="display:block;padding:0.2rem 0;font-size:0.85rem;font-weight:600;color:#38bdf8;">⚡ API</a>
    <a href="projects.php" style="display:block;padding:0.2rem 0;font-size:0.85rem;font-weight:600;color:#38bdf8;">📁 Projects</a>
    <a href="stats.php" style="display:block;padding:0.2rem 0;font-size:0.85rem;">📊 Statistics</a>
    <a href="check.php" style="display:block;padding:0.2rem 0;font-size:0.85rem;">🔍 Check</a>
      <div style="padding:0.25rem 1.5rem;border-top:1px solid #1e293b;margin-top:0.5rem;padding-top:0.75rem;">
        <button onclick="toggleDark()" id="dark-toggle" style="width:100%;padding:0.4rem 0.75rem;border:1px solid #334155;border-radius:6px;background:#1e293b;color:#e2e8f0;cursor:pointer;font-size:0.8rem;">🌙 Dark Mode</button>
      </div>
  </nav>
  <?php include 'sidebar.php'; ?>
</div>

<div class="main">
  <h1>Projects</h1>
  <p class="subtitle"><?= count($projectStats) ?> projects across <?= $s['namespaceCount'] ?> namespaces from <?= $s['totalFiles'] ?> source files.</p>

  <?php foreach ($projectStats as $proj => $ps): ?>
  <?php
    $pi = $projectInfo[$proj] ?? null;
    $outType = '—';
    $isDll = false; $isExe = false;
    if ($pi) {
        if ($pi['isWeb']) $outType = '🌐 Web';
        elseif ($pi['isWpf']) $outType = '🖥 WPF';
        elseif ($pi['isWinForms']) $outType = '🪟 WinForms';
        elseif ($pi['outputType'] === 'Library' || (!$pi['outputType'])) $outType = '📚 Class Library';
        elseif ($pi['outputType'] === 'Console') $outType = '⚙ Console';
        elseif ($pi['outputType'] === 'WinApp') $outType = '⚙ WinApp';
        else $outType = '⚙ ' . $pi['outputType'];
        $isDll = !$pi['outputType'] || $pi['outputType'] === 'Library';
        $isExe = $pi['outputType'] === 'Console' || $pi['outputType'] === 'WinApp';
    }
    $bytes = $s['projectBytes'][$proj] ?? 0;
    $size = $bytes > 1048576 ? round($bytes / 1048576, 2) . ' MB' : ($bytes > 1024 ? round($bytes / 1024) . ' KB' : $bytes . ' B');
  ?>
  <div class="project-card">
    <h2><?= e($proj) ?></h2>
    <div class="meta">
      <?php if ($pi): ?><span>📦 <?= e($pi['framework']) ?></span><?php endif; ?>
      <span><?= $outType ?></span><?php if ($pi && $isDll): ?> <span class="output-badge">DLL</span><?php elseif ($pi && $isExe): ?> <span class="output-badge">EXE</span><?php endif; ?>
      <span>💾 <?= $size ?></span>
      <span>📄 <?= $ps['files'] ?> files</span>
    </div>
    <div class="project-stats">
      <div class="stat"><div class="num"><?= $ps['types'] ?></div><div class="label">Types</div></div>
      <div class="stat"><div class="num"><?= $ps['methods'] ?? 0 ?></div><div class="label">Methods</div></div>
      <div class="stat"><div class="num"><?= $ps['properties'] ?? 0 ?></div><div class="label">Properties</div></div>
      <div class="stat"><div class="num"><?= $ps['fields'] ?? 0 ?></div><div class="label">Fields</div></div>
      <div class="stat"><div class="num"><?= $ps['constructors'] ?? 0 ?></div><div class="label">Ctors</div></div>
    </div>
    <?php if ($pi && !empty($pi['packages'])): ?>
    <div style="font-size:0.78rem;color:var(--secondary);margin-bottom:0.3rem;">NuGet Packages:</div>
    <div class="package-list">
      <?php foreach ($pi['packages'] as $pkg): ?>
      <span class="package-tag"><?= e($pkg['name']) ?> <span style="color:var(--secondary);"><?= e($pkg['version']) ?></span></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($pi && !empty($pi['projectRefs'])): ?>
    <div style="font-size:0.78rem;color:var(--secondary);margin-bottom:0.3rem;margin-top:0.5rem;">Project References:</div>
    <div class="package-list">
      <?php foreach ($pi['projectRefs'] as $ref): ?>
      <span class="package-tag"><?= e(preg_replace('/^.*\\\\(.+)\.csproj$/', '$1', $ref)) ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>

  <div class="footer">
    <p><a href="index.php">Back to overview</a> &mdash; Generated <?= date('Y-m-d H:i', strtotime($data['generated'] ?? 'now')) ?></p>
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
  if (searchInput) {
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
  }
  function doSearch(q) {
    const xhr = new XMLHttpRequest();
    xhr.open('GET', 'search.php?q=' + encodeURIComponent(q) + '&ajax=1', true);
    xhr.onload = function() {
      if (xhr.status !== 200) return;
      try { const data = JSON.parse(xhr.responseText); renderResults(data.results || [], q); } catch(e) { resultsDiv.innerHTML = ''; resultsDiv.classList.remove('open'); }
    };
    xhr.send();
  }
  function renderResults(items, q) {
    if (!items.length) { resultsDiv.innerHTML = '<div class="live-search-loading">No results</div>'; return; }
    let html = '';
    const maxShow = 20;
    items.slice(0, maxShow).forEach(function(r) {
      const url = r.type === 'class' ? 'class.php?c=' + encodeURIComponent(r.key) : 'class.php?c=' + encodeURIComponent(r.parentKey) + '#m-' + encodeURIComponent(r.name);
      html += '<a href="' + url + '" class="live-search-item"><span class="kind-badge ' + r.kind + '">' + r.kind + '</span> <span class="name">' + escapeHtml(r.name) + '</span>' + (r.parent ? ' <span class="parent-name">in ' + escapeHtml(r.parent) + '</span>' : '') + '</a>';
    });
    if (items.length > maxShow) { html += '<a href="search.php?q=' + encodeURIComponent(q) + '" class="live-search-item" style="text-align:center;color:#2563eb;font-weight:600;">View all ' + items.length + ' results →</a>'; }
    resultsDiv.innerHTML = html; resultsDiv.classList.add('open');
  }
  function escapeHtml(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
})();
</script>
</body>
</html>
