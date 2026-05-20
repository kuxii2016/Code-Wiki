<?php
/**
 * Code Documentation Generator v3
 * Parses .cs files → doc.json with source code extraction
 *
 * Usage: php generate.php
 */

$rootDir = realpath(__DIR__ . '/code');
$outputFile = __DIR__ . '/doc.json';

if (!$rootDir || !is_dir($rootDir)) {
    die("Error: project code directory not found at: " . __DIR__ . "/code\n");
}

echo "Scanning: $rootDir\nGenerating code documentation...\n";

$docData = [
    'generated'   => date('c'),
    'totalFiles'  => 0,
    'namespaces'  => [],
    'classes'     => [],
];

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($rootDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);

$csFiles = [];
$xamlFiles = [];
foreach ($files as $file) {
    $p = $file->getRealPath();
    if (stripos($p, '\\obj\\') !== false || stripos($p, '\\bin\\') !== false) continue;
    if ($file->getExtension() === 'cs') $csFiles[] = $p;
    elseif ($file->getExtension() === 'xaml') $xamlFiles[] = $p;
}

$docData['totalFiles'] = count($csFiles) + count($xamlFiles);
echo "Found " . count($csFiles) . " .cs files and " . count($xamlFiles) . " .xaml files\n";

foreach ($csFiles as $filePath) {
    $relativePath = str_replace($rootDir . DIRECTORY_SEPARATOR, '', $filePath);
    $content = file_get_contents($filePath);
    if ($content === false) continue;
    if (str_starts_with($content, "\xEF\xBB\xBF")) $content = substr($content, 3);
    $lines = file($filePath);
    if ($lines === false) continue;
    if ($lines && str_starts_with($lines[0], "\xEF\xBB\xBF")) $lines[0] = substr($lines[0], 3);

    // Extract namespace
    $namespace = '';
    if (preg_match('/^namespace\s+([\w.\\\]+)/m', $content, $m)) {
        $namespace = trim($m[1]);
    }
    if (empty($namespace)) continue;

    if (!isset($docData['namespaces'][$namespace])) {
        $docData['namespaces'][$namespace] = [];
    }

    $docComments = extractDocComments($lines);
    $types = parseTypes($content, $docComments, $namespace, $relativePath);

    foreach ($types as $type) {
        $key = $type['namespace'] . '\\' . $type['name'];
        if (!isset($docData['classes'][$key])) {
            $docData['classes'][$key] = $type;
            $docData['namespaces'][$namespace][] = $key;
        }
    }
}

$xamlResources = [];
foreach ($xamlFiles as $filePath) {
    $relativePath = str_replace($rootDir . DIRECTORY_SEPARATOR, '', $filePath);
    $content = file_get_contents($filePath);
    if ($content === false) continue;
    $lines = file($filePath);
    $lineCount = $lines ? count($lines) : 0;
    $xaml = [
        'file' => $relativePath,
        'lineCount' => $lineCount,
        'size' => filesize($filePath),
    ];
    if (preg_match('/<(Window|Page|UserControl|ResourceDictionary|DataTemplate|ControlTemplate|Style|Application|NavigationWindow|ContentPage|ContentDialog|View|DataForm|Popup|ToolBar|ContextMenu)[>\s]/', $content, $m)) {
        $xaml['kind'] = $m[1];
    } else {
        $xaml['kind'] = 'Xaml';
    }
    if (preg_match('/x:Class\s*=\s*"([^"]+)"/', $content, $m)) {
        $xaml['classKey'] = $m[1];
        $lastBackslash = strrpos($m[1], '\\');
        $lastDot = strrpos($m[1], '.');
        $sep = $lastBackslash !== false ? $lastBackslash : $lastDot;
        $xaml['namespace'] = $sep !== false ? substr($m[1], 0, $sep) : '';
        $xaml['className'] = $sep !== false ? substr($m[1], $sep + 1) : $m[1];
    } else {
        $xaml['classKey'] = '';
        $xaml['namespace'] = '';
        $xaml['className'] = '';
    }
    $xamlResources[$relativePath] = $xaml;
}
$docData['xamlFiles'] = $xamlResources;
echo "Extracted " . count($xamlResources) . " XAML resources\n";

ksort($docData['namespaces']);
foreach ($docData['namespaces'] as &$classes) {
    sort($classes);
}
unset($classes);
ksort($docData['classes']);

$totalLines = 0;
foreach ($csFiles as $filePath) {
    $content = file_get_contents($filePath);
    if ($content !== false) $totalLines += substr_count($content, "\n") + 1;
}
$typeCounts = ['class' => 0, 'struct' => 0, 'interface' => 0, 'enum' => 0];
$memberCounts = ['method' => 0, 'property' => 0, 'field' => 0, 'constructor' => 0, 'destructor' => 0];
$projectNames = [];
$totalBytes = 0;
$projectBytes = [];
foreach ($docData['classes'] as $c) {
    $typeCounts[$c['kind']]++;
    foreach ($c['members'] as $m) $memberCounts[$m['kind']]++;
    $proj = explode('\\', $c['file'])[0];
    $projectNames[$proj] = true;
}
foreach ($csFiles as $filePath) {
    $size = filesize($filePath);
    $totalBytes += $size;
    $rel = str_replace($rootDir . DIRECTORY_SEPARATOR, '', $filePath);
    $proj = explode(DIRECTORY_SEPARATOR, $rel)[0];
    $projectBytes[$proj] = ($projectBytes[$proj] ?? 0) + $size;
    $projectNames[$proj] = true;
}
$xamlCount = count($xamlFiles);
$xamlTotalLines = 0;
$xamlTotalBytes = 0;
foreach ($xamlFiles as $filePath) {
    $size = filesize($filePath);
    $rel = str_replace($rootDir . DIRECTORY_SEPARATOR, '', $filePath);
    $content = file_get_contents($filePath);
    if ($content !== false) $xamlTotalLines += substr_count($content, "\n") + 1;
    $xamlTotalBytes += $size;
    $proj = explode(DIRECTORY_SEPARATOR, $rel)[0];
    $projectBytes[$proj] = ($projectBytes[$proj] ?? 0) + $size;
    $projectNames[$proj] = true;
}
$docData['stats'] = [
    'sourceRoot'     => realpath($rootDir),
    'totalFiles'     => count($csFiles) + $xamlCount,
    'csFiles'        => count($csFiles),
    'xamlFiles'      => $xamlCount,
    'totalTypes'     => count($docData['classes']),
    'totalMembers'   => array_sum($memberCounts),
    'totalLines'     => $totalLines + $xamlTotalLines,
    'totalBytes'     => $totalBytes + $xamlTotalBytes,
    'csLines'        => $totalLines,
    'csBytes'        => $totalBytes,
    'xamlLines'      => $xamlTotalLines,
    'xamlBytes'      => $xamlTotalBytes,
    'projectBytes'   => $projectBytes,
    'typeCounts'     => $typeCounts,
    'memberCounts'   => $memberCounts,
    'projectCount'   => count($projectNames),
    'namespaceCount' => count($docData['namespaces']),
];

file_put_contents($outputFile, json_encode($docData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo "Done! " . count($docData['classes']) . " types, " . array_sum(array_map(fn($c) => count($c['members']), $docData['classes'])) . " members, $totalLines LOC documented.\n";

function extractAccess(string $text): string {
    if (preg_match('/\b(public|private|internal|protected)\b/', $text, $m)) return $m[1];
    return '';
}

function removeStrings($code): string {
    if (!is_string($code)) return '';
    $code = str_replace("\r", '', $code);
    $code = (string)preg_replace('/\$?@"(?:[^"]|"")*"/', '" "', $code);
    $code = (string)preg_replace('/\$"(?:[^"\\\\{}]|\\\\.|\{\{|\}\})*"/', '" "', $code);
    $code = (string)preg_replace('/"(?:[^"\\\\]|\\\\.)*"/', '" "', $code);
    $code = (string)preg_replace("/'(?:[^'\\\\]|\\\\.)'/", "' '", $code);
    $code = (string)preg_replace_callback('/\/\*.*?\*\//s', fn($m) => str_repeat("\n", substr_count($m[0], "\n")), $code);
    return $code;
}

function extractDocComments(array $lines): array {
    $result = [];
    $count = count($lines);
    $i = 0;
    while ($i < $count) {
        if (preg_match('/^\s*\/\/\/\s*(.*)$/', $lines[$i], $m)) {
            $parts = [trim($m[1])];
            $j = $i + 1;
            while ($j < $count && preg_match('/^\s*\/\/\/\s*(.*)$/', $lines[$j], $m2)) {
                $parts[] = trim($m2[1]);
                $j++;
            }
            $result[$j] = implode(' ', $parts);
            $i = $j;
        } else {
            $i++;
        }
    }
    return $result;
}

function findOriginalBodyStart(string $code, string $className): ?int {
    // Find 'class Name' or 'struct Name' etc in original code
    $pattern = '/(?:(?:class|struct|interface|enum)\s+)' . preg_quote($className, '/') . '/';
    if (!preg_match($pattern, $code, $m, PREG_OFFSET_CAPTURE)) return null;
    $afterName = $m[0][1] + strlen($m[0][0]);
    $brace = strpos($code, '{', $afterName);
    if ($brace === false) return null;
    return $brace + 1;
}

function parseTypes(string $code, array $docComments, string $namespace, string $filePath): array {
    $stripped = removeStrings($code);
    $types = [];

    $modPat = '(?:(?:public|internal|private|protected|new|unsafe)\s+)?(?:static|abstract|sealed|partial|readonly|unsafe)?\s*';
    $pattern = '/(?:^|\n)\s*(' . $modPat . '(?:class|struct|interface|enum)\s+(\w+)(?:\s*:\s*([^{]+?))?)\s*\{/m';

    if (preg_match_all($pattern, $stripped, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($matches as $match) {
            $declText = trim($match[1][0]);
            $name = $match[2][0];
            $offset = (int)$match[0][1];
            $lineNo = substr_count(substr($code, 0, $offset), "\n") + 1;

            $access = 'internal';
            if (preg_match('/\b(public|private|protected|internal)\b/', $declText, $am)) {
                $access = $am[1];
            }

            $kind = 'class';
            if (strpos($declText, ' struct ') !== false) $kind = 'struct';
            elseif (strpos($declText, ' interface ') !== false) $kind = 'interface';
            elseif (strpos($declText, ' enum ') !== false) $kind = 'enum';

            $isStatic = (strpos($declText, ' static ') !== false);

            $baseTypes = [];
            if (!empty($match[3][0])) {
                $base = trim($match[3][0]);
                $base = preg_replace('/\bwhere\s+.*/s', '', $base);
                $baseTypes = array_map('trim', explode(',', $base));
            }

            $origBodyStart = findOriginalBodyStart($code, $name);
            $originalClassBody = $origBodyStart !== null ? extractClassBody($code, $origBodyStart) : '';

            $strippedBodyStart = $offset + strlen($match[0][0]);
            $strippedClassBody = $originalClassBody !== ''
                ? removeStrings($originalClassBody)
                : extractClassBody($stripped, $strippedBodyStart);

            $members = [];
            if ($strippedClassBody !== null) {
                if ($kind === 'enum') {
                    $members = parseEnumMembers($strippedClassBody);
                } else {
                    $members = parseClassMembers($strippedClassBody, $originalClassBody ?? '', $name);
                }
            }
            if (!empty($originalClassBody) && !empty($members)) {
                $origBodyLines = explode("\n", $originalClassBody);
                foreach ($members as &$member) {
                    $member['doc'] = extractMemberDoc($originalClassBody, $origBodyLines, $member['name'], $member['kind']);
                    if (!empty($member['code'])) {
                        $member['code'] = preg_replace('/^\s*\/\/\/.*$/m', '', $member['code']);
                        $member['code'] = preg_replace('/\n{3,}/', "\n\n", $member['code']);
                        $member['code'] = trim($member['code']);
                    }
                }
                unset($member);
            }

            $doc = '';
            foreach ($docComments as $dLine => $dText) {
                if ($dLine === $lineNo || $dLine === $lineNo - 1) {
                    $doc = $dText;
                    break;
                }
            }
            $cleanDoc = cleanDoc($doc);

            $types[] = [
                'namespace' => $namespace,
                'name'      => $name,
                'kind'      => $kind,
                'access'    => $access,
                'static'    => $isStatic,
                'baseTypes' => $baseTypes,
                'file'      => $filePath,
                'line'      => $lineNo,
                'doc'       => $cleanDoc,
                'members'   => $members,
            ];
        }
    }

    return $types;
}

function extractClassBody(string $code, int $startPos): ?string {
    $len = strlen($code);
    if ($startPos >= $len) return null;
    $depth = 1;
    $body = '';
    for ($i = $startPos; $i < $len; $i++) {
        $ch = $code[$i];
        if ($ch === '{') { $depth++; $body .= $ch; }
        elseif ($ch === '}') { $depth--; if ($depth === 0) return $body; $body .= $ch; }
        else { $body .= $ch; }
    }
    return null;
}

function parseEnumMembers(string $body): array {
    $members = [];
    if (preg_match_all('/^\s*(\w+)\s*(?:=\s*([^,}]+))?/m', $body, $m, PREG_SET_ORDER)) {
        $currentValue = null;
        foreach ($m as $match) {
            $name = trim($match[1]);
            if (!empty($name)) {
                $code = trim($match[0]);
                if (isset($match[2]) && $match[2] !== '') {
                    $expr = trim($match[2]);
                    if (preg_match('/^[+-]?(0x[0-9a-fA-F]+|\d+)$/', $expr)) {
                        $currentValue = stripos($expr, '0x') === 0 ? hexdec($expr) : (int)$expr;
                    } else {
                        $currentValue = null;
                    }
                } else {
                    $currentValue = $currentValue !== null ? $currentValue + 1 : 0;
                    $code .= ' = ' . $currentValue;
                }
                $members[] = ['kind' => 'field', 'name' => $name, 'type' => '', 'doc' => '', 'line' => 0, 'code' => $code];
            }
        }
    }
    return $members;
}

function parseClassMembers(string $strippedBody, string $originalBody, string $className): array {
    $members = [];
    $modifierSet = ['public','internal','private','protected','static','virtual','override','abstract','async','unsafe','new','sealed','extern','partial','readonly','volatile'];
    $modPat = '(?:public|internal|private|protected|static|virtual|override|abstract|async|unsafe|new|sealed|extern|partial|readonly|volatile)\s+';
    $blockedRanges = [];

    $nestPat = '/(?:^|\n)\s*(?:(?:public|internal|private|protected|new|unsafe)\s+)*(?:static|abstract|sealed|partial|readonly|unsafe)?\s*(?:class|struct|interface|enum)\s+\w+/m';
    if (preg_match_all($nestPat, $strippedBody, $nm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($nm as $n) {
            $bracePos = strpos($strippedBody, '{', (int)$n[0][1]);
            if ($bracePos === false) continue;
            $blockEnd = findBlockEnd($strippedBody, $bracePos);
            if ($blockEnd !== null) $blockedRanges[] = [(int)$n[0][1], $blockEnd];
        }
    }

    $propPattern = '/(?:^|\n)\s*((?:(?:public|internal|private|protected|static|virtual|override|abstract|new|sealed|unsafe)\s+)*)([\w\[\]<>,\[\]?]+)\s+(\w+)\s*\{/m';
    if (preg_match_all($propPattern, $strippedBody, $pm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($pm as $p) {
            $pName = $p[3][0];
            if (in_array($pName, ['get', 'set', 'add', 'remove', 'init'])) continue;
            $blockStart = $p[0][1] + strlen($p[0][0]) - 1;
            $blockEnd = findBlockEnd($strippedBody, $blockStart);
            if ($blockEnd !== null) $blockedRanges[] = [$p[0][1], $blockEnd];
        }
    }
    $methodPattern = '/\b(\w+)\s*\(/';
    preg_match_all($methodPattern, $strippedBody, $rawMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($rawMatches as $m) {
        $mName = $m[1][0];
        if ($mName === $className) continue;
        if (in_array($mName, ['get','set','add','remove','init'])) continue;
        if (preg_match('/^(if|for|while|switch|case|return|throw|new|using|foreach|lock|fixed|stackalloc|base|this|yield|sizeof|typeof|nameof|catch|break|continue|default|else|do|try|finally|in|out|ref|var|let|const)$/', $mName)) continue;
        $parenStart = (int)$m[1][1] + strlen($mName);
        $openParen = strpos($strippedBody, '(', $parenStart);
        if ($openParen === false || $openParen > $parenStart + 5) continue;
        $paramsStr = extractParenContent($strippedBody, $openParen);
        if ($paramsStr === null) continue;
        $afterClose = $openParen + strlen($paramsStr) + 2;
        $nextChars = substr($strippedBody, $afterClose, 10);
        if (!preg_match('/^\s*\{/', $nextChars)) continue;
        // Adjust afterClose to the {
        $bracePos = strpos($strippedBody, '{', $afterClose);
        if ($bracePos === false) continue;
        $blockEnd = findBlockEnd($strippedBody, $bracePos);
        if ($blockEnd !== null) $blockedRanges[] = [(int)$m[1][1], $blockEnd];
    }

    $fieldPattern = '/(?:^|\n)\s*(?!(?:return|throw|if|for|while|switch|case|break|continue|using|namespace|#|else|do|try|catch|finally|fixed|stackalloc|lock|foreach|yield|base|this|sizeof|typeof|nameof|default))\s*((?:(?:public|internal|private|protected|static|readonly|volatile|new|unsafe)\s+)*(?:[\w\[\]<>,\[\]?]+)\s+(\w+(?:\s*,\s*\w+)*)\s*(?:=\s*[^;]+)?\s*;)/m';
    if (preg_match_all($fieldPattern, $strippedBody, $fm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($fm as $f) {
            $fNames = preg_split('/\s*,\s*/', $f[2][0]);
            $fOffset = (int)$f[0][1];
            $inBlock = false; foreach ($blockedRanges as $br) { if ($fOffset >= $br[0] && $fOffset <= $br[1]) { $inBlock = true; break; } }
            if ($inBlock) continue;
            $bd = 0; for ($bi = 0; $bi < $fOffset && $bi < strlen($strippedBody); $bi++) { if ($strippedBody[$bi] === '{') $bd++; elseif ($strippedBody[$bi] === '}') $bd--; }
            if ($bd !== 0) continue;
            $decl = trim($f[1][0]);
            $declBeforeEq = strstr($decl, '=', true);
            if ($declBeforeEq === false) $declBeforeEq = $decl;
            if (preg_match('/[\({\]=>]/', $declBeforeEq)) continue;
            $typeStr = preg_replace('/\b(public|internal|private|protected|static|readonly|volatile|new|unsafe|sealed|virtual|override|abstract|async|partial|event)\s*/', '', $decl);
            $typeStr = preg_replace('/\s*=\s*[^;]+/', '', $typeStr);
            $typeStr = preg_replace('/\s+\w+(?:\s*,\s*\w+)*\s*;?\s*$/', '', $typeStr);
            $typeStr = trim($typeStr);
            if (empty($typeStr) || strlen($typeStr) > 50) continue;
            if (in_array($typeStr, ['var', 'return', 'throw', 'if', 'for', 'while', 'switch', 'case', 'break', 'continue', 'using', 'foreach', 'lock', 'fixed', 'stackalloc', 'catch', 'yield'])) continue;
            $access = extractAccess($decl);
            $mods = preg_replace('/\s+/', ' ', trim(preg_replace('/\b' . preg_quote($typeStr, '/') . '\b.*/s', '', $decl)));
            foreach ($fNames as $fi => $fName) {
                if (empty($fName) || strlen($fName) > 60) continue;
                if (in_array($fName, ['true','false','null','return','this','base'])) continue;
                $val = '';
                if (preg_match('/' . preg_quote($fName, '/') . '\s*=\s*([^;,]+)/', $decl, $vMatch)) {
                    $val = ' = ' . trim($vMatch[1]);
                }
                $code = $mods . ' ' . $typeStr . ' ' . $fName . $val . ';';
                $members[] = ['kind' => 'field', 'name' => $fName, 'type' => $typeStr, 'doc' => '', 'line' => 0, 'code' => $code, 'access' => $access];
            }
        }
    }

    $propPattern = '/(?:^|\n)\s*((?:(?:public|internal|private|protected|static|virtual|override|abstract|new|sealed|unsafe)\s+)*)([\w\[\]<>,\[\]?]+)\s+(\w+)\s*\{/m';
    if (preg_match_all($propPattern, $strippedBody, $pm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($pm as $p) {
            $pName = $p[3][0];
            if (in_array($pName, ['get', 'set', 'add', 'remove', 'init'])) continue;
            $blockStart = $p[0][1] + strlen($p[0][0]) - 1;
            $propBlock = extractBraceBlock($strippedBody, $blockStart);
            if ($propBlock === null) continue;
            if (!preg_match('/\b(get|set)\b/', $propBlock)) continue;
            $typeStr = trim($p[2][0]);
            $code = extractCodeForMember($originalBody, $strippedBody, (int)$p[0][1], $pName, 'property');
            $members[] = ['kind' => 'property', 'name' => $pName, 'type' => $typeStr, 'doc' => '', 'line' => 0, 'code' => $code, 'access' => extractAccess($p[1][0])];
        }
    }

    $exprPropPattern = '/(?:^|\n)\s*(?:(?:public|internal|private|protected|static|virtual|override|new)\s+)*([\w\[\]<>,\[\]?]+)\s+(\w+)\s*=>\s*[^;]+;/m';
    if (preg_match_all($exprPropPattern, $strippedBody, $epm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($epm as $ep) {
            $epName = $ep[2][0];
            $found = false;
            foreach ($members as $m) {
                if ($m['name'] === $epName && $m['kind'] === 'property') { $found = true; break; }
            }
            if (!$found) {
                $typeStr = trim($ep[1][0]);
                $code = extractCodeForMember($originalBody, $strippedBody, (int)$ep[0][1], $epName, 'property');
                $members[] = ['kind' => 'property', 'name' => $epName, 'type' => $typeStr, 'doc' => '', 'line' => 0, 'code' => $code, 'access' => extractAccess($ep[0][0])];
            }
        }
    }

    $methodPattern = '/\b(\w+)\s*\(/';
    preg_match_all($methodPattern, $strippedBody, $rawMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($rawMatches as $m) {
        $mName = $m[1][0];
        $nameOffset = (int)$m[1][1];

        if ($mName === $className) continue;
        if (in_array($mName, ['get', 'set', 'add', 'remove', 'init'])) continue;
        if (strlen($mName) > 60) continue;
        if (preg_match('/^(if|for|while|switch|case|return|throw|new|using|foreach|lock|fixed|stackalloc|base|this|yield|sizeof|typeof|nameof|catch|break|continue|default|else|do|try|finally|in|out|ref|var|let|const)$/', $mName)) continue;

        $lineStart = strrpos(substr($strippedBody, 0, $nameOffset), "\n");
        if ($lineStart === false) $lineStart = 0; else $lineStart++;
        $prelude = trim(substr($strippedBody, $lineStart, $nameOffset - $lineStart));
        $prelude = preg_replace('/\s+' . preg_quote($mName, '/') . '$/', '', $prelude);

        if (strpos($prelude, '.') !== false) continue;
        if (strpos($prelude, '=') !== false) continue;
        if (preg_match('/=>/', $prelude)) continue;
        if (preg_match('/^\s*new\s/', $prelude)) continue;

        $tokens = preg_split('/\s+/', $prelude);
        $returnTokens = [];
        $foundNonModifier = false;
        foreach ($tokens as $t) {
            $clean = trim($t);
            if (!$foundNonModifier && in_array($clean, $modifierSet)) {
            } else {
                $foundNonModifier = true;
                if ($clean !== '') $returnTokens[] = $clean;
            }
        }
        if (count($returnTokens) === 0) continue;

        $mReturn = implode(' ', $returnTokens);
        if (empty($mReturn) || $mReturn === $mName) $mReturn = 'void';

        $firstToken = $returnTokens[0] ?? '';
        if (in_array($firstToken, ['if','for','while','switch','case','return','throw','new','using','foreach','lock','fixed','stackalloc','catch','break','continue','default','sizeof','typeof','nameof','yield','base','this','else','do','try','finally','in','out','ref','var','let','const','delegate','event','where','async','await'])) continue;

        $parenStart = $nameOffset + strlen($mName);
        $openParen = strpos($strippedBody, '(', $parenStart);
        if ($openParen === false || $openParen > $parenStart + 5) continue;
        $paramsStr = extractParenContent($strippedBody, $openParen);
        if ($paramsStr === null) continue;

        $afterClose = $openParen + strlen($paramsStr) + 2;
        $nextChars = substr($strippedBody, $afterClose, 10);
        if (!preg_match('/^\s*[\{=>;]/', $nextChars)) continue;

        $params = [];
        if (trim($paramsStr) !== '') {
            $paramParts = preg_split('/,(?=(?:[^<>]*[<>][^<>]*)*[^<>]*$)(?=(?:[^()]*\([^()]*\))*[^()]*$)/', $paramsStr);
            foreach ($paramParts as $pp) {
                $pp = trim($pp);
                if (empty($pp)) continue;
                $ppClean = preg_replace('/\b(in|out|ref|params|this)\s+/', '', $pp);
                $ppClean = preg_replace('/\s*=.*$/', '', $ppClean);
                $parts = preg_split('/\s+/', $ppClean);
                if (count($parts) >= 2) {
                    $pType = implode(' ', array_slice($parts, 0, -1));
                    $pName = end($parts);
                    $params[] = ['type' => $pType, 'name' => ltrim($pName, '@')];
                }
            }
        }

        $code = extractCodeForMember($originalBody, $strippedBody, $nameOffset, $mName, 'method');

        $members[] = [
            'kind'       => 'method',
            'name'       => $mName,
            'returnType' => $mReturn,
            'params'     => $params,
            'doc'        => '',
            'line'       => 0,
            'code'       => $code,
            'access'     => extractAccess($prelude),
        ];
    }

    $ctorPattern = '/\b(' . preg_quote($className, '/') . ')\s*\(/';
    if (preg_match_all($ctorPattern, $strippedBody, $cm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($cm as $c) {
            $parenStart = $c[0][1] + strlen($className);
            $openParen = strpos($strippedBody, '(', $parenStart);
            if ($openParen === false || $openParen > $parenStart + 5) continue;
            $paramsStr = extractParenContent($strippedBody, $openParen);
            if ($paramsStr === null) continue;
            $afterClose = $openParen + strlen($paramsStr) + 2;
            $nextChars = substr($strippedBody, $afterClose, 10);
            if (!preg_match('/^\s*[\{:]/', $nextChars)) continue;

            $params = [];
            if (trim($paramsStr) !== '') {
                $paramParts = explode(',', $paramsStr);
                foreach ($paramParts as $pp) {
                    $pp = trim($pp);
                    if (empty($pp)) continue;
                    $ppClean = preg_replace('/\b(in|out|ref|params|this)\s+/', '', $pp);
                    $ppClean = preg_replace('/\s*=.*$/', '', $ppClean);
                    $parts = preg_split('/\s+/', $ppClean);
                    if (count($parts) >= 2) {
                        $pType = implode(' ', array_slice($parts, 0, -1));
                        $pName = end($parts);
                        $params[] = ['type' => $pType, 'name' => ltrim($pName, '@')];
                    }
                }
            }

            $code = extractCodeForMember($originalBody, $strippedBody, (int)$c[0][1], $className, 'constructor');

            $lineStart2 = strrpos(substr($strippedBody, 0, (int)$c[0][1]), "\n");
            $ctorPrelude = $lineStart2 === false ? '' : trim(substr($strippedBody, $lineStart2, (int)$c[0][1] - $lineStart2));
            $members[] = ['kind' => 'constructor', 'name' => $className, 'params' => $params, 'doc' => '', 'line' => 0, 'code' => $code, 'access' => extractAccess($ctorPrelude)];
        }
    }

    if (preg_match('/~(' . preg_quote($className, '/') . ')\s*\(/', $strippedBody, $destrM, PREG_OFFSET_CAPTURE)) {
        $destrOffset = (int)$destrM[0][1];
        $lineStart3 = strrpos(substr($strippedBody, 0, $destrOffset), "\n");
        $destrPrelude = $lineStart3 === false ? '' : trim(substr($strippedBody, $lineStart3, $destrOffset - $lineStart3));
        $members[] = ['kind' => 'destructor', 'name' => '~' . $className, 'params' => [], 'doc' => '', 'line' => 0, 'code' => '', 'access' => extractAccess($destrPrelude)];
    }

    $members = deduplicateMembers($members);

    $order = ['constructor' => 0, 'destructor' => 1, 'property' => 2, 'field' => 3, 'method' => 4];
    usort($members, function ($a, $b) use ($order) {
        $oa = $order[$a['kind']] ?? 99;
        $ob = $order[$b['kind']] ?? 99;
        if ($oa !== $ob) return $oa - $ob;
        return strcmp($a['name'], $b['name']);
    });

    return $members;
}

function extractCodeForMember(string $originalBody, string $strippedBody, $strippedOffset, string $memberName, string $kind): string {
    if (empty($originalBody) || empty($memberName)) return '';

    $modPat = '(?:public|internal|private|protected|static|virtual|override|abstract|async|unsafe|new|sealed|extern|partial|readonly|volatile)\s+';

    if ($kind === 'field') {
        $fieldPat = '/(?:^|\n)\s*(?:\[[^\]]*\]\s*)*(?:\/\/\/[^\n]*\n\s*)*(?:' . $modPat . ')*(?:[\w\[\]<>,\[\]?]+)\s+' . preg_quote($memberName, '/') . '\s*(?:=|\b)/m';
        if (preg_match($fieldPat, $originalBody, $sm, PREG_OFFSET_CAPTURE)) {
            $declStart = (int)$sm[0][1];
            $lineStart = strrpos(substr($originalBody, 0, $declStart), "\n");
            if ($lineStart === false) $lineStart = 0;
            $nextNewline = strpos($originalBody, "\n", $declStart);
            if ($nextNewline !== false) return trim(substr($originalBody, $lineStart, $nextNewline - $lineStart));
        }
        return '';
    }

    if ($kind === 'method' || $kind === 'constructor') {
        $pattern = '/(?:^|\n)\s*(?:\[[^\]]*\]\s*)*(?:\/\/\/[^\n]*\n\s*)*(?:' . $modPat . ')*(?:[\w\[\]<>,\[\]]+\s+)?' . preg_quote($memberName, '/') . '\s*\(/m';
    } else {
        $pattern = '/(?:^|\n)\s*(?:\[[^\]]*\]\s*)*(?:\/\/\/[^\n]*\n\s*)*(?:' . $modPat . ')*(?:[\w\[\]<>,\[\]]+\s+)?' . preg_quote($memberName, '/') . '\s*(?:\{|=>)/m';
    }

    $targetOffset = max(0, (int)$strippedOffset);
    if (!preg_match_all($pattern, $originalBody, $allMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return '';
    $bestMatch = null;
    $bestDist = PHP_INT_MAX;
    foreach ($allMatches as $am) {
        $dist = abs((int)$am[0][1] - $targetOffset);
        if ($dist < $bestDist) { $bestDist = $dist; $bestMatch = $am; }
    }
    $declStart = (int)$bestMatch[0][1];
    $matchText = $bestMatch[0][0];
    $declStart += strspn($matchText, "\n\r \t");
    $lineStart = strrpos(substr($originalBody, 0, $declStart), "\n");
    if ($lineStart === false) $lineStart = 0;

    if (in_array($kind, ['method', 'constructor'])) {
        $openParen = strpos($originalBody, '(', $declStart);
        if ($openParen === false || $openParen > $declStart + 100) return '';

        $depth = 1; $i = $openParen + 1; $len = strlen($originalBody);
        while ($i < $len && $depth > 0) {
            if ($originalBody[$i] === '(') $depth++;
            if ($originalBody[$i] === ')') $depth--;
            $i++;
        }
        $afterParen = $i;
        while ($afterParen < $len && ($originalBody[$afterParen] === ' ' || $originalBody[$afterParen] === "\n" || $originalBody[$afterParen] === "\r")) $afterParen++;

        if ($afterParen < $len && substr($originalBody, $afterParen, 2) === '=>') {
            $semi = strpos($originalBody, ';', $afterParen + 2);
            if ($semi !== false) return trim(substr($originalBody, $lineStart, $semi - $lineStart + 1));
            return '';
        }
        if ($afterParen < $len && $originalBody[$afterParen] === '{') {
            $block = extractClassBody($originalBody, $afterParen + 1);
            if ($block !== null) {
                return trim(substr($originalBody, $lineStart, $afterParen + 2 + strlen($block) - $lineStart));
            }
        }
        if ($kind === 'constructor' && $afterParen < $len && $originalBody[$afterParen] === ':') {
            $semiOrBrace = strpos($originalBody, '{', $afterParen);
            if ($semiOrBrace !== false) {
                $block = extractClassBody($originalBody, $semiOrBrace + 1);
                if ($block !== null) {
                    return trim(substr($originalBody, $lineStart, $semiOrBrace + 2 + strlen($block) - $lineStart));
                }
            }
        }
        $semi = strpos($originalBody, ';', $declStart);
        if ($semi !== false && $semi < $lineStart + 200) return trim(substr($originalBody, $lineStart, $semi - $lineStart + 1));
        return '';
    }

    if ($kind === 'property') {
        $openBrace = strpos($originalBody, '{', $declStart);
        if ($openBrace !== false) {
            $block = extractClassBody($originalBody, $openBrace + 1);
            if ($block !== null) {
                return trim(substr($originalBody, $lineStart, $openBrace + 2 + strlen($block) - $lineStart));
            }
        }
        if (preg_match('/=>\s*([^;]+);/', substr($originalBody, $declStart), $em)) {
            $semi = strpos($originalBody, ';', $declStart);
            if ($semi !== false) return trim(substr($originalBody, $lineStart, $semi - $lineStart + 1));
        }
        return '';
    }

    $nextNewline = strpos($originalBody, "\n", $declStart);
    if ($nextNewline !== false) return trim(substr($originalBody, $lineStart, $nextNewline - $lineStart));
    return '';
}

function deduplicateMembers(array $members): array {
    $seen = [];
    $result = [];
    foreach ($members as $m) {
        $key = $m['kind'] . '|' . $m['name'];
        if ($m['kind'] === 'method' || $m['kind'] === 'constructor') {
            $paramStr = '';
            foreach ($m['params'] ?? [] as $p) {
                $paramStr .= ($paramStr ? ',' : '') . ($p['type'] ?? '');
            }
            $key .= '|' . $paramStr;
        }
        if (empty($m['name']) || strlen($m['name']) > 80) continue;
        if (in_array($m['name'], ['true', 'false', 'null', 'return', 'this', 'base'])) continue;
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $result[] = $m;
        }
    }
    return $result;
}

function extractMemberDoc(string $body, array $lines, string $name, string $kind): string {
    if (empty($body) || empty($lines) || empty($name)) return '';
    $modPat = '(?:public|internal|private|protected|static|virtual|override|abstract|async|unsafe|new|sealed|extern|partial|readonly|volatile)\s+';
    if ($kind === 'field') {
        $pat = '/(?:^|\n)\s*(?:\[[^\]]*\]\s*)*(?:' . $modPat . ')*(?:[\w\[\]<>,\[\]?]+)\s+' . preg_quote($name, '/') . '\s*(?:=|;)/m';
    } elseif (in_array($kind, ['method', 'constructor'])) {
        $pat = '/(?:^|\n)\s*(?:\[[^\]]*\]\s*)*(?:' . $modPat . ')*(?:[\w\[\]<>,\[\]]+\s+)?' . preg_quote($name, '/') . '\s*\(/m';
    } else {
        $pat = '/(?:^|\n)\s*(?:\[[^\]]*\]\s*)*(?:' . $modPat . ')*(?:[\w\[\]<>,\[\]]+\s+)?' . preg_quote($name, '/') . '\s*(?:\{|=>)/m';
    }
    if (!preg_match($pat, $body, $sm, PREG_OFFSET_CAPTURE)) return '';
    $offset = (int)$sm[0][1];
    $lineNum = substr_count(substr($body, 0, $offset), "\n");
    $docParts = [];
    for ($i = $lineNum - 1; $i >= 0; $i--) {
        $tl = trim($lines[$i] ?? '');
        if (strpos($tl, '///') === 0) {
            $docParts[] = trim(substr($tl, 3));
        } elseif ($tl === '' || $tl[0] === '[') {
            continue;
        } else {
            break;
        }
    }
    if (empty($docParts)) return '';
    $docParts = array_reverse($docParts);
    return cleanDoc(implode("\n", $docParts));
}

function findBlockEnd(string $code, int $bracePos): ?int {
    $len = strlen($code);
    if ($bracePos >= $len || $code[$bracePos] !== '{') return null;
    $depth = 1;
    for ($i = $bracePos + 1; $i < $len; $i++) {
        if ($code[$i] === '{') $depth++;
        elseif ($code[$i] === '}') { $depth--; if ($depth === 0) return $i; }
    }
    return null;
}

function extractParenContent(string $code, int $openPos): ?string {
    $len = strlen($code);
    $depth = 1;
    $content = '';
    for ($i = $openPos + 1; $i < $len; $i++) {
        $ch = $code[$i];
        if ($ch === '(') { $depth++; if ($depth > 1) $content .= $ch; }
        elseif ($ch === ')') { $depth--; if ($depth === 0) return $content; $content .= $ch; }
        else { $content .= $ch; }
    }
    return null;
}

function extractBraceBlock(string $code, int $openPos): ?string {
    $len = strlen($code);
    $depth = 1;
    $content = '';
    for ($i = $openPos + 1; $i < $len; $i++) {
        $ch = $code[$i];
        if ($ch === '{') { $depth++; $content .= $ch; }
        elseif ($ch === '}') { $depth--; if ($depth === 0) return $content; $content .= $ch; }
        else { $content .= $ch; }
    }
    return null;
}

function cleanDoc(string $doc): string {
    if ($doc === '' || $doc === null) return '';
    if (preg_match('/<summary>\s*(.*?)\s*<\/summary>/s', $doc, $m)) {
        return trim(preg_replace('/<[^>]+>/', '', $m[1]));
    }
    return trim(preg_replace('/<[^>]+>/', '', $doc));
}
