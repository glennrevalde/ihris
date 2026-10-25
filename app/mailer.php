<?php
/**
 * Email delivery with a built-in SMTP client (no external libraries).
 * Every email is recorded in email_log, giving a time-stamped trail
 * (e.g. when a leave request was filed, routed and acted upon).
 *
 * Delivery modes (Settings > Email):
 *   log  – record only; view in Administration > Email Outbox (ideal for local testing)
 *   smtp – send through an SMTP server (Gmail, Office 365, company relay, ...)
 *   mail – use PHP's mail() function
 */

function send_mail(string $to, ?string $toName, string $subject, string $html, ?string $relatedType = null, ?int $relatedId = null): bool
{
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $logId = insert('email_log', [
        'to_email' => $to, 'to_name' => $toName, 'subject' => $subject, 'body_html' => $html,
        'related_type' => $relatedType, 'related_id' => $relatedId, 'status' => 'queued',
    ]);
    $mode = setting('mail_mode', 'log');
    try {
        if ($mode === 'smtp') {
            smtp_send($to, (string)$toName, $subject, $html);
            update('email_log', ['status' => 'sent', 'sent_at' => now()], 'id = ?', [$logId]);
        } elseif ($mode === 'mail') {
            $boundary = 'b' . bin2hex(random_bytes(8));
            $headers = 'From: ' . mail_encode_name((string)setting('mail_from_name', app_name())) . ' <' . setting('mail_from') . ">\r\n"
                . "MIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"$boundary\"";
            $ok = mail($to, mail_encode_name($subject), mail_mime_body($html, $boundary), $headers);
            if (!$ok) {
                throw new RuntimeException('mail() returned false');
            }
            update('email_log', ['status' => 'sent', 'sent_at' => now()], 'id = ?', [$logId]);
        } else {
            update('email_log', ['status' => 'logged', 'sent_at' => now()], 'id = ?', [$logId]);
        }
        return true;
    } catch (Throwable $e) {
        update('email_log', ['status' => 'failed', 'error_message' => mb_substr($e->getMessage(), 0, 490)], 'id = ?', [$logId]);
        return false;
    }
}

function mail_encode_name(string $s): string
{
    return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

function mail_mime_body(string $html, string $boundary): string
{
    $text = trim(html_entity_decode(strip_tags(preg_replace('#<br\s*/?>|</p>|</tr>|</h\d>#i', "\n", $html)), ENT_QUOTES, 'UTF-8'));
    return "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($text))
        . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($html))
        . "--$boundary--\r\n";
}

/** Minimal SMTP client supporting STARTTLS / SSL and AUTH LOGIN. */
function smtp_send(string $to, string $toName, string $subject, string $html): void
{
    $host = (string)setting('smtp_host');
    $port = (int)setting('smtp_port', 587);
    $secure = (string)setting('smtp_secure', 'tls');
    $user = (string)setting('smtp_user');
    $pass = (string)setting('smtp_pass');
    $from = (string)setting('mail_from');
    $fromName = (string)setting('mail_from_name', app_name());
    if ($host === '' || $from === '') {
        throw new RuntimeException('SMTP host / sender not configured.');
    }
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
    $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        throw new RuntimeException("SMTP connect failed: $errstr ($errno)");
    }
    stream_set_timeout($fp, 20);
    $read = function () use ($fp): array {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return [(int)substr($data, 0, 3), $data];
    };
    $cmd = function (string $c, array $expect) use ($fp, $read): string {
        fwrite($fp, $c . "\r\n");
        [$code, $resp] = $read();
        if (!in_array($code, $expect, true)) {
            $shown = stripos($c, 'AUTH') === 0 || strlen($c) > 60 ? '[command]' : $c;
            throw new RuntimeException("SMTP error after $shown: " . trim($resp));
        }
        return $resp;
    };
    [$code, $resp] = $read();
    if ($code !== 220) {
        throw new RuntimeException('SMTP greeting failed: ' . trim($resp));
    }
    $ehloHost = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $cmd('EHLO ' . $ehloHost, [250]);
    if ($secure === 'tls') {
        $cmd('STARTTLS', [220]);
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT : 0))) {
            throw new RuntimeException('Could not start TLS.');
        }
        $cmd('EHLO ' . $ehloHost, [250]);
    }
    if ($user !== '') {
        $cmd('AUTH LOGIN', [334]);
        $cmd(base64_encode($user), [334]);
        $cmd(base64_encode($pass), [235]);
    }
    $cmd('MAIL FROM:<' . $from . '>', [250]);
    $cmd('RCPT TO:<' . $to . '>', [250, 251]);
    $cmd('DATA', [354]);
    $boundary = 'b' . bin2hex(random_bytes(8));
    $headers = 'Date: ' . date('r') . "\r\n"
        . 'From: ' . mail_encode_name($fromName) . ' <' . $from . ">\r\n"
        . 'To: ' . ($toName !== '' ? mail_encode_name($toName) . ' ' : '') . '<' . $to . ">\r\n"
        . 'Subject: ' . mail_encode_name($subject) . "\r\n"
        . 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (explode('@', $from)[1] ?? 'ihris.local') . ">\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
    $body = $headers . "\r\n" . mail_mime_body($html, $boundary);
    $body = preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $body));
    fwrite($fp, $body . "\r\n.\r\n");
    [$code, $resp] = $read();
    if ($code !== 250) {
        throw new RuntimeException('SMTP DATA rejected: ' . trim($resp));
    }
    fwrite($fp, "QUIT\r\n");
    fclose($fp);
}

/** Wrap content in a simple, email-client-safe HTML template. */
function email_template(string $heading, string $bodyHtml): string
{
    $company = h(setting('company_name', app_name()));
    return '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f3f5f9;font-family:Segoe UI,Arial,sans-serif;color:#1f2937">'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f5f9;padding:24px 0"><tr><td align="center">'
        . '<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #e5e7eb">'
        . '<tr><td style="background:#1e3a8a;color:#fff;padding:18px 24px;font-size:18px;font-weight:600">' . $company . ' - ' . h(app_name()) . '</td></tr>'
        . '<tr><td style="padding:24px"><h2 style="margin:0 0 14px;font-size:18px;color:#111827">' . h($heading) . '</h2>' . $bodyHtml . '</td></tr>'
        . '<tr><td style="padding:14px 24px;background:#f9fafb;color:#6b7280;font-size:12px">This is an automated message from ' . h(app_name()) . '. Please do not reply directly to this email.</td></tr>'
        . '</table></td></tr></table></body></html>';
}

function email_button(string $href, string $text, string $color = '#1e3a8a'): string
{
    return '<a href="' . h($href) . '" style="display:inline-block;padding:10px 18px;margin:4px 6px 4px 0;background:' . $color
        . ';color:#fff;text-decoration:none;border-radius:6px;font-weight:600;font-size:14px">' . h($text) . '</a>';
}

function email_table(array $rowsKv): string
{
    $html = '<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:14px;margin:8px 0 16px">';
    foreach ($rowsKv as $k => $v) {
        $html .= '<tr><td style="border-bottom:1px solid #eee;color:#6b7280;width:40%">' . h($k) . '</td><td style="border-bottom:1px solid #eee;font-weight:600">' . $v . '</td></tr>';
    }
    return $html . '</table>';
}
