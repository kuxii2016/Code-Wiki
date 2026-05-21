<?php
$docFile = __DIR__ . '/doc.json';
if (!file_exists($docFile)) {
    header('Location: generate.php');
    exit;
}
$data = json_decode(file_get_contents($docFile), true);

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

$query = trim($_GET['q'] ?? '');
$isAjax = isset($_GET['ajax']);
$results = [];
$maxResults = 100;

function e($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function highlight($text, $query) {
    $words = preg_split('/\s+/', $query);
    $result = e($text);
    foreach ($words as $w) {
        if (strlen($w) < 2) continue;
        $result = preg_replace('/(' . preg_quote(e($w), '/') . ')/iu', '<span class="highlight">$1</span>', $result);
    }
    return $result;
}

if ($query && strlen($query) >= 2) {
    $queryLower = strtolower($query);
    $words = preg_split('/\s+/', $queryLower);

    foreach ($data['classes'] as $ckey => $c) {
        $score = 0;
        $nameLower = strtolower($c['name']);
        $docLower = strtolower($c['doc'] ?? '');
        $nsLower = strtolower($c['namespace']);

        foreach ($words as $w) {
            if (strlen($w) < 2) continue;
            if (strpos($nameLower, $w) !== false) $score += 10;
            if (strpos($nsLower, $w) !== false) $score += 3;
            if (strpos($docLower, $w) !== false) $score += 2;
        }

        if ($score > 0) {
            $results[] = [
                'type' => 'class',
                'kind' => $c['kind'],
                'name' => $c['name'],
                'namespace' => $c['namespace'],
                'key' => $ckey,
                'doc' => $c['doc'] ?? '',
                'score' => $score,
            ];
        }

        foreach ($c['members'] as $m) {
            $scoreM = 0;
            $mNameLower = strtolower($m['name']);
            foreach ($words as $w) {
                if (strlen($w) < 2) continue;
                if (strpos($mNameLower, $w) !== false) $scoreM += 8;
                if (strpos(strtolower($m['doc'] ?? ''), $w) !== false) $scoreM += 2;
                if (strpos($nameLower, $w) !== false) $scoreM += 1;
            }
            if ($scoreM > 0) {
                $results[] = [
                    'type' => 'member',
                    'kind' => $m['kind'],
                    'name' => $m['name'],
                    'parent' => $c['name'],
                    'parentKey' => $ckey,
                    'namespace' => $c['namespace'],
                    'doc' => $m['doc'] ?? '',
                    'score' => $scoreM,
                ];
            }
        }
    }

    usort($results, fn($a, $b) => $b['score'] - $a['score']);
    $results = array_slice($results, 0, $maxResults);
}

// JSON output for AJAX
if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode(['total' => count($results), 'results' => $results], JSON_UNESCAPED_SLASHES);
    exit;
}

$total = count($results);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Search: <?= e($query) ?> — Code Documentation</title>
<link rel="stylesheet" href="style.css">

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
    <input type="text" id="search-input" placeholder="Search classes, methods..." value="<?= e($query) ?>" autocomplete="off">
    <div class="live-search-results" id="search-results"></div>
  </div>
  <div class="sidebar-section">Navigation</div>
  <nav style="padding:0 0 0.5rem 1.5rem;">
    <a href="api.php" style="display:block;padding:0.2rem 0;font-size:0.85rem;font-weight:600;color:#38bdf8;">⚡ API</a>
    <a href="projects.php" style="display:block;padding:0.2rem 0;font-size:0.85rem;">📁 Projects</a>
    <a href="stats.php" style="display:block;padding:0.2rem 0;font-size:0.85rem;">📊 Statistics</a>
    <a href="check.php" style="display:block;padding:0.2rem 0;font-size:0.85rem;">🔍 Check</a>
      <div style="padding:0.25rem 1.5rem;border-top:1px solid #1e293b;margin-top:0.5rem;padding-top:0.75rem;">
        <button onclick="toggleDark()" id="dark-toggle" style="width:100%;padding:0.4rem 0.75rem;border:1px solid #334155;border-radius:6px;background:#1e293b;color:#e2e8f0;cursor:pointer;font-size:0.8rem;">🌙 Dark Mode</button>
      </div>
  </nav>
  <?php include 'sidebar.php'; ?>
</div>

<div class="main">
  <h1>Search</h1>

  <form action="search.php" method="get" style="margin-bottom:1.5rem;">
    <input type="text" name="q" id="main-search" value="<?= e($query) ?>" placeholder="Search..." class="main-search-input">
  </form>

  <?php if ($query): ?>
  <p class="subtitle"><?= $total ?> result<?= $total !== 1 ? 's' : '' ?> for "<?= e($query) ?>"</p>
  <?php endif; ?>

  <?php if (empty($query)): ?>
  <div class="empty-state"><h3>Enter a search query</h3><p>Search for class names, method names, properties, or documentation text.</p></div>
  <?php elseif (strlen($query) < 2): ?>
  <div class="empty-state"><h3>Query too short</h3><p>Please enter at least 2 characters.</p></div>
  <?php elseif (empty($results)): ?>
  <div class="empty-state"><h3>No results</h3><p>Try different search terms.</p></div>
  <?php else: ?>
    <?php foreach ($results as $r): ?>
    <div class="search-result">
      <div class="sr-title">
        <span class="kind-badge <?= $r['kind'] ?>"><?= $r['kind'] ?></span>
        <?php if ($r['type'] === 'class'): ?>
        <a href="class.php?c=<?= urlencode($r['key']) ?>"><?= highlight($r['name'], $query) ?></a>
        <?php else: ?>
        <a href="class.php?c=<?= urlencode($r['parentKey']) ?>#m-<?= urlencode($r['name']) ?>"><?= highlight($r['name'], $query) ?></a>
        <span style="color:#94a3b8;font-size:0.85rem;">in <a href="class.php?c=<?= urlencode($r['parentKey']) ?>"><?= e($r['parent']) ?></a></span>
        <?php endif; ?>
      </div>
      <?php if ($r['doc']): ?><div class="sr-doc"><?= highlight(substr($r['doc'], 0, 200), $query) ?></div><?php endif; ?>
      <div class="sr-location"><?= e($r['namespace']) ?></div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <div class="footer">
    <p><a href="index.php">Back to overview</a> &mdash; Generated from <?= $data['totalFiles'] ?> source files</p>
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
    if (!searchInput.contains(e.target) && !resultsDiv.contains(e.target)) {
      resultsDiv.classList.remove('open');
    }
  });

  function doSearch(q) {
    const xhr = new XMLHttpRequest();
    xhr.open('GET', 'search.php?q=' + encodeURIComponent(q) + '&ajax=1', true);
    xhr.onload = function() {
      if (xhr.status !== 200) return;
      try {
        const data = JSON.parse(xhr.responseText);
        renderResults(data.results || [], q);
      } catch(e) { resultsDiv.innerHTML = ''; resultsDiv.classList.remove('open'); }
    };
    xhr.send();
  }

  function renderResults(items, q) {
    if (!items.length) {
      resultsDiv.innerHTML = '<div class="live-search-loading">No results</div>';
      return;
    }
    let html = '';
    const maxShow = 20;
    const show = items.slice(0, maxShow);
    show.forEach(function(r) {
      const url = r.type === 'class'
        ? 'class.php?c=' + encodeURIComponent(r.key)
        : 'class.php?c=' + encodeURIComponent(r.parentKey) + '#m-' + encodeURIComponent(r.name);
      html += '<a href="' + url + '" class="live-search-item">'
        + '<span class="kind-badge ' + r.kind + '">' + r.kind + '</span> '
        + '<span class="name">' + escapeHtml(r.name) + '</span>'
        + (r.parent ? ' <span class="parent-name">in ' + escapeHtml(r.parent) + '</span>' : '')
        + '</a>';
    });
    if (items.length > maxShow) {
      html += '<a href="search.php?q=' + encodeURIComponent(q) + '" class="live-search-item" style="text-align:center;color:#2563eb;font-weight:600;">View all ' + items.length + ' results →</a>';
    }
    resultsDiv.innerHTML = html;
    resultsDiv.classList.add('open');
  }

  function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }
})();
</script>
</body>
</html>
