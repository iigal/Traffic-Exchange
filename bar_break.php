<?php
/**
 * bar_break.php - Surf error / session-break page.
 * Destroys the surf session and bounces the user back to the site, showing the
 * (escaped) reason. Rewritten with full PHP tags; the query string is now
 * HTML-escaped to close an XSS hole.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_name('TrafExchange');
    session_start();
}
include('vars.php');
flush();
header('Cache-control: private');
$_SESSION = array();
session_destroy();

$reason = htmlspecialchars($_SERVER['QUERY_STRING'] ?? '', ENT_QUOTES);
$title_esc = htmlspecialchars($title);
$self_esc  = htmlspecialchars($self_url);
?>
<html>
<head>
<title><?php echo $title_esc; ?> - Surf Error</title>
<meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1">
<script>
<!--
if (window != top) {
top.location.href=location.href;
}
// -->
</script>
<meta http-equiv="Refresh" content="1;URL=<?php echo $self_esc; ?>">
</head>
<body>
<div align="center">
  <table width="100%" border="0">
    <tr>
      <td height="79"><div align="center"><font size="2" face="<?php echo htmlspecialchars($fontface); ?>"><strong><a href="<?php echo $self_esc; ?>login.php" target="_top">Click
          Here to Login</a></strong></font></div>
        </td>
      <td width="69%" valign="top"><div align="center">
          <p><strong><?php echo $reason; ?><br>
            </strong></p>
        </div></td>
    </tr>
  </table>
</div>
</body>
</html>
<?php
exit;
