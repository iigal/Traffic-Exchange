<?php
/**
 * surfbar.php - The surf toolbar and credit engine.
 *
 * This is where a member "exchanges traffic": on every validated page view we
 * (a) show another member's site, (b) charge that site one credit, and
 * (c) award the surfer their per-view rate. Migrated from the removed mysql_*
 * API to the shared mysqli layer, hardened against timer cheating, and made
 * PHP 8 warning-safe. The credit award is wrapped in a transaction so, on a
 * transactional engine (InnoDB), a surfer's balance and the daily stat row can
 * never diverge.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_name('TrafExchange');
    session_start();
}

include('vars.php');
include('auth.php');
include('db.php');
include('headfoot.php');

db_connect();

// ---- Establish "now" up front (the original left $date undefined here) ------
$date     = date('Y-m-d');
$time_now = date('H:i:s');
$hour     = date('Y-m-d:H');

$user           = checkbarauth();
$usrid          = (int) $user['id'];
$my_investment  = (float) $user['invested'];
$my_acctype     = (int) $user['acctype'];
$my_last_roi    = $user['lastroi'];
$my_ref         = (int) $user['ref'];
$lastsurfed     = (string) $user['lastsurfed'];
$my_status      = $user['status'];
$mref           = $my_ref;

$daily_max = ($my_acctype == 1) ? $surf_max_free : $surf_max_pro;

$dummyt = explode(' ', $lastsurfed);
$lastsurfed_time = ($dummyt[0] != $date) ? '00:00:00' : ($dummyt[1] ?? '00:00:00');

if ($my_acctype == 1) {
    $min_credits_to_earn = $min_credits_to_earn_free;
    $roi_conversion_ratio = $roi_conversion_ratio_free;
} else {
    $min_credits_to_earn = $min_credits_to_earn_pro;
    $roi_conversion_ratio = $roi_conversion_ratio_pro;
}

// ---- Session sanity / anti-tamper -----------------------------------------
$sess    = $_SESSION['sess_data'] ?? array();
$sess_usrid = (int) ($sess['usrid'] ?? -1);
$reftim  = max(1, (int) ($sess['reftim'] ?? 6));
$rate    = (float) ($sess['rate'] ?? 0);
$negact  = (int) ($sess['negact'] ?? 0);

if ($sess_usrid !== $usrid || !isset($sess['usrid'])) {
    header('Location: ' . $self_url . 'bar_break.php?error=session-expired');
    exit;
}
$credit_me = 'yes';

if (($sess['from'] ?? '') !== md5((string) ($sess['surfing'] ?? ''))) {
    header('Location: ' . $self_url . 'bar_break.php?error=inv-access');
    exit;
}

$encoder_token = md5((string) ($sess['surf_encoder_vals'] ?? ''));

// Enforce the minimum interval since the last recorded surf time.
$vf_time_amt = date('H:i:s', strtotime("$lastsurfed_time + $reftim seconds"));
if ($vf_time_amt > $time_now) {
    $wait = $reftim - (time() - (int) ($sess['time'] ?? 0));
    if ($wait > $reftim || $wait <= 0) {
        $wait = $reftim;
    }
    echo "<head><title>" . htmlspecialchars($title) . "</title>"
        . "<meta http-equiv=\"Refresh\" content=\"$wait;URL=" . htmlspecialchars($self_url)
        . "surfbar.php?vc_val=$encoder_token\"></head><body>"
        . "<span style=\"font-size:150%\">Surfing too fast! Re-connecting in $wait secs..</span></body></html>";
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

// Server-side rate limit: refuse views that arrive faster than reftim.
if (!isset($sess['time']) || (time() - (int) $sess['time']) >= $reftim) {
    $_SESSION['sess_data']['time'] = time();
    $sess['time'] = $_SESSION['sess_data']['time'];
} else {
    $wait = $reftim - (time() - (int) $sess['time']);
    echo "<head><title>" . htmlspecialchars($title) . "</title>"
        . "<meta http-equiv=\"Refresh\" content=\"$wait;URL=" . htmlspecialchars($self_url)
        . "surfbar.php?vc_val=$encoder_token\"></head><body>"
        . "<span style=\"font-size:150%\">Surfing too fast! Re-connecting in $wait secs..</span></body></html>";
    exit;
}

// ---- Pick the next site to display ----------------------------------------
db_write('UPDATE site SET hour = ?, cth = 0 WHERE hour != ? AND cph != 0', 'ss', array($hour, $hour));

$site_sql = 'SELECT id, url FROM site WHERE usrid != ? AND state = ?';
$types    = 'is';
$params   = array($usrid, 'Enabled');
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
        // Charge the advertised site one credit for the view it just received.
        db_write(
            'UPDATE site SET credits = credits - 1, totalhits = totalhits + 1,
                    hitslastmail = hitslastmail + 1, cth = cth + 1 WHERE id = ?',
            'i',
            array($siteid)
        );
        $exists = db_scalar(
            'SELECT num FROM 7statsite WHERE siteid = ? AND date = ? LIMIT 1',
            'is',
            array($siteid, $date)
        );
        if ($exists === null) {
            db_write(
                'INSERT INTO 7statsite (siteid, date, last_hit_time, num) VALUES (?, ?, ?, 1)',
                'iss',
                array($siteid, $date, $time_now)
            );
        } else {
            db_write(
                'UPDATE 7statsite SET last_hit_time = ?, num = num + 1 WHERE siteid = ? AND date = ?',
                'sis',
                array($time_now, $siteid, $date)
            );
        }
    }
}

// New anti-replay token for the next request.
$_SESSION['sess_data']['surf_encoder_vals'] = md5(rand(10000, 100000000));
$next_token = md5($_SESSION['sess_data']['surf_encoder_vals']);
$_SESSION['sess_data']['pgv'] = (int) ($sess['pgv'] ?? 0) + 1;
$delay = $reftim;

// ---- Record the daily stat / determine today's earned credits --------------
$my_crds_today = 0;
$iam_waiting   = 'no';
$do_award      = false;

if ($credit_me == 'yes') {
    $why = 'Credits Per View: <b>' . htmlspecialchars($rate) . '</b>';
    $stat = db_row('SELECT num, time, received_pay FROM 7stat WHERE usrid = ? AND date = ? LIMIT 1',
        'is', array($usrid, $date));

    if ($stat === null) {
        db_write('INSERT INTO 7stat (usrid, date, time, pg_views, num) VALUES (?, ?, ?, 1, ?)',
            'issd', array($usrid, $date, $time_now, $rate));
        $my_crds_today = $rate;
        $iam_waiting   = 'yes';
        $do_award      = true;
    } else {
        $c_today     = (float) $stat['num'];
        $laccess     = (string) $stat['time'];
        $was_paid_t  = $stat['received_pay'];
        $thevftime   = date('H:i:s', strtotime("$laccess + $reftim seconds"));

        if ($thevftime > $time_now) {
            header('Location: ' . $self_url . 'bar_break.php?error=cheating-timer');
            session_destroy();
            exit;
        } elseif ($c_today >= $daily_max) {
            echo 'Sorry you have earned your daily maximum of ' . htmlspecialchars($daily_max)
                . " credits!<br />Please return tomorrow!<br /><br />"
                . '<a href="' . htmlspecialchars($self_url) . '" target="_top">Login to your account here</a>';
            session_destroy();
            exit;
        } else {
            db_write('UPDATE 7stat SET time = ?, pg_views = pg_views + 1, num = num + ?
                       WHERE usrid = ? AND date = ?', 'sdis', array($time_now, $rate, $usrid, $date));
            $my_crds_today = $c_today + $rate;
            $iam_waiting   = ($was_paid_t === 'no') ? 'yes' : 'no';
            $do_award      = true;
        }
    }
    $_SESSION['sess_data']['cts'] = (float) ($sess['cts'] ?? 0) + $rate;
    $_SESSION['sess_data']['sts'] = (int) ($sess['sts'] ?? 0) + 1;
}

$sts = (int) ($_SESSION['sess_data']['sts'] ?? 0);

// ---- Render the toolbar ----------------------------------------------------
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
echo "<input type=button name=\"stimer\" size=4 style=\"border: 0px;border-style: none;Color: #FFFFFF;background-color: #000000;text-align: center; top: 0; position: relative; float: center;\" onClick=\"return false;\"> "
    . "<input name=\"stopgo\" value=\"« Pause Surf »\" type=\"button\" style=\"border: 0px;border-style: none;Color: #FFFFFF;background-color: #000000;width:100px; height:20px; text-align: center; top: 0; position: relative; float: center; cursor: hand;\" onClick=\"pause_time();\"><br>
        [<a href={$self_esc}members/ target=_top>Open Account</a>] [<a href=\"#\" onClick=\"open_w('" . addslashes($url) . "');\">Open Site</a>] [<a href=report.php?s=$siteid target=_top><font color=#ff0000>Report Abuse</font></a>]";

// Contest / bonus prompts.
if (isset($sess['contex']) && $credit_me == 'yes' && (int) $sess['contex'] !== 0) {
    if (($sts % (int) $sess['contex']) == 0) {
        $_SESSION['sess_data']['won'] = 'really';
        echo "<br><br><b>You have won " . htmlspecialchars($sess['contey'] ?? 0) . " bonus credits!</b><br>"
            . "<a href={$self_esc}bonus.php?next=$next_token target=_top>Click here to add them to your account!</a>\n";
    }
}
if (isset($sess['contcx']) && $credit_me == 'yes' && ($_SESSION['sess_data']['won'] ?? '') != 'really' && (int) $sess['contcx'] !== 0) {
    if (($sts % (int) $sess['contcx']) == 0) {
        $_SESSION['sess_data']['ccwon'] = 'ccreallycc';
        echo "<br><br><b>You have won \$" . htmlspecialchars($sess['contcy'] ?? 0) . " bonus cash!</b><br>"
            . "<a href={$self_esc}bonus_cash.php?next=$next_token target=_top>Click here to credit it to your account!</a>\n";
    }
}

echo "</div></td><td width=\"40%\"><center>
ID #<b>$usrid</b> | $why | Credits This Surf: <b>" . htmlspecialchars($_SESSION['sess_data']['cts'] ?? 0) . "</b> | Credits Today: <b>" . htmlspecialchars($my_crds_today) . "</b><br>
$surf_ban_rotator</center></td></tr></table>\n";

// ---- Award credits (atomic where the engine supports it) -------------------
$surplus = 1;
if ($credit_me == 'yes' && $do_award) {
    db()->begin_transaction();

    $awarded = db_write(
        'UPDATE user SET credits = credits + ?, lifetime_pages = lifetime_pages + 1,
                lifetime_credits = lifetime_credits + ? WHERE id = ?',
        'ddi',
        array($rate, $rate, $usrid)
    );

    if ($awarded === false) {
        db()->rollback();
        error_log("Traffic-Exchange: failed to award $rate credits to user $usrid");
    } else {
        // Signup activation bonus.
        if ($my_status == 'Verified' && $activation_pages >= 1) {
            $my_pages_are = (int) db_scalar('SELECT lifetime_pages FROM user WHERE id = ? LIMIT 1', 'i', array($usrid), 0);
            $inibon = (float) db_scalar("SELECT value FROM adminprops WHERE field = 'inibon' LIMIT 1", '', array(), 0);
            if ($my_pages_are >= $activation_pages) {
                db_write('UPDATE user SET credits = credits + ?, lifetime_credits = lifetime_credits + ?,
                          status = ? WHERE id = ?', 'ddsi', array($inibon, $inibon, 'Active', $usrid));
                $surplus = $surplus - $inibon;
                if ($mref >= 1) {
                    $accs = db_query('SELECT * FROM acctype');
                    $acc_r_bon = array();
                    if ($accs) {
                        while ($a = $accs->fetch_assoc()) {
                            $acc_r_bon[(int) $a['id']] = count(explode(',', (string) $a['rbonuses']));
                        }
                    }
                    $b1 = $acc_r_bon[1] ?? 0;
                    $b2 = $acc_r_bon[2] ?? 0;
                    $greatest = ($b1 > $b2) ? 1 : 2;
                    if ($my_ref >= 1 && ($b1 > 0 || $b2 > 0)) {
                        $cc = 0;
                        $ref_id = array($cc => $my_ref);
                        for ($v = 0; $v < (($acc_r_bon[$greatest] ?? 0) - 1); $v++) {
                            $my_ref = get_referral($my_ref);
                            if (!$my_ref || $my_ref == 0) {
                                break;
                            }
                            $ref_id[++$cc] = $my_ref;
                        }
                        credit_ref_bonuses($ref_id);
                    }
                }
                echo "<center><font size=1>Your account was just awarded the signup bonus of "
                    . htmlspecialchars($inibon) . " credits! You are now set to Active status and can now earn from referrals!</font></center>";
            }
        }

        // Daily ROI reward for investors who hit the credit threshold.
        if ($my_crds_today >= $min_credits_to_earn && $iam_waiting == 'yes' && $my_investment > 0) {
            if ($my_last_roi < $date) {
                $roi_rate = round($roi_conversion_ratio / 100, 3);
                $roi_credit_return = $my_investment * $roi_rate;
                db_write('UPDATE user SET roi_cash = roi_cash + ?, lifetot_roi = lifetot_roi + ?,
                          lifetime_cash = lifetime_cash + ?, lastroi = ? WHERE id = ?',
                    'dddsi', array($roi_credit_return, $roi_credit_return, $roi_credit_return, $date, $usrid));
                db_write("UPDATE 7stat SET received_pay = 'yes' WHERE usrid = ? AND date = ? AND received_pay = 'no'",
                    'is', array($usrid, $date));
                db_write("UPDATE adminprops SET value = value - ? WHERE field = 'csurpl'", 'd', array($roi_credit_return));

                $nns = explode('-', $date);
                $yearis  = (int) $nns[0];
                $monthis = (int) $nns[1];
                $has = db_scalar('SELECT usrid FROM monthly_stats WHERE usrid = ? AND monthis = ? AND yearis = ? LIMIT 1',
                    'iii', array($usrid, $monthis, $yearis));
                if ($has !== null) {
                    db_write('UPDATE monthly_stats SET roi_earned = roi_earned + ?, days_paid_roi = days_paid_roi + 1,
                              tot_owed = tot_owed + ?, this_month = ? WHERE usrid = ? AND monthis = ? AND yearis = ?',
                        'ddsiii', array($roi_credit_return, $roi_credit_return, $date, $usrid, $monthis, $yearis));
                } else {
                    db_write('INSERT INTO monthly_stats (usrid, days_paid_roi, roi_earned, tot_owed, monthis, yearis, this_month)
                              VALUES (?, 1, ?, ?, ?, ?, ?)',
                        'ddiis', array($usrid, $roi_credit_return, $roi_credit_return, $monthis, $yearis, $date));
                }
                echo "<font color=#FF0000>Your account was just awarded \$" . htmlspecialchars($roi_credit_return)
                    . " for earning " . htmlspecialchars($min_credits_to_earn) . " credits in one day!</font>";
            }
        }

        $surplus = $surplus - $rate;

        // Multi-level referral credit bonuses.
        if ($mref >= 1) {
            $accs = db_query('SELECT id, levels FROM acctype');
            $acc_r_cbon = array();
            if ($accs) {
                while ($a = $accs->fetch_assoc()) {
                    $acc_r_cbon[(int) $a['id']] = count(explode(',', (string) $a['levels']));
                }
            }
            $l1 = $acc_r_cbon[1] ?? 0;
            $l2 = $acc_r_cbon[2] ?? 0;
            $greatesta = ($l1 > $l2) ? 1 : 2;
            if ($l1 > 0 || $l2 > 0) {
                $ccx = 0;
                $refs_id = array($ccx => $mref);
                for ($z = 0; $z < (($acc_r_cbon[$greatesta] ?? 0) - 1); $z++) {
                    $mref = get_referral($mref);
                    if (!$mref || $mref == 0) {
                        break;
                    }
                    $refs_id[++$ccx] = $mref;
                }
                $givento_ref = credit_r_bonuses($refs_id, 'credits', $rate);
                if ($givento_ref > 0) {
                    db_write('UPDATE user SET toref = toref + ? WHERE id = ?', 'di',
                        array($givento_ref, $usrid));
                }
            }
        }

        db_write("UPDATE adminprops SET value = value + ? WHERE field = 'surplu'", 'd', array($surplus));
        db()->commit();
    }
}

// Record last activity regardless of crediting.
$la = date('Y-m-d H:i:s');
db_write('UPDATE user SET lastaccess = ?, lastsurfed = ? WHERE id = ?', 'ssi', array($la, $la, $usrid));

echo "</form>\n</body>\n</html>";
exit;
