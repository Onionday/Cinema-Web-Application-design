<?php
require_once __DIR__ . '/functions.php';

/** Bind a displayed choice to its exact seats and prices, using integer cents. */
function checkout_fingerprint(array $choice): string
{
    $seats = [];
    foreach ($choice['seats'] as $s) {
        $seats[(int)$s['seat_id']] = (int)round((float)$s['seat_price'] * 100);
    }
    ksort($seats);
    $parts = [(int)$choice['pref_id'], (int)$choice['showtime_id'],
              (int)$choice['seat_count'], (int)round((float)$choice['est_total'] * 100)];
    foreach ($seats as $id => $cents) { $parts[] = $id . ':' . $cents; }
    return hash('sha256', implode('|', $parts));
}

/** Each rendered checkout gets its own quote so separate tabs do not overwrite it. */
function create_checkout_quote(array $choices): string
{
    $quotes = $_SESSION['checkout_quotes'] ?? [];
    foreach ($quotes as $id => $q) {
        if ($q['expires'] < time()) { unset($quotes[$id]); }
    }
    while (count($quotes) >= 20) { array_shift($quotes); }
    $items = [];
    foreach ($choices as $choice) {
        if ($choice['available']) {
            $items[(int)$choice['pref_id']] = checkout_fingerprint($choice);
        }
    }
    $token = bin2hex(random_bytes(24));
    $quotes[$token] = ['expires'=>time()+900, 'items'=>$items];
    $_SESSION['checkout_quotes'] = $quotes;
    return $token;
}

/** A submitted price is never authoritative: the browser sends only this token. */
function take_checkout_quote($token): ?array
{
    if (!is_string($token) || !isset($_SESSION['checkout_quotes'][$token])) { return null; }
    $quote = $_SESSION['checkout_quotes'][$token];
    unset($_SESSION['checkout_quotes'][$token]);
    return $quote['expires'] >= time() ? $quote['items'] : null;
}

function checkout_quote_matches(array $quote, array $choice): bool
{
    $fingerprint = $quote[(int)$choice['pref_id']] ?? null;
    return is_string($fingerprint) && hash_equals($fingerprint, checkout_fingerprint($choice));
}

/** Read current monetary state after the caller has locked its screening. */
function locked_checkout_choice(int $pref_id, int $user_id): ?array
{
    $choice = db_one('SELECT * FROM preferences WHERE pref_id = ? AND user_id = ? FOR UPDATE',
                     [$pref_id, $user_id]);
    if (!$choice) { return null; }
    $choice['seats'] = db_all(
        'SELECT ps.seat_id, ps.seat_price, s.seat_row, s.seat_number, s.seat_type
           FROM preference_seats ps JOIN seats s ON s.seat_id = ps.seat_id
          WHERE ps.pref_id = ? ORDER BY s.seat_row, s.seat_number FOR UPDATE', [$pref_id]);
    return $choice;
}
