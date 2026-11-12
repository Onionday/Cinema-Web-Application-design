<?php













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
    usleep(400000);

    stash_form('login',
        ['email' => 'That email address and password do not match an account.'],
        ['email' => $email, 'remember' => $remember]);

    flash('error', 'Sign-in failed. Please check the address and password.');
    redirect('account.php?tab=login');
}


unset($_SESSION['login_attempts']);


if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
    db_execute('UPDATE users SET password_hash = ? WHERE user_id = ?',
               [password_hash($password, PASSWORD_DEFAULT), (int) $user['user_id']]);
}

login_user($user);

if ($remember) {


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


$next = $_SESSION['redirect_after_login'] ?? 'index.php';
unset($_SESSION['redirect_after_login']);

redirect($next);
