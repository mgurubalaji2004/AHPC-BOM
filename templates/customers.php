<div class="page-header"><h1>Customers</h1></div>

<div class="grid-2 cust-grid">
  <div class="card">
    <div class="section-title" style="margin-top:0;">Add Customer</div>
    <form method="post">
      <div class="field"><label>Company </label><input name="company" required></div>
      <div class="field"><label>Contact Person</label><input name="contact_person"></div>
      <div class="field"><label>Email</label><input name="email" type="email"></div>
      <div class="field"><label>Phone</label><input name="phone"></div>
      <div class="field"><label>Address (printed on quotation)</label><textarea name="address"></textarea></div>
      <button class="btn" type="submit">Save Customer</button>
    </form>
  </div>

  <div class="card">
    <div class="section-title" style="margin-top:0;">All Customers</div>
    <?php if ($customers): ?>
    <table>
      <tr><th>Company</th><th>Contact</th><th>Email</th><th>Phone</th><th>Address</th><th>BOMs</th><?php if (current_role() === 'Admin'): ?><th></th><?php endif; ?></tr>
      <?php foreach ($customers as $c): ?>
      <tr><td><a href="<?= e(url('/boms', ['tab' => 'library', 'q' => $c['company']])) ?>"><?= e($c['company']) ?></a></td><td><?= e($c['contact_person'] ?: '-') ?></td><td><?= e($c['email'] ?: '-') ?></td><td><?= e($c['phone'] ?: '-') ?></td><td><?= e($c['address'] ?: '-') ?></td>
        <td><?= (int)$c['n_boms'] ?></td>
        <?php if (current_role() === 'Admin'): ?>
        <td style="white-space:nowrap;">
          <details class="inline-edit"><summary class="btn small secondary">Edit</summary>
            <form method="post" action="<?= e(url("/customers/{$c['id']}/edit")) ?>">
              <input name="company" value="<?= e($c['company']) ?>" required placeholder="Company">
              <input name="contact_person" value="<?= e($c['contact_person']) ?>" placeholder="Contact">
              <input name="email" value="<?= e($c['email']) ?>" placeholder="Email">
              <input name="phone" value="<?= e($c['phone']) ?>" placeholder="Phone">
              <textarea name="address" placeholder="Address"><?= e($c['address']) ?></textarea>
              <button class="btn small" type="submit">Save</button>
            </form></details>
          <form method="post" class="inline-form" action="<?= e(url("/customers/{$c['id']}/delete")) ?>" onsubmit="return confirm(<?= e(json_encode('Delete customer ' . $c['company'] . '? Their BOMs and quotations are kept.')) ?>)"><button class="btn small danger" type="submit">Delete</button></form>
        </td>
        <?php endif; ?></tr>
      <?php endforeach; ?>
    </table>
    <?php else: ?>
    <div class="empty-state">No customers yet.</div>
    <?php endif; ?>
  </div>
</div>
