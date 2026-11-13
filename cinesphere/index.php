<?php
/* =====================================================================
 * index.php - home page
 *
 * Server-side generated: the featured film, the "now showing" grid, the
 * headline figures and the next screenings are all read from MySQL on
 * every request, so the page changes as the schedule and the bookings
 * change.
 * ===================================================================== */

require_once __DIR__ . '/includes/auth.php';

/* -- The featured film: the best-rated title currently on release ---- */
$featured = db_one(
    "SELECT * FROM movies
      WHERE status = 'now_showing'
      ORDER BY user_score DESC, release_date DESC
      LIMIT 1"
);

/* -- Everything on release, with a count of upcoming screenings ------ */
$nowShowing = db_all(
    "SELECT m.*, /*take every column from the movies table*/
            (SELECT COUNT(*)
               FROM showtimes st
              WHERE st.movie_id = m.movie_id
                AND st.status = 'open'
                AND TIMESTAMP(st.show_date, st.show_time) >= NOW()) AS upcoming
       FROM movies m
      WHERE m.status = 'now_showing'
      ORDER BY m.user_score DESC"
);

/* -- Coming soon ----------------------------------------------------- */
$comingSoon = db_all(
    "SELECT * FROM movies
      WHERE status = 'coming_soon'
      ORDER BY release_date"
);

/* -- The next few screenings starting today -------------------------- */
$nextUp = db_all(
    "SELECT v.*
       FROM v_showtime_availability v
      WHERE TIMESTAMP(v.show_date, v.show_time) >= NOW()
        AND v.status = 'open'
      ORDER BY v.show_date, v.show_time
      LIMIT 6"
);

/* -- Headline figures ------------------------------------------------ */
$stats = db_one(
    "SELECT
        (SELECT COUNT(*) FROM movies WHERE status = 'now_showing')  AS films,
        (SELECT COUNT(*) FROM cinemas)                              AS venues,
        (SELECT COUNT(*) FROM seats)                                AS seats,
        (SELECT COUNT(*) FROM showtimes
          WHERE TIMESTAMP(show_date, show_time) >= NOW())           AS screenings"
);

/* -- Venue list for the quick-book bar ------------------------------- */
$cinemas = db_all('SELECT cinema_id, name, location FROM cinemas ORDER BY name');

$page_title       = 'Cinema tickets, without the guesswork';
$page_description = 'Browse what is showing across three CineSphere venues, '
                  . 'short-list the screenings you would accept, and confirm '
                  . 'the ones that are still available.';

require __DIR__ . '/includes/header.php';
?>

<!-- ============================ HERO ============================== -->
<section class="hero">
  <div class="hero-bg">
    <img src="images/hero.svg" alt="" width="1600" height="560">
  </div>

  <div class="hero-beams" aria-hidden="true"><i></i><i></i></div>

  <div class="container hero-inner">
    <div class="hero-copy">
      <span class="eyebrow">Now booking &middot; <?= (int) $stats['screenings'] ?> screenings this week</span>
      <h1>Pick the seat you <em>actually</em> want.</h1>
      <p class="lede">
        CineSphere lets you short-list several screenings in the order you
        would accept them, then confirms whichever are still free when you
        are ready to pay. No more losing the row you wanted because you
        stopped to check the other hall.
      </p>
      <div class="btn-row">
        <a class="btn btn-primary btn-lg" href="movies.php">See what&rsquo;s on</a>
        <a class="btn btn-ghost btn-lg" href="showtimes.php">Full schedule</a>
      </div>
    </div>

    <!-- Showing featured films of the week -->
    <?php if ($featured): ?>
    <div class="hero-feature">
      <a class="hero-poster" href="movie.php?id=<?= (int) $featured['movie_id'] ?>">
        <img src="images/<?= e($featured['poster']) ?>"
             alt="Poster for <?= e($featured['title']) ?>" width="320" height="480">
        <span class="hero-score"><b><?= number_format((float) $featured['user_score'], 1) ?></b>/10</span>
      </a>
      <div class="hero-feature-text">
        <span class="eyebrow">Best reviewed this week</span>
        <h3><?= e($featured['title']) ?></h3>
        <p class="meta-row">
          <span class="rating"><?= e($featured['classification']) ?></span>
          <span><?= e(pretty_runtime((int) $featured['duration_min'])) ?></span>
          <span><?= e($featured['genre']) ?></span>
        </p>
        <p><?= e($featured['tagline']) ?></p>
        <a class="btn btn-primary btn-sm" href="movie.php?id=<?= (int) $featured['movie_id'] ?>">
          Times &amp; tickets
        </a>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="marquee-bulbs" aria-hidden="true"></div>
</section>


<!-- ====================== QUICK SEARCH BAR ======================== -->
<div class="container">
  <form class="quick-book" method="get" action="showtimes.php" data-autosubmit>
    <div class="field">
      <label for="q-film">Film</label>
      <select name="movie" id="q-film">
        <option value="0">Any film</option>
        <?php foreach ($nowShowing as $m): ?>
          <option value="<?= (int) $m['movie_id'] ?>"><?= e($m['title']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label for="q-venue">Venue</label>
      <select name="cinema" id="q-venue">
        <option value="0">All three venues</option>
        <?php foreach ($cinemas as $c): ?>
          <option value="<?= (int) $c['cinema_id'] ?>"><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label for="q-date">Date</label>
      <input type="date" name="date" id="q-date"
             value="<?= e(date('Y-m-d')) ?>"
             min="<?= e(date('Y-m-d')) ?>"
             max="<?= e(date('Y-m-d', strtotime('+6 days'))) ?>">
    </div>

    <div class="field">
      <label for="q-part">Time of day</label>
      <select name="part" id="q-part">
        <option value="">Any time</option>
        <option value="matinee">Matinee (before 5pm)</option>
        <option value="evening">Evening (5pm &ndash; 9pm)</option>
        <option value="late">Late (after 9pm)</option>
      </select>
    </div>

    <button type="submit" class="btn btn-primary">Find times</button>
  </form>
</div>


<!-- ========================= NOW SHOWING ========================== -->
<section class="section">
  <div class="container">
    <div class="section-head">
      <div>
        <span class="eyebrow">In cinemas</span>
        <h2>Now showing</h2>
      </div>
      <p><a href="movies.php">Browse all films &rarr;</a></p>
    </div>

    <div class="film-grid">
      <?php foreach ($nowShowing as $m): ?>
        <article class="film-card">
          <div class="film-poster">
            <img src="images/<?= e($m['poster']) ?>"
                 alt="Poster for <?= e($m['title']) ?>" width="480" height="720" loading="lazy">
            <span class="badge badge-gold poster-tag"><?= e($m['classification']) ?></span>
          </div>
          <div class="film-body">
            <h3><a href="movie.php?id=<?= (int) $m['movie_id'] ?>"><?= e($m['title']) ?></a></h3>
            <p class="film-genre"><?= e($m['genre']) ?> &middot; <?= e(pretty_runtime((int) $m['duration_min'])) ?></p>
            <p class="meta-row small">
              <span class="score"><?= number_format((float) $m['user_score'], 1) ?></span>
              <span class="muted"><?= (int) $m['upcoming'] ?> screenings left this week</span>
            </p>
            <div class="btn-row">
              <a class="btn btn-primary btn-sm" href="movie.php?id=<?= (int) $m['movie_id'] ?>">Book</a>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>


<!-- ========================== NEXT UP ============================= -->
<?php if ($nextUp): ?>
<section class="section section-sunken">
  <div class="container">
    <div class="section-head">
      <div>
        <span class="eyebrow">Starting soon</span>
        <h2>Next screenings</h2>
      </div>
      <p><a href="showtimes.php">Show this week &rarr;</a></p>
    </div>

    <div class="table-wrap">
      <table class="data">
        <caption>
          Six Screenings starting today
          <span class="muted">Availability is subject to time slot of each showing</span>
        </caption>
        <thead>
          <tr>
            <th scope="col">Film</th>
            <th scope="col">When</th>
            <th scope="col">Venue</th>
            <th scope="col">Hall</th>
            <th scope="col" class="num">Seats left</th>
            <th scope="col">Availability</th>
            <th scope="col"><span class="sr-only">Book</span></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($nextUp as $s):
              $left = (int) $s['total_seats'] - (int) $s['seats_taken'];
              [$lblText, $lblClass] = availability_label($left, (int) $s['total_seats']); ?>
          <tr>
            <td>
              <div class="table-film">
                <img src="images/<?= e($s['poster']) ?>" alt="" width="42" height="63" loading="lazy">
                <div>
                  <strong><?= e($s['title']) ?></strong>
                  <span><?= e($s['classification']) ?> &middot; <?= e($s['format']) ?></span>
                </div>
              </div>
            </td>
            <td class="nowrap">
              <?= e(pretty_time($s['show_time'])) ?><br>
              <span class="small muted"><?= e(pretty_date($s['show_date'])) ?></span>
            </td>
            <td><?= e($s['cinema_name']) ?><br><span class="small muted"><?= e($s['cinema_location']) ?></span></td>
            <td><?= e($s['hall_name']) ?></td>
            <td class="num"><?= $left ?></td>
            <td><span class="badge badge-<?= e($lblClass) ?>"><?= e($lblText) ?></span></td>
            <td>
              <?php if ($left > 0): ?>
                <a class="btn btn-ghost btn-sm" href="booking.php?showtime=<?= (int) $s['showtime_id'] ?>">Choose seats</a>
              <?php else: ?>
                <span class="faint small">Sold out</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>
<?php endif; ?>


<!-- How booking works here -->
<section class="section">
  <div class="container">
    <div class="section-head">
      <div>
        <span class="eyebrow">Three steps</span>
        <h2>How booking works here</h2>
      </div>
    </div>

    <div class="step-grid">
      <div class="step">
        <img src="images/step-browse.svg" alt="" width="74" height="74">
        <h3>1. Find your screening</h3>
        <p>
          Filter the week&rsquo;s schedule by film, venue, date or time of
          day. Every row shows how many seats are genuinely left.
        </p>
      </div>
      <div class="step">
        <img src="images/step-seats.svg" alt="" width="74" height="74">
        <h3>2. Build a short-list</h3>
        <p>
          Choose seats and add the screening to your short-list. Add as
          many alternatives as you like and rank them in the order you
          would accept them.
        </p>
      </div>
      <div class="step">
        <img src="images/step-confirm.svg" alt="" width="74" height="74">
        <h3>3. Confirm what survived</h3>
        <p>
          When you are ready, we re-check every entry, show you which are
          still free, and you confirm one or more. The acknowledgement
          arrives by email.
        </p>
      </div>
    </div>
  </div>
</section>


<!-- ========================== THE NUMBERS ========================= -->
<section class="section-tight">
  <div class="container">
    <div class="stat-strip">
      <div class="stat"><span class="n"><?= (int) $stats['films'] ?></span><span class="l">Films on release</span></div>
      <div class="stat"><span class="n"><?= (int) $stats['venues'] ?></span><span class="l">Venues</span></div>
      <div class="stat"><span class="n"><?= number_format((int) $stats['seats']) ?></span><span class="l">Seats</span></div>
      <div class="stat"><span class="n"><?= (int) $stats['screenings'] ?></span><span class="l">Screenings ahead</span></div>
    </div>
  </div>
</section>


<!-- ========================= COMING SOON ========================== -->
<?php if ($comingSoon): ?>
<section class="section section-sunken">
  <div class="container">
    <div class="section-head">
      <div>
        <span class="eyebrow">On the way</span>
        <h2>Coming soon</h2>
      </div>
      <p class="muted">Booking opens two weeks before release.</p>
    </div>

    <div class="grid grid-2">
      <?php foreach ($comingSoon as $m): ?>
        <article class="card" style="display:flex;gap:1.2rem;align-items:flex-start;">
          <img src="images/<?= e($m['poster']) ?>"
               alt="Poster for <?= e($m['title']) ?>"
               width="110" height="165" loading="lazy"
               style="width:110px;border-radius:8px;flex:0 0 auto;">
          <div>
            <span class="badge badge-teal">In cinemas <?= e(date('j M Y', strtotime($m['release_date']))) ?></span>
            <h3 style="margin-top:.6rem;"><?= e($m['title']) ?></h3>
            <p class="small muted"><?= e($m['genre']) ?> &middot; <?= e(pretty_runtime((int) $m['duration_min'])) ?> &middot; <?= e($m['classification']) ?></p>
            <p class="small"><?= e($m['tagline']) ?></p>
            <a class="btn btn-ghost btn-sm" href="movie.php?id=<?= (int) $m['movie_id'] ?>">Read more</a>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>


<!-- =========================== VENUES ============================= -->
<section class="section">
  <div class="container">
    <div class="grid grid-2" style="align-items:center;gap:2.5rem;">
      <div>
        <span class="eyebrow">Where to find us</span>
        <h2>Three halls, one account</h2>
        <p class="lede">
          Orchard for the IMAX screen, Jewel for the reclining Premiere
          seats, Harbourfront for the late shows. Your short-list, your
          bookings and your history follow you across all three.
        </p>
        <dl class="spec-list">
          <?php foreach ($cinemas as $c):
              $halls = db_all('SELECT hall_name, hall_type FROM halls WHERE cinema_id = ? ORDER BY hall_id',
                              [(int) $c['cinema_id']]); ?>
            <div>
              <dt><?= e($c['location']) ?></dt>
              <dd><?= e($c['name']) ?></dd>
              <dd class="small muted"><?= implode(' &middot; ', array_map('e', array_column($halls, 'hall_name'))) ?></dd>
            </div>
          <?php endforeach; ?>
        </dl>
        <a class="btn btn-ghost" href="contact.php">Addresses &amp; contact</a>
      </div>
      <div>
        <img src="images/venue.svg" alt="Illustration of a CineSphere venue frontage"
             width="760" height="440" loading="lazy"
             style="border-radius:var(--radius);border:1px solid var(--line);">
      </div>
    </div>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
