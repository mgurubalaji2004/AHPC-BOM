<?php
$stage_order = ['DRAFT', 'UNDER_REVIEW', 'APPROVED', 'QUOTED'];
$cur_index = array_search($bom['status'], $stage_order, true);
$cur_index = $cur_index === false ? -1 : $cur_index;
$is_admin = current_role() === 'Admin';
$bid = (int)$bom['id'];
$is_lib = ($bom['source'] ?? '') === 'UPLOAD';
?>
<div class="page-header">
  <h1><?= e($bom['bom_number']) ?> <span style="color:var(--muted);font-weight:400;">v<?= e($bom['version']) ?></span></h1>
  <div style="display:flex;gap:8px;align-items:center;">
    <?php if ($bom['status'] === 'REVISION_REQUIRED'): ?><span class="badge red">REVISION REQUIRED</span><?php endif; ?>
    <?php if (!$is_lib): ?>
    <form method="post" action="<?= e(url("/boms/$bid/clone")) ?>"><button class="btn secondary" type="submit">Create another BOM from this</button></form>
    <?php if ($is_admin): ?>
    <form method="post" action="<?= e(url("/boms/$bid/delete")) ?>" onsubmit="return confirm('Delete this BOM? Its quotations are deleted too.')"><button class="btn danger" type="submit">Delete</button></form>
    <?php endif; endif; ?>
  </div>
</div>

<?php if (!$is_lib): $eng = user_by_id($bom['assigned_to']); $cust = $customers ? array_values(array_filter($customers, fn($c) => $c['id'] == $bom['customer_id'])) : []; ?>
<div class="bom-meta-strip">
  <div class="pill"><small>Quotation no.</small><b><?= e($bom['quote_number'] ?: '-') ?></b></div>
  <div class="pill"><small>Customer</small><b><?= e($cust[0]['company'] ?? '-') ?></b></div>
  <div class="pill"><small>Engineer</small><b><?= e($eng['name'] ?? ($bom['created_by'] ?: 'Not assigned')) ?></b></div>
  <div class="pill"><small>Status</small><b><?= e(ucwords(strtolower(str_replace('_', ' ', $bom['status'])))) ?></b></div>
  <div class="pill"><small>Created</small><b><?= e(substr((string)$bom['created_at'], 0, 10)) ?></b></div>
</div>
<?php endif; ?>
<?php if ($is_lib): ?>
<div class="card soft-panel">
  <b>Uploaded BOM</b><?= $bom['source_file'] ? ' from <code>' . e($bom['source_file']) . '</code>' : '' ?>. Use it as the start of a customer BOM:
  <?php $b = $bom; include __DIR__ . '/_bom_actions.php'; ?>
</div>
<?php else: ?>
<?php $kind = 'bom'; $item_id = $bid; include __DIR__ . '/_thread.php'; ?>

<div class="workflow-steps">
  <?php foreach ($stage_order as $i => $s): ?>
  <div class="step <?= $i < $cur_index ? 'done' : ($i === $cur_index ? 'active' : '') ?>"><?= e(str_replace('_', ' ', $s)) ?></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
  <form method="post" action="<?= e(url("/boms/$bid/meta")) ?>" class="grid-3">
    <div class="field"><label>BOM Title</label><input name="title" value="<?= e($bom['title']) ?>"></div>
    <div class="field">
      <label>Customer</label>
      <select name="customer_id" id="customer_id" onchange="toggleNewCustomer(this)">
        <?php foreach ($customers as $c): ?><option value="<?= e($c['id']) ?>" <?= $c['id'] == $bom['customer_id'] ? 'selected' : '' ?>><?= e($c['company']) ?></option><?php endforeach; ?>
        <option value="__new__">+ Add new customer...</option>
      </select>
    </div>
    <div class="field"><label>Assigned to</label><div style="padding:9px 0;font-size:0.9rem;"><?php $a = user_by_id($bom['assigned_to']); ?><?= e($a ? $a['name'] : 'Not assigned') ?></div></div>
    <div id="new-customer" style="display:none;grid-column:span 3;" class="grid-3">
      <div class="field"><label>New company *</label><input name="new_company" id="new_company"></div>
      <div class="field"><label>Contact</label><input name="new_contact_person"></div>
      <div class="field"><label>Phone</label><input name="new_phone"></div>
      <div class="field"><label>Email</label><input name="new_email"></div>
      <div class="field" style="grid-column:span 2;"><label>Address</label><input name="new_address"></div>
    </div>
    <div style="grid-column:span 3;"><button class="btn secondary small" type="submit">Save details</button>
      <span style="color:var(--muted);font-size:0.82rem;margin-left:8px;">Created by <?= e($bom['created_by']) ?> on <?= e(substr((string)$bom['created_at'], 0, 10)) ?></span></div>
  </form>
  <script>
  function toggleNewCustomer(sel) {
    var on = sel.value === '__new__';
    document.getElementById('new-customer').style.display = on ? 'grid' : 'none';
    document.getElementById('new_company').required = on;
  }
  </script>
  <?php if ($bom['review_comment']): ?>
  <div class="note warn">
    <b>Review comment:</b> <?= e($bom['review_comment']) ?>
  </div>
  <?php endif; ?>
  <?php if (count($versions) > 1): ?>
  <div style="margin-top:10px;font-size:0.85rem;">
    <b>Version history:</b>
    <?php foreach ($versions as $i => $v): ?>
      <?php if ($v['id'] == $bid): ?><b>v<?= e($v['version']) ?> (this)</b><?php else: ?><a href="<?= e(url("/boms/{$v['id']}")) ?>">v<?= e($v['version']) ?></a><?php endif; ?><?= $i < count($versions) - 1 ? ' &rarr; ' : '' ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if (!$is_lib && in_array($bom['status'], ['UNDER_REVIEW', 'APPROVED', 'QUOTED'], true)): ?>
  <div class="note info">
    This BOM is <b><?= e(str_replace('_', ' ', $bom['status'])) ?></b> but can still be edited. Changes are saved straight away.
    <?php if ($bom['status'] !== 'QUOTED'): ?>
    <form method="post" action="<?= e(url("/boms/$bid/reopen")) ?>" style="display:inline;"><button class="btn small secondary" type="submit">Move back to Draft for re-review</button></form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<div class="section-title">Bill of Materials <span style="font-weight:400;color:var(--muted);font-size:0.85rem;">&mdash; edit any cell, then press "Save all changes"</span></div>
<div class="card">
  <?php if ($items): ?>
  <form method="post" action="<?= e(url("/boms/$bid/save_items")) ?>" id="items-form">
  <table class="edit-table">
    <tr><th>#</th><th>Category</th><th>Manufacturer</th><th>Part No. / Model</th><th>Description</th><th style="width:70px;">Qty</th><th style="width:120px;">Unit Price (&#8377;)</th><th style="width:110px;text-align:right;">Total</th><th></th></tr>
    <?php foreach ($items as $i => $it): $iid = (int)$it['id']; ?>
    <tr>
      <td><?= $i + 1 ?><input type="hidden" name="item_id[]" value="<?= $iid ?>"></td>
      <td><input name="category_<?= $iid ?>" value="<?= e($it['category']) ?>"></td>
      <td><input name="manufacturer_<?= $iid ?>" value="<?= e($it['manufacturer']) ?>"></td>
      <td><input name="part_number_<?= $iid ?>" value="<?= e($it['part_number']) ?>"></td>
      <td><input name="description_<?= $iid ?>" value="<?= e($it['description']) ?>"></td>
      <td><input type="number" step="1" min="1" name="quantity_<?= $iid ?>" value="<?= (int)$it['quantity'] ?>" class="num qty"></td>
      <td><input type="number" step="1" min="0" name="unit_price_<?= $iid ?>" value="<?= (int)$it['unit_price'] ?>" class="num price"></td>
      <td class="line-total" style="text-align:right;">&#8377;<?= inr($it['quantity'] * $it['unit_price']) ?></td>
      <td><button class="btn small danger" type="submit" formaction="<?= e(url("/boms/$bid/remove_item/$iid")) ?>" formnovalidate onclick="return confirm('Remove this item?')">Remove</button></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <div style="margin-top:12px;"><button class="btn" type="submit">Save all changes</button></div>
  </form>
  <script>
  // live line totals (Indian number format, no decimals)
  function inr(n){ n=Math.round(n); var s=String(Math.abs(n)),t=s.slice(-3),h=s.slice(0,-3); if(h) t=h.replace(/\B(?=(\d{2})+(?!\d))/g,',')+','+t; return (n<0?'-':'')+t; }
  document.querySelectorAll('#items-form tr').forEach(function(tr){
    var q=tr.querySelector('.qty'), p=tr.querySelector('.price'), t=tr.querySelector('.line-total');
    if(!q) return;
    function upd(){ t.textContent='₹'+inr((parseInt(q.value)||0)*(parseInt(p.value)||0)); }
    q.addEventListener('input',upd); p.addEventListener('input',upd);
  });
  </script>
  <?php else: ?>
  <div class="empty-state">No components added yet. Add them below.</div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="section-title" style="margin-top:0;">Add Component from Database</div>
  <form method="post" action="<?= e(url("/boms/$bid/add_item")) ?>" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
    <div class="field" style="flex:2;min-width:260px;margin-bottom:0;">
      <label>Component</label>
      <select name="component_id" required>
        <option value="">-- Select component --</option>
        <?php foreach ($all_components as $c): ?>
        <option value="<?= e($c['id']) ?>"><?= e($c['category']) ?> — <?= e($c['manufacturer']) ?> <?= e($c['part_number']) ?> (₹<?= inr($c['selling_price']) ?>, stock <?= e($c['stock']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="width:100px;margin-bottom:0;">
      <label>Qty</label>
      <input type="number" step="1" min="1" name="quantity" value="1">
    </div>
    <button class="btn" type="submit">Add</button>
  </form>

  <div class="section-title">Or Add a Custom Line Item</div>
  <form method="post" action="<?= e(url("/boms/$bid/add_item")) ?>">
    <div class="grid-3">
      <div class="field"><label>Category</label><input name="category" placeholder="CPU / GPU / Memory / SSD ..."></div>
      <div class="field"><label>Manufacturer</label><input name="manufacturer"></div>
      <div class="field"><label>Part Number / Model</label><input name="part_number"></div>
    </div>
    <div class="grid-3">
      <div class="field"><label>Description</label><input name="description"></div>
      <div class="field"><label>Qty</label><input type="number" step="1" min="1" name="quantity" value="1"></div>
      <div class="field"><label>Unit Price (₹)</label><input type="number" step="1" min="0" name="unit_price" value="0"></div>
    </div>
    <button class="btn secondary" type="submit">Add Custom Item</button>
  </form>
</div>

<div class="section-title">Pricing (Margin + GST)</div>
<div class="card">
  <form method="post" action="<?= e(url("/boms/$bid/pricing")) ?>" style="display:flex;gap:16px;align-items:flex-end;flex-wrap:wrap;">
    <div class="field" style="margin-bottom:0;">
      <label>Pricing Mode</label>
      <select name="pricing_mode">
        <option value="AUTOMATIC" <?= $bom['pricing_mode'] === 'AUTOMATIC' ? 'selected' : '' ?>>Automatic</option>
        <option value="CUSTOMIZED" <?= $bom['pricing_mode'] === 'CUSTOMIZED' ? 'selected' : '' ?>>Customized</option>
      </select>
    </div>
    <div class="field" style="margin-bottom:0;">
      <label>Margin %</label>
      <input type="number" step="0.01" name="margin_percent" value="<?= e(fmt_g($bom['margin_percent'])) ?>">
    </div>
    <div class="field" style="margin-bottom:0;">
      <label>GST %</label>
      <input type="number" step="0.01" name="gst_percent" value="<?= e(fmt_g($bom['gst_percent'])) ?>">
    </div>
    <button class="btn secondary" type="submit">Update Pricing</button>
  </form>

  <div class="totals-box">
    <div class="row"><span>Component Subtotal</span><span>₹<?= inr($totals['subtotal']) ?></span></div>
    <div class="row"><span>Margin (<?= fmt_round($bom['margin_percent'], 2) ?>%)</span><span>₹<?= inr($totals['margin_amount']) ?></span></div>
    <div class="row"><span>Selling Price</span><span>₹<?= inr($totals['selling']) ?></span></div>
    <div class="row"><span>GST (<?= fmt_round($bom['gst_percent'], 2) ?>%)</span><span>₹<?= inr($totals['gst_amount']) ?></span></div>
    <div class="row grand"><span>Final Quote</span><span>₹<?= inr($totals['grand_total']) ?></span></div>
  </div>
</div>

<?php if (!$is_lib): ?>
<div class="section-title">Workflow Actions</div>
<div class="card no-print" style="display:flex;gap:10px;flex-wrap:wrap;">
  <?php if ($bom['status'] === 'DRAFT'): ?>
  <form method="post" action="<?= e(url("/boms/$bid/submit_review")) ?>">
    <button class="btn" type="submit" <?= $items ? '' : 'disabled title="Add at least one item"' ?>>Submit for Internal Review</button>
  </form>
  <?php endif; ?>

  <?php if ($bom['status'] === 'UNDER_REVIEW' && $is_admin): ?>
  <form method="post" action="<?= e(url("/boms/$bid/review")) ?>" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
    <div class="field" style="margin-bottom:0;min-width:260px;">
      <label>Review Comment</label>
      <input name="comment" placeholder="e.g. Increase RAM to 256GB">
    </div>
    <button class="btn success" name="decision" value="approve" type="submit">Approve</button>
    <button class="btn danger" name="decision" value="reject" type="submit">Request Revision</button>
  </form>
  <?php elseif ($bom['status'] === 'UNDER_REVIEW'): ?>
  <p style="color:var(--muted);">Waiting for Admin approval.</p>
  <?php endif; ?>

  <?php if ($bom['status'] === 'APPROVED' && !$quote): ?>
  <form method="post" action="<?= e(url("/boms/$bid/generate_quote")) ?>">
    <button class="btn" type="submit">Generate Quotation</button>
  </form>
  <?php endif; ?>

  <?php if ($quote): ?>
  <a class="btn secondary" href="<?= e(url("/quotes/{$quote['id']}")) ?>">View Quotation <?= e($quote['quote_number']) ?></a>
  <a class="btn secondary" href="<?= e(url("/quotes/{$quote['id']}/edit")) ?>">Edit Quotation</a>
  <?php endif; ?>
</div>
<?php endif; ?>
