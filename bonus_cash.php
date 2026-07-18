<?php
/**
 * bonus_cash.php - Credits a surf-contest "bonus cash" win to the member.
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

if (($sess['ccwon'] ?? '') !== 'ccreallycc' || $next !== $expected) {
    header('Location: ' . $self_url . 'surf.php?next=' . urlencode($next));
    exit;
}
unset($_SESSION['sess_data']['ccwon']);

$contcx = (float) db_scalar("SELECT value FROM adminprops WHERE field = 'contcx' LIMIT 1", '', array(), 0);
if ($contcx == 0) {
    header('Location: ' . $self_url . 'surf.php?next=' . urlencode($next));
    exit;
}
$contcy = db_scalar("SELECT value FROM adminprops WHERE field = 'contcy' LIMIT 1", '', array(), 0);

if ($contcy != ($sess['contcy'] ?? null)) {
    $_SESSION['sess_data']['contcy'] = $contcy;
}
if ($contcx != ($sess['contcx'] ?? null)) {
    header('Location: ' . $self_url . 'surf.php?next=' . urlencode($next));
    exit;
}

$award   = (float) ($_SESSION['sess_data']['contcy'] ?? 0);
$yearis  = (int) date('Y');
$monthis = (int) date('m');

db()->begin_transaction();

$has = db_scalar('SELECT usrid FROM monthly_stats WHERE usrid = ? AND yearis = ? AND monthis = ? LIMIT 1',
    'iii', array($usrid, $yearis, $monthis));
if ($has === null) {
    db_write('INSERT INTO monthly_stats (usrid, sbcash_earned, tot_owed, monthis, yearis) VALUES (?, ?, ?, ?, ?)',
        'iddii', array($usrid, $award, $award, $monthis, $yearis));
} else {
    db_write('UPDATE monthly_stats SET sbcash_earned = sbcash_earned + ?, tot_owed = tot_owed + ?
              WHERE usrid = ? AND yearis = ? AND monthis = ?',
        'ddiii', array($award, $award, $usrid, $yearis, $monthis));
}

$ok = db_write('UPDATE user SET roi_cash = roi_cash + ?, lifetime_cash = lifetime_cash + ?, sb_cash = sb_cash + ?
                WHERE id = ?', 'dddi', array($award, $award, $award, $usrid));
if ($ok === false) {
    db()->rollback();
} else {
    db_write("UPDATE adminprops SET value = value - ? WHERE field = 'csurpl'", 'd', array($award));
    db()->commit();
}

secheader();
echo "<h4>Bonus Cash Won!</h4>
<p>Congratulations! <b>\$" . htmlspecialchars($award) . " Cash</b> was just added to your account!</p>\n"
    . "<p><a href=" . htmlspecialchars($self_url) . "surf.php?next=" . htmlspecialchars($next) . ">Continue back To Surf</a><br>
<a href=" . htmlspecialchars($self_url) . "members/>Go To Member Area</a></p>\n";
secfooter();
exit;
