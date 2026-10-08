<?php /* Tracker form fields. Needs $w (row or []), $users */ $v = fn($k) => $w[$k] ?? ''; ?>
    <div class="grid-4">
      <div class="field"><label>Date</label><input type="date" name="entry_date" value="<?= e($v('entry_date')) ?>"></div>
      <div class="field"><label>Received date</label><input type="date" name="received_date" value="<?= e($v('received_date')) ?>"></div>
      <div class="field"><label>Quotation number</label><input name="quote_number" value="<?= e($v('quote_number')) ?>" placeholder="auto"></div>
      <div class="field"><label>Region</label><input name="region" value="<?= e($v('region')) ?>" placeholder="TN / KA / TS ..."></div>
    </div>
    <div class="grid-3">
      <div class="field"><label>Customer name *</label><input name="customer_name" required value="<?= e($v('customer_name')) ?>"></div>
      <div class="field"><label>Company name</label><input name="company_name" value="<?= e($v('company_name')) ?>"></div>
      <div class="field"><label>Contact (mail / phone)</label><input name="contact" value="<?= e($v('contact')) ?>"></div>
    </div>
    <div class="grid-3">
      <div class="field" style="grid-column:span 2;"><label>Requirement / product</label><input name="requirement" value="<?= e($v('requirement')) ?>"></div>
      <div class="field"><label>Quantity</label><input name="quantity" value="<?= e($v('quantity')) ?>"></div>
    </div>
    <div class="grid-4">
      <div class="field"><label>Assigned engineer</label>
        <select name="engineer_id"><option value="">-- other / none --</option>
          <?php foreach ($users as $u): ?><option value="<?= e($u['id']) ?>" <?= ($w['engineer_id'] ?? null) == $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="field"><label>or engineer name</label><input name="engineer_other" value="<?= e(empty($w['engineer_id']) ? $v('engineer_name') : '') ?>" placeholder="not a user (e.g. Bala)"></div>
      <div class="field"><label>Start date</label><input type="date" name="start_date" value="<?= e($v('start_date')) ?>"></div>
      <div class="field"><label>Due date</label><input type="date" name="due_date" value="<?= e($v('due_date')) ?>"></div>
    </div>
    <div class="grid-4">
      <div class="field"><label>Stage</label>
        <select name="stage"><?php foreach (STAGES as $k => $label): ?><option value="<?= $k ?>" <?= ($w['stage'] ?? 'OPEN') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Status</label><input name="status" value="<?= e($v('status')) ?>" placeholder="Quote sent / Waiting for price ..." list="status-list">
        <datalist id="status-list"><option>Requirement received</option><option>Bom need to be created</option><option>BOM created</option><option>Waiting for price</option><option>Quote ready</option><option>Quote sent</option><option>Order Closed</option><option>Cancelled</option></datalist></div>
      <div class="field"><label>Quote sent date</label><input type="date" name="quote_sent_date" value="<?= e($v('quote_sent_date')) ?>"></div>
      <div class="field"><label>PO status</label><input name="po_status" value="<?= e($v('po_status')) ?>"></div>
    </div>
    <div class="grid-2">
      <div class="field"><label>Remarks</label><textarea name="remarks" rows="2"><?= e($v('remarks')) ?></textarea></div>
      <div class="field"><label>Follow-up</label><textarea name="followup" rows="2"><?= e($v('followup')) ?></textarea></div>
    </div>
