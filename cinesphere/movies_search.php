<?php
/* Additional version only. Read-only catalogue API; card markup is escaped
   by the same PHP template used for the ordinary no-JavaScript page. */
require_once __DIR__ . '/includes/functions.php';
session_write_close(); // Search requests do not need to hold the session lock.
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'Use GET to search.']);
    exit;
}
ini_set('display_errors', '0');
try {
    require __DIR__ . '/includes/catalogue.php';
    ob_start();
    require __DIR__ . '/includes/catalogue_results.php';
    $html = ob_get_clean();
    echo json_encode(['html' => $html, 'count' => count($films), 'summary' => $summary], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    if (ob_get_level()) { ob_end_clean(); }
    error_log('CineSphere catalogue search: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Search is temporarily unavailable.']);
}
