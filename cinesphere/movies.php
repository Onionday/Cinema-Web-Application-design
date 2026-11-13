<?php
/* =====================================================================
 * movies.php - browse the catalogue
 *
 * Demonstrates server-side processing of GET input: the keyword, genre,
 * classification, status and sort controls are all validated in PHP and
 * folded into one prepared SELECT.
 * ===================================================================== */

require_once __DIR__ . '/includes/auth.php';

require __DIR__ . '/includes/catalogue.php';
$genres = all_genres();
$page_scripts = ['movies.js'];

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
    <p class="lede" id="catalogue-summary"><?= e($summary) ?></p>
  </div>
</section>

<div class="container">

  <!-- ===================== FILTERS (GET form) ==================== -->
  <form class="filter-bar" method="get" action="movies.php" id="movie-filters">

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
    <a class="btn btn-ghost" href="movies.php" data-search-reset>Reset</a>
  </form>


  <!-- ========================= RESULTS =========================== -->
  <div class="search-feedback">
    <p id="search-status" role="status" aria-live="polite" aria-atomic="true"></p>
    <button type="button" id="search-retry" class="btn btn-ghost btn-sm" hidden>Retry search</button>
  </div>
  <div id="movie-results" aria-busy="false" aria-label="Film search results">
    <?php require __DIR__ . '/includes/catalogue_results.php'; ?>
  </div>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
