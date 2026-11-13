<?php
/* =====================================================================
 * logout.php
 *
 * Ends the session. POST only and CSRF-checked, so a stray <img> tag on
 * another site cannot sign the visitor out.
 * ===================================================================== */

require_once __DIR__ . '/includes/auth.php';

require_post();

if (is_logged_in()) {
    logout_user();
    flash('success', 'You have been signed out. Your short-list is saved for '
                   . 'the next time you sign in.');
} else {
    flash('info', 'You were not signed in.');
}

redirect('index.php');
