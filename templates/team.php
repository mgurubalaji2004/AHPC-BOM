<div class="page-header"><h1>Users &amp; e-mail notifications</h1></div>

<div class="card">
  <div class="section-title" style="margin-top:0;">Users <span style="font-weight:400;color:var(--muted);font-size:0.85rem;">&mdash; everyone signs in with their own user name (or e-mail) and password</span></div>
  <table>
    <tr><th>Name</th><th>User name</th><th>E-mail</th><th>Role</th><th>Status</th><th>Open work</th><th></th></tr>
    <?php foreach ($users as $u): $me_row = $u['id'] == ($_SESSION['user_id'] ?? 0); ?>
    <tr style="<?= $u['active'] ? '' : 'opacity:.55;' ?>"><td><b><?= e($u['name']) ?></b><?= $me_row ? ' <span class="badge gray">you</span>' : '' ?></td>
      <td><code><?= e($u['username']) ?></code></td><td><?= e($u['email'] ?: '-') ?></td>
      <td><span class="badge <?= $u['role'] === 'Admin' ? 'purple' : 'blue' ?>"><?= e($u['role']) ?></span></td>
      <td><?= $u['active'] ? 'Active' : 'Disabled' ?></td>
      <td><?= (int)$u['n_assigned'] ?></td>
      <td style="white-space:nowrap;">
        <details class="inline-edit"><summary class="btn small secondary">Edit</summary>
          <form method="post" action="<?= e(url("/team/{$u['id']}/edit")) ?>">
            <label>Name<input name="name" value="<?= e($u['name']) ?>" required></label>
            <label>User name<input name="username" value="<?= e($u['username']) ?>" required></label>
            <label>E-mail<input name="email" type="email" value="<?= e($u['email']) ?>"></label>
            <label>Role<select name="role"><?php foreach (ROLES as $r): ?><option <?= $u['role'] === $r ? 'selected' : '' ?>><?= $r ?></option><?php endforeach; ?></select></label>
            <label><input type="checkbox" name="active" value="1" <?= $u['active'] ? 'checked' : '' ?>> Active (can log in)</label>
            <label>New password (optional)<input name="password" type="password" minlength="8" autocomplete="new-password"></label>
            <button class="btn small" type="submit">Save</button>
          </form></details>
        <form method="post" class="inline-form" action="<?= e(url("/team/{$u['id']}/reset_password")) ?>" onsubmit="return confirm(<?= e(json_encode('Generate a new password for ' . $u['name'] . '?')) ?>)">
          <button class="btn small secondary" type="submit">Reset password</button></form>
        <?php if (!$me_row): ?>
        <form method="post" class="inline-form" action="<?= e(url("/team/{$u['id']}/delete")) ?>" onsubmit="return confirm(<?= e(json_encode('Delete user ' . $u['name'] . '? Their open work becomes unassigned.')) ?>)">
          <button class="btn small danger" type="submit">Delete</button></form>
        <?php endif; ?>
      </td></tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="card" style="max-width:760px;">
  <div class="section-title" style="margin-top:0;">Add user</div>
  <form method="post" action="<?= e(url('/team/create')) ?>">
    <div class="grid-3">
      <div class="field"><label>Name *</label><input name="name" required placeholder="e.g. Muthumadhan"></div>
      <div class="field"><label>User name (login) *</label><input name="username" required pattern="[A-Za-z0-9._-]{3,60}" placeholder="e.g. muthumadhan"></div>
      <div class="field"><label>E-mail (for notifications)</label><input name="email" type="email" placeholder="name@allwayhpc.com"></div>
    </div>
    <div class="grid-3">
      <div class="field"><label>Role</label><select name="role"><option>Engineer</option><option>Admin</option></select></div>
      <div class="field"><label>Password (empty = generate one)</label><input name="password" type="password" minlength="8" autocomplete="new-password"></div>
      <div class="field" style="align-self:end;"><button class="btn" type="submit">Create user</button></div>
    </div>
  </form>
  <p style="color:var(--muted);font-size:0.82rem;margin:0;">Admins can add, edit and delete users, assign work and add remarks. Engineers do the BOM / quotation work.</p>
</div>

<div class="card">
  <div class="section-title" style="margin-top:0;">Zoho Mail</div>
  <?php if ($mail_ok): ?>
    <p>Sending as <b><?= e($mail['user']) ?></b> via <?= e($mail['host']) ?>:<?= e($mail['port']) ?>. Links in mails point to
      <b><?= e($mail['base_url'] ?: '(this server address)') ?></b>.</p>
    <form method="post" action="<?= e(url('/team/test_mail')) ?>"><button class="btn small" type="submit">Send me a test e-mail</button></form>
  <?php else: ?>
    <p class="flash error" style="margin:0 0 8px;">Zoho Mail is not configured yet, so e-mails are only being logged (status SKIPPED).
      Open <code>config.php</code>, fill in the Zoho settings (mailbox, password, server address), save and reload.</p>
  <?php endif; ?>
</div>

<div class="card">
  <div class="section-title" style="margin-top:0;">E-mail log (last 100)</div>
  <?php if ($log): ?>
  <table>
    <tr><th>When</th><th>Event</th><th>Ref</th><th>To</th><th>Subject</th><th>Status</th></tr>
    <?php foreach ($log as $l): ?>
    <tr><td style="white-space:nowrap;"><?= e(str_replace('T', ' ', (string)$l['created_at'])) ?></td><td><?= e($l['event']) ?></td><td><?= e($l['ref']) ?></td>
      <td style="font-size:0.8rem;"><?= e($l['recipients']) ?></td><td style="font-size:0.85rem;"><?= e($l['subject']) ?></td>
      <td><span class="badge <?= $l['status'] === 'SENT' ? 'green' : ($l['status'] === 'FAILED' ? 'red' : 'gray') ?>"><?= e($l['status']) ?></span>
        <?php if ($l['error']): ?><div style="font-size:0.72rem;color:var(--danger);"><?= e($l['error']) ?></div><?php endif; ?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?><div class="empty-state">No e-mails yet.</div><?php endif; ?>
</div>
