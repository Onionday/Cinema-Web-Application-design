<?php
/* =====================================================================
 * process_contact.php
 *
 * Server-side processing for the feedback form: validate, store in
 * contact_messages, acknowledge by email.
 *
 * SQL used: INSERT.
 * ===================================================================== */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mailer.php';

require_post();

$errors = [];

$name    = v_name((string) ($_POST['name'] ?? ''), 'Your name', $errors, 'name');
$email   = v_email((string) ($_POST['email'] ?? ''), $errors);
$phone   = v_phone((string) ($_POST['phone'] ?? ''), $errors);
$subject = trim((string) ($_POST['subject'] ?? ''));
$rating  = (int) ($_POST['rating'] ?? 0);
$message = trim((string) ($_POST['message'] ?? ''));
$copyMe  = !empty($_POST['copy_me']);

/* ---- Subject must be one we offer --------------------------------- */
$allowedSubjects = ['Feedback', 'Booking help', 'Technical issue',
                    'Accessibility', 'Lost property', 'Enquiry'];

if ($subject === '') {
    $errors['subject'] = 'Please choose a subject.';
} elseif (!in_array($subject, $allowedSubjects, true)) {
    $errors['subject'] = 'Please choose one of the listed subjects.';
}

/* ---- Rating -------------------------------------------------------- */
if ($rating < 1 || $rating > 5) {
    $errors['rating'] = 'Please choose a rating between 1 and 5.';
}

/* ---- Message ------------------------------------------------------- */
if ($message === '') {
    $errors['message'] = 'Please tell us what you would like to say.';
} elseif (mb_strlen($message) < 20) {
    $errors['message'] = 'Please give us a little more detail - at least 20 characters.';
} elseif (mb_strlen($message) > 1200) {
    $errors['message'] = 'Please keep the message to 1200 characters or fewer.';
}

/* ---- Hand it back if anything is wrong ----------------------------- */
if ($errors) {
    stash_form('contact', $errors, [
        'name'    => $name,
        'email'   => $email,
        'phone'   => $phone,
        'subject' => $subject,
        'rating'  => $rating,
        'message' => $message,
        'copy_me' => $copyMe,
    ]);
    flash('error', 'Your message has not been sent yet - please check the '
                 . 'highlighted fields.');
    redirect('contact.php#c-name');
}

/* ---- Store it ------------------------------------------------------ */
$res = db_execute(
    'INSERT INTO contact_messages (name, email, phone, subject, rating, message)
     VALUES (?, ?, ?, ?, ?, ?)',
    [$name, $email, $phone, $subject, $rating, $message],
    'ssssis'
);

$ref = (int) $res['insert_id'];

/* ---- Acknowledge it ------------------------------------------------ */
send_contact_ack($name, $email, $subject, $ref);

if ($copyMe) {
    send_mail(
        $email,
        SITE_NAME . ' - copy of your message (#CM' . $ref . ')',
        "Here is the copy you asked for.\n\n"
        . "Subject : {$subject}\n"
        . "Rating  : {$rating}/5\n"
        . "Sent    : " . date('j M Y, g:i A') . "\n\n"
        . str_repeat('-', 58) . "\n"
        . $message . "\n"
        . str_repeat('-', 58) . "\n"
    );
}

flash('success', 'Thank you - your message has been logged as reference #CM'
                . $ref . '. We will reply to ' . $email
                . ' within two working days.');

redirect('contact.php');
