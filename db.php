<?php
// Centralized MySQL connection with graceful error handling
// Update credentials as needed for your XAMPP environment

declare(strict_types=1);

$DB_HOST = getenv('DB_HOST') ?: '127.0.0.1';
$DB_USER = getenv('DB_USER') ?: 'root';
$DB_PASS = getenv('DB_PASS') ?: '';
$DB_NAME = getenv('DB_NAME') ?: 'cloudstorage';
$DB_PORT = (int)(getenv('DB_PORT') ?: 3306);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, $DB_PORT);
    $mysqli->set_charset('utf8mb4');
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Database connection failed.';
    error_log('[DB] Connection failed: ' . $e->getMessage());
    exit;
}

// Helper: fetch single row safely
function db_fetch_one(mysqli_stmt $stmt): ?array {
    $res = $stmt->get_result();
    if ($res === false) { return null; }
    $row = $res->fetch_assoc();
    return $row ?: null;
}

// Helper: sanitize output
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }


