<div class="page-header"><h1>Team &amp; e-mail notifications</h1></div>

<div class="card">
  <div class="section-title" style="margin-top:0;">Logins</div>
  <table>
    <tr><th>Name</th><th>E-mail (login)</th><th>Role</th><th></th></tr>
    <?php foreach ($users as $u): ?>
    <tr><td><?= e($u['name']) ?></td><td><?= e($u['email']) ?></td>
      <td><span class="badge <?= $u['role'] === 'Admin' ? 'purple' : 'blue' ?>"><?= e($u['role']) ?></span></td>
      <td><form method="post" action="<?= e(url("/team/{$u['id']}/reset_password")) ?>" onsubmit="return confirm(<?= e(json_encode('Generate a new password for ' . $u['name'] . '?')) ?>)">
        <button class="btn small secondary" type="submit">Reset password</button></form></td></tr>
    <?php endforeach; ?>
  </table>
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
