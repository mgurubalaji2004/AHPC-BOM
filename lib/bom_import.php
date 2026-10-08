<?php
/*
 * Reads BOM spreadsheets (Excel .xlsx, or Zoho Sheet "HTML" exports) and turns them into BOMs.
 * The sheets are hand-made and vary a lot, so the reader looks for:
 *   - a header row ("Components | Model | Unit Price | Quantity | Rate ...", in any order), or
 *   - a column full of component names (Chassis, CPU, Memory, SSD ...) when there is no header,
 * then reads the lines under it until the totals ("Sub total", "Margin", "GST", "Total Price").
 * Several option blocks in one sheet (one under the other, or side by side) become separate BOMs.
 */

const CATEGORY_WORDS = ['chassis', 'cabinet', 'motherboard', 'mother board', 'mainboard', 'cpu', 'processor', 'heatsink',
    'heat sink', 'cooler', 'memory', 'ram', 'ssd', 'nvme', 'hdd', 'hard disk', 'storage', 'gpu', 'graphics', 'graphic card',
    'power supply', 'psu', 'smps', 'raid', 'hba', 'network', 'nic', 'lan card', 'os', 'operating system', 'keyboard',
    'mouse', 'monitor', 'accessories', 'ups', 'rack', 'switch', 'cable', 'riser', 'fan', 'optical', 'dvd', 'warranty',
    'installation', 'software', 'license', 'transceiver', 'sfp', 'controller', 'add on card', 'add-on card', 'bmc',
    'drive', 'backplane', 'rail', 'kvm', 'server', 'workstation', 'node', 'battery', 'm.2'];

// ---------------------------------------------------------------------------
// Reading files into grids: [sheet name => [row index => [col index => value]]]
// ---------------------------------------------------------------------------

function xlsx_col_index(string $ref): int
{
    $letters = preg_replace('/\d+/', '', $ref);
    $n = 0;
    foreach (str_split($letters) as $ch) {
        $n = $n * 26 + (ord($ch) - 64);
    }
    return $n - 1;
}

function xml_load(string $xml): ?DOMDocument
{
    if ($xml === '') {
        return null;
    }
    $d = new DOMDocument();
    return @$d->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE) ? $d : null;
}

function read_xlsx(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('not a valid .xlsx file');
    }
    $strings = [];
    if ($d = xml_load((string)$zip->getFromName('xl/sharedStrings.xml'))) {
        foreach ($d->getElementsByTagName('si') as $si) {
            $s = '';
            foreach ($si->getElementsByTagName('t') as $t) {
                $s .= $t->textContent;
            }
            $strings[] = $s;
        }
    }
    $rels = [];
    if ($d = xml_load((string)$zip->getFromName('xl/_rels/workbook.xml.rels'))) {
        foreach ($d->getElementsByTagName('Relationship') as $r) {
            $rels[$r->getAttribute('Id')] = $r->getAttribute('Target');
        }
    }
    $sheets = [];
    $wb = xml_load((string)$zip->getFromName('xl/workbook.xml'));
    foreach ($wb ? $wb->getElementsByTagName('sheet') : [] as $s) {
        $rid = $s->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
        $target = ltrim($rels[$rid] ?? '', '/');
        $target = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
        $d = xml_load((string)$zip->getFromName($target));
        if (!$d) {
            continue;
        }
        $grid = [];
        foreach ($d->getElementsByTagName('c') as $c) {
            $ref = $c->getAttribute('r');
            if ($ref === '') {
                continue;
            }
            $row = (int)preg_replace('/\D+/', '', $ref) - 1;
            $col = xlsx_col_index($ref);
            $type = $c->getAttribute('t');
            $v = null;
            foreach ($c->childNodes as $ch) {
                if ($ch->nodeName === 'v') {
                    $v = $ch->textContent;
                } elseif ($ch->nodeName === 'is') {
                    $v = $ch->textContent;
                }
            }
            if ($v === null) {
                continue;
            }
            if ($type === 's') {
                $v = $strings[(int)$v] ?? '';
            } elseif ($type === 'b') {
                $v = $v ? 'TRUE' : 'FALSE';
            } elseif ($type === '' || $type === 'n') {
                $v = is_numeric($v) ? $v + 0 : $v;
            }
            $grid[$row][$col] = is_string($v) ? trim($v) : $v;
        }
        ksort($grid);
        $sheets[$s->getAttribute('name')] = $grid;
    }
    $zip->close();
    return $sheets;
}

/** Zoho Sheet "publish as HTML" export: absolutely positioned div rows / cells. */
function read_zoho_html(string $path): array
{
    $html = file_get_contents($path);
    $title = preg_match('/<title>(.*?)<\/title>/is', $html, $m) ? trim(html_entity_decode(strip_tags($m[1]))) : '';
    $grid = [];
    $lefts = [];
    $rows = preg_split("/<div class='row' /", $html);
    array_shift($rows);
    foreach ($rows as $ri => $rowhtml) {
        preg_match_all("/<div style='[^']*?left:(\d+)px;[^']*' class='cD[^']*'>(.*?)<\/div>(?=<div|$)/s", $rowhtml, $cells, PREG_SET_ORDER);
        foreach ($cells as $c) {
            $text = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>'], ' ', $c[2])), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $text = trim(preg_replace('/\s+/u', ' ', $text));
            if ($text === '') {
                continue;
            }
            $lefts[(int)$c[1]] = true;
            $grid[$ri][(int)$c[1]] = $text;
        }
    }
    // pixel positions -> column numbers
    ksort($lefts);
    $colmap = array_flip(array_keys($lefts));
    $out = [];
    foreach ($grid as $r => $cells) {
        foreach ($cells as $left => $v) {
            $num = to_number($v);
            $out[$r][$colmap[$left]] = $num !== null ? ($num == (int)$num ? (int)$num : $num) : $v;
        }
        ksort($out[$r]);
    }
    return [$title !== '' ? $title : basename($path, '.html') => $out];
}

function read_bom_file(string $path, ?string $name = null): array
{
    $ext = strtolower(pathinfo($name ?? $path, PATHINFO_EXTENSION));
    return match ($ext) {
        'xlsx', 'xlsm' => read_xlsx($path),
        'html', 'htm' => read_zoho_html($path),
        default => throw new RuntimeException("unsupported file type .$ext (use .xlsx)"),
    };
}

// ---------------------------------------------------------------------------
// Grid -> BOM blocks
// ---------------------------------------------------------------------------

function cell_text($v): string
{
    return is_string($v) ? trim(preg_replace('/\s+/u', ' ', $v)) : (is_numeric($v) ? (string)$v : '');
}

function to_number($v): ?float
{
    if (is_int($v) || is_float($v)) {
        return (float)$v;
    }
    $s = str_replace([',', '₹', 'Rs.', 'Rs', 'INR', ' '], '', (string)$v);
    return is_numeric($s) ? (float)$s : null;
}

function is_category_word(string $s): bool
{
    $s = strtolower(trim($s, " \t:-.*"));
    if ($s === '' || strlen($s) > 30) {
        return false;
    }
    foreach (CATEGORY_WORDS as $w) {
        if ($s === $w || str_starts_with($s, $w . ' ') || str_ends_with($s, ' ' . $w) || str_contains($s, $w) && strlen($s) <= strlen($w) + 12) {
            return true;
        }
    }
    return false;
}

function header_role(string $s): ?string
{
    $s = strtolower(trim($s, " \t:.*"));
    return match (true) {
        (bool)preg_match('/^(components?|category|item|items|particulars|part|parts|hardware|type)$/', $s) => 'cat',
        (bool)preg_match('/^(model|models|description|specifications?|configuration|details|part ?(no|number)|product)/', $s) => 'model',
        (bool)preg_match('/^(qty|quantity|nos|no\.? of units|units?)$/', $s) => 'qty',
        (bool)preg_match('/^(unit ?(price|prize|cost|rate)|price|cost|rate per unit|per unit|price per unit|unit)$/', $s) => 'unit',
        (bool)preg_match('/^(rate|total|total ?(price|prize|cost|amount)|amount|sub ?total|value|net)$/', $s) => 'total',
        (bool)preg_match('/^(from|vendor|supplier|source|indicate changes)/', $s) => 'vendor',
        default => null,
    };
}

function summary_kind(string $s): ?string
{
    $s = strtolower($s);
    if (preg_match('/margin/', $s)) return 'margin';
    if (preg_match('/\bgst\b|\btax\b|igst|cgst/', $s)) return 'gst';
    if (preg_match('/sub ?-?total/', $s)) return 'subtotal';
    if (preg_match('/^(grand )?total|total (price|amount|cost)|^net (total|amount|price)\b/', $s)) return 'total';
    return null;
}

/** Split a grid into regions (one per component column) and read the BOM blocks of each. */
function parse_bom_grid(array $grid): array
{
    $meta = ['customer' => '', 'quote_no' => ''];
    // header rows: a row with a 'cat' cell and at least 2 other recognised headings
    $headers = [];
    foreach ($grid as $r => $cells) {
        $roles = [];
        foreach ($cells as $c => $v) {
            if (is_string($v) && ($role = header_role($v))) {
                $roles[$c] = $role;
            }
        }
        $cats = array_keys($roles, 'cat', true);
        if ($cats && count($roles) >= 3) {
            $headers[$r] = $roles;
        } elseif (count(array_intersect($roles, ['model', 'qty', 'unit', 'total'])) >= 3 && !$cats) {
            // no "Components" heading: the column left of the model column holds the names
            $model = array_search('model', $roles, true);
            if ($model !== false && $model > 0) {
                $roles[$model - 1] = 'cat';
                $headers[$r] = $roles;
            }
        }
        foreach ($cells as $c => $v) {   // "Customer : X" / "Quotation No" above the first table
            if (!is_string($v) || count($headers) > ($cats && count($roles) >= 3 ? 1 : 0)) {
                continue;
            }
            if ($meta['customer'] === '' && preg_match('/^customer\s*[:\-]?\s*(.*)$/i', $v, $m)) {
                $meta['customer'] = trim($m[1]) !== '' ? trim($m[1]) : cell_text($cells[$c + 1] ?? '');
                if (preg_match('/^date/i', $meta['customer'])) {
                    $meta['customer'] = '';
                }
            }
            if (preg_match('/^qu?o?a?tation no\.?\s*[:\-]?\s*(.*)$/i', $v, $m)) {
                $meta['quote_no'] = trim($m[1]) !== '' ? trim($m[1]) : cell_text($cells[$c + 1] ?? '');
            }
        }
    }

    // component-name columns (with or without header)
    $cat_cols = [];
    foreach ($headers as $roles) {
        foreach ($roles as $c => $role) {
            if ($role === 'cat') {
                $cat_cols[$c] = true;
            }
        }
    }
    if (!$cat_cols) {
        $hits = [];
        foreach ($grid as $cells) {
            foreach ($cells as $c => $v) {
                if (is_string($v) && is_category_word($v)) {
                    $hits[$c] = ($hits[$c] ?? 0) + 1;
                }
            }
        }
        foreach ($hits as $c => $n) {
            // the model column next to the names also contains words like "ssd" - keep only the left one
            if ($n >= 3 && !(($hits[$c - 1] ?? 0) >= 3)) {
                $cat_cols[$c] = true;
            }
        }
    }
    ksort($cat_cols);
    $cat_cols = array_keys($cat_cols);
    $blocks = [];
    foreach ($cat_cols as $i => $cc) {
        $end = $cat_cols[$i + 1] ?? PHP_INT_MAX;
        foreach (parse_region($grid, $cc, $end, $headers) as $b) {
            $blocks[] = $b;
        }
    }
    return [$blocks, $meta];
}

/** Columns of a region: [cat, model, qty, unit, total] from its header row, else guessed from the numbers. */
function region_columns(array $grid, int $cc, int $end, ?array $roles): array
{
    $cols = ['cat' => $cc, 'model' => null, 'qty' => null, 'unit' => null, 'total' => null];
    if ($roles) {
        foreach ($roles as $c => $role) {
            if ($c > $cc && $c < $end && array_key_exists($role, $cols) && $cols[$role] === null && $role !== 'cat') {
                $cols[$role] = $c;
            }
        }
    }
    $cols['model'] ??= $cc + 1;
    if ($cols['qty'] !== null || $cols['unit'] !== null || $cols['total'] !== null) {
        return $cols;
    }
    // no header: look at numeric columns right of the model column on rows that have a component name
    $num = [];
    foreach ($grid as $cells) {
        if (!is_string($cells[$cc] ?? null) || !is_category_word($cells[$cc])) {
            continue;
        }
        foreach ($cells as $c => $v) {
            if ($c > $cols['model'] && $c < $end && to_number($v) !== null) {
                $num[$c][] = to_number($v);
            }
        }
    }
    ksort($num);
    $nc = array_keys($num);
    if (count($nc) >= 3) {
        // try every order of the first three numeric columns: unit * qty = total
        [$a, $b, $c] = array_slice($nc, 0, 3);
        $best = null;
        foreach ([[$a, $b, $c], [$b, $a, $c], [$a, $c, $b]] as [$u, $q, $t]) {
            $score = 0;
            foreach ($grid as $cells) {
                $U = to_number($cells[$u] ?? null);
                $Q = to_number($cells[$q] ?? null);
                $T = to_number($cells[$t] ?? null);
                if ($U && $Q && $T && abs($U * $Q - $T) < 1) {
                    $score++;
                }
            }
            if ($best === null || $score > $best[0]) {
                $best = [$score, $u, $q, $t];
            }
        }
        [, $cols['unit'], $cols['qty'], $cols['total']] = $best;
        // quantities are small whole numbers; prices are not
        if ($best[0] === 0) {
            $avg = fn($c) => array_sum($num[$c]) / max(1, count($num[$c]));
            if ($avg($cols['qty']) > $avg($cols['unit'])) {
                [$cols['qty'], $cols['unit']] = [$cols['unit'], $cols['qty']];
            }
        }
    } elseif (count($nc) === 2) {
        $avg = fn($c) => array_sum($num[$c]) / max(1, count($num[$c]));
        [$x, $y] = $nc;
        [$cols['qty'], $cols['unit']] = $avg($x) <= 64 ? [$x, $y] : ($avg($y) <= 64 ? [$y, $x] : [null, $x]);
    } elseif (count($nc) === 1) {
        $cols['unit'] = $nc[0];
    }
    return $cols;
}

function parse_region(array $grid, int $cc, int $end, array $headers): array
{
    $blocks = [];
    $cur = null;
    $cols = null;
    $blank = 0;
    $close = function () use (&$cur, &$blocks) {
        $sum = 0;
        foreach ($cur['lines'] ?? [] as $l) {
            $sum += $l['quantity'] * $l['unit_price'];
        }
        if ($cur && $cur['lines'] && !(count($cur['lines']) === 1 && $sum == 0)) {   // ignore stray single empty lines
            $blocks[] = $cur;
        }
        $cur = null;
    };
    $default_cols = region_columns($grid, $cc, $end, null);
    $pending_label = '';
    $pending_is_customer = false;
    $new_block = function () use (&$pending_label, &$pending_is_customer) {
        $b = ['lines' => [], 'margin' => null, 'gst' => null, 'label' => $pending_label, 'summary' => false, 'total' => null,
              'label_is_customer' => $pending_is_customer];
        $pending_label = '';
        $pending_is_customer = false;
        return $b;
    };
    foreach ($grid as $r => $cells) {
        $roles = $headers[$r] ?? null;
        if ($roles && ($roles[$cc] ?? null) === 'cat') {
            $close();
            $cols = region_columns($grid, $cc, $end, $roles);
            $cur = $new_block();
            continue;
        }
        // "Customer | Workstation for editing" rows name the next block; "Quotation No" rows are skipped
        $first = '';
        foreach ($cells as $c => $v) {
            if ($c >= $cc && $c < $end && is_string($v) && $v !== '') {
                $first = $v;
                break;
            }
        }
        if (preg_match('/^(customer|qu?o?a?tation no|reference|date)\b\s*[:\-]?\s*(.*)$/i', $first, $m)) {
            if (strtolower($m[1]) === 'customer') {
                $val = trim($m[2]);
                if ($val === '') {
                    foreach ($cells as $c => $v) {
                        if ($c > $cc && $c < $end && is_string($v) && !preg_match('/^date/i', $v) && $v !== $first) {
                            $val = trim($v);
                            break;
                        }
                    }
                }
                if ($val !== '') {
                    if ($cur && $cur['lines']) {
                        $close();
                    }
                    $pending_label = $val;
                    $pending_is_customer = true;
                }
            }
            continue;
        }
        $cols_now = $cols ?? $default_cols;
        $region = array_filter($cells, fn($c) => $c >= $cc && $c < $end, ARRAY_FILTER_USE_KEY);
        if (!$region) {
            if (++$blank >= 3) {
                $close();
            }
            continue;
        }
        $blank = 0;
        $cat = cell_text($cells[$cc] ?? '');
        $model = cell_text($cells[$cols_now['model']] ?? '');
        $texts = implode(' ', array_map('cell_text', array_filter($region, 'is_string')));

        // totals / margin / gst rows
        $kind = summary_kind($texts);
        $numbers_only = $cat === '' && $model === '' && !array_filter($region, 'is_string');
        if ($kind || ($numbers_only && $cur && $cur['lines'])) {
            if ($cur) {
                $cur['summary'] = true;
                if ($kind === 'margin' && preg_match('/(\d+(?:\.\d+)?)\s*%/', $texts, $m)) {
                    $cur['margin'] = (float)$m[1];
                } elseif ($kind === 'gst' && preg_match('/(\d+(?:\.\d+)?)\s*%/', $texts, $m)) {
                    $cur['gst'] = (float)$m[1];
                }
                foreach ($region as $v) {   // a bare 0.15 next to the margin line
                    if (is_float($v) && $v > 0 && $v < 1 && $cur['margin'] === null) {
                        $cur['margin'] = round($v * 100, 2);
                    }
                }
                if ($kind === 'total' || $numbers_only) {
                    $close();
                }
            }
            continue;
        }
        $has_number = (bool)array_filter($region, fn($v) => !is_string($v) && to_number($v) !== null);
        if (($cat === '' && $model === '') || ($cat === '' && !$has_number)) {
            // a title row, e.g. "option a" / "Desktop News & Digital"
            if ($cur && $cur['lines']) {
                $close();
            }
            if ($cur) {
                $cur['label'] = $cur['label'] ?: $texts;
            } elseif ($pending_label === '') {
                $pending_label = $texts;
                $pending_is_customer = false;
            }
            continue;
        }
        if (is_numeric($cat) && $model === '') {
            continue;
        }
        if ($cur && $cur['summary']) {
            $close();
        }
        if ($cols === null && !is_category_word($cat) && $cat !== '' && !$cur) {
            continue;   // text above the table that is not a component
        }
        $cur ??= $new_block();
        $qty = $cols_now['qty'] !== null ? to_number($cells[$cols_now['qty']] ?? null) : null;
        $unit = $cols_now['unit'] !== null ? to_number($cells[$cols_now['unit']] ?? null) : null;
        $total = $cols_now['total'] !== null ? to_number($cells[$cols_now['total']] ?? null) : null;
        if ($unit === null && $total !== null) {
            $unit = $total / max(1, $qty ?: 1);
        }
        if ($model === '' && !$unit) {
            continue;   // empty placeholder row of the template
        }
        if ($cat === '' || is_numeric($cat)) {
            $cat = guess_category($model);
        }
        $qty = $qty ?: 1;
        if ($unit !== null && $unit > 0 && $unit <= 500 && $qty > 1000) {
            [$qty, $unit] = [$unit, $qty];   // quantity and price columns the wrong way round
        }
        if ($qty > 5000) {
            $qty = 1;
        }
        $cur['lines'][] = [
            'category' => mb_substr(normalize_category($cat, $model), 0, 120),
            'model' => $model,
            'quantity' => max(1, (int)round($qty ?: 1)),
            'unit_price' => max(0, (int)round($unit ?? 0)),
        ];
    }
    $close();
    return $blocks;
}

/** One spelling per kind of part: "Raid Controlller" -> "RAID Controller", "HEAT SINK" -> "Heatsink" ... */
function normalize_category(string $cat, string $model = ''): string
{
    $c = strtolower(trim(preg_replace('/\s+/', ' ', $cat), " :-.*"));
    $map = [
        '/^(chassis|cabinet|case|.*chassis)$/' => 'Chassis',
        '/^(mother ?board|mainboard|mb|board)$/' => 'Motherboard',
        '/^(cpu|processor|processors|cpus)$/' => 'CPU',
        '/^(heat ?sink|heatsinks?|cpu cooler|cooler|cooling|air cooler|liquid cooler|fan)$/' => 'Heatsink',
        '/^(memory|ram|dimm|ddr\d?)$/' => 'Memory',
        '/^(graphics?|graphic card|graphics card|gpu|gpus|vga)$/' => 'GPU',
        '/^(hdd|hard ?disk|hard drive|sas hdd|sata hdd|hdds)$/' => 'HDD',
        '/^(ssd|sata ssd|ssd sata|m\.?2 ssd|boot ssd)$/' => 'SSD',
        '/^(nvme|nvme ssd|nvme m\.?2|nvme m\.?2 ssd|nvme u\.?2 ssd|u\.?2 ssd|m\.?2 nvme)$/' => 'NVMe SSD',
        '/^(raid|raid card|raid controll?l?er|raid controller card|hba|hba controller)$/' => 'RAID Controller',
        '/^(raid cables?|raid card cables?)$/' => 'RAID Cables',
        '/^(nic|network|networl|network card|lan|lan card|network adapter|ethernet)$/' => 'Network Card',
        '/^(psu|power ?supply|smps|power|rps)$/' => 'Power Supply',
        '/^(os|operating system)$/' => 'Operating System',
        '/^(keyboard ?(&|and)? ?mouse|keyboard|mouse|kb ?(&|and)? ?mouse)$/' => 'Keyboard & Mouse',
        '/^(monitor|display)$/' => 'Monitor',
        '/^(additional|accessories|accessory|others?|misc)$/' => 'Accessories',
    ];
    foreach ($map as $re => $name) {
        if (preg_match($re, $c)) {
            return $name;
        }
    }
    // a model written in the category column ("64gb ram ddr5", "960GB SSD", "4u 4 gpu")
    if (preg_match('/\d/', $c)) {
        $g = guess_category($cat . ' ' . $model);
        if ($g !== 'Other') {
            return normalize_category($g);
        }
    }
    return $cat === mb_strtolower($cat) || $cat === mb_strtoupper($cat) ? ucwords(mb_strtolower($cat)) : $cat;
}

function guess_category(string $model): string
{
    $m = strtolower($model);
    if (preg_match('/\b\d\s*u\b/', $m)) {
        return 'Chassis';
    }
    $map = ['chassis' => 'Chassis', 'cabinet' => 'Chassis', 'motherboard' => 'Motherboard', 'epyc' => 'CPU', 'xeon' => 'CPU',
            'core i' => 'CPU', 'ryzen' => 'CPU', 'threadripper' => 'CPU', 'ddr' => 'Memory', 'ram' => 'Memory', 'nvme' => 'SSD',
            'ssd' => 'SSD', 'hdd' => 'HDD', 'rtx' => 'GPU', 'nvidia' => 'GPU', 'gpu' => 'GPU', 'h100' => 'GPU', 'h200' => 'GPU',
            'l40' => 'GPU', 'a100' => 'GPU', 'psu' => 'Power Supply', 'power' => 'Power Supply', 'rps' => 'Power Supply', 'smps' => 'Power Supply', 'raid' => 'RAID Controller',
            'hba' => 'HBA', 'network' => 'Network Card', 'gbe' => 'Network Card', 'nic' => 'Network Card', '10g' => 'Network Card',
            'keyboard' => 'Accessories', 'mouse' => 'Accessories', 'monitor' => 'Monitor', 'ubuntu' => 'Operating System',
            'windows' => 'Operating System', 'linux' => 'Operating System', 'heatsink' => 'Heatsink', 'cooler' => 'Cooler'];
    foreach ($map as $k => $v) {
        if (str_contains($m, $k)) {
            return $v;
        }
    }
    return 'Other';
}

/** Customer name from a file name: "20260001469_linux labs 28-09-2026 17_11_47_224.xlsx" -> "Linux Labs" */
function title_from_filename(string $file): string
{
    $s = pathinfo($file, PATHINFO_FILENAME);
    $s = preg_replace('/\d{2}-\d{2}-\d{4}\s+[\d_]+/', ' ', $s);   // export timestamps
    $s = preg_replace('/\b\d{8,}\b/', ' ', $s);                     // quotation numbers
    return trim(preg_replace('/[\s_]+/', ' ', $s), " -_");
}

function customer_from_filename(string $file): string
{
    $s = clean_customer_name(title_from_filename($file));
    if ($s === '' || preg_match('/^(untitled spreadsheet|bom|description|sheet\s*\d*|new basic|budgetary p|serial|format)$/i', $s)) {
        return '';
    }
    return $s;
}

/** Drop configuration words a file name often carries: "IISC 4U 8GPU" -> "IISC", "Signal Tron 1U" -> "Signal Tron". */
function clean_customer_name(string $s): string
{
    $s = trim(preg_replace('/[\s_]+/', ' ', $s), " -_");
    $noise = '\d+\s*U|\d+\s*GPU|\d+(\.\d+)?\s*(TB|PB)|GPU|servers?|workstations?|desktops?|AMD|Intel|i[3579]\s*\d*|storage|spare|'
           . 'edition|barebon\w*|tower|rack|HDDs?|kernel|thread|BOM|AHPC|H\d00|BOQ|\d{3,}';
    do {
        $before = $s;
        $s = trim(preg_replace('/(^|\s)(' . $noise . ')$/i', '', $s), " -_");
        $s = trim(preg_replace('/^(' . $noise . ')(\s|$)/i', '', $s), " -_");
    } while ($s !== $before && $s !== '');
    return $s;
}

/**
 * Parse one file into BOM records:
 * [['title', 'customer', 'margin', 'gst', 'lines' => [...], 'source_file', 'quote_no'], ...]
 */
function parse_bom_file(string $path, ?string $name = null): array
{
    $name ??= basename($path);
    $out = [];
    $sheets = read_bom_file($path, $name);
    $file_customer = customer_from_filename($name);
    $all = [];
    foreach ($sheets as $sheet => $grid) {
        [$blocks, $meta] = parse_bom_grid($grid);
        foreach ($blocks as $b) {
            $all[] = [$sheet, $b, $meta];
        }
    }
    $multi = count($all) > 1;
    $multi_sheets = count(array_unique(array_column($all, 0))) > 1;
    $is_html = (bool)preg_match('/\.html?$/i', $name);
    $seq = [];
    foreach ($all as $i => [$sheet, $b, $meta]) {
        $customer = $meta['customer'] !== '' && !preg_match('/^[\d.\s]*(tb|pb|gb)?$/i', $meta['customer'])
            ? clean_customer_name($meta['customer']) : $file_customer;
        $label = $b['label'];
        if (($b['label_is_customer'] ?? false) && ($is_html || $file_customer === '')) {
            $customer = clean_customer_name($label);     // Zoho sheets: each block names its customer
            $label = '';
        }
        if ($customer === '' && $is_html && !preg_match('/^sheet\s*\d+$/i', $sheet)) {
            $customer = $sheet;
        }
        $base = $is_html ? ($customer !== '' ? $customer : $sheet)
                         : ($file_customer !== '' ? title_from_filename($name) : ($customer !== '' ? $customer : title_from_filename($name)));
        $title = $base;
        if ($multi_sheets && !preg_match('/^sheet\s*\d+$/i', $sheet)) {
            $title .= " - $sheet";
        }
        if ($label !== '' && mb_strlen($label) <= 60 && !preg_match('/^unit price$/i', $label) && strcasecmp($label, $base) !== 0) {
            $title .= ' - ' . $label;
        } elseif ($multi) {
            $seq[$base] = ($seq[$base] ?? 0) + 1;
            $title .= "\0OPT" . $seq[$base];
        }
        $out[] = ['title' => $title, 'customer' => $customer, 'margin' => $b['margin'], 'gst' => $b['gst'],
                  'lines' => $b['lines'], 'source_file' => $name, 'quote_no' => $meta['quote_no'], 'base' => $base];
    }
    // "Option n" only where one customer has several blocks
    foreach ($out as &$o) {
        $o['title'] = preg_replace_callback('/\0OPT(\d+)$/', fn($m) => ($seq[$o['base']] ?? 0) > 1 ? ' - Option ' . $m[1] : '', $o['title']);
        unset($o['base']);
    }
    unset($o);
    return $out;
}

// ---------------------------------------------------------------------------
// Saving into the database
// ---------------------------------------------------------------------------

function find_or_create_customer(string $company): ?int
{
    $company = trim($company);
    if ($company === '') {
        return null;
    }
    $id = val('SELECT id FROM customers WHERE LOWER(company)=LOWER(?) LIMIT 1', [$company]);
    return $id ? (int)$id : insert('INSERT INTO customers (company) VALUES (?)', [$company]);
}

/** Store parsed BOMs as uploaded (library) BOMs. Returns the new BOM ids. */
function bom_fingerprint(string $customer, array $lines): string
{
    $l = array_map(fn($x) => [mb_strtolower(trim($x['category'])), mb_strtolower(trim($x['model'])), $x['quantity'], $x['unit_price']], $lines);
    return md5(mb_strtolower(trim($customer)) . json_encode($l));
}

function save_uploaded_boms(array $records, string $by = 'Excel import', bool $skip_duplicates = true): array
{
    $ids = [];
    foreach ($records as $rec) {
        $fp = bom_fingerprint($rec['customer'], $rec['lines']);
        if ($skip_duplicates && val("SELECT id FROM boms WHERE source='UPLOAD' AND fingerprint=? LIMIT 1", [$fp])) {
            continue;   // the same BOM is already in the library (e.g. a re-exported copy of a file)
        }
        $cust = find_or_create_customer($rec['customer']);
        $num = next_number('boms', 'bom_number', 'BOM');
        $id = insert("INSERT INTO boms (bom_number, version, customer_id, title, status, pricing_mode, margin_percent, gst_percent,
                                        created_by, created_at, source, source_file, fingerprint)
                      VALUES (?, 1, ?, ?, 'APPROVED', 'AUTOMATIC', ?, ?, ?, ?, 'UPLOAD', ?, ?)",
                     [$num, $cust, mb_substr($rec['title'], 0, 250), $rec['margin'] ?? 15, $rec['gst'] ?? 18, $by, now_str(),
                      mb_substr($rec['source_file'], 0, 250), $fp]);
        foreach ($rec['lines'] as $l) {
            q('INSERT INTO bom_items (bom_id, category, manufacturer, part_number, description, quantity, unit_price)
               VALUES (?, ?, ?, ?, ?, ?, ?)', [$id, $l['category'], '', $l['model'], '', $l['quantity'], $l['unit_price']]);
        }
        $ids[] = $id;
    }
    return $ids;
}

/** Import every file of a folder (recursively). Returns [boms created, files read, files skipped => reason]. */
function import_bom_folder(string $dir): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $n = $f->getFilename();
        if (preg_match('/\.(xlsx|xlsm|html?)$/i', $n) && !str_starts_with($n, '~$')) {
            $files[] = $f->getPathname();
        }
    }
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);
    $created = 0;
    $skipped = [];
    foreach ($files as $f) {
        try {
            $recs = parse_bom_file($f);
            if (!$recs) {
                $skipped[basename($f)] = 'no BOM table found';
                continue;
            }
            $created += count(save_uploaded_boms($recs));
        } catch (Throwable $e) {
            $skipped[basename($f)] = $e->getMessage();
        }
    }
    return [$created, count($files), $skipped];
}
