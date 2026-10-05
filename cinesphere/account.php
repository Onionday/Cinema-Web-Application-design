<?php
/* =====================================================================
 * account.php - sign in, register, and edit your details
 *
 * Holds the project's main form: the registration form has seven fields,
 * validated by HTML5 attributes, by js/validate.js and again by PHP in
 * process_register.php before anything is written to the users table.
 * ===================================================================== */

require_once __DIR__ . '/includes/auth.php';

$me  = current_user();
$tab = (string) ($_GET['tab'] ?? ($me ? 'details' : 'login'));

if (!in_array($tab, ['login', 'register', 'details'], true)) {
    $tab = $me ? 'details' : 'login';
}
if ($tab === 'details' && !$me) {
    $tab = 'login';
}
if ($me && $tab !== 'details') {
    /* Already signed in - there is nothing to log into. */
    $tab = 'details';
}

/* Field errors and previously typed values, carried over a redirect. */
$reg   = take_form('register');
$log   = take_form('login');
$det   = take_form('details');

$regE  = $reg['errors']; $regO = $reg['old'];
$logE  = $log['errors'];  $logO = $log['old'];
$detE  = $det['errors'];  $detO = $det['old'];

/* If the visitor was sent here mid-booking, say so. */
$pending = $_SESSION['pending_seats'] ?? null;

$page_title       = $me ? 'My details' : 'Sign in or register';
$page_description = 'Sign in to your CineSphere account, or create one to '
                  . 'short-list screenings and book seats.';
$page_scripts     = ['validate.js'];

require __DIR__ . '/includes/header.php';
?>

<div class="container">
  <p class="breadcrumb">
    <a href="index.php">Home</a><span>/</span><?= $me ? 'My details' : 'Account' ?>
  </p>
</div>

<section class="section-tight">
  <div class="container">
    <span class="eyebrow">Your account</span>
    <h1><?= $me ? 'Your details' : 'Sign in or create an account' ?></h1>
    <p class="lede">
      <?php if ($me): ?>
        Keep your contact details current &mdash; booking acknowledgements
        are sent to the email address below.
      <?php else: ?>
        An account keeps your short-list, your bookings and your
        references in one place. It takes about thirty seconds.
      <?php endif; ?>
    </p>
  </div>
</section>

<div class="container" style="padding-bottom:4rem;">

  <?php if ($pending && !$me): ?>
    <p class="notice notice-info">
      Your seat selection is being held. Sign in or register and you will be
      taken straight back to it.
    </p>
  <?php endif; ?>

  <div class="tabs">
    <?php if ($me): ?>
      <a href="account.php?tab=details" class="active">My details</a>
    <?php else: ?>
      <a href="account.php?tab=login"    class="<?= $tab === 'login'    ? 'active' : '' ?>">Sign in</a>
      <a href="account.php?tab=register" class="<?= $tab === 'register' ? 'active' : '' ?>">Create an account</a>
    <?php endif; ?>
  </div>


<!-- ==================================================================
     SIGN IN
     ================================================================== -->
<?php if ($tab === 'login'): ?>

  <div class="grid grid-2" style="gap:2.5rem;align-items:start;">

    <div class="card card-pad-lg">
      <h2 style="font-size:1.2rem;">Sign in</h2>

      <p class="notice notice-error form-summary" <?= $logE ? '' : 'hidden' ?>>
        <?= $logE ? e(reset($logE)) : '' ?>
      </p>

      <form method="post" action="process_login.php" data-validate>
        <?= csrf_field() ?>

        <div class="form-grid">
          <div class="field field-wide <?= isset($logE['email']) ? 'has-error' : '' ?>">
            <label for="l-email">Email address <span class="req">*</span></label>
            <input type="email" name="email" id="l-email"
                   value="<?= e($logO['email'] ?? '') ?>"
                   required maxlength="120" autocomplete="email"
                   placeholder="you@example.com"
                   data-check="email">
            <span class="error"><?= e($logE['email'] ?? '') ?></span>
          </div>

          <div class="field field-wide <?= isset($logE['password']) ? 'has-error' : '' ?>">
            <label for="l-pass">Password <span class="req">*</span></label>
            <input type="password" name="password" id="l-pass"
                   required maxlength="64" autocomplete="current-password"
                   data-check="required">
            <span class="error"><?= e($logE['password'] ?? '') ?></span>
          </div>

          <div class="field field-wide">
            <label class="check-row">
              <input type="checkbox" name="remember" value="1"
                     <?= !empty($logO['remember']) ? 'checked' : '' ?>>
              <span>Keep me signed in on this computer</span>
            </label>
          </div>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary btn-lg" data-busy="Signing in&hellip;">
            Sign in
          </button>
          <span class="small muted">
            No account yet? <a href="account.php?tab=register">Create one</a>.
          </span>
        </div>
      </form>
    </div>

    <div class="card card-pad-lg">
      <h2 style="font-size:1.05rem;">Try it without registering</h2>
      <p class="small muted">
        This is a coursework build, so it ships with two accounts already in
        the database. Both are ordinary rows in the <code>users</code> table
        with bcrypt-hashed passwords.
      </p>

      <div class="table-wrap" style="margin-top:1rem;">
        <table class="data" style="min-width:auto;">
          <thead>
            <tr>
              <th scope="col">Role</th>
              <th scope="col">Email</th>
              <th scope="col">Password</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>Customer</td>
              <td><code>demo@cinesphere.sg</code></td>
              <td><code>Demo@123</code></td>
            </tr>
            <tr>
              <td>Staff</td>
              <td><code>admin@cinesphere.sg</code></td>
              <td><code>Admin@123</code></td>
            </tr>
          </tbody>
        </table>
      </div>

      <p class="small muted" style="margin-top:1rem;margin-bottom:0;">
        The staff account also unlocks the Staff page, where films and
        screenings are added and feedback is triaged.
      </p>
    </div>
  </div>


<!-- ==================================================================
     REGISTER
     ================================================================== -->
<?php elseif ($tab === 'register'): ?>

  <div class="grid grid-2" style="gap:2.5rem;align-items:start;">

    <div class="card card-pad-lg">
      <h2 style="font-size:1.2rem;">Create an account</h2>
      <p class="small muted">
        Fields marked <span class="req">*</span> are required.
      </p>

      <p class="notice notice-error form-summary" <?= $regE ? '' : 'hidden' ?>>
        <?= $regE ? 'Please correct the highlighted fields before continuing.' : '' ?>
      </p>

      <form method="post" action="process_register.php" data-validate>
        <?= csrf_field() ?>

        <fieldset>
          <legend>About you</legend>
          <div class="form-grid">

            <div class="field field-wide <?= isset($regE['full_name']) ? 'has-error' : '' ?>">
              <label for="r-name">Full name <span class="req">*</span></label>
              <input type="text" name="full_name" id="r-name"
                     value="<?= e($regO['full_name'] ?? '') ?>"
                     required minlength="3" maxlength="80" autocomplete="name"
                     placeholder="As it appears on your ID"
                     data-check="name">
              <span class="error"><?= e($regE['full_name'] ?? '') ?></span>
            </div>

            <div class="field <?= isset($regE['email']) ? 'has-error' : '' ?>">
              <label for="r-email">Email address <span class="req">*</span></label>
              <input type="email" name="email" id="r-email"
                     value="<?= e($regO['email'] ?? '') ?>"
                     required maxlength="120" autocomplete="email"
                     placeholder="you@example.com"
                     data-check="email">
              <span class="hint">Booking acknowledgements are sent here.</span>
              <span class="error"><?= e($regE['email'] ?? '') ?></span>
            </div>

            <div class="field <?= isset($regE['phone']) ? 'has-error' : '' ?>">
              <label for="r-phone">Contact number <span class="req">*</span></label>
              <input type="tel" name="phone" id="r-phone"
                     value="<?= e($regO['phone'] ?? '') ?>"
                     required maxlength="20" autocomplete="tel"
                     pattern="[+]?[0-9 \-()]{8,20}"
                     placeholder="+65 9123 4567"
                     data-check="phone">
              <span class="error"><?= e($regE['phone'] ?? '') ?></span>
            </div>

            <div class="field field-wide <?= isset($regE['birth_date']) ? 'has-error' : '' ?>">
              <label for="r-dob">Date of birth</label>
              <input type="date" name="birth_date" id="r-dob"
                     value="<?= e($regO['birth_date'] ?? '') ?>"
                     max="<?= e(date('Y-m-d')) ?>"
                     data-check="birthdate">
              <span class="hint">
                Optional, but we need it to sell you an NC16 or M18 ticket.
              </span>
              <span class="error"><?= e($regE['birth_date'] ?? '') ?></span>
            </div>

          </div>
        </fieldset>

        <fieldset>
          <legend>Security</legend>
          <div class="form-grid">

            <div class="field <?= isset($regE['password']) ? 'has-error' : '' ?>">
              <label for="r-pass">Password <span class="req">*</span></label>
              <input type="password" name="password" id="r-pass"
                     required minlength="8" maxlength="64" autocomplete="new-password"
                     data-check="password" data-meter="#pw-meter">
              <span class="pw-meter" id="pw-meter"><i></i></span>
              <span class="hint">
                At least 8 characters with a letter, a number and a symbol.
              </span>
              <span class="error"><?= e($regE['password'] ?? '') ?></span>
            </div>

            <div class="field <?= isset($regE['confirm']) ? 'has-error' : '' ?>">
              <label for="r-confirm">Re-type password <span class="req">*</span></label>
              <input type="password" name="confirm" id="r-confirm"
                     required maxlength="64" autocomplete="new-password"
                     data-check="match" data-match="#r-pass">
              <span class="error"><?= e($regE['confirm'] ?? '') ?></span>
            </div>

          </div>
        </fieldset>

        <div class="field <?= isset($regE['terms']) ? 'has-error' : '' ?>">
          <label class="check-row">
            <input type="checkbox" name="terms" id="r-terms" value="1" required
                   data-check="accept">
            <span>
              I agree to the booking conditions and understand that tickets
              are refundable up to two hours before the screening.
              <span class="req">*</span>
            </span>
          </label>
          <span class="error"><?= e($regE['terms'] ?? '') ?></span>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary btn-lg" data-busy="Creating&hellip;">
            Create my account
          </button>
          <span class="small muted">
            Already registered? <a href="account.php?tab=login">Sign in</a>.
          </span>
        </div>
      </form>
    </div>

    <div class="card card-pad-lg">
      <h2 style="font-size:1.05rem;">What an account gets you</h2>
      <ul class="plain small" style="color:var(--ink-soft);">
        <li>&#9679;&nbsp; A short-list of up to <?= (int) MAX_PREFERENCES ?> screenings you would accept</li>
        <li>&#9679;&nbsp; One-step confirmation of whichever are still free</li>
        <li>&#9679;&nbsp; Every booking reference and e-ticket in one place</li>
        <li>&#9679;&nbsp; Self-service cancellation up to two hours before the film</li>
        <li>&#9679;&nbsp; Email acknowledgements for bookings and cancellations</li>
      </ul>

      <hr>

      <h3 style="font-size:.95rem;">How your details are handled</h3>
      <p class="small muted" style="margin-bottom:0;">
        Passwords are stored as bcrypt hashes, never as text. Card details
        never reach this site at all &mdash; payment is taken by a third-party
        gateway. Everything you type is validated in the browser and again
        on the server before it is written to the database.
      </p>
    </div>
  </div>


<!-- ==================================================================
     MY DETAILS (signed in)
     ================================================================== -->
<?php else: ?>

  <div class="grid grid-2" style="gap:2.5rem;align-items:start;">

    <div class="card card-pad-lg">
      <h2 style="font-size:1.2rem;">Edit your details</h2>

      <p class="notice notice-error form-summary" <?= $detE ? '' : 'hidden' ?>>
        <?= $detE ? 'Please correct the highlighted fields.' : '' ?>
      </p>

      <form method="post" action="process_account.php" data-validate>
        <?= csrf_field() ?>

        <div class="form-grid">
          <div class="field field-wide <?= isset($detE['full_name']) ? 'has-error' : '' ?>">
            <label for="d-name">Full name <span class="req">*</span></label>
            <input type="text" name="full_name" id="d-name"
                   value="<?= e($detO['full_name'] ?? $me['full_name']) ?>"
                   required minlength="3" maxlength="80" data-check="name">
            <span class="error"><?= e($detE['full_name'] ?? '') ?></span>
          </div>

          <div class="field <?= isset($detE['email']) ? 'has-error' : '' ?>">
            <label for="d-email">Email address <span class="req">*</span></label>
            <input type="email" name="email" id="d-email"
                   value="<?= e($detO['email'] ?? $me['email']) ?>"
                   required maxlength="120" data-check="email">
            <span class="error"><?= e($detE['email'] ?? '') ?></span>
          </div>

          <div class="field <?= isset($detE['phone']) ? 'has-error' : '' ?>">
            <label for="d-phone">Contact number <span class="req">*</span></label>
            <input type="tel" name="phone" id="d-phone"
                   value="<?= e($detO['phone'] ?? $me['phone']) ?>"
                   required maxlength="20"
                   pattern="[+]?[0-9 \-()]{8,20}" data-check="phone">
            <span class="error"><?= e($detE['phone'] ?? '') ?></span>
          </div>

          <div class="field field-wide <?= isset($detE['birth_date']) ? 'has-error' : '' ?>">
            <label for="d-dob">Date of birth</label>
            <input type="date" name="birth_date" id="d-dob"
                   value="<?= e($detO['birth_date'] ?? ($me['birth_date'] ?? '')) ?>"
                   max="<?= e(date('Y-m-d')) ?>" data-check="birthdate">
            <span class="error"><?= e($detE['birth_date'] ?? '') ?></span>
          </div>
        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary" data-busy="Saving&hellip;">
            Save changes
          </button>
          <span class="small muted">Your password is not changed here.</span>
        </div>
      </form>
    </div>

    <div class="card card-pad-lg">
      <h2 style="font-size:1.05rem;">Account</h2>
      <dl class="spec-list" style="border:0;padding:0;margin-top:0;">
        <div><dt>Member since</dt>
          <dd><?= e(date('j F Y', strtotime($me['created_at']))) ?></dd></div>
        <div><dt>Last signed in</dt>
          <dd><?= $me['last_login']
                ? e(date('j M Y, g:i A', strtotime($me['last_login'])))
                : 'This is your first visit' ?></dd></div>
        <div><dt>Account type</dt>
          <dd><?= e(ucfirst($me['role'])) ?></dd></div>
        <div><dt>Short-list</dt>
          <dd><?= preference_count((int) $me['user_id']) ?> of <?= (int) MAX_PREFERENCES ?> entries</dd></div>
      </dl>

      <div class="btn-row" style="margin-top:1.4rem;">
        <a class="btn btn-ghost btn-sm" href="mybookings.php">My bookings</a>
        <a class="btn btn-ghost btn-sm" href="preferences.php">My short-list</a>
      </div>

      <hr>

      <form method="post" action="logout.php">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-ghost btn-sm">Sign out of this device</button>
      </form>
    </div>
  </div>

<?php endif; ?>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
