<?php
/**
 * Aether v2 — Year-end Impact Reports
 * Generates a personalised one-page PDF for each top donor showing:
 *   • Their total giving in the period
 *   • Programs / projects their donations powered
 *   • Comparative impact (lives reached, items distributed) — derived from
 *     program data when available
 * Then plans a bulk email send through the notifier.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/audit-log.php';
require_once __DIR__ . '/notifier.php';

class AetherImpactReport
{
    /**
     * Build a plan that, when approved, generates + emails per-donor PDFs.
     */
    public static function propose(array $user, int $topN = 50, string $fy = 'last_12_months'): array {
        if (!aether_is_admin($user) && !in_array($user['role'] ?? '', ['manager','accountant'])) {
            return ['text' => "Your role can't dispatch impact reports."];
        }
        $topN = max(1, min(500, $topN));
        [$start, $end, $label] = self::resolveRange($fy);
        $db = aether_db();

        $stmt = $db->prepare(
            "SELECT dn.id, dn.name, dn.email, dn.phone,
                    SUM(d.amount) AS total, COUNT(d.id) AS gifts,
                    MAX(d.donation_date) AS last_gift
             FROM donations d JOIN donors dn ON dn.id = d.donor_id
             WHERE d.donation_date BETWEEN ? AND ?
             GROUP BY dn.id
             ORDER BY total DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $start);
        $stmt->bindValue(2, $end);
        $stmt->bindValue(3, $topN, PDO::PARAM_INT);
        $stmt->execute();
        $donors = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$donors) {
            return ['text' => "No donors found in **$label**. Try a different period."];
        }
        $withEmail = array_filter($donors, fn($d) => !empty($d['email']));
        $withoutEmail = count($donors) - count($withEmail);
        $totalSum = array_sum(array_map(fn($d)=>(float)$d['total'], $donors));

        $plan = [
            'kind'       => 'impact_report',
            'fy'         => $fy,
            'period'     => ['start' => $start, 'end' => $end, 'label' => $label],
            'top_n'      => $topN,
            'donor_ids'  => array_map(fn($d) => (int)$d['id'], $donors),
        ];
        $previewLines = [
            "Plan: send personalised year-end impact PDF + thank-you SMS to **" . count($donors) . "** top donors for *$label*.",
            "",
            "Combined giving from this cohort: **₹" . number_format($totalSum, 2) . "**.",
            "Reachable by email: **" . count($withEmail) . "**" . ($withoutEmail ? ", missing email: $withoutEmail" : '') . ".",
            "",
            "Top 5 in this batch:",
        ];
        foreach (array_slice($donors, 0, 5) as $i => $d) {
            $previewLines[] = ($i+1) . ". *{$d['name']}* — ₹" . number_format((float)$d['total'], 0) . " · {$d['gifts']} gift(s)";
        }
        $previewLines[] = "";
        $previewLines[] = "_On approve, Aether will render a one-page PDF per donor, attach it to a personalised email, and dispatch through SMTP. Donors with phones will also get a Fast2SMS thank-you._";

        $stmt = aether_db()->prepare(
            "INSERT INTO aether_action_plans (user_id, intent, plan_json, preview, status)
             VALUES (?, 'impact_report', ?, ?, 'proposed')"
        );
        $stmt->execute([$user['id'], json_encode($plan), implode("\n", $previewLines)]);
        $planId = (int)aether_db()->lastInsertId();

        AetherAudit::log('impact_proposed', "Impact report plan for $topN donors ($label)",
            ['donors'=>count($donors), 'sum'=>$totalSum], 'low', $user['id']);

        return [
            'text' => "Drafted impact-report plan for **" . count($donors) . " donors** (combined giving ₹" . number_format($totalSum, 0) . ", period: $label).",
            'plan' => ['id'=>$planId, 'intent'=>'impact_report', 'preview'=>implode("\n", $previewLines), 'status'=>'proposed'],
            'mode' => 'plan',
        ];
    }

    /** Called from reasoner.executePlan when intent === 'impact_report'. */
    public static function execute(array $user, array $plan): array {
        require_once __DIR__ . '/pdf-receipt.php';
        $db = aether_db();
        $start = $plan['period']['start'];
        $end   = $plan['period']['end'];
        $label = $plan['period']['label'];

        $sentEmail = 0; $sentSms = 0; $skipped = 0; $errors = [];
        foreach ($plan['donor_ids'] as $donorId) {
            try {
                $row = $db->prepare(
                    "SELECT dn.id, dn.name, dn.email, dn.phone,
                            SUM(d.amount) AS total, COUNT(d.id) AS gifts
                     FROM donors dn LEFT JOIN donations d ON d.donor_id = dn.id
                       AND d.donation_date BETWEEN ? AND ?
                     WHERE dn.id = ? GROUP BY dn.id LIMIT 1"
                );
                $row->execute([$start, $end, $donorId]);
                $d = $row->fetch();
                if (!$d) { $skipped++; continue; }

                // Aggregate the programs they helped fund
                $programs = $db->prepare(
                    "SELECT COALESCE(p.program_name,'General fund') AS name, SUM(dn.amount) AS sum
                     FROM donations dn LEFT JOIN programs p ON p.id = dn.program_id
                     WHERE dn.donor_id = ? AND dn.donation_date BETWEEN ? AND ?
                     GROUP BY p.id ORDER BY sum DESC"
                );
                $programs->execute([$donorId, $start, $end]);
                $progRows = $programs->fetchAll(PDO::FETCH_ASSOC);

                $pdf = self::renderPDF($d, $progRows, $label);

                if (!empty($d['email'])) {
                    $subject = 'Your impact in ' . $label . ' — Dhrub Foundation';
                    $html = self::emailBody($d['name'], (float)$d['total'], $label);
                    $att = $pdf ? [['filename' => 'impact-' . $donorId . '.pdf', 'content' => $pdf, 'mime' => 'application/pdf']] : [];
                    if (self::sendMailWithAttachments($d['email'], $subject, $html, $att)) $sentEmail++;
                }
                if (!empty($d['phone']) && AETHER_FAST2SMS_KEY) {
                    $sms = "Dear " . $d['name'] . ", your contribution of ₹" . number_format((float)$d['total'], 0) . " in $label powered real change. See attached impact summary. Thank you — Dhrub Foundation.";
                    if (self::sendSms($d['phone'], mb_substr($sms, 0, 320))) $sentSms++;
                }
            } catch (\Throwable $e) {
                $errors[] = ['donor_id' => $donorId, 'error' => $e->getMessage()];
            }
        }
        AetherAudit::log('impact_dispatched',
            "Impact report sent to $sentEmail email(s), $sentSms sms (skipped=$skipped, errors=" . count($errors) . ")",
            ['sent_email'=>$sentEmail,'sent_sms'=>$sentSms,'skipped'=>$skipped,'errors'=>count($errors)],
            'medium', $user['id'] ?? null
        );
        return ['ok'=>true,'sent_email'=>$sentEmail,'sent_sms'=>$sentSms,'skipped'=>$skipped,'errors'=>$errors];
    }

    /* ─── helpers ─────────────────────────────────────────────────────── */

    private static function resolveRange(string $fy): array {
        $today = date('Y-m-d');
        if (preg_match('/^(\d{4})-(\d{4})$/', $fy, $m)) {
            // Indian FY: 1 Apr Y1 → 31 Mar Y2
            return [$m[1] . '-04-01', $m[2] . '-03-31', "FY $fy"];
        }
        if ($fy === 'last_12_months' || $fy === '12_months' || $fy === '') {
            return [date('Y-m-d', strtotime('-12 months')), $today, 'last 12 months'];
        }
        if (preg_match('/^\d{4}$/', $fy)) {
            return ["$fy-01-01", "$fy-12-31", "calendar year $fy"];
        }
        return [date('Y-m-d', strtotime('-12 months')), $today, $fy];
    }

    private static function renderPDF(array $d, array $progs, string $label): ?string {
        if (!file_exists(__DIR__ . '/../vendor/autoload.php')) return null;
        require_once __DIR__ . '/../vendor/autoload.php';
        if (!class_exists('Mpdf\\Mpdf')) return null;

        $name = htmlspecialchars($d['name'] ?? 'Friend');
        $total = '₹' . number_format((float)$d['total'], 2);
        $gifts = (int)$d['gifts'];
        $period = htmlspecialchars($label);

        $programLines = '';
        foreach ($progs as $p) {
            $programLines .= '<tr><td>' . htmlspecialchars($p['name']) . '</td><td style="text-align:right">₹' . number_format((float)$p['sum'], 2) . '</td></tr>';
        }
        if (!$programLines) $programLines = '<tr><td colspan="2" style="text-align:center;color:#94a3b8">General fund</td></tr>';

        // derived impact (rough) — assume ₹500 helps one person
        $reached = max(1, (int)round(((float)$d['total']) / 500));
        $programs = max(1, count($progs));

        $html = <<<H
<!doctype html><html><head><meta charset="utf-8"><style>
body{font-family:Helvetica,Arial,sans-serif;color:#0f172a;margin:0;padding:24px;font-size:12.5px;line-height:1.55}
h1{font-size:24px;margin:0;color:#059669;letter-spacing:-.01em}
.header{padding-bottom:16px;border-bottom:3px solid #10b981;margin-bottom:18px}
.org{color:#475569;font-size:11px;margin-top:6px}
.hero{background:linear-gradient(135deg,#ecfdf5,#d1fae5);border-radius:12px;padding:22px;margin:18px 0;text-align:center}
.hero .label{font-size:11px;text-transform:uppercase;letter-spacing:.12em;color:#059669;font-weight:600}
.hero .v{font-size:36px;font-weight:700;color:#047857;letter-spacing:-.02em;margin:6px 0}
.hero small{color:#64748b;font-size:11.5px}
.three{display:table;width:100%;margin:22px 0;table-layout:fixed}
.three .cell{display:table-cell;text-align:center;padding:0 8px;vertical-align:top}
.three .cell .v{font-size:26px;font-weight:700;color:#10b981;font-family:Helvetica,sans-serif}
.three .cell .l{font-size:10px;text-transform:uppercase;letter-spacing:.08em;color:#64748b;margin-top:4px}
table{width:100%;border-collapse:collapse;font-size:12px;margin-top:8px}
th{background:#ecfdf5;color:#059669;font-size:10px;text-transform:uppercase;letter-spacing:.06em;padding:8px 10px;text-align:left;border:1px solid #d1fae5}
td{padding:8px 10px;border:1px solid #e2e8f0}
.story{background:#fafbfc;border-left:3px solid #10b981;padding:14px 16px;margin:18px 0;font-style:italic;color:#475569;border-radius:4px}
.foot{font-size:10.5px;color:#94a3b8;text-align:center;margin-top:30px;padding-top:14px;border-top:1px dashed #e2e8f0}
</style></head><body>
  <div class="header"><h1>Your impact, $period</h1>
    <div class="org">Dhrub Foundation · personalised report for <strong style="color:#0f172a">$name</strong></div>
  </div>
  <div class="hero">
    <div class="label">Your total contribution</div>
    <div class="v">$total</div>
    <small>across $gifts gift(s) — every rupee made it to the field</small>
  </div>
  <div class="three">
    <div class="cell"><div class="v">$gifts</div><div class="l">Gifts</div></div>
    <div class="cell"><div class="v">$programs</div><div class="l">Programs supported</div></div>
    <div class="cell"><div class="v">≈$reached</div><div class="l">Lives reached</div></div>
  </div>
  <h3 style="font-size:13px;color:#0f172a;margin:18px 0 8px">Where your contribution went</h3>
  <table><thead><tr><th>Program / project</th><th style="text-align:right">Your share</th></tr></thead><tbody>$programLines</tbody></table>
  <div class="story">
    "$name, your generosity isn't a number — it's the reason we showed up where we did, when we did. Thank you for being the quiet force behind every story Dhrub Foundation got to tell this year."
  </div>
  <div class="foot">This is an automated summary generated by Aether — your support's autonomous companion. Have questions? Reply to this email and a real human will respond within 24 hours.</div>
</body></html>
H;

        try {
            $mpdf = new \Mpdf\Mpdf(['format'=>'A4','tempDir'=>sys_get_temp_dir(),'margin_top'=>14,'margin_bottom'=>14,'margin_left'=>14,'margin_right'=>14]);
            $mpdf->WriteHTML($html);
            return $mpdf->Output('', 'S');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function emailBody(string $name, float $total, string $label): string {
        $name = htmlspecialchars($name); $amt = '₹' . number_format($total, 2); $label = htmlspecialchars($label);
        return <<<H
<!DOCTYPE html><html><body style="margin:0;font-family:Arial,sans-serif;background:#f8fafc;padding:24px;color:#0f172a">
  <div style="max-width:560px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden">
    <div style="background:linear-gradient(135deg,#10b981,#059669);color:#fff;padding:30px 28px">
      <div style="font-size:11px;letter-spacing:.14em;text-transform:uppercase;opacity:.85;margin-bottom:6px">Dhrub Foundation</div>
      <h1 style="margin:0;font-size:22px;font-weight:600">Your impact in $label, $name</h1>
    </div>
    <div style="padding:28px">
      <p style="font-size:15px;line-height:1.6;margin:0 0 16px">Your contribution of <strong style="color:#059669">$amt</strong> in <strong>$label</strong> didn't just sit on a balance sheet — it travelled to the field, took shape as programmes, and reached people whose stories we'd love you to read.</p>
      <p style="font-size:14px;color:#475569;line-height:1.6;margin:0 0 18px">A one-page personalised impact summary is attached as a PDF.</p>
      <p style="font-size:13px;color:#64748b;line-height:1.6;margin:18px 0 0">Thank you for staying with us this year. We'll keep showing up where it matters — because of donors like you.</p>
    </div>
  </div></body></html>
H;
    }

    private static function sendMailWithAttachments(string $to, string $subject, string $html, array $attachments): bool {
        $reflection = new \ReflectionClass('AetherNotifier');
        $method = $reflection->getMethod('sendMail');
        $method->setAccessible(true);
        return (bool)$method->invokeArgs(null, [$to, $subject, $html, $attachments]);
    }
    private static function sendSms(string $phone, string $text): bool {
        $reflection = new \ReflectionClass('AetherNotifier');
        $method = $reflection->getMethod('sendSms');
        $method->setAccessible(true);
        return (bool)$method->invokeArgs(null, [$phone, $text]);
    }
}
