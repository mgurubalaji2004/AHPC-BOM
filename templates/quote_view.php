<?php $qid = (int)$q['id']; ob_start(); ?>
<div class="qhead">
  <img src="<?= e(asset('logo.jpg')) ?>" alt="Allway HPC">
  <div class="addr">6, 2nd Floor, Kakkan Nagar 3rd Main Road,<br>2nd Cross Street, Adambakkam,<br>Chennai, Tamil Nadu &ndash; 600088<br>+91 96325 99044</div>
</div>
<?php $head = ob_get_clean(); ob_start(); ?>
<div class="qfoot"><div class="in">
  <span>&#9993; <a href="mailto:sales@allwayhpc.com">sales@allwayhpc.com</a></span>
  <span>Chennai | Hyderabad | Bangalore | Mumbai</span>
  <span>&#127760; <a href="http://www.allwayhpc.com">www.allwayhpc.com</a></span>
</div></div>
<?php $foot = ob_get_clean(); ?>
<link rel="stylesheet" href="<?= e(asset('quote.css')) ?>">
<div class="page-header no-print">
  <h1>Quotation <?= e($q['quote_number']) ?> <span class="badge <?= $q['status'] === 'SENT' ? 'blue' : 'gray' ?>" style="vertical-align:middle;"><?= e($q['status']) ?></span></h1>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <a class="btn" href="<?= e(url("/quotes/$qid/edit")) ?>">Edit Quotation</a>
    <a class="btn secondary" href="<?= e(url("/quotes/$qid/docx")) ?>">Download Word (.docx)</a>
    <a class="btn secondary" href="<?= e(url("/quotes/$qid/pdf")) ?>">Download PDF</a>
    <button class="btn secondary" onclick="window.print()">Print</button>
    <?php if (current_role() === 'Admin'): ?>
    <form method="post" class="inline-form" action="<?= e(url("/quotes/$qid/delete")) ?>" onsubmit="return confirm('Delete this quotation?')"><button class="btn danger" type="submit">Delete</button></form>
    <?php endif; ?>
  </div>
</div>

<div class="card no-print" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
  <form method="post" action="<?= e(url("/quotes/$qid/refresh")) ?>">
    <button class="btn small secondary" type="submit" onclick="return confirm('Re-read components and price from the BOM? Edited configuration rows and price will be replaced (Make, Model, Ports, OS are kept).')">Refresh from BOM</button>
  </form>
  <a class="btn small secondary" href="<?= e(url("/boms/{$q['bom_id']}")) ?>">Open / edit BOM</a>
  <?php if ($q['status'] !== 'SENT'): ?>
  <form method="post" action="<?= e(url("/quotes/$qid/status")) ?>"><input type="hidden" name="status" value="SENT"><button class="btn small success" type="submit">Mark as Sent to Customer</button></form>
  <?php else: ?>
  <form method="post" action="<?= e(url("/quotes/$qid/status")) ?>"><input type="hidden" name="status" value="DRAFT"><button class="btn small secondary" type="submit">Move back to Draft</button></form>
  <?php endif; ?>
</div>

<div class="no-print"><?php $kind = 'quote'; $item_id = $qid; include __DIR__ . '/_thread.php'; ?></div>


<div class="qdoc">
<!-- PAGE 1 -->
<div class="qpage">
  <?= $head ?>
  <div class="qtitle"><h2>Quotation Cover Letter</h2><div>High Performance Server / Workstation Proposal</div></div>
  <div class="qno"><span class="n">QUOTATION No.: <?= e($q['quote_number']) ?></span><span class="d">Date: <?= e($q['date']) ?></span></div>
  <div class="qto"><b class="k">TO</b>
    <div class="row"><b>Company Name:</b> <?= e($q['to_company']) ?></div>
    <div class="row"><b>Address:</b> <?= e($q['to_address']) ?></div>
  </div>
  <div class="qsub">Sub : <?= e($q['subject']) ?></div>
  <p class="dear">Dear Sir/Madam,</p>
  <p>Greetings from ALLWAY HPC Private Limited.</p>
  <p>Thank you for giving us the opportunity to submit our proposal for your High-Performance Computing requirements. Based on our discussions and your technical requirements, we are pleased to present our quotation for your kind consideration.</p>
  <p>ALLWAY HPC Private Limited is a technology-driven organization specializing in High Performance Computing (HPC), Artificial Intelligence and Machine Learning (AI/ML), GPU Computing, Enterprise Servers, High-End Workstations, Storage Solutions, and Data Center Infrastructure. We deliver powerful, reliable, and scalable computing platforms designed to meet the evolving demands of research institutions, government organizations, educational institutions, enterprises, and AI-driven industries.</p>
  <p>Our team of experienced engineers focuses on proven technologies. Every solution is carefully designed to maximize performance, scalability, and long-term reliability while ensuring cost-effective deployment and dependable technical support throughout the product lifecycle.</p>
  <p>We appreciate the opportunity to serve your organization and look forward to establishing a long-term business relationship.</p>
  <?= $foot ?>
</div>

<!-- PAGE 2 -->
<div class="qpage qabout">
  <?= $head ?>
  <div class="qtitle"><h2>About ALLWAY HPC Pvt. Ltd.</h2><div>Engineering high-performance computing platforms</div></div>
  <h3>WHO WE ARE</h3>
  <p>ALLWAY HPC Private Limited specializes in delivering High Performance Computing (HPC), Artificial Intelligence (AI), GPU Computing, Enterprise Servers, High-End Workstations, Storage Solutions, and Data Center Infrastructure. Backed by an experienced engineering team, we provide customized computing solutions, system integration, deployment, and technical support, enabling customers to achieve reliable performance, scalability, and operational excellence through innovative technology solutions.</p>
  <h3>OUR APPROACH</h3>
  <p>Organizations today require technology solutions that provide maximum performance, scalability, and reliability while remaining cost-effective. At ALLWAY HPC, we understand these challenges and deliver customized HPC, AI, GPU, Server, Storage, and Workstation solutions that combine advanced technologies, flexible configurations, and dependable performance to maximize customer value.</p>
  <h3>ENGINEERING EXCELLENCE</h3>
  <p>Continuous innovation and engineering excellence enable our technical team to deliver high-quality computing platforms built using globally recognized OEM components. Every system is carefully designed, assembled, validated, and tested to ensure consistent performance, long-term reliability, and future scalability.</p>
  <h3>CUSTOMER-CENTRIC SOLUTIONS</h3>
  <p>We believe every customer has unique computing requirements. Instead of offering standard hardware configurations, we focus on delivering customized infrastructure designed around customer workloads, technical objectives, and future expansion plans while maintaining the highest standards of quality and reliability.</p>
  <h3>BUILT TO SCALE</h3>
  <p>Technology continues to evolve rapidly, and organizations require infrastructure that can adapt to changing business requirements. Our solutions are designed with scalability, flexibility, and operational efficiency in mind, enabling customers to maximize the value of their technology investments while ensuring long-term business continuity.</p>
  <h3>OUR COMMITMENT</h3>
  <p>Our commitment to quality, customer satisfaction, and technical excellence enables us to build long-term relationships with customers across multiple industries. We continue to provide professional consultation, solution design, deployment, installation, and post-sales technical support to ensure every customer receives dependable computing infrastructure and responsive service.</p>
  <p>We hope you will find our proposal in line with your requirements. Should you require any additional information or technical clarification, please feel free to contact us. Our team will be pleased to assist you.</p>
  <?= $foot ?>
</div>

<!-- PAGE 3 -->
<div class="qpage">
  <?= $head ?>
  <div class="qtitle"><h2>Proposal for Server</h2><div>Configuration and commercial summary</div></div>
  <table class="qtable">
    <tr><th style="width:17%;">CATEGORY</th><th style="width:21%;">SPECIFICATION</th><th>DETAILS</th></tr>
    <?php foreach (SPEC_GROUPS as [$group, $labels]): foreach ($labels as $i => $l): ?>
      <tr>
        <?php if ($i === 0): ?><td class="cat" rowspan="<?= count($labels) ?>"><?= e($group) ?></td><?php endif; ?>
        <td class="sp"><?= e($l) ?></td><td><?= e($q['specs'][$l]) ?></td>
      </tr>
    <?php endforeach; endforeach; ?>
  </table>
  <table class="qtotals">
    <tr><td>Total Price</td><td style="width:34px;">Rs.</td><td class="v"><?= inr($q['total_price'], 2) ?></td></tr>
    <tr class="b"><td>GST @ <?= fmt_g($q['gst_percent']) ?>%</td><td>Rs.</td><td class="v"><?= inr($q['gst_amount'], 2) ?></td></tr>
    <tr class="b"><td>Total Amount</td><td>Rs.</td><td class="v"><?= inr($q['grand_total'], 2) ?></td></tr>
  </table>
  <?= $foot ?>
</div>

<!-- PAGE 4 -->
<div class="qpage">
  <?= $head ?>
  <div class="qtitle"><h2>Terms &amp; Conditions</h2><div class="qterms-note" style="font-size:9px;">Please review the following before order confirmation</div></div>
  <ul class="qterms">
    <?php foreach (TERM_LABELS as $i => [$key, $label]): ?>
    <li><span class="num"><?= $i + 1 ?>.</span><b><?= e($label) ?></b> &mdash; <?= e($q['terms'][$key]) ?></li>
    <?php endforeach; ?>
  </ul>
  <div class="qnote"><b>NOTE</b><span>ALLWAY HPC Private Limited shall not be held responsible for any delay or inability to fulfil its obligations due to events beyond its reasonable control. Such events may include, but are not limited to, natural disasters, war, terrorism, civil unrest, pandemics, government restrictions, transportation delays, labour strikes, shortages of materials, power interruptions, or supply chain disruptions. In such situations, delivery and performance obligations may be extended for a reasonable period until normal business operations resume. If such circumstances continue for an extended duration and significantly affect order execution, ALLWAY HPC Private Limited reserves the right to revise, suspend, or cancel the affected portion of the order after providing appropriate notice to the customer. Any products delivered or services completed prior to such cancellation shall remain payable by the customer.</span></div>
  <div class="qsign">Thanks &amp; Regards,<br><b>ALLWAY HPC PRIVATE LIMITED</b></div>
  <?= $foot ?>
</div>
</div>
