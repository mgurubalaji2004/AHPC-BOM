<div class="page-header"><h1>Component Database</h1>
  <?php if (current_role() === 'Admin'): ?>
  <form method="post" action="<?= e(url('/components/rebuild')) ?>" onsubmit="return confirm('Replace the ENTIRE component database with the distinct components found in all BOMs?')">
    <button class="btn secondary" type="submit">Rebuild from all BOMs</button>
  </form>
  <?php endif; ?>
</div>

<div class="card">
  <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
    <input type="hidden" name="r" value="/components">
    <div class="field" style="flex:2;min-width:220px;margin-bottom:0;">
      <label>Search by name / manufacturer / part number</label>
      <input name="q" value="<?= e($q) ?>" placeholder="e.g. NVIDIA H200">
    </div>
    <div class="field" style="flex:1;min-width:160px;margin-bottom:0;">
      <label>Category</label>
      <select name="category">
        <option value="">All Categories</option>
        <?php foreach ($categories as $c): ?>
        <option value="<?= e($c) ?>" <?= $c === $category ? 'selected' : '' ?>><?= e($c) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn" type="submit">Search</button>
    <a class="btn secondary" href="<?= e(url('/components')) ?>">Reset</a>
  </form>
</div>

<div class="grid-2">
  <div class="card" style="grid-column: span 2;">
    <div class="section-title" style="margin-top:0;">Results (<?= count($components) ?>)</div>
    <?php if ($components): ?>
    <table>
      <tr><th>Category</th><th>Manufacturer</th><th>Part No.</th><th>Description</th><th>Cost</th><th>Selling Price</th><th>Stock</th></tr>
      <?php foreach ($components as $c): ?>
      <tr>
        <td><?= e($c['category']) ?></td>
        <td><?= e($c['manufacturer'] ?: '-') ?></td>
        <td><?= e($c['part_number'] ?: '-') ?></td>
        <td><?= e($c['description'] ?: '-') ?></td>
        <td>₹<?= inr($c['cost']) ?></td>
        <td>₹<?= inr($c['selling_price']) ?></td>
        <td><?php if ((int)$c['stock'] < 3): ?><span class="badge red"><?= e($c['stock']) ?></span><?php else: ?><?= e($c['stock']) ?><?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php else: ?>
    <div class="empty-state">No components found.</div>
    <?php endif; ?>
  </div>
</div>

<div class="card" style="max-width:640px;">
  <div class="section-title" style="margin-top:0;">Add New Component</div>
  <form method="post">
    <div class="grid-2">
      <div class="field"><label>Category *</label><input name="category" required placeholder="CPU / GPU / RAM / Storage..."></div>
      <div class="field"><label>Manufacturer</label><input name="manufacturer"></div>
    </div>
    <div class="grid-2">
      <div class="field"><label>Part Number</label><input name="part_number"></div>
      <div class="field"><label>Supplier</label><input name="supplier"></div>
    </div>
    <div class="field"><label>Description</label><input name="description"></div>
    <div class="grid-3">
      <div class="field"><label>Cost (₹)</label><input name="cost" type="number" step="0.01" value="0"></div>
      <div class="field"><label>Selling Price (₹)</label><input name="selling_price" type="number" step="0.01" value="0"></div>
      <div class="field"><label>Stock Qty</label><input name="stock" type="number" value="0"></div>
    </div>
    <button class="btn" type="submit">Add Component</button>
  </form>
</div>
