<?php
/**
 * bonus.php - Credits a surf-contest "bonus credits" win to the member.
 * Migrated to the shared mysqli layer and made PHP 8 warning-safe.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_name('TrafExchange');
    session_start();
}

include('vars.php');
include('db.php');
include('headfoot.php');
include('auth.php');

db_connect();

$user  = checkauth();
$usrid = (int) $user['id'];

$next     = $_GET['next'] ?? '';
$sess     = $_SESSION['sess_data'] ?? array();
$expected = md5((string) ($sess['surf_encoder_vals'] ?? ''));

if (($sess['won'] ?? '') !== 'really' || $next !== $expected) {
    header('Location: ' . $self_url . 'surf.php?next=' . urlencode($next));
    exit;
}
unset($_SESSION['sess_data']['won']);

$contex = (float) db_scalar("SELECT value FROM adminprops WHERE field = 'contex' LIMIT 1", '', array(), 0);
if ($contex == 0) {
    header('Location: ' . $self_url . 'surf.php?next=' . urlencode($next));
    exit;
}
$contey = db_scalar("SELECT value FROM adminprops WHERE field = 'contey' LIMIT 1", '', array(), 0);

if ($contey != ($sess['contey'] ?? null)) {
    $_SESSION['sess_data']['contey'] = $contey;
}
if ($contex != ($sess['contex'] ?? null)) {
    header('Location: ' . $self_url . 'surf.php?next=' . urlencode($next));
    exit;
}

$award = (float) ($_SESSION['sess_data']['contey'] ?? 0);

db()->begin_transaction();
$ok = db_write('UPDATE user SET credits = credits + ?, sb_credits = sb_credits + ? WHERE id = ?',
    'ddi', array($award, $award, $usrid));
if ($ok === false) {
    db()->rollback();
} else {
    db_write("UPDATE adminprops SET value = value - ? WHERE field = 'surplu'", 'd', array($award));
    db()->commit();
}

secheader();
echo "<h4>Bonus Credits Won!</h4>
<p>Congratulations! <b>" . htmlspecialchars($award) . " Free Credits</b> were added to your account!</p>\n"
    . "<p><a href=" . htmlspecialchars($self_url) . "surf.php?next=" . htmlspecialchars($next) . ">Continue Back To Surf</a><br>
<a href=" . htmlspecialchars($self_url) . "members/>Go To Member Area</a></p>\n";
secfooter();
exit;
