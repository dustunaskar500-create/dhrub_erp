<?php
/**
 * Aether v2 — NLP Engine
 * Pure-PHP, fully local NLP. Combines:
 *   • Tokenization + lemma-style normalization
 *   • Synonym expansion from knowledge graph
 *   • TF-IDF cosine similarity against a corpus of intent exemplars
 *   • Regex pattern matching for high-confidence routes
 *   • Entity extraction (numbers, dates, money, emails, names, table refs)
 *
 * No external libraries / API calls. Adapts to schema changes via the graph.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/knowledge-graph.php';

class AetherNLP
{
    private PDO $db;
    private AetherKnowledgeGraph $kg;
    /** @var array<string,float[]> token-weights cache for each intent */
    private array $intentVectors = [];

    /** Built-in intent corpus. Each line is an exemplar phrase. */
    private const INTENTS = [
        'greeting'        => ['hi', 'hello', 'hey', 'good morning', 'good evening', 'namaste', 'howdy'],
        'help'            => ['help', 'what can you do', 'show commands', 'capabilities', 'how do you work'],
        'dashboard'       => ['dashboard', 'show overview', 'system summary', 'erp snapshot', 'show stats', 'overall status'],
        'health_status'   => ['health', 'system health', 'is everything ok', 'any errors', 'health check', 'self diagnose'],

        'list_donations'  => ['list donations', 'show donations', 'recent donations', 'donations this month', 'all donations'],
        'record_donation' => ['record a donation', 'add donation', 'new donation', 'log a contribution', 'received donation'],
        'list_donors'     => ['list donors', 'show donors', 'all donors', 'find donor'],
        'create_donor'    => ['add donor', 'new donor', 'register donor', 'onboard donor'],

        'list_expenses'   => ['list expenses', 'show expenses', 'recent spend', 'expenses this month', 'all expenses'],
        'create_expense'  => ['add expense', 'record expense', 'new expense', 'log spend'],

        'list_employees'  => ['list employees', 'show staff', 'all employees', 'show team'],
        'employee_details'=> ['employee details', 'employee info', 'about employee', 'staff profile'],
        'update_salary'   => ['update salary', 'change salary', 'salary hike', 'increase salary', 'salary increment'],

        'list_inventory'  => ['list inventory', 'show stock', 'inventory items', 'low stock items'],
        'low_stock'       => ['low stock', 'items running low', 'reorder list'],

        'generate_payslip'=> ['generate payslip', 'create payslip', 'make pay slip'],
        'generate_receipt'=> ['generate receipt', 'create receipt', 'donation receipt', '80g certificate'],

        'forecast'        => ['forecast donations', 'predict next month', 'projection', 'trend'],
        'top_donors'      => ['top donors', 'biggest contributors', 'highest givers', 'best donors'],

        'schema_info'     => ['describe table', 'schema of', 'fields in', 'columns of', 'structure of'],
        'find_module'     => ['where is', 'how do i', 'navigate to', 'go to module'],
        'audit_recent'    => ['recent audit', 'system log', 'aether activity', 'what did you do'],
    ];

    public function __construct(?PDO $db = null) {
        $this->db = $db ?: aether_db();
        $this->kg = new AetherKnowledgeGraph($this->db);
        $this->buildVectors();
    }

    /** Tokenize + normalize. */
    public function tokenize(string $text): array {
        $t = strtolower($text);
        $t = preg_replace('/[^\p{L}\p{N}\s_-]/u', ' ', $t);
        $t = preg_replace('/\s+/', ' ', trim($t));
        if ($t === '') return [];
        $stop = ['a','an','the','is','am','are','to','of','for','in','on','please','can','could','would','show','me','give','get','my','our','do','you','i','it','that','this','and','or','with','from','by','about'];
        $tokens = array_filter(explode(' ', $t), fn($w) => $w !== '' && !in_array($w, $stop, true) && strlen($w) > 1);
        // simple stem: drop trailing 's'/'ing'/'ed'
        return array_values(array_map(function ($w) {
            if (preg_match('/(ies)$/', $w)) return substr($w, 0, -3) . 'y';
            if (strlen($w) > 4 && str_ends_with($w, 'ing')) return substr($w, 0, -3);
            if (strlen($w) > 4 && str_ends_with($w, 'ed'))  return substr($w, 0, -2);
            if (strlen($w) > 3 && str_ends_with($w, 's') && !str_ends_with($w, 'ss')) return substr($w, 0, -1);
            return $w;
        }, $tokens));
    }

    /**
     * Compute the best-matching intent + score + entities.
     */
    public function analyze(string $message): array {
        $tokens = $this->tokenize($message);
        $entities = $this->extractEntities($message);
        $kgMatches = $this->matchKnowledgeGraph($tokens);

        $scores = [];
        foreach ($this->intentVectors as $intent => $vec) {
            $scores[$intent] = $this->cosine($this->vectorize($tokens), $vec);
        }

        // Apply learned intent weight overrides
        $this->applyLearnedWeights($tokens, $scores);

        arsort($scores);
        $best = key($scores);
        $confidence = (float)($scores[$best] ?? 0);

        // Pattern-based override (exact regex wins)
        $regex = $this->regexIntent($message);
        if ($regex !== null) {
            $best = $regex;
            $confidence = max($confidence, 0.95);
        }

        return [
            'intent'      => $best,
            'confidence'  => round($confidence, 3),
            'tokens'      => $tokens,
            'entities'    => $entities,
            'kg_matches'  => $kgMatches,
            'top_intents' => array_slice($scores, 0, 5, true),
        ];
    }

    /** Vectorise tokens: simple binary + frequency vector. */
    private function vectorize(array $tokens): array {
        $v = [];
        foreach ($tokens as $t) $v[$t] = ($v[$t] ?? 0) + 1.0;
        return $v;
    }

    /** Build per-intent token vectors with IDF-style weighting. */
    private function buildVectors(): void {
        $df = []; // document frequency
        $intentTokens = [];
        foreach (self::INTENTS as $intent => $exemplars) {
            $bag = [];
            foreach ($exemplars as $ex) {
                foreach ($this->tokenize($ex) as $tok) {
                    $bag[$tok] = ($bag[$tok] ?? 0) + 1;
                }
            }
            $intentTokens[$intent] = $bag;
            foreach (array_keys($bag) as $tok) $df[$tok] = ($df[$tok] ?? 0) + 1;
        }
        $N = max(1, count(self::INTENTS));
        foreach ($intentTokens as $intent => $bag) {
            $vec = [];
            foreach ($bag as $tok => $tf) {
                $idf = log(($N + 1) / (1 + ($df[$tok] ?? 0))) + 1;
                $vec[$tok] = $tf * $idf;
            }
            $this->intentVectors[$intent] = $vec;
        }
    }

    private function cosine(array $a, array $b): float {
        if (!$a || !$b) return 0.0;
        $dot = $na = $nb = 0;
        foreach ($a as $k => $v) {
            if (isset($b[$k])) $dot += $v * $b[$k];
            $na += $v * $v;
        }
        foreach ($b as $v) $nb += $v * $v;
        if ($na == 0 || $nb == 0) return 0.0;
        return $dot / (sqrt($na) * sqrt($nb));
    }

    private function applyLearnedWeights(array $tokens, array &$scores): void {
        if (!$tokens) return;
        try {
            $place = implode(',', array_fill(0, count($tokens), '?'));
            $stmt = $this->db->prepare(
                "SELECT token, intent, weight FROM aether_intent_weights WHERE token IN ($place)"
            );
            $stmt->execute($tokens);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (isset($scores[$row['intent']])) {
                    $scores[$row['intent']] += (float)$row['weight'] * 0.05;
                }
            }
        } catch (\Throwable $e) {}
    }

    private function regexIntent(string $msg): ?string {
        $m = strtolower(trim($msg));
        if (preg_match('/^(hi|hello|hey|namaste|good\s+(morning|evening|afternoon|night))\b/', $m)) return 'greeting';
        if (preg_match('/\bhealth\b|\bdiagnose\b|\bself[- ]?heal\b|\bissues?\b/', $m)) return 'health_status';
        if (preg_match('/\bdashboard\b|\boverview\b|\bsnapshot\b|\bsummary\b/', $m)) return 'dashboard';
        if (preg_match('/\b(top|biggest|largest|highest)\b.*\bdonor/', $m)) return 'top_donors';
        if (preg_match('/\b(low\s+stock|out\s+of\s+stock|reorder)\b/', $m)) return 'low_stock';
        if (preg_match('/\b(forecast|predict|projection|trend)\b/', $m)) return 'forecast';
        if (preg_match('/\b(describe|schema of|fields in|columns of|structure of)\b/', $m)) return 'schema_info';
        if (preg_match('/\b(audit|recent activity|what did you do|aether log)\b/', $m)) return 'audit_recent';
        return null;
    }

    /** Extract structured entities from the message. */
    public function extractEntities(string $msg): array {
        $entities = [];
        // money / amounts
        if (preg_match_all('/(?:rs\.?|inr|₹|\$)\s*([0-9][\d,]*(?:\.\d+)?)|\b([0-9]+(?:,\d{3})*(?:\.\d+)?)\s*(rupees|rs|inr|dollars|usd)?/i', $msg, $m)) {
            foreach ($m[0] as $i => $full) {
                $num = $m[1][$i] !== '' ? $m[1][$i] : $m[2][$i];
                $num = (float)str_replace(',', '', $num);
                if ($num > 0) $entities['amount'][] = $num;
            }
        }
        // dates
        if (preg_match_all('/\b(\d{4}-\d{2}-\d{2}|\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4}|today|yesterday|tomorrow|this\s+(month|week|year)|last\s+(month|week|year))\b/i', $msg, $dm)) {
            foreach ($dm[0] as $d) $entities['date'][] = $d;
        }
        // emails
        if (preg_match_all('/[\w._-]+@[\w.-]+\.[a-z]{2,}/i', $msg, $em)) $entities['email'] = $em[0];
        // phones (Indian + generic)
        if (preg_match_all('/\b(?:\+91[- ]?)?[6-9]\d{9}\b|\b\d{3}[- ]?\d{3}[- ]?\d{4}\b/', $msg, $pm)) $entities['phone'] = $pm[0];
        // numbers (fallback)
        if (preg_match_all('/\b\d+\b/', $msg, $nm)) $entities['number'] = array_map('intval', $nm[0]);
        // quoted strings (names)
        if (preg_match_all('/"([^"]+)"/', $msg, $qm)) $entities['quoted'] = $qm[1];
        return $entities;
    }

    /**
     * Match tokens against the knowledge graph to find referenced ERP entities.
     */
    public function matchKnowledgeGraph(array $tokens): array {
        if (!$tokens) return [];
        $found = [];
        foreach ($tokens as $tok) {
            $rows = $this->kg->findEntities($tok, 4);
            foreach ($rows as $r) {
                $key = $r['entity_type'] . ':' . $r['entity_name'];
                if (!isset($found[$key])) $found[$key] = $r;
            }
        }
        return array_values($found);
    }
}
