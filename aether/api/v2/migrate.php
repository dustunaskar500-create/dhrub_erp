<?php
/**
 * Aether v2 — Migration Runner
 * Creates / upgrades all Aether v2 tables. Idempotent, safe to call repeatedly.
 * Auto-invoked by the v2 API router on first request.
 */

require_once __DIR__ . '/bootstrap.php';

function aether_run_migrations(?PDO $db = null): array {
    $db = $db ?: aether_db();
    $created = [];

    $tables = [
        // Snapshot of the entire ERP schema fingerprint at a point in time
        'aether_schema_snapshots' => "
            CREATE TABLE IF NOT EXISTS aether_schema_snapshots (
                id            INT AUTO_INCREMENT PRIMARY KEY,
                fingerprint   CHAR(64) NOT NULL,
                table_count   INT      NOT NULL DEFAULT 0,
                column_count  INT      NOT NULL DEFAULT 0,
                fk_count      INT      NOT NULL DEFAULT 0,
                snapshot_json LONGTEXT NOT NULL,
                taken_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (fingerprint), INDEX (taken_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Detected schema changes (diff between snapshots)
        'aether_schema_changes' => "
            CREATE TABLE IF NOT EXISTS aether_schema_changes (
                id            INT AUTO_INCREMENT PRIMARY KEY,
                change_type   VARCHAR(32) NOT NULL,
                object_type   VARCHAR(32) NOT NULL,
                object_name   VARCHAR(200) NOT NULL,
                details_json  TEXT,
                impact_level  ENUM('info','low','medium','high','critical') DEFAULT 'info',
                detected_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                acknowledged  TINYINT(1) DEFAULT 0,
                INDEX (detected_at), INDEX (impact_level), INDEX (acknowledged)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Aether's understanding of each ERP entity (table) - business meaning
        'aether_knowledge' => "
            CREATE TABLE IF NOT EXISTS aether_knowledge (
                id             INT AUTO_INCREMENT PRIMARY KEY,
                entity_type    VARCHAR(32)  NOT NULL,
                entity_name    VARCHAR(200) NOT NULL,
                module         VARCHAR(64),
                business_label VARCHAR(200),
                description    TEXT,
                synonyms_json  TEXT,
                metadata_json  LONGTEXT,
                relevance      FLOAT DEFAULT 1.0,
                last_synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_entity (entity_type, entity_name),
                INDEX (module)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Health-check definitions registered by error-monitor
        'aether_health_checks' => "
            CREATE TABLE IF NOT EXISTS aether_health_checks (
                id             INT AUTO_INCREMENT PRIMARY KEY,
                check_code     VARCHAR(64) NOT NULL UNIQUE,
                category       VARCHAR(32) NOT NULL,
                severity       ENUM('info','low','medium','high','critical') DEFAULT 'medium',
                title          VARCHAR(200) NOT NULL,
                description    TEXT,
                last_status    ENUM('ok','warn','fail','unknown') DEFAULT 'unknown',
                last_run_at    TIMESTAMP NULL DEFAULT NULL,
                last_findings  INT DEFAULT 0,
                last_detail    TEXT,
                auto_heal      TINYINT(1) DEFAULT 0,
                enabled        TINYINT(1) DEFAULT 1,
                INDEX (last_status), INDEX (severity)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Append-only history of every Aether decision/action
        'aether_audit_log' => "
            CREATE TABLE IF NOT EXISTS aether_audit_log (
                id           BIGINT AUTO_INCREMENT PRIMARY KEY,
                event_type   VARCHAR(64) NOT NULL,
                actor        VARCHAR(64) DEFAULT 'aether',
                user_id      INT NULL,
                target       VARCHAR(200),
                summary      VARCHAR(500),
                payload_json LONGTEXT,
                severity     ENUM('info','low','medium','high','critical') DEFAULT 'info',
                created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (event_type), INDEX (created_at), INDEX (severity), INDEX (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Issues detected by error-monitor (records that need attention)
        'aether_issues' => "
            CREATE TABLE IF NOT EXISTS aether_issues (
                id           INT AUTO_INCREMENT PRIMARY KEY,
                check_code   VARCHAR(64) NOT NULL,
                entity_type  VARCHAR(64),
                entity_id    VARCHAR(64),
                severity     ENUM('info','low','medium','high','critical') DEFAULT 'low',
                title        VARCHAR(255) NOT NULL,
                detail       TEXT,
                status       ENUM('open','healed','suppressed','closed') DEFAULT 'open',
                healed_by    VARCHAR(64),
                healed_at    TIMESTAMP NULL DEFAULT NULL,
                created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX (status), INDEX (check_code), INDEX (severity)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Learning store: each interaction's intent + outcome
        'aether_learning' => "
            CREATE TABLE IF NOT EXISTS aether_learning (
                id           INT AUTO_INCREMENT PRIMARY KEY,
                user_id      INT,
                phrase       VARCHAR(500),
                intent       VARCHAR(64),
                confidence   FLOAT DEFAULT 0,
                outcome      ENUM('success','partial','failed','unknown') DEFAULT 'unknown',
                feedback     TINYINT,
                created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (intent), INDEX (outcome), INDEX (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Adaptive intent-weight overrides learned over time
        'aether_intent_weights' => "
            CREATE TABLE IF NOT EXISTS aether_intent_weights (
                id        INT AUTO_INCREMENT PRIMARY KEY,
                token     VARCHAR(120) NOT NULL,
                intent    VARCHAR(64)  NOT NULL,
                weight    FLOAT NOT NULL DEFAULT 1.0,
                samples   INT NOT NULL DEFAULT 0,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_tok_int (token, intent)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Action plans queued for confirmation/execution
        'aether_action_plans' => "
            CREATE TABLE IF NOT EXISTS aether_action_plans (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                user_id     INT,
                intent      VARCHAR(64),
                plan_json   LONGTEXT NOT NULL,
                status      ENUM('proposed','approved','executed','rejected','failed') DEFAULT 'proposed',
                preview     TEXT,
                executed_at TIMESTAMP NULL,
                created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (user_id), INDEX (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($tables as $name => $ddl) {
        try {
            $exists = $db->query("SHOW TABLES LIKE " . $db->quote($name))->fetchColumn();
            $db->exec($ddl);
            if (!$exists) $created[] = $name;
        } catch (\Throwable $e) {
            // skip — keep migrations resilient
        }
    }

    return $created;
}

// CLI: php migrate.php
if (php_sapi_name() === 'cli') {
    $created = aether_run_migrations();
    echo "Aether v2 migrations done. New: " . (empty($created) ? '(none)' : implode(', ', $created)) . "\n";
}
