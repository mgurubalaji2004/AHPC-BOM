<?php /* expects: $kind ('req'|'bom'|'quote') and $item_id */
$t = thread_for($kind, (int)$item_id);
$is_admin = current_role() === 'Admin';
?>
<div class="card thread no-print">
  <div class="section-title" style="margin-top:0;">Assignment &amp; remarks
    <span style="font-weight:400;color:var(--muted);font-size:0.85rem;">&mdash; everything here is e-mailed automatically</span></div>

  <div class="assign-line">
    <?php if ($t['assignee']): ?>
      Assigned to <b><?= e($t['assignee']['name']) ?></b> <span class="badge <?= $t['assignee']['role'] === 'Admin' ? 'purple' : 'blue' ?>"><?= e($t['assignee']['role']) ?></span>
      <?php if ($t['info']['due_date']): ?>&middot; due <b style="color:<?= $t['overdue'] ? 'var(--danger)' : 'inherit' ?>;"><?= e($t['info']['due_date']) ?><?= $t['overdue'] ? ' (OVERDUE)' : '' ?></b><?php endif; ?>
    <?php else: ?><span class="badge orange">Not assigned yet</span><?php endif; ?>
  </div>

  <?php if ($is_admin): ?>
  <form method="post" action="<?= e(url("/assign/$kind/$item_id")) ?>" class="assign-form">
    <input type="hidden" name="next" value="<?= e(current_uri()) ?>">
    <select name="assignee_id" required>
      <option value="">-- assign to --</option>
      <?php foreach ($t['users'] as $u): ?><option value="<?= e($u['id']) ?>" <?= ($t['assignee'] && $u['id'] == $t['assignee']['id']) ? 'selected' : '' ?>><?= e($u['name']) ?> (<?= e($u['role']) ?>)<?= $u['name'] === current_user_name() ? ' - me' : '' ?></option><?php endforeach; ?>
    </select>
    <input type="date" name="due_date" value="<?= e($t['info']['due_date']) ?>" title="Due date">
    <input name="note" placeholder="instruction (optional)" style="flex:1;min-width:160px;">
    <button class="btn small" type="submit"><?= $t['assignee'] ? 'Re-assign' : 'Assign' ?></button>
  </form>
  <?php endif; ?>

  <form method="post" action="<?= e(url("/remarks/$kind/$item_id")) ?>" class="remark-box">
    <select name="rtype" onchange="this.form.querySelector('.newdue').style.display = this.value=='DELAY' ? 'inline-block' : 'none'">
      <option value="REMARK"><?= $is_admin ? 'Remark to engineer / admins' : 'Remark to Admin' ?></option>
      <option value="DELAY">Reason for delay</option>
    </select>
    <input name="remarks" placeholder="type your remark..." required style="flex:1;min-width:220px;">
    <input type="date" name="new_due" class="newdue" style="display:none;" title="Revised due date (optional)">
    <input type="hidden" name="next" value="<?= e(current_uri()) ?>">
    <button class="btn small secondary" type="submit">Add &amp; e-mail</button>
  </form>

  <?php if ($t['remarks']): ?>
  <div class="thread-list">
    <?php foreach ($t['remarks'] as $r): ?>
    <div class="msg <?= e(strtolower((string)$r['rtype'])) ?>">
      <div class="meta"><b><?= e($r['author_name']) ?></b>
        <span class="badge <?= $r['author_role'] === 'Admin' ? 'purple' : ($r['author_role'] ? 'blue' : 'gray') ?>"><?= e($r['author_role'] ?: 'System') ?></span>
        <?php if ($r['rtype'] === 'DELAY'): ?><span class="badge red">Reason for delay</span>
        <?php elseif ($r['rtype'] === 'ASSIGNMENT'): ?><span class="badge green">Assignment</span>
        <?php elseif ($r['rtype'] === 'REMARK'): ?><span class="badge gray"><?= $r['author_role'] === 'Admin' ? 'Remark from Admin' : 'Remark to Admin' ?></span><?php endif; ?>
        <span class="when"><?= e(substr(str_replace('T', ' ', (string)$r['created_at']), 0, 16)) ?></span></div>
      <div class="body"><?= e($r['body']) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?><div style="color:var(--muted);font-size:0.85rem;margin-top:10px;">No remarks yet.</div><?php endif; ?>
</div>
