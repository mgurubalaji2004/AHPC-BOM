<?php
/*
 * The Allway HPC quotation as a PDF, drawn directly (same 4 pages as the Word template and the
 * on-screen view). Used for "Download PDF" when LibreOffice is not installed on the server.
 */

require_once __DIR__ . '/pdf.php';

const QP_NAVY = '#182a4a';
const QP_DARK = '#0e2841';
const QP_ORANGE = '#e05a22';
const QP_GREY = '#6e7580';
const QP_TEXT = '#2b2f36';
const QP_PANEL = '#f2f3f5';
const QP_L = 42.0;                 // left margin (pt)
const QP_R = Pdf::W - 42.0;        // right edge of the text area
const QP_BOTTOM = Pdf::H - 62.0;   // content must stay above the footer

const QP_COVER = [
    'Greetings from ALLWAY HPC Private Limited.',
    'Thank you for giving us the opportunity to submit our proposal for your High-Performance Computing requirements. Based on our discussions and your technical requirements, we are pleased to present our quotation for your kind consideration.',
    'ALLWAY HPC Private Limited is a technology-driven organization specializing in High Performance Computing (HPC), Artificial Intelligence and Machine Learning (AI/ML), GPU Computing, Enterprise Servers, High-End Workstations, Storage Solutions, and Data Center Infrastructure. We deliver powerful, reliable, and scalable computing platforms designed to meet the evolving demands of research institutions, government organizations, educational institutions, enterprises, and AI-driven industries.',
    'Our team of experienced engineers focuses on proven technologies. Every solution is carefully designed to maximize performance, scalability, and long-term reliability while ensuring cost-effective deployment and dependable technical support throughout the product lifecycle.',
    'We appreciate the opportunity to serve your organization and look forward to establishing a long-term business relationship.',
];
const QP_ABOUT = [
    ['WHO WE ARE', 'ALLWAY HPC Private Limited specializes in delivering High Performance Computing (HPC), Artificial Intelligence (AI), GPU Computing, Enterprise Servers, High-End Workstations, Storage Solutions, and Data Center Infrastructure. Backed by an experienced engineering team, we provide customized computing solutions, system integration, deployment, and technical support, enabling customers to achieve reliable performance, scalability, and operational excellence through innovative technology solutions.'],
    ['OUR APPROACH', 'Organizations today require technology solutions that provide maximum performance, scalability, and reliability while remaining cost-effective. At ALLWAY HPC, we understand these challenges and deliver customized HPC, AI, GPU, Server, Storage, and Workstation solutions that combine advanced technologies, flexible configurations, and dependable performance to maximize customer value.'],
    ['ENGINEERING EXCELLENCE', 'Continuous innovation and engineering excellence enable our technical team to deliver high-quality computing platforms built using globally recognized OEM components. Every system is carefully designed, assembled, validated, and tested to ensure consistent performance, long-term reliability, and future scalability.'],
    ['CUSTOMER-CENTRIC SOLUTIONS', 'We believe every customer has unique computing requirements. Instead of offering standard hardware configurations, we focus on delivering customized infrastructure designed around customer workloads, technical objectives, and future expansion plans while maintaining the highest standards of quality and reliability.'],
    ['BUILT TO SCALE', 'Technology continues to evolve rapidly, and organizations require infrastructure that can adapt to changing business requirements. Our solutions are designed with scalability, flexibility, and operational efficiency in mind, enabling customers to maximize the value of their technology investments while ensuring long-term business continuity.'],
    ['OUR COMMITMENT', 'Our commitment to quality, customer satisfaction, and technical excellence enables us to build long-term relationships with customers across multiple industries. We continue to provide professional consultation, solution design, deployment, installation, and post-sales technical support to ensure every customer receives dependable computing infrastructure and responsive service.'],
];
const QP_ABOUT_END = 'We hope you will find our proposal in line with your requirements. Should you require any additional information or technical clarification, please feel free to contact us. Our team will be pleased to assist you.';
const QP_NOTE = 'ALLWAY HPC Private Limited shall not be held responsible for any delay or inability to fulfil its obligations due to events beyond its reasonable control. Such events may include, but are not limited to, natural disasters, war, terrorism, civil unrest, pandemics, government restrictions, transportation delays, labour strikes, shortages of materials, power interruptions, or supply chain disruptions. In such situations, delivery and performance obligations may be extended for a reasonable period until normal business operations resume. If such circumstances continue for an extended duration and significantly affect order execution, ALLWAY HPC Private Limited reserves the right to revise, suspend, or cancel the affected portion of the order after providing appropriate notice to the customer. Any products delivered or services completed prior to such cancellation shall remain payable by the customer.';

/** New page with the letterhead and footer; returns the y where content starts. */
function qp_page(Pdf $p): float
{
    $p->addPage();
    $p->image(BASE_DIR . '/static/logo.jpg', QP_L, 30, 127.5);
    $p->font('Times-Roman', 7.1);
    $y = 38;
    foreach (['6, 2nd Floor, Kakkan Nagar 3rd Main Road,', '2nd Cross Street, Adambakkam,', 'Chennai, Tamil Nadu - 600088', '+91 96325 99044'] as $l) {
        $p->textRight(QP_R, $y, $l, QP_GREY);
        $y += 10.3;
    }
    // footer
    $fy = Pdf::H - 40;
    $p->line(QP_L, $fy, QP_R, $fy, '#c9ccd2', 0.75);
    $p->font('Helvetica', 6.4);
    $p->text(QP_L, $fy + 14, 'sales@allwayhpc.com', '#1c64f2');
    $p->link(QP_L, $fy + 6, $p->width('sales@allwayhpc.com'), 10, 'mailto:sales@allwayhpc.com');
    $p->textCenter(Pdf::W / 2, $fy + 14, 'Chennai | Hyderabad | Bangalore | Mumbai', QP_NAVY);
    $p->textRight(QP_R, $fy + 14, 'www.allwayhpc.com', '#1c64f2');
    $p->link(QP_R - $p->width('www.allwayhpc.com'), $fy + 6, $p->width('www.allwayhpc.com'), 10, 'http://www.allwayhpc.com');
    return 112;
}

function qp_title(Pdf $p, float $y, string $title, string $sub): float
{
    $p->line(QP_L, $y, QP_R, $y, QP_NAVY, 0.75);
    $p->font('Times-Bold', 12);
    $p->text(QP_L, $y + 15, $title, QP_NAVY);
    $p->font('Times-Italic', 6.8);
    $p->text(QP_L, $y + 26, $sub, QP_GREY);
    $p->line(QP_L, $y + 32, QP_R, $y + 32, QP_NAVY, 0.75);
    return $y + 32;
}

/** Content of a quotation (quote_context() array) as PDF bytes. */
function build_quote_pdf(array $q): string
{
    $p = new Pdf();
    $w = QP_R - QP_L;

    // ---- page 1: cover letter
    $y = qp_title($p, qp_page($p), 'Quotation Cover Letter', 'High Performance Server / Workstation Proposal');
    $y += 20;
    $p->font('Times-Bold', 8.3);
    $p->text(QP_L, $y, 'QUOTATION No.: ' . $q['quote_number'], QP_ORANGE, 1.1);
    $p->textRight(QP_R, $y, 'Date: ' . $q['date'], QP_NAVY);
    $y += 22;
    $p->font('Times-Roman', 7.5);
    $addr = $p->wrap('Address: ' . $q['to_address'], $w - 21);
    $box_h = max(50, 30 + 11 * (1 + count($addr)));
    $p->rect(QP_L, $y, $w, $box_h, QP_PANEL);
    $p->font('Times-Bold', 7.5);
    $p->text(QP_L + 10.5, $y + 15, 'TO', QP_ORANGE, 0.75);
    $ty = $y + 30;
    $p->text(QP_L + 10.5, $ty, 'Company Name:', QP_NAVY);
    $p->font('Times-Roman', 7.5);
    $p->text(QP_L + 10.5 + $p->width('Company Name: ', 'Times-Bold'), $ty, $q['to_company'], QP_NAVY);
    foreach ($addr as $i => $line) {
        $ty += 11;
        if ($i === 0) {
            $p->font('Times-Bold', 7.5);
            $p->text(QP_L + 10.5, $ty, 'Address:', QP_NAVY);
            $p->font('Times-Roman', 7.5);
            $p->text(QP_L + 10.5 + $p->width('Address: ', 'Times-Bold'), $ty, substr($line, strlen('Address: ')), QP_NAVY);
        } else {
            $p->text(QP_L + 10.5, $ty, $line, QP_NAVY);
        }
    }
    $y += $box_h + 21;
    $p->font('Times-Bold', 7.5);
    $sub = $p->wrap('Sub : ' . $q['subject'], $w - 18);
    $sh = 14 + 10 * count($sub);
    $p->rect(QP_L, $y, $w, $sh, QP_NAVY);
    $p->paragraph(QP_L + 9, $y + 12.5, $w - 18, 'Sub : ' . $q['subject'], 10, '#ffffff');
    $y += $sh + 22;
    $p->font('Times-Bold', 7.9);
    $p->text(QP_L, $y, 'Dear Sir/Madam,', QP_NAVY);
    $y += 19;
    $p->font('Times-Roman', 7.9);
    foreach (QP_COVER as $para) {
        $y = $p->paragraph(QP_L, $y, $w, $para, 12.2, QP_TEXT, true) + 7.5;
    }

    // ---- page 2: about
    $y = qp_title($p, qp_page($p), 'About ALLWAY HPC Pvt. Ltd.', 'Engineering high-performance computing platforms');
    $y += 8;
    foreach (QP_ABOUT as [$h, $text]) {
        $y += 14;
        $p->rect(QP_L, $y - 8, 2.25, 10, QP_ORANGE);
        $p->font('Times-Bold', 7.9);
        $p->text(QP_L + 7.5, $y, $h, QP_NAVY, 0.75);
        $y += 13;
        $p->font('Times-Roman', 7.5);
        $y = $p->paragraph(QP_L, $y, $w, $text, 11.6, QP_TEXT, true);
    }
    $y += 6;
    $p->paragraph(QP_L, $y, $w, QP_ABOUT_END, 11.6, QP_TEXT, true);

    // ---- page 3: configuration table + totals
    $y = qp_title($p, qp_page($p), 'Proposal for Server', 'Configuration and commercial summary');
    $y += 12;
    $c1 = $w * 0.17;
    $c2 = $w * 0.21;
    $c3 = $w - $c1 - $c2;
    $head = function (float $y) use ($p, $w, $c1, $c2): float {
        $p->rect(QP_L, $y, $w, 15, QP_DARK);
        $p->font('Times-Bold', 7.5);
        $p->text(QP_L + 5, $y + 10.5, 'CATEGORY', '#ffffff', 0.4);
        $p->text(QP_L + $c1 + 5, $y + 10.5, 'SPECIFICATION', '#ffffff', 0.4);
        $p->text(QP_L + $c1 + $c2 + 5, $y + 10.5, 'DETAILS', '#ffffff', 0.4);
        return $y + 15;
    };
    $y = $head($y);
    foreach (SPEC_GROUPS as [$group, $labels]) {
        $p->font('Times-Roman', 7.5);
        $rows = [];
        foreach ($labels as $l) {
            $lines = $p->wrap((string)$q['specs'][$l], $c3 - 10);
            $rows[] = [$l, $lines, max(13, 4.5 + 9.6 * count($lines))];
        }
        $gh = array_sum(array_column($rows, 2));
        if ($y + min($gh, 200) > QP_BOTTOM) {
            $y = $head(qp_page($p) + 6);
        }
        $gy = $y;
        $p->rect(QP_L, $gy, $c1, $gh, QP_PANEL);
        foreach ($rows as [$label, $lines, $rh]) {
            if ($y + $rh > QP_BOTTOM) {   // a very long row: continue on a new page
                $p->rect(QP_L, $gy, $w, $y - $gy, null, '#aeb4bf', 0.75);
                $y = $gy = $head(qp_page($p) + 6);
                $p->rect(QP_L, $gy, $c1, $gh, QP_PANEL);
            }
            $p->line(QP_L + $c1, $y, QP_R, $y, '#aeb4bf', 0.75);
            $p->font('Times-Bold', 7.5);
            $p->text(QP_L + $c1 + 5, $y + 9.5, $label, QP_NAVY);
            $p->font('Times-Roman', 7.5);
            $ly = $y + 9.5;
            foreach ($lines as $line) {
                $p->text(QP_L + $c1 + $c2 + 5, $ly, $line, QP_TEXT);
                $ly += 9.6;
            }
            $y += $rh;
        }
        $p->font('Times-Bold', 7.5);
        $p->text(QP_L + 5, $gy + 9.5, $group, QP_NAVY);
        $p->line(QP_L, $gy, QP_L + $c1, $gy, '#aeb4bf', 0.75);
        $p->rect(QP_L, $gy, $w, $y - $gy, null, '#aeb4bf', 0.75);
        $p->line(QP_L + $c1, $gy, QP_L + $c1, $y, '#aeb4bf', 0.75);
        $p->line(QP_L + $c1 + $c2, $gy, QP_L + $c1 + $c2, $y, '#aeb4bf', 0.75);
    }
    $y += 10.5;
    if ($y + 50 > QP_BOTTOM) {
        $y = qp_page($p) + 6;
    }
    $tw = 229;
    $tx = QP_R - $tw;
    foreach ([['Total Price', $q['total_price'], false], ['GST @ ' . fmt_g($q['gst_percent']) . '%', $q['gst_amount'], true],
              ['Total Amount', $q['grand_total'], true]] as [$label, $amount, $bold]) {
        $p->rect($tx, $y, $tw, 15, QP_DARK);
        $p->line($tx, $y + 15, $tx + $tw, $y + 15, '#ffffff', 0.75);
        $p->font($bold ? 'Times-Bold' : 'Times-Roman', 7.5);
        $p->text($tx + 6, $y + 10.5, $label, '#ffffff');
        $p->text($tx + $tw - 82.5 - 25, $y + 10.5, 'Rs.', '#ffffff');
        $p->textRight($tx + $tw - 6, $y + 10.5, inr($amount, 2), '#ffffff');
        $y += 15;
    }

    // ---- page 4: terms, note, signature
    $y = qp_title($p, qp_page($p), 'Terms & Conditions', 'Please review the following before order confirmation');
    $y += 18;
    foreach (TERM_LABELS as $i => [$key, $label]) {
        $p->font('Times-Bold', 7.5);
        $p->text(QP_L, $y, ($i + 1) . '.', QP_ORANGE);
        $lx = QP_L + 12;
        $p->text($lx, $y, $label, QP_NAVY);
        $lx += $p->width($label . ' ');
        $p->font('Times-Roman', 7.5);
        $p->text($lx, $y, '- ', QP_TEXT);
        $lx += $p->width('- ');
        $lines = $p->wrap((string)$q['terms'][$key], QP_R - $lx);
        foreach ($lines as $j => $line) {
            $p->text($j === 0 ? $lx : QP_L + 12, $y, $line, QP_TEXT);
            $y += 10.9;
        }
        $y += 3;
    }
    $y += 14;
    $p->font('Times-Italic', 6.4);
    $note = $p->wrap(QP_NOTE, $w - 22);
    $nh = 24 + 9.6 * count($note);
    $p->rect(QP_L, $y, $w, $nh, QP_PANEL);
    $p->rect(QP_L, $y, 2.25, $nh, QP_ORANGE);
    $p->font('Helvetica-Bold', 6);
    $p->text(QP_L + 11, $y + 12, 'NOTE', QP_ORANGE, 0.75);
    $p->font('Times-Italic', 6.4);
    $p->paragraph(QP_L + 11, $y + 23, $w - 22, QP_NOTE, 9.6, '#4a505a', true);
    $y += $nh + 22;
    $p->font('Times-Roman', 7.5);
    $p->text(QP_L, $y, 'Thanks & Regards,', QP_TEXT);
    $p->font('Times-Bold', 7.5);
    $p->text(QP_L, $y + 11, 'ALLWAY HPC PRIVATE LIMITED', QP_NAVY);

    return $p->output();
}
