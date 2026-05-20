<?php
$docFile = __DIR__ . '/doc.json';
if (!file_exists($docFile)) {
    header('Location: generate.php');
    exit;
}
$data = json_decode(file_get_contents($docFile), true);

function e($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

$s = $data['stats'];
$root = $s['sourceRoot'] ?? (dirname(__DIR__) . DIRECTORY_SEPARATOR . 'code');

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

$projectStats = [];
foreach ($data['classes'] as $c) {
    $proj = explode('\\', $c['file'])[0];
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
ksort($projects);

$totalMethods = 0;
foreach ($projectStats as $ps) { $totalMethods += $ps['methods'] ?? 0; }

$typeSizes = [];
foreach ($data['classes'] as $key => $c) {
    $typeSizes[$key] = ['count' => count($c['members']), 'name' => $c['name'], 'kind' => $c['kind']];
}
usort($typeSizes, fn($a, $b) => $b['count'] - $a['count']);
$topTypes = array_slice($typeSizes, 0, 10);

$projectFramework = [];
if (is_dir($root)) {
    $iterCsproj = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($iterCsproj as $file) {
        if ($file->getExtension() !== 'csproj') continue;
        $content = file_get_contents($file->getPathname());
        $relDir = str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPath());
        $projKey = explode(DIRECTORY_SEPARATOR, $relDir)[0];

        $tf = '';
        if (preg_match('/<TargetFramework>([^<]+)<\/TargetFramework>/', $content, $m)) {
            $tf = $m[1];
        } elseif (preg_match('/<TargetFrameworkVersion>([^<]+)<\/TargetFrameworkVersion>/', $content, $m)) {
            $tf = 'net' . ltrim($m[1], 'v');
        }

        $ot = '';
        if (preg_match('/<OutputType>([^<]+)<\/OutputType>/', $content, $m)) {
            $otLabel = $m[1];
            $ot = $otLabel === 'Exe' ? 'Console' : ($otLabel === 'WinExe' ? 'WinApp' : ($otLabel === 'Library' ? 'Library' : $otLabel));
        }

        $isWeb = strpos($content, 'Microsoft.NET.Sdk.Web') !== false;
        $isWpf = strpos($content, 'UseWPF') !== false || strpos($content, 'WindowsDesktop') !== false;
        $isWinForms = strpos($content, 'UseWindowsForms') !== false;
        $type = $isWeb ? '🌐 Web' : ($isWpf ? '🖥 WPF' : ($isWinForms ? '🪟 WinForms' : ($ot === 'Library' ? '📚 Library' : ($ot ? "⚙ $ot" : '📚 Library'))));

        $projectFramework[$projKey] = ['framework' => $tf ?: '—', 'type' => $type];
    }
}

// Comment coverage
$commentedTypes = 0; $commentedMembers = 0; $totalTypesCheck = 0; $totalMembersCheck = 0;
foreach ($data['classes'] as $c) {
    $totalTypesCheck++;
    if (!empty($c['doc'])) $commentedTypes++;
    foreach ($c['members'] as $m) {
        $totalMembersCheck++;
        if (!empty($m['doc'])) $commentedMembers++;
    }
}

// Access level breakdown
$accessLevels = ['public' => 0, 'private' => 0, 'internal' => 0, 'protected' => 0];
foreach ($data['classes'] as $c) {
    $a = $c['access'] ?? 'public';
    $accessLevels[$a] = ($accessLevels[$a] ?? 0) + 1;
    foreach ($c['members'] as $m) {
        $a = $m['access'] ?? 'public';
        $accessLevels[$a] = ($accessLevels[$a] ?? 0) + 1;
    }
}

// Namespace statistics
$nsStats = [];
foreach ($data['classes'] as $key => $c) {
    $ns = $c['namespace'];
    if (!isset($nsStats[$ns])) $nsStats[$ns] = ['types' => 0, 'members' => 0];
    $nsStats[$ns]['types']++;
    $nsStats[$ns]['members'] += count($c['members']);
}
uksort($nsStats, 'strnatcasecmp');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Statistics — Code Documentation</title>
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
.stats-grid {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 1rem; margin: 1.5rem 0;
}
.stat-card {
  background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
  padding: 1.25rem; text-align: center;
}
.stat-card:hover { border-color: #93c5fd; }
.stat-card .stat-value {
  font-size: 2rem; font-weight: 800; color: #0f172a;
}
.stat-card .stat-label {
  font-size: 0.78rem; color: #64748b; text-transform: uppercase;
  letter-spacing: 0.05em; margin-top: 0.25rem;
}
.stat-card .stat-sub {
  font-size: 0.78rem; color: #94a3b8; margin-top: 0.15rem;
}
.stat-bar {
  height: 8px; border-radius: 4px; background: #e2e8f0; margin: 1rem 0; overflow: hidden;
}
.stat-bar-fill { height: 100%; border-radius: 4px; }
.top-types-list {
  list-style: none; padding: 0;
}
.top-types-list li {
  display: flex; align-items: center; gap: 0.75rem;
  padding: 0.6rem 0.75rem; border-bottom: 1px solid #f1f5f9;
}
.top-types-list li:last-child { border-bottom: none; }
.top-types-list .rank {
  font-size: 0.8rem; font-weight: 700; color: #94a3b8; width: 24px;
}
.top-types-list .type-name {
  flex: 1; font-family: "SF Mono", "Fira Code", monospace; font-size: 0.85rem;
}
.top-types-list .member-count {
  font-size: 0.85rem; font-weight: 600; color: #0f172a;
}
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
  <nav>
    <div style="padding:0.25rem 1.5rem">
      <a href="api.php" style="display:block;padding:0.2rem 0;font-size:0.85rem;">⚡ API</a>
      <a href="stats.php" style="display:block;padding:0.2rem 0;font-size:0.85rem;font-weight:700;color:#38bdf8;">📊 Statistics</a>
      <a href="check.php" style="display:block;padding:0.2rem 0;font-size:0.85rem;">🔍 Check</a>
    </div>
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
  <h1>📊 Statistics</h1>
  <p class="subtitle">Overview of the entire codebase</p>

  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-value"><?= number_format($s['totalFiles']) ?></div>
      <div class="stat-label">Total Files</div>
      <div class="stat-sub"><?= number_format($s['csFiles'] ?? $s['totalFiles']) ?> .cs + <?= number_format($s['xamlFiles'] ?? 0) ?> .xaml</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= number_format($s['totalLines']) ?></div>
      <div class="stat-label">Lines of Code</div>
      <div class="stat-sub"><?= number_format($s['csLines'] ?? $s['totalLines']) ?> .cs + <?= number_format($s['xamlLines'] ?? 0) ?> .xaml</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= number_format($s['totalBytes']) ?></div>
      <div class="stat-label">File Size</div>
      <div class="stat-sub"><?= round(($s['csBytes'] ?? $s['totalBytes'])/1024/1024, 1) ?> MB .cs + <?= round(($s['xamlBytes'] ?? 0)/1024/1024, 2) ?> MB .xaml</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= $s['totalTypes'] ?></div>
      <div class="stat-label">Types</div>
      <div class="stat-sub">in <?= $s['namespaceCount'] ?> namespaces</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= number_format($s['totalMembers']) ?></div>
      <div class="stat-label">Members</div>
      <div class="stat-sub">methods, fields, properties</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= number_format($totalMethods) ?></div>
      <div class="stat-label">Methods</div>
      <div class="stat-sub">~<?= round($totalMethods / max($s['totalTypes'], 1)) ?> per type</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= number_format(round($s['totalMembers'] / max($s['totalTypes'], 1), 1)) ?></div>
      <div class="stat-label">Avg Members / Type</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= number_format(round($s['totalLines'] / max($s['totalFiles'], 1))) ?></div>
      <div class="stat-label">Avg Lines / File</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= $s['projectCount'] ?></div>
      <div class="stat-label">Projects</div>
      <div class="stat-sub"><?= $s['totalFiles'] ?> source files</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= $totalTypesCheck ? round($commentedTypes / $totalTypesCheck * 100) : 0 ?>%</div>
      <div class="stat-label">Types with Docs</div>
      <div class="stat-sub"><?= $commentedTypes ?> / <?= $totalTypesCheck ?> types</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= $totalMembersCheck ? round($commentedMembers / $totalMembersCheck * 100) : 0 ?>%</div>
      <div class="stat-label">Members with Docs</div>
      <div class="stat-sub"><?= $commentedMembers ?> / <?= $totalMembersCheck ?> members</div>
    </div>
  </div>

  <h2>Types</h2>
  <?php
  $typeColors = ['class' => '#1d4ed8', 'struct' => '#b45309', 'interface' => '#6d28d9', 'enum' => '#047857'];
  $maxType = max($s['typeCounts']) ?: 1;
  ?>
  <?php foreach ($s['typeCounts'] as $kind => $count): ?>
  <div style="display:flex;align-items:center;gap:0.75rem;margin:0.5rem 0;">
    <span style="width:80px;font-size:0.85rem;font-weight:600;"><?= ucfirst($kind) ?></span>
    <div class="stat-bar" style="flex:1;">
      <div class="stat-bar-fill" style="width:<?= round($count/$maxType*100) ?>%;background:<?= $typeColors[$kind] ?>;"></div>
    </div>
    <span style="width:60px;text-align:right;font-size:0.85rem;"><?= $count ?></span>
  </div>
  <?php endforeach; ?>

  <h2>Members</h2>
  <?php
  $memColors = ['method' => '#be185d', 'property' => '#4338ca', 'field' => '#1d4ed8', 'constructor' => '#374151', 'destructor' => '#94a3b8'];
  $maxMem = max($s['memberCounts']) ?: 1;
  ?>
  <?php foreach ($s['memberCounts'] as $kind => $count): ?>
  <div style="display:flex;align-items:center;gap:0.75rem;margin:0.5rem 0;">
    <span style="width:100px;font-size:0.85rem;font-weight:600;"><?= $kind === 'property' ? 'Properties' : ucfirst($kind) . 's' ?></span>
    <div class="stat-bar" style="flex:1;">
      <div class="stat-bar-fill" style="width:<?= round($count/$maxMem*100) ?>%;background:<?= $memColors[$kind] ?>;"></div>
    </div>
    <span style="width:60px;text-align:right;font-size:0.85rem;"><?= $count ?></span>
  </div>
  <?php endforeach; ?>

  <h2>Access Levels</h2>
  <?php
  $accessColors = ['public' => '#2563eb', 'private' => '#ef4444', 'internal' => '#8b5cf6', 'protected' => '#f59e0b'];
  $maxAccess = max($accessLevels) ?: 1;
  ?>
  <?php foreach (['public', 'private', 'internal', 'protected'] as $al): ?>
  <?php if (($accessLevels[$al] ?? 0) === 0) continue; ?>
  <div style="display:flex;align-items:center;gap:0.75rem;margin:0.5rem 0;">
    <span style="width:80px;font-size:0.85rem;font-weight:600;text-transform:capitalize;"><?= $al ?></span>
    <div class="stat-bar" style="flex:1;">
      <div class="stat-bar-fill" style="width:<?= round(($accessLevels[$al] ?? 0) / $maxAccess * 100) ?>%;background:<?= $accessColors[$al] ?>;"></div>
    </div>
    <span style="width:60px;text-align:right;font-size:0.85rem;"><?= $accessLevels[$al] ?? 0 ?></span>
  </div>
  <?php endforeach; ?>

  <h2>Top 10 Largest Types</h2>
  <div class="stat-card" style="text-align:left;padding:0;">
    <ol class="top-types-list">
      <?php $rankColors = ['#f59e0b', '#94a3b8', '#b45309', '#64748b', '#64748b', '#64748b', '#64748b', '#64748b', '#64748b', '#64748b']; ?>
      <?php foreach ($topTypes as $i => $tt): ?>
      <li>
        <span class="rank" style="color:<?= $rankColors[$i] ?? '#94a3b8' ?>;">#<?= $i + 1 ?></span>
        <span class="kind-badge <?= $tt['kind'] ?>"><?= $tt['kind'] ?></span>
        <span class="type-name"><?= e($tt['name']) ?></span>
        <span class="member-count"><?= $tt['count'] ?> members</span>
      </li>
      <?php endforeach; ?>
    </ol>
  </div>

  <h2>Per Project</h2>
  <table>
    <tr><th>Project</th><th>Files</th><th>Types</th><th>Methods</th><th>Fields</th><th>Properties</th><th>Constructors</th><th>Framework</th><th>Type</th><th>Size</th></tr>
    <?php foreach ($projectStats as $proj => $ps): ?>
    <?php $bytes = $s['projectBytes'][$proj] ?? 0; ?>
    <?php $fw = $projectFramework[$proj] ?? null; ?>
    <tr>
      <td><strong><?= e($proj) ?></strong></td>
      <td><?= $ps['files'] ?></td>
      <td><?= $ps['types'] ?></td>
      <td><?= $ps['methods'] ?? 0 ?></td>
      <td><?= $ps['fields'] ?? 0 ?></td>
      <td><?= $ps['properties'] ?? 0 ?></td>
      <td><?= $ps['constructors'] ?? 0 ?></td>
      <td style="font-size:0.8rem;font-family:monospace;"><?= $fw ? e($fw['framework']) : '—' ?></td>
      <td style="font-size:0.8rem;"><?= $fw ? $fw['type'] : '—' ?></td>
      <td style="font-size:0.8rem;color:#64748b;"><?= number_format($bytes) ?> B<?= $bytes > 10240 ? '<br><span style="font-size:0.7rem;">' . round($bytes/1024/1024, 2) . ' MB</span>' : '' ?></td>
    </tr>
    <?php endforeach; ?>
  </table>

  <h2>Per Namespace</h2>
  <table>
    <tr><th>Namespace</th><th>Types</th><th>Members</th></tr>
    <?php foreach ($nsStats as $ns => $nsd): ?>
    <tr>
      <td><a href="index.php#ns-<?= urlencode($ns) ?>"><?= e($ns) ?></a></td>
      <td><?= $nsd['types'] ?></td>
      <td><?= $nsd['members'] ?></td>
    </tr>
    <?php endforeach; ?>
  </table>

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
