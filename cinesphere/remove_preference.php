<?php
/* =====================================================================
 * remove_preference.php
 *
 * Housekeeping on the short-list: move an entry up, move it down, or
 * delete it. Ownership is checked before anything happens, so one
 * customer can never touch another customer's short-list.
 *
 * SQL used: SELECT, UPDATE, DELETE.
 * ===================================================================== */

require_once __DIR__ . '/includes/auth.php';

require_post();
require_login();

$user_id = current_user_id();
$pref_id = (int) ($_POST['pref_id'] ?? 0);
$action  = (string) ($_POST['action'] ?? '');

if (!in_array($action, ['up', 'down', 'remove'], true)) {
    flash('error', 'Unknown action.');
    redirect('preferences.php');
}

/* ---- The entry must exist AND belong to this customer -------------- */
$pref = db_one(
    'SELECT p.pref_id, p.rank_order, p.user_id, m.title
       FROM preferences p
       JOIN showtimes st ON st.showtime_id = p.showtime_id
       JOIN movies    m  ON m.movie_id     = st.movie_id
      WHERE p.pref_id = ? AND p.user_id = ?',
    [$pref_id, $user_id]
);

if (!$pref) {
    flash('error', 'That short-list entry was not found on your account.');
    redirect('preferences.php');
}

/* ------------------------------------------------------------------ */
/*  Remove                                                             */
/* ------------------------------------------------------------------ */
if ($action === 'remove') {

    /* preference_seats rows go with it via ON DELETE CASCADE. */
    db_execute('DELETE FROM preferences WHERE pref_id = ? AND user_id = ?',
               [$pref_id, $user_id]);

    renumber_preferences($user_id);

    flash('success', 'Removed "' . $pref['title'] . '" from your short-list.');
    redirect('preferences.php');
}

/* ------------------------------------------------------------------ */
/*  Move up / down - swap rank_order with the neighbouring entry       */
/* ------------------------------------------------------------------ */
$all = db_all(
    'SELECT pref_id, rank_order FROM preferences
      WHERE user_id = ? ORDER BY rank_order, pref_id',
    [$user_id]
);

$index = null;
foreach ($all as $i => $row) {
    if ((int) $row['pref_id'] === $pref_id) {
        $index = $i;
        break;
    }
}

if ($index === null) {
    redirect('preferences.php');
}

$swapWith = $action === 'up' ? $index - 1 : $index + 1;

if ($swapWith < 0 || $swapWith >= count($all)) {
    flash('info', 'That entry is already ' . ($action === 'up' ? 'first' : 'last') . '.');
    redirect('preferences.php');
}

$a = $all[$index];
$b = $all[$swapWith];

/* Two UPDATEs inside one transaction so the order can never be left
   half-swapped if something goes wrong between them. */
$conn = db();
$conn->begin_transaction();

try {
    db_execute('UPDATE preferences SET rank_order = ? WHERE pref_id = ? AND user_id = ?',
               [(int) $b['rank_order'], (int) $a['pref_id'], $user_id]);

    db_execute('UPDATE preferences SET rank_order = ? WHERE pref_id = ? AND user_id = ?',
               [(int) $a['rank_order'], (int) $b['pref_id'], $user_id]);

    $conn->commit();
    flash('success', '"' . $pref['title'] . '" moved '
                   . ($action === 'up' ? 'up' : 'down') . ' your short-list.');

} catch (Throwable $ex) {
    $conn->rollback();
    flash('error', 'Could not reorder the short-list. Please try again.');
}

redirect('preferences.php');


/* ------------------------------------------------------------------ */
/**
 * Closes the gaps after a deletion so the ranks read 1, 2, 3 ... again.
 */
function renumber_preferences(int $user_id): void
{
    $rows = db_all(
        'SELECT pref_id FROM preferences WHERE user_id = ? ORDER BY rank_order, pref_id',
        [$user_id]
    );

    $n = 1;
    foreach ($rows as $r) {
        db_execute('UPDATE preferences SET rank_order = ? WHERE pref_id = ?',
                   [$n, (int) $r['pref_id']]);
        $n++;
    }
}
