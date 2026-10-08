<?php
$is_admin = current_role() === 'Admin';
$f = ['tab' => $tab, 'q' => arg('q'), 'engineer' => arg('engineer'), 'from' => arg('from'), 'to' => arg('to')];
$fmt = fn($d) => $d ? date('d M y', strtotime($d)) : '';
$here = current_uri();
?>
<div class="page-header">
  <h1>Work Tracker</h1>
  <div>
    <a class="btn secondary" href="<?= e(url('/tracker/export', $f)) ?>">Export to Excel (CSV)</a>
    <a class="btn" href="#add" onclick="document.getElementById('add').open = true;">+ Add entry</a>
  </div>
</div>
<p class="subtitle">Every requirement and BOM is listed here automatically with its quotation number, customer and engineer. Next quotation number: <b><?= e($next_quote) ?></b></p>

<div class="tabs">
  <?php foreach (['all' => ['All work', array_sum($stage_counts)], 'open' => ['In progress', $stage_counts['OPEN']],
                  'done' => ['Completed', $stage_counts['DONE']], 'cancelled' => ['Cancelled', $stage_counts['CANCELLED']]] as $k => [$label, $n]): ?>
  <a class="tab <?= $tab === $k ? 'active' : '' ?>" href="<?= e(url('/tracker', ['tab' => $k] + $f)) ?>"><?= e($label) ?> <span><?= (int)$n ?></span></a>
  <?php endforeach; ?>
</div>

<div class="eng-board">
  <?php foreach ($by_engineer as $g): $open = (int)$g['open']; $done = (int)$g['done']; $n = max(1, (int)$g['n']);
        $key = $g['name'] === '(none)' ? 'none' : $g['name']; ?>
  <a class="eng-card <?= arg('engineer') === $key ? 'active' : '' ?>" href="<?= e(url('/tracker', ['engineer' => arg('engineer') === $key ? null : $key] + array_diff_key($f, ['engineer' => 1]))) ?>">
    <b><?= e($g['name'] === '(none)' ? 'Not assigned' : $g['name']) ?></b>
    <div class="bar"><i style="width:<?= round(100 * $done / $n) ?>%"></i><i class="o" style="width:<?= round(100 * $open / $n) ?>%"></i></div>
    <small><?= $open ?> in progress &middot; <?= $done ?> completed</small>
  </a>
  <?php endforeach; ?>
</div>

<div class="card">
  <form method="get" class="filters">
    <input type="hidden" name="r" value="/tracker"><input type="hidden" name="tab" value="<?= e($tab) ?>">
    <div class="field"><label>Search</label><input name="q" value="<?= e(arg('q')) ?>" placeholder="quotation no., customer, requirement, status..."></div>
    <div class="field"><label>Engineer</label>
      <select name="engineer"><option value="">Everyone</option>
        <?php foreach ($engineers as $en): ?><option <?= arg('engineer') === $en ? 'selected' : '' ?>><?= e($en) ?></option><?php endforeach; ?>
        <option value="none" <?= arg('engineer') === 'none' ? 'selected' : '' ?>>(not assigned)</option>
      </select></div>
    <div class="field"><label>Received from</label><input type="date" name="from" value="<?= e(arg('from')) ?>"></div>
    <div class="field"><label>to</label><input type="date" name="to" value="<?= e(arg('to')) ?>"></div>
    <div style="display:flex;gap:8px;"><button class="btn" type="submit">Filter</button><a class="btn secondary" href="<?= e(url('/tracker', ['tab' => $tab])) ?>">Reset</a></div>
  </form>
</div>

<div class="card">
  <div class="legend-dots"><span><i style="background:var(--warning)"></i>In progress</span><span><i style="background:var(--success)"></i>Completed</span><span><i style="background:var(--muted)"></i>Cancelled</span>
    <span style="margin-left:auto;"><?= $total ?> row<?= $total === 1 ? '' : 's' ?></span></div>
  <?php if ($rows): ?>
  <div class="table-wrap">
  <table class="tracker">
    <thead><tr><th>S.No.</th><th>Received</th><th>Quotation No.</th><th>Customer</th><th>Requirement</th><th>Engineer</th><th>Status</th>
      <th>Quote sent</th><th>Due</th><th>Remarks / follow-up</th><th></th></tr></thead>
    <?php foreach ($rows as $w): $wid = (int)$w['id']; $late = $w['stage'] === 'OPEN' && $w['due_date'] && $w['due_date'] < today_str(); ?>
    <tr class="stage-<?= e($w['stage']) ?>">
      <td class="sno"><?= e($w['sno']) ?></td>
      <td style="white-space:nowrap;"><?= e($fmt($w['received_date'] ?: $w['entry_date'])) ?></td>
      <td class="qn"><?= e($w['quote_number'] ?: '-') ?>
        <?php if ($w['bom_id']): ?><div><a href="<?= e(url("/boms/{$w['bom_id']}")) ?>" style="font-size:.75rem;font-weight:600;"><?= e($w['bom_number']) ?> v<?= e($w['bom_version']) ?></a></div><?php endif; ?>
        <?php if ($w['quote_id']): ?><div><a href="<?= e(url("/quotes/{$w['quote_id']}")) ?>" style="font-size:.75rem;font-weight:600;">quotation</a></div><?php endif; ?></td>
      <td><b><?= e($w['customer_name']) ?></b><?php if ($w['company_name']): ?><div style="font-size:.78rem;color:var(--muted);"><?= e($w['company_name']) ?></div><?php endif; ?>
        <?php if ($w['region']): ?><span class="badge gray"><?= e($w['region']) ?></span><?php endif; ?></td>
      <td class="req"><?= e($w['requirement']) ?><?= $w['quantity'] ? ' <span class="badge gray">qty ' . e($w['quantity']) . '</span>' : '' ?></td>
      <td><?= $w['engineer_name'] ? e($w['engineer_name']) : '<span class="badge orange">Unassigned</span>' ?></td>
      <td><span class="badge <?= $w['stage'] === 'DONE' ? 'green' : ($w['stage'] === 'CANCELLED' ? 'gray' : 'orange') ?>"><?= e(STAGES[$w['stage']] ?? $w['stage']) ?></span>
        <div style="font-size:.8rem;margin-top:3px;"><?= e($w['status']) ?></div><?php if ($w['po_status']): ?><div style="font-size:.76rem;color:var(--muted);">PO: <?= e($w['po_status']) ?></div><?php endif; ?></td>
      <td style="white-space:nowrap;"><?= e($fmt($w['quote_sent_date'])) ?></td>
      <td style="white-space:nowrap;<?= $late ? 'color:var(--danger);font-weight:700;' : '' ?>"><?= e($fmt($w['due_date'])) ?><?= $late ? '<br>overdue' : '' ?></td>
      <td class="rem"><?= e($w['remarks']) ?><?php if ($w['followup']): ?><div>Follow-up: <?= e($w['followup']) ?></div><?php endif; ?>
        <?php if ($w['contact']): ?><div><?= e($w['contact']) ?></div><?php endif; ?></td>
      <td style="white-space:nowrap;">
        <a class="btn small secondary" href="<?= e(url("/tracker/$wid/edit")) ?>">Edit</a>
        <?php if ($w['stage'] === 'OPEN'): ?>
        <form method="post" class="inline-form" action="<?= e(url("/tracker/$wid/stage")) ?>"><input type="hidden" name="stage" value="DONE"><button class="btn small success" type="submit" title="Mark completed">&#10003;</button></form>
        <?php else: ?>
        <form method="post" class="inline-form" action="<?= e(url("/tracker/$wid/stage")) ?>"><input type="hidden" name="stage" value="OPEN"><button class="btn small secondary" type="submit" title="Back to in progress">&#8634;</button></form>
        <?php endif; ?>
        <?php if ($is_admin): ?>
        <form method="post" class="inline-form" action="<?= e(url("/tracker/$wid/delete")) ?>" onsubmit="return confirm('Delete tracker row <?= e($w['sno']) ?>?')"><button class="btn small danger" type="submit" title="Delete">&#10005;</button></form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
  <?php else: ?><div class="empty-state">No work matches.</div><?php endif; ?>
  <?php if ($pages > 1): ?>
  <div class="pager">
    <?php for ($i = 1; $i <= $pages; $i++): if ($pages > 12 && $i > 2 && $i < $pages - 1 && abs($i - $page) > 2) { if ($i === 3 || $i === $pages - 2) echo '<span>&hellip;</span>'; continue; } ?>
      <?php if ($i === $page): ?><b><?= $i ?></b><?php else: ?><a href="<?= e(url('/tracker', ['page' => $i] + $f)) ?>"><?= $i ?></a><?php endif; ?>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>

<details class="card" id="add">
  <summary class="section-title" style="margin:0;cursor:pointer;">+ Add an entry to the tracker</summary>
  <form method="post" action="<?= e(url('/tracker/new')) ?>" style="margin-top:16px;">
    <?php $w = []; include __DIR__ . '/_tracker_fields.php'; ?>
    <label style="display:flex;gap:8px;align-items:center;font-weight:500;margin:4px 0 14px;"><input type="checkbox" name="auto_number" value="1" checked> Give it the next quotation number (<?= e($next_quote) ?>) if the field above is empty</label>
    <button class="btn" type="submit">Add to tracker</button>
  </form>
</details>
