<?php
/**
 * Aether v2 — Donation Reminders
 *
 * Classifies donors by inactivity into 30 / 60 / 90+ day buckets, generates
 * personalised re-engagement emails (and Fast2SMS nudges) for each bucket,
 * and escalates the 90+ cohort to super_admin / admin as a report.
 *
 * The actual sending happens through the Aether plan-approve workflow —
 * propose() returns a single batched plan that, on approval, dispatches
 * tone-appropriate messages to each donor in each bucket.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/audit-log.php';
require_once __DIR__ . '/notifier.php';

class AetherReminders
{
    private const TONES = [
        30 => [
            'subject' => 'A small note from Dhrub Foundation',
            'opening' => "It's been a few weeks since your last gift, and we wanted to reach out — not for a number, just to say thank you for being part of our work.",
            'cta'     => "If you'd like to continue supporting us, our latest projects are listed on the website. No pressure, ever.",
            'sev'     => 'gentle',
        ],
        60 => [
            'subject' => 'We miss you at Dhrub Foundation',
            'opening' => "Two months without hearing from you — we noticed, because every recurring donor matters to us. We thought you'd want a quick update on where your earlier contributions have travelled.",
            'cta'     => "If circumstances allow, even a small contribution helps us continue the programmes you supported. If something changed, we'd genuinely love to hear from you.",
            'sev'     => 'warm',
        ],
        90 => [
            'subject' => 'It\'s been a while — a personal note from Dhrub Foundation',
            'opening' => "Three months have quietly passed since you last contributed. Our team paused before sending this — not because we want to ask, but because we want to make sure you're doing okay.",
            'cta'     => "If you'd like to come back, we're here. If you'd prefer a break, just reply with a single word and we'll respect that. Either way — thank you for ever being part of this story.",
            'sev'     => 'sincere',
        ],
    ];

    public static function scan(): array {
        $db = aether_db();
        $today = date('Y-m-d');

        $bucket = function (int $minDays, int $maxDays) use ($db, $today) {
            $stmt = $db->prepare(
                "SELECT dn.id, dn.name, dn.email, dn.phone,
                        MAX(d.donation_date) AS last_gift,
                        DATEDIFF(?, MAX(d.donation_date)) AS days_inactive,
                        SUM(d.amount) AS lifetime,
                        COUNT(d.id) AS gifts
                 FROM donors dn
                 LEFT JOIN donations d ON d.donor_id = dn.id
                 GROUP BY dn.id
                 HAVING last_gift IS NOT NULL
                    AND days_inactive >= ? AND days_inactive < ?
                 ORDER BY lifetime DESC"
            );
            $stmt->execute([$today, $minDays, $maxDays]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        };

        return [
            '30'  => $bucket(30,  60),
            '60'  => $bucket(60,  90),
            '90'  => $bucket(90, 9999),
        ];
    }

    /** Build a one-shot plan covering all three buckets. */
    public static function propose(array $user): array {
        if (!aether_is_admin($user) && !in_array($user['role'] ?? '', ['manager','accountant'])) {
            return ['text' => "Your role can't dispatch reminders."];
        }
        $buckets = self::scan();
        $b30 = $buckets['30']; $b60 = $buckets['60']; $b90 = $buckets['90'];
        $countables = count($b30) + count($b60) + count($b90);
        if ($countables === 0) {
            return ['text' => "No inactive donors at the moment — everyone's active in the last 30 days. ✓"];
        }

        $plan = [
            'kind' => 'reminders_dispatch',
            'buckets' => [
                '30' => array_map(fn($d) => (int)$d['id'], $b30),
                '60' => array_map(fn($d) => (int)$d['id'], $b60),
                '90' => array_map(fn($d) => (int)$d['id'], $b90),
            ],
        ];
        $previewLines = [
            "Plan: send tone-appropriate re-engagement messages to inactive donors.",
            "",
            "**Buckets**",
            "• 30–59 days inactive — *gentle* tone — **" . count($b30) . "** donors",
            "• 60–89 days inactive — *warm* tone — **" . count($b60) . "** donors",
            "• 90+ days inactive — *sincere* tone — **" . count($b90) . "** donors",
            "",
            "On approve, Aether will email + Fast2SMS each donor with the appropriate tone.",
            "The 90-day cohort will also be summarised in an escalation report to super-admin / admin.",
        ];
        $stmt = aether_db()->prepare(
            "INSERT INTO aether_action_plans (user_id, intent, plan_json, preview, status)
             VALUES (?, 'donation_reminders', ?, ?, 'proposed')"
        );
        $stmt->execute([$user['id'], json_encode($plan), implode("\n", $previewLines)]);
        $planId = (int)aether_db()->lastInsertId();

        AetherAudit::log('reminders_proposed', "Reminder plan proposed (" . $countables . " donors)",
            ['b30'=>count($b30),'b60'=>count($b60),'b90'=>count($b90)],
            'low', $user['id']);

        // Build a quick "preview" summary of inactive donors for the UI
        $cards = [
            ['label'=>'30-59 days', 'value'=>count($b30), 'icon'=>'circle-info'],
            ['label'=>'60-89 days', 'value'=>count($b60), 'icon'=>'triangle-exclamation'],
            ['label'=>'90+ days',   'value'=>count($b90), 'icon'=>'circle-exclamation'],
            ['label'=>'Total',      'value'=>$countables, 'icon'=>'users'],
        ];

        return [
            'text' => implode("\n", $previewLines),
            'cards' => $cards,
            'plan' => ['id'=>$planId,'intent'=>'donation_reminders','preview'=>implode("\n",$previewLines),'status'=>'proposed'],
            'mode' => 'plan',
        ];
    }

    /** Called from reasoner.executePlan on approve. */
    public static function execute(array $user, array $plan): array {
        $db = aether_db();
        $sentEmail = 0; $sentSms = 0; $errors = [];

        foreach ([30, 60, 90] as $bucket) {
            $ids = $plan['buckets'][(string)$bucket] ?? [];
            if (!$ids) continue;
            $tone = self::TONES[$bucket];
            foreach ($ids as $donorId) {
                try {
                    $stmt = $db->prepare("SELECT id, name, email, phone FROM donors WHERE id = ?");
                    $stmt->execute([$donorId]);
                    $d = $stmt->fetch();
                    if (!$d) continue;
                    if (!empty($d['email'])) {
                        $html = self::emailHtml($d['name'], $tone, $bucket);
                        if (self::callPrivate('sendMail', [$d['email'], $tone['subject'], $html, []])) $sentEmail++;
                    }
                    if (!empty($d['phone']) && AETHER_FAST2SMS_KEY) {
                        $sms = "Dear {$d['name']}, " . strip_tags($tone['opening']) . ' — Dhrub Foundation';
                        if (self::callPrivate('sendSms', [$d['phone'], mb_substr($sms, 0, 320)])) $sentSms++;
                    }
                } catch (\Throwable $e) {
                    $errors[] = ['donor_id' => $donorId, 'error' => $e->getMessage()];
                }
            }
        }

        // Escalate 90+ to super_admin / admin
        $b90ids = $plan['buckets']['90'] ?? [];
        if ($b90ids && AETHER_NOTIFY_EMAIL) {
            $b90count = count($b90ids);
            $rows = $db->prepare("SELECT name, email, phone FROM donors WHERE id IN (" . implode(',', array_fill(0, count($b90ids), '?')) . ")");
            $rows->execute($b90ids);
            $list = $rows->fetchAll(PDO::FETCH_ASSOC);

            $body = "<h2>Long-inactive donor alert</h2><p><strong>$b90count</strong> donors have been silent for 90+ days. Aether has just sent them a sincere re-engagement message; you may want to follow up personally with the highest-value ones.</p><table border='1' cellpadding='6' cellspacing='0' style='border-collapse:collapse;font-family:Arial;font-size:12px'><tr style='background:#f1f5f9'><th>Donor</th><th>Email</th><th>Phone</th></tr>";
            foreach ($list as $r) {
                $body .= '<tr><td>' . htmlspecialchars($r['name']) . '</td><td>' . htmlspecialchars($r['email']??'') . '</td><td>' . htmlspecialchars($r['phone']??'') . '</td></tr>';
            }
            $body .= '</table>';
            foreach (array_filter(array_map('trim', explode(',', AETHER_NOTIFY_EMAIL))) as $admin) {
                self::callPrivate('sendMail', [$admin, "[Aether] $b90count donors inactive 90+ days", $body, []]);
            }
        }

        AetherAudit::log('reminders_dispatched',
            "Reminders sent: email=$sentEmail, sms=$sentSms (errors=" . count($errors) . ")",
            ['email'=>$sentEmail,'sms'=>$sentSms,'errors'=>count($errors)], 'medium', $user['id']);

        return ['ok'=>true, 'sent_email'=>$sentEmail, 'sent_sms'=>$sentSms, 'errors'=>$errors];
    }

    private static function emailHtml(string $name, array $tone, int $bucket): string {
        $name = htmlspecialchars($name);
        $opening = htmlspecialchars($tone['opening']);
        $cta = htmlspecialchars($tone['cta']);
        $color = $bucket >= 90 ? '#7c3aed' : ($bucket >= 60 ? '#f59e0b' : '#10b981');
        return <<<H
<!DOCTYPE html><html><body style="margin:0;font-family:Arial,sans-serif;background:#f8fafc;padding:24px;color:#0f172a">
  <div style="max-width:560px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden">
    <div style="background:$color;color:#fff;padding:24px 28px">
      <div style="font-size:11px;letter-spacing:.14em;text-transform:uppercase;opacity:.85">Dhrub Foundation</div>
      <h1 style="margin:6px 0 0;font-size:20px;font-weight:600">Hello $name</h1>
    </div>
    <div style="padding:28px">
      <p style="font-size:15px;line-height:1.7;margin:0 0 18px;color:#334155">$opening</p>
      <p style="font-size:13px;line-height:1.7;color:#64748b;margin:0 0 0">$cta</p>
      <hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0">
      <p style="font-size:11px;color:#94a3b8;margin:0">Sent by Aether on behalf of the Dhrub Foundation team. Reply to this email any time — a real human will read it.</p>
    </div>
  </div></body></html>
H;
    }

    private static function callPrivate(string $name, array $args): bool {
        $r = new \ReflectionClass('AetherNotifier');
        $m = $r->getMethod($name);
        $m->setAccessible(true);
        return (bool)$m->invokeArgs(null, $args);
    }
}
