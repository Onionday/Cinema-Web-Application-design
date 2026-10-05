<?php
/* =====================================================================
 * admin.php - the staff page
 *
 * Restricted to accounts whose role is 'admin'. This is where the
 * project's INSERT and UPDATE statements against the catalogue live:
 * new screenings are scheduled, films are moved between "coming soon"
 * and "now showing", screenings are cancelled and feedback is triaged.
 * ===================================================================== */

require_once __DIR__ . '/includes/auth.php';

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

$form = take_form('showtime');
$fErr = $form['errors'];
$fOld = $form['old'];

$page_title       = 'Staff';
$page_description = 'CineSphere staff tools: schedule screenings, manage the '
                  . 'catalogue and triage guest feedback.';
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
      Schedule screenings, move films on and off release, cancel a show and
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


      <!-- ============= CANCEL A SCREENING (UPDATE) ============ -->
      <div class="panel">
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
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
