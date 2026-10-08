<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?></title>
  <link rel="stylesheet" href="<?= e(asset('style.css')) ?>">
</head>
<body>
  <?php if (current_user_name()): ?>
  <div class="topbar no-print">
    <div class="brand">AHPC <span>BOM & QUOTATION SYSTEM</span></div>
    <nav>
      <a href="<?= e(url('/')) ?>">Dashboard</a>
      <a href="<?= e(url('/analytics')) ?>">Analytics</a>
      <a href="<?= e(url('/requirements')) ?>">Requirements</a>
      <a href="<?= e(url('/components')) ?>">Components</a>
      <a href="<?= e(url('/boms')) ?>">BOMs</a>
      <a href="<?= e(url('/quotes')) ?>">Quotes</a>
      <a href="<?= e(url('/customers')) ?>">Customers</a>
      <?php if (current_role() === 'Admin'): ?><a href="<?= e(url('/team')) ?>">Team &amp; Emails</a><?php endif; ?>
    </nav>
    <div class="user-info"><?= e(current_user_name()) ?> &middot; <?= e(current_role()) ?> &middot; <a href="<?= e(url('/account/password')) ?>">Password</a> &middot; <a href="<?= e(url('/logout')) ?>">Logout</a></div>
  </div>
  <?php endif; ?>
  <div class="container">
    <?php foreach ($flashes as [$category, $message]): ?>
      <div class="flash <?= e($category) ?>"><?= e($message) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
  </div>
</body>
</html>
