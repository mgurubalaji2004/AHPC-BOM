<!DOCTYPE html>
<html><body style="margin:0;background:#f4f5fa;font-family:Segoe UI,Arial,sans-serif;color:#23253a;">
<div style="max-width:620px;margin:0 auto;padding:20px;">
  <div style="background:#2d3250;color:#fff;padding:14px 20px;border-radius:10px 10px 0 0;font-weight:700;letter-spacing:.5px;">
    AHPC <span style="font-weight:400;opacity:.8;">BOM &amp; Quotation System</span></div>
  <div style="background:#fff;padding:22px 20px;border:1px solid #e4e6f0;border-top:0;border-radius:0 0 10px 10px;">
    <h2 style="margin:0 0 12px;font-size:18px;color:#7c6cf6;"><?= e($headline) ?></h2>
    <div style="font-size:14px;line-height:1.55;white-space:pre-line;margin-bottom:16px;"><?= e($message) ?></div>
    <table style="border-collapse:collapse;width:100%;font-size:13px;">
      <?php foreach ($rows as [$k, $v]): ?>
      <tr><td style="padding:6px 8px;border-bottom:1px solid #eef0f7;color:#6b6f8a;width:120px;"><?= e($k) ?></td>
          <td style="padding:6px 8px;border-bottom:1px solid #eef0f7;"><?= e($v) ?></td></tr>
      <?php endforeach; ?>
    </table>
    <p style="margin:20px 0 6px;"><a href="<?= e($link) ?>" style="background:#7c6cf6;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px;">Open in BOM system</a></p>
    <p style="font-size:12px;color:#6b6f8a;margin-top:18px;">By <?= e($by) ?> &middot; <?= e($when) ?><br>
      Reply to this e-mail to reply to the sender directly. To add a remark inside the system, use the link above.</p>
  </div>
</div></body></html>
