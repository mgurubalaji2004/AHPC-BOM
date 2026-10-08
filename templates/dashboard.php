<?php $is_admin = current_role() === 'Admin'; $here = current_uri(); ?>
<div class="page-header">
  <h1>Dashboard</h1>
  <div>
    <a class="btn secondary" href="<?= e(url('/analytics')) ?>">View Analytics</a>
    <a class="btn secondary" href="<?= e(url('/requirements/new')) ?>">+ New Requirement</a>
    <a class="btn" href="<?= e(url('/boms/search')) ?>">+ Create BOM</a>
  </div>
</div>

<div class="stats-row">
  <div class="stat-card"><div class="num"><?= e($counts['requirements']) ?></div><div class="label">Requirements</div></div>
  <div class="stat-card"><div class="num"><?= e($counts['boms']) ?></div><div class="label">BOMs</div></div>
  <div class="stat-card"><div class="num"><?= e($counts['pending']) ?></div><div class="label">Pending Quotes</div></div>
  <div class="stat-card"><div class="num"><?= e($counts['quotes']) ?></div><div class="label">Quotations</div></div>
</div>

<div class="card">
  <div class="section-title" style="margin-top:0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
    <span>Pending Quotes <span style="font-weight:400;color:var(--muted);font-size:0.85rem;">&mdash; assign work, add remarks or the reason for a delay; every action is e-mailed</span></span>
    <span>
      <a class="btn small <?= $mine ? '' : 'secondary' ?>" href="<?= e(url('/', ['mine' => 1])) ?>">My tasks (<?= e($my_count) ?>)</a>
      <a class="btn small <?= $mine ? 'secondary' : '' ?>" href="<?= e(url('/')) ?>">All pending</a>
    </span>
  </div>
  <?php if ($pending): ?>
  <table class="pending">
    <thead><tr><th>Ref</th><th>Customer</th><th>Requirement / BOM</th><th>Stage</th><th style="width:21%;">Assigned to</th><th style="width:30%;">Remarks</th></tr></thead>
    <?php foreach ($pending as $p): ?>
    <tr>
      <td data-label="Ref"><a href="<?= e($p['link']) ?>"><?= e($p['ref']) ?></a><div style="font-size:0.72rem;color:var(--muted);">since <?= e($p['since']) ?></div></td>
      <td data-label="Customer"><?= e($p['company'] ?: '-') ?></td>
      <td data-label="Requirement / BOM"><?= e($p['title'] ?: '-') ?></td>
      <td data-label="Stage" style="font-size:0.85rem;"><?= e($p['stage']) ?></td>
      <td data-label="Assigned to">
        <?php if ($p['assignee']): ?><b><?= e($p['assignee']) ?></b><?php else: ?><span class="badge orange">Unassigned</span><?php endif; ?>
        <?php if ($p['due']): ?><div style="font-size:0.74rem;color:<?= $p['overdue'] ? 'var(--danger)' : 'var(--muted)' ?>;">due <?= e($p['due']) ?><?= $p['overdue'] ? ' - OVERDUE' : '' ?></div><?php endif; ?>
        <?php if ($is_admin): ?>
        <form method="post" action="<?= e(url("/assign/{$p['kind']}/{$p['id']}")) ?>" class="mini-assign">
          <input type="hidden" name="next" value="<?= e($here) ?>">
          <select name="assignee_id" required><option value="">assign to...</option>
            <?php foreach ($users as $u): ?><option value="<?= e($u['id']) ?>" <?= $u['id'] == $p['assignee_id'] ? 'selected' : '' ?>><?= e($u['name']) ?><?= $u['name'] === current_user_name() ? ' (me)' : '' ?></option><?php endforeach; ?>
          </select>
          <input type="date" name="due_date" value="<?= e($p['due']) ?>" title="Due date">
          <button class="btn small secondary" type="submit">Assign</button>
        </form>
        <?php endif; ?>
      </td>
      <td data-label="Remarks">
        <?php if ($p['last_remark']): $lr = $p['last_remark']; ?>
        <div class="last-remark"><b><?= e($lr['author_name']) ?></b>
          <?php if ($lr['rtype'] === 'DELAY'): ?><span class="badge red">delay</span><?php endif; ?>
          <span style="color:var(--muted);font-size:0.72rem;"><?= e(substr((string)$lr['created_at'], 0, 10)) ?></span><br><?= e($lr['body']) ?></div>
        <?php endif; ?>
        <form method="post" action="<?= e(url("/remarks/{$p['kind']}/{$p['id']}")) ?>" class="remark-form">
          <input type="hidden" name="next" value="<?= e($here) ?>">
          <select name="rtype"><option value="REMARK">Remark</option><option value="DELAY">Reason for delay</option></select>
          <input name="remarks" placeholder="type and press Add" required>
          <button class="btn small secondary" type="submit">Add</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <div class="empty-state"><?= $mine ? 'Nothing assigned to you.' : 'No pending quotes. Everything has been quoted.' ?></div>
  <?php endif; ?>
</div>
