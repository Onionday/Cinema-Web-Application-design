<?php
/* =====================================================================
 * process_register.php
 *
 * Server-side processing for the registration form.
 *
 * The browser has already checked these fields twice - once through the
 * HTML5 attributes and once through js/validate.js - but neither can be
 * relied on: a form can be posted with JavaScript turned off, or from a
 * tool that never loaded the page at all. These checks are the ones that
 * decide whether a row reaches the users table.
 *
 * SQL used: SELECT, INSERT.
 * ===================================================================== */

require_once __DIR__ . '/includes/auth.php';

require_post();

/* Already signed in? Nothing to do. */
if (is_logged_in()) {
    redirect('account.php');
}

$errors = [];

/* ---- Collect ------------------------------------------------------- */
$full_name = (string) ($_POST['full_name']  ?? '');
$email     = (string) ($_POST['email']      ?? '');
$phone     = (string) ($_POST['phone']      ?? '');
$birth     = (string) ($_POST['birth_date'] ?? '');
$password  = (string) ($_POST['password']   ?? '');
$confirm   = (string) ($_POST['confirm']    ?? '');
$terms     = !empty($_POST['terms']);

/* ---- Validate ------------------------------------------------------ */
$full_name = v_name($full_name, 'Full name', $errors, 'full_name');
$email     = v_email($email, $errors);
$phone     = v_phone($phone, $errors);
$birth     = v_birthdate($birth, $errors);
$password  = v_password($password, $errors);

if ($confirm === '') {
    $errors['confirm'] = 'Please re-type the password.';
} elseif ($confirm !== $password) {
    $errors['confirm'] = 'The two passwords do not match.';
}

if (!$terms) {
    $errors['terms'] = 'Please accept the booking conditions to continue.';
}

/* ---- The address has to be free ------------------------------------ */
if (!isset($errors['email'])) {
    $existing = db_one('SELECT user_id FROM users WHERE email = ?', [$email]);
    if ($existing) {
        $errors['email'] = 'There is already an account with that email address. '
                         . 'Try signing in instead.';
    }
}

/* ---- Anything wrong: hand the form back with the values kept ------- */
if ($errors) {
    stash_form('register', $errors, [
        'full_name'  => $full_name,
        'email'      => $email,
        'phone'      => $phone,
        'birth_date' => $birth ?? '',
    ]);
    flash('error', 'We could not create the account yet - please check the '
                 . 'highlighted fields.');
    redirect('account.php?tab=register');
}

/* ---- Create the account -------------------------------------------- */
$hash = password_hash($password, PASSWORD_DEFAULT);

try {
    $res = db_execute(
        'INSERT INTO users (full_name, email, phone, password_hash, birth_date, role)
         VALUES (?, ?, ?, ?, ?, ?)',
        [$full_name, $email, $phone, $hash, $birth, 'customer']
    );
} catch (Throwable $ex) {
    /* The UNIQUE key on users.email is the final guard against two
       people registering the same address at the same moment. */
    stash_form('register', ['email' => 'That email address has just been taken.'],
               ['full_name' => $full_name, 'phone' => $phone]);
    flash('error', 'That email address is already registered.');
    redirect('account.php?tab=register');
}

$user = db_one('SELECT * FROM users WHERE user_id = ?', [(int) $res['insert_id']]);

login_user($user);

flash('success', 'Welcome to CineSphere, ' . explode(' ', $full_name)[0]
                . '. Your account is ready.');

/* Back to whatever they were doing before they were asked to register. */
$next = $_SESSION['redirect_after_login'] ?? 'index.php';
unset($_SESSION['redirect_after_login']);

redirect($next);
