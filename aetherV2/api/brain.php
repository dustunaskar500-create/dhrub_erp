<?php
/**
 * Aether v2 — Brain (LLM gateway)
 *
 * Wraps Anthropic Claude (via Emergent's OpenAI-compatible proxy) so the rest
 * of Aether speaks one simple API. Supports:
 *   • Non-streaming  →  Brain::think(messages, opts) : string
 *   • Streaming SSE   →  Brain::stream(messages, opts) : void  (writes to stdout)
 *   • Tool / function calling (Aether knows when to query its own DB)
 *
 * Designed to remain *optional* — every caller MUST check Brain::isReady().
 * If the LLM is misconfigured (no API key, network down) Aether silently
 * falls back to its local rule engine.
 */

require_once __DIR__ . '/bootstrap.php';

class AetherBrain
{
    private string $endpoint;
    private string $apiKey;
    private string $model;
    private string $fallbackModel;
    private int    $maxTokens;
    private float  $threshold;

    public function __construct() {
        $this->endpoint      = defined('AETHER_LLM_ENDPOINT') ? AETHER_LLM_ENDPOINT : 'https://integrations.emergentagent.com/llm/chat/completions';
        $this->apiKey        = defined('AETHER_LLM_KEY')      ? AETHER_LLM_KEY      : '';
        $this->model         = defined('AETHER_LLM_MODEL')    ? AETHER_LLM_MODEL    : 'claude-sonnet-4-6';
        $this->fallbackModel = defined('AETHER_LLM_FALLBACK') ? AETHER_LLM_FALLBACK : 'claude-haiku-4-5-20251001';
        $this->maxTokens     = defined('AETHER_LLM_MAX_TOKENS') ? AETHER_LLM_MAX_TOKENS : 1200;
        $this->threshold     = defined('AETHER_LLM_THRESHOLD')  ? AETHER_LLM_THRESHOLD  : 0.55;
    }

    public function isReady(): bool {
        return $this->apiKey !== '' && str_starts_with($this->apiKey, 'sk-');
    }

    public function threshold(): float { return $this->threshold; }
    public function model(): string    { return $this->model; }

    /**
     * Non-streaming call. Returns assistant text, or throws on failure.
     *
     * @param array $messages [{role:'system'|'user'|'assistant', content:string}, …]
     * @param array $opts     ['temperature'=>0.7, 'max_tokens'=>1200, 'model'=>null]
     */
    public function think(array $messages, array $opts = []): array {
        if (!$this->isReady()) {
            throw new \RuntimeException('Aether LLM not configured');
        }
        $model = $opts['model'] ?? $this->model;
        $body = [
            'model'       => $model,
            'messages'    => $messages,
            'stream'      => false,
            'temperature' => $opts['temperature'] ?? 0.55,
            'max_tokens'  => $opts['max_tokens']  ?? $this->maxTokens,
        ];

        $t0 = microtime(true);
        [$status, $raw, $err] = $this->call($body, false);
        $latency = (int)round((microtime(true) - $t0) * 1000);

        if ($status !== 200) {
            // try fallback model once (Haiku is cheaper + faster)
            if ($model !== $this->fallbackModel) {
                $body['model'] = $this->fallbackModel;
                [$status2, $raw2] = $this->call($body, false);
                if ($status2 === 200) {
                    $j = json_decode($raw2, true);
                    return [
                        'text'      => $j['choices'][0]['message']['content'] ?? '',
                        'model'     => $this->fallbackModel,
                        'latency_ms'=> (int)round((microtime(true) - $t0) * 1000),
                        'tokens'    => $j['usage']['total_tokens'] ?? null,
                        'fallback'  => true,
                    ];
                }
            }
            throw new \RuntimeException("LLM HTTP $status: $err");
        }
        $j = json_decode($raw, true);
        return [
            'text'      => $j['choices'][0]['message']['content'] ?? '',
            'model'     => $model,
            'latency_ms'=> $latency,
            'tokens'    => $j['usage']['total_tokens'] ?? null,
            'fallback'  => false,
        ];
    }

    /**
     * Stream completion as SSE to the browser.
     * The caller MUST have set the right headers before calling this.
     */
    public function stream(array $messages, array $opts = []): void {
        if (!$this->isReady()) {
            $this->sse('token', ['t' => 'I am awake, but the brain is offline. Please configure EMERGENT_LLM_KEY in .env.']);
            $this->sse('done', ['reason' => 'no_key']);
            return;
        }
        $body = [
            'model'       => $opts['model'] ?? $this->model,
            'messages'    => $messages,
            'stream'      => true,
            'temperature' => $opts['temperature'] ?? 0.55,
            'max_tokens'  => $opts['max_tokens']  ?? $this->maxTokens,
        ];
        $this->call($body, true);
        $this->sse('done', []);
    }

    /** Emit one SSE frame. */
    public function sse(string $event, array $payload): void {
        echo "event: $event\n";
        echo 'data: ' . json_encode($payload) . "\n\n";
        @ob_flush(); @flush();
    }

    private function call(array $body, bool $stream): array {
        $ch = curl_init($this->endpoint);
        $opts = [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: ' . ($stream ? 'text/event-stream' : 'application/json'),
            ],
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 15,
        ];
        if ($stream) {
            $opts[CURLOPT_WRITEFUNCTION] = function ($ch, $chunk) {
                $this->processStreamChunk($chunk);
                return strlen($chunk);
            };
        } else {
            $opts[CURLOPT_RETURNTRANSFER] = true;
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return [$status, $resp ?: '', $err];
    }

    private string $sseBuffer = '';
    private function processStreamChunk(string $chunk): void {
        $this->sseBuffer .= $chunk;
        // Process complete events terminated by \n\n
        while (($pos = strpos($this->sseBuffer, "\n\n")) !== false) {
            $event = substr($this->sseBuffer, 0, $pos);
            $this->sseBuffer = substr($this->sseBuffer, $pos + 2);
            foreach (explode("\n", $event) as $line) {
                if (!str_starts_with($line, 'data: ')) continue;
                $data = trim(substr($line, 6));
                if ($data === '[DONE]') { return; }
                $j = json_decode($data, true);
                $delta = $j['choices'][0]['delta']['content'] ?? null;
                if ($delta !== null && $delta !== '') {
                    $this->sse('token', ['t' => $delta]);
                }
            }
        }
    }
}
