</main>

<footer class="site-footer">
  <div class="container footer-grid">

    <div class="footer-col">
      <div class="brand brand-footer">
        <img src="images/logo.svg" alt="" width="34" height="34">
        <strong><?= e(SITE_NAME) ?></strong>
      </div>
      <p class="muted">
        Three halls across Singapore, one short-list, and a seat map that
        tells you the truth about what is left.
      </p>
    </div>

    <div class="footer-col">
      <h3>Booking</h3>
      <ul class="plain">
        <li><a href="movies.php">What&rsquo;s on</a></li>
        <li><a href="showtimes.php">Full schedule</a></li>
        <li><a href="preferences.php">Your short-list</a></li>
        <li><a href="mybookings.php">My bookings</a></li>
      </ul>
    </div>

    <div class="footer-col">
      <h3>Venues</h3>
      <ul class="plain">
<?php
require_once __DIR__ . '/functions.php';
foreach (db_all('SELECT name, location FROM cinemas ORDER BY cinema_id') as $c): ?>
        <li><?= e($c['name']) ?> <span class="muted">&middot; <?= e($c['location']) ?></span></li>
<?php endforeach; ?>
      </ul>
    </div>

    <div class="footer-col">
      <h3>Help</h3>
      <ul class="plain">
        <li><a href="contact.php">Contact &amp; feedback</a></li>
        <li><a href="account.php">Sign in or register</a></li>
        <li class="muted">Box office: +65 6100 2000</li>
        <li class="muted">Daily 10:00 &ndash; 01:00</li>
      </ul>
    </div>

  </div>

  <div class="container footer-base">
    <p class="muted">
      &copy; <?= date('Y') ?> <?= e(SITE_NAME) ?>. A student project built for
      IE4727 Web Application Design. All films, artwork and venues are fictional.
    </p>
    <p class="muted">
      Payment is handled by a third-party gateway; no card details are
      collected or stored by this site.
    </p>
  </div>
</footer>

<script src="js/main.js"></script>
<?php foreach (($page_scripts ?? []) as $s): ?>
<script src="js/<?= e($s) ?>"></script>
<?php endforeach; ?>
</body>
</html>
