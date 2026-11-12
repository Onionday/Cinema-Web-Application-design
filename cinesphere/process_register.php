<?php














require_once __DIR__ . '/includes/auth.php';

require_post();


if (is_logged_in()) {
    redirect('account.php');
}

$errors = [];


$full_name = (string) ($_POST['full_name']  ?? '');
$email     = (string) ($_POST['email']      ?? '');
$phone     = (string) ($_POST['phone']      ?? '');
$birth     = (string) ($_POST['birth_date'] ?? '');
$password  = (string) ($_POST['password']   ?? '');
$confirm   = (string) ($_POST['confirm']    ?? '');
$terms     = !empty($_POST['terms']);


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


if (!isset($errors['email'])) {
    $existing = db_one('SELECT user_id FROM users WHERE email = ?', [$email]);
    if ($existing) {
        $errors['email'] = 'There is already an account with that email address. '
                         . 'Try signing in instead.';
    }
}


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


$hash = password_hash($password, PASSWORD_DEFAULT);

try {
    $res = db_execute(
        'INSERT INTO users (full_name, email, phone, password_hash, birth_date, role)
         VALUES (?, ?, ?, ?, ?, ?)',
        [$full_name, $email, $phone, $hash, $birth, 'customer']
    );
} catch (Throwable $ex) {


    stash_form('register', ['email' => 'That email address has just been taken.'],
               ['full_name' => $full_name, 'phone' => $phone]);
    flash('error', 'That email address is already registered.');
    redirect('account.php?tab=register');
}

$user = db_one('SELECT * FROM users WHERE user_id = ?', [(int) $res['insert_id']]);

login_user($user);

flash('success', 'Welcome to CineSphere, ' . explode(' ', $full_name)[0]
                . '. Your account is ready.');


$next = $_SESSION['redirect_after_login'] ?? 'index.php';
unset($_SESSION['redirect_after_login']);

redirect($next);
