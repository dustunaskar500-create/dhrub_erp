<?php
/**
 * Aether v2 — Learning Engine
 * Tracks intent prediction outcomes and adapts intent-token weights.
 * Also computes a self-improvement score over time.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/audit-log.php';

class AetherLearning
{
    private PDO $db;
    public function __construct(?PDO $db = null) { $this->db = $db ?: aether_db(); }

    /**
     * Record explicit feedback on the most recent learning row for a user.
     * @param int $userId
     * @param int $score   +1 / 0 / -1
     */
    public function recordFeedback(int $userId, int $score): array {
        $score = max(-1, min(1, $score));
        $row = $this->db->prepare("SELECT id, phrase, intent, confidence FROM aether_learning WHERE user_id = ? ORDER BY id DESC LIMIT 1");
        $row->execute([$userId]);
        $r = $row->fetch();
        if (!$r) return ['ok' => false, 'error' => 'No recent interaction'];

        $outcome = $score > 0 ? 'success' : ($score < 0 ? 'failed' : 'partial');
        $this->db->prepare("UPDATE aether_learning SET feedback=?, outcome=? WHERE id=?")->execute([$score, $outcome, $r['id']]);

        if ($score !== 0) {
            $this->updateWeights($r['phrase'], $r['intent'], $score);
        }

        AetherAudit::log(
            'learning_feedback',
            "Feedback $score on intent {$r['intent']}",
            ['phrase' => $r['phrase'], 'score' => $score],
            'info', $userId
        );
        return ['ok' => true, 'updated' => $r['id'], 'outcome' => $outcome];
    }

    /**
     * Reinforce/decay token-intent weights based on feedback.
     */
    private function updateWeights(string $phrase, string $intent, int $score): void {
        $tokens = $this->tokens($phrase);
        if (!$tokens) return;
        $up = $this->db->prepare(
            "INSERT INTO aether_intent_weights (token, intent, weight, samples)
             VALUES (?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE
                weight = LEAST(5, GREATEST(-3, weight + VALUES(weight))),
                samples = samples + 1"
        );
        $delta = $score > 0 ? 0.4 : -0.3;
        foreach ($tokens as $t) {
            $up->execute([$t, $intent, $delta]);
        }
    }

    private function tokens(string $msg): array {
        $msg = strtolower(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $msg));
        $stop = ['a','an','the','is','to','of','for','in','on','please','can','i','you','my','our'];
        $out = [];
        foreach (preg_split('/\s+/', trim($msg)) as $w) {
            if (strlen($w) > 2 && !in_array($w, $stop, true)) $out[] = $w;
        }
        return array_slice(array_unique($out), 0, 12);
    }

    public function stats(): array {
        $db = $this->db;
        $tot = (int)$db->query("SELECT COUNT(*) FROM aether_learning")->fetchColumn();
        $byOut = $db->query("SELECT outcome, COUNT(*) c FROM aether_learning GROUP BY outcome")->fetchAll(PDO::FETCH_ASSOC);
        $weights = (int)$db->query("SELECT COUNT(*) FROM aether_intent_weights")->fetchColumn();
        $confAvg = $db->query("SELECT AVG(confidence) FROM aether_learning WHERE created_at > NOW() - INTERVAL 7 DAY")->fetchColumn();
        $confidenceTrend = (float)($confAvg ?: 0);
        $byIntent = $db->query("SELECT intent, COUNT(*) c FROM aether_learning GROUP BY intent ORDER BY c DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

        $outMap = ['success' => 0, 'partial' => 0, 'failed' => 0, 'unknown' => 0];
        foreach ($byOut as $r) $outMap[$r['outcome']] = (int)$r['c'];
        $rated = $outMap['success'] + $outMap['partial'] + $outMap['failed'];
        $rate = $rated > 0 ? round(($outMap['success'] / $rated) * 100, 1) : null;

        return [
            'interactions'        => $tot,
            'rated'               => $rated,
            'success_rate_pct'    => $rate,
            'avg_confidence_7d'   => round($confidenceTrend, 3),
            'learned_weights'     => $weights,
            'top_intents'         => $byIntent,
            'outcome_breakdown'   => $outMap,
        ];
    }

    public function recent(int $limit = 20): array {
        $stmt = $this->db->prepare("SELECT id, user_id, phrase, intent, confidence, outcome, feedback, created_at FROM aether_learning ORDER BY id DESC LIMIT " . max(1, min($limit, 200)));
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
