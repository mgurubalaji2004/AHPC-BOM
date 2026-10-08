<?php
$route = '/' . trim((string)($_GET['r'] ?? '/'), '/');
$theme = current_theme();
$icon = function (string $name): string {
    $p = [
        'home' => '<path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
        'tracker' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4M7 13h4M7 17h7"/>',
        'req' => '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M8 13h8M8 17h5"/>',
        'bom' => '<path d="M21 8 12 3 3 8l9 5z"/><path d="m3 13 9 5 9-5M3 17.5l9 5 9-5"/>',
        'quote' => '<path d="M6 2h9l5 5v15H6z"/><path d="M14 2v6h6M9 13h7M9 17h7M9 9h2"/>',
        'comp' => '<rect x="5" y="5" width="14" height="14" rx="2"/><rect x="9" y="9" width="6" height="6"/><path d="M9 2v3M15 2v3M9 19v3M15 19v3M2 9h3M2 15h3M19 9h3M19 15h3"/>',
        'cust' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>',
        'chart' => '<path d="M3 3v18h18"/><path d="M7 15v3M12 10v8M17 6v12"/>',
        'users' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'menu' => '<path d="M3 6h18M3 12h18M3 18h18"/>',
    ][$name] ?? '';
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>';
};
$nav = [
    ['Work', null, null],
    ['Dashboard', '/', 'home'],
    ['Work Tracker', '/tracker', 'tracker'],
    ['Requirements', '/requirements', 'req'],
    ['BOMs', '/boms', 'bom'],
    ['Quotations', '/quotes', 'quote'],
    ['Data', null, null],
    ['Components', '/components', 'comp'],
    ['Customers', '/customers', 'cust'],
    ['Analytics', '/analytics', 'chart'],
];
if (current_role() === 'Admin') {
    $nav[] = ['Admin', null, null];
    $nav[] = ['Users & Emails', '/team', 'users'];
}
$is_active = fn(string $path) => $path === '/' ? $route === '/' : ($route === $path || str_starts_with($route, $path . '/'));
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= e($theme) ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?></title>
  <link rel="stylesheet" href="<?= e(asset('style.css')) ?>">
  <script>try { var t = localStorage.getItem('ahpc_theme'); if (t && !<?= json_encode((bool)me()) ?>) document.documentElement.dataset.theme = t; } catch (e) {}</script>
</head>
<?php if (current_user_name()): ?>
<body class="app">
  <aside class="sidebar no-print" id="sidebar">
    <div class="brand"><div class="mark">AH</div><div class="name">Allway HPC<small>BOM &amp; Quotation</small></div></div>
    <nav class="nav">
      <?php foreach ($nav as [$label, $path, $ic]): ?>
        <?php if ($path === null): ?><div class="group"><?= e($label) ?></div>
        <?php else: ?><a href="<?= e(url($path)) ?>" class="<?= $is_active($path) ? 'active' : '' ?>"><?= $icon($ic) ?><?= e($label) ?></a><?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <div class="side-foot">
      <div class="me"><div class="avatar"><?= e(mb_strtoupper(mb_substr((string)current_user_name(), 0, 1))) ?></div>
        <div><b><?= e(current_user_name()) ?></b><span><?= e(current_role()) ?></span></div></div>
      <div class="side-links"><a href="<?= e(url('/account/password')) ?>">Password</a><a href="<?= e(url('/logout')) ?>">Log out</a></div>
      <form method="post" action="<?= e(url('/account/theme')) ?>" class="theme-pick" id="theme-form">
        <label>Theme</label>
        <div class="swatches">
          <?php foreach (THEMES as $key => [$name, $c1, $c2]): ?>
          <button type="submit" name="theme" value="<?= e($key) ?>" title="<?= e($name) ?>" class="<?= $key === $theme ? 'on' : '' ?>"
                  style="background:linear-gradient(135deg, <?= e($c1) ?> 50%, <?= e($c2) ?> 50%);"></button>
          <?php endforeach; ?>
        </div>
      </form>
    </div>
  </aside>
  <div class="main">
    <div class="mobile-bar no-print"><button type="button" onclick="document.body.classList.toggle('nav-open')" aria-label="Menu"><?= $icon('menu') ?></button><b>Allway HPC</b></div>
    <div class="container">
      <?php foreach ($flashes as [$category, $message]): ?>
        <div class="flash <?= e($category) ?>"><?= e($message) ?></div>
      <?php endforeach; ?>
      <?= $content ?>
    </div>
  </div>
  <script>
  document.body.addEventListener('click', function (e) { if (document.body.classList.contains('nav-open') && !e.target.closest('#sidebar, .mobile-bar')) document.body.classList.remove('nav-open'); });
  (function () {
    var f = document.getElementById('theme-form');
    f.addEventListener('click', function (e) {
      var b = e.target.closest('button[name=theme]'); if (!b) return;
      e.preventDefault();
      document.documentElement.dataset.theme = b.value;
      f.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
      try { localStorage.setItem('ahpc_theme', b.value); } catch (err) {}
      var d = new FormData(f); d.append('theme', b.value);
      fetch(f.action, { method: 'POST', body: d, headers: { 'X-Requested-With': 'fetch' } });
      document.dispatchEvent(new Event('themechange'));
    });
  })();
  </script>
</body>
<?php else: ?>
<body>
  <div class="login-wrap"><div style="width:100%;max-width:420px;">
    <?php foreach ($flashes as [$category, $message]): ?><div class="flash <?= e($category) ?>"><?= e($message) ?></div><?php endforeach; ?>
    <?= $content ?>
  </div></div>
</body>
<?php endif; ?>
</html>
