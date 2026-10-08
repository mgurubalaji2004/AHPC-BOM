<?php /* Customer dropdown with an "Add new customer" option. Needs: $customers, $selected_id (optional) */ ?>
<div class="field">
  <label>Customer *</label>
  <select name="customer_id" id="customer_id" required onchange="toggleNewCustomer(this)">
    <option value="">-- Select customer --</option>
    <?php foreach ($customers as $c): ?>
    <option value="<?= e($c['id']) ?>" <?= (!empty($selected_id) && $selected_id == $c['id']) ? 'selected' : '' ?>><?= e($c['company']) ?></option>
    <?php endforeach; ?>
    <option value="__new__">+ Add new customer...</option>
  </select>
</div>
<div id="new-customer" class="card" style="display:none;background:#f8f7ff;border:1px dashed var(--purple);">
  <div class="section-title" style="margin-top:0;">New Customer</div>
  <div class="grid-2">
    <div class="field"><label>Company Name *</label><input name="new_company" id="new_company"></div>
    <div class="field"><label>Contact Person</label><input name="new_contact_person"></div>
  </div>
  <div class="grid-2">
    <div class="field"><label>Email</label><input name="new_email" type="email"></div>
    <div class="field"><label>Phone</label><input name="new_phone"></div>
  </div>
  <div class="field"><label>Address (printed on the quotation)</label><textarea name="new_address" rows="2"></textarea></div>
</div>
<script>
function toggleNewCustomer(sel) {
  var box = document.getElementById('new-customer');
  var isNew = sel.value === '__new__';
  box.style.display = isNew ? 'block' : 'none';
  document.getElementById('new_company').required = isNew;
}
</script>
