<?php
/* =====================================================================
 * process_account.php
 *
 * Updates the signed-in customer's own details.
 *
 * This is the project's UPDATE against the users table. The email
 * address is re-checked for uniqueness against every OTHER account
 * before it is written.
 *
 * SQL used: SELECT, UPDATE.
 * ===================================================================== */

require_once __DIR__ . '/includes/auth.php';

require_post();
require_login();

$me      = current_user();
$user_id = (int) $me['user_id'];
$errors  = [];

$full_name = v_name((string) ($_POST['full_name'] ?? ''), 'Full name', $errors, 'full_name');
$email     = v_email((string) ($_POST['email'] ?? ''), $errors);
$phone     = v_phone((string) ($_POST['phone'] ?? ''), $errors);
$birth     = v_birthdate((string) ($_POST['birth_date'] ?? ''), $errors);

/* The address may not belong to somebody else. */
if (!isset($errors['email'])) {
    $clash = db_one('SELECT user_id FROM users WHERE email = ? AND user_id <> ?',
                    [$email, $user_id]);
    if ($clash) {
        $errors['email'] = 'Another account is already using that email address.';
    }
}

if ($errors) {
    stash_form('details', $errors, [
        'full_name'  => $full_name,
        'email'      => $email,
        'phone'      => $phone,
        'birth_date' => $birth ?? '',
    ]);
    flash('error', 'Your details were not saved - please check the highlighted fields.');
    redirect('account.php?tab=details');
}

/* ---- Write it ------------------------------------------------------- */
$emailChanged = (strtolower($me['email']) !== strtolower($email));

db_execute(
    'UPDATE users
        SET full_name = ?, email = ?, phone = ?, birth_date = ?
      WHERE user_id = ?',
    [$full_name, $email, $phone, $birth, $user_id]
);

$_SESSION['user_name'] = $full_name;

flash('success', $emailChanged
    ? 'Your details have been saved. Future acknowledgements will go to ' . $email . '.'
    : 'Your details have been saved.');

redirect('account.php?tab=details');
