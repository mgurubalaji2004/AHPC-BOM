<div class="page-header">
  <h1><?= $copy_bom ? 'Customize Existing BOM' : 'Create New BOM' ?></h1>
</div>

<?php if ($copy_bom): ?>
<div class="card">
  <div class="section-title" style="margin-top:0;">Copying from <?= e($copy_bom['bom_number']) ?> (v<?= e($copy_bom['version']) ?>)</div>
  <table>
    <tr><th>Category</th><th>Manufacturer</th><th>Part No.</th><th>Qty</th><th>Unit Price</th></tr>
    <?php foreach ($copy_items as $it): ?>
    <tr><td><?= e($it['category']) ?></td><td><?= e($it['manufacturer']) ?></td><td><?= e($it['part_number']) ?></td><td><?= (int)$it['quantity'] ?></td><td>₹<?= inr($it['unit_price']) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <p style="color:var(--muted);font-size:0.85rem;">All <?= count($copy_items) ?> line items will be copied into the new BOM. You can edit or remove them after creation.</p>
</div>
<?php endif; ?>

<div class="card" style="max-width:600px;">
  <form method="post">
    <?php if ($copy_bom): ?><input type="hidden" name="copy_from" value="<?= e($copy_bom['id']) ?>"><?php endif; ?>
    <?php if ($requirement): ?><input type="hidden" name="requirement_id" value="<?= e($requirement['id']) ?>"><?php endif; ?>
    <?php $selected_id = $requirement ? $requirement['customer_id'] : ($copy_bom['customer_id'] ?? null);
          include __DIR__ . '/_customer_picker.php'; ?>
    <div class="field">
      <label>BOM Title *</label>
      <input name="title" required value="<?= e(($requirement['title'] ?? '') ?: ($copy_bom['title'] ?? '')) ?>">
    </div>
    <button class="btn" type="submit"><?= $copy_bom ? 'Create Customized BOM' : 'Create Empty BOM' ?></button>
  </form>
</div>
