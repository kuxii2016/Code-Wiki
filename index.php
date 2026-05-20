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
    $ns = $c['namespace'];
    if (!in_array($ns, $projects[$proj]['namespaces'])) $projects[$proj]['namespaces'][] = $ns;
}
ksort($projects);
foreach ($projects as &$p) { sort($p['namespaces']); }
unset($p);

$grouped = [];
foreach ($data['namespaces'] as $ns => $classes) {
    $parts = explode('.', $ns);
    $group = $parts[0];
    if (!isset($grouped[$group])) $grouped[$group] = [];
    $grouped[$group][$ns] = $classes;
}
ksort($grouped);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Code Documentation — Code Wiki</title>
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
    <a href="api.php" style="display:block;padding:0.2rem 0;font-size:0.85rem;font-weight:600;color:#38bdf8;">⚡ API</a>
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
        <?php foreach ($info['namespaces'] as $ns): ?>
        <a href="#ns-<?= urlencode($ns) ?>"><?= e($ns) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </nav>
</div>

<div class="main">
  <h1>Code Documentation</h1>
  <p class="subtitle">All classes, interfaces, enums, and structs across <?= count($data['namespaces']) ?> namespaces from <?= $data['totalFiles'] ?> source files.</p>

  <form action="search.php" method="get" style="margin-bottom: 2rem;">
    <input type="text" name="q" placeholder="Search classes, methods, properties..." style="width:100%;padding:0.75rem 1rem;border:1px solid #e2e8f0;border-radius:8px;font-size:1rem;outline:0;background:#fff;" onfocus="this.style.borderColor='#38bdf8'" onblur="this.style.borderColor='#e2e8f0'">
  </form>

  <div id="filter-bar" style="display:none;margin-bottom:1rem;">
    <button onclick="showAllNamespaces()" style="padding:0.4rem 1rem;border:1px solid #e2e8f0;border-radius:6px;background:#fff;cursor:pointer;font-size:0.85rem;">← Show all namespaces</button>
    <span id="filter-label" style="margin-left:0.75rem;font-size:0.85rem;color:#64748b;"></span>
  </div>
  <div id="ns-groups">
  <?php foreach ($grouped as $groupName => $namespaces): ?>
    <?php ksort($namespaces); ?>
    <div class="ns-group" data-group="<?= e($groupName) ?>">
    <h2><?= e($groupName) ?></h2>
    <?php foreach ($namespaces as $ns => $classes): ?>
    <div class="ns-section" data-ns="<?= e($ns) ?>">
    <h3 id="ns-<?= urlencode($ns) ?>"><?= e($ns) ?></h3>
    <table>
      <tr><th>Type</th><th>Name</th><th>Description</th></tr>
      <?php foreach ($classes as $ckey): ?>
      <?php $c = $data['classes'][$ckey]; ?>
      <tr>
        <td><span class="kind-badge <?= $c['kind'] ?>"><?= $c['kind'] ?></span></td>
        <td><a href="class.php?c=<?= urlencode($ckey) ?>"><code><?= e($c['name']) ?></code></a></td>
        <td><?= e(substr($c['doc'] ?? '', 0, 120)) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php
    // Show XAML files belonging to this namespace
    $nsXaml = array_filter($data['xamlFiles'] ?? [], fn($x) => $x['namespace'] === $ns);
    if ($nsXaml): ?>
    <table style="margin-top:0.5rem;">
      <tr><th>Form</th><th>File</th><th>Class</th></tr>
      <?php foreach ($nsXaml as $x): ?>
      <tr>
        <td><span class="kind-badge"><?= e($x['kind']) ?></span></td>
        <td style="font-size:0.8rem;color:#64748b;"><code><?= e($x['file']) ?></code></td>
        <td><?php if ($x['classKey'] && isset($data['classes'][$x['classKey']])): ?><a href="class.php?c=<?= urlencode($x['classKey']) ?>"><code><?= e($x['className']) ?></code></a><?php else: ?><span style="color:#94a3b8;"><?= e($x['className']) ?: '—' ?></span><?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
  </div>

  <div class="footer">
    <p>Generated from <?= $data['totalFiles'] ?> .cs files &mdash; <?= count($data['classes']) ?> documented types</p>
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
  // Live search
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

  // Namespace filter
  function filterNamespace(ns) {
    document.querySelectorAll('.ns-section').forEach(function(s) {
      s.style.display = s.getAttribute('data-ns') === ns ? '' : 'none';
    });
    document.querySelectorAll('.ns-group').forEach(function(g) {
      var hasVisible = Array.from(g.querySelectorAll('.ns-section')).some(function(s) { return s.style.display !== 'none'; });
      g.style.display = hasVisible ? '' : 'none';
    });
    document.getElementById('filter-bar').style.display = '';
    document.getElementById('filter-label').textContent = 'Showing: ' + ns;
    history.replaceState(null, '', '#ns-' + encodeURIComponent(ns));
    document.querySelectorAll('.project-namespaces a').forEach(function(l) { l.classList.remove('active'); });
    document.querySelectorAll('.project-namespaces a[href^="#ns-"]').forEach(function(l) {
      if (decodeURIComponent(l.getAttribute('href').substring(4)) === ns) l.classList.add('active');
    });
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
  window.showAllNamespaces = function() {
    document.querySelectorAll('.ns-section, .ns-group').forEach(function(s) { s.style.display = ''; });
    document.getElementById('filter-bar').style.display = 'none';
    history.replaceState(null, '', window.location.pathname);
    document.querySelectorAll('.project-namespaces a').forEach(function(l) { l.classList.remove('active'); });
  };
  // Intercept sidebar namespace clicks
  document.querySelector('.sidebar').addEventListener('click', function(e) {
    var a = e.target.closest('.project-namespaces a[href^="#ns-"]');
    if (a) {
      e.preventDefault();
      var ns = decodeURIComponent(a.getAttribute('href').substring(4));
      filterNamespace(ns);
    }
  });
  // On hash load, apply filter
  if (window.location.hash && window.location.hash.startsWith('#ns-')) {
    var ns = decodeURIComponent(window.location.hash.substring(4));
    var target = document.querySelector('.ns-section[data-ns="' + ns.replace(/"/g, '\\"') + '"]');
    if (target) filterNamespace(ns);
  }
})();
</script>
</body>
</html>
