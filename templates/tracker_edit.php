<div class="page-header">
  <h1>Tracker row <?= e($w['sno']) ?><?= $w['quote_number'] ? ' &middot; ' . e($w['quote_number']) : '' ?></h1>
  <div>
    <?php if ($bom): ?><a class="btn secondary" href="<?= e(url("/boms/{$bom['id']}")) ?>">Open <?= e($bom['bom_number']) ?> v<?= e($bom['version']) ?></a><?php endif; ?>
    <a class="btn secondary" href="<?= e($back ?? url('/tracker')) ?>">Back</a>
  </div>
</div>
<?php if ($w['source'] === 'AUTO'): ?><p class="subtitle">Added automatically for a <?= $w['bom_id'] ? 'BOM' : 'requirement' ?>; status, engineer and customer keep updating as the BOM moves on.</p><?php endif; ?>
<div class="card">
  <form method="post">
    <input type="hidden" name="next" value="<?= e($back ?? '') ?>">
    <?php include __DIR__ . '/_tracker_fields.php'; ?>
    <button class="btn" type="submit">Save</button>
  </form>
</div>
