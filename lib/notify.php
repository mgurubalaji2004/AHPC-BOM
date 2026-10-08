<?php
/*
 * Automatic e-mail notifications through Zoho Mail (SMTP). Settings are in config.php.
 * Mails are sent after the page has been delivered to the browser, so users never wait for SMTP.
 */

function mail_config(): array
{
    return ['host' => SMTP_HOST, 'port' => SMTP_PORT, 'user' => MAIL_USER, 'password' => MAIL_PASSWORD,
            'from_name' => MAIL_FROM_NAME, 'base_url' => rtrim(APP_BASE_URL, '/')];
}

function mail_is_configured(): bool
{
    return MAIL_USER !== '' && MAIL_PASSWORD !== '' && MAIL_PASSWORD !== 'PUT-PASSWORD-HERE';
}

function mail_log(array $row): void
{
    try {
        q('INSERT INTO email_log (created_at, event, ref, recipients, subject, status, error) VALUES (?, ?, ?, ?, ?, ?, ?)',
          [now_str(), $row['event'] ?? '', $row['ref'] ?? '', $row['recipients'] ?? '', $row['subject'] ?? '',
           $row['status'] ?? '', $row['error'] ?? '']);
    } catch (Throwable $e) {   // logging must never break the app
        error_log('[mail] could not write email_log: ' . $e->getMessage());
    }
}

/**
 * $to = list of [name, email]. Duplicates are removed. Returns the list of addresses used.
 */
function send_mail(array $to, string $subject, string $html, string $text, ?string $reply_to = null,
                   string $event = '', string $ref = ''): array
{
    $seen = [];
    $pairs = [];
    foreach ($to as [$name, $email]) {
        $email = trim((string)$email);
        if ($email !== '' && !isset($seen[strtolower($email)])) {
            $seen[strtolower($email)] = true;
            $pairs[] = [(string)$name, $email];
        }
    }
    if (!$pairs) {
        return [];
    }
    $addrs = array_column($pairs, 1);
    if (!mail_is_configured()) {
        mail_log(['event' => $event, 'ref' => $ref, 'recipients' => implode(', ', $addrs), 'subject' => $subject,
                  'status' => 'SKIPPED', 'error' => 'Zoho mail is not configured (edit the mail settings in config.php)']);
        return $addrs;
    }
    $raw = build_mime($pairs, $subject, $html, $text, $reply_to);
    mail_queue_add(['raw' => $raw, 'addrs' => $addrs, 'subject' => $subject, 'event' => $event, 'ref' => $ref]);
    return $addrs;
}

function mail_queue_add(array $job): void
{
    static $registered = false;
    $GLOBALS['MAIL_QUEUE'][] = $job;
    if (!$registered) {
        $registered = true;
        register_shutdown_function('mail_flush_queue');
    }
}

/** Runs after the response: close the connection to the browser first, then talk to SMTP. */
function mail_flush_queue(): void
{
    $jobs = $GLOBALS['MAIL_QUEUE'] ?? [];
    $GLOBALS['MAIL_QUEUE'] = [];
    if (!$jobs) {
        return;
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    }
    ignore_user_abort(true);
    @set_time_limit(120);
    foreach ($jobs as $j) {
        $status = 'SENT';
        $error = '';
        try {
            smtp_send($j['addrs'], $j['raw']);
        } catch (Throwable $e) {
            $status = 'FAILED';
            $error = get_class($e) . ': ' . $e->getMessage();
            error_log('[mail] FAILED: ' . $error);
        }
        mail_log(['event' => $j['event'], 'ref' => $j['ref'], 'recipients' => implode(', ', $j['addrs']),
                  'subject' => $j['subject'], 'status' => $status, 'error' => $error]);
    }
}

function mime_header(string $s): string
{
    return preg_match('/^[\x20-\x7e]*$/', $s) ? $s : '=?UTF-8?B?' . base64_encode($s) . '?=';
}

function format_addr(string $name, string $email): string
{
    if ($name === '') {
        return $email;
    }
    $n = preg_match('/^[\x20-\x7e]*$/', $name) ? '"' . addcslashes($name, '"\\') . '"' : mime_header($name);
    return "$n <$email>";
}

function build_mime(array $pairs, string $subject, string $html, string $text, ?string $reply_to): string
{
    $boundary = '=_ahpc_' . bin2hex(random_bytes(12));
    $domain = substr(strrchr(MAIL_USER, '@') ?: '@localhost', 1);
    $h = [
        'Date: ' . date('r'),
        'From: ' . format_addr(MAIL_FROM_NAME, MAIL_USER),
        'To: ' . implode(', ', array_map(fn($p) => format_addr($p[0], $p[1]), $pairs)),
        'Subject: ' . mime_header($subject),
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];
    if ($reply_to) {
        $h[] = 'Reply-To: ' . $reply_to;
    }
    $body = "--$boundary\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
          . chunk_split(base64_encode($text), 76, "\r\n")
          . "--$boundary\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
          . chunk_split(base64_encode($html), 76, "\r\n")
          . "--$boundary--\r\n";
    return implode("\r\n", $h) . "\r\n\r\n" . $body;
}

function smtp_send(array $recipients, string $raw): void
{
    $c = mail_config();
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $c['host']]]);
    $remote = ($c['port'] == 465 ? 'ssl://' : 'tcp://') . $c['host'] . ':' . $c['port'];
    $fp = @stream_socket_client($remote, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        throw new RuntimeException("cannot connect to $remote: $errstr ($errno)");
    }
    stream_set_timeout($fp, 30);
    try {
        smtp_expect($fp, [220]);
        $helo = gethostname() ?: 'localhost';
        smtp_cmd($fp, "EHLO $helo", [250]);
        if ($c['port'] != 465) {
            smtp_cmd($fp, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS failed');
            }
            smtp_cmd($fp, "EHLO $helo", [250]);
        }
        smtp_cmd($fp, 'AUTH LOGIN', [334]);
        smtp_cmd($fp, base64_encode($c['user']), [334]);
        smtp_cmd($fp, base64_encode($c['password']), [235]);
        smtp_cmd($fp, 'MAIL FROM:<' . $c['user'] . '>', [250]);
        foreach ($recipients as $r) {
            smtp_cmd($fp, "RCPT TO:<$r>", [250, 251]);
        }
        smtp_cmd($fp, 'DATA', [354]);
        $data = preg_replace('/(?<!\r)\n/', "\r\n", $raw);
        $data = preg_replace('/^\./m', '..', $data);           // dot-stuffing
        smtp_cmd($fp, rtrim($data, "\r\n") . "\r\n.", [250]);
        @fwrite($fp, "QUIT\r\n");
    } finally {
        fclose($fp);
    }
}

function smtp_cmd($fp, string $line, array $ok): string
{
    fwrite($fp, $line . "\r\n");
    return smtp_expect($fp, $ok);
}

function smtp_expect($fp, array $ok): string
{
    $resp = '';
    while (($l = fgets($fp, 2048)) !== false) {
        $resp .= $l;
        if (strlen($l) < 4 || $l[3] !== '-') {
            break;
        }
    }
    $code = (int)substr($resp, 0, 3);
    if (!in_array($code, $ok, true)) {
        throw new RuntimeException('SMTP error: ' . trim($resp ?: 'no response'));
    }
    return $resp;
}
