<?php
/*
 * Fills the ORIGINAL Allway HPC Word quotation template (templates_docx/quotation_template.docx)
 * with the data of a quotation, so the downloaded .docx is identical in layout to the
 * format supplied by the company (logo, header/footer, 4 pages, tables, terms, note).
 * Uses only PHP's built-in ZipArchive and DOM extensions.
 */

const DOCX_TEMPLATE = __DIR__ . '/../templates_docx/quotation_template.docx';
const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

// Row order of the "Proposal for Server" table in the template (row 1..16)
const DOCX_SPEC_ROWS = [
    'Make', 'Model', 'Chassis', 'Chipset', 'CPU', 'Memory', 'SSD', 'HDD', 'Drive Bays',
    'Expansion Slots', 'GPU', 'Network', 'Ports', 'Add-on-Card', 'Power Supply', 'Operating System',
];

/** Indian digit grouping with 2 decimals, e.g. 514050 -> 5,14,050.00 */
function inr2($x): string
{
    return inr((float)($x ?? 0), 2);
}

/** Direct <w:tag> children of a node. */
function w_children(DOMNode $node, string $tag): array
{
    $out = [];
    foreach ($node->childNodes as $c) {
        if ($c instanceof DOMElement && $c->namespaceURI === W_NS && $c->localName === $tag) {
            $out[] = $c;
        }
    }
    return $out;
}

function w_text(DOMNode $node): string
{
    $s = '';
    foreach ((new DOMXPath($node->ownerDocument))->query('.//w:t', $node) as $t) {
        $s .= $t->textContent;
    }
    return $s;
}

/** run.text = $text (keeps the run's formatting <w:rPr>). */
function w_set_run_text(DOMElement $r, string $text): void
{
    foreach (iterator_to_array($r->childNodes) as $c) {
        if (!($c instanceof DOMElement && $c->localName === 'rPr')) {
            $r->removeChild($c);
        }
    }
    $doc = $r->ownerDocument;
    foreach (preg_split('/(\t|\r\n|\n|\r)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $piece) {
        if ($piece === "\t") {
            $r->appendChild($doc->createElementNS(W_NS, 'w:tab'));
        } elseif ($piece === "\n" || $piece === "\r" || $piece === "\r\n") {
            $r->appendChild($doc->createElementNS(W_NS, 'w:br'));
        } else {
            $t = $doc->createElementNS(W_NS, 'w:t');
            $t->appendChild($doc->createTextNode($piece));
            if (trim($piece) !== $piece) {
                $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
            }
            $r->appendChild($t);
        }
    }
}

function w_add_run(DOMElement $p, string $text): DOMElement
{
    $r = $p->ownerDocument->createElementNS(W_NS, 'w:r');
    $p->appendChild($r);
    w_set_run_text($r, $text);
    return $r;
}

/** Put $text in the first run of the paragraph (keeps that run's formatting), blank the others. */
function w_set_par_text(DOMElement $p, string $text): void
{
    $runs = w_children($p, 'r');
    if (!$runs) {
        w_add_run($p, $text);
        return;
    }
    foreach ($runs as $i => $r) {
        w_set_run_text($r, $i === 0 ? $text : '');
    }
}

function w_set_cell(DOMElement $tc, string $text, ?DOMElement $like = null): void
{
    $p = w_children($tc, 'p')[0];
    if (w_children($p, 'r')) {
        w_set_par_text($p, $text);
        return;
    }
    $r = w_add_run($p, $text);
    if ($like !== null && ($lr = w_children($like, 'r'))) {
        $rpr = w_children($lr[0], 'rPr');
        if ($rpr) {
            $r->insertBefore($rpr[0]->cloneNode(true), $r->firstChild);
        }
    }
}

function w_cell(DOMElement $tbl, int $row, int $col): DOMElement
{
    return w_children(w_children($tbl, 'tr')[$row], 'tc')[$col];
}

function w_find_par(array $pars, string $startswith): ?DOMElement
{
    foreach ($pars as $p) {
        if (str_starts_with(trim(w_text($p)), $startswith)) {
            return $p;
        }
    }
    return null;
}

/**
 * $q: quote_context() array (quote_number, date, to_company, to_address, subject, specs, total_price,
 * gst_percent, gst_amount, grand_total, terms, optional note). Returns the bytes of the filled .docx.
 */
function build_docx(array $q): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'ahpcq');
    copy(DOCX_TEMPLATE, $tmp);
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        throw new RuntimeException('cannot open the quotation template');
    }
    $doc = new DOMDocument();
    $doc->preserveWhiteSpace = true;
    $doc->loadXML($zip->getFromName('word/document.xml'));
    $body = w_children($doc->documentElement, 'body')[0];
    $tables = w_children($body, 'tbl');
    $pars = w_children($body, 'p');
    [$t_no, $t_to, $t_sub, $t_spec, $t_tot] = array_slice($tables, 0, 5);

    // Quotation no. + date
    $runs = w_children(w_children(w_cell($t_no, 0, 0), 'p')[0], 'r');
    w_set_run_text($runs[1], ' No.: ');
    w_set_run_text($runs[2], (string)$q['quote_number']);
    foreach (array_slice($runs, 3) as $r) {
        w_set_run_text($r, '');
    }
    $runs = w_children(w_children(w_cell($t_no, 0, 1), 'p')[0], 'r');
    w_set_run_text($runs[0], 'Date: ' . $q['date']);
    foreach (array_slice($runs, 1) as $r) {
        w_set_run_text($r, '');
    }

    // TO block (paragraphs: TO / Company Name: / Address:)
    $to_pars = w_children(w_cell($t_to, 0, 0), 'p');
    w_set_par_text($to_pars[1], 'Company Name: ' . ($q['to_company'] ?? ''));
    w_set_par_text($to_pars[2], 'Address: ' . ($q['to_address'] ?? ''));

    // Subject
    w_set_par_text(w_children(w_cell($t_sub, 0, 0), 'p')[0], 'Sub : ' . ($q['subject'] ?? ''));

    // Spec table
    $like = w_children(w_cell($t_spec, 1, 2), 'p')[0];   // "AHPC " cell - details style
    foreach (DOCX_SPEC_ROWS as $i => $label) {
        w_set_cell(w_cell($t_spec, $i + 1, 2), (string)($q['specs'][$label] ?? ''), $like);
    }

    // Totals
    w_set_cell(w_cell($t_tot, 0, 2), inr2($q['total_price']));
    w_set_par_text(w_children(w_cell($t_tot, 1, 0), 'p')[0], 'GST @ ' . fmt_g($q['gst_percent']) . '%');
    w_set_cell(w_cell($t_tot, 1, 2), inr2($q['gst_amount']));
    w_set_cell(w_cell($t_tot, 2, 2), inr2($q['grand_total']));

    // Terms (run 3 of each numbered paragraph holds the editable text)
    $T = $q['terms'];
    $p_tax = w_find_par($pars, '1.  Taxes');
    $p_war = w_find_par($pars, '2.  Warranty');
    $p_pay = w_find_par($pars, '3.  Payment');
    $p_del = w_find_par($pars, '4.  Delivery');
    $p_val = w_find_par($pars, '5.  Validity');
    $p_ord = w_find_par($pars, '6.  Order to be released');
    $runs = w_children($p_tax, 'r');
    w_set_run_text($runs[3], $T['taxes']);
    foreach (array_slice($runs, 4) as $r) {
        w_set_run_text($r, '');
    }
    w_set_run_text(w_children($p_war, 'r')[3], $T['warranty']);
    w_set_run_text(w_children($p_pay, 'r')[3], $T['payment']);
    // delivery: runs 3 (weeks) + 4 (rest); the template has a continuation paragraph
    $next = $p_del->nextSibling;
    while ($next !== null && !($next instanceof DOMElement)) {
        $next = $next->nextSibling;
    }
    $runs = w_children($p_del, 'r');
    w_set_run_text($runs[3], $T['delivery']);
    w_set_run_text($runs[4], '');
    if ($next !== null && $next->localName === 'p' && str_contains($next->textContent, 'Processing the PO')) {
        $next->parentNode->removeChild($next);
    }
    w_set_run_text(w_children($p_val, 'r')[3], $T['validity']);
    w_set_run_text(w_children($p_ord, 'r')[3], $T['order_to']);

    // NOTE box
    if (!empty($q['note'])) {
        $cell_pars = w_children(w_cell($tables[5], 0, 0), 'p');
        if (count($cell_pars) > 1 && w_children($cell_pars[1], 'r')) {
            w_set_par_text($cell_pars[1], $q['note']);
        }
    }

    $zip->addFromString('word/document.xml', $doc->saveXML());
    $zip->close();
    $data = file_get_contents($tmp);
    unlink($tmp);
    return $data;
}

/** Convert docx bytes to PDF bytes with LibreOffice if installed; returns null otherwise. */
function docx_to_pdf(string $data): ?string
{
    $dir = sys_get_temp_dir() . '/ahpc_pdf_' . bin2hex(random_bytes(6));
    mkdir($dir, 0700);
    $src = "$dir/q.docx";
    file_put_contents($src, $data);
    $result = null;
    $exes = SOFFICE_PATH !== '' ? [SOFFICE_PATH] : ['soffice', 'libreoffice'];
    foreach ($exes as $exe) {
        $cmd = escapeshellarg($exe) . ' -env:UserInstallation=file://' . $dir . '/profile --headless --convert-to pdf --outdir '
             . escapeshellarg($dir) . ' ' . escapeshellarg($src) . ' 2>&1';
        @exec($cmd, $out, $code);
        if ($code === 0 && is_file("$dir/q.pdf")) {
            $result = file_get_contents("$dir/q.pdf");
            break;
        }
    }
    rrmdir($dir);
    return $result;
}

function rrmdir(string $dir): void
{
    foreach (array_diff(@scandir($dir) ?: [], ['.', '..']) as $name) {
        $f = "$dir/$name";
        is_dir($f) && !is_link($f) ? rrmdir($f) : @unlink($f);
    }
    @rmdir($dir);
}
