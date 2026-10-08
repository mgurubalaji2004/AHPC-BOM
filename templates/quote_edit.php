<div class="page-header">
  <h1>Edit Quotation <?= e($q['quote_number']) ?></h1>
  <a class="btn secondary" href="<?= e(url("/quotes/{$q['id']}")) ?>">Cancel</a>
</div>
<form method="post">
  <div class="card">
    <div class="section-title" style="margin-top:0;">Cover letter</div>
    <div class="grid-3">
      <div class="field"><label>Quotation No.</label><input name="quote_number" value="<?= e($q['quote_number']) ?>"></div>
      <div class="field"><label>Date</label><input type="date" name="quote_date" value="<?= e($q['date_iso']) ?>"></div>
      <div class="field"><label>Status</label>
        <select name="status"><option value="DRAFT" <?= $q['status'] !== 'SENT' ? 'selected' : '' ?>>Draft</option><option value="SENT" <?= $q['status'] === 'SENT' ? 'selected' : '' ?>>Sent to customer</option></select></div>
    </div>
    <div class="field"><label>Company Name</label><input name="to_company" value="<?= e($q['to_company']) ?>"></div>
    <div class="field"><label>Address</label><textarea name="to_address" rows="2"><?= e($q['to_address']) ?></textarea></div>
    <div class="field"><label>Subject</label><input name="subject" value="<?= e($q['subject']) ?>"></div>
  </div>

  <div class="card">
    <div class="section-title" style="margin-top:0;">Proposal for Server &mdash; configuration table</div>
    <?php foreach (SPEC_GROUPS as [$group, $labels]): ?>
      <div style="font-weight:700;color:var(--navy);margin:10px 0 4px;"><?= e($group) ?></div>
      <?php foreach ($labels as $l): ?>
      <div class="field" style="display:grid;grid-template-columns:170px 1fr;gap:10px;align-items:center;margin-bottom:6px;">
        <label style="margin:0;"><?= e($l) ?></label><input name="spec[<?= e($l) ?>]" value="<?= e($q['specs'][$l]) ?>">
      </div>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <div class="section-title" style="margin-top:0;">Commercials</div>
    <div class="grid-3">
      <div class="field"><label>Total Price (Rs., before GST)</label><input type="number" step="1" min="0" name="total_price" id="tp" value="<?= (int)$q['total_price'] ?>"></div>
      <div class="field"><label>GST %</label><input type="number" step="0.01" name="gst_percent" id="gp" value="<?= e(fmt_g($q['gst_percent'])) ?>"></div>
      <div class="field"><label>Total Amount (auto)</label><input id="ta" disabled value="<?= inr($q['grand_total']) ?>"></div>
    </div>
    <script>
    function calc(){var t=parseFloat(document.getElementById('tp').value)||0,g=parseFloat(document.getElementById('gp').value)||0;
      document.getElementById('ta').value=(Math.round(t)+Math.round(t*g/100)).toLocaleString('en-IN');}
    document.getElementById('tp').oninput=calc;document.getElementById('gp').oninput=calc;
    </script>
    <p style="color:var(--muted);font-size:0.82rem;margin:0;">Tip: "Refresh from BOM" on the quotation page re-reads price and components from the BOM; you can then still adjust anything here.</p>
  </div>

  <div class="card">
    <div class="section-title" style="margin-top:0;">Terms &amp; Conditions</div>
    <?php foreach (TERM_LABELS as $i => [$key, $label]): ?>
    <div class="field"><label><?= $i + 1 ?>. <?= e($label) ?></label><input name="term[<?= e($key) ?>]" value="<?= e($q['terms'][$key]) ?>"></div>
    <?php endforeach; ?>
  </div>
  <button class="btn" type="submit">Save Quotation</button>
</form>
