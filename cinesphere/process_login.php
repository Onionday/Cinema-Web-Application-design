<?php
/* =====================================================================
 * process_login.php
 *
 * Verifies an email address and password against the users table.
 *
 * The same message is shown whether the address is unknown or the
 * password is wrong, so the form cannot be used to find out which
 * addresses are registered. A short delay and a per-session attempt
 * counter blunt repeated guessing.
 *
 * SQL used: SELECT, UPDATE (the last_login stamp, in login_user()).
 * ===================================================================== */

require_once __DIR__ . '/includes/auth.php';

require_post();

if (is_logged_in()) {
    redirect('index.php');
}

$errors   = [];
$email    = (string) ($_POST['email']    ?? '');
$password = (string) ($_POST['password'] ?? '');
$remember = !empty($_POST['remember']);

$email = v_email($email, $errors);

if ($password === '') {
    $errors['password'] = 'Please enter your password.';
}

/* ---- Rate limiting -------------------------------------------------- */
$_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0);

if ($_SESSION['login_attempts'] >= 8) {
    flash('error', 'Too many failed sign-in attempts. Close the browser tab '
                 . 'and start again, or contact the box office.');
    redirect('account.php?tab=login');
}

if ($errors) {
    stash_form('login', $errors, ['email' => $email, 'remember' => $remember]);
    redirect('account.php?tab=login');
}

/* ---- Look the account up ------------------------------------------- */
$user = db_one(
    'SELECT user_id, full_name, email, password_hash, role, is_active
       FROM users
      WHERE email = ?',
    [$email]
);

$ok = $user
   && (int) $user['is_active'] === 1
   && password_verify($password, $user['password_hash']);

if (!$ok) {
    $_SESSION['login_attempts']++;
    usleep(400000);                    // 0.4s - slows down automated guessing

    stash_form('login',
        ['email' => 'That email address and password do not match an account.'],
        ['email' => $email, 'remember' => $remember]);

    flash('error', 'Sign-in failed. Please check the address and password.');
    redirect('account.php?tab=login');
}

/* ---- Success -------------------------------------------------------- */
unset($_SESSION['login_attempts']);

/* If the stored hash was made with an older algorithm, quietly upgrade it. */
if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
    db_execute('UPDATE users SET password_hash = ? WHERE user_id = ?',
               [password_hash($password, PASSWORD_DEFAULT), (int) $user['user_id']]);
}

login_user($user);

if ($remember) {
    /* Keep the session cookie for thirty days instead of until the
       browser closes. */
    $p = session_get_cookie_params();
    setcookie(session_name(), session_id(), [
        'expires'  => time() + 30 * 24 * 3600,
        'path'     => $p['path'],
        'domain'   => $p['domain'],
        'secure'   => $p['secure'],
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

flash('success', 'Signed in as ' . $user['email'] . '.');

/* Straight back to whatever they were doing. */
$next = $_SESSION['redirect_after_login'] ?? 'index.php';
unset($_SESSION['redirect_after_login']);

redirect($next);
