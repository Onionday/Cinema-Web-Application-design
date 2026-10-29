  <?php if (!$films): ?>

    <div class="empty-state">
      <img src="images/step-browse.svg" alt="" width="74" height="74">
      <h2>Nothing matched that</h2>
      <p class="muted">
        No film matches
        <?php if ($q !== ''): ?>&ldquo;<?= e($q) ?>&rdquo;<?php endif; ?>
        with those filters. Try widening the search.
      </p>
      <a class="btn btn-primary" href="movies.php" data-search-reset>Show everything on release</a>
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
