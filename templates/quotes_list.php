<div class="page-header"><h1>Quotations</h1></div>
<div class="card">
  <?php if ($quotes): ?>
  <table>
    <tr><th>Quote No.</th><th>Date</th><th>Customer</th><th>Total Amount</th><th>Status</th><th></th></tr>
    <?php foreach ($quotes as $qt): $qid = (int)$qt['id']; ?>
    <tr>
      <td><a href="<?= e(url("/quotes/$qid")) ?>"><?= e($qt['quote_number']) ?></a></td>
      <td><?= e(substr((string)($qt['quote_date'] ?: $qt['created_at']), 0, 10)) ?></td>
      <td><?= e($qt['to_company'] ?: ($qt['company'] ?: '-')) ?></td>
      <td>₹<?= inr($qt['grand_total']) ?></td>
      <td><span class="badge <?= $qt['status'] === 'SENT' ? 'blue' : 'gray' ?>"><?= e($qt['status']) ?></span></td>
      <td style="white-space:nowrap;">
        <a class="btn small secondary" href="<?= e(url("/quotes/$qid")) ?>">View</a>
        <a class="btn small secondary" href="<?= e(url("/quotes/$qid/edit")) ?>">Edit</a>
        <a class="btn small" href="<?= e(url("/quotes/$qid/docx")) ?>">Word</a>
        <a class="btn small" href="<?= e(url("/quotes/$qid/pdf")) ?>">PDF</a>
        <?php if (current_role() === 'Admin'): ?>
        <form method="post" class="inline-form" action="<?= e(url("/quotes/$qid/delete")) ?>" onsubmit="return confirm('Delete quotation <?= e($qt['quote_number']) ?>?')"><button class="btn small danger" type="submit">Delete</button></form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <div class="empty-state">No quotations generated yet. Approve a BOM and click "Generate Quotation".</div>
  <?php endif; ?>
</div>
