<div class="page-header"><h1>Find Existing BOM</h1></div>

<?php if ($requirement): ?>
<div class="card">
  <div class="section-title" style="margin-top:0;">Requirement</div>
  <b><?= e($requirement['req_number']) ?></b> &mdash; <?= e($requirement['title']) ?><br>
  <span style="color:var(--muted);font-size:0.88rem;"><?= e($requirement['description']) ?></span>
</div>
<?php endif; ?>

<div class="card">
  <form method="get" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
    <input type="hidden" name="r" value="/boms/search">
    <?php if ($requirement): ?><input type="hidden" name="requirement_id" value="<?= e($requirement['id']) ?>"><?php endif; ?>
    <div class="field" style="flex:1;min-width:260px;margin-bottom:0;">
      <label>Keyword &mdash; CPU, GPU, motherboard, chassis, customer, BOM title (e.g. Xeon, EPYC, RTX 4000, IIT)</label>
      <input name="q" value="<?= e($q) ?>" placeholder="Xeon" autofocus>
    </div>
    <button class="btn" type="submit">Search BOMs</button>
    <a class="btn secondary" href="<?= e(url('/boms/new', ['requirement_id' => $requirement['id'] ?? null])) ?>">+ Create New BOM</a>
  </form>
</div>

<?php if ($words): ?>
<div class="section-title"><?= count($similar) ?> BOM<?= count($similar) === 1 ? '' : 's' ?> found for "<?= e($q) ?>"</div>
<?php foreach ($similar as $n => $b): [$items, $t] = $detail[$b['id']]; ?>
<details class="card bom-card" <?= $n < 3 ? 'open' : '' ?>>
  <summary>
    <span class="bom-no"><?= hl($b['bom_number'], $words) ?> <small>v<?= e($b['version']) ?></small></span>
    <span class="bom-title"><?= hl($b['title'], $words) ?></span>
    <span class="bom-cust"><?= hl($b['company'] ?: '-', $words) ?></span>
    <span class="badge gray"><?= count($items) ?> components</span>
    <span class="bom-total">&#8377;<?= inr($t['grand_total']) ?></span>
  </summary>
  <div style="margin-top:12px;">
    <?php include __DIR__ . '/_bom_lines.php'; ?>
    <?php $req_id = $requirement['id'] ?? null; include __DIR__ . '/_bom_actions.php'; ?>
  </div>
</details>
<?php endforeach; ?>
<?php if (!$similar): ?>
<div class="card"><div class="empty-state">No BOM contains "<?= e($q) ?>". Try a shorter keyword, or create a new BOM.</div></div>
<?php endif; ?>
<?php else: ?>
<div class="card"><div class="empty-state">Type a keyword such as <b>Xeon</b> to list every BOM that uses it, with all of its components.</div></div>
<?php endif; ?>
