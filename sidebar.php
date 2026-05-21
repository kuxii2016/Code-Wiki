<?php
function _countDeep($n) {
    $c = count($n['__classes'] ?? []);
    foreach ($n['__children'] ?? [] as $ch) $c += _countDeep($ch);
    return $c;
}

function _renderNsNode($name, $node, $activeNs, $activeClass, $prefix, $depth) {
    $ns = $prefix === '' ? $name : "$prefix.$name";
    $open = $activeNs !== null && ($activeNs === $ns || strpos($activeNs, $ns . '.') === 0);
    $total = _countDeep($node);
    if ($total === 0) return;
    $padNs = (1 + $depth * 1.5) . 'rem';
    $padCl = (2.5 + $depth * 1.5) . 'rem';
    ?><div class="tree-ns">
      <div class="ns-header<?= $open ? ' open' : '' ?>" style="padding-left:<?= $padNs ?>" onclick="this.classList.toggle('open')">
        <span class="arrow">▶</span><?= e($name) ?><span class="tree-count"><?= $total ?></span>
      </div>
      <div class="ns-children">
        <?php foreach ($node['__classes'] as $c): ?>
        <a href="class.php?c=<?= urlencode($c['key']) ?>" class="tree-class<?= ($activeClass === $c['key']) ? ' active' : '' ?>" style="padding-left:<?= $padCl ?>">
          <span class="kind-dot <?= $c['kind'] ?>"></span><?= e($c['name']) ?>
        </a>
        <?php endforeach;
        ksort($node['__children']);
        foreach ($node['__children'] as $cn => $cv) _renderNsNode($cn, $cv, $activeNs, $activeClass, $ns, $depth + 1); ?>
      </div>
    </div><?php
}

$tree = [];
foreach ($data['classes'] as $key => $c) {
    $proj = explode('/', str_replace('\\', '/', $c['file']))[0] ?? '';
    $clsNs = $c['namespace'];
    if ($clsNs === '') continue;
    $parts = explode('.', $clsNs);
    if (!isset($tree[$proj])) $tree[$proj] = ['__children' => [], '__classes' => []];
    $ref = &$tree[$proj];
    foreach ($parts as $part) {
        if (!isset($ref['__children'][$part])) {
            $ref['__children'][$part] = ['__children' => [], '__classes' => []];
        }
        $ref = &$ref['__children'][$part];
    }
    $ref['__classes'][] = ['key' => $key, 'name' => $c['name'], 'kind' => $c['kind']];
    unset($ref);
}
ksort($tree);

$activeProj = '';
if (!empty($treeActiveNs)) {
    foreach ($data['classes'] as $k => $c) {
        if ($c['namespace'] === $treeActiveNs) {
            $activeProj = explode('/', str_replace('\\', '/', $c['file']))[0] ?? '';
            break;
        }
    }
}
$treeActiveNs ??= null;
$treeActiveClass ??= null;
?>
<div class="sidebar-section">Projects</div>
<nav>
<?php foreach ($tree as $proj => $root):
$total = _countDeep($root);
if ($total === 0) continue;
?>
<div class="project-group">
  <div class="project-header<?= $proj === $activeProj ? ' open' : '' ?>" onclick="this.classList.toggle('open')">
    <span class="arrow">▶</span><?= e($proj) ?><span style="margin-left:auto;font-size:0.65rem;color:#64748b;font-weight:400"><?= $total ?></span>
  </div>
  <div class="project-children">
    <?php
    ksort($root['__children']);
    foreach ($root['__children'] as $cn => $cv) _renderNsNode($cn, $cv, $treeActiveNs, $treeActiveClass, '', 1);
    foreach ($root['__classes'] as $c): ?>
    <a href="class.php?c=<?= urlencode($c['key']) ?>" class="tree-class<?= $treeActiveClass === $c['key'] ? ' active' : '' ?>" style="padding-left:4rem">
      <span class="kind-dot <?= $c['kind'] ?>"></span><?= e($c['name']) ?>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>
</nav>
