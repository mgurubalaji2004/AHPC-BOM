<div class="page-header">
  <h1>Bills of Material</h1>
  <a class="btn" href="<?= e(url('/boms/search')) ?>">+ Create BOM</a>
</div>

<div class="tabs">
  <a class="tab <?= $tab === 'library' ? 'active' : '' ?>" href="<?= e(url('/boms', ['tab' => 'library'])) ?>">Uploaded BOMs <span><?= $counts['library'] ?></span></a>
  <a class="tab <?= $tab === 'working' ? 'active' : '' ?>" href="<?= e(url('/boms', ['tab' => 'working'])) ?>">Project BOMs <span><?= $counts['working'] ?></span></a>
</div>

<div class="card">
  <form method="get" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
    <input type="hidden" name="r" value="/boms">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <div class="field" style="flex:1;min-width:240px;margin-bottom:0;">
      <label>Search (customer, title, file, BOM no., any component / model)</label>
      <input name="q" value="<?= e($q) ?>" placeholder="e.g. TIFR, EPYC 9554, H200, 4U">
    </div>
    <button class="btn" type="submit">Search</button>
    <a class="btn secondary" href="<?= e(url('/boms', ['tab' => $tab])) ?>">Reset</a>
  </form>
  <?php if ($tab === 'library'): ?>
  <form method="post" action="<?= e(url('/boms/upload')) ?>" enctype="multipart/form-data" class="upload-row">
    <label><b>Upload BOM sheets</b> (.xlsx, one or more files) &mdash; every "Components / Model / Quantity / Unit Price" table becomes a BOM here</label>
    <input type="file" name="files[]" accept=".xlsx,.xlsm,.html,.htm" multiple required>
    <button class="btn small" type="submit">Upload</button>
  </form>
  <?php endif; ?>
</div>

<div class="section-title"><?= $total ?> BOM<?= $total === 1 ? '' : 's' ?><?= $q !== '' ? ' found for "' . e($q) . '"' : '' ?>
  <span style="font-weight:400;color:var(--muted);font-size:0.85rem;">&mdash; click a BOM to see all components.
  <b>Use</b> = copy into a new BOM, <b>Customize</b> = choose / change lines first, <b>Open as new BOM</b> = instant editable copy</span></div>

<?php $words = []; foreach ($boms as $b): [$items, $t] = $detail[$b['id']]; ?>
<details class="card bom-card">
  <summary>
    <span class="bom-no"><?= e($b['bom_number']) ?> <small>v<?= e($b['version']) ?></small></span>
    <span class="bom-title"><?= e($b['title']) ?><?php if ($b['source_file']): ?><small class="src">&#128196; <?= e($b['source_file']) ?></small><?php endif; ?></span>
    <span class="bom-cust"><?= e($b['company'] ?: '-') ?></span>
    <?php if ($tab === 'library'): ?><span class="badge gray"><?= count($items) ?> lines</span>
    <?php else: ?><span class="badge <?= badge_status_class((string)$b['status']) ?>"><?= e(str_replace('_', ' ', (string)$b['status'])) ?></span><?php endif; ?>
    <span class="bom-total">&#8377;<?= inr($t['grand_total']) ?></span>
  </summary>
  <div style="margin-top:12px;">
    <?php include __DIR__ . '/_bom_lines.php'; ?>
    <?php include __DIR__ . '/_bom_actions.php'; ?>
  </div>
</details>
<?php endforeach; ?>
<?php if (!$boms): ?>
<div class="card"><div class="empty-state">No BOMs found.</div></div>
<?php endif; ?>
<?php if ($pages > 1): ?>
<div class="pager">
  <?php for ($i = 1; $i <= $pages; $i++): ?>
    <?php if ($i === $page): ?><b><?= $i ?></b><?php else: ?><a href="<?= e(url('/boms', ['tab' => $tab, 'q' => $q, 'page' => $i])) ?>"><?= $i ?></a><?php endif; ?>
  <?php endfor; ?>
</div>
<?php endif; ?>
