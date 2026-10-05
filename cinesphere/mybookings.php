<?php
/* =====================================================================
 * mybookings.php - the customer's own booking history
 *
 * A server-side generated page: nothing here exists as static markup.
 * Bookings are split into what is still ahead, what has been seen, and
 * the attempts that never became a booking.
 * ===================================================================== */

require_once __DIR__ . '/includes/auth.php';

require_login('Sign in to see your bookings.');

$user_id = current_user_id();
$me      = current_user();

$filter = (string) ($_GET['show'] ?? 'upcoming');
if (!in_array($filter, ['upcoming', 'past', 'other'], true)) {
    $filter = 'upcoming';
}

/* ---- One query, grouped in PHP ------------------------------------ */
$all = db_all(
    'SELECT b.*, st.show_date, st.show_time, st.format,
            m.movie_id, m.title, m.poster, m.classification, m.duration_min,
            h.hall_name, h.hall_type,
            c.name AS cinema_name, c.location AS cinema_location,
            c.address AS cinema_address,
            TIMESTAMP(st.show_date, st.show_time) AS starts_at
       FROM bookings   b
       JOIN showtimes st ON st.showtime_id = b.showtime_id
       JOIN movies    m  ON m.movie_id     = st.movie_id
       JOIN halls     h  ON h.hall_id      = st.hall_id
       JOIN cinemas   c  ON c.cinema_id    = h.cinema_id
      WHERE b.user_id = ?
      ORDER BY TIMESTAMP(st.show_date, st.show_time) DESC',
    [$user_id]
);

$upcoming = [];
$past     = [];
$other    = [];
$now      = time();

foreach ($all as $b) {
    $b['seats']    = get_booking_seats((int) $b['booking_id']);
    $b['starts']   = strtotime($b['starts_at']);
    /* Cancellation closes two hours before the screening begins. */
    $b['can_cancel'] = ($b['booking_status'] === 'confirmed')
                     && ($b['starts'] - $now > 2 * 3600);

    if ($b['booking_status'] !== 'confirmed') {
        $other[] = $b;
    } elseif ($b['starts'] >= $now) {
        $upcoming[] = $b;
    } else {
        $past[] = $b;
    }
}

/* Upcoming reads better soonest-first. */
usort($upcoming, static fn($a, $b) => $a['starts'] <=> $b['starts']);

/* ---- A few figures for the header --------------------------------- */
$totals = db_one(
    "SELECT COUNT(*) AS n, COALESCE(SUM(seat_count), 0) AS tickets,
            COALESCE(SUM(total_amount), 0) AS spent
       FROM bookings
      WHERE user_id = ? AND booking_status = 'confirmed'",
    [$user_id]
);

$favourite = db_one(
    "SELECT m.title, COUNT(*) AS n
       FROM bookings b
       JOIN showtimes st ON st.showtime_id = b.showtime_id
       JOIN movies    m  ON m.movie_id     = st.movie_id
      WHERE b.user_id = ? AND b.booking_status = 'confirmed'
      GROUP BY m.movie_id, m.title
      ORDER BY n DESC, m.title
      LIMIT 1",
    [$user_id]
);

$lists = ['upcoming' => $upcoming, 'past' => $past, 'other' => $other];
$shown = $lists[$filter];

$page_title       = 'My bookings';
$page_description = 'Your CineSphere tickets: what is coming up, what you '
                  . 'have already seen, and anything that did not go through.';

require __DIR__ . '/includes/header.php';
?>

<div class="container">
  <p class="breadcrumb"><a href="index.php">Home</a><span>/</span>My bookings</p>
</div>

<section class="section-tight">
  <div class="container">
    <span class="eyebrow">Signed in as <?= e($me['email']) ?></span>
    <h1>My bookings</h1>
    <p class="lede">
      Everything booked on this account, newest screening first. Tickets can
      be cancelled up to two hours before the film starts.
    </p>
  </div>
</section>

<div class="container" style="padding-bottom:4rem;">

  <!-- ====================== ACCOUNT SUMMARY ===================== -->
  <div class="stat-strip" style="margin-bottom:2rem;">
    <div class="stat">
      <span class="n"><?= (int) $totals['n'] ?></span>
      <span class="l">Bookings made</span>
    </div>
    <div class="stat">
      <span class="n"><?= (int) $totals['tickets'] ?></span>
      <span class="l">Tickets</span>
    </div>
    <div class="stat">
      <span class="n"><?= e(money($totals['spent'])) ?></span>
      <span class="l">Total spent</span>
    </div>
    <div class="stat">
      <span class="n" style="font-size:1.05rem;line-height:1.9;">
        <?= $favourite ? e($favourite['title']) : '&mdash;' ?>
      </span>
      <span class="l">Most booked</span>
    </div>
  </div>

  <!-- ========================== TABS ============================ -->
  <div class="tabs">
    <a href="mybookings.php?show=upcoming" class="<?= $filter === 'upcoming' ? 'active' : '' ?>">
      Coming up <span class="pill"><?= count($upcoming) ?></span>
    </a>
    <a href="mybookings.php?show=past" class="<?= $filter === 'past' ? 'active' : '' ?>">
      Already seen <span class="pill"><?= count($past) ?></span>
    </a>
    <a href="mybookings.php?show=other" class="<?= $filter === 'other' ? 'active' : '' ?>">
      Cancelled &amp; declined <span class="pill"><?= count($other) ?></span>
    </a>
  </div>

  <!-- ========================= THE LIST ========================= -->
  <?php if (!$shown): ?>

    <div class="empty-state">
      <img src="images/step-confirm.svg" alt="" width="74" height="74">
      <?php if ($filter === 'upcoming'): ?>
        <h2>Nothing booked yet</h2>
        <p class="muted">When you confirm a booking it will appear here with its reference.</p>
        <a class="btn btn-primary" href="movies.php">Find a film</a>
      <?php elseif ($filter === 'past'): ?>
        <h2>No past screenings</h2>
        <p class="muted">Films you have already been to will be listed here.</p>
      <?php else: ?>
        <h2>Nothing cancelled or declined</h2>
        <p class="muted">Cancelled bookings and declined payment attempts would show here.</p>
      <?php endif; ?>
    </div>

  <?php else: ?>

    <?php foreach ($shown as $b):
        $isConfirmed = ($b['booking_status'] === 'confirmed');
        $statusClass = 'status-' . $b['booking_status']; ?>

      <article class="ticket">

        <div class="ticket-poster">
          <img src="images/<?= e($b['poster']) ?>"
               alt="Poster for <?= e($b['title']) ?>" width="150" height="225" loading="lazy">
        </div>

        <div class="ticket-main">
          <h3>
            <a href="movie.php?id=<?= (int) $b['movie_id'] ?>"><?= e($b['title']) ?></a>
            <span class="rating"><?= e($b['classification']) ?></span>
          </h3>

          <p class="meta-row small" style="margin-bottom:.4rem;">
            <span class="<?= e($statusClass) ?>" style="font-weight:700;">
              <?= e(ucfirst($b['booking_status'])) ?>
            </span>
            <span>&middot;</span>
            <span><?= e(ucfirst($b['payment_status'])) ?></span>
            <span>&middot;</span>
            <span>Booked <?= e(date('j M Y, g:i A', strtotime($b['booked_at']))) ?></span>
          </p>

          <dl class="ticket-facts">
            <div><dt>When</dt>
              <dd><?= e(pretty_date($b['show_date'])) ?>, <?= e(pretty_time($b['show_time'])) ?></dd></div>
            <div><dt>Venue</dt>
              <dd><?= e($b['cinema_name']) ?></dd></div>
            <div><dt>Hall</dt>
              <dd><?= e($b['hall_name']) ?> &middot; <?= e($b['format']) ?></dd></div>
            <div><dt>Seats</dt>
              <dd class="seats">
                <?= $b['seats'] ? e(seat_list_text($b['seats'])) : '&mdash;' ?>
              </dd></div>
            <div><dt>Tickets</dt>
              <dd><?= (int) $b['seat_count'] ?></dd></div>
            <div><dt>Amount</dt>
              <dd><?= e(money($b['total_amount'])) ?></dd></div>
          </dl>

          <?php if ($b['booking_status'] === 'failed'): ?>
            <p class="notice notice-warning small" style="margin:1rem 0 0;">
              This payment was declined by the gateway, so no seats were
              issued and nothing was charged.
              <?= e($b['payment_method']) ?>.
            </p>
          <?php elseif ($b['booking_status'] === 'cancelled'): ?>
            <p class="notice notice-info small" style="margin:1rem 0 0;">
              Cancelled on <?= e(date('j M Y', strtotime($b['updated_at']))) ?>.
              The seats were released and
              <?= $b['payment_status'] === 'refunded' ? 'a refund was issued'
                                                      : 'no charge was taken' ?>.
            </p>
          <?php endif; ?>

          <div class="btn-row" style="margin-top:1.1rem;">
            <?php if ($isConfirmed): ?>
              <a class="btn btn-ghost btn-sm"
                 href="confirmation.php?ref=<?= e(urlencode($b['booking_ref'])) ?>">
                View e-ticket
              </a>
            <?php endif; ?>

            <?php if ($b['can_cancel']): ?>
              <form method="post" action="cancel_booking.php" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="booking_id" value="<?= (int) $b['booking_id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm"
                        data-confirm="Press again to cancel this booking">
                  Cancel booking
                </button>
              </form>
            <?php elseif ($isConfirmed && $b['starts'] >= $now): ?>
              <span class="small faint">Too close to the start time to cancel online</span>
            <?php endif; ?>
          </div>
        </div>

        <div class="ticket-stub">
          <span class="small muted">Reference</span>
          <span class="ticket-ref"><?= e($b['booking_ref']) ?></span>
          <?php if ($isConfirmed): ?>
            <span class="barcode" data-ref="<?= e($b['booking_ref']) ?>" aria-hidden="true"></span>
            <span class="badge badge-good">Valid</span>
          <?php elseif ($b['booking_status'] === 'cancelled'): ?>
            <span class="badge badge-sold">Cancelled</span>
          <?php else: ?>
            <span class="badge badge-mid">Not paid</span>
          <?php endif; ?>
        </div>

      </article>
    <?php endforeach; ?>

  <?php endif; ?>

  <p class="small muted" style="margin-top:2rem;">
    Need something changed that you cannot do here?
    <a href="contact.php">Send us a message</a> quoting the booking reference.
  </p>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
