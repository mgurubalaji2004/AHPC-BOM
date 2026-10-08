<?php $bid = (int)$bom['id']; ?>
<div class="page-header">
  <h1>Customize <?= e($bom['bom_number']) ?></h1>
  <a class="btn secondary" href="<?= e(url('/boms')) ?>">Cancel</a>
</div>
<p style="color:var(--muted);margin-top:-6px;">Starting from <b><?= e($bom['title']) ?></b><?= $bom['source_file'] ? ' (' . e($bom['source_file']) . ')' : '' ?>.
  Untick lines you do not need, change anything, add lines at the bottom, then save. A <b>new</b> BOM is created; the original stays as it is.</p>

<form method="post">
  <div class="card">
    <div class="grid-3">
      <div class="field"><label>New BOM title *</label><input name="title" required value="<?= e($bom['title']) ?>"></div>
      <div class="field"><label>For requirement (optional)</label>
        <select name="requirement_id"><option value="">-- none --</option>
          <?php foreach ($requirements as $r): ?><option value="<?= e($r['id']) ?>" <?= $requirement_id == $r['id'] ? 'selected' : '' ?>><?= e($r['req_number'] . ' - ' . $r['title'] . ($r['company'] ? ' (' . $r['company'] . ')' : '')) ?></option><?php endforeach; ?>
        </select></div>
      <div class="grid-2">
        <div class="field"><label>Margin %</label><input type="number" step="0.01" name="margin_percent" id="mp" value="<?= e(fmt_g($bom['margin_percent'])) ?>"></div>
        <div class="field"><label>GST %</label><input type="number" step="0.01" name="gst_percent" id="gp" value="<?= e(fmt_g($bom['gst_percent'])) ?>"></div>
      </div>
    </div>
    <?php $selected_id = $bom['customer_id']; include __DIR__ . '/_customer_picker.php'; ?>
  </div>

  <div class="card">
    <table class="edit-table" id="cust-table">
      <tr><th style="width:40px;">Keep</th><th>Category</th><th>Manufacturer</th><th>Model / Part No.</th><th>Description</th><th style="width:70px;">Qty</th><th style="width:120px;">Unit Price (&#8377;)</th><th style="width:110px;text-align:right;">Total</th></tr>
      <?php $n = 0; foreach ($items as $it): ?>
      <tr>
        <td><input type="checkbox" name="line[<?= $n ?>][keep]" value="1" checked class="keep"></td>
        <td><input name="line[<?= $n ?>][category]" value="<?= e($it['category']) ?>"></td>
        <td><input name="line[<?= $n ?>][manufacturer]" value="<?= e($it['manufacturer']) ?>"></td>
        <td><input name="line[<?= $n ?>][part_number]" value="<?= e($it['part_number']) ?>"></td>
        <td><input name="line[<?= $n ?>][description]" value="<?= e($it['description']) ?>"></td>
        <td><input type="number" min="1" step="1" name="line[<?= $n ?>][quantity]" value="<?= (int)$it['quantity'] ?>" class="num qty"></td>
        <td><input type="number" min="0" step="1" name="line[<?= $n ?>][unit_price]" value="<?= (int)$it['unit_price'] ?>" class="num price"></td>
        <td class="line-total" style="text-align:right;"></td>
      </tr>
      <?php $n++; endforeach; ?>
      <?php for ($k = 0; $k < 4; $k++, $n++): ?>
      <tr class="extra">
        <td><input type="checkbox" name="line[<?= $n ?>][keep]" value="1" class="keep"></td>
        <td><input name="line[<?= $n ?>][category]" placeholder="new line"></td>
        <td><input name="line[<?= $n ?>][manufacturer]"></td>
        <td><input name="line[<?= $n ?>][part_number]"></td>
        <td><input name="line[<?= $n ?>][description]"></td>
        <td><input type="number" min="1" step="1" name="line[<?= $n ?>][quantity]" value="1" class="num qty"></td>
        <td><input type="number" min="0" step="1" name="line[<?= $n ?>][unit_price]" value="0" class="num price"></td>
        <td class="line-total" style="text-align:right;"></td>
      </tr>
      <?php endfor; ?>
    </table>
    <div class="totals-box" style="margin-top:12px;">
      <div class="row"><span>Subtotal</span><span id="t-sub"></span></div>
      <div class="row"><span>Margin</span><span id="t-mar"></span></div>
      <div class="row"><span>GST</span><span id="t-gst"></span></div>
      <div class="row grand"><span>Final Quote</span><span id="t-all"></span></div>
    </div>
    <div style="margin-top:12px;"><button class="btn" type="submit">Save as new BOM</button></div>
  </div>
</form>
<script>
function inr(n){ n=Math.round(n); var s=String(Math.abs(n)),t=s.slice(-3),h=s.slice(0,-3); if(h) t=h.replace(/\B(?=(\d{2})+(?!\d))/g,',')+','+t; return (n<0?'-':'')+t; }
function rnd(x){ var f=Math.floor(x), d=x-f; return Math.abs(d-0.5)<1e-9 ? (f%2===0?f:f+1) : Math.round(x); }   // same rounding as the server
function recalc(){
  var sub=0;
  document.querySelectorAll('#cust-table tr').forEach(function(tr){
    var k=tr.querySelector('.keep'); if(!k) return;
    var v=(parseInt(tr.querySelector('.qty').value)||0)*(parseInt(tr.querySelector('.price').value)||0);
    tr.querySelector('.line-total').textContent='₹'+inr(v); tr.style.opacity=k.checked?1:.45; if(k.checked) sub+=v;
  });
  var m=rnd(sub*(parseFloat(document.getElementById('mp').value)||0)/100), g=rnd((sub+m)*(parseFloat(document.getElementById('gp').value)||0)/100);
  document.getElementById('t-sub').textContent='₹'+inr(sub); document.getElementById('t-mar').textContent='₹'+inr(m);
  document.getElementById('t-gst').textContent='₹'+inr(g); document.getElementById('t-all').textContent='₹'+inr(sub+m+g);
}
document.getElementById('cust-table').addEventListener('input', function(ev){
  var tr=ev.target.closest('tr.extra'); if(tr && ev.target.type!=='checkbox' && ev.target.value) tr.querySelector('.keep').checked=true; recalc(); });
document.getElementById('cust-table').addEventListener('change', recalc);
document.getElementById('mp').oninput=recalc; document.getElementById('gp').oninput=recalc; recalc();
</script>
