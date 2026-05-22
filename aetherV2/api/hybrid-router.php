<?php
/**
 * Aether v2 — Hybrid Router (rules-first, LLM fallback)
 *
 * Decision flow for every chat message:
 *   1. Run the local rule-based NLP engine + reasoner.
 *   2. If confidence ≥ threshold AND intent isn't "open_question" / "write"
 *      → return rule answer instantly (sub-second).
 *   3. Otherwise → escalate to Claude Sonnet 4.5 with full context:
 *        - Butler persona system prompt
 *        - Live ERP KPIs + role + pending tasks
 *        - Recent chat memory (last 16 turns)
 *        - Local rule attempt + its data (so the LLM doesn't hallucinate)
 *
 * The LLM never replaces the rule engine for *writes* (donations, expenses
 * etc.) — those still flow through the explicit slot-filling reasoner so
 * actions remain auditable and idempotent.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/brain.php';
require_once __DIR__ . '/persona.php';
require_once __DIR__ . '/chat-memory.php';
require_once __DIR__ . '/audit-log.php';

class AetherHybridRouter
{
    private AetherBrain $brain;
    private array $user;

    public function __construct(array $user) {
        $this->brain = new AetherBrain();
        $this->user  = $user;
    }

    /**
     * Decide whether the local rule answer is "good enough" or we should
     * route to the LLM. Returns true to keep the rule answer.
     */
    public function ruleAnswerSufficient(array $ruleResult, string $userMessage): bool {
        $confidence = (float)($ruleResult['confidence'] ?? 0);
        $intent     = (string)($ruleResult['intent'] ?? '');

        // Writes (record_donation, create_expense, etc.) always use rules — they need slots.
        if (in_array($intent, [
            'record_donation','create_donor','create_expense','update_salary',
            'add_inventory_item','adjust_inventory','create_program','create_blog_post',
            'send_message','approve_expense','impact_report','csv_import','reminders',
            'plan_approve','plan_reject','self_heal','schema_sync',
        ], true)) {
            return true;
        }

        // If reasoner is already in a multi-turn slot-filling flow, keep it.
        if (($ruleResult['mode'] ?? '') === 'awaiting_slot') return true;

        // High-confidence read intents (list_donations, module_report, etc.)
        // serve the rule's structured response directly.
        if ($confidence >= $this->brain->threshold() &&
            in_array($intent, ['greet','help','list_donations','list_donors','list_expenses',
                                'list_employees','module_report','my_tasks','health'], true)) {
            return true;
        }

        // Detected "open / creative / explanatory" — let the LLM speak.
        // Heuristics:
        $low = mb_strtolower(trim($userMessage));
        $creativeMarkers = ['write','draft','compose','explain','suggest','recommend','why','how','what should',
                             'tell me','describe','summarise','summarize','outline','letter','blog','email','post'];
        foreach ($creativeMarkers as $marker) {
            if (str_contains($low, $marker)) return false;
        }

        // Short / chatty messages — let the butler reply with grace.
        if (mb_strlen($low) < 8 && $confidence < 0.85) return false;

        // Confidence below threshold → escalate.
        return $confidence >= $this->brain->threshold();
    }

    /**
     * Run the LLM with full context. Returns ['reply'=>..., 'meta'=>...].
     *
     * If the brain is offline, returns a graceful butler-style fallback.
     */
    public function llmReply(string $userMessage, string $convId, array $ruleHint = []): array {
        if (!$this->brain->isReady()) {
            return [
                'reply' => "Forgive me, " . AetherPersona::greetingName($this->user) .
                           ", I am awake but my reasoning faculties are not yet wired in. " .
                           "A super-admin will need to configure `EMERGENT_LLM_KEY` in the estate's `.env` file.",
                'meta'  => ['source' => 'fallback', 'model' => null],
            ];
        }

        $messages = $this->buildMessages($userMessage, $convId, $ruleHint);

        try {
            $resp = $this->brain->think($messages, ['temperature' => 0.6]);
            $reply = trim($resp['text'] ?? '');
            if ($reply === '') {
                $reply = "I find myself momentarily without words, " .
                         AetherPersona::greetingName($this->user) . ". Might you rephrase?";
            }
            AetherChatMemory::append($this->user['id'], $convId, 'user', $userMessage);
            AetherChatMemory::append($this->user['id'], $convId, 'assistant', $reply, [
                'model'      => $resp['model'],
                'latency_ms' => $resp['latency_ms'],
                'tokens'     => $resp['tokens'],
            ]);
            return [
                'reply' => $reply,
                'meta'  => [
                    'source'     => 'llm',
                    'model'      => $resp['model'],
                    'latency_ms' => $resp['latency_ms'],
                    'tokens'     => $resp['tokens'],
                    'fallback'   => $resp['fallback'] ?? false,
                ],
            ];
        } catch (\Throwable $e) {
            AetherAudit::log('llm_error', "LLM call failed: " . $e->getMessage(),
                ['msg' => mb_substr($userMessage, 0, 200)], 'low', $this->user['id'] ?? null);
            return [
                'reply' => "My apologies, " . AetherPersona::greetingName($this->user) .
                           " — I encountered a brief disturbance reaching my reasoning. " .
                           "Allow me to fall back to the estate's records directly.",
                'meta'  => ['source' => 'llm_error', 'error' => $e->getMessage()],
            ];
        }
    }

    /** Build the messages array for the LLM call. */
    private function buildMessages(string $userMessage, string $convId, array $ruleHint): array {
        // 1) System: butler persona + live ERP context
        $ctx = $this->liveContext();
        $sys = AetherPersona::systemPrompt($this->user, $ctx);

        // 2) Long-term notes (preferences across sessions)
        $notes = AetherChatMemory::notes($this->user['id']);
        if ($notes) {
            $sys .= "\n\n# Persistent notes about this user\n- " . implode("\n- ", $notes);
        }

        // 3) Rule engine attempt — give the LLM the live data so it doesn't hallucinate.
        if (!empty($ruleHint['text'])) {
            $sys .= "\n\n# Local rule engine result (live data; you may quote/paraphrase)\n" .
                    mb_substr($ruleHint['text'], 0, 1800);
        }

        $messages = [['role' => 'system', 'content' => $sys]];

        // 4) Recent conversation (last 16 turns excluding the one we're about to add)
        foreach (AetherChatMemory::recent($this->user['id'], $convId, 16) as $m) {
            if (in_array($m['role'], ['user', 'assistant'], true)) {
                $messages[] = ['role' => $m['role'], 'content' => $m['content']];
            }
        }

        // 5) The new user message
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        return $messages;
    }

    /** Snapshot live ERP context for the persona prompt. */
    private function liveContext(): array {
        $db = aether_db();
        $kpis = [];
        try {
            $don = $db->query("SELECT COUNT(*) c, COALESCE(SUM(amount),0) s FROM donations WHERE donation_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)")->fetch();
            $kpis[] = ['label' => 'Donations (90d)',  'value' => '₹' . number_format((float)$don['s'])];
            $kpis[] = ['label' => 'Donation count',   'value' => (int)$don['c']];
            $exp = $db->query("SELECT COUNT(*) c, COALESCE(SUM(amount),0) s FROM expenses WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)")->fetch();
            $kpis[] = ['label' => 'Expenses (90d)',   'value' => '₹' . number_format((float)$exp['s'])];
            $kpis[] = ['label' => 'Donor count',      'value' => (int)$db->query("SELECT COUNT(*) FROM donors")->fetchColumn()];
            $kpis[] = ['label' => 'Active programmes','value' => (int)$db->query("SELECT COUNT(*) FROM programs WHERE status IN ('active','running','ongoing')")->fetchColumn()];
        } catch (\Throwable $e) {}

        $pending = 0;
        try { $pending = (int)$db->query("SELECT COUNT(*) FROM aether_action_plans WHERE status='proposed'")->fetchColumn(); } catch (\Throwable $e) {}

        return ['kpis' => $kpis, 'pending_plans' => $pending];
    }
}
