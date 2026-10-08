<div class="page-header"><h1><?= $req ? 'Edit Requirement ' . e($req['req_number']) : 'Received Requirement' ?></h1></div>
<div class="card" style="max-width:720px;">
  <form method="post">
    <?php $selected_id = $req['customer_id'] ?? null; include __DIR__ . '/_customer_picker.php'; ?>
    <div class="grid-2">
      <div class="field"><label>Sales Person</label><input name="sales_person" value="<?= e($req ? ($req['sales_person'] ?? '') : current_user_name()) ?>"></div>
      <div class="field"><label>Requirement Date</label><input type="date" name="req_date" value="<?= e($req['req_date'] ?? '') ?>"></div>
    </div>
    <div class="field"><label>Expected Delivery Date</label><input type="date" name="expected_delivery" value="<?= e($req['expected_delivery'] ?? '') ?>"></div>
    <div class="field"><label>Requirement Title *</label><input name="title" placeholder="e.g. 8 GPU AI Server" required value="<?= e($req['title'] ?? '') ?>"></div>
    <div class="field">
      <label>Requirement Details</label>
      <textarea name="description" placeholder="GPU: NVIDIA H200 x8, CPU: AMD EPYC, RAM: 1TB, Storage: 30TB NVMe, Networking: 400Gb InfiniBand..."><?= e($req['description'] ?? '') ?></textarea>
    </div>
    <?php if (!$req && current_role() === 'Admin'): ?>
    <div class="assign-box">
      <b>Assign now (optional)</b>
      <div class="grid-3">
        <div class="field"><label>Assign to</label>
          <select name="assignee_id"><option value="">-- assign later --</option>
            <?php foreach ($users as $u): ?><option value="<?= e($u['id']) ?>"><?= e($u['name']) ?> (<?= e($u['role']) ?>)<?= $u['name'] === current_user_name() ? ' - me' : '' ?></option><?php endforeach; ?>
          </select></div>
        <div class="field"><label>Due date</label><input type="date" name="due_date"></div>
        <div class="field"><label>Instruction</label><input name="note" placeholder="optional note for the assignee"></div>
      </div>
    </div>
    <?php endif; ?>
    <button class="btn" type="submit"><?= $req ? 'Save Requirement' : 'Save Requirement &amp; Search BOMs' ?></button>
  </form>
</div>
<?php if ($req) { $kind = 'req'; $item_id = $req['id']; include __DIR__ . '/_thread.php'; } ?>
