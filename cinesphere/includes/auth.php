<?php







require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/rolling_schedule.php';



ensure_rolling_schedule();

function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

function current_user(): ?array
{
    static $cache = null;

    if (!is_logged_in()) {
        return null;
    }
    if ($cache !== null) {
        return $cache;
    }

    $cache = db_one(
        'SELECT user_id, full_name, email, phone, birth_date, role, created_at, last_login
           FROM users
          WHERE user_id = ? AND is_active = 1',
        [(int) $_SESSION['user_id']]
    );

    if (!$cache) {
        logout_user();
        return null;
    }

    return $cache;
}

function current_user_id(): int
{
    return (int) ($_SESSION['user_id'] ?? 0);
}

function is_admin(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'admin';
}


function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id']   = (int) $user['user_id'];
    $_SESSION['user_name'] = $user['full_name'];
    $_SESSION['user_role'] = $user['role'];


    db_execute('UPDATE users SET last_login = NOW() WHERE user_id = ?',
               [(int) $user['user_id']]);
}










function logout_user(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}





function require_login(string $why = 'Please sign in to continue.'): void
{
    if (is_logged_in()) {
        return;
    }
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? 'index.php';
    flash('info', $why);
    redirect('account.php');
}


function require_admin(): void
{
    require_login('Please sign in with a staff account.');
    if (!is_admin()) {
        flash('error', 'That page is restricted to CineSphere staff accounts.');
        redirect('index.php');
    }
}
