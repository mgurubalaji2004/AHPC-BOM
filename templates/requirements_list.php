<div class="page-header">
  <h1>Customer Requirements</h1>
  <a class="btn" href="<?= e(url('/requirements/new')) ?>">+ New Requirement</a>
</div>
<div class="card">
  <?php if ($requirements): ?>
  <table>
    <tr><th>Req #</th><th>Title</th><th>Customer</th><th>Sales Person</th><th>Expected Delivery</th><th>Assigned to</th><th>Latest remark</th><th></th></tr>
    <?php foreach ($requirements as $r): $t = thread_for('req', (int)$r['id']); ?>
    <tr>
      <td><?= e($r['req_number']) ?></td>
      <td><?= e($r['title']) ?></td>
      <td><?= e($r['company'] ?: '-') ?></td>
      <td><?= e($r['sales_person'] ?: '-') ?></td>
      <td><?= e($r['expected_delivery'] ?: '-') ?></td>
      <td><?php if ($t['assignee']): ?><b><?= e($t['assignee']['name']) ?></b><?php if ($r['due_date']): ?><div style="font-size:0.72rem;color:<?= $t['overdue'] ? 'var(--danger)' : 'var(--muted)' ?>;">due <?= e($r['due_date']) ?><?= $t['overdue'] ? ' - OVERDUE' : '' ?></div><?php endif; ?><?php else: ?><span class="badge orange">Unassigned</span><?php endif; ?></td>
      <td style="font-size:0.85rem;color:var(--muted);"><?= e($r['remarks'] ?: '-') ?></td>
      <td style="white-space:nowrap;"><a class="btn small secondary" href="<?= e(url("/requirements/{$r['id']}/edit")) ?>">Edit</a>
        <a class="btn small" href="<?= e(url('/boms/search', ['requirement_id' => $r['id']])) ?>">Find / Create BOM</a></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <div class="empty-state">No requirements captured yet. Click "New Requirement" to start.</div>
  <?php endif; ?>
</div>
