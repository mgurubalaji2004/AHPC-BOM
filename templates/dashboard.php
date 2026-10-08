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
  <div class="stat-card"><div class="num"><?= e($counts['boms']) ?></div><div class="label">Project BOMs</div></div>
  <div class="stat-card"><div class="num"><?= e($counts['library']) ?></div><div class="label">Uploaded BOMs</div></div>
  <div class="stat-card"><div class="num"><?= e($counts['pending']) ?></div><div class="label">Pending</div></div>
  <div class="stat-card"><div class="num"><?= e($counts['quotes']) ?></div><div class="label">Quotations</div></div>
</div>

<div class="card">
  <form method="get" class="search-bar">
    <input type="hidden" name="r" value="/">
    <?php if ($mine): ?><input type="hidden" name="mine" value="1"><?php endif; ?>
    <input name="q" value="<?= e($q) ?>" placeholder="Search customers, requirements, BOMs, quotations... e.g. TIFR, REQ-2026-004, H200" autofocus>
    <button class="btn" type="submit">Search</button>
    <?php if ($q !== ''): ?><a class="btn secondary" href="<?= e(url('/')) ?>">Clear</a><?php endif; ?>
  </form>
  <?php if ($results !== null): $nres = array_sum(array_map('count', $results)); ?>
  <div class="search-results">
    <?php if (!$nres): ?><div class="empty-state" style="padding:14px;">Nothing found for "<?= e($q) ?>".</div><?php endif; ?>
    <?php if ($results['customers']): ?>
    <h4>Customers (<?= count($results['customers']) ?>)</h4>
    <table><?php foreach ($results['customers'] as $c): ?>
      <tr><td><b><?= e($c['company']) ?></b></td><td><?= e($c['contact_person'] ?: '') ?> <?= e($c['phone'] ?: '') ?> <?= e($c['email'] ?: '') ?></td>
        <td><?= (int)$c['n_reqs'] ?> requirement(s), <?= (int)$c['n_boms'] ?> BOM(s)</td>
        <td><a class="btn small secondary" href="<?= e(url('/boms', ['tab' => 'library', 'q' => $c['company']])) ?>">BOMs</a>
            <a class="btn small" href="<?= e(url('/requirements/new')) ?>">New requirement</a></td></tr>
    <?php endforeach; ?></table>
    <?php endif; ?>
    <?php if ($results['requirements']): ?>
    <h4>Requirements (<?= count($results['requirements']) ?>)</h4>
    <table><?php foreach ($results['requirements'] as $r): ?>
      <tr><td><a href="<?= e(url("/requirements/{$r['id']}/edit")) ?>"><?= e($r['req_number']) ?></a></td><td><?= e($r['title']) ?></td>
        <td><?= e($r['company'] ?: '-') ?></td><td><?= e($r['assignee'] ?: 'Unassigned') ?></td>
        <td><a class="btn small" href="<?= e(url('/boms/search', ['requirement_id' => $r['id']])) ?>">Find / Create BOM</a></td></tr>
    <?php endforeach; ?></table>
    <?php endif; ?>
    <?php if ($results['boms']): ?>
    <h4>BOMs (<?= count($results['boms']) ?>)</h4>
    <table><?php foreach ($results['boms'] as $b): ?>
      <tr><td><a href="<?= e(url("/boms/{$b['id']}")) ?>"><?= e($b['bom_number']) ?> v<?= e($b['version']) ?></a></td><td><?= e($b['title']) ?></td>
        <td><?= e($b['company'] ?: '-') ?></td><td><?= ($b['source'] ?? '') === 'UPLOAD' ? '<span class="badge gray">uploaded</span>' : '<span class="badge ' . badge_status_class((string)$b['status']) . '">' . e(str_replace('_', ' ', $b['status'])) . '</span>' ?></td></tr>
    <?php endforeach; ?></table>
    <?php endif; ?>
    <?php if ($results['quotes']): ?>
    <h4>Quotations (<?= count($results['quotes']) ?>)</h4>
    <table><?php foreach ($results['quotes'] as $qt): ?>
      <tr><td><a href="<?= e(url("/quotes/{$qt['id']}")) ?>"><?= e($qt['quote_number']) ?></a></td><td><?= e($qt['to_company'] ?: ($qt['company'] ?: '-')) ?></td>
        <td>&#8377;<?= inr($qt['grand_total']) ?></td><td><span class="badge <?= $qt['status'] === 'SENT' ? 'blue' : 'gray' ?>"><?= e($qt['status']) ?></span></td></tr>
    <?php endforeach; ?></table>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="section-title" style="margin-top:0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
    <span>Pending work by person <span style="font-weight:400;color:var(--muted);font-size:0.85rem;">&mdash; assign work, add remarks or the reason for a delay; every action is e-mailed</span></span>
    <span>
      <a class="btn small <?= $mine ? '' : 'secondary' ?>" href="<?= e(url('/', ['mine' => 1])) ?>">My tasks (<?= e($my_count) ?>)</a>
      <a class="btn small <?= $mine || $person ? 'secondary' : '' ?>" href="<?= e(url('/')) ?>">All pending</a>
    </span>
  </div>
  <div class="people-chips">
    <?php foreach ($chips as $g): $n = count($g['items']); ?>
    <a class="chip <?= (string)$person === (string)$g['id'] ? 'active' : '' ?> <?= $n ? '' : 'zero' ?>" href="<?= e(url('/', ['person' => $g['id'], 'q' => $q])) ?>">
      <?= e($g['name']) ?><?php if ($g['role']): ?> <small><?= e($g['role']) ?></small><?php endif; ?>
      <b><?= $n ?></b><?php if ($g['overdue']): ?><i title="overdue"><?= $g['overdue'] ?> late</i><?php endif; ?></a>
    <?php endforeach; ?>
  </div>
  <?php if ($groups): ?>
  <table class="pending">
    <thead><tr><th>Ref</th><th>Customer</th><th>Requirement / BOM</th><th>Stage</th><th style="width:21%;">Assigned to</th><th style="width:30%;">Remarks</th></tr></thead>
    <?php foreach ($groups as $g): ?>
    <tr class="group-row"><td colspan="6"><?= e($g['name']) ?><?= $g['role'] ? ' &middot; ' . e($g['role']) : '' ?> &mdash; <?= count($g['items']) ?> pending<?= $g['overdue'] ? ', <span style="color:var(--danger);">' . $g['overdue'] . ' overdue</span>' : '' ?></td></tr>
    <?php foreach ($g['items'] as $p): ?>
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
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <div class="empty-state"><?= $mine ? 'Nothing assigned to you.' : ($q !== '' || $person ? 'No pending work matches.' : 'No pending work. Everything has been quoted.') ?></div>
  <?php endif; ?>
</div>
