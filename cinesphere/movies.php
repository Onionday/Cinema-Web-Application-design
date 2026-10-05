<?php
/* =====================================================================
 * movies.php - browse the catalogue
 *
 * Demonstrates server-side processing of GET input: the keyword, genre,
 * classification, status and sort controls are all validated in PHP and
 * folded into one prepared SELECT.
 * ===================================================================== */

require_once __DIR__ . '/includes/auth.php';

/* ---- Read and sanitise the filters -------------------------------- */
$q      = trim((string) ($_GET['q']      ?? ''));
$genre  = trim((string) ($_GET['genre']  ?? ''));
$rating = trim((string) ($_GET['rating'] ?? ''));
$status = trim((string) ($_GET['status'] ?? 'now_showing'));
$sort   = trim((string) ($_GET['sort']   ?? 'score'));

$validStatus = ['now_showing', 'coming_soon', 'all'];
if (!in_array($status, $validStatus, true)) {
    $status = 'now_showing';
}

$validRating = ['G', 'PG', 'PG13', 'NC16', 'M18'];
if ($rating !== '' && !in_array($rating, $validRating, true)) {
    $rating = '';
}

$sortMap = [
    'score'   => 'm.user_score DESC, m.title',
    'title'   => 'm.title',
    'newest'  => 'm.release_date DESC, m.title',
    'runtime' => 'm.duration_min, m.title',
];
if (!isset($sortMap[$sort])) {
    $sort = 'score';
}

/* ---- Build the query ---------------------------------------------- */
$where  = ["m.status <> 'archived'"];
$params = [];

if ($status !== 'all') {
    $where[]  = 'm.status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $where[]  = '(m.title LIKE ? OR m.synopsis LIKE ? OR m.cast_list LIKE ? OR m.director LIKE ?)';
    $like     = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($genre !== '') {
    $where[]  = 'm.genre LIKE ?';
    $params[] = '%' . $genre . '%';
}
if ($rating !== '') {
    $where[]  = 'm.classification = ?';
    $params[] = $rating;
}

$sql = 'SELECT m.*,
               (SELECT COUNT(*)
                  FROM showtimes st
                 WHERE st.movie_id = m.movie_id
                   AND st.status = ' . "'open'" . '
                   AND TIMESTAMP(st.show_date, st.show_time) >= NOW()) AS upcoming,
               (SELECT MIN(st.base_price)
                  FROM showtimes st
                 WHERE st.movie_id = m.movie_id
                   AND TIMESTAMP(st.show_date, st.show_time) >= NOW()) AS from_price
          FROM movies m
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY ' . $sortMap[$sort];

$films  = db_all($sql, $params);
$genres = all_genres();

/* Does the current filter set differ from the default? */
$filtered = ($q !== '' || $genre !== '' || $rating !== ''
             || $status !== 'now_showing' || $sort !== 'score');

$page_title       = 'Films';
$page_description = 'Every film on release and on the way at CineSphere, '
                  . 'with runtimes, classifications and the number of '
                  . 'screenings left this week.';

require __DIR__ . '/includes/header.php';
?>

<div class="container">
  <p class="breadcrumb"><a href="index.php">Home</a><span>/</span>Films</p>
</div>

<section class="section-tight">
  <div class="container">
    <span class="eyebrow">The catalogue</span>
    <h1>Films at CineSphere</h1>
    <p class="lede">
      <?= count($films) ?> <?= count($films) === 1 ? 'title' : 'titles' ?>
      <?php if ($status === 'now_showing'): ?>on release right now<?php
        elseif ($status === 'coming_soon'): ?>opening in the next few months<?php
        else: ?>in the catalogue<?php endif; ?>.
      Pick one to see its schedule and choose seats.
    </p>
  </div>
</section>

<div class="container">

  <!-- ===================== FILTERS (GET form) ==================== -->
  <form class="filter-bar" method="get" action="movies.php" data-autosubmit>

    <div class="field" style="flex:2 1 260px;">
      <label for="f-q">Search</label>
      <input type="search" name="q" id="f-q" value="<?= e($q) ?>"
             placeholder="Title, director or cast" maxlength="60">
    </div>

    <div class="field">
      <label for="f-status">Showing</label>
      <select name="status" id="f-status">
        <option value="now_showing" <?= $status === 'now_showing' ? 'selected' : '' ?>>Now showing</option>
        <option value="coming_soon" <?= $status === 'coming_soon' ? 'selected' : '' ?>>Coming soon</option>
        <option value="all"         <?= $status === 'all'         ? 'selected' : '' ?>>Everything</option>
      </select>
    </div>

    <div class="field">
      <label for="f-genre">Genre</label>
      <select name="genre" id="f-genre">
        <option value="">All genres</option>
        <?php foreach ($genres as $g): ?>
          <option value="<?= e($g) ?>" <?= $genre === $g ? 'selected' : '' ?>><?= e($g) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label for="f-rating">Rating</label>
      <select name="rating" id="f-rating">
        <option value="">Any rating</option>
        <?php foreach ($validRating as $r): ?>
          <option value="<?= e($r) ?>" <?= $rating === $r ? 'selected' : '' ?>><?= e($r) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label for="f-sort">Order by</label>
      <select name="sort" id="f-sort">
        <option value="score"   <?= $sort === 'score'   ? 'selected' : '' ?>>Best reviewed</option>
        <option value="title"   <?= $sort === 'title'   ? 'selected' : '' ?>>Title A&ndash;Z</option>
        <option value="newest"  <?= $sort === 'newest'  ? 'selected' : '' ?>>Newest release</option>
        <option value="runtime" <?= $sort === 'runtime' ? 'selected' : '' ?>>Shortest first</option>
      </select>
    </div>

    <button type="submit" class="btn btn-primary">Apply</button>
    <?php if ($filtered): ?>
      <a class="btn btn-ghost" href="movies.php">Reset</a>
    <?php endif; ?>
  </form>


  <!-- ========================= RESULTS =========================== -->
  <?php if (!$films): ?>

    <div class="empty-state">
      <img src="images/step-browse.svg" alt="" width="74" height="74">
      <h2>Nothing matched that</h2>
      <p class="muted">
        No film matches
        <?php if ($q !== ''): ?>&ldquo;<?= e($q) ?>&rdquo;<?php endif; ?>
        with those filters. Try widening the search.
      </p>
      <a class="btn btn-primary" href="movies.php">Show everything on release</a>
    </div>

  <?php else: ?>

    <div class="film-grid" style="margin-bottom:3rem;">
      <?php foreach ($films as $m):
          $soon = ($m['status'] === 'coming_soon'); ?>
        <article class="film-card">
          <div class="film-poster">
            <img src="images/<?= e($m['poster']) ?>"
                 alt="Poster for <?= e($m['title']) ?>"
                 width="480" height="720" loading="lazy">
            <span class="badge <?= $soon ? 'badge-teal' : 'badge-gold' ?> poster-tag">
              <?= $soon ? 'Coming soon' : e($m['classification']) ?>
            </span>
          </div>

          <div class="film-body">
            <h3><a href="movie.php?id=<?= (int) $m['movie_id'] ?>"><?= e($m['title']) ?></a></h3>
            <p class="film-genre">
              <?= e($m['genre']) ?> &middot; <?= e(pretty_runtime((int) $m['duration_min'])) ?>
            </p>

            <p class="meta-row small">
              <?php if ((float) $m['user_score'] > 0): ?>
                <span class="score"><?= number_format((float) $m['user_score'], 1) ?></span>
              <?php endif; ?>
              <span class="rating"><?= e($m['classification']) ?></span>
              <span class="muted"><?= e($m['language']) ?></span>
            </p>

            <p class="small muted" style="margin-bottom:.4rem;">
              <?php if ($soon): ?>
                In cinemas <?= e(date('j M Y', strtotime($m['release_date']))) ?>
              <?php elseif ((int) $m['upcoming'] > 0): ?>
                <?= (int) $m['upcoming'] ?> screenings left &middot; from
                <?= e(money($m['from_price'])) ?>
              <?php else: ?>
                No screenings left this week
              <?php endif; ?>
            </p>

            <div class="btn-row">
              <?php if ($soon || (int) $m['upcoming'] === 0): ?>
                <a class="btn btn-ghost btn-sm" href="movie.php?id=<?= (int) $m['movie_id'] ?>">Details</a>
              <?php else: ?>
                <a class="btn btn-primary btn-sm" href="movie.php?id=<?= (int) $m['movie_id'] ?>">Times &amp; tickets</a>
              <?php endif; ?>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>

  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
