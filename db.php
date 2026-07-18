<?php
/**
 * db.php - Robust shared database layer for the Traffic Exchange.
 *
 * The original script was written against the ext/mysql (mysql_*) API, which
 * was removed in PHP 7. This layer provides a single, reused mysqli connection
 * plus small helpers so the surf / earn-credit subsystem runs safely on modern
 * PHP (7/8+) with prepared statements and consistent error handling.
 *
 * Include order:  session -> vars.php -> db.php -> auth.php / headfoot.php
 *
 * It exposes a global $mysqli (an instance of TE_MySQLi, a thin mysqli subclass
 * that also provides a mysql_result()-style ->result() helper so already
 * migrated files such as headfoot.php keep working unchanged).
 */

if (!defined('TE_DB_LOADED')) {
    define('TE_DB_LOADED', true);

    /**
     * mysqli subclass adding a legacy-style result() accessor.
     */
    class TE_MySQLi extends mysqli
    {
        /**
         * Fetch a single value, emulating the old mysql_result().
         *
         * @param mysqli_result|null $res   Result set.
         * @param int                $row   Zero-based row offset.
         * @param int|string         $field Column index or name.
         * @return mixed|null
         */
        public function result($res, $row = 0, $field = 0)
        {
            if (!($res instanceof mysqli_result) || $res->num_rows === 0) {
                return null;
            }
            if ($row < 0 || $row >= $res->num_rows) {
                return null;
            }
            $res->data_seek($row);
            $data = $res->fetch_array(MYSQLI_BOTH);
            if ($data === null) {
                return null;
            }
            return array_key_exists($field, $data) ? $data[$field] : null;
        }
    }

    /**
     * Establish (once) the shared connection and store it in $GLOBALS['mysqli'].
     */
    function db_connect()
    {
        if (isset($GLOBALS['mysqli']) && $GLOBALS['mysqli'] instanceof mysqli) {
            return $GLOBALS['mysqli'];
        }

        global $db_host, $db_user, $db_pwd, $db_name;

        // We handle errors explicitly rather than via exceptions so that a
        // transient DB problem degrades gracefully instead of leaking a stack
        // trace to surfers.
        if (function_exists('mysqli_report')) {
            mysqli_report(MYSQLI_REPORT_OFF);
        }

        $conn = @new TE_MySQLi($db_host, $db_user, $db_pwd, $db_name);
        if ($conn->connect_errno) {
            error_log('Traffic-Exchange DB connect failed: ' . $conn->connect_error);
            http_response_code(503);
            exit('Service temporarily unavailable. Please try again in a moment.');
        }
        @$conn->set_charset('utf8mb4');

        $GLOBALS['mysqli'] = $conn;
        return $conn;
    }

    /** @return TE_MySQLi */
    function db()
    {
        return db_connect();
    }

    /**
     * Run a query with no user input. Returns the mysqli_result / bool, or
     * false on error (errors are logged, never echoed).
     */
    function db_query($sql)
    {
        $res = @db()->query($sql);
        if ($res === false) {
            error_log('Traffic-Exchange query failed: ' . db()->error . ' | SQL: ' . $sql);
        }
        return $res;
    }

    /**
     * Prepare + execute a parameterised statement safely.
     *
     * @param string $sql   SQL with ? placeholders.
     * @param string $types e.g. "si" (s=string, i=int, d=double, b=blob).
     * @param array  $params Bound parameters.
     * @return mysqli_stmt|false
     */
    function db_stmt($sql, $types = '', array $params = array())
    {
        $stmt = @db()->prepare($sql);
        if ($stmt === false) {
            error_log('Traffic-Exchange prepare failed: ' . db()->error . ' | SQL: ' . $sql);
            return false;
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        if (!$stmt->execute()) {
            error_log('Traffic-Exchange execute failed: ' . $stmt->error . ' | SQL: ' . $sql);
            $stmt->close();
            return false;
        }
        return $stmt;
    }

    /**
     * Fetch a single associative row via a parameterised query, or null.
     */
    function db_row($sql, $types = '', array $params = array())
    {
        $stmt = db_stmt($sql, $types, $params);
        if ($stmt === false) {
            return null;
        }
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row;
    }

    /**
     * Fetch a single scalar value via a parameterised query.
     */
    function db_scalar($sql, $types = '', array $params = array(), $default = null)
    {
        $stmt = db_stmt($sql, $types, $params);
        if ($stmt === false) {
            return $default;
        }
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_row() : null;
        $stmt->close();
        return ($row && array_key_exists(0, $row) && $row[0] !== null) ? $row[0] : $default;
    }

    /**
     * Execute a parameterised write (INSERT/UPDATE/DELETE).
     *
     * @return int|false Affected rows on success, false on failure.
     */
    function db_write($sql, $types = '', array $params = array())
    {
        $stmt = db_stmt($sql, $types, $params);
        if ($stmt === false) {
            return false;
        }
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected;
    }
}
