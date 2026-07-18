<?php
/**
 * auth.php - Session authentication for the surf / earn-credit subsystem.
 *
 * Hardened: user lookups use prepared statements (the account email comes from
 * the session and must never be concatenated into SQL), and both helpers now
 * return the authenticated user row as an associative array so callers can read
 * fields directly (e.g. $user['id']) instead of via the removed mysql_result().
 *
 * Requires db.php to have been included first (provides db_row()).
 */

// Behind a local reverse proxy, prefer the forwarded client address.
if (isset($_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR'] === '127.0.0.1'
    && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_X_FORWARDED_FOR'];
}

// Block attempts to inject a forged _SESSION via request variables.
if (isset($_REQUEST['_SESSION'])) {
    global $self_url;
    header('Location: ' . $self_url . '?e=sess-invasion');
    $_SESSION = array();
    @session_destroy();
    exit;
}

/**
 * Look up and validate the currently logged-in user.
 *
 * @param string $error_redirect Absolute URL to redirect to on any failure.
 * @return array The authenticated user row.
 */
function te_authenticate($error_redirect)
{
    if (empty($_SESSION['sess_name']) || empty($_SESSION['sess_passwd'])) {
        header('Location: ' . $error_redirect);
        session_destroy();
        exit;
    }

    $user = db_row(
        'SELECT * FROM user WHERE email = ? LIMIT 1',
        's',
        array($_SESSION['sess_name'])
    );

    if (!$user) {
        header('Location: ' . $error_redirect);
        session_destroy();
        exit;
    }

    // The session stores md5() of the stored password; account must be active
    // (ac == 0). hash_equals guards against timing side-channels.
    if (!hash_equals(md5((string) $user['passwd']), (string) $_SESSION['sess_passwd'])
        || (int) $user['ac'] !== 0) {
        header('Location: ' . $error_redirect);
        session_destroy();
        exit;
    }

    return $user;
}

function checkauth()
{
    global $self_url;
    return te_authenticate($self_url . '?session-expired');
}

function checkbarauth()
{
    global $self_url;
    return te_authenticate($self_url . 'bar_break.php?error=session-expired');
}
