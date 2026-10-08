<?php /* Full BOM line table + totals. Needs: $b (bom row), $items, $t (totals), $words (search words, may be empty) */
$h = fn($text) => $words ? hl($text, $words) : e($text);
?>
    <?php if ($items): ?>
    <table>
      <tr><th>#</th><th>Category</th><th>Component<?= $words ? ' / Model' : '' ?></th><th>Description</th><th style="text-align:right;">Qty</th><th style="text-align:right;">Unit Price</th><th style="text-align:right;">Total</th></tr>
      <?php foreach ($items as $i => $it): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><?= $h($it['category']) ?></td>
        <td><?= $h(trim(($it['manufacturer'] ?? '') . ' ' . ($it['part_number'] ?? ''))) ?></td>
        <td><?= $h($it['description'] ?: '-') ?></td>
        <td style="text-align:right;"><?= (int)$it['quantity'] ?></td>
        <td style="text-align:right;">&#8377;<?= inr($it['unit_price']) ?></td>
        <td style="text-align:right;">&#8377;<?= inr($it['quantity'] * $it['unit_price']) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php else: ?><div class="empty-state" style="padding:16px;">No components yet.</div><?php endif; ?>
    <div class="totals-box" style="margin-top:10px;">
      <div class="row"><span>Subtotal</span><span>&#8377;<?= inr($t['subtotal']) ?></span></div>
      <div class="row"><span>Margin (<?= fmt_round($b['margin_percent'], 1) ?>%)</span><span>&#8377;<?= inr($t['margin_amount']) ?></span></div>
      <div class="row"><span>GST (<?= fmt_round($b['gst_percent'], 1) ?>%)</span><span>&#8377;<?= inr($t['gst_amount']) ?></span></div>
      <div class="row grand"><span>Final Quote</span><span>&#8377;<?= inr($t['grand_total']) ?></span></div>
    </div>
