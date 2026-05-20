<?php
$docFile = __DIR__ . '/doc.json';
if (!file_exists($docFile)) {
    header('Location: generate.php');
    exit;
}
$data = json_decode(file_get_contents($docFile), true);

$shortToFqn = [];
foreach ($data['classes'] as $key => $c) {
    $parts = explode('\\', $key);
    $short = end($parts);
    $lc = strtolower($short);
    if (!isset($shortToFqn[$lc])) $shortToFqn[$lc] = [];
    $shortToFqn[$lc][] = $key;
}

$classKey = $_GET['c'] ?? '';
if (!isset($data['classes'][$classKey])) {
    $lc = strtolower($classKey);
    if (isset($shortToFqn[$lc])) {
        $candidates = $shortToFqn[$lc];
        $classKey = count($candidates) === 1 ? $candidates[0] : $candidates[0];
    }
}

$nullableSuffix = '';
if (substr($classKey, -1) === '?') {
    $nullableSuffix = '?';
    $classKey = substr($classKey, 0, -1);
}

$primitiveInfo = null;
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

$primitiveTypes = [
    'bool'     => ['size' => 1,  'desc' => 'Boolean value (true/false)', 'base' => 'ValueType'],
    'byte'     => ['size' => 1,  'desc' => '8-bit unsigned integer', 'base' => 'ValueType'],
    'sbyte'    => ['size' => 1,  'desc' => '8-bit signed integer', 'base' => 'ValueType'],
    'char'     => ['size' => 2,  'desc' => 'UTF-16 code unit', 'base' => 'ValueType'],
    'short'    => ['size' => 2,  'desc' => '16-bit signed integer', 'base' => 'ValueType'],
    'ushort'   => ['size' => 2,  'desc' => '16-bit unsigned integer', 'base' => 'ValueType'],
    'int'      => ['size' => 4,  'desc' => '32-bit signed integer', 'base' => 'ValueType'],
    'uint'     => ['size' => 4,  'desc' => '32-bit unsigned integer', 'base' => 'ValueType'],
    'float'    => ['size' => 4,  'desc' => '32-bit single-precision floating point', 'base' => 'ValueType'],
    'long'     => ['size' => 8,  'desc' => '64-bit signed integer', 'base' => 'ValueType'],
    'ulong'    => ['size' => 8,  'desc' => '64-bit unsigned integer', 'base' => 'ValueType'],
    'double'   => ['size' => 8,  'desc' => '64-bit double-precision floating point', 'base' => 'ValueType'],
    'decimal'  => ['size' => 16, 'desc' => '128-bit decimal value', 'base' => 'ValueType'],
    'nint'     => ['size' => null, 'desc' => 'Platform-specific signed integer (native int)', 'base' => 'ValueType'],
    'nuint'    => ['size' => null, 'desc' => 'Platform-specific unsigned integer (native uint)', 'base' => 'ValueType'],
    'datetime' => ['size' => 8,  'desc' => 'Date and time (UTC ticks)', 'base' => 'ValueType'],
    'timespan' => ['size' => 8,  'desc' => 'Time interval', 'base' => 'ValueType'],
    'guid'     => ['size' => 16, 'desc' => 'Globally unique identifier', 'base' => 'ValueType'],
    'string'   => ['size' => null, 'desc' => 'Sequence of characters (reference type)', 'base' => 'Object'],
    'object'   => ['size' => null, 'desc' => 'Base type of all .NET types', 'base' => null],
    'void'     => ['size' => 0,  'desc' => 'No return type', 'base' => null],
    'task'     => ['size' => null, 'desc' => 'Asynchronous operation', 'base' => 'Object'],
];
$lcKey = strtolower($classKey);
if (isset($primitiveTypes[$lcKey])) {
    $primitiveInfo = $primitiveTypes[$lcKey];
}

$isArray = false;
if (!$primitiveInfo && !isset($data['classes'][$classKey]) && substr($classKey, -2) === '[]') {
    $baseKey = substr($classKey, 0, -2);
    $lcBase = strtolower($baseKey);
    if (isset($primitiveTypes[$lcBase])) {
        $p = $primitiveTypes[$lcBase];
        $primitiveInfo = ['size' => null, 'desc' => 'Array of ' . $baseKey . ' — ' . $p['desc'], 'base' => 'Array'];
        $isArray = true;
    }
}

$genericInner = null;
$genericWrapper = null;
if (!$primitiveInfo && !isset($data['classes'][$classKey])) {
    if (preg_match('/^(\w+)<(.+)>$/', $classKey, $gMatch)) {
        $genericBase = $gMatch[1];
        $genericArgs = array_map('trim', explode(',', $gMatch[2]));
        $firstArg = $genericArgs[0];
        $lcFirstArg = strtolower($firstArg);
        if (substr($firstArg, -2) === '[]') {
            $baseOfArray = substr($firstArg, 0, -2);
            $lcBaseArr = strtolower($baseOfArray);
            if (isset($primitiveTypes[$lcBaseArr])) {
                $p = $primitiveTypes[$lcBaseArr];
                $primitiveInfo = ['size' => null, 'desc' => $genericBase . '&lt;' . $firstArg . '&gt; — ' . $p['desc'], 'base' => 'Array'];
            }
        }
        if (!$primitiveInfo && isset($shortToFqn[$lcFirstArg])) {
            $classKey = $shortToFqn[$lcFirstArg][0];
        } elseif (!$primitiveInfo && isset($primitiveTypes[$lcFirstArg])) {
            $primitiveInfo = $primitiveTypes[$lcFirstArg];
            $primitiveInfo['desc'] = $genericBase . '&lt;' . $firstArg . '&gt; — ' . $primitiveInfo['desc'];
        } elseif (!$primitiveInfo && isset($builtinTypes[$lcFirstArg])) {
            $primitiveInfo = $builtinTypes[$lcFirstArg];
            $isBuiltin = true;
        }
        if (!$primitiveInfo && !isset($data['classes'][$classKey])) {
            $primitiveInfo = ['size' => null, 'desc' => 'Generic type ' . $genericBase . '&lt;' . implode(', ', $genericArgs) . '&gt;', 'base' => null];
        }
        $lcKey = strtolower($classKey);
    }
}

$builtinTypes = [
    'exception'                => ['desc' => 'Represents errors that occur during application execution', 'base' => 'Object', 'size' => null],
    'systemexception'          => ['desc' => 'Base class for predefined system exceptions', 'base' => 'Exception', 'size' => null],
    'argumentexception'        => ['desc' => 'Exception thrown when an argument is not valid', 'base' => 'SystemException', 'size' => null],
    'argumentnullexception'    => ['desc' => 'Exception thrown when a null argument is passed', 'base' => 'ArgumentException', 'size' => null],
    'argumentoutofrangeexception' => ['desc' => 'Exception thrown when an argument is outside the allowable range', 'base' => 'ArgumentException', 'size' => null],
    'nullreferenceexception'   => ['desc' => 'Exception thrown when dereferencing a null object reference', 'base' => 'SystemException', 'size' => null],
    'invalidoperationexception'=> ['desc' => 'Exception thrown when a method call is invalid for the current state', 'base' => 'SystemException', 'size' => null],
    'notsupportedexception'    => ['desc' => 'Exception thrown when an invoked method is not supported', 'base' => 'SystemException', 'size' => null],
    'list'                     => ['desc' => 'Generic collection (List&lt;T&gt;)', 'base' => 'Object', 'size' => null],
    'ienumerable'              => ['desc' => 'Generic enumerable interface (IEnumerable&lt;T&gt;)', 'base' => 'Object', 'size' => null],
    'ilist'                    => ['desc' => 'Generic list interface (IList&lt;T&gt;)', 'base' => 'IEnumerable', 'size' => null],
    'icollection'              => ['desc' => 'Generic collection interface (ICollection&lt;T&gt;)', 'base' => 'IEnumerable', 'size' => null],
    'ireadonlylist'            => ['desc' => 'Generic read-only list interface (IReadOnlyList&lt;T&gt;)', 'base' => 'IEnumerable', 'size' => null],
    'ireadonlycollection'      => ['desc' => 'Generic read-only collection interface (IReadOnlyCollection&lt;T&gt;)', 'base' => 'IEnumerable', 'size' => null],
    'dictionary'               => ['desc' => 'Generic key-value collection (Dictionary&lt;TKey,TValue&gt;)', 'base' => 'Object', 'size' => null],
    'hashset'                  => ['desc' => 'Generic hash set (HashSet&lt;T&gt;)', 'base' => 'Object', 'size' => null],
    'stack'                    => ['desc' => 'Generic LIFO collection (Stack&lt;T&gt;)', 'base' => 'Object', 'size' => null],
    'queue'                    => ['desc' => 'Generic FIFO collection (Queue&lt;T&gt;)', 'base' => 'Object', 'size' => null],
];
$isBuiltin = false;
if (!$primitiveInfo && !isset($data['classes'][$classKey])) {
    if (isset($builtinTypes[$lcKey])) {
        $primitiveInfo = $builtinTypes[$lcKey];
        $isBuiltin = true;
    }
}

if (!isset($data['classes'][$classKey]) && !$primitiveInfo) {
    header('HTTP/1.0 404 Not Found');
    echo "Class not found.";
    exit;
}
if ($primitiveInfo) {
    $class = [
        'name' => $classKey,
        'namespace' => '',
        'kind' => $isArray ? 'array' : ($isBuiltin ? 'builtin' : 'primitive'),
        'access' => 'public',
        'static' => false,
        'file' => '(built-in)',
        'line' => 0,
        'doc' => $primitiveInfo['desc'],
        'baseTypes' => $primitiveInfo['base'] ? [$primitiveInfo['base']] : [],
        'members' => [],
        'size' => $primitiveInfo['size'],
    ];
    $ns = '';
    $shortName = $classKey;
} else {
    $class = $data['classes'][$classKey];
    $ns = $class['namespace'];
    $shortName = substr($classKey, strlen($ns) + 1);
}

$derivedMap = [];
foreach ($data['classes'] as $key => $c) {
    foreach ($c['baseTypes'] ?? [] as $bt) {
        $btClean = preg_replace('/[<>].*$/', '', $bt);
        if (!isset($derivedMap[$btClean])) $derivedMap[$btClean] = [];
        $derivedMap[$btClean][] = $key;
    }
}

function e($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function resolveFqn($short) {
    global $shortToFqn, $ns;
    $lc = strtolower($short);
    if (!isset($shortToFqn[$lc])) return $short;
    $candidates = $shortToFqn[$lc];
    if (count($candidates) === 1) return $candidates[0];
    // Prefer same namespace (keys are "Namespace\ClassName")
    $nsPrefix = $ns . '\\';
    foreach ($candidates as $c) {
        if (strpos($c, $nsPrefix) === 0) return $c;
    }
    return $candidates[0];
}

function renderType($type) {
    $map = ['string' => 'string', 'int' => 'int', 'long' => 'long', 'bool' => 'bool', 'void' => 'void', 'byte' => 'byte', 'float' => 'float', 'double' => 'double', 'decimal' => 'decimal', 'uint' => 'uint', 'ulong' => 'ulong', 'ushort' => 'ushort', 'short' => 'short', 'char' => 'char', 'object' => 'object', 'Task' => 'Task', 'DateTime' => 'DateTime', 'TimeSpan' => 'TimeSpan', 'Guid' => 'Guid', 'Exception' => 'Exception', 'List' => 'List', 'IEnumerable' => 'IEnumerable', 'IList' => 'IList', 'ICollection' => 'ICollection', 'Dictionary' => 'Dictionary', 'HashSet' => 'HashSet', 'Stack' => 'Stack', 'Queue' => 'Queue'];
    $t = trim($type);
    if (isset($map[$t])) return '<a href="class.php?c=' . urlencode($t) . '" class="param-type">' . $map[$t] . '</a>';
    if (strpos($t, 'List<') === 0) return '<span class="param-type">List&lt;</span><a href="class.php?c=' . urlencode(resolveFqn(substr($t, 5, -1))) . '" class="param-name">' . e(substr($t, 5, -1)) . '</a><span class="param-type">&gt;</span>';
    if (strpos($t, 'Task<') === 0) return '<span class="param-type">Task&lt;</span><a href="class.php?c=' . urlencode(resolveFqn(substr($t, 5, -1))) . '" class="param-name">' . e(substr($t, 5, -1)) . '</a><span class="param-type">&gt;</span>';
    if (strpos($t, 'IEnumerable<') === 0) return '<span class="param-type">IEnumerable&lt;</span><a href="class.php?c=' . urlencode(resolveFqn(substr($t, 12, -1))) . '" class="param-name">' . e(substr($t, 12, -1)) . '</a><span class="param-type">&gt;</span>';
    if (strpos($t, 'Dictionary<') === 0) return '<span class="param-type">Dictionary&lt;</span><a href="class.php?c=' . urlencode(resolveFqn(substr($t, 11, -1))) . '" class="param-name">' . e(substr($t, 11, -1)) . '</a><span class="param-type">&gt;</span>';
    if (substr($t, -1) === '?') return renderType(substr($t, 0, -1)) . '<span class="param-type">?</span>';
    if (preg_match('/^[A-Za-z]/', $t)) return '<a href="class.php?c=' . urlencode(resolveFqn($t)) . '" class="param-name">' . e($t) . '</a>';
    return '<span class="param-type">' . e($t) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($shortName) ?> — Code Documentation</title>
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
.access-badge { font-size:0.65rem; padding:1px 5px; border-radius:3px; margin-left:4px; font-weight:600; text-transform:uppercase; }
.access-badge.public { background:#dbeafe; color:#1e40af; }
.access-badge.private { background:#fce7f3; color:#9d174d; }
.access-badge.internal { background:#e0e7ff; color:#3730a3; }
.access-badge.protected { background:#fef3c7; color:#92400e; }
.primitive-table { width:100%; border-collapse:collapse; margin:1rem 0; font-size:0.85rem; }
.primitive-table th { background:#f1f5f9; text-align:left; padding:0.4rem 0.6rem; border:1px solid #e2e8f0; font-weight:600; }
.primitive-table td { padding:0.4rem 0.6rem; border:1px solid #e2e8f0; }
.primitive-table .active-primitive { background:#dbeafe; font-weight:600; }
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
      <div class="project-header<?= in_array($ns, $info['namespaces']) ? ' open' : '' ?>" onclick="this.classList.toggle('open');this.nextElementSibling.classList.toggle('open')">
        <span class="arrow">▶</span>
        <?= e($proj) ?>
        <span class="proj-count"><?= count($info['namespaces']) ?></span>
      </div>
      <div class="project-namespaces<?= in_array($ns, $info['namespaces']) ? ' open' : '' ?>">
        <?php foreach ($info['namespaces'] as $nsName): ?>
        <a href="index.php#ns-<?= urlencode($nsName) ?>" class="<?= $nsName === $ns ? 'active' : '' ?>"><?= e($nsName) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </nav>
</div>

<div class="main">
  <h1><span class="kind-badge <?= $class['kind'] ?>"><?= $class['kind'] ?></span> <?= e($shortName) ?></h1>
  <p class="subtitle">Namespace: <code><?= e($ns) ?></code></p>
  <?php if ($class['doc']): ?><p><?= e($class['doc']) ?></p><?php endif; ?>

  <?php
  $relatedXaml = null;
  $classFullKey = $ns . '\\' . $shortName;
  foreach ($data['xamlFiles'] ?? [] as $x) {
      if ($x['classKey'] === $classFullKey) { $relatedXaml = $x; break; }
  }
  if ($relatedXaml): ?>
  <div style="margin:1rem 0;padding:0.75rem 1rem;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;font-size:0.85rem;">
    <strong style="color:#166534;">📄 <?= e($relatedXaml['kind']) ?>:</strong>
    <code style="background:#dcfce7;"><?= e($relatedXaml['file']) ?></code>
    <span style="color:#64748b;margin-left:0.5rem;"><?= $relatedXaml['lineCount'] ?> lines</span>
  </div>
  <?php endif; ?>

  <div class="stats">
    <?php if ($class['baseTypes']): ?>
    <strong>Inherits:</strong> <?= implode(', ', array_map('e', $class['baseTypes'])) ?><br>
    <?php endif; ?>
    <?php if (!empty($derivedMap[$class['name']])): ?>
    <strong>Derived by:</strong>
      <?php $derivedLinks = []; ?>
      <?php foreach ($derivedMap[$class['name']] as $dk): ?>
        <?php $derivedLinks[] = '<a href="class.php?c=' . urlencode($dk) . '">' . e($dk) . '</a>'; ?>
      <?php endforeach; ?>
      <?= implode(', ', $derivedLinks) ?><br>
    <?php endif; ?>
    <?php if (isset($class['size'])): ?>
    <strong>Size:</strong> <?= $class['size'] !== null ? $class['size'] . ' bytes' : 'Platform-specific' ?> |
    <?php endif; ?>
    <strong>Access:</strong> <?= $class['access'] ?><?= $class['static'] ? ' static' : '' ?> |
    <strong>File:</strong> <?= e($class['file']) ?> :<?= $class['line'] ?>
  </div>

  <?php
  $constructors = array_filter($class['members'], fn($m) => $m['kind'] === 'constructor');
  $properties = array_filter($class['members'], fn($m) => $m['kind'] === 'property');
  $fields = array_filter($class['members'], fn($m) => $m['kind'] === 'field');
  $methods = array_filter($class['members'], fn($m) => $m['kind'] === 'method');
  $destructors = array_filter($class['members'], fn($m) => $m['kind'] === 'destructor');
  ?>

    <?php if ($constructors): ?>
  <h2>Constructors</h2>
  <?php foreach ($constructors as $m): ?>
  <div class="member">
    <div class="member-header">
      <span class="kind-badge constructor">ctor</span>
      <?php if (!empty($m['access'])): ?><span class="access-badge <?= $m['access'] ?>"><?= $m['access'] ?></span><?php endif; ?>
      <?php if (!empty($m['obsolete'])): ?><span class="obsolete-badge">obsolete<?= $m['obsolete'] !== '1' ? ': ' . e($m['obsolete']) : '' ?></span><?php endif; ?>
      <span class="member-name"><?= e($m['name']) ?></span>
      <span class="member-type">(<?php foreach ($m['params'] as $i => $p): ?><?php if ($i > 0): ?>, <?php endif; ?><?= renderType($p['type']) ?> <span class="param-name"><?= e($p['name']) ?></span><?php endforeach; ?>)</span>
    </div>
    <?php if ($m['doc']): ?><div class="doc-text"><?= e($m['doc']) ?></div><?php endif; ?>
    <?php if (!empty($m['code'])): ?><details class="code-details"><summary>Show code</summary><pre><code><?= e($m['code']) ?></code></pre></details><?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($destructors): ?>
  <h2>Destructor</h2>
  <?php foreach ($destructors as $m): ?>
  <div class="member">
    <div class="member-header">
      <span class="kind-badge constructor">~ctor</span>
      <?php if (!empty($m['access'])): ?><span class="access-badge <?= $m['access'] ?>"><?= $m['access'] ?></span><?php endif; ?>
      <?php if (!empty($m['obsolete'])): ?><span class="obsolete-badge">obsolete<?= $m['obsolete'] !== '1' ? ': ' . e($m['obsolete']) : '' ?></span><?php endif; ?>
      <span class="member-name"><?= e($m['name']) ?></span>
    </div>
    <?php if ($m['doc']): ?><div class="doc-text"><?= e($m['doc']) ?></div><?php endif; ?>
    <?php if (!empty($m['code'])): ?><details class="code-details"><summary>Show code</summary><pre><code><?= e($m['code']) ?></code></pre></details><?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($properties): ?>
  <h2>Properties</h2>
  <?php foreach ($properties as $m): ?>
  <div class="member">
    <div class="member-header">
      <span class="kind-badge property">prop</span>
      <?php if (!empty($m['access'])): ?><span class="access-badge <?= $m['access'] ?>"><?= $m['access'] ?></span><?php endif; ?>
      <?php if (!empty($m['obsolete'])): ?><span class="obsolete-badge">obsolete<?= $m['obsolete'] !== '1' ? ': ' . e($m['obsolete']) : '' ?></span><?php endif; ?>
      <span class="member-name"><?= e($m['name']) ?></span>
      <span class="member-type">: <?= renderType($m['type']) ?></span>
    </div>
    <?php if ($m['doc']): ?><div class="doc-text"><?= e($m['doc']) ?></div><?php endif; ?>
    <?php if (!empty($m['code'])): ?><details class="code-details"><summary>Show code</summary><pre><code><?= e($m['code']) ?></code></pre></details><?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($fields): ?>
  <h2>Fields</h2>
  <?php foreach ($fields as $m): ?>
  <div class="member">
    <div class="member-header">
      <span class="kind-badge field">field</span>
      <?php if (!empty($m['access'])): ?><span class="access-badge <?= $m['access'] ?>"><?= $m['access'] ?></span><?php endif; ?>
      <?php if (!empty($m['obsolete'])): ?><span class="obsolete-badge">obsolete<?= $m['obsolete'] !== '1' ? ': ' . e($m['obsolete']) : '' ?></span><?php endif; ?>
      <span class="member-name"><?= e($m['name']) ?></span>
      <span class="member-type">(<?php foreach ($m['params'] ?? [] as $i => $p): ?><?php if ($i > 0): ?>, <?php endif; ?><?= renderType($p['type']) ?> <span class="param-name"><?= e($p['name']) ?></span><?php endforeach; ?>)</span>
    </div>
    <?php if ($m['doc']): ?><div class="doc-text"><?= e($m['doc']) ?></div><?php endif; ?>
    <?php if (!empty($m['code'])): ?><details class="code-details"><summary>Show code</summary><pre><code><?= e($m['code']) ?></code></pre></details><?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($destructors): ?>
  <h2>Destructor</h2>
  <?php foreach ($destructors as $m): ?>
  <div class="member">
    <div class="member-header">
      <span class="kind-badge constructor">~ctor</span>
      <?php if (!empty($m['access'])): ?><span class="access-badge <?= $m['access'] ?>"><?= $m['access'] ?></span><?php endif; ?>
      <?php if (!empty($m['obsolete'])): ?><span class="obsolete-badge">obsolete<?= $m['obsolete'] !== '1' ? ': ' . e($m['obsolete']) : '' ?></span><?php endif; ?>
      <span class="member-name"><?= e($m['name']) ?></span>
    </div>
    <?php if ($m['doc']): ?><div class="doc-text"><?= e($m['doc']) ?></div><?php endif; ?>
    <?php if (!empty($m['code'])): ?><details class="code-details"><summary>Show code</summary><pre><code><?= e($m['code']) ?></code></pre></details><?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($properties): ?>
  <h2>Properties</h2>
  <?php foreach ($properties as $m): ?>
  <div class="member">
    <div class="member-header">
      <span class="kind-badge property">prop</span>
      <?php if (!empty($m['access'])): ?><span class="access-badge <?= $m['access'] ?>"><?= $m['access'] ?></span><?php endif; ?>
      <?php if (!empty($m['obsolete'])): ?><span class="obsolete-badge">obsolete<?= $m['obsolete'] !== '1' ? ': ' . e($m['obsolete']) : '' ?></span><?php endif; ?>
      <span class="member-name"><?= e($m['name']) ?></span>
      <span class="member-type">: <?= renderType($m['type']) ?></span>
    </div>
    <?php if ($m['doc']): ?><div class="doc-text"><?= e($m['doc']) ?></div><?php endif; ?>
    <?php if (!empty($m['code'])): ?><details class="code-details"><summary>Show code</summary><pre><code><?= e($m['code']) ?></code></pre></details><?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($fields): ?>
  <h2>Fields</h2>
  <?php foreach ($fields as $m): ?>
  <div class="member">
    <div class="member-header">
      <span class="kind-badge field">field</span>
      <?php if (!empty($m['access'])): ?><span class="access-badge <?= $m['access'] ?>"><?= $m['access'] ?></span><?php endif; ?>
      <?php if (!empty($m['obsolete'])): ?><span class="obsolete-badge">obsolete<?= $m['obsolete'] !== '1' ? ': ' . e($m['obsolete']) : '' ?></span><?php endif; ?>
      <span class="member-name"><?= e($m['name']) ?></span>
      <?php if ($m['type']): ?><span class="member-type">: <?= e($m['type']) ?></span><?php endif; ?>
    </div>
    <?php if ($m['doc']): ?><div class="doc-text"><?= e($m['doc']) ?></div><?php endif; ?>
    <?php if (!empty($m['code'])): ?><details class="code-details"><summary>Show code</summary><pre><code><?= e($m['code']) ?></code></pre></details><?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <?php if ($methods): ?>
  <h2>Methods</h2>
  <?php foreach ($methods as $m): ?>
  <div class="member" id="m-<?= e($m['name']) ?>">
    <div class="member-header">
      <span class="kind-badge method">method</span>
      <?php if (!empty($m['access'])): ?><span class="access-badge <?= $m['access'] ?>"><?= $m['access'] ?></span><?php endif; ?>
      <?php if (!empty($m['obsolete'])): ?><span class="obsolete-badge">obsolete<?= $m['obsolete'] !== '1' ? ': ' . e($m['obsolete']) : '' ?></span><?php endif; ?>
      <span class="member-name"><?= e($m['name']) ?></span>
      <span class="member-type">(<?php foreach ($m['params'] as $i => $p): ?><?php if ($i > 0): ?>, <?php endif; ?><?= renderType($p['type']) ?> <span class="param-name"><?= e($p['name']) ?></span><?php endforeach; ?>)</span>
      <span class="member-type">: <?= renderType($m['returnType']) ?></span>
    </div>
    <?php if ($m['doc']): ?><div class="doc-text"><?= e($m['doc']) ?></div><?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
  
  <?php if (isset($class['size'])): ?>
  <h2>Built-in Types Overview</h2>
  <table class="primitive-table">
    <tr><th>Type</th><th>Size</th><th>Description</th></tr>
    <?php foreach ([
      ['bool',1,'Boolean (true/false)'], ['byte',1,'8-bit unsigned integer'], ['sbyte',1,'8-bit signed integer'],
      ['char',2,'UTF-16 code unit'], ['short',2,'16-bit signed integer'], ['ushort',2,'16-bit unsigned integer'],
      ['int',4,'32-bit signed integer'], ['uint',4,'32-bit unsigned integer'], ['float',4,'32-bit single-precision floating point'],
      ['long',8,'64-bit signed integer'], ['ulong',8,'64-bit unsigned integer'], ['double',8,'64-bit double-precision floating point'],
      ['decimal',16,'128-bit decimal value'], ['DateTime',8,'Date and time (UTC ticks)'], ['TimeSpan',8,'Time interval'],
      ['Guid',16,'Globally unique identifier'],
    ] as $r): ?>
    <tr<?= strtolower($r[0]) === $lcKey ? ' class="active-primitive"' : '' ?>>
      <td><a href="class.php?c=<?= $r[0] ?>" class="param-type"><?= $r[0] ?></a></td>
      <td><?= $r[1] ?> bytes</td>
      <td><?= $r[2] ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
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

  const activeLink = document.querySelector('.project-namespaces a.active');
  if (activeLink) {
    setTimeout(function() { activeLink.scrollIntoView({ block: 'nearest' }); }, 100);
  }
})();
</script>
</body>
</html>
