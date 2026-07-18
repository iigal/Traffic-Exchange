<?php
/**
 * surf.php - Starts an authenticated surf session and renders the surf frameset.
 *
 * Migrated from the removed mysql_* API to the shared mysqli layer (db.php),
 * hardened against PHP 8 "undefined key" warnings, and made safe against SQL
 * injection (all identifiers are constants; user id comes from an authenticated
 * lookup).
 */

if (session_status() === PHP_SESSION_NONE) {
    session_name('TrafExchange');
    session_start();
}

include('vars.php');
include('db.php');
include('auth.php');

db_connect();

$user   = checkauth();
$usrid  = (int) $user['id'];
$acctype = (int) $user['acctype'];

$daily_max = ($acctype == 1) ? $surf_max_free : $surf_max_pro;

if (($_SESSION['sess_data']['usrid'] ?? null) != $usrid) {
    $_SESSION['sess_data']['cts'] = 0;
    $_SESSION['sess_data']['sts'] = 0;
    $_SESSION['sess_data']['pgv'] = 0;
    $_SESSION['sess_data']['usrid'] = $usrid;
}

$today = date('Y-m-d');

// Enforce the daily credit cap before starting a session.
$crds_today = (float) db_scalar(
    "SELECT num FROM 7stat WHERE usrid = ? AND date = ?",
    'is',
    array($usrid, $today),
    0
);

if ($crds_today >= $daily_max) {
    echo 'Sorry you have earned your daily maximum of ' . htmlspecialchars($daily_max)
        . ' credits!<br />Please return tomorrow!<br /><br />'
        . '<a href="' . htmlspecialchars($self_url) . '">Login to your account here</a>';
    session_destroy();
    exit;
}

/**
 * Read a numeric admin property.
 */
function te_prop($field, $default = 0)
{
    $v = db_scalar('SELECT value FROM adminprops WHERE field = ? LIMIT 1', 's', array($field));
    return ($v === null) ? $default : $v;
}

$_SESSION['sess_data']['negact'] = te_prop('negact', 0);
$_SESSION['sess_data']['reftim'] = max(1, (int) te_prop('reftim', 6));

$contex = (float) te_prop('contex', 0);
if ($contex != 0) {
    $_SESSION['sess_data']['contex'] = $contex;
    $_SESSION['sess_data']['contey'] = te_prop('contey', 0);
}

$contcx = (float) te_prop('contcx', 0);
if ($contcx != 0) {
    $_SESSION['sess_data']['contcx'] = $contcx;
    $_SESSION['sess_data']['contcy'] = te_prop('contcy', 0);
}

// Per-user credit rate: minmax picks the min or max rate for their account type.
$minmax = (int) db_scalar('SELECT minmax FROM user WHERE id = ? LIMIT 1', 'i', array($usrid), 0);
$_SESSION['sess_data']['mmax'] = $minmax;
$rate_col = ($minmax === 1) ? 'ratemin' : 'ratemax';

$rate_row = db_row(
    "SELECT acctype.$rate_col AS rate, user.ref AS ref
       FROM acctype, user
      WHERE acctype.id = user.acctype AND user.id = ? LIMIT 1",
    'i',
    array($usrid)
);
$_SESSION['sess_data']['rate'] = $rate_row ? (float) $rate_row['rate'] : 0;
$_SESSION['sess_data']['ref']  = $rate_row ? (int) $rate_row['ref'] : 0;

// Fresh anti-tamper token for this surf session.
$_SESSION['sess_data']['surfing'] = rand(9999, 9999999999);
$_SESSION['sess_data']['from']    = md5($_SESSION['sess_data']['surfing']);

$next = $_GET['next'] ?? '';
if ($next !== '' && isset($_SESSION['sess_data']['surf_encoder_vals'])
    && $next === md5($_SESSION['sess_data']['surf_encoder_vals'])) {
    $s_bar_url = 'surfbar.php?PHPSESSID=' . session_id() . '&vc_val=' . urlencode($next);
} else {
    $s_bar_url = 'surfbar.php?PHPSESSID=' . session_id()
        . '&vc_val=begin&coder=' . md5($_SESSION['sess_data']['from']);
}

$title_esc = htmlspecialchars($title);
echo "<html>\n<head>\n<title>$title_esc: Surf</title>\n"
    . "<link rel=stylesheet type=text/css href=" . htmlspecialchars($self_url) . "style.css>\n</head>\n"
    . "<frameset rows=90,* border=0>"
    . "<frame marginheight=0 marginwidth=0 scrolling=no noresize border=0 src=\"" . htmlspecialchars($s_bar_url) . "\">"
    . "<frame marginheight=0 marginwidth=0 scrolling=auto noresize border=0 src=./target.php>"
    . "</frameset>\n</html>";
exit;
