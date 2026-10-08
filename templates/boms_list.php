<div class="page-header">
  <h1>Bills of Material</h1>
  <a class="btn" href="<?= e(url('/boms/search')) ?>">+ Create BOM</a>
</div>

<div class="card">
  <form method="get" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
    <input type="hidden" name="r" value="/boms">
    <div class="field" style="flex:1;min-width:240px;margin-bottom:0;">
      <label>Search BOMs (title, customer, BOM no., component)</label>
      <input name="q" value="<?= e($q) ?>" placeholder="e.g. GPU, Xeon, ASHTECH">
    </div>
    <button class="btn" type="submit">Search</button>
    <a class="btn secondary" href="<?= e(url('/boms')) ?>">Reset</a>
  </form>
</div>

<?php foreach ($boms as $b): [$items, $t] = $detail[$b['id']]; $words = []; ?>
<details class="card bom-card">
  <summary>
    <span class="bom-no"><?= e($b['bom_number']) ?> <small>v<?= e($b['version']) ?></small></span>
    <span class="bom-title"><?= e($b['title']) ?></span>
    <span class="bom-cust"><?= e($b['company'] ?: '-') ?></span>
    <span class="badge <?= badge_status_class((string)$b['status']) ?>"><?= e(str_replace('_', ' ', (string)$b['status'])) ?></span>
    <span class="bom-total">&#8377;<?= inr($t['grand_total']) ?></span>
  </summary>
  <div style="margin-top:12px;">
    <?php include __DIR__ . '/_bom_lines.php'; ?>
    <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap;">
      <a class="btn small" href="<?= e(url("/boms/{$b['id']}")) ?>">Edit BOM (add / modify components)</a>
      <form method="post" action="<?= e(url("/boms/{$b['id']}/clone")) ?>"><button class="btn small secondary" type="submit">Create another BOM from this</button></form>
    </div>
  </div>
</details>
<?php endforeach; ?>
<?php if (!$boms): ?>
<div class="card"><div class="empty-state">No BOMs found.</div></div>
<?php endif; ?>
