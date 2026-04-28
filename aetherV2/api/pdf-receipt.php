<?php
/**
 * Aether v2 — PDF generator (donation receipts + payslips)
 * Uses mPDF (vendor/mpdf). If vendor/ isn't installed, falls back to printable HTML.
 */

require_once __DIR__ . '/bootstrap.php';

class AetherPDF
{
    public static function isAvailable(): bool {
        $auto = dirname(__DIR__) . '/vendor/autoload.php';
        if (!file_exists($auto)) return false;
        require_once $auto;
        return class_exists('Mpdf\\Mpdf');
    }

    /** Render donation receipt — outputs PDF (or printable HTML fallback). */
    public static function streamReceipt(int $donationId): void {
        $row = aether_db()->prepare(
            "SELECT d.*, dn.name AS donor_name, dn.email AS donor_email, dn.address AS donor_address,
                    dn.pan AS donor_pan, p.program_name
             FROM donations d
             LEFT JOIN donors  dn ON dn.id = d.donor_id
             LEFT JOIN programs p ON p.id = d.program_id
             WHERE d.id = ?"
        );
        $row->execute([$donationId]);
        $r = $row->fetch();
        if (!$r) aether_error('Donation not found', 404);

        $org = self::orgInfo();
        $html = self::renderReceiptHTML($r, $org);
        self::output($html, 'receipt-' . $r['donation_code'] . '.pdf');
    }

    /** Render payslip for a payroll row — outputs PDF (or printable HTML fallback). */
    public static function streamPayslip(int $payrollId): void {
        $row = aether_db()->prepare(
            "SELECT p.*, e.name AS emp_name, e.designation, e.department, e.employee_code,
                    e.bank_name, e.bank_account, e.ifsc_code, e.pan_number,
                    e.basic_salary, e.hra, e.da, e.travel_allowance, e.medical_allowance,
                    e.special_allowance, e.other_allowances,
                    e.pf_deduction, e.esi_deduction, e.tds_deduction, e.professional_tax,
                    e.other_deductions, e.net_salary
             FROM payroll p JOIN employees e ON e.id = p.employee_id
             WHERE p.id = ?"
        );
        $row->execute([$payrollId]);
        $r = $row->fetch();
        if (!$r) aether_error('Payroll record not found', 404);

        $org = self::orgInfo();
        $html = self::renderPayslipHTML($r, $org);
        self::output($html, 'payslip-' . ($r['employee_code'] ?? $r['employee_id']) . '-' . ($r['month'] ?? '') . '.pdf');
    }

    /* ───────────────────────────────────────────── helpers ───────────── */

    private static function orgInfo(): array {
        try {
            $rows = aether_db()->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (\Throwable $e) { $rows = []; }
        return [
            'name'    => $rows['organization_name'] ?? 'Dhrub Foundation',
            'address' => $rows['organization_address'] ?? '',
            'email'   => $rows['contact_email'] ?? '',
            'phone'   => $rows['contact_phone'] ?? '',
            'pan'     => $rows['pan_number'] ?? '',
            'reg'     => $rows['registration_number'] ?? '',
        ];
    }

    private static function output(string $html, string $filename): void {
        // mPDF path
        if (self::isAvailable()) {
            try {
                $mpdf = new \Mpdf\Mpdf([
                    'format'      => 'A4',
                    'tempDir'     => sys_get_temp_dir(),
                    'margin_top'  => 12, 'margin_bottom' => 12,
                    'margin_left' => 12, 'margin_right'  => 12,
                ]);
                $mpdf->WriteHTML($html);
                while (ob_get_level()) ob_end_clean();
                header('Content-Type: application/pdf');
                header('Content-Disposition: inline; filename="' . $filename . '"');
                $mpdf->Output($filename, \Mpdf\Output\Destination::INLINE);
                exit;
            } catch (\Throwable $e) {
                // fall through to HTML
            }
        }
        // HTML fallback (browser-printable)
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/html; charset=UTF-8');
        echo $html . '<script>window.print();</script>';
        exit;
    }

    private static function esc($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
    private static function inr($v): string { return '₹' . number_format((float)$v, 2); }

    private static function renderReceiptHTML(array $r, array $org): string {
        $amt   = self::inr($r['amount']);
        $words = self::amountInWords((float)$r['amount']);
        $code  = self::esc($r['donation_code']);
        $date  = self::esc(date('d M Y', strtotime($r['donation_date'])));
        $donor = self::esc($r['donor_name'] ?? 'Anonymous');
        $email = self::esc($r['donor_email'] ?? '');
        $addr  = self::esc($r['donor_address'] ?? '');
        $pan   = self::esc($r['donor_pan'] ?? '');
        $pay   = self::esc(ucfirst($r['payment_method'] ?? 'cash'));
        $tx    = self::esc($r['transaction_id'] ?? '—');
        $prog  = self::esc($r['program_name'] ?? 'General');
        $orgN  = self::esc($org['name']);
        $orgA  = self::esc($org['address']);
        $orgE  = self::esc($org['email']);
        $orgP  = self::esc($org['phone']);
        $orgPan= self::esc($org['pan']);

        return <<<HTML
<!doctype html><html><head><meta charset="utf-8"><title>Receipt {$code}</title>
<style>
  body { font-family: 'Helvetica','Arial',sans-serif; color:#0f172a; margin:0; padding:0; font-size:13px; line-height:1.5 }
  .wrap { padding:24px }
  h1 { font-size:22px; margin:0 0 4px; color:#059669 }
  .org-line { color:#475569; font-size:12px }
  .badge { background:#10b981; color:#fff; padding:6px 14px; border-radius:999px; font-size:11px; letter-spacing:.06em; text-transform:uppercase; display:inline-block }
  .amount-card { background:#ecfdf5; border:2px solid #10b981; border-radius:12px; padding:20px; text-align:center; margin:24px 0 }
  .amount-card .v { font-size:34px; font-weight:700; color:#059669; letter-spacing:-.02em }
  .amount-card .w { color:#475569; font-size:12px; margin-top:6px; text-transform:uppercase; letter-spacing:.08em }
  table.kv { width:100%; border-collapse:collapse; margin:12px 0 }
  table.kv td { padding:8px 0; border-bottom:1px dashed #e2e8f0; vertical-align:top }
  table.kv td.k { width:160px; color:#64748b; font-size:12px; text-transform:uppercase; letter-spacing:.05em }
  table.kv td.v { color:#0f172a; font-weight:500 }
  .foot { margin-top:32px; padding-top:18px; border-top:2px solid #10b981; font-size:11px; color:#64748b; line-height:1.6 }
  .stamp { float:right; border:2px dashed #94a3b8; padding:14px; border-radius:8px; color:#64748b; transform:rotate(-6deg); margin-left:18px; font-size:10px; text-align:center }
</style></head><body><div class="wrap">
  <table style="width:100%; border-collapse:collapse; margin-bottom:18px"><tr>
    <td><h1>{$orgN}</h1><div class="org-line">{$orgA}</div><div class="org-line">{$orgP} · {$orgE}</div>{$orgPan}</td>
    <td style="text-align:right"><div class="badge">Donation Receipt</div><div style="margin-top:8px;color:#64748b;font-size:11px">No: <strong>{$code}</strong></div><div style="color:#64748b;font-size:11px">Date: {$date}</div></td>
  </tr></table>

  <div class="amount-card">
    <div class="v">{$amt}</div>
    <div class="w">{$words} only</div>
  </div>

  <table class="kv">
    <tr><td class="k">Received from</td><td class="v">{$donor}</td></tr>
    <tr><td class="k">Address</td><td class="v">{$addr}</td></tr>
    <tr><td class="k">Email</td><td class="v">{$email}</td></tr>
    <tr><td class="k">PAN</td><td class="v">{$pan}</td></tr>
    <tr><td class="k">Towards</td><td class="v">{$prog}</td></tr>
    <tr><td class="k">Mode of payment</td><td class="v">{$pay}</td></tr>
    <tr><td class="k">Transaction ID</td><td class="v">{$tx}</td></tr>
  </table>

  <div class="foot">
    <div class="stamp">{$orgN}<br>(Authorised<br>Signatory)</div>
    Thank you for your generous contribution. This receipt is generated electronically by Aether — no signature is required for validity.<br>
    {$orgPan}
  </div>
</div></body></html>
HTML;
    }

    private static function renderPayslipHTML(array $r, array $org): string {
        $earn = ['Basic'=>$r['basic_salary'],'HRA'=>$r['hra'],'DA'=>$r['da'],'Travel'=>$r['travel_allowance'],'Medical'=>$r['medical_allowance'],'Special'=>$r['special_allowance'],'Other'=>$r['other_allowances']];
        $ded  = ['PF'=>$r['pf_deduction'],'ESI'=>$r['esi_deduction'],'TDS'=>$r['tds_deduction'],'Prof. Tax'=>$r['professional_tax'],'Other'=>$r['other_deductions']];
        $totEarn = array_sum(array_map('floatval', $earn));
        $totDed  = array_sum(array_map('floatval', $ded));
        $net = self::inr($r['net_salary']);
        $gross = self::inr($totEarn);
        $totalDed = self::inr($totDed);

        $earnRows = '';
        foreach ($earn as $k => $v) $earnRows .= "<tr><td>" . self::esc($k) . "</td><td style=\"text-align:right\">" . self::inr($v) . "</td></tr>";
        $dedRows = '';
        foreach ($ded as $k => $v) $dedRows .= "<tr><td>" . self::esc($k) . "</td><td style=\"text-align:right\">" . self::inr($v) . "</td></tr>";

        $orgN = self::esc($org['name']);
        $orgA = self::esc($org['address']);
        $emp  = self::esc($r['emp_name']);
        $code = self::esc($r['employee_code'] ?? '');
        $des  = self::esc($r['designation'] ?? '');
        $dep  = self::esc($r['department'] ?? '');
        $month = self::esc($r['month'] ?? date('Y-m'));
        $bank = self::esc($r['bank_name'] ?? '');
        $acc  = self::esc($r['bank_account'] ?? '');
        $ifsc = self::esc($r['ifsc_code'] ?? '');
        $pan  = self::esc($r['pan_number'] ?? '');

        $css = '<style>body{font-family:Helvetica,Arial,sans-serif;color:#0f172a;margin:0;padding:24px;font-size:12px}h1{font-size:20px;margin:0;color:#059669}.head{display:table;width:100%;border-bottom:2px solid #10b981;padding-bottom:14px}.head .l,.head .r{display:table-cell;vertical-align:top}.head .r{text-align:right;color:#64748b;font-size:11px}table.emp{width:100%;border-collapse:collapse;margin:14px 0}table.emp td{padding:6px 0;border-bottom:1px dashed #e2e8f0;font-size:12px}table.emp td.k{width:120px;color:#64748b;text-transform:uppercase;font-size:10px;letter-spacing:.06em}.grid{width:100%;border-collapse:collapse;margin-top:16px}.grid th{background:#ecfdf5;color:#059669;font-size:10px;text-transform:uppercase;letter-spacing:.08em;padding:8px 10px;text-align:left;border:1px solid #d1fae5}.grid td{padding:7px 10px;border:1px solid #e2e8f0}.grid tr.tot td{background:#f8fafc;font-weight:600}.net{background:#10b981;color:#fff;padding:14px 20px;border-radius:8px;font-size:18px;font-weight:600;text-align:center;margin-top:18px}.net small{font-size:11px;opacity:.85;font-weight:400;display:block;letter-spacing:.06em;text-transform:uppercase}</style>';

        $html = '<!doctype html><html><head><meta charset="utf-8"><title>Payslip ' . $code . ' ' . $month . '</title>' . $css . '</head><body>';
        $html .= '<div class="head"><div class="l"><h1>' . $orgN . '</h1><div style="color:#475569;font-size:11px">' . $orgA . '</div></div>';
        $html .= '<div class="r"><strong>PAY SLIP</strong><br>For ' . $month . '</div></div>';
        $html .= '<table class="emp">';
        $html .= '<tr><td class="k">Employee</td><td>' . $emp . ' <span style="color:#94a3b8">(' . $code . ')</span></td><td class="k">Designation</td><td>' . $des . '</td></tr>';
        $html .= '<tr><td class="k">Department</td><td>' . $dep . '</td><td class="k">PAN</td><td>' . $pan . '</td></tr>';
        $html .= '<tr><td class="k">Bank</td><td>' . $bank . '</td><td class="k">A/C</td><td>' . $acc . ' (' . $ifsc . ')</td></tr>';
        $html .= '</table>';
        $html .= '<table style="width:100%;border-collapse:collapse" cellspacing="0"><tr>';
        $html .= '<td style="width:50%;vertical-align:top;padding-right:8px"><table class="grid"><thead><tr><th>Earnings</th><th style="text-align:right">Amount</th></tr></thead><tbody>' . $earnRows . '</tbody><tfoot><tr class="tot"><td>Gross earnings</td><td style="text-align:right">' . $gross . '</td></tr></tfoot></table></td>';
        $html .= '<td style="width:50%;vertical-align:top;padding-left:8px"><table class="grid"><thead><tr><th>Deductions</th><th style="text-align:right">Amount</th></tr></thead><tbody>' . $dedRows . '</tbody><tfoot><tr class="tot"><td>Total deductions</td><td style="text-align:right">' . $totalDed . '</td></tr></tfoot></table></td>';
        $html .= '</tr></table>';
        $html .= '<div class="net"><small>Net pay</small>' . $net . '</div>';
        $html .= '<p style="margin-top:24px;font-size:10px;color:#94a3b8;text-align:center">Generated by Aether — your ERP\'s autonomous brain. This payslip is computer-generated and does not require a signature.</p>';
        $html .= '</body></html>';
        return $html;
    }

    /** Convert number to Indian-system words (lakhs/crores). Best-effort. */
    public static function amountInWords(float $n): string {
        $n = (int)round($n);
        if ($n === 0) return 'Rupees zero';
        $crore = (int)floor($n / 10000000); $n %= 10000000;
        $lakh  = (int)floor($n / 100000);   $n %= 100000;
        $thou  = (int)floor($n / 1000);     $n %= 1000;
        $hund  = (int)floor($n / 100);      $n %= 100;
        $ones  = $n;

        $units = ['','one','two','three','four','five','six','seven','eight','nine','ten','eleven','twelve','thirteen','fourteen','fifteen','sixteen','seventeen','eighteen','nineteen'];
        $tens  = ['','','twenty','thirty','forty','fifty','sixty','seventy','eighty','ninety'];
        $convertTwo = static function (int $x) use ($units, $tens): string {
            if ($x < 20) return $units[$x];
            return trim($tens[(int)($x / 10)] . ' ' . $units[$x % 10]);
        };
        $parts = [];
        if ($crore) $parts[] = $convertTwo($crore) . ' crore';
        if ($lakh)  $parts[] = $convertTwo($lakh)  . ' lakh';
        if ($thou)  $parts[] = $convertTwo($thou)  . ' thousand';
        if ($hund)  $parts[] = $units[$hund] . ' hundred';
        if ($ones)  $parts[] = ($parts ? 'and ' : '') . $convertTwo($ones);
        return 'Rupees ' . ucfirst(trim(implode(' ', $parts)));
    }
}
