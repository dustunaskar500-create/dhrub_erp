<?php
/**
 * Database Connection
 */
require_once __DIR__ . '/../config/database.php';

class Database {
    private static $instance = null;
    private $connection;

    private function __construct() {
        try {
            $this->connection = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            header('Content-Type: application/json');
            die(json_encode(['error' => 'Database connection failed']));
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->connection;
    }
}

function generateDonationCode() {
    return 'DON-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));
}

function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function getRequestBody() {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

/**
 * Sanitize numeric fields in a record to prevent NaN on the frontend.
 * Converts NULL numeric DB values to 0 (int or float based on field name).
 */
function sanitizeNumericFields(array $record, array $floatFields = [], array $intFields = []): array {
    $defaultFloatFields = [
        'basic_salary','hra','da','travel_allowance','medical_allowance',
        'special_allowance','other_allowances','pf_deduction','esi_deduction',
        'tds_deduction','professional_tax','other_deductions','gross_salary',
        'net_salary','total_deductions','salary_paid','overtime','bonus',
        'loan_deduction','amount','budget','total_donations','total_expenses',
        'credit','debit','total'
    ];
    $defaultIntFields = [
        'days_worked','days_absent','age','year'
    ];
    $allFloatFields = array_merge($defaultFloatFields, $floatFields);
    $allIntFields   = array_merge($defaultIntFields,   $intFields);
    foreach ($allFloatFields as $field) {
        if (array_key_exists($field, $record)) {
            $record[$field] = is_null($record[$field]) ? 0.0 : (float)$record[$field];
        }
    }
    foreach ($allIntFields as $field) {
        if (array_key_exists($field, $record)) {
            $record[$field] = is_null($record[$field]) ? 0 : (int)$record[$field];
        }
    }
    return $record;
}

/**
 * Sanitize an array of records.
 */
function sanitizeRecords(array $records): array {
    return array_map('sanitizeNumericFields', $records);
}
