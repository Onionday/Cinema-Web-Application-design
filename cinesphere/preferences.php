<?php
/* =====================================================================
 * preferences.php - the customer's ranked short-list
 *
 * This is the page the theme description asks for: the customer lodges
 * several bookings in order of preference, the site presents the ones
 * that are still available, and the customer makes a final selection of
 * one or more of them.
 *
 * Every entry is re-checked against the live bookings table as the page
 * is built, so an entry whose seats were sold in the meantime is shown
 * as unavailable rather than silently failing at payment.
 * ===================================================================== */

require_once __DIR__ . '/includes/auth.php';

require_login('Sign in to see the screenings you have short-listed.');

$user_id = current_user_id();
$prefs   = get_preferences($user_id);

$availableCount = 0;
$availableTotal = 0.0;
$blockedCount   = 0;

foreach ($prefs as $p) {
    if ($p['available']) {
        $availableCount++;
        $availableTotal += (float) $p['est_total'];
    } else {
        $blockedCount++;
    }
}

$page_title       = 'Your short-list';
$page_description = 'Review the screenings you have short-listed, see which '
                  . 'are still available, and confirm the ones you want.';

require __DIR__ . '/includes/header.php';
?>

<div class="container">
  <p class="breadcrumb"><a href="index.php">Home</a><span>/</span>Short-list</p>
</div>

<section class="section-tight">
  <div class="container">
    <span class="eyebrow">Step two of three</span>
    <h1>Your short-list</h1>
    <p class="lede">
      These are the screenings you would accept, in your order of
      preference. Nothing here is booked or paid for yet. Tick the ones
      you want, and we will confirm them together.
    </p>
  </div>
</section>

<div class="container" style="padding-bottom:4rem;">

<?php if (!$prefs): ?>

  <div class="empty-state">
    <img src="images/step-seats.svg" alt="" width="74" height="74">
    <h2>Your short-list is empty</h2>
    <p class="muted">
      Find a screening, choose your seats, and it will appear here. You
      can hold up to <?= (int) MAX_PREFERENCES ?> alternatives at once and
      decide between them later.
    </p>
    <div class="btn-row" style="justify-content:center;">
      <a class="btn btn-primary" href="movies.php">Browse films</a>
      <a class="btn btn-ghost" href="showtimes.php">See the schedule</a>
    </div>
  </div>

<?php else: ?>

  <!-- ===================== STATUS SUMMARY ======================== -->
  <?php if ($blockedCount > 0): ?>
    <p class="notice notice-warning">
      <?= $blockedCount ?> of your <?= count($prefs) ?>
      <?= count($prefs) === 1 ? 'entry is' : 'entries are' ?>
      no longer available &mdash; the seats were booked by someone else, or
      the screening has started. Those entries cannot be confirmed; remove
      them or choose different seats.
    </p>
  <?php elseif ($availableCount > 0): ?>
    <p class="notice notice-success">
      All <?= $availableCount ?>
      <?= $availableCount === 1 ? 'entry is' : 'entries are' ?>
      still available. Tick whichever you want and confirm below.
    </p>
  <?php endif; ?>

  <div class="btn-row" style="margin-bottom:1.4rem;justify-content:space-between;">
    <p class="muted small" style="margin:0;">
      Holding <?= count($prefs) ?> of <?= (int) MAX_PREFERENCES ?> possible entries.
    </p>
    <?php if ($availableCount > 1): ?>
      <button type="button" class="btn btn-ghost btn-sm" id="pick-all">Select all available</button>
    <?php endif; ?>
  </div>


  <!-- =================== THE FINAL SELECTION ===================== -->
  <form id="confirm-form" method="post" action="process_booking.php"
        data-currency="<?= e(CURRENCY) ?>">
    <?= csrf_field() ?>

    <?php foreach ($prefs as $i => $p):
        $ok     = (bool) $p['available'];
        $seats  = seat_list_text($p['seats']);
        $rank   = $i + 1; ?>

      <article class="pref-item <?= $ok ? 'is-available' : 'is-unavailable' ?>">

        <div class="pref-rank">
          <span class="n"><?= $rank ?></span>
          <span class="lbl">Choice</span>
        </div>

        <img src="images/<?= e($p['poster']) ?>"
             alt="Poster for <?= e($p['title']) ?>" width="78" height="117" loading="lazy">

        <div class="pref-detail">
          <h3><?= e($p['title']) ?> <span class="rating"><?= e($p['classification']) ?></span></h3>

          <p class="meta-row small" style="margin-bottom:.35rem;">
            <span><?= e(pretty_date($p['show_date'])) ?></span>
            <span><?= e(pretty_time($p['show_time'])) ?></span>
            <span><?= e($p['format']) ?></span>
            <span><?= e(pretty_runtime((int) $p['duration_min'])) ?></span>
          </p>

          <p class="small muted" style="margin-bottom:.35rem;">
            <?= e($p['cinema_name']) ?> &middot; <?= e($p['cinema_location']) ?>
            &middot; <?= e($p['hall_name']) ?>
          </p>

          <p class="seats" style="margin-bottom:.5rem;">
            <?= (int) $p['seat_count'] ?>
            <?= (int) $p['seat_count'] === 1 ? 'seat' : 'seats' ?>:
            <?= e($seats) ?>
          </p>

          <?php if (!$ok): ?>
            <p class="small" style="color:var(--rose);margin:0;">
              <?php if ($p['expired']): ?>
                This screening has already started.
              <?php elseif ($p['cancelled']): ?>
                This screening has been cancelled by the venue.
              <?php else: ?>
                <?= e(implode(', ', $p['clashes'])) ?>
                <?= count($p['clashes']) === 1 ? 'has' : 'have' ?>
                been booked by someone else.
              <?php endif; ?>
            </p>
          <?php endif; ?>
        </div>

        <div class="pref-side">
          <span class="price"><?= e(money($p['est_total'])) ?></span>

          <?php if ($ok): ?>
            <label class="check-row" style="justify-content:flex-end;">
              <input type="checkbox"
                     class="pref-pick"
                     name="confirm[]"
                     value="<?= (int) $p['pref_id'] ?>"
                     data-amount="<?= e(number_format((float) $p['est_total'], 2, '.', '')) ?>"
                     data-seats="<?= (int) $p['seat_count'] ?>">
              <span>Confirm this one</span>
            </label>
          <?php else: ?>
            <span class="badge badge-sold">Unavailable</span>
          <?php endif; ?>

          <div class="btn-row" style="justify-content:flex-end;gap:.4rem;">
            <?php if ($ok): ?>
              <a class="btn btn-ghost btn-sm"
                 href="booking.php?showtime=<?= (int) $p['showtime_id'] ?>">Seat map</a>
            <?php else: ?>
              <a class="btn btn-ghost btn-sm"
                 href="movie.php?id=<?= (int) $p['movie_id'] ?>">Other times</a>
            <?php endif; ?>
          </div>
        </div>
      </article>
    <?php endforeach; ?>

    <p class="notice notice-error" id="pref-warning" hidden></p>


    <!-- ======================= PAYMENT ========================== -->
    <?php if ($availableCount > 0): ?>
    <div class="card card-pad-lg" style="margin-top:2rem;">
      <h2 style="font-size:1.15rem;">Payment</h2>
      <p class="muted small">
        Payment is handled by an external gateway; this site never sees or
        stores card details. For this coursework build the gateway is
        simulated, so you can choose which answer it comes back with and
        see how the application handles each one.
      </p>

      <div class="pay-options" style="margin-top:1.2rem;">
        <label class="pay-option">
          <input type="radio" name="gateway" value="success" checked>
          <span>
            <span class="t">Payment approved</span>
            <span class="d">The gateway authorises the charge. Bookings are issued and the acknowledgement is emailed.</span>
          </span>
        </label>

        <label class="pay-option">
          <input type="radio" name="gateway" value="declined">
          <span>
            <span class="t">Payment declined</span>
            <span class="d">The gateway refuses the charge. Nothing is booked, the seats stay on your short-list, and the attempt is recorded.</span>
          </span>
        </label>
      </div>

      <label class="check-row" style="margin-top:1.2rem;">
        <input type="checkbox" name="agree" value="1" required>
        <span>
          I have checked the films, times and seats above. I understand
          tickets can be cancelled up to two hours before the screening
          begins.
        </span>
      </label>
    </div>
    <?php endif; ?>


    <!-- ====================== CHECKOUT BAR ====================== -->
    <?php if ($availableCount > 0): ?>
    <div class="checkout-bar">
      <div>
        <p style="margin:0;" class="small muted" id="pref-count">Nothing selected</p>
        <p class="total" style="margin:0;">
          Total to pay <strong id="pref-total"><?= e(money(0)) ?></strong>
        </p>
      </div>
      <div class="btn-row">
        <a class="btn btn-ghost" href="showtimes.php">Add another screening</a>
        <button type="submit" id="pay-btn" class="btn btn-primary btn-lg is-disabled"
                data-busy="Contacting gateway&hellip;">
          Confirm selected bookings
        </button>
      </div>
    </div>
    <?php else: ?>
    <div class="checkout-bar">
      <p class="muted" style="margin:0;">
        None of your short-listed screenings can be confirmed right now.
      </p>
      <a class="btn btn-primary" href="showtimes.php">Find another screening</a>
    </div>
    <?php endif; ?>

  </form>


  <!-- ====================== HOUSEKEEPING ======================== -->
  <div class="card" style="margin-top:2rem;">
    <h2 style="font-size:1.05rem;">Reorder or remove</h2>
    <p class="muted small">
      Your ranking decides the order the entries appear in and the order
      they are confirmed if you select several at once.
    </p>

    <div class="table-wrap" style="margin-top:1rem;">
      <table class="data" style="min-width:560px;">
        <thead>
          <tr>
            <th scope="col">#</th>
            <th scope="col">Screening</th>
            <th scope="col">Seats</th>
            <th scope="col" class="num">Total</th>
            <th scope="col">Move</th>
            <th scope="col"><span class="sr-only">Remove</span></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($prefs as $i => $p): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td>
              <strong><?= e($p['title']) ?></strong><br>
              <span class="small muted">
                <?= e(pretty_date($p['show_date'])) ?>,
                <?= e(pretty_time($p['show_time'])) ?> &middot; <?= e($p['hall_name']) ?>
              </span>
            </td>
            <td class="small"><?= e(seat_list_text($p['seats'])) ?></td>
            <td class="num"><?= e(money($p['est_total'])) ?></td>
            <td class="nowrap">
              <form method="post" action="remove_preference.php" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="pref_id" value="<?= (int) $p['pref_id'] ?>">
                <input type="hidden" name="action" value="up">
                <button type="submit" class="btn btn-ghost btn-sm"
                        <?= $i === 0 ? 'disabled' : '' ?>
                        title="Move up">&uarr;</button>
              </form>
              <form method="post" action="remove_preference.php" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="pref_id" value="<?= (int) $p['pref_id'] ?>">
                <input type="hidden" name="action" value="down">
                <button type="submit" class="btn btn-ghost btn-sm"
                        <?= $i === count($prefs) - 1 ? 'disabled' : '' ?>
                        title="Move down">&darr;</button>
              </form>
            </td>
            <td>
              <form method="post" action="remove_preference.php" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="pref_id" value="<?= (int) $p['pref_id'] ?>">
                <input type="hidden" name="action" value="remove">
                <button type="submit" class="btn btn-ghost btn-sm"
                        data-confirm="Press again to remove">Remove</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
