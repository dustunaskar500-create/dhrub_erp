<?php
/**
 * Aether v2 — Notifier
 * Sends alerts to email / SMS when the audit log records an event at or
 * above the configured severity threshold (default: high).
 *
 * SMTP: built-in PHP `mail()` with SMTP envelope OR raw SMTP socket fallback.
 *       (No PHPMailer dependency required — keeps the bundle copy-paste simple.)
 * SMS:  Fast2SMS quick API (HTTPS GET).
 *
 * Hooked from AetherAudit::log() — fire-and-forget, never blocks the request.
 */

require_once __DIR__ . '/bootstrap.php';

class AetherNotifier
{
    private const SEVERITY_LEVEL = [
        'info' => 1, 'low' => 2, 'medium' => 3, 'high' => 4, 'critical' => 5,
    ];

    /** Should this severity trigger a notification? */
    public static function shouldNotify(string $severity): bool {
        $threshold = strtolower(AETHER_NOTIFY_THRESHOLD ?: 'high');
        $a = self::SEVERITY_LEVEL[$severity] ?? 1;
        $b = self::SEVERITY_LEVEL[$threshold] ?? 4;
        return $a >= $b;
    }

    /** Public entry — called from AetherAudit::log() for severe events. */
    public static function dispatch(string $eventType, string $summary, array $payload, string $severity): void {
        if (!self::shouldNotify($severity)) return;

        $subject = "[Aether " . strtoupper($severity) . "] $eventType";
        $body    = self::renderBody($eventType, $summary, $payload, $severity);

        // Email (best-effort)
        if (AETHER_NOTIFY_EMAIL) {
            foreach (self::splitList(AETHER_NOTIFY_EMAIL) as $to) {
                self::sendMail($to, $subject, $body);
            }
        }
        // SMS (only summary, plain text)
        if (AETHER_NOTIFY_SMS && AETHER_FAST2SMS_KEY) {
            $sms = mb_substr(strip_tags($subject . ' — ' . $summary), 0, 320);
            foreach (self::splitList(AETHER_NOTIFY_SMS) as $num) {
                self::sendSms($num, $sms);
            }
        }
    }

    /**
     * Send a donation thank-you to a donor — beautifully-rendered PDF receipt
     * via email + Fast2SMS thank-you with the receipt link.
     * Fire-and-forget; logs every step to audit.
     */
    public static function sendDonationReceipt(int $donationId, ?string $publicReceiptUrl = null): array {
        $result = ['email' => false, 'sms' => false, 'reasons' => []];
        try {
            $db = aether_db();
            $row = $db->prepare(
                "SELECT d.id, d.donation_code, d.amount, d.donation_date,
                        dn.name AS donor_name, dn.email AS donor_email, dn.phone AS donor_phone
                 FROM donations d LEFT JOIN donors dn ON dn.id = d.donor_id
                 WHERE d.id = ?"
            );
            $row->execute([$donationId]);
            $r = $row->fetch();
            if (!$r) { $result['reasons'][] = 'donation not found'; return $result; }

            $name  = $r['donor_name'] ?: 'Friend';
            $code  = $r['donation_code'];
            $amt   = '₹' . number_format((float)$r['amount'], 2);

            // ── Email with PDF attachment (best-effort) ────────────────
            if ($r['donor_email']) {
                $pdf = self::buildReceiptPDF($donationId);
                $subject = 'Thank you for your contribution — Receipt ' . $code;
                $html = self::donorThankYouHtml($name, $amt, $code, $r['donation_date'], $publicReceiptUrl);

                $sent = self::sendMail(
                    $r['donor_email'], $subject, $html,
                    $pdf ? [['filename' => "receipt-$code.pdf", 'content' => $pdf, 'mime' => 'application/pdf']] : []
                );
                $result['email'] = $sent;
                if (!$sent) $result['reasons'][] = 'email send failed (check SMTP_*)';
            } else {
                $result['reasons'][] = 'donor has no email';
            }

            // ── Fast2SMS thank-you ─────────────────────────────────────
            if ($r['donor_phone'] && AETHER_FAST2SMS_KEY) {
                $sms = "Dear $name, thank you for your $amt donation to Dhrub Foundation. "
                     . "Receipt: $code"
                     . ($publicReceiptUrl ? " | $publicReceiptUrl" : "")
                     . " — Aether";
                $result['sms'] = self::sendSms($r['donor_phone'], mb_substr($sms, 0, 320));
                if (!$result['sms']) $result['reasons'][] = 'sms send failed';
            } elseif (!$r['donor_phone']) {
                $result['reasons'][] = 'donor has no phone';
            } else {
                $result['reasons'][] = 'FAST2SMS_API_KEY not configured';
            }
        } catch (\Throwable $e) {
            $result['reasons'][] = 'exception: ' . $e->getMessage();
        }
        return $result;
    }

    /** Helper: render the receipt PDF as a string (for email attachment). */
    private static function buildReceiptPDF(int $donationId): ?string {
        try {
            require_once __DIR__ . '/pdf-receipt.php';
            return AetherPDF::renderReceiptString($donationId);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function donorThankYouHtml(string $name, string $amt, string $code, string $date, ?string $url): string {
        $name = htmlspecialchars($name); $amt = htmlspecialchars($amt); $code = htmlspecialchars($code); $date = htmlspecialchars(date('d M Y', strtotime($date)));
        $cta = $url ? '<p style="margin:20px 0"><a href="' . htmlspecialchars($url) . '" style="background:#10b981;color:#fff;padding:11px 22px;border-radius:9999px;text-decoration:none;font-weight:600;display:inline-block">View receipt online</a></p>' : '';
        return <<<H
<!DOCTYPE html><html><body style="margin:0;font-family:Arial,sans-serif;background:#f8fafc;padding:24px;color:#0f172a">
  <div style="max-width:560px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden">
    <div style="background:linear-gradient(135deg,#10b981,#059669);color:#fff;padding:30px 28px">
      <div style="font-size:11px;letter-spacing:.14em;text-transform:uppercase;opacity:.85;margin-bottom:6px">Dhrub Foundation</div>
      <h1 style="margin:0;font-size:22px;font-weight:600">Thank you, $name</h1>
    </div>
    <div style="padding:28px">
      <p style="font-size:15px;line-height:1.6;margin:0 0 16px">Your generous donation of <strong style="color:#059669">$amt</strong> on <strong>$date</strong> has been gratefully received.</p>
      <p style="font-size:14px;color:#475569;line-height:1.6;margin:0 0 18px">Receipt no. <code style="background:#f1f5f9;padding:3px 8px;border-radius:4px;font-family:monospace">$code</code> &nbsp;is attached as PDF for your records and tax purposes.</p>
      $cta
      <p style="font-size:13px;color:#64748b;line-height:1.6;margin:20px 0 0">Every rupee you've contributed goes directly toward our mission. We'll keep you posted on how your support is making a difference.</p>
      <hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0">
      <p style="font-size:11px;color:#94a3b8;line-height:1.5;margin:0">This receipt was generated and sent automatically by Aether — your ERP's autonomous brain. No reply needed.</p>
    </div>
  </div></body></html>
H;
    }


    /** Send a custom email + optional SMS to any address — used by chat planners. */
    public static function sendCustom(?string $email, ?string $phone, string $subject, string $body): array {
        $out = ['email' => false, 'sms' => false];
        if ($email) {
            $html = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#0f172a;background:#f8fafc;padding:24px;margin:0">'
                  . '<div style="max-width:560px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:28px">'
                  . '<div style="font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:#10b981;font-weight:600;margin-bottom:8px">Dhrub Foundation</div>'
                  . '<h2 style="margin:0 0 14px;color:#0f172a;font-size:18px">' . htmlspecialchars($subject) . '</h2>'
                  . '<div style="font-size:14px;line-height:1.6;color:#334155">' . nl2br(htmlspecialchars($body)) . '</div>'
                  . '<hr style="border:none;border-top:1px solid #e2e8f0;margin:22px 0"><p style="font-size:11px;color:#94a3b8;margin:0">Sent by Aether on behalf of Dhrub Foundation.</p>'
                  . '</div></body></html>';
            $out['email'] = self::sendMail($email, $subject, $html);
        }
        if ($phone && AETHER_FAST2SMS_KEY) {
            $out['sms'] = self::sendSms($phone, mb_substr($subject . ' — ' . $body, 0, 320));
        }
        return $out;
    }

    /* ───────────────────────────────────────────── helpers ───────────── */

    private static function splitList(string $csv): array {
        $out = array_filter(array_map('trim', explode(',', $csv)));
        return array_values(array_unique($out));
    }

    private static function renderBody(string $eventType, string $summary, array $payload, string $severity): string {
        $when = date('Y-m-d H:i:s');
        $colors = [
            'info'     => '#0ea5e9',
            'low'      => '#10b981',
            'medium'   => '#f59e0b',
            'high'     => '#ef4444',
            'critical' => '#7f1d1d',
        ];
        $c = $colors[$severity] ?? '#6b7280';
        $detail = htmlspecialchars(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '');
        return <<<HTML
<!DOCTYPE html><html><body style="margin:0;font-family:Arial,sans-serif;background:#f8fafc;padding:24px;color:#0f172a">
  <div style="max-width:580px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden">
    <div style="background:$c;color:#fff;padding:18px 24px">
      <div style="font-size:11px;letter-spacing:.12em;text-transform:uppercase;opacity:.85">Aether ERP · Alert</div>
      <h1 style="margin:6px 0 0;font-size:18px;font-weight:600">$subject</h1>
    </div>
    <div style="padding:22px 24px">
      <p style="margin:0 0 12px;font-size:15px;line-height:1.55">{$summary}</p>
      <table style="font-size:13px;color:#475569;border-collapse:collapse">
        <tr><td style="padding:4px 12px 4px 0">Event:</td><td><code>$eventType</code></td></tr>
        <tr><td style="padding:4px 12px 4px 0">Severity:</td><td><strong style="color:$c">$severity</strong></td></tr>
        <tr><td style="padding:4px 12px 4px 0">When:</td><td>$when</td></tr>
      </table>
      <pre style="margin:18px 0 0;background:#f1f5f9;border:1px solid #e2e8f0;padding:12px;border-radius:8px;font-size:11px;color:#334155;overflow:auto;max-height:240px">$detail</pre>
      <p style="margin:18px 0 0;font-size:11px;color:#94a3b8">Sent automatically by Aether — your ERP's autonomous brain.</p>
    </div>
  </div></body></html>
HTML;
    }
    private static function sendMail(string $to, string $subject, string $html, array $attachments = []): bool {
        $from     = AETHER_SMTP_FROM_EMAIL ?: 'aether@localhost';
        $fromName = AETHER_SMTP_FROM_NAME  ?: 'Aether ERP';

        // MIME message with optional attachments
        $boundary = 'aev_' . md5(uniqid('', true));
        $altBoundary = 'aev_alt_' . md5(uniqid('', true));

        if (empty($attachments)) {
            $headers  = "MIME-Version: 1.0\r\n"
                      . "Content-Type: text/html; charset=UTF-8\r\n"
                      . "From: $fromName <$from>\r\n"
                      . "Reply-To: $from\r\n"
                      . "X-Mailer: Aether/2.0";
            $body = $html;
        } else {
            $headers  = "MIME-Version: 1.0\r\n"
                      . "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n"
                      . "From: $fromName <$from>\r\n"
                      . "Reply-To: $from\r\n"
                      . "X-Mailer: Aether/2.0";
            $body  = "--$boundary\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
            $body .= $html . "\r\n";
            foreach ($attachments as $a) {
                $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $a['filename'] ?? 'attachment.bin');
                $mime = $a['mime'] ?? 'application/octet-stream';
                $body .= "--$boundary\r\n";
                $body .= "Content-Type: $mime; name=\"$name\"\r\n";
                $body .= "Content-Transfer-Encoding: base64\r\n";
                $body .= "Content-Disposition: attachment; filename=\"$name\"\r\n\r\n";
                $body .= chunk_split(base64_encode($a['content'])) . "\r\n";
            }
            $body .= "--$boundary--\r\n";
        }

        try {
            if (AETHER_SMTP_HOST) {
                return self::smtpSend($to, $subject, $body, $headers, $from);
            }
            return @mail($to, $subject, $body, $headers);
        } catch (\Throwable $e) {
            error_log('Aether notifier mail error: ' . $e->getMessage());
            return false;
        }
    }

    /** Minimal SMTP-over-TLS sender (no external library). */
    private static function smtpSend(string $to, string $subject, string $body, string $extraHeaders, ?string $from = null): bool {
        $host = AETHER_SMTP_HOST;
        $port = (int)AETHER_SMTP_PORT;
        $user = AETHER_SMTP_USERNAME;
        $pass = AETHER_SMTP_PASSWORD;
        $from = $from ?: (AETHER_SMTP_FROM_EMAIL ?: $user);
        $tls  = $port === 465;

        $remote = ($tls ? 'ssl://' : '') . $host . ':' . $port;
        $errNo = 0; $errStr = '';
        $sock = @stream_socket_client($remote, $errNo, $errStr, 10, STREAM_CLIENT_CONNECT);
        if (!$sock) return false;

        $read = static function () use ($sock): string {
            $out = '';
            while (!feof($sock)) {
                $line = fgets($sock, 1024);
                if ($line === false) break;
                $out .= $line;
                if (isset($line[3]) && $line[3] === ' ') break;
            }
            return $out;
        };
        $cmd = static function (string $c) use ($sock, $read): string {
            fwrite($sock, $c . "\r\n");
            return $read();
        };

        $read();                                 // banner
        $cmd('EHLO aether.local');
        if (!$tls && $port === 587) {
            $cmd('STARTTLS');
            if (!@stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($sock); return false;
            }
            $cmd('EHLO aether.local');
        }
        if ($user) {
            $cmd('AUTH LOGIN');
            $cmd(base64_encode($user));
            $cmd(base64_encode($pass));
        }
        $cmd("MAIL FROM:<$from>");
        $cmd("RCPT TO:<$to>");
        $cmd('DATA');
        $msg = "Subject: $subject\r\nTo: $to\r\n$extraHeaders\r\n\r\n$body\r\n.";
        $cmd($msg);
        $cmd('QUIT');
        fclose($sock);
        return true;
    }

    /** Fast2SMS quick send. Returns true on HTTP 200. */
    private static function sendSms(string $number, string $text): bool {
        $key = AETHER_FAST2SMS_KEY;
        if (!$key) return false;
        $url = 'https://www.fast2sms.com/dev/bulkV2?'
             . http_build_query([
                'authorization' => $key,
                'message'       => $text,
                'language'      => 'english',
                'route'         => 'q',
                'numbers'       => $number,
             ]);
        try {
            $ctx = stream_context_create(['http' => ['timeout' => 6, 'method' => 'GET']]);
            $resp = @file_get_contents($url, false, $ctx);
            return $resp !== false;
        } catch (\Throwable $e) { return false; }
    }
}
