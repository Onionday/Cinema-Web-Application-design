<?php
/* =====================================================================
 * admin.php - the staff page
 *
 * Restricted to accounts whose role is 'admin'. This is where the
 * project's INSERT and UPDATE statements against the catalogue live:
 * new screenings are scheduled, films are moved between "coming soon"
 * and "now showing", screenings are cancelled and feedback is triaged.
 *
 * admin.php?booking=CS1A2B3C26 opens one booking for staff to refund
 * seats, move a seat or cancel it; the upcoming schedule lets staff
 * change a screening's ticket price. Both post to admin_action.php.
 * ===================================================================== */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/booking_admin.php';

require_admin();

$me = current_user();

/* ---- Reference data for the form ---------------------------------- */
$movies = db_all("SELECT movie_id, title, status FROM movies
                   WHERE status <> 'archived' ORDER BY title");

$halls = db_all(
    'SELECT h.hall_id, h.hall_name, h.hall_type,
            (h.row_count * h.seats_per_row) AS seats, c.name AS cinema_name
       FROM halls h JOIN cinemas c ON c.cinema_id = h.cinema_id
      ORDER BY c.name, h.hall_name'
);

/* ---- Headline numbers --------------------------------------------- */
$kpi = db_one(
    "SELECT
        (SELECT COUNT(*) FROM bookings WHERE booking_status = 'confirmed')  AS bookings,
        (SELECT COALESCE(SUM(total_amount), 0) FROM bookings
          WHERE booking_status = 'confirmed')                               AS revenue,
        (SELECT COUNT(*) FROM booking_seats)                                AS seats_sold,
        (SELECT COUNT(*) FROM contact_messages WHERE msg_status = 'new')    AS new_msgs"
);

/* ---- Busiest screenings ------------------------------------------- */
$busiest = db_all(
    "SELECT v.*, ROUND(v.seats_taken / v.total_seats * 100) AS pct
       FROM v_showtime_availability v
      WHERE TIMESTAMP(v.show_date, v.show_time) >= NOW()
        AND v.status = 'open'
      ORDER BY (v.seats_taken / v.total_seats) DESC, v.show_date, v.show_time
      LIMIT 8"
);

/* ---- Recent bookings ---------------------------------------------- */
$recent = db_all(
    'SELECT b.booking_ref, b.seat_count, b.total_amount, b.booking_status,
            b.payment_status, b.booked_at, u.full_name, u.email,
            m.title, st.show_date, st.show_time
       FROM bookings   b
       JOIN users     u  ON u.user_id      = b.user_id
       JOIN showtimes st ON st.showtime_id = b.showtime_id
       JOIN movies    m  ON m.movie_id     = st.movie_id
      ORDER BY b.booked_at DESC
      LIMIT 12'
);

/* ---- Feedback queue ------------------------------------------------ */
$msgFilter = (string) ($_GET['msgs'] ?? 'new');
if (!in_array($msgFilter, ['new', 'read', 'resolved', 'all'], true)) {
    $msgFilter = 'new';
}

$messages = $msgFilter === 'all'
    ? db_all('SELECT * FROM contact_messages ORDER BY submitted_at DESC LIMIT 25')
    : db_all('SELECT * FROM contact_messages WHERE msg_status = ?
               ORDER BY submitted_at DESC LIMIT 25', [$msgFilter]);

/* ---- Upcoming schedule, so staff can cancel a screening ------------ */
$schedule = db_all(
    "SELECT v.* FROM v_showtime_availability v
      WHERE TIMESTAMP(v.show_date, v.show_time) >= NOW()
      ORDER BY v.show_date, v.show_time
      LIMIT 20"
);

/* ---- One booking to manage (admin.php?booking=REF) ---------------- */
$lookupRaw   = trim((string) ($_GET['booking'] ?? ''));
$lookupRef   = v_booking_ref($lookupRaw);
$manage      = $lookupRef !== '' ? find_booking_by_ref($lookupRef) : null;
$manageHeld  = [];   // seats the booking holds now
$manageSeats = [];   // what to list (history for cancelled bookings)
$manageFree  = [];   // free seats a held seat can move to
$manageLog   = [];
$canChange   = false;

if ($manage) {
    $bid         = (int) $manage['booking_id'];
    $manageHeld  = get_held_seats($bid);
    $manageSeats = $manageHeld ?: get_booking_seats($bid);
    $manageLog   = get_booking_adjustments($bid);
    $canChange   = $manage['booking_status'] === 'confirmed'
                   && !showtime_has_passed($manage) && $manageHeld;
    if ($canChange) {
        $mst        = get_showtime((int) $manage['showtime_id']);
        $manageFree = get_free_seats((int) $manage['showtime_id'], (int) $mst['hall_id']);
    }
}

$form = take_form('showtime');
$fErr = $form['errors'];
$fOld = $form['old'];

$page_title       = 'Staff';
$page_description = 'CineSphere staff tools: schedule screenings, set ticket prices, '
                  . 'manage customer bookings and triage guest feedback.';
$page_scripts     = ['validate.js'];

require __DIR__ . '/includes/header.php';
?>

<div class="container">
  <p class="breadcrumb"><a href="index.php">Home</a><span>/</span>Staff</p>
</div>

<section class="section-tight">
  <div class="container">
    <span class="eyebrow">Signed in as <?= e($me['full_name']) ?></span>
    <h1>Staff tools</h1>
    <p class="lede">
      Schedule screenings and set their prices, move films on and off
      release, refund or change a customer's booking, cancel a show and
      work through guest feedback. Every action here writes straight to the
      database the public pages read from.
    </p>
  </div>
</section>

<div class="container" style="padding-bottom:4rem;">

  <!-- ======================== KPI STRIP ========================== -->
  <div class="stat-strip" style="margin-bottom:2rem;">
    <div class="stat"><span class="n"><?= (int) $kpi['bookings'] ?></span><span class="l">Confirmed bookings</span></div>
    <div class="stat"><span class="n"><?= e(money($kpi['revenue'])) ?></span><span class="l">Gross takings</span></div>
    <div class="stat"><span class="n"><?= (int) $kpi['seats_sold'] ?></span><span class="l">Seats sold</span></div>
    <div class="stat"><span class="n"><?= (int) $kpi['new_msgs'] ?></span><span class="l">Unread messages</span></div>
  </div>

  <!-- ============== MANAGE A BOOKING (UPDATE / DELETE) ========== -->
  <div class="panel" id="manage">
    <div class="panel-head">
      <h2>Manage a booking</h2>
      <span class="badge badge-gold">UPDATE &middot; DELETE</span>
    </div>
    <div class="panel-body">

      <form method="get" action="admin.php#manage" class="form-actions" style="margin-bottom:1.2rem;" data-validate>
        <div class="field" style="flex:1 1 220px;margin:0;">
          <label for="m-ref">Booking reference</label>
          <input type="text" name="booking" id="m-ref" value="<?= e($lookupRaw) ?>"
                 placeholder="e.g. CS1A2B3C26" required minlength="4" maxlength="16"
                 pattern="[A-Za-z0-9]{4,16}" autocomplete="off"
                 data-check="required">
          <span class="error"></span>
        </div>
        <button type="submit" class="btn btn-primary" style="align-self:flex-end;">Find booking</button>
        <span class="small muted" style="align-self:flex-end;">Or choose <em>Manage</em> on a row under Recent bookings.</span>
      </form>

      <?php if ($lookupRaw !== '' && !$manage): ?>
        <p class="notice notice-warning">No booking has the reference <code><?= e($lookupRaw) ?></code>.</p>
      <?php endif; ?>

      <?php if ($manage): ?>
        <div class="table-wrap">
          <table class="data" style="min-width:auto;">
            <caption>Booking <code><?= e($manage['booking_ref']) ?></code></caption>
            <tbody>
              <tr><th scope="row">Customer</th>
                  <td><?= e($manage['full_name']) ?> <span class="small muted">&middot; <?= e($manage['email']) ?></span></td></tr>
              <tr><th scope="row">Screening</th>
                  <td><?= e($manage['title']) ?> &middot; <?= e(pretty_date($manage['show_date'])) ?>,
                      <?= e(pretty_time($manage['show_time'])) ?> &middot; <?= e($manage['cinema_name']) ?>,
                      <?= e($manage['hall_name']) ?></td></tr>
              <tr><th scope="row">Status</th>
                  <td><span class="badge <?= $manage['booking_status'] === 'confirmed' ? 'badge-good'
                        : ($manage['booking_status'] === 'cancelled' ? 'badge-sold' : 'badge-mid') ?>">
                        <?= e(ucfirst($manage['booking_status'])) ?></span>
                      <span class="small muted">payment <?= e($manage['payment_status']) ?></span></td></tr>
              <tr><th scope="row">Seats</th>
                  <td><?= (int) $manage['seat_count'] ?> &middot; <?= e(seat_list_text($manageSeats)) ?></td></tr>
              <tr><th scope="row">Total paid</th>
                  <td><?= e(money($manage['total_amount'])) ?>
                      <span class="small muted">(seat prices + <?= e(money(BOOKING_FEE)) ?> fee per seat)</span></td></tr>
            </tbody>
          </table>
        </div>

        <?php if (!$canChange): ?>
          <p class="notice notice-info" style="margin-top:1rem;">
            This booking can no longer be changed: only confirmed bookings for
            screenings that have not started can be refunded or moved.
          </p>
        <?php else: ?>

          <div class="admin-grid" style="margin-top:1.4rem;">

            <!-- Refund selected seats -->
            <form method="post" action="admin_action.php" data-validate>
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="refund_seats">
              <input type="hidden" name="booking_id" value="<?= (int) $manage['booking_id'] ?>">
              <h3>Refund seats</h3>
              <p class="small muted">Tick the seats to refund. Each returns its price plus its
                <?= e(money(BOOKING_FEE)) ?> fee, and goes back on sale.</p>
              <fieldset class="field" style="border:0;padding:0;margin:0 0 1rem;">
                <legend class="sr-only">Seats to refund</legend>
                <?php foreach ($manageHeld as $s): ?>
                  <label class="check-row">
                    <input type="checkbox" name="seat_ids[]" value="<?= (int) $s['seat_id'] ?>">
                    <span><strong><?= e($s['seat_row'] . $s['seat_number']) ?></strong>
                      &middot; <?= e($s['seat_type']) ?> &middot; <?= e(money($s['seat_price'])) ?></span>
                  </label>
                <?php endforeach; ?>
              </fieldset>
              <div class="field">
                <label for="r-reason">Reason <span class="req">*</span></label>
                <input type="text" name="reason" id="r-reason" required minlength="5" maxlength="160"
                       placeholder="e.g. Guest can no longer attend with one friend"
                       data-check="required minlen" data-minlen="5">
                <span class="error"></span>
              </div>
              <button type="submit" class="btn btn-ghost" data-confirm="Press again to refund">
                Refund selected seats
              </button>
            </form>

            <!-- Move one seat -->
            <form method="post" action="admin_action.php" data-validate>
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="move_seat">
              <input type="hidden" name="booking_id" value="<?= (int) $manage['booking_id'] ?>">
              <h3>Change a seat</h3>
              <p class="small muted">The customer keeps the price paid; only a different seat
                type adds or refunds the surcharge difference.</p>
              <div class="field">
                <label for="mv-from">Seat to move <span class="req">*</span></label>
                <select name="from_seat" id="mv-from" required data-check="choose">
                  <option value="">Choose a seat&hellip;</option>
                  <?php foreach ($manageHeld as $s): ?>
                    <option value="<?= (int) $s['seat_id'] ?>">
                      <?= e($s['seat_row'] . $s['seat_number']) ?> (<?= e($s['seat_type']) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
                <span class="error"></span>
              </div>
              <div class="field">
                <label for="mv-to">Move to <span class="req">*</span></label>
                <select name="to_seat" id="mv-to" required data-check="choose">
                  <option value="">Choose a free seat&hellip;</option>
                  <?php foreach ($manageFree as $s): ?>
                    <option value="<?= (int) $s['seat_id'] ?>">
                      <?= e($s['seat_row'] . $s['seat_number']) ?> &middot; <?= e($s['seat_type']) ?>
                      <?= (float) $s['surcharge'] > 0 ? '(+' . e(money($s['surcharge'])) . ')' : '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <span class="error"></span>
              </div>
              <div class="field">
                <label for="mv-reason">Reason <span class="req">*</span></label>
                <input type="text" name="reason" id="mv-reason" required minlength="5" maxlength="160"
                       placeholder="e.g. Wheelchair access requested"
                       data-check="required minlen" data-minlen="5">
                <span class="error"></span>
              </div>
              <button type="submit" class="btn btn-ghost">Move seat</button>
            </form>
          </div>

          <!-- Cancel the whole booking -->
          <form method="post" action="admin_action.php" class="form-actions" data-validate
                style="margin-top:.6rem;padding-top:1.2rem;border-top:1px solid var(--line-soft);">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cancel_booking">
            <input type="hidden" name="booking_id" value="<?= (int) $manage['booking_id'] ?>">
            <div class="field" style="flex:1 1 260px;margin:0;">
              <label for="c-reason">Reason for cancelling <span class="req">*</span></label>
              <input type="text" name="reason" id="c-reason" required minlength="5" maxlength="160"
                     placeholder="e.g. Customer called the box office"
                     data-check="required minlen" data-minlen="5">
              <span class="error"></span>
            </div>
            <button type="submit" class="btn btn-ghost" style="align-self:flex-end;"
                    data-confirm="Press again to cancel and refund">
              Cancel booking and refund <?= e(money($manage['total_amount'])) ?>
            </button>
          </form>
        <?php endif; ?>

        <h3 style="margin-top:1.6rem;">Change log</h3>
        <?php if (!$manageLog): ?>
          <p class="small muted">No staff changes to this booking yet.</p>
        <?php else: ?>
          <div class="table-wrap">
            <table class="data" style="min-width:auto;">
              <thead>
                <tr>
                  <th scope="col">When</th>
                  <th scope="col">Change</th>
                  <th scope="col">Reason</th>
                  <th scope="col" class="num">Amount</th>
                  <th scope="col">Staff</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($manageLog as $a): $amt = (float) $a['amount_change']; ?>
                <tr>
                  <td class="small nowrap"><?= e(date('j M, g:i A', strtotime($a['created_at']))) ?></td>
                  <td class="small"><?= e($a['detail']) ?></td>
                  <td class="small"><?= e($a['reason']) ?></td>
                  <td class="num small nowrap"><?= $amt < 0 ? 'Refund ' . e(money(-$amt))
                        : ($amt > 0 ? 'Collect ' . e(money($amt)) : '&mdash;') ?></td>
                  <td class="small"><?= e($a['staff_name'] ?? 'Former staff') ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="admin-grid">

    <!-- ============== SCHEDULE A NEW SCREENING (INSERT) ========== -->
    <div>
      <div class="panel">
        <div class="panel-head">
          <h2>Schedule a screening</h2>
          <span class="badge badge-gold">INSERT</span>
        </div>
        <div class="panel-body">

          <p class="notice notice-error form-summary" <?= $fErr ? '' : 'hidden' ?>>
            <?= $fErr ? e(reset($fErr)) : '' ?>
          </p>

          <form method="post" action="admin_action.php" data-validate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_showtime">

            <div class="form-grid">

              <div class="field field-wide <?= isset($fErr['movie_id']) ? 'has-error' : '' ?>">
                <label for="a-movie">Film <span class="req">*</span></label>
                <select name="movie_id" id="a-movie" required data-check="choose">
                  <option value="">Choose a film&hellip;</option>
                  <?php foreach ($movies as $m): ?>
                    <option value="<?= (int) $m['movie_id'] ?>"
                            <?= (string) ($fOld['movie_id'] ?? '') === (string) $m['movie_id'] ? 'selected' : '' ?>>
                      <?= e($m['title']) ?>
                      <?= $m['status'] === 'coming_soon' ? ' (coming soon)' : '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <span class="error"><?= e($fErr['movie_id'] ?? '') ?></span>
              </div>

              <div class="field field-wide <?= isset($fErr['hall_id']) ? 'has-error' : '' ?>">
                <label for="a-hall">Hall <span class="req">*</span></label>
                <select name="hall_id" id="a-hall" required data-check="choose">
                  <option value="">Choose a hall&hellip;</option>
                  <?php foreach ($halls as $h): ?>
                    <option value="<?= (int) $h['hall_id'] ?>"
                            <?= (string) ($fOld['hall_id'] ?? '') === (string) $h['hall_id'] ? 'selected' : '' ?>>
                      <?= e($h['cinema_name']) ?> &mdash; <?= e($h['hall_name']) ?>
                      (<?= (int) $h['seats'] ?> seats)
                    </option>
                  <?php endforeach; ?>
                </select>
                <span class="error"><?= e($fErr['hall_id'] ?? '') ?></span>
              </div>

              <div class="field <?= isset($fErr['show_date']) ? 'has-error' : '' ?>">
                <label for="a-date">Date <span class="req">*</span></label>
                <input type="date" name="show_date" id="a-date"
                       value="<?= e($fOld['show_date'] ?? date('Y-m-d')) ?>"
                       min="<?= e(date('Y-m-d')) ?>"
                       max="<?= e(date('Y-m-d', strtotime('+90 days'))) ?>"
                       required data-check="futuredate">
                <span class="error"><?= e($fErr['show_date'] ?? '') ?></span>
              </div>

              <div class="field <?= isset($fErr['show_time']) ? 'has-error' : '' ?>">
                <label for="a-time">Start time <span class="req">*</span></label>
                <input type="time" name="show_time" id="a-time"
                       value="<?= e($fOld['show_time'] ?? '19:45') ?>"
                       required data-check="required">
                <span class="error"><?= e($fErr['show_time'] ?? '') ?></span>
              </div>

              <div class="field <?= isset($fErr['format']) ? 'has-error' : '' ?>">
                <label for="a-format">Format <span class="req">*</span></label>
                <select name="format" id="a-format" required data-check="choose">
                  <?php foreach (['2D', '3D', 'IMAX'] as $f): ?>
                    <option value="<?= e($f) ?>"
                            <?= ($fOld['format'] ?? '2D') === $f ? 'selected' : '' ?>>
                      <?= e($f) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <span class="error"><?= e($fErr['format'] ?? '') ?></span>
              </div>

              <div class="field <?= isset($fErr['base_price']) ? 'has-error' : '' ?>">
                <label for="a-price">Base price (<?= e(CURRENCY) ?>) <span class="req">*</span></label>
                <input type="number" name="base_price" id="a-price"
                       value="<?= e($fOld['base_price'] ?? '15.50') ?>"
                       step="0.50" min="5" max="60" required
                       data-check="number" data-min="5" data-max="60">
                <span class="hint">Cheapest seat. Premium and couch add on top.</span>
                <span class="error"><?= e($fErr['base_price'] ?? '') ?></span>
              </div>

            </div>

            <div class="form-actions">
              <button type="submit" class="btn btn-primary" data-busy="Scheduling&hellip;">
                Add to schedule
              </button>
              <span class="small muted">
                The hall must be free at that date and time.
              </span>
            </div>
          </form>
        </div>
      </div>


      <!-- ============ CATALOGUE STATUS (UPDATE) ================= -->
      <div class="panel">
        <div class="panel-head">
          <h2>Catalogue</h2>
          <span class="badge badge-gold">UPDATE</span>
        </div>
        <div class="panel-body">
          <div class="table-wrap">
            <table class="data" style="min-width:auto;">
              <thead>
                <tr>
                  <th scope="col">Film</th>
                  <th scope="col">Status</th>
                  <th scope="col">Move to</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($movies as $m):
                    $next = $m['status'] === 'now_showing' ? 'coming_soon' : 'now_showing'; ?>
                <tr>
                  <td><?= e($m['title']) ?></td>
                  <td>
                    <span class="badge <?= $m['status'] === 'now_showing' ? 'badge-good' : 'badge-teal' ?>">
                      <?= $m['status'] === 'now_showing' ? 'Now showing' : 'Coming soon' ?>
                    </span>
                  </td>
                  <td>
                    <form method="post" action="admin_action.php" class="inline-form">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="set_movie_status">
                      <input type="hidden" name="movie_id" value="<?= (int) $m['movie_id'] ?>">
                      <input type="hidden" name="status" value="<?= e($next) ?>">
                      <button type="submit" class="btn btn-ghost btn-sm">
                        <?= $next === 'now_showing' ? 'Put on release' : 'Move to coming soon' ?>
                      </button>
                    </form>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>


    <!-- =================== RIGHT-HAND COLUMN ==================== -->
    <div>

      <!-- ================ OCCUPANCY ========================= -->
      <div class="panel">
        <div class="panel-head">
          <h2>Filling fastest</h2>
          <span class="badge">SELECT</span>
        </div>
        <div class="panel-body">
          <?php if (!$busiest): ?>
            <p class="muted small" style="margin:0;">No screenings scheduled.</p>
          <?php else: ?>
            <div class="table-wrap">
              <table class="data" style="min-width:auto;">
                <thead>
                  <tr>
                    <th scope="col">Screening</th>
                    <th scope="col" class="num">Sold</th>
                    <th scope="col" class="num">Full</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($busiest as $b): ?>
                  <tr>
                    <td>
                      <strong><?= e($b['title']) ?></strong><br>
                      <span class="small muted">
                        <?= e(pretty_date($b['show_date'])) ?>,
                        <?= e(pretty_time($b['show_time'])) ?> &middot;
                        <?= e($b['hall_name']) ?>
                      </span>
                    </td>
                    <td class="num"><?= (int) $b['seats_taken'] ?>/<?= (int) $b['total_seats'] ?></td>
                    <td class="num"><?= (int) $b['pct'] ?>%</td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>


      <!-- ====== CANCEL A SCREENING / SET ITS PRICE (UPDATE) ====== -->
      <div class="panel" id="schedule">
        <div class="panel-head">
          <h2>Upcoming schedule</h2>
          <span class="badge badge-gold">UPDATE</span>
        </div>
        <div class="panel-body">
          <div class="table-wrap">
            <table class="data" style="min-width:auto;">
              <thead>
                <tr>
                  <th scope="col">When</th>
                  <th scope="col">Film / hall</th>
                  <th scope="col">Base price</th>
                  <th scope="col">Status</th>
                  <th scope="col"><span class="sr-only">Action</span></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($schedule as $s): ?>
                <tr>
                  <td class="nowrap small">
                    <?= e(date('D j M', strtotime($s['show_date']))) ?><br>
                    <?= e(pretty_time($s['show_time'])) ?>
                  </td>
                  <td class="small">
                    <strong><?= e($s['title']) ?></strong><br>
                    <span class="muted"><?= e($s['hall_name']) ?> &middot; <?= e($s['format']) ?></span>
                  </td>
                  <td>
                    <?php if ($s['status'] !== 'cancelled'): ?>
                      <form method="post" action="admin_action.php" class="inline-form" style="gap:.3rem;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="set_price">
                        <input type="hidden" name="showtime_id" value="<?= (int) $s['showtime_id'] ?>">
                        <label class="sr-only" for="p-<?= (int) $s['showtime_id'] ?>">
                          Base price for <?= e($s['title']) ?> on <?= e(pretty_date($s['show_date'])) ?>
                        </label>
                        <input type="number" name="base_price" id="p-<?= (int) $s['showtime_id'] ?>"
                               value="<?= e(number_format((float) $s['base_price'], 2, '.', '')) ?>"
                               step="0.50" min="5" max="60" required
                               style="width:5.2rem;padding:.3rem .4rem;background:var(--bg-sunken);color:var(--ink);border:1px solid var(--line);border-radius:6px;">
                        <button type="submit" class="btn btn-ghost btn-sm">Set</button>
                      </form>
                    <?php else: ?>
                      <span class="small muted"><?= e(money($s['base_price'])) ?></span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="badge <?= $s['status'] === 'open' ? 'badge-good' : 'badge-sold' ?>">
                      <?= e(ucfirst($s['status'])) ?>
                    </span>
                  </td>
                  <td>
                    <?php if ($s['status'] === 'open'): ?>
                      <form method="post" action="admin_action.php" class="inline-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="cancel_showtime">
                        <input type="hidden" name="showtime_id" value="<?= (int) $s['showtime_id'] ?>">
                        <button type="submit" class="btn btn-ghost btn-sm"
                                data-confirm="Press again to cancel">Cancel</button>
                      </form>
                    <?php else: ?>
                      <form method="post" action="admin_action.php" class="inline-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reopen_showtime">
                        <input type="hidden" name="showtime_id" value="<?= (int) $s['showtime_id'] ?>">
                        <button type="submit" class="btn btn-ghost btn-sm">Reopen</button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>


      <!-- ================ FEEDBACK QUEUE (UPDATE) ============= -->
      <div class="panel">
        <div class="panel-head">
          <h2>Guest feedback</h2>
          <form method="get" action="admin.php" class="inline-form" data-autosubmit>
            <select name="msgs" aria-label="Filter messages"
                    style="padding:.35rem .6rem;background:var(--bg-sunken);color:var(--ink);border:1px solid var(--line);border-radius:6px;">
              <option value="new"      <?= $msgFilter === 'new'      ? 'selected' : '' ?>>New</option>
              <option value="read"     <?= $msgFilter === 'read'     ? 'selected' : '' ?>>Read</option>
              <option value="resolved" <?= $msgFilter === 'resolved' ? 'selected' : '' ?>>Resolved</option>
              <option value="all"      <?= $msgFilter === 'all'      ? 'selected' : '' ?>>All</option>
            </select>
          </form>
        </div>
        <div class="panel-body">
          <?php if (!$messages): ?>
            <p class="muted small" style="margin:0;">Nothing in this queue.</p>
          <?php else: ?>
            <?php foreach ($messages as $msg): ?>
              <div style="padding:1rem 0;border-bottom:1px solid var(--line-soft);">
                <p class="meta-row small" style="margin-bottom:.3rem;">
                  <strong><?= e($msg['name']) ?></strong>
                  <span class="badge"><?= e($msg['subject']) ?></span>
                  <span class="score"><?= (int) $msg['rating'] ?>/5</span>
                  <span class="muted"><?= e(date('j M', strtotime($msg['submitted_at']))) ?></span>
                  <span class="badge <?= $msg['msg_status'] === 'new' ? 'badge-mid' : 'badge' ?>">
                    <?= e(ucfirst($msg['msg_status'])) ?>
                  </span>
                </p>
                <p class="small" style="margin-bottom:.5rem;"><?= e($msg['message']) ?></p>
                <p class="small faint" style="margin-bottom:.5rem;">
                  <?= e($msg['email']) ?> &middot; <?= e($msg['phone']) ?>
                </p>
                <div class="btn-row">
                  <?php foreach (['read' => 'Mark read', 'resolved' => 'Mark resolved'] as $st => $lbl):
                      if ($msg['msg_status'] === $st) { continue; } ?>
                    <form method="post" action="admin_action.php" class="inline-form">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="set_msg_status">
                      <input type="hidden" name="msg_id" value="<?= (int) $msg['msg_id'] ?>">
                      <input type="hidden" name="status" value="<?= e($st) ?>">
                      <button type="submit" class="btn btn-ghost btn-sm"><?= e($lbl) ?></button>
                    </form>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </div>


  <!-- ==================== RECENT BOOKINGS ====================== -->
  <div class="panel" style="margin-top:1.6rem;">
    <div class="panel-head">
      <h2>Recent bookings</h2>
      <span class="badge">SELECT</span>
    </div>
    <div class="panel-body">
      <div class="table-wrap">
        <table class="data">
          <caption>
            The last <?= count($recent) ?> transactions
            <span class="muted">Declined attempts are kept so the gateway can be reconciled.</span>
          </caption>
          <thead>
            <tr>
              <th scope="col">Reference</th>
              <th scope="col">Customer</th>
              <th scope="col">Film</th>
              <th scope="col">Screening</th>
              <th scope="col" class="num">Seats</th>
              <th scope="col" class="num">Amount</th>
              <th scope="col">Status</th>
              <th scope="col">Booked</th>
              <th scope="col"><span class="sr-only">Action</span></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recent as $r): ?>
            <tr>
              <td><code><?= e($r['booking_ref']) ?></code></td>
              <td>
                <?= e($r['full_name']) ?><br>
                <span class="small muted"><?= e($r['email']) ?></span>
              </td>
              <td><?= e($r['title']) ?></td>
              <td class="small nowrap">
                <?= e(date('j M', strtotime($r['show_date']))) ?>,
                <?= e(pretty_time($r['show_time'])) ?>
              </td>
              <td class="num"><?= (int) $r['seat_count'] ?></td>
              <td class="num"><?= e(money($r['total_amount'])) ?></td>
              <td>
                <span class="badge <?= $r['booking_status'] === 'confirmed' ? 'badge-good'
                                     : ($r['booking_status'] === 'cancelled' ? 'badge-sold' : 'badge-mid') ?>">
                  <?= e(ucfirst($r['booking_status'])) ?>
                </span>
              </td>
              <td class="small nowrap"><?= e(date('j M, g:i A', strtotime($r['booked_at']))) ?></td>
              <td>
                <a class="btn btn-ghost btn-sm"
                   href="admin.php?booking=<?= e(urlencode($r['booking_ref'])) ?>#manage">Manage</a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
