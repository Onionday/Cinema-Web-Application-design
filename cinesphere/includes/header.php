<?php
/* =====================================================================
 * includes/header.php
 *
 * The top of every page. A page sets these before requiring it:
 *   $page_title       - goes in <title> and is required
 *   $page_description - meta description
 *   $page_scripts     - array of extra .js files to load before </body>
 * ===================================================================== */

require_once __DIR__ . '/auth.php';

$page_title       = $page_title       ?? 'Cinema tickets';
$page_description = $page_description ?? SITE_TAGLINE;
$page_scripts     = $page_scripts     ?? [];

$me         = current_user();
$prefCount  = $me ? preference_count((int) $me['user_id']) : 0;
$flashes    = take_flashes();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="<?= e($page_description) ?>">
<meta name="author" content="IE4727 Web Application Design project">
<title><?= e($page_title) ?> &middot; <?= e(SITE_NAME) ?></title>
<link rel="icon" type="image/svg+xml" href="images/logo.svg">
<link rel="stylesheet" href="css/style.css?v=<?= (int) filemtime(__DIR__ . '/../css/style.css') ?>">
</head>
<body>

<a class="skip-link" href="#main">Skip to main content</a>

<header class="site-header">
  <div class="container header-inner">

    <a class="brand" href="index.php">
      <img src="images/logo.svg" alt="" width="40" height="40">
      <span class="brand-text">
        <strong><?= e(SITE_NAME) ?></strong>
        <small><?= e(SITE_TAGLINE) ?></small>
      </span>
    </a>

    <input type="checkbox" id="nav-toggle" class="nav-toggle">
    <label for="nav-toggle" class="nav-toggle-label" aria-hidden="true">
      <span></span><span></span><span></span>
    </label>

    <nav class="site-nav" aria-label="Main">
      <ul>
        <li><a href="index.php"<?= nav_active('index.php') ?>>Home</a></li>
        <li><a href="movies.php"<?= nav_active('movies.php') ?>>Films</a></li>
        <li><a href="showtimes.php"<?= nav_active('showtimes.php') ?>>Schedule</a></li>
        <li>
          <a href="preferences.php"<?= nav_active('preferences.php') ?>>Short&#8209;list<?php
            if ($prefCount > 0): ?><span class="pill"><?= (int) $prefCount ?></span><?php endif; ?></a>
        </li>
        <?php if ($me): ?>
          <li><a href="mybookings.php"<?= nav_active('mybookings.php') ?>>My bookings</a></li>
          <?php if (is_admin()): ?>
            <li><a href="admin.php"<?= nav_active('admin.php') ?>>Staff</a></li>
          <?php endif; ?>
        <?php endif; ?>
        <li><a href="contact.php"<?= nav_active('contact.php') ?>>Contact</a></li>
      </ul>

      <div class="nav-account">
        <?php if ($me): ?>
          <span class="who">Hello, <strong><?= e(explode(' ', $me['full_name'])[0]) ?></strong></span>
          <form method="post" action="logout.php" class="inline-form">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-ghost btn-sm">Sign out</button>
          </form>
        <?php else: ?>
          <a class="btn btn-ghost btn-sm" href="account.php">Sign in</a>
          <a class="btn btn-primary btn-sm" href="account.php?tab=register">Register</a>
        <?php endif; ?>
      </div>
    </nav>

  </div>
</header>

<?php if ($flashes): ?>
<div class="container flash-stack">
  <?php foreach ($flashes as $f):
      $cls = in_array($f['type'], ['success', 'error', 'info', 'warning'], true)
           ? $f['type'] : 'info'; ?>
    <p class="notice notice-<?= e($cls) ?>" role="status"><?= e($f['msg']) ?></p>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<main id="main">
