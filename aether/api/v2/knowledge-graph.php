<?php
/**
 * Aether v2 — Knowledge Graph
 * Builds Aether's internal model of the ERP from the raw schema, enriching
 * it with business semantics (module mapping, synonyms, business labels).
 * Auto-rebuilds when schema-watcher reports changes.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/schema-watcher.php';

class AetherKnowledgeGraph
{
    private PDO $db;

    /** Heuristic mapping of table/column tokens → ERP module */
    private const MODULE_HINTS = [
        'donor'       => 'donations',
        'donation'    => 'donations',
        'receipt'     => 'donations',
        'expense'     => 'finance',
        'ledger'      => 'finance',
        'invoice'     => 'finance',
        'payment'     => 'finance',
        'employee'    => 'hr',
        'payroll'     => 'hr',
        'salary'      => 'hr',
        'volunteer'   => 'volunteers',
        'inventory'   => 'inventory',
        'stock'       => 'inventory',
        'program'     => 'programs',
        'project'     => 'projects',
        'gallery'     => 'cms',
        'blog'        => 'cms',
        'cms'         => 'cms',
        'newsletter'  => 'cms',
        'member'      => 'members',
        'role'        => 'admin',
        'permission'  => 'admin',
        'user'        => 'admin',
        'setting'     => 'admin',
        'aether'      => 'aether',
        'log'         => 'system',
        'session'     => 'system',
        'token'       => 'system',
        'otp'         => 'system',
        'attempt'     => 'system',
    ];

    /** Synonyms that humans use for ERP entities */
    private const SYNONYMS = [
        'donations'        => ['donation', 'gift', 'contribution', 'offering', 'gifts received'],
        'donors'           => ['donor', 'contributor', 'supporter', 'giver', 'patron'],
        'expenses'         => ['expense', 'spending', 'expenditure', 'cost', 'outflow'],
        'employees'        => ['employee', 'staff', 'team', 'workforce', 'personnel', 'people'],
        'payroll'          => ['salary run', 'wages', 'pay', 'compensation', 'payslips'],
        'volunteers'       => ['volunteer', 'helpers'],
        'inventory_items'  => ['inventory', 'stock', 'items', 'supplies', 'goods'],
        'projects'         => ['project', 'initiative', 'campaign'],
        'programs'         => ['program', 'programme', 'scheme'],
        'invoices'         => ['invoice', 'bill', 'tax invoice'],
        'ledger_entries'   => ['ledger', 'transactions', 'book entry'],
        'members'          => ['founders', 'trustees', 'board'],
        'gallery'          => ['photos', 'pictures', 'images'],
        'roles'            => ['role', 'designation', 'access level'],
        'users'            => ['user', 'account', 'login'],
        'permissions'      => ['permission', 'rights', 'access'],
    ];

    public function __construct(?PDO $db = null) {
        $this->db = $db ?: aether_db();
    }

    /**
     * Build/refresh the knowledge graph from current schema.
     * Idempotent — uses INSERT ... ON DUPLICATE KEY UPDATE.
     */
    public function rebuild(?array $snapshot = null): array {
        if (!$snapshot) {
            $watcher = new AetherSchemaWatcher($this->db);
            $snapshot = $watcher->snapshot();
        }
        $tables = $snapshot['tables'] ?? [];

        $upsertEntity = $this->db->prepare(
            "INSERT INTO aether_knowledge
             (entity_type, entity_name, module, business_label, description, synonyms_json, metadata_json, relevance, last_synced_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                module = VALUES(module),
                business_label = VALUES(business_label),
                description = VALUES(description),
                synonyms_json = VALUES(synonyms_json),
                metadata_json = VALUES(metadata_json),
                relevance = VALUES(relevance),
                last_synced_at = NOW()"
        );

        $tableEntities = 0;
        $columnEntities = 0;

        foreach ($tables as $name => $info) {
            $module    = $this->guessModule($name);
            $label     = $this->humanise($name);
            $synonyms  = self::SYNONYMS[$name] ?? $this->generateSynonyms($name);
            $relevance = $this->relevanceScore($name, $info);

            $meta = [
                'columns'      => array_keys($info['columns'] ?? []),
                'pk'           => $this->primaryKey($info),
                'foreign_keys' => $info['foreign_keys'] ?? [],
                'rows'         => $info['rows'] ?? 0,
                'is_system'    => str_starts_with($name, 'aether_'),
            ];

            $upsertEntity->execute([
                'table', $name, $module, $label,
                'ERP table: ' . $name,
                json_encode($synonyms, JSON_UNESCAPED_UNICODE),
                json_encode($meta, JSON_UNESCAPED_UNICODE),
                $relevance,
            ]);
            $tableEntities++;

            foreach ($info['columns'] ?? [] as $col => $colMeta) {
                $colSyn = $this->generateSynonyms($col);
                $upsertEntity->execute([
                    'column', "$name.$col", $module,
                    $this->humanise($col),
                    'Column ' . $col . ' of ' . $name,
                    json_encode($colSyn, JSON_UNESCAPED_UNICODE),
                    json_encode($colMeta, JSON_UNESCAPED_UNICODE),
                    $relevance * 0.6,
                ]);
                $columnEntities++;
            }
        }

        // record relationships
        $relInsert = $this->db->prepare(
            "INSERT INTO aether_knowledge
             (entity_type, entity_name, module, business_label, description, synonyms_json, metadata_json, relevance)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE description = VALUES(description), metadata_json = VALUES(metadata_json), last_synced_at = NOW()"
        );
        $relCount = 0;
        foreach ($tables as $name => $info) {
            foreach ($info['foreign_keys'] ?? [] as $fk) {
                $key = "$name.{$fk['column']}=>{$fk['ref_table']}.{$fk['ref_column']}";
                $relInsert->execute([
                    'relationship', $key, $this->guessModule($name),
                    $this->humanise($name) . ' → ' . $this->humanise($fk['ref_table']),
                    "FK: $key", json_encode([], JSON_UNESCAPED_UNICODE),
                    json_encode($fk, JSON_UNESCAPED_UNICODE), 0.4,
                ]);
                $relCount++;
            }
        }

        return [
            'tables'        => $tableEntities,
            'columns'       => $columnEntities,
            'relationships' => $relCount,
        ];
    }

    public function modules(): array {
        $stmt = $this->db->query(
            "SELECT module, COUNT(*) c FROM aether_knowledge
             WHERE entity_type='table' AND module IS NOT NULL
             GROUP BY module ORDER BY c DESC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function summary(): array {
        $db = $this->db;
        $entities = (int)$db->query("SELECT COUNT(*) FROM aether_knowledge WHERE entity_type='table'")->fetchColumn();
        $cols     = (int)$db->query("SELECT COUNT(*) FROM aether_knowledge WHERE entity_type='column'")->fetchColumn();
        $rels     = (int)$db->query("SELECT COUNT(*) FROM aether_knowledge WHERE entity_type='relationship'")->fetchColumn();
        return [
            'tables'        => $entities,
            'columns'       => $cols,
            'relationships' => $rels,
            'modules'       => $this->modules(),
        ];
    }

    /**
     * Search the graph by free-text — used by the NLP engine for entity linking.
     */
    public function findEntities(string $term, int $limit = 8): array {
        $term = trim($term);
        if ($term === '') return [];
        $like = '%' . $term . '%';
        $stmt = $this->db->prepare(
            "SELECT entity_type, entity_name, module, business_label, synonyms_json, relevance
             FROM aether_knowledge
             WHERE entity_name LIKE ? OR business_label LIKE ? OR synonyms_json LIKE ?
             ORDER BY relevance DESC, entity_type ASC LIMIT " . max(1, min($limit, 50))
        );
        $stmt->execute([$like, $like, $like]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function tablesForModule(string $module): array {
        $stmt = $this->db->prepare(
            "SELECT entity_name, business_label, metadata_json
             FROM aether_knowledge
             WHERE entity_type='table' AND module = ?
             ORDER BY relevance DESC"
        );
        $stmt->execute([$module]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Internal helpers ---------------------------------------------------- */

    private function guessModule(string $tableName): string {
        $n = strtolower($tableName);
        foreach (self::MODULE_HINTS as $needle => $module) {
            if (str_contains($n, $needle)) return $module;
        }
        return 'misc';
    }

    private function humanise(string $name): string {
        $name = preg_replace('/^(aether_)/', '', $name);
        $name = str_replace(['_', '-'], ' ', $name);
        return ucwords($name);
    }

    private function primaryKey(array $info): ?string {
        foreach ($info['columns'] ?? [] as $col => $meta) {
            if (($meta['key'] ?? '') === 'PRI') return $col;
        }
        return null;
    }

    private function relevanceScore(string $name, array $info): float {
        $r = 1.0;
        if (str_starts_with($name, 'aether_')) $r -= 0.4;
        $rows = (int)($info['rows'] ?? 0);
        if ($rows > 1000) $r += 0.2;
        if ($rows > 10000) $r += 0.2;
        return max(0.1, min(2.0, $r));
    }

    private function generateSynonyms(string $name): array {
        $base = strtolower(preg_replace('/^(aether_)/', '', $name));
        $words = preg_split('/[_\s-]+/', $base);
        $out = [$base, str_replace('_', ' ', $base)];
        if (count($words) > 1) $out[] = end($words);
        // singular form
        if (str_ends_with($base, 'ies')) $out[] = substr($base, 0, -3) . 'y';
        elseif (str_ends_with($base, 's') && !str_ends_with($base, 'ss')) $out[] = substr($base, 0, -1);
        return array_values(array_unique($out));
    }
}
