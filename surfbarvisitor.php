<?php
/**
 * surfbarvisitor.php - Guest (trial) surf toolbar. Shows rotating sites and
 * records trial views under the reserved usrid 0, but awards no real account
 * credits. Migrated to the shared mysqli layer and made PHP 8 warning-safe.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_name('TrafExchange');
    session_start();
}
include('vars.php');
include('db.php');
include('headfoot.php');

db_connect();

$my_acctype = 1;
$daily_max  = 9999999999;
$lastsurfed_time = '00:00:00';

$date     = date('Y-m-d');
$time_now = date('H:i:s');
$hour     = date('Y-m-d:H');

$sess   = $_SESSION['sess_data'] ?? array();
$reftim = max(1, (int) ($sess['reftim'] ?? 6));
$rate   = (float) ($sess['rate'] ?? 0.5);
$negact = (int) ($sess['negact'] ?? 0);

$credit_me = 'yes';
$encoder_token = md5((string) ($sess['surf_encoder_vals'] ?? ''));

$vf_time_amt = date('H:i:s', strtotime("$lastsurfed_time + $reftim seconds"));
if ($vf_time_amt > $time_now) {
    $wait = $reftim - (time() - (int) ($sess['time'] ?? 0));
    if ($wait > $reftim || $wait <= 0) {
        $wait = $reftim;
    }
    echo "<head><title>" . htmlspecialchars($title) . "</title>"
        . "<meta http-equiv=\"Refresh\" content=\"$wait;URL=" . htmlspecialchars($self_url)
        . "surfbarvisitor.php?vc_val=$encoder_token\"></head><body>"
        . "<b>Surfing too fast! Re-connecting in $wait seconds...</b></body></html>";
    exit;
}

$vc_val = $_GET['vc_val'] ?? '';
$coder  = $_GET['coder'] ?? '';
if ($vc_val === 'begin' && $coder === md5((string) ($sess['from'] ?? ''))) {
    $credit_me = 'no';
    $why = 'Starting/Resuming Session';
} elseif ($vc_val !== $encoder_token) {
    header('Location: ' . $self_url . '?error=inv-access');
    exit;
}

if (!isset($sess['time']) || (time() - (int) $sess['time']) >= $reftim) {
    $_SESSION['sess_data']['time'] = time();
    $sess['time'] = $_SESSION['sess_data']['time'];
} else {
    $wait = $reftim - (time() - (int) $sess['time']);
    echo "<head><title>" . htmlspecialchars($title) . "</title>"
        . "<meta http-equiv=\"Refresh\" content=\"$wait;URL=" . htmlspecialchars($self_url)
        . "surfbarvisitor.php?vc_val=$encoder_token\"></head><body>"
        . "<b>Surfing too fast! Re-connecting in $wait seconds...</b></body></html>";
    exit;
}

db_write('UPDATE site SET hour = ?, cth = 0 WHERE hour != ? AND cph != 0', 'ss', array($hour, $hour));

$site_sql = 'SELECT id, url FROM site WHERE usrid != 0 AND state = ?';
$types    = 's';
$params   = array('Enabled');
if ($negact == 0) {
    $site_sql .= ' AND credits >= 1';
}
$site_sql .= ' AND (cth < cph OR cph = 0) ORDER BY rand() LIMIT 1';
$site = db_row($site_sql, $types, $params);

if (!$site) {
    $url    = $default_site;
    $siteid = 0;
} else {
    $url    = $site['url'];
    $siteid = (int) $site['id'];
    if ($siteid != 0) {
        db_write('UPDATE site SET credits = credits - 1, totalhits = totalhits + 1,
                  hitslastmail = hitslastmail + 1, cth = cth + 1 WHERE id = ?', 'i', array($siteid));
        $exists = db_scalar('SELECT num FROM 7statsite WHERE siteid = ? AND date = ? LIMIT 1',
            'is', array($siteid, $date));
        if ($exists === null) {
            db_write('INSERT INTO 7statsite (siteid, date, last_hit_time, num) VALUES (?, ?, ?, 1)',
                'iss', array($siteid, $date, $time_now));
        } else {
            db_write('UPDATE 7statsite SET last_hit_time = ?, num = num + 1 WHERE siteid = ? AND date = ?',
                'sis', array($time_now, $siteid, $date));
        }
    }
}

$_SESSION['sess_data']['surf_encoder_vals'] = md5(rand(10000, 100000000));
$next_token = md5($_SESSION['sess_data']['surf_encoder_vals']);
$_SESSION['sess_data']['pgv'] = (int) ($sess['pgv'] ?? 0) + 1;
$delay = $reftim;

$my_crds_today = 0;
if ($credit_me == 'yes') {
    $why = 'Credits Per View: <b>' . htmlspecialchars($rate) . '</b>';
    $stat = db_row("SELECT num, time, received_pay FROM 7stat WHERE usrid = 0 AND date = ? LIMIT 1",
        's', array($date));
    if ($stat === null) {
        db_write('INSERT INTO 7stat (usrid, date, time, pg_views, num) VALUES (0, ?, ?, 1, ?)',
            'ssd', array($date, $time_now, $rate));
        $my_crds_today = $rate;
    } else {
        $c_today   = (float) $stat['num'];
        $laccess   = (string) $stat['time'];
        $thevftime = date('H:i:s', strtotime("$laccess + $reftim seconds"));
        if ($thevftime > $time_now) {
            $wait = ($reftim <= 0) ? 10 : $reftim;
            echo "<head><title>" . htmlspecialchars($title) . "</title>"
                . "<meta http-equiv=\"Refresh\" content=\"$wait;URL=" . htmlspecialchars($self_url)
                . "surfbarvisitor.php?vc_val=$next_token\"></head><body>"
                . "<b>Cheating Timer! Re-connecting in $wait seconds...</b></body></html>";
            exit;
        } elseif ($c_today >= $daily_max) {
            echo 'Sorry you have earned your daily maximum of ' . htmlspecialchars($daily_max)
                . " credits!<br />Please return tomorrow!<br /><br />"
                . '<a href="' . htmlspecialchars($self_url) . '" target="_top">Login to your account here.</a>';
            session_destroy();
            exit;
        } else {
            db_write('UPDATE 7stat SET time = ?, pg_views = pg_views + 1, num = num + ? WHERE usrid = 0 AND date = ?',
                'sds', array($time_now, $rate, $date));
            $my_crds_today = $c_today + $rate;
        }
    }
    $_SESSION['sess_data']['cts'] = (float) ($sess['cts'] ?? 0) + $rate;
    $_SESSION['sess_data']['sts'] = (int) ($sess['sts'] ?? 0) + 1;
}

$sts = (int) ($_SESSION['sess_data']['sts'] ?? 0);
$title_esc = htmlspecialchars($title);
$self_esc  = htmlspecialchars($self_url);

$extra_js = '';
if ((int) ($sess['mmax'] ?? 0) === 0) {
    $extra_js = "top.window.moveTo(0,0);\nif (document.all) {\ntop.window.resizeTo(screen.availWidth,screen.availHeight);\n}\n"
        . "else if (document.layers||document.getElementById) {\nif\n(top.window.outerHeight<screen.availHeight||top.window.outerWidth<screen.availWidth){\n"
        . "top.window.outerHeight = screen.availHeight;\ntop.window.outerWidth = screen.availWidth;\n}\n}\nwindow.focus();\n";
}

echo "<html>\n<head>\n<title>$title_esc</title>\n<link rel=stylesheet type=text/css href={$self_esc}bar.css>\n";
echo "<script language=\"JavaScript\">\n<!--\ndefaultStatus = '$title_esc';\nif (parent.location.href == self.location.href) {window.location.href = 'surf.php';}\n
var counter=1+parseInt($delay);
var paused=0;

function start_time()
{
do_count();
}

function do_count()
{
   if (paused==0){
	counter--;
   }
	if (counter>=0) {document.f.stimer.value=counter;
	setTimeout(\"do_count()\",1000);
	}
   if (counter<0)
   {
      document.f.submit();
   }
}

function pause_time()
{
   paused=1-paused;
   if (paused==1) {document.f.stopgo.value='« Start Surf »';} else {document.f.stopgo.value='« Pause Surf »';}
}

function open_w(imf){

     window.open(imf);
	 return false;

}
$extra_js
//-->\n</script>\n";

echo "</head>\n<body onLoad=\"do_count();\"  bgcolor=\"#000000\">\n";
echo "<script language=JavaScript>window.status=\"$title_esc\";top.frames[1].location.href = \"" . addslashes($url) . "\";</script>\n";
echo "<script language=JavaScript>if (document.all) document.body.onmousedown=new Function(\"if (event.button==2||event.button==3)alert('Sorry, right click is disabled here!')\")</script>\n";

echo "<form name=f method=GET><input type=hidden name=\"" . session_name() . "\" value=" . session_id() . ">"
    . "<input type=hidden name=vc_val value=$next_token>
        <table width=\"100%\" border=\"0\" cellspacing=\"0\" cellpadding=\"2\"><tr>
<td width=\"20%\" valign=top><div align=\"center\">
<img src={$self_esc}images/surflogo.jpg border=0>
</div></td>
<td width=\"40%\" valign=top><div align=\"center\">";
echo "<input type=button name=\"stimer\" size=4 style=\"border: 0px;border-style: none; Color: #FFFFFF; background-color: #000000; text-align: center; top: 0; position: relative; float: center;\" onClick=\"return false;\"> "
    . "<input name=\"stopgo\" value=\"« Pause Surf »\" type=\"button\" style=\"border: 0px; border-style: none; Color: #FFFFFF; background-color: #000000; width:100px; height:20px; text-align: center; top: 0; position: relative; float: center; cursor: hand;\" onClick=\"pause_time();\"><br>
        [<a href={$self_esc}signup.php target=_top>Open Account</a>] [<a href=\"#\" onClick=\"open_w('" . addslashes($url) . "');\">Open Site</a>] [<b>Site ID: $siteid</b>]";

echo "</div></td><td width=\"40%\" valign=top><center>
ID #: <b>" . htmlspecialchars((string) ($sess['usrid'] ?? 'Guest')) . "</b> | $why | Credits This Surf: <b>" . htmlspecialchars($_SESSION['sess_data']['cts'] ?? 0) . "</b> | Credits Today: <b>" . htmlspecialchars($my_crds_today) . "</b><br>
$surf_ban_rotator</center></td></tr></table>\n";

db_write("UPDATE adminprops SET value = value + 1 WHERE field = 'surplu'");
echo "</form>\n</body>\n</html>";
exit;
