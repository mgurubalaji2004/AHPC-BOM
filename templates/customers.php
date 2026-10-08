<div class="page-header"><h1>Customers</h1></div>

<div class="grid-2">
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
      <tr><th>Company</th><th>Contact</th><th>Email</th><th>Phone</th><th>Address</th></tr>
      <?php foreach ($customers as $c): ?>
      <tr><td><?= e($c['company']) ?></td><td><?= e($c['contact_person'] ?: '-') ?></td><td><?= e($c['email'] ?: '-') ?></td><td><?= e($c['phone'] ?: '-') ?></td><td><?= e($c['address'] ?: '-') ?></td></tr>
      <?php endforeach; ?>
    </table>
    <?php else: ?>
    <div class="empty-state">No customers yet.</div>
    <?php endif; ?>
  </div>
</div>
