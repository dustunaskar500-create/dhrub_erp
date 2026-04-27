<?php
/**
 * Aether — helpers.php
 * Contains PDF generation, Email, SMS & WhatsApp functions
 */

require_once __DIR__ . '/config.php';

// ── PHPMailer — supports both flat manual install and Composer install ─────────
$_phpmailer_flat     = __DIR__ . '/../vendor/phpmailer/src/PHPMailer.php';
$_phpmailer_composer = __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';

if (file_exists($_phpmailer_flat)) {
    // Manual / flat install: vendor/phpmailer/src/
    require_once __DIR__ . '/../vendor/phpmailer/src/PHPMailer.php';
    require_once __DIR__ . '/../vendor/phpmailer/src/SMTP.php';
    require_once __DIR__ . '/../vendor/phpmailer/src/Exception.php';
} elseif (file_exists($_phpmailer_composer)) {
    // Composer install: vendor/phpmailer/phpmailer/src/
    require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';
    require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/SMTP.php';
    require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/Exception.php';
} else {
    // Define a stub so the rest of the file doesn't fatally error
    // Email sending will return a config error at runtime
}

// ── mPDF — PSR-4 autoloader (supports both Composer and manual installs) ──────
(function () {
    // 1. Root Composer autoload (standard `composer require mpdf/mpdf`)
    $rootAutoload = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($rootAutoload)) {
        require_once $rootAutoload;
        return;
    }

    // 2. Nested Composer autoload (mpdf shipped with its own vendor/)
    $nestedAutoload = __DIR__ . '/../vendor/mpdf/mpdf/vendor/autoload.php';
    if (file_exists($nestedAutoload)) {
        require_once $nestedAutoload;
        return;
    }

    // 3. Manual / flat install — register PSR-4 for Mpdf\ namespace
    $srcDir = __DIR__ . '/../vendor/mpdf/src/';
    if (is_dir($srcDir)) {
        spl_autoload_register(function (string $class) use ($srcDir) {
            if (strpos($class, 'Mpdf\\') !== 0) return;
            $relative = str_replace('\\', '/', substr($class, 5));
            $file     = $srcDir . $relative . '.php';
            if (file_exists($file)) require_once $file;
        }, true, true);
    }
})();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use Mpdf\Mpdf;

// ── Generate PDF Document ──────────────────────────────────────────────────────
function generateDocument(string $type, array $data): string {
    try {
        $tmpDir = sys_get_temp_dir() . '/mpdf';
        if (!is_dir($tmpDir)) mkdir($tmpDir, 0755, true);

        $mpdf = new Mpdf([
            'mode'    => 'utf-8',
            'format'  => 'A4',
            'tempDir' => $tmpDir,
        ]);

        $templatePath = ($type === 'payslip')
            ? __DIR__ . '/../templates/payslip.html'
            : __DIR__ . '/../templates/receipt.html';

        if (!file_exists($templatePath)) {
            return "❌ Template not found: {$type}";
        }

        $template = file_get_contents($templatePath);

        foreach ($data as $key => $value) {
            $template = str_replace(
                '{{' . strtoupper($key) . '}}',
                htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                $template
            );
        }

        // Replace any unfilled placeholders with an em dash
        $template = preg_replace('/\{\{[A-Z_]+\}\}/', '—', $template);

        $filename  = $type . '_' . time() . '_' . rand(100, 999) . '.pdf';
        $uploadDir = __DIR__ . '/../uploads/documents/';

        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $mpdf->WriteHTML($template);
        $mpdf->Output($uploadDir . $filename, 'F');

        return '/dhrub_erp/aether/uploads/documents/' . $filename;

    } catch (\Throwable $e) {
        return '❌ PDF generation failed: ' . $e->getMessage();
    }
}

// ── Send Email ─────────────────────────────────────────────────────────────────
function sendEmail(string $to, string $subject, string $body): string {
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        return '❌ PHPMailer not found. Check vendor/phpmailer path.';
    }
    if (empty(SMTP_USERNAME)) return '❌ Email configuration missing in .env';
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return '❌ Invalid email address: ' . $to;

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(SMTP_USERNAME, SMTP_FROM_NAME);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body    = $body;
        $mail->AltBody = strip_tags($body);

        $mail->send();
        return '✅ Email sent to ' . $to;
    } catch (PHPMailerException $e) {
        return '❌ Email failed: ' . $mail->ErrorInfo;
    }
}

// ── Send SMS ───────────────────────────────────────────────────────────────────
function sendSMS(string $phone, string $message): string {
    if (empty(FAST2SMS_API_KEY)) return '❌ SMS API key not configured in .env';

    $url    = 'https://www.fast2sms.com/dev/bulkV2';
    $params = http_build_query([
        'authorization' => FAST2SMS_API_KEY,
        'message'       => $message,
        'numbers'       => $phone,
        'route'         => 'q',
        'flash'         => '0',
    ]);

    $ch = curl_init($url . '?' . $params);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['cache-control: no-cache'],
    ]);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err) return '❌ SMS cURL error: ' . $err;

    $result = json_decode($response, true);
    return (isset($result['return']) && $result['return'] === true)
        ? '✅ SMS sent to ' . $phone
        : '❌ SMS failed: ' . ($result['message'][0] ?? $response);
}

// ── Send WhatsApp ──────────────────────────────────────────────────────────────
function sendWhatsApp(string $phone, string $message): string {
    if (empty(EVOLUTION_API_URL)) return '❌ WhatsApp API not configured in .env';

    $url     = rtrim(EVOLUTION_API_URL, '/') . '/message/sendText/' . EVOLUTION_INSTANCE_NAME;
    $payload = json_encode(['number' => $phone, 'text' => $message]);

    $headers = [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload),
    ];
    if (!empty(EVOLUTION_API_KEY)) {
        $headers[] = 'apikey: ' . EVOLUTION_API_KEY;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err)             return '❌ WhatsApp cURL error: ' . $err;
    if ($httpCode >= 400) return '❌ WhatsApp API error (' . $httpCode . '): ' . $response;

    return '✅ WhatsApp message sent to ' . $phone;
}
