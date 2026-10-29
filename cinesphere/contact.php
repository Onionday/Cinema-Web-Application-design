<?php
/* =====================================================================
 * contact.php - venues, opening hours and the feedback form
 *
 * The form here is a second full server-side-processed form: six fields,
 * validated in the browser and again in process_contact.php, written to
 * contact_messages and acknowledged by email.
 * ===================================================================== */

require_once __DIR__ . '/includes/auth.php';

$cinemas = db_all('SELECT * FROM cinemas ORDER BY cinema_id');

foreach ($cinemas as &$c) {
    $c['halls'] = db_all(
        'SELECT h.hall_name, h.hall_type, (h.row_count * h.seats_per_row) AS seats
           FROM halls h WHERE h.cinema_id = ? ORDER BY h.hall_id',
        [(int) $c['cinema_id']]
    );
}
unset($c);

$form   = take_form('contact');
$errs   = $form['errors'];
$old    = $form['old'];

$me = current_user();

$subjects = [
    'Feedback'        => 'General feedback',
    'Booking help'    => 'Help with a booking',
    'Technical issue' => 'Something on the site is broken',
    'Accessibility'   => 'Accessibility request',
    'Lost property'   => 'Lost property',
    'Enquiry'         => 'Something else',
];

$page_title       = 'Contact us';
$page_description = 'CineSphere venue addresses, opening hours and the '
                  . 'feedback form.';
$page_scripts     = ['validate.js'];

require __DIR__ . '/includes/header.php';
?>

<div class="container">
  <p class="breadcrumb"><a href="index.php">Home</a><span>/</span>Contact</p>
</div>

<section class="section-tight">
  <div class="container">
    <span class="eyebrow">Guest services</span>
    <h1>Get in touch</h1>
    <p class="lede">
      Box office lines are open daily from 10:00 to 01:00. If it is about a
      booking, quoting the reference gets you an answer faster.
    </p>
  </div>
</section>

<div class="container" style="padding-bottom:4rem;">

  <div class="grid grid-2" style="gap:2.5rem;align-items:start;">

    <!-- ======================== THE FORM ======================== -->
    <div class="card card-pad-lg">
      <h2 style="font-size:1.2rem;">Send us a message</h2>
      <p class="small muted">
        We reply within two working days. Fields marked
        <span class="req">*</span> are required.
      </p>

      <p class="notice notice-error form-summary" <?= $errs ? '' : 'hidden' ?>>
        <?= $errs ? 'Please correct the highlighted fields before sending.' : '' ?>
      </p>

      <form method="post" action="process_contact.php" data-validate>
        <?= csrf_field() ?>

        <div class="form-grid">

          <div class="field <?= isset($errs['name']) ? 'has-error' : '' ?>">
            <label for="c-name">Your name <span class="req">*</span></label>
            <input type="text" name="name" id="c-name"
                   value="<?= e($old['name'] ?? ($me['full_name'] ?? '')) ?>"
                   required minlength="3" maxlength="80" autocomplete="name"
                   data-check="name">
            <span class="error"><?= e($errs['name'] ?? '') ?></span>
          </div>

          <div class="field <?= isset($errs['email']) ? 'has-error' : '' ?>">
            <label for="c-email">Email address <span class="req">*</span></label>
            <input type="email" name="email" id="c-email"
                   value="<?= e($old['email'] ?? ($me['email'] ?? '')) ?>"
                   required maxlength="120" autocomplete="email"
                   data-check="email">
            <span class="error"><?= e($errs['email'] ?? '') ?></span>
          </div>

          <div class="field <?= isset($errs['phone']) ? 'has-error' : '' ?>">
            <label for="c-phone">Contact number <span class="req">*</span></label>
            <input type="tel" name="phone" id="c-phone"
                   value="<?= e($old['phone'] ?? ($me['phone'] ?? '')) ?>"
                   required maxlength="20" autocomplete="tel"
                   pattern="[+]?[0-9 \-()]{8,20}"
                   data-check="phone">
            <span class="error"><?= e($errs['phone'] ?? '') ?></span>
          </div>

          <div class="field <?= isset($errs['subject']) ? 'has-error' : '' ?>">
            <label for="c-subject">Subject <span class="req">*</span></label>
            <select name="subject" id="c-subject" required data-check="choose">
              <option value="">Choose a subject&hellip;</option>
              <?php foreach ($subjects as $value => $text): ?>
                <option value="<?= e($value) ?>"
                        <?= ($old['subject'] ?? '') === $value ? 'selected' : '' ?>>
                  <?= e($text) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <span class="error"><?= e($errs['subject'] ?? '') ?></span>
          </div>

          <div class="field field-wide <?= isset($errs['rating']) ? 'has-error' : '' ?>">
            <label for="c-rating">How would you rate your last visit? <span class="req">*</span></label>
            <select name="rating" id="c-rating" required data-check="choose">
              <?php
              $ratings = [
                  5 => '5 - Excellent',
                  4 => '4 - Good',
                  3 => '3 - Fine',
                  2 => '2 - Disappointing',
                  1 => '1 - Poor',
              ];
              $sel = (string) ($old['rating'] ?? '5');
              foreach ($ratings as $v => $t): ?>
                <option value="<?= $v ?>" <?= $sel === (string) $v ? 'selected' : '' ?>>
                  <?= e($t) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <span class="error"><?= e($errs['rating'] ?? '') ?></span>
          </div>

          <div class="field field-wide <?= isset($errs['message']) ? 'has-error' : '' ?>">
            <label for="c-message">Message <span class="req">*</span></label>
            <textarea name="message" id="c-message"
                      required minlength="20" maxlength="1200"
                      placeholder="Tell us what happened. If it is about a booking, include the reference (for example CS4A2F1B26)."
                      data-check="minlen maxlen"
                      data-minlen="20" data-maxlen="1200"
                      data-counter="#c-count"><?= e($old['message'] ?? '') ?></textarea>
            <span class="hint" id="c-count">0 / 1200 characters</span>
            <span class="error"><?= e($errs['message'] ?? '') ?></span>
          </div>

          <div class="field field-wide">
            <label class="check-row">
              <input type="checkbox" name="copy_me" value="1"
                     <?= !empty($old['copy_me']) ? 'checked' : '' ?>>
              <span>Email me a copy of this message</span>
            </label>
          </div>

        </div>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary btn-lg" data-busy="Sending&hellip;">
            Send message
          </button>
          <span class="small muted">
            We never pass your details to anyone else.
          </span>
        </div>
      </form>
    </div>


    <!-- ========================= VENUES ========================= -->
    <div>
      <h2 style="font-size:1.2rem;margin-top:0;">Our venues</h2>

      <?php foreach ($cinemas as $c): ?>
        <div class="card" style="margin-bottom:1rem;">
          <h3 style="font-size:1rem;margin-bottom:.3rem;"><?= e($c['name']) ?></h3>
          <p class="small muted" style="margin-bottom:.5rem;"><?= e($c['address']) ?></p>
          <dl class="spec-list" style="border:0;padding:0;margin:0;gap:.7rem 1.2rem;">
            <div><dt>Phone</dt><dd class="small"><?= e($c['phone']) ?></dd></div>
            <div><dt>Open</dt><dd class="small"><?= e($c['opening_hrs']) ?></dd></div>
            <div>
              <dt>Halls</dt>
              <dd class="small">
                <?php foreach ($c['halls'] as $h): ?>
                  <?= e($h['hall_name']) ?>
                  <span class="faint">(<?= e($h['hall_type']) ?>, <?= (int) $h['seats'] ?> seats)</span><br>
                <?php endforeach; ?>
              </dd>
            </div>
          </dl>
          <p style="margin:.8rem 0 0;">
            <a class="small" href="showtimes.php?cinema=<?= (int) $c['cinema_id'] ?>">
              What&rsquo;s on here &rarr;
            </a>
          </p>
        </div>
      <?php endforeach; ?>

      <div class="card">
        <h3 style="font-size:1rem;">Before you write</h3>
        <ul class="plain small" style="color:var(--ink-soft);">
          <li><strong>Cancelling?</strong> You can do it yourself from
              <a href="mybookings.php">My bookings</a> up to two hours before
              the screening.</li>
          <li><strong>Lost your reference?</strong> Sign in and it is on the
              booking, no need to ask.</li>
          <li><strong>Refund not arrived?</strong> Allow five working days
              before chasing it.</li>
        </ul>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
