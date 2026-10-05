<?php
/* =====================================================================
 * showtimes.php - the full weekly schedule
 *
 * This is the project's main table display. Every row is generated on
 * the server from a joined SELECT against the availability view, and the
 * filter controls above it are processed server-side.
 * ===================================================================== */

require_once __DIR__ . '/includes/auth.php';

/* ---- Filters ------------------------------------------------------- */
$movie_id  = (int) ($_GET['movie']  ?? 0);
$cinema_id = (int) ($_GET['cinema'] ?? 0);
$date      = trim((string) ($_GET['date'] ?? ''));
$part      = trim((string) ($_GET['part'] ?? ''));
$format    = trim((string) ($_GET['format'] ?? ''));

/* A date is only accepted if it is a real date inside the week we sell. */
$firstDay = date('Y-m-d');
$lastDay  = date('Y-m-d', strtotime('+6 days'));

if ($date !== '') {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date || $date < $firstDay || $date > $lastDay) {
        flash('warning', 'That date is outside the week we are currently selling, so it has been ignored.');
        $date = '';
    }
}

if (!in_array($part, ['', 'matinee', 'evening', 'late'], true)) {
    $part = '';
}
if (!in_array($format, ['', '2D', '3D', 'IMAX'], true)) {
    $format = '';
}

/* ---- Query --------------------------------------------------------- */
$where  = ["v.status = 'open'", 'TIMESTAMP(v.show_date, v.show_time) >= NOW()'];
$params = [];

if ($movie_id > 0)  { $where[] = 'v.movie_id = ?';  $params[] = $movie_id; }
if ($cinema_id > 0) { $where[] = 'v.cinema_id = ?'; $params[] = $cinema_id; }
if ($date !== '')   { $where[] = 'v.show_date = ?'; $params[] = $date; }
if ($format !== '') { $where[] = 'v.format = ?';    $params[] = $format; }

if ($part === 'matinee') {
    $where[] = 'v.show_time < ?';
    $params[] = '17:00:00';
} elseif ($part === 'evening') {
    $where[] = 'v.show_time >= ? AND v.show_time < ?';
    $params[] = '17:00:00';
    $params[] = '21:00:00';
} elseif ($part === 'late') {
    $where[] = 'v.show_time >= ?';
    $params[] = '21:00:00';
}

$screenings = db_all(
    'SELECT v.* FROM v_showtime_availability v
      WHERE ' . implode(' AND ', $where) . '
      ORDER BY v.show_date, v.show_time, v.cinema_name, v.hall_name',
    $params
);

/* ---- Group by day so the table can carry a heading row per date ---- */
$byDate = [];
foreach ($screenings as $s) {
    $byDate[$s['show_date']][] = $s;
}

/* ---- Dropdown contents --------------------------------------------- */
$films   = db_all("SELECT movie_id, title FROM movies WHERE status = 'now_showing' ORDER BY title");
$cinemas = db_all('SELECT cinema_id, name, location FROM cinemas ORDER BY name');

/* ---- A few figures for the caption ---------------------------------- */
$totalSeatsLeft = 0;
foreach ($screenings as $s) {
    $totalSeatsLeft += max(0, (int) $s['total_seats'] - (int) $s['seats_taken']);
}

$filtered = ($movie_id > 0 || $cinema_id > 0 || $date !== '' || $part !== '' || $format !== '');

$page_title       = 'Schedule';
$page_description = 'The complete CineSphere screening schedule for the week '
                  . 'ahead, with live seat availability for every show.';

require __DIR__ . '/includes/header.php';
?>

<div class="container">
  <p class="breadcrumb"><a href="index.php">Home</a><span>/</span>Schedule</p>
</div>

<section class="section-tight">
  <div class="container">
    <span class="eyebrow">Seven days ahead</span>
    <h1>The full schedule</h1>
    <p class="lede">
      Every screening still open for booking, across all three venues.
      Seat counts come straight from the bookings table, so what you see
      here is what is genuinely left.
    </p>
  </div>
</section>

<div class="container">

  <!-- ======================== FILTER BAR ========================= -->
  <form class="filter-bar" method="get" action="showtimes.php" data-autosubmit>

    <div class="field">
      <label for="s-film">Film</label>
      <select name="movie" id="s-film">
        <option value="0">Any film</option>
        <?php foreach ($films as $f): ?>
          <option value="<?= (int) $f['movie_id'] ?>" <?= $movie_id === (int) $f['movie_id'] ? 'selected' : '' ?>>
            <?= e($f['title']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label for="s-venue">Venue</label>
      <select name="cinema" id="s-venue">
        <option value="0">All venues</option>
        <?php foreach ($cinemas as $c): ?>
          <option value="<?= (int) $c['cinema_id'] ?>" <?= $cinema_id === (int) $c['cinema_id'] ? 'selected' : '' ?>>
            <?= e($c['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label for="s-date">Date</label>
      <input type="date" name="date" id="s-date" value="<?= e($date) ?>"
             min="<?= e($firstDay) ?>" max="<?= e($lastDay) ?>">
    </div>

    <div class="field">
      <label for="s-part">Time of day</label>
      <select name="part" id="s-part">
        <option value=""        <?= $part === ''        ? 'selected' : '' ?>>Any time</option>
        <option value="matinee" <?= $part === 'matinee' ? 'selected' : '' ?>>Matinee (before 5pm)</option>
        <option value="evening" <?= $part === 'evening' ? 'selected' : '' ?>>Evening (5&ndash;9pm)</option>
        <option value="late"    <?= $part === 'late'    ? 'selected' : '' ?>>Late (after 9pm)</option>
      </select>
    </div>

    <div class="field">
      <label for="s-format">Format</label>
      <select name="format" id="s-format">
        <option value=""     <?= $format === ''     ? 'selected' : '' ?>>Any format</option>
        <option value="2D"   <?= $format === '2D'   ? 'selected' : '' ?>>2D</option>
        <option value="3D"   <?= $format === '3D'   ? 'selected' : '' ?>>3D</option>
        <option value="IMAX" <?= $format === 'IMAX' ? 'selected' : '' ?>>IMAX</option>
      </select>
    </div>

    <button type="submit" class="btn btn-primary">Apply</button>
    <?php if ($filtered): ?>
      <a class="btn btn-ghost" href="showtimes.php">Reset</a>
    <?php endif; ?>
  </form>


  <!-- ========================== TABLE ============================ -->
  <?php if (!$screenings): ?>

    <div class="empty-state" style="margin-bottom:3rem;">
      <img src="images/step-browse.svg" alt="" width="74" height="74">
      <h2>No screenings match those filters</h2>
      <p class="muted">Try another date, or widen the search to all venues.</p>
      <a class="btn btn-primary" href="showtimes.php">Show the whole week</a>
    </div>

  <?php else: ?>

    <div class="table-wrap" style="margin-bottom:1.2rem;">
      <table class="data">
        <caption>
          Screening schedule
          <span class="muted">
            <?= count($screenings) ?> screenings over
            <?= count($byDate) ?> <?= count($byDate) === 1 ? 'day' : 'days' ?>,
            <?= number_format($totalSeatsLeft) ?> seats still available.
            Weekend screenings carry a <?= e(money(WEEKEND_SURCHARGE)) ?> surcharge.
          </span>
        </caption>

        <thead>
          <tr>
            <th scope="col">Time</th>
            <th scope="col">Film</th>
            <th scope="col">Venue</th>
            <th scope="col">Hall</th>
            <th scope="col">Format</th>
            <th scope="col" class="num">From</th>
            <th scope="col" class="num">Seats left</th>
            <th scope="col">Availability</th>
            <th scope="col"><span class="sr-only">Action</span></th>
          </tr>
        </thead>

        <tbody>
        <?php foreach ($byDate as $day => $list): ?>

          <tr>
            <th colspan="9" scope="colgroup" class="day-heading">
              <?= e(pretty_date($day)) ?>
              <?php if ($day === date('Y-m-d')): ?>&nbsp;&mdash;&nbsp;Today<?php endif; ?>
              <?php if ((int) date('N', strtotime($day)) >= 6): ?>
                &nbsp;&mdash;&nbsp;weekend pricing
              <?php endif; ?>
            </th>
          </tr>

          <?php foreach ($list as $s):
              $left = max(0, (int) $s['total_seats'] - (int) $s['seats_taken']);
              [$lblText, $lblClass] = availability_label($left, (int) $s['total_seats']); ?>
          <tr>
            <td class="nowrap"><strong><?= e(pretty_time($s['show_time'])) ?></strong></td>

            <td>
              <div class="table-film">
                <img src="images/<?= e($s['poster']) ?>" alt="" width="42" height="63" loading="lazy">
                <div>
                  <strong><a href="movie.php?id=<?= (int) $s['movie_id'] ?>"><?= e($s['title']) ?></a></strong>
                  <span><?= e($s['classification']) ?> &middot; <?= e(pretty_runtime((int) $s['duration_min'])) ?></span>
                </div>
              </div>
            </td>

            <td class="nowrap"><?= e($s['cinema_location']) ?></td>

            <td class="nowrap">
              <?= e($s['hall_name']) ?><br>
              <span class="small muted"><?= e($s['hall_type']) ?></span>
            </td>

            <td><span class="badge"><?= e($s['format']) ?></span></td>

            <td class="num"><?= e(money(showtime_price($s))) ?></td>

            <td class="num"><?= $left ?> <span class="faint">/ <?= (int) $s['total_seats'] ?></span></td>

            <td><span class="badge badge-<?= e($lblClass) ?>"><?= e($lblText) ?></span></td>

            <td class="nowrap">
              <?php if ($left > 0): ?>
                <a class="btn btn-primary btn-sm" href="booking.php?showtime=<?= (int) $s['showtime_id'] ?>">
                  Choose seats
                </a>
              <?php else: ?>
                <span class="faint small">&mdash;</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>

        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <p class="small muted" style="margin-bottom:3rem;">
      Prices shown are the cheapest seat at that screening. Premium rows add
      <?= e(money(4.00)) ?> and couch seats add <?= e(money(9.00)) ?> per person;
      a booking fee of <?= e(money(BOOKING_FEE)) ?> is charged per ticket at
      checkout.
    </p>

  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
