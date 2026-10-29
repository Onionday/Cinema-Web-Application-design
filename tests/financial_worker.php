<?php
/* CLI worker used only against the randomly named test database. */
if (PHP_SAPI !== 'cli' || !preg_match('/^cinesphere_test_[a-f0-9]+$/', getenv('CINESPHERE_DB_NAME') ?: '')) {
    http_response_code(403);
    exit(1);
}
if ($argv[1] === 'http') {
    file_put_contents($argv[4], '0');
    $request = json_decode(stream_get_contents(STDIN), true);
    $context = stream_context_create(['http'=>[
        'method'=>'POST', 'header'=>"Content-Type: application/x-www-form-urlencoded\r\nCookie: ".$request['cookie'],
        'content'=>$request['body'], 'ignore_errors'=>true, 'timeout'=>20
    ]]);
    $html = file_get_contents($request['url'], false, $context);
    if ($html === false) { exit(1); }
    echo json_encode(['html'=>$html]);
    exit;
}
require_once dirname(__DIR__) . '/cinesphere/includes/booking_admin.php';
file_put_contents($argv[4], (string) db()->thread_id);
try {
    $result = $argv[1] === 'refund'
        ? staff_refund_seats((int)$argv[2], [(int)$argv[3]], 'Concurrent partial refund', 1)
        : ['refund'=>staff_cancel_booking((int)$argv[2], 'Concurrent staff cancellation', 1)];
    echo json_encode($result);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
