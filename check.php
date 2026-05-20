<?php
$docFile = __DIR__ . '/doc.json';
$docData = json_decode(file_get_contents($docFile), true);
$root = $docData['stats']['sourceRoot'] ?? (dirname(__DIR__) . DIRECTORY_SEPARATOR . 'code');

$fileToKey = [];
foreach ($docData['classes'] as $key => $c) {
    $fileToKey[strtolower(str_replace('/', '\\', $c['file']))] = $key;
}

$issues = [];

$dir = new RecursiveDirectoryIterator($root);
$iter = new RecursiveIteratorIterator($dir);
$totalFiles = 0;
$consoleWrites = 0;
$emptyCatches = 0;
$todos = 0;
$obsoletes = 0;
$threadSleep = 0;
$taskBlocking = 0;
$hardcodedIp = 0;
$publicFields = 0;
$manyParams = 0;
$largeMethods = 0;
$infiniteLoops = 0;
$asyncVoid = 0;
$hardcodedCreds = 0;
$pragmaWarning = 0;

foreach ($iter as $file) {
    if ($file->getExtension() !== 'cs') continue;
    $totalFiles++;
    $relPath = str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname());
    $content = file_get_contents($file->getPathname());
    if ($content === false) continue;
    
    $lines = explode("\n", $content);
    $inMethod = false;
    $methodName = '';
    $methodStartLine = 0;
    $braceDepth = 0;
    $methodBraceDepth = 0;
    $methodLines = 0;
    
    foreach ($lines as $ln => $line) {
        $lineNum = $ln + 1;
        
        if (preg_match('/Console\.Write(?:Line)?\s*\(/', $line)) {
            $issues[] = ['type' => 'console', 'file' => $relPath, 'line' => $lineNum, 'text' => trim($line)];
            $consoleWrites++;
        }
        
        if (preg_match('/catch\s*\([^)]*\)\s*\{\s*\}/', $line) || 
            preg_match('/catch\s*\([^)]*\)\s*\{\s*\}\s*$/', $line)) {
            $issues[] = ['type' => 'empty-catch', 'file' => $relPath, 'line' => $lineNum, 'text' => trim($line)];
            $emptyCatches++;
        }
        if (preg_match('/catch\s*\([^)]*\)\s*$/', $line)) {
            $nextLine = $lines[$ln + 1] ?? '';
            $nextNextLine = $lines[$ln + 2] ?? '';
            if (preg_match('/^\s*\{\s*\}/', $nextLine) || 
                (preg_match('/^\s*\{\s*$/', $nextLine) && preg_match('/^\s*\}\s*$/', $nextNextLine))) {
                $issues[] = ['type' => 'empty-catch', 'file' => $relPath, 'line' => $lineNum, 'text' => trim($line) . ' { }'];
                $emptyCatches++;
            }
        }

        // TODO / FIXME / HACK
        if (preg_match('/\/\/\s*(TODO|todo|Todo|FIXME|fixme|HACK|hack)\s*[:]?\s*(.*)$/', $line, $m)) {
            $issues[] = ['type' => 'todo', 'file' => $relPath, 'line' => $lineNum, 'text' => trim($line)];
            $todos++;
        }

        // [Obsolete] members
        if (preg_match('/\[Obsolete\s*([^\]]*)\]/', $line, $obM)) {
            $reason = trim($obM[1]);
            $issues[] = ['type' => 'obsolete', 'file' => $relPath, 'line' => $lineNum, 'text' => trim($line) . ($reason ? ' — ' . $reason : '')];
            $obsoletes++;
        }

        // Thread.Sleep
        if (preg_match('/Thread\.Sleep\s*\(/', $line)) {
            $issues[] = ['type' => 'thread-sleep', 'file' => $relPath, 'line' => $lineNum, 'text' => trim($line)];
            $threadSleep++;
        }

        // Task.Result or Task.Wait() (blocking on async)
        if (preg_match('/\.(Result|Wait)\s*[\(;]/', $line) && !preg_match('/\bawait\b/', $line)) {
            $issues[] = ['type' => 'task-blocking', 'file' => $relPath, 'line' => $lineNum, 'text' => trim($line)];
            $taskBlocking++;
        }

        // Hardcoded IP addresses in string literals (skip assembly/version metadata)
        if (!preg_match('/Assembly(File)?Version|GeneratedCode(Attribute)?|ResourceBuilder|SettingsSingleFileGenerator|CompilerGenerated|^\[assembly:/', $line)) {
            if (preg_match('/"[^"]*\b(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})\b[^"]*"/', $line, $ipM)) {
                $o1 = (int)$ipM[1]; $o2 = (int)$ipM[2]; $o3 = (int)$ipM[3]; $o4 = (int)$ipM[4];
                // Skip localhost, any-address, broadcast
                if ($o1 === 127 || ($o1 === 0 && $o2 === 0 && $o3 === 0 && $o4 === 0) || ($o1 === 255 && $o2 === 255 && $o3 === 255 && $o4 === 255)) continue;
                // Skip IPs in URL contexts (http://, https://, etc.)
                if (preg_match('|\bhttps?://|', $line)) continue;
                // Skip IPAddress.Parse(...) or IPAddress.Loopback etc.
                if (preg_match('/IPAddress\.(Parse|Loopback|Any|Broadcast)\s*\(/', $line)) continue;
                // Skip IPs in config arrays: = { "ip1", "ip2", ... }
                if (preg_match('/=\s*\{[^}]*' . preg_quote($ipM[0], '/') . '[^}]*\}/', $line)) continue;
                // Skip IPs in connection strings (Server=, Data Source=, Host=)
                if (preg_match('/(?:Server|Data\s+Source|Host)\s*=\s*\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', $line)) continue;
                // Skip version-like patterns (pure small numbers)
                $isLikelyVersion = ($o1 < 10 && $o2 < 100 && $o3 < 100 && $o4 < 100);
                $isPrivateIp = ($o1 === 10) || ($o1 === 172 && $o2 >= 16 && $o2 <= 31) || ($o1 === 192 && $o2 === 168);
                if (!$isLikelyVersion || $isPrivateIp) {
                    $issues[] = ['type' => 'hardcoded-ip', 'file' => $relPath, 'line' => $lineNum, 'text' => trim($line)];
                    $hardcodedIp++;
                }
            }
        }

        // Public fields (not properties)
        if (preg_match('/^\s*public\s+(?:static\s+)?(?:readonly\s+)?(\w+)\s+(\w+)\s*;/', $line) && !preg_match('/\bconst\b/', $line)) {
            $issues[] = ['type' => 'public-field', 'file' => $relPath, 'line' => $lineNum, 'text' => trim($line)];
            $publicFields++;
        }

        // Methods with many parameters
        if (preg_match('/^\s*(?:public|internal|private|protected)\s+(?:static\s+)?(?:async\s+)?(?:Task|void|\w+)\s+(\w+)\s*\(([^)]*)\)/', $line, $pm)) {
            $paramStr = $pm[2];
            $paramCount = 0;
            $depth = 0;
            for ($i = 0; $i < strlen($paramStr); $i++) {
                if ($paramStr[$i] === '<') $depth++;
                elseif ($paramStr[$i] === '>') $depth--;
                elseif ($paramStr[$i] === ',' && $depth === 0) $paramCount++;
            }
            if (!empty(trim($paramStr))) $paramCount++;
            if ($paramCount >= 7) {
                $issues[] = ['type' => 'many-params', 'file' => $relPath, 'line' => $lineNum, 'text' => trim($pm[0]) . ' (' . $paramCount . ' params)'];
                $manyParams++;
            }
        }

        // Infinite loops (while(true), for(;;)) without exit
        if (preg_match('/(?:while\s*\(\s*true\s*\)|for\s*\(\s*;\s*;\s*\))/', $line)) {
            $loopLine = $ln;
            $hasExit = false;
            $hasBrace = strpos($line, '{') !== false;
            if (!$hasBrace) {
                for ($j = $ln + 1; $j < count($lines); $j++) {
                    $tl = trim($lines[$j]);
                    if ($tl === '' || strpos($tl, '//') === 0) continue;
                    if (strpos($tl, '{') !== false) { $hasBrace = true; $loopLine = $j; }
                    break;
                }
            }
            if ($hasBrace) {
                $depth = 0;
                $inBody = false;
                for ($j = $loopLine; $j < count($lines) && $j <= $loopLine + 100; $j++) {
                    $sl = $lines[$j];
                    $opens = substr_count($sl, '{');
                    $closes = substr_count($sl, '}');
                    if (!$inBody && $opens > 0) $inBody = true;
                    $depth += $opens - $closes;
                    if ($inBody && $depth <= 0) break;
                    if ($inBody) {
                        if (preg_match('/\b(break|return|yield|throw)\b/', $sl) ||
                            preg_match('/Thread\.Sleep|await\b/', $sl)) {
                            $hasExit = true;
                            break;
                        }
                    }
                }
            } else {
                for ($j = $loopLine + 1; $j < count($lines); $j++) {
                    $tl = trim($lines[$j]);
                    if ($tl === '' || strpos($tl, '//') === 0) continue;
                    if (preg_match('/\b(break|return|yield|throw)\b/', $tl) ||
                        preg_match('/Thread\.Sleep|await\b/', $tl)) {
                        $hasExit = true;
                    }
                    break;
                }
            }
            if (!$hasExit) {
                $issues[] = ['type' => 'infinite-loop', 'file' => $relPath, 'line' => $lineNum, 'text' => trim($line)];
                $infiniteLoops++;
            }
        }

        if (preg_match('/\basync\s+void\s+(\w+)\s*\(/', $line, $avM)) {
            if (!str_contains($avM[1], '_')) {
                $issues[] = ['type' => 'async-void', 'file' => $relPath, 'line' => $lineNum, 'text' => trim($line)];
                $asyncVoid++;
            }
        }

        if (preg_match('/"[^"]*?(?:password|pwd|token|secret|apikey|api_key)\s*[:=]\s*([^"]+?)"/i', $line, $credM)) {
            if (!preg_match('/\{/', $credM[1])) { // skip interpolated values like {var}
                $masked = preg_replace('/"(password|pwd|token|secret|apikey|api_key)\s*[:=]\s*\K[^"]+"/i', '***"', trim($line));
                $issues[] = ['type' => 'hardcoded-cred', 'file' => $relPath, 'line' => $lineNum, 'text' => $masked];
                $hardcodedCreds++;
            }
        }

        if (preg_match('/^\s*(?:public|internal|private|protected|static|async|unsafe|override|virtual|sealed)\s+.*\)\s*$/', $line) || preg_match('/^\s*(?:public|internal|private|protected|static|async|unsafe|override|virtual|sealed)\s+.*\)\s*\{\s*$/', $line)) {

        
        }
        foreach (str_split($line) as $ch) {
            if ($ch === '{') $braceDepth++;
            elseif ($ch === '}') $braceDepth--;
        }
    }
}

$iter2 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($iter2 as $file) {
    if ($file->getExtension() !== 'cs') continue;
    $relPath = str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname());
    $content = file_get_contents($file->getPathname());
    if ($content === false) continue;
    
    $lines = explode("\n", $content);
    $total = count($lines);
    
    for ($i = 0; $i < $total; $i++) {
        $line = $lines[$i];
        if (preg_match('/^\s*(?:public|internal|private|protected|static|async|unsafe|override|virtual|sealed|new)\s+.*\)\s*$/', $line)) {
            $sigEnd = $i;
            for ($j = $i + 1; $j < $total; $j++) {
                $nextLine = trim($lines[$j]);
                if ($nextLine === '' || strpos($nextLine, '//') === 0) continue;
                if (strpos($nextLine, '{') === 0 || strpos($nextLine, '{') !== false) {
                    $bodyStart = $j;
                    $bodyDepth = 0;
                    $inBody = false;
                    for ($k = $bodyStart; $k < $total; $k++) {
                        $bodyLine = $lines[$k];
                        $openCount = substr_count($bodyLine, '{');
                        $closeCount = substr_count($bodyLine, '}');
                        if (!$inBody && $openCount > 0) { $inBody = true; }
                        $bodyDepth += $openCount - $closeCount;
                        if ($inBody && $bodyDepth <= 0) {
                            $methodLines = $k - $bodyStart + 1;
                            if ($methodLines > 300) {
                                $methodName = trim(preg_replace('/\s*\(.*$/', '', preg_replace('/^\s*(?:public|internal|private|protected|static|async|unsafe|override|virtual|sealed|new)\s+/', '', $line)));
                                $issues[] = ['type' => 'large-method', 'file' => $relPath, 'line' => $i + 1, 'text' => $methodName . ' (' . $methodLines . ' lines)'];
                                $largeMethods++;
                            }
                            break;
                        }
                    }
                    $i = $k ?? $i;
                    break;
                }
                if (!preg_match('/^\s*(?:public|internal|private|protected|static|async|unsafe|override|virtual|sealed|new|\[)/', $nextLine)) break;
            }
        } elseif (preg_match('/^\s*(?:public|internal|private|protected|static|async|unsafe|override|virtual|sealed|new)\s+.*\)\s*\{\s*$/', $line)) {
            $bodyStart = $i;
            $bodyDepth = 1;
            for ($k = $i + 1; $k < $total; $k++) {
                $bodyLine = $lines[$k];
                $openCount = substr_count($bodyLine, '{');
                $closeCount = substr_count($bodyLine, '}');
                $bodyDepth += $openCount - $closeCount;
                if ($bodyDepth <= 0) {
                    $methodLines = $k - $bodyStart + 1;
                    if ($methodLines > 300) {
                        $methodName = trim(preg_replace('/\s*\(.*$/', '', preg_replace('/^\s*(?:public|internal|private|protected|static|async|unsafe|override|virtual|sealed|new)\s+/', '', $line)));
                        $issues[] = ['type' => 'large-method', 'file' => $relPath, 'line' => $i + 1, 'text' => $methodName . ' (' . $methodLines . ' lines)'];
                        $largeMethods++;
                    }
                    $i = $k;
                    break;
                }
            }
        }
    }
}

$iter3 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($iter3 as $file) {
    if ($file->getExtension() !== 'cs') continue;
    $relPath = str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname());
    $content = file_get_contents($file->getPathname());
    if ($content === false) continue;
    $lines = explode("\n", $content);
    $disabled = [];
    foreach ($lines as $ln => $line) {
        if (preg_match('/#pragma\s+warning\s+disable\s+(\d+(?:\s*,\s*\d+)*)/i', $line, $m)) {
            $codes = preg_split('/\s*,\s*/', $m[1]);
            foreach ($codes as $c) { $disabled[(int)$c] = $ln + 1; }
        }
        if (preg_match('/#pragma\s+warning\s+restore\s+(\d+(?:\s*,\s*\d+)*)/i', $line, $m)) {
            $codes = preg_split('/\s*,\s*/', $m[1]);
            foreach ($codes as $c) { unset($disabled[(int)$c]); }
        }
    }
    foreach ($disabled as $code => $lineNum) {
        $issues[] = ['type' => 'pragma-warning', 'file' => $relPath, 'line' => $lineNum, 'text' => "#pragma warning disable $code (never restored)"];
        $pragmaWarning++;
    }
}

usort($issues, fn($a, $b) => strcmp($a['file'] . ':' . $a['line'], $b['file'] . ':' . $b['line']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Code Check — Code Documentation</title>
<link rel="stylesheet" href="style.css">
<style>
.issue { padding: 0.75rem; border-radius: 6px; margin-bottom: 0.5rem; border: 1px solid #e2e8f0; }
.issue.console { border-left: 4px solid #f59e0b; }
.issue.empty-catch { border-left: 4px solid #ef4444; }
.issue.todo { border-left: 4px solid #3b82f6; }
.issue.obsolete { border-left: 4px solid #6b7280; }
.issue.thread-sleep { border-left: 4px solid #8b5cf6; }
.issue.task-blocking { border-left: 4px solid #dc2626; }
.issue.hardcoded-ip { border-left: 4px solid #0891b2; }
.issue.public-field { border-left: 4px solid #d97706; }
.issue.many-params { border-left: 4px solid #65a30d; }
.issue.large-method { border-left: 4px solid #e11d48; }
.issue .file-line { font-size: 0.8rem; color: #64748b; }
.issue .code-snippet { font-family: 'Courier New', monospace; font-size: 0.82rem; background: #f8fafc; padding: 0.25rem 0.5rem; border-radius: 4px; margin-top: 0.25rem; white-space: pre-wrap; }
.summary-cards { display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
.summary-card { flex: 1; min-width: 140px; background: #f8fafc; border-radius: 8px; padding: 1rem; border: 1px solid #e2e8f0; text-align: center; }
.summary-card .num { font-size: 2rem; font-weight: 700; }
.summary-card .label { font-size: 0.75rem; color: #64748b; margin-top: 0.25rem; }
.filter-bar { margin-bottom: 1rem; }
.filter-bar button { padding: 0.35rem 0.75rem; border: 1px solid #e2e8f0; border-radius: 6px; background: #fff; cursor: pointer; font-size: 0.8rem; margin-right: 0.4rem; }
.filter-bar button.active { background: #2563eb; color: #fff; border-color: #2563eb; }
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
    <a href="api.php" style="display:block;padding:0.2rem 0;font-size:0.85rem;">⚡ API</a>
    <a href="stats.php" style="display:block;padding:0.2rem 0;font-size:0.85rem;">📊 Statistics</a>
    <a href="check.php" style="display:block;padding:0.2rem 0;font-size:0.85rem;font-weight:700;color:#38bdf8;">🔍 Check</a>
      <div style="padding:0.25rem 1.5rem;border-top:1px solid #1e293b;margin-top:0.5rem;padding-top:0.75rem;">
        <button onclick="toggleDark()" id="dark-toggle" style="width:100%;padding:0.4rem 0.75rem;border:1px solid #334155;border-radius:6px;background:#1e293b;color:#e2e8f0;cursor:pointer;font-size:0.8rem;">🌙 Dark Mode</button>
      </div>
  </nav>
  <div class="sidebar-section">Projects</div>
  <nav>
    <?php
    $data = json_decode(file_get_contents($docFile), true);
    $projects = [];
    foreach ($data['classes'] as $key => $c) {
        $parts = explode('\\', $c['file']);
        $proj = $parts[0];
        if (!isset($projects[$proj])) $projects[$proj] = ['namespaces' => []];
        $nss = $c['namespace'];
        if (!in_array($nss, $projects[$proj]['namespaces'])) $projects[$proj]['namespaces'][] = $nss;
    }
    ksort($projects);
    foreach ($projects as &$p) { sort($p['namespaces']); }
    unset($p);
    ?>
    <?php foreach ($projects as $proj => $info): ?>
    <div class="project-group">
      <div class="project-header" onclick="this.classList.toggle('open');this.nextElementSibling.classList.toggle('open')">
        <span class="arrow">▶</span>
        <?= htmlspecialchars($proj) ?>
        <span class="proj-count"><?= count($info['namespaces']) ?></span>
      </div>
      <div class="project-namespaces">
        <?php foreach ($info['namespaces'] as $nsName): ?>
        <a href="index.php#ns-<?= urlencode($nsName) ?>"><?= htmlspecialchars($nsName) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </nav>
</div>

<div class="main">
  <h1>🔍 Code Check</h1>
  <p class="subtitle">Scanned <?= $totalFiles ?> .cs files — <?= count($issues) ?> issues found</p>

  <div class="summary-cards">
    <div class="summary-card">
      <div class="num" style="color:#f59e0b;"><?= $consoleWrites ?></div>
      <div class="label">Console.Write</div>
    </div>
    <div class="summary-card">
      <div class="num" style="color:#ef4444;"><?= $emptyCatches ?></div>
      <div class="label">Empty Catch</div>
    </div>
    <div class="summary-card">
      <div class="num" style="color:#3b82f6;"><?= $todos ?></div>
      <div class="label">TODO/FIXME</div>
    </div>
    <div class="summary-card">
      <div class="num" style="color:#6b7280;"><?= $obsoletes ?></div>
      <div class="label">[Obsolete]</div>
    </div>
    <div class="summary-card">
      <div class="num" style="color:#8b5cf6;"><?= $threadSleep ?></div>
      <div class="label">Thread.Sleep</div>
    </div>
    <div class="summary-card">
      <div class="num" style="color:#dc2626;"><?= $taskBlocking ?></div>
      <div class="label">Task.Result/Wait</div>
    </div>
    <div class="summary-card">
      <div class="num" style="color:#0891b2;"><?= $hardcodedIp ?></div>
      <div class="label">Hardcoded IP</div>
    </div>
    <div class="summary-card">
      <div class="num" style="color:#d97706;"><?= $publicFields ?></div>
      <div class="label">Public Fields</div>
    </div>
    <div class="summary-card">
      <div class="num" style="color:#65a30d;"><?= $manyParams ?></div>
      <div class="label">Many Params (7+)</div>
    </div>
    <div class="summary-card">
      <div class="num" style="color:#e11d48;"><?= $largeMethods ?></div>
      <div class="label">Large Methods (300+)</div>
    </div>
    <div class="summary-card">
      <div class="num" style="color:#7c3aed;"><?= $infiniteLoops ?></div>
      <div class="label">Infinite Loops</div>
    </div>
    <div class="summary-card">
      <div class="num" style="color:#ec4899;"><?= $asyncVoid ?></div>
      <div class="label">async void</div>
    </div>
    <div class="summary-card">
      <div class="num" style="color:#f97316;"><?= $hardcodedCreds ?></div>
      <div class="label">Hardcoded Credentials</div>
    </div>
    <div class="summary-card">
      <div class="num" style="color:#14b8a6;"><?= $pragmaWarning ?></div>
      <div class="label">Pragma Disabled</div>
    </div>
  </div>

  <div class="filter-bar">
    <button onclick="filterIssues('all')" class="active" id="f-all">All (<?= count($issues) ?>)</button>
    <button onclick="filterIssues('console')" id="f-console">Console (<?= $consoleWrites ?>)</button>
    <button onclick="filterIssues('empty-catch')" id="f-empty-catch">Catch (<?= $emptyCatches ?>)</button>
    <button onclick="filterIssues('todo')" id="f-todo">TODO (<?= $todos ?>)</button>
    <button onclick="filterIssues('obsolete')" id="f-obsolete">Obsolete (<?= $obsoletes ?>)</button>
    <button onclick="filterIssues('thread-sleep')" id="f-thread-sleep">Sleep (<?= $threadSleep ?>)</button>
    <button onclick="filterIssues('task-blocking')" id="f-task-blocking">Block (<?= $taskBlocking ?>)</button>
    <button onclick="filterIssues('hardcoded-ip')" id="f-hardcoded-ip">IP (<?= $hardcodedIp ?>)</button>
    <button onclick="filterIssues('public-field')" id="f-public-field">PubField (<?= $publicFields ?>)</button>
    <button onclick="filterIssues('many-params')" id="f-many-params">Params (<?= $manyParams ?>)</button>
    <button onclick="filterIssues('large-method')" id="f-large-method">Large (<?= $largeMethods ?>)</button>
    <button onclick="filterIssues('infinite-loop')" id="f-infinite-loop">Loop (<?= $infiniteLoops ?>)</button>
    <button onclick="filterIssues('async-void')" id="f-async-void">AsyncVoid (<?= $asyncVoid ?>)</button>
    <button onclick="filterIssues('hardcoded-cred')" id="f-hardcoded-cred">Cred (<?= $hardcodedCreds ?>)</button>
    <button onclick="filterIssues('pragma-warning')" id="f-pragma-warning">Pragma (<?= $pragmaWarning ?>)</button>
  </div>

  <div id="issue-list">
  <?php foreach ($issues as $iss): ?>
  <div class="issue <?= $iss['type'] ?>" data-type="<?= $iss['type'] ?>">
    <div class="file-line">
      <a href="class.php?c=<?= urlencode($fileToKey[strtolower($iss['file'])] ?? str_replace('.cs', '', str_replace('\\', '.', $iss['file']))) ?>"><?= htmlspecialchars($iss['file']) ?></a>:<?= $iss['line'] ?>
      <span style="float:right;font-size:0.7rem;color:#94a3b8;"><?php
$labels = [
    'console' => 'Console.Write',
    'empty-catch' => 'Empty catch',
    'todo' => 'TODO/FIXME',
    'obsolete' => '[Obsolete]',
    'thread-sleep' => 'Thread.Sleep',
    'task-blocking' => 'Task.Result/Wait',
    'hardcoded-ip' => 'Hardcoded IP',
    'public-field' => 'Public field',
    'many-params' => 'Many params',
    'large-method' => 'Large method',
    'infinite-loop' => 'Infinite loop',
    'async-void' => 'async void',
    'hardcoded-cred' => 'Hardcoded credential',
    'pragma-warning' => 'Pragma disabled',
];
echo $labels[$iss['type']] ?? $iss['type'];
?></span>
    </div>
    <div class="code-snippet"><?= htmlspecialchars($iss['text']) ?></div>
  </div>
  <?php endforeach; ?>
  </div>

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
function filterIssues(type) {
  document.querySelectorAll('.filter-bar button').forEach(b => b.classList.remove('active'));
  document.getElementById('f-' + type).classList.add('active');
  document.querySelectorAll('.issue').forEach(el => {
    el.style.display = (type === 'all' || el.dataset.type === type) ? '' : 'none';
  });
}

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
