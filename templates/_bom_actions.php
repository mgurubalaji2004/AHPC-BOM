<?php /* Use / Customize / Open as new / View / Delete buttons for one BOM. Needs $b; optional $req_id */
$req_id = $req_id ?? null; $bid = (int)$b['id']; ?>
    <div class="bom-actions">
      <a class="btn small" href="<?= e(url('/boms/new', ['copy_from' => $bid, 'requirement_id' => $req_id])) ?>" title="Copy every line into a new BOM for a customer / requirement">Use this BOM</a>
      <a class="btn small secondary" href="<?= e(url("/boms/$bid/customize", ['requirement_id' => $req_id])) ?>" title="Choose lines, change models, quantities and prices, then save as a new BOM">Customize</a>
      <form method="post" action="<?= e(url("/boms/$bid/clone")) ?>"><button class="btn small secondary" type="submit" title="Make an editable copy straight away">Open as new BOM</button></form>
      <a class="btn small secondary" href="<?= e(url("/boms/$bid")) ?>">View / edit</a>
      <?php if (current_role() === 'Admin'): ?>
      <form method="post" action="<?= e(url("/boms/$bid/delete")) ?>" onsubmit="return confirm(<?= e(json_encode('Delete ' . $b['bom_number'] . ' (' . $b['title'] . ')? Its quotations are deleted too.')) ?>)"><button class="btn small danger" type="submit">Delete</button></form>
      <?php endif; ?>
    </div>
