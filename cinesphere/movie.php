<?php
/* =====================================================================
 * movie.php - one film, its details and its schedule
 *
 * The schedule is grouped date -> venue -> time entirely in PHP from a
 * single joined SELECT, and each time chip carries live availability
 * worked out from the bookings table.
 * ===================================================================== */

require_once __DIR__ . '/includes/auth.php';

$movie_id = (int) ($_GET['id'] ?? 0);

$movie = $movie_id > 0
    ? db_one('SELECT * FROM movies WHERE movie_id = ?', [$movie_id])
    : null;

if (!$movie) {
    http_response_code(404);
    $page_title = 'Film not found';
    require __DIR__ . '/includes/header.php';
    echo '<div class="container section"><div class="empty-state">'
       . '<h1>We could not find that film</h1>'
       . '<p class="muted">It may have finished its run, or the link may be wrong.</p>'
       . '<a class="btn btn-primary" href="movies.php">Browse all films</a>'
       . '</div></div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

/* ---- Every upcoming screening of this film ------------------------ */
$rows = db_all(
    "SELECT v.*
       FROM v_showtime_availability v
      WHERE v.movie_id = ?
        AND v.status = 'open'
        AND TIMESTAMP(v.show_date, v.show_time) >= NOW()
      ORDER BY v.show_date, v.cinema_name, v.show_time",
    [$movie_id]
);

/* ---- Group: date -> cinema -> list of screenings ------------------ */
$byDate = [];
foreach ($rows as $r) {
    $byDate[$r['show_date']][$r['cinema_name'] . '|' . $r['cinema_location']][] = $r;
}

/* ---- Other films the visitor might like --------------------------- */
$firstGenre = trim(explode(',', $movie['genre'])[0]);
$alsoLike = db_all(
    "SELECT movie_id, title, poster, classification, genre, duration_min, user_score
       FROM movies
      WHERE movie_id <> ? AND status = 'now_showing' AND genre LIKE ?
      ORDER BY user_score DESC
      LIMIT 4",
    [$movie_id, '%' . $firstGenre . '%']
);

if (!$alsoLike) {
    $alsoLike = db_all(
        "SELECT movie_id, title, poster, classification, genre, duration_min, user_score
           FROM movies
          WHERE movie_id <> ? AND status = 'now_showing'
          ORDER BY user_score DESC LIMIT 4",
        [$movie_id]
    );
}

$banner = $movie['banner'] !== '' ? $movie['banner'] : $movie['poster'];

$page_title       = $movie['title'];
$page_description = mb_substr(strip_tags($movie['synopsis']), 0, 155);

require __DIR__ . '/includes/header.php';
?>

<!-- ======================== FILM HEADER ========================== -->
<section class="film-hero">
  <div class="film-hero-bg">
    <img src="images/<?= e($banner) ?>" alt="" width="1600" height="620">
  </div>

  <div class="container">
    <p class="breadcrumb">
      <a href="index.php">Home</a><span>/</span>
      <a href="movies.php">Films</a><span>/</span><?= e($movie['title']) ?>
    </p>

    <div class="film-hero-inner">
      <img src="images/<?= e($movie['poster']) ?>"
           alt="Poster for <?= e($movie['title']) ?>" width="480" height="720">

      <div>
        <span class="eyebrow">
          <?= $movie['status'] === 'coming_soon' ? 'Coming soon' : 'Now showing' ?>
        </span>
        <h1><?= e($movie['title']) ?></h1>
        <p class="lede" style="font-style:italic;"><?= e($movie['tagline']) ?></p>

        <p class="meta-row">
          <span class="rating"><?= e($movie['classification']) ?></span>
          <span><?= e(pretty_runtime((int) $movie['duration_min'])) ?></span>
          <span><?= e($movie['genre']) ?></span>
          <?php if ((float) $movie['user_score'] > 0): ?>
            <span class="score"><?= number_format((float) $movie['user_score'], 1) ?>/10</span>
          <?php endif; ?>
        </p>

        <p><?= e($movie['synopsis']) ?></p>

        <dl class="spec-list">
          <div><dt>Director</dt><dd><?= e($movie['director']) ?></dd></div>
          <div><dt>Cast</dt><dd><?= e($movie['cast_list']) ?></dd></div>
          <div><dt>Language</dt><dd><?= e($movie['language']) ?></dd></div>
          <div><dt>Released</dt><dd><?= e(date('j M Y', strtotime($movie['release_date']))) ?></dd></div>
        </dl>

        <div class="btn-row">
          <?php if ($rows): ?>
            <a class="btn btn-primary btn-lg" href="#schedule">Choose a screening</a>
          <?php endif; ?>
          <a class="btn btn-ghost btn-lg" href="showtimes.php?movie=<?= (int) $movie['movie_id'] ?>">
            See it in the full schedule
          </a>
        </div>
      </div>
    </div>
  </div>
</section>


<!-- ========================== SCHEDULE =========================== -->
<section class="section" id="schedule">
  <div class="container">
    <div class="section-head">
      <div>
        <span class="eyebrow">Tickets</span>
        <h2>Screenings this week</h2>
      </div>
      <?php if ($rows): ?>
        <p class="muted"><?= count($rows) ?> screenings across our three venues</p>
      <?php endif; ?>
    </div>

    <?php if (!$rows): ?>

      <div class="empty-state">
        <h3><?= $movie['status'] === 'coming_soon'
              ? 'Booking has not opened yet'
              : 'No screenings left this week' ?></h3>
        <p class="muted">
          <?php if ($movie['status'] === 'coming_soon'): ?>
            <?= e($movie['title']) ?> opens on
            <?= e(date('j F Y', strtotime($movie['release_date']))) ?>.
            Tickets go on sale two weeks beforehand.
          <?php else: ?>
            Every screening of this film in the next seven days has already
            started or sold out.
          <?php endif; ?>
        </p>
        <a class="btn btn-primary" href="movies.php">Find something else</a>
      </div>

    <?php else: ?>

      <?php foreach ($byDate as $date => $byVenue): ?>
        <div class="showtime-day">
          <h3>
            <?= e(pretty_date($date)) ?>
            <?php if ($date === date('Y-m-d')): ?>
              <span class="badge badge-gold">Today</span>
            <?php elseif ($date === date('Y-m-d', strtotime('+1 day'))): ?>
              <span class="badge">Tomorrow</span>
            <?php endif; ?>
          </h3>

          <?php foreach ($byVenue as $venueKey => $screenings):
              [$vName, $vLoc] = explode('|', $venueKey); ?>
            <div class="venue-block">
              <h4><?= e($vName) ?> &middot; <?= e($vLoc) ?></h4>

              <div class="time-row">
                <?php foreach ($screenings as $s):
                    $left  = (int) $s['total_seats'] - (int) $s['seats_taken'];
                    $full  = ($left <= 0);
                    [$lblText, $lblClass] = availability_label($left, (int) $s['total_seats']);
                    $price = showtime_price($s); ?>
                  <a class="time-chip<?= $full ? ' is-full' : '' ?>"
                     href="booking.php?showtime=<?= (int) $s['showtime_id'] ?>"
                     <?= $full ? 'aria-disabled="true" tabindex="-1"' : '' ?>
                     title="<?= e($s['hall_name']) ?> &middot; from <?= e(money($price)) ?>">
                    <span class="t"><?= e(pretty_time($s['show_time'])) ?></span>
                    <span class="f"><?= e($s['format']) ?> &middot; <?= e($s['hall_type']) ?></span>
                    <span class="s">
                      <?php if ($full): ?>
                        <span class="badge badge-sold">Sold out</span>
                      <?php else: ?>
                        <span class="badge badge-<?= e($lblClass) ?>"><?= $left ?> left</span>
                      <?php endif; ?>
                    </span>
                  </a>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>

      <p class="small muted">
        Prices start at <?= e(money(min(array_map('showtime_price', $rows)))) ?> per
        ticket. Premium rows and couch seats carry a surcharge, shown on the
        seat map. A booking fee of <?= e(money(BOOKING_FEE)) ?> applies per ticket.
      </p>

    <?php endif; ?>
  </div>
</section>


<!-- ========================= ALSO SHOWING ======================== -->
<?php if ($alsoLike): ?>
<section class="section section-sunken">
  <div class="container">
    <div class="section-head">
      <div>
        <span class="eyebrow">If you liked the sound of that</span>
        <h2>Also showing</h2>
      </div>
    </div>

    <div class="film-grid">
      <?php foreach ($alsoLike as $m): ?>
        <article class="film-card">
          <div class="film-poster">
            <img src="images/<?= e($m['poster']) ?>" alt="Poster for <?= e($m['title']) ?>"
                 width="480" height="720" loading="lazy">
          </div>
          <div class="film-body">
            <h3><a href="movie.php?id=<?= (int) $m['movie_id'] ?>"><?= e($m['title']) ?></a></h3>
            <p class="film-genre"><?= e($m['genre']) ?> &middot; <?= e(pretty_runtime((int) $m['duration_min'])) ?></p>
            <div class="btn-row">
              <a class="btn btn-ghost btn-sm" href="movie.php?id=<?= (int) $m['movie_id'] ?>">Details</a>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
