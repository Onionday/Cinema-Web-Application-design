<?php
/* =====================================================================
 * booking.php - the seat picker for one screening
 *
 * The hall is drawn from the seats table; which of those seats are gone
 * is worked out from booking_seats joined to confirmed bookings. The
 * visitor's choice is posted to process_preference.php, which re-checks
 * everything before writing to the database.
 * ===================================================================== */

require_once __DIR__ . '/includes/auth.php';

$showtime_id = (int) ($_GET['showtime'] ?? 0);
$showtime    = $showtime_id > 0 ? get_showtime($showtime_id) : null;

if (!$showtime) {
    http_response_code(404);
    flash('error', 'That screening does not exist. It may have been withdrawn.');
    redirect('showtimes.php');
}

if ($showtime['status'] === 'cancelled') {
    flash('warning', 'That screening has been cancelled. Please choose another.');
    redirect('movie.php?id=' . (int) $showtime['movie_id']);
}

if (showtime_has_passed($showtime)) {
    flash('warning', 'That screening has already started. Here is what is still ahead.');
    redirect('movie.php?id=' . (int) $showtime['movie_id']);
}

/* ---- Seats -------------------------------------------------------- */
$seats    = get_hall_seats((int) $showtime['hall_id']);
$taken    = get_taken_seat_ids($showtime_id);
$held     = is_logged_in()
          ? get_reserved_seat_ids($showtime_id, current_user_id())
          : [];

/* Seats carried over from a failed submit, or from before a sign-in. */
$preselect = [];
$pending   = $_SESSION['pending_seats'] ?? null;
if ($pending && (int) $pending['showtime_id'] === $showtime_id) {
    $preselect = array_map('intval', $pending['seat_ids']);
    unset($_SESSION['pending_seats']);
}

/* ---- Group into rows ---------------------------------------------- */
$rows = [];
foreach ($seats as $s) {
    $rows[$s['seat_row']][] = $s;
}

$basePrice   = showtime_price($showtime);
$totalSeats  = count($seats);
$seatsLeft   = $totalSeats - count($taken);
$perRow      = (int) $showtime['seats_per_row'];

/* Where the aisles fall, so the map reads like a real auditorium. */
$aisleAfter = $perRow >= 10 ? [2, $perRow - 2] : [];

/* Distinct surcharges present in this hall, for the price key. */
$tiers = [];
foreach ($seats as $s) {
    $tiers[$s['seat_type']] = (float) $s['surcharge'];
}
ksort($tiers);

$page_title       = 'Choose seats - ' . $showtime['title'];
$page_description = 'Pick your seats for ' . $showtime['title'] . ' at '
                  . $showtime['cinema_name'] . '.';
$page_scripts     = ['seats.js'];

require __DIR__ . '/includes/header.php';
?>

<div class="container">
  <p class="breadcrumb">
    <a href="index.php">Home</a><span>/</span>
    <a href="movies.php">Films</a><span>/</span>
    <a href="movie.php?id=<?= (int) $showtime['movie_id'] ?>"><?= e($showtime['title']) ?></a>
    <span>/</span>Seats
  </p>
</div>

<section class="section-tight">
  <div class="container">
    <span class="eyebrow">
      <?= e(pretty_date($showtime['show_date'])) ?> &middot;
      <?= e(pretty_time($showtime['show_time'])) ?> &middot;
      <?= e($showtime['cinema_name']) ?>
    </span>
    <h1>Choose your seats</h1>
    <p class="lede">
      <?= e($showtime['hall_name']) ?> &mdash; <?= e($showtime['hall_type']) ?> hall,
      <?= e($showtime['format']) ?>.
      <strong><?= $seatsLeft ?></strong> of <?= $totalSeats ?> seats are still free.
    </p>
  </div>
</section>

<section class="section-tight" style="padding-bottom:4rem;">
  <div class="container booking-layout">

    <!-- =================== LEFT: THE AUDITORIUM ================== -->
    <div>
      <div class="screen">
        <div class="screen-bar"></div>
        <span>Screen this way</span>
      </div>

      <div id="seatmap" class="seatmap"
           data-max="<?= (int) MAX_SEATS_PER_BOOKING ?>"
           data-fee="<?= e(number_format(BOOKING_FEE, 2, '.', '')) ?>"
           data-currency="<?= e(CURRENCY) ?>">

        <?php foreach ($rows as $letter => $rowSeats): ?>
          <div class="seat-row">
            <span class="row-letter" aria-hidden="true"><?= e($letter) ?></span>

            <?php foreach ($rowSeats as $i => $s):
                $id      = (int) $s['seat_id'];
                $isTaken = isset($taken[$id]);
                $isHeld  = in_array($id, $held, true);
                $price   = seat_price($showtime, $s);
                $name    = $letter . $s['seat_number'];

                $cls = 'seat ' . strtolower($s['seat_type']);
                if ($isTaken) { $cls .= ' is-taken'; }
                if ($isHeld)  { $cls .= ' is-held'; }

                if ($isTaken) {
                    $title = $name . ' - already booked';
                } elseif ($isHeld) {
                    $title = $name . ' - already on your short-list';
                } else {
                    $title = $name . ' - ' . $s['seat_type'] . ' - ' . money($price);
                }
            ?>
              <button type="button"
                      class="<?= e($cls) ?>"
                      <?= ($isTaken || $isHeld) ? 'disabled' : '' ?>
                      data-seat="<?= $id ?>"
                      data-row="<?= e($letter) ?>"
                      data-num="<?= (int) $s['seat_number'] ?>"
                      data-price="<?= e(number_format($price, 2, '.', '')) ?>"
                      <?= in_array($id, $preselect, true) && !$isTaken && !$isHeld
                          ? 'data-preselect="1"' : '' ?>
                      aria-pressed="false"
                      title="<?= e($title) ?>">
                <?= (int) $s['seat_number'] ?>
              </button>

              <?php if (in_array($i + 1, $aisleAfter, true)): ?>
                <span class="seat-gap" aria-hidden="true"></span>
              <?php endif; ?>
            <?php endforeach; ?>

            <span class="row-letter" aria-hidden="true"><?= e($letter) ?></span>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="legend">
        <span><i></i> Standard <?= e(money($basePrice)) ?></span>
        <?php if (isset($tiers['Premium'])): ?>
          <span><i class="premium"></i> Premium <?= e(money($basePrice + $tiers['Premium'])) ?></span>
        <?php endif; ?>
        <?php if (isset($tiers['Couch'])): ?>
          <span><i class="couch"></i> Couch <?= e(money($basePrice + $tiers['Couch'])) ?></span>
        <?php endif; ?>
        <span><i class="selected"></i> Your choice</span>
        <span><i class="taken"></i> Taken</span>
        <?php if ($held): ?>
          <span><i class="held"></i> On your short-list</span>
        <?php endif; ?>
      </div>

      <p class="small muted center" style="margin-top:1.2rem;">
        Use the arrow keys to move between seats and Enter or Space to
        select one. Up to <?= (int) MAX_SEATS_PER_BOOKING ?> seats per screening.
      </p>
    </div>


    <!-- ==================== RIGHT: THE SUMMARY =================== -->
    <aside class="summary-panel">
      <div class="summary-head">
        <img src="images/<?= e($showtime['poster']) ?>"
             alt="Poster for <?= e($showtime['title']) ?>" width="58" height="87">
        <div>
          <h3><?= e($showtime['title']) ?></h3>
          <p>
            <?= e($showtime['classification']) ?> &middot;
            <?= e(pretty_runtime((int) $showtime['duration_min'])) ?> &middot;
            <?= e($showtime['format']) ?>
          </p>
          <p class="small">
            <?= e(pretty_date($showtime['show_date'])) ?><br>
            <?= e(pretty_time($showtime['show_time'])) ?> &middot; <?= e($showtime['hall_name']) ?>
          </p>
        </div>
      </div>

      <div class="summary-body">

        <form id="seat-form" method="post" action="process_preference.php">
          <?= csrf_field() ?>
          <input type="hidden" name="showtime_id" value="<?= (int) $showtime['showtime_id'] ?>">
          <input type="hidden" name="seat_ids" id="seat_ids" value="">

          <h4 style="font-size:.76rem;letter-spacing:.12em;text-transform:uppercase;color:var(--ink-faint);margin-bottom:.3rem;">
            Selected seats
          </h4>
          <div id="chosen-seats" class="chosen-seats">
            <span class="faint small">No seats chosen yet.</span>
          </div>

          <div class="summary-line"><span>Seats</span><span id="sum-count">0 seats</span></div>
          <div class="summary-line"><span>Tickets</span><span id="sum-tickets"><?= e(money(0)) ?></span></div>
          <div class="summary-line">
            <span>Booking fee <span class="faint">(<?= e(money(BOOKING_FEE)) ?> each)</span></span>
            <span id="sum-fee"><?= e(money(0)) ?></span>
          </div>

          <div class="summary-total">
            <span>Estimated total</span>
            <span class="amount" id="sum-total"><?= e(money(0)) ?></span>
          </div>

          <p class="small muted" id="seat-hint" style="margin:.9rem 0 1rem;">
            Pick at least one seat to continue.
          </p>

          <button type="submit" id="add-btn" class="btn btn-primary btn-block is-disabled"
                  data-busy="Adding&hellip;">
            <?= is_logged_in() ? 'Add to short-list' : 'Sign in and hold these seats' ?>
          </button>

          <p class="center" style="margin:.8rem 0 0;">
            <a href="#" id="clear-seats" class="small muted">Clear selection</a>
          </p>
        </form>

        <hr style="margin:1.4rem 0;">

        <p class="small muted" style="margin:0;">
          Adding a screening to your short-list does not charge you. You
          choose which entries to confirm on the
          <a href="preferences.php">short-list page</a>, and only then is
          payment taken and the seats issued.
        </p>

        <?php if (is_logged_in()):
            $n = preference_count(current_user_id());
            if ($n > 0): ?>
          <p class="small" style="margin:.8rem 0 0;">
            You currently have <strong><?= $n ?></strong>
            <?= $n === 1 ? 'entry' : 'entries' ?> on your short-list.
            <a href="preferences.php">Review them &rarr;</a>
          </p>
        <?php endif; endif; ?>
      </div>
    </aside>

  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
