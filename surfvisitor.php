<?php
/**
 * surfvisitor.php - Guest (trial) surf session setup. No real credits are
 * earned; this just lets visitors preview the surf experience. Migrated to the
 * shared mysqli layer and made PHP 8 warning-safe.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_name('TrafExchange');
    session_start();
}
include('vars.php');
include('db.php');

db_connect();

$usrid = 'Guest';
$daily_max = 999999999999;

if (($_SESSION['sess_data']['usrid'] ?? null) != $usrid) {
    $_SESSION['sess_data']['cts'] = 0;
    $_SESSION['sess_data']['sts'] = 0;
    $_SESSION['sess_data']['pgv'] = 0;
    $_SESSION['sess_data']['usrid'] = $usrid;
}

/** Read a numeric admin property. */
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

$_SESSION['sess_data']['mmax']    = 0;
$_SESSION['sess_data']['rate']    = 0.5;
$_SESSION['sess_data']['ref']     = 0;
$_SESSION['sess_data']['surfing'] = rand(9999, 9999999999);
$_SESSION['sess_data']['from']    = md5($_SESSION['sess_data']['surfing']);

$next = $_GET['next'] ?? '';
if ($next !== '' && isset($_SESSION['sess_data']['surf_encoder_vals'])
    && $next === md5($_SESSION['sess_data']['surf_encoder_vals'])) {
    $s_bar_url = 'surfbarvisitor.php?PHPSESSID=' . session_id() . '&vc_val=' . urlencode($next);
} else {
    $s_bar_url = 'surfbarvisitor.php?PHPSESSID=' . session_id()
        . '&vc_val=begin&coder=' . md5($_SESSION['sess_data']['from']);
}

$title_esc = htmlspecialchars($title);
echo "<html>\n<head>\n<title>$title_esc's Trial Surf</title>\n"
    . "<link rel=stylesheet type=text/css href=" . htmlspecialchars($self_url) . "style.css>\n</head>\n"
    . "<frameset rows=90,* border=0>"
    . "<frame marginheight=0 marginwidth=0 scrolling=no noresize border=0 src=\"" . htmlspecialchars($s_bar_url) . "\">"
    . "<frame marginheight=0 marginwidth=0 scrolling=auto noresize border=0 src=/target.php>"
    . "</frameset>\n</html>";
exit;
