<?php
/* =====================================================================
 * includes/db.php
 *
 * One mysqli connection per request, plus three small helpers that wrap
 * prepared statements. Every SQL statement in this project goes through
 * db_query() / db_execute(), so no user-supplied value is ever
 * concatenated into an SQL string.
 * ===================================================================== */

require_once __DIR__ . '/config.php';

/**
 * Returns the shared mysqli connection, opening it on first use.
 */
function db(): mysqli
{
    static $conn = null;

    if ($conn instanceof mysqli) {
        return $conn;
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    } catch (Throwable $e) {
        http_response_code(503);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
           . '<title>CineSphere &ndash; database unavailable</title>'
           . '<link rel="stylesheet" href="css/style.css"></head><body>'
           . '<main class="container"><div class="notice notice-error">'
           . '<h1>The booking database is not available</h1>'
           . '<p>Start MySQL in the XAMPP control panel, then import '
           . '<code>sql/01_schema.sql</code> followed by '
           . '<code>sql/02_seed.sql</code>.</p>'
           . (DEBUG ? '<p><small>' . htmlspecialchars($e->getMessage(),
                        ENT_QUOTES, 'UTF-8') . '</small></p>' : '')
           . '</div></main></body></html>';
        exit;
    }

    $conn->set_charset('utf8mb4');
    return $conn;
}

/**
 * Runs a SELECT and returns the mysqli_result.
 *
 * @param string $sql    SQL with ? placeholders
 * @param array  $params Values to bind
 * @param string $types  bind_param type string; auto-detected when empty
 */
function db_query(string $sql, array $params = [], string $types = '')
{
    $stmt = db()->prepare($sql);

    if ($params) {
        $stmt->bind_param($types !== '' ? $types : db_types($params), ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    return $result;
}

/**
 * Runs an INSERT / UPDATE / DELETE.
 *
 * @return array{affected:int, insert_id:int}
 */
function db_execute(string $sql, array $params = [], string $types = ''): array
{
    $stmt = db()->prepare($sql);

    if ($params) {
        $stmt->bind_param($types !== '' ? $types : db_types($params), ...$params);
    }

    $stmt->execute();
    $out = ['affected' => $stmt->affected_rows, 'insert_id' => $stmt->insert_id];
    $stmt->close();

    return $out;
}

/** Convenience: first row of a SELECT, or null. */
function db_one(string $sql, array $params = [], string $types = ''): ?array
{
    $res = db_query($sql, $params, $types);
    $row = $res ? $res->fetch_assoc() : null;
    if ($res) {
        $res->free();
    }
    return $row ?: null;
}

/** Convenience: every row of a SELECT as a plain array. */
function db_all(string $sql, array $params = [], string $types = ''): array
{
    $res  = db_query($sql, $params, $types);
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
        $res->free();
    }
    return $rows;
}

/** Builds the bind_param type string from the PHP types of the values. */
function db_types(array $params): string
{
    $t = '';
    foreach ($params as $p) {
        if (is_int($p)) {
            $t .= 'i';
        } elseif (is_float($p)) {
            $t .= 'd';
        } else {
            $t .= 's';
        }
    }
    return $t;
}
