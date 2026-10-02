<?php
/**
 * Database selection and the SQLite compatibility driver.
 *
 * The legacy MySQL driver remains available while modules are migrated away
 * from mysqli-specific calls. SQLite uses the same public API as Mysql.php.
 */
class DatabaseFactory
{
    public static function get_instance($bothandle)
    {
        static $instances = array();

        if (isset($instances[$bothandle])) {
            return $instances[$bothandle];
        }

        $bot = Bot::get_instance($bothandle);
        $driver = 'mysql';
        $config_file = 'Conf/' . $bot->botname . '.Mysql.conf';
        if (!file_exists($config_file)) {
            $config_file = 'Conf/Mysql.conf';
        }

        // Configuration files intentionally contain legacy variables.
        include $config_file;
        if (isset($db_driver)) {
            $driver = strtolower($db_driver);
        } elseif (isset($database['driver'])) {
            $driver = strtolower($database['driver']);
        }

        if (function_exists('bebot_require_sql_driver')) {
            bebot_require_sql_driver($driver);
        }

        if ($driver === 'sqlite') {
            $instances[$bothandle] = new SQLiteDatabase($bothandle, isset($dpath) ? $dpath : array());
        } else {
            // Keep mysqli as the compatibility path until direct mysqli users
            // in modules have been migrated.
            $instances[$bothandle] = MySQL::get_instance($bothandle);
        }

        return $instances[$bothandle];
    }
}

class SQLiteDatabase
{
    public $CONN = null;
    public $DBASE = '';
    public $bot;
    public $tablenames = array();
    public $master_tablename;
    public $table_prefix;
    public $underscore = '_';
    public $affected_rows = 0;
    private $error_count = 0;
    private $last_error = '';

    public function __construct($bothandle, array $config = array())
    {
        $this->bot = Bot::get_instance($bothandle);
        $this->master_tablename = isset($config['master_tablename'])
            ? str_ireplace('<botname>', strtolower($this->bot->botname), $config['master_tablename'])
            : strtolower($this->bot->botname) . '_tablenames';
        $this->table_prefix = isset($config['table_prefix'])
            ? str_ireplace('<botname>', strtolower($this->bot->botname), $config['table_prefix'])
            : strtolower($this->bot->botname);
        if (isset($config['nounderscore']) && $config['nounderscore']) {
            $this->underscore = '';
        }

        $path = isset($config['path']) ? $config['path'] : 'Data/' . strtolower($this->bot->botname) . '.sqlite';
        $directory = dirname($path);
        if ($directory !== '.' && !is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        $this->DBASE = $path;
        $this->CONN = new PDO('sqlite:' . $path);
        $this->CONN->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->CONN->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->CONN->exec('PRAGMA foreign_keys = ON');

        $this->query(
            'CREATE TABLE IF NOT EXISTS ' . $this->quoteIdentifier($this->master_tablename) .
            ' (internal_name VARCHAR(255) NOT NULL PRIMARY KEY, prefix VARCHAR(100), use_prefix VARCHAR(10) NOT NULL DEFAULT \'false\', schemaversion INTEGER NOT NULL DEFAULT 1)'
        );
        $this->query(
            'CREATE TABLE IF NOT EXISTS table_versions (internal_name VARCHAR(255) NOT NULL PRIMARY KEY, schemaversion INTEGER NOT NULL DEFAULT 1)'
        );
        // Bot::log() can be called while Main modules are still loading, so
        // this table must exist before Main/15_Log.php is instantiated.
        $log_table = $this->get_tablename('log_message', true);
        $this->query(
            'CREATE TABLE IF NOT EXISTS ' . $this->quoteIdentifier($log_table) .
            ' (id INTEGER PRIMARY KEY AUTOINCREMENT, message VARCHAR(500) NOT NULL, first VARCHAR(45) NOT NULL, second VARCHAR(45) NOT NULL, timestamp INTEGER NOT NULL)'
        );
    }

    public function connect($initial = false) { return true; }
    public function close() { $this->CONN = null; }
    public function begin() { return $this->CONN->beginTransaction(); }
    public function commit() { return $this->CONN->commit(); }
    public function rollback() { return $this->CONN->rollBack(); }
    public function driverName() { return 'sqlite'; }
    public function serverVersion() { return $this->CONN->query('SELECT sqlite_version()')->fetchColumn(); }
    public function lastInsertId() { return $this->CONN->lastInsertId(); }
    public function affectedRows() { return isset($this->affected_rows) ? $this->affected_rows : 0; }

    public function real_escape_string($value)
    {
        // Compatibility only. New code should use parameters. Unlike MySQL,
        // SQLite escapes a quote in a string literal by doubling it; a
        // backslash-escaped quote would terminate the literal early.
        return str_replace("'", "''", (string)$value);
    }

    public function escape($value) { return $this->real_escape_string($value); }

    public function select($sql, $params = array(), $result_form = null)
    {
        if (!is_array($params)) {
            $result_form = $params;
            $params = array();
        }
        $schema_rows = $this->select_schema_compatibility($sql);
        if ($schema_rows !== null) {
            return $schema_rows;
        }
        $stmt = $this->prepare($sql, $params);
        // Mysql.php historically defaults to MYSQLI_NUM. Preserve that
        // shape for existing modules unless MYSQLI_ASSOC (or 'assoc') is
        // explicitly requested.
        $numeric = $result_form === null || (defined('MYSQLI_NUM') && $result_form === MYSQLI_NUM);
        if ($result_form === 'assoc' || (defined('MYSQLI_ASSOC') && $result_form === MYSQLI_ASSOC)) {
            $numeric = false;
        }
        $rows = $stmt->fetchAll($numeric ? PDO::FETCH_NUM : PDO::FETCH_ASSOC);
        return $rows;
    }

    private function select_schema_compatibility($sql)
    {
        if (preg_match('/^\s*SELECT\s+LAST_INSERT_ID\s*\(\s*\)(?:\s+AS\s+[A-Za-z_][A-Za-z0-9_]*)?\s*;?\s*$/i', $sql)) {
            return array(array((int)$this->CONN->lastInsertId()));
        }
        // MySQL's EXPLAIN table form is used by legacy modules as a
        // DESCRIBE-like schema probe. SQLite requires EXPLAIN to be followed
        // by a complete statement, so expose the equivalent PRAGMA metadata.
        if (preg_match('/^\s*EXPLAIN\s+([#A-Za-z_][A-Za-z0-9_#]*)\s*;?\s*$/i', $sql, $explain_match)) {
            $table = $explain_match[1];
            if (strpos($table, '#___') === 0) $table = $this->get_tablename(substr($table, 4));
            $pragma = $this->CONN->query('PRAGMA table_info(' . $this->quoteIdentifier($table) . ')')->fetchAll(PDO::FETCH_ASSOC);
            $rows = array();
            foreach ($pragma as $field) {
                $rows[] = array(
                    'Field' => $field['name'],
                    'Type' => strtolower($field['type']),
                    'Null' => $field['notnull'] ? 'NO' : 'YES',
                    'Default' => $field['dflt_value'],
                    'Key' => $field['pk'] ? 'PRI' : ''
                );
            }
            return $rows;
        }
        if (stripos($sql, 'INFORMATION_SCHEMA.COLUMNS') !== false) {
            if (!preg_match("/TABLE_NAME\\s*=\\s*'([^']+)'/i", $sql, $table_match)) return array();
            $table = $table_match[1];
            if (strpos($table, '#___') === 0) $table = $this->get_tablename(substr($table, 4));
            $column = null;
            if (preg_match("/COLUMN_NAME\\s*=\\s*'([^']+)'/i", $sql, $column_match)) $column = $column_match[1];
            $rows = $this->CONN->query('PRAGMA table_info(' . $this->quoteIdentifier($table) . ')')->fetchAll(PDO::FETCH_ASSOC);
            if ($column === null) return $rows;
            foreach ($rows as $row) {
                if (strcasecmp($row['name'], $column) === 0) return array($row);
            }
            return array();
        }
        if (stripos($sql, 'INFORMATION_SCHEMA.TABLES') !== false) {
            if (!preg_match("/TABLE_NAME\\s*=\\s*'([^']+)'/i", $sql, $table_match)) return array(array(0));
            $table = $table_match[1];
            if (strpos($table, '#___') === 0) $table = $this->get_tablename(substr($table, 4));
            $stmt = $this->CONN->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :table");
            $stmt->execute(array(':table' => $table));
            return array(array((int)$stmt->fetchColumn()));
        }
        if (preg_match('/\bSHOW\s+(?:INDEX|KEYS)\s+FROM\s+([^\s;]+)/i', $sql, $index_match)) {
            $table = trim($index_match[1], '`"');
            if (strpos($table, '#___') === 0) $table = $this->get_tablename(substr($table, 4));
            return $this->CONN->query('PRAGMA index_list(' . $this->quoteIdentifier($table) . ')')->fetchAll(PDO::FETCH_ASSOC);
        }
        return null;
    }

    public function query($sql, $params = array())
    {
        if (preg_match('/\bALTER(?:\s+IGNORE)?\s+TABLE\b.*\b(?:ADD|DROP)\s+PRIMARY\s+KEY\b/i', $sql)) {
            return true;
        }
        // SQLite does not support MySQL's ALTER TABLE ... ADD/DROP INDEX
        // syntax. Indexes are optional for this compatibility path.
        if (preg_match('/\bALTER(?:\s+IGNORE)?\s+TABLE\b.*\b(?:ADD|DROP)\s+(?:(?:UNIQUE\s+)?(?:INDEX|KEY))\b/i', $sql)) {
            return true;
        }
        // SQLite cannot change a column definition in place. Legacy modules
        // use MySQL's ALTER TABLE ... MODIFY form for migrations; the
        // existing SQLite column remains usable, so treat this as handled.
        if (preg_match('/\bALTER(?:\s+IGNORE)?\s+TABLE\b.*\b(?:MODIFY|CHANGE)\b/i', $sql)) {
            return true;
        }
        if (preg_match('/^\s*(?:LOCK|UNLOCK)\s+TABLES?/i', $sql)) {
            return true;
        }
        try {
            $stmt = $this->prepare($sql, $params);
            $this->affected_rows = $stmt->rowCount();
            return true;
        } catch (Exception $e) {
            $this->error($sql . " [SQLite normalized: " . $this->normalize_sql($sql) . "]", false, true, $e);
            return false;
        }
    }

    public function execute($sql, array $params = array()) { return $this->query($sql, $params) ? $this->affectedRows() : false; }
    public function returnQuery($sql)
    {
        try {
            return $this->prepare($sql, array());
        } catch (Exception $e) {
            $this->error($sql . " [SQLite normalized: " . $this->normalize_sql($sql) . "]", false, true, $e);
            return false;
        }
    }
    public function dropTable($sql) { return $this->query('DROP TABLE ' . $this->add_prefix($sql)); }

    public function define_tablename($table, $use_prefix)
    {
        return $this->get_tablename($table, $use_prefix);
    }

    public function get_tablename($table, $use_prefix = true)
    {
        if (isset($this->tablenames[$table])) return $this->tablenames[$table];
        $stmt = $this->CONN->prepare('SELECT prefix, use_prefix FROM ' . $this->quoteIdentifier($this->master_tablename) . ' WHERE internal_name = :name');
        $stmt->execute(array(':name' => $table));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $prefix = ($use_prefix === true || strtolower((string)$use_prefix) === 'true') ? $this->table_prefix : '';
            $stmt = $this->CONN->prepare('INSERT INTO ' . $this->quoteIdentifier($this->master_tablename) . ' (internal_name, prefix, use_prefix) VALUES (:name, :prefix, :use_prefix)');
            $stmt->execute(array(':name' => $table, ':prefix' => $prefix, ':use_prefix' => $prefix === '' ? 'false' : 'true'));
            $name = $prefix === '' ? $table : $prefix . $this->underscore . $table;
        } else {
            $name = ($row['use_prefix'] === 'true' && $row['prefix'] !== '') ? $row['prefix'] . $this->underscore . $table : $table;
        }
        $this->tablenames[$table] = $name;
        return $name;
    }

    public function add_prefix($sql)
    {
        return preg_replace_callback('/#___([A-Za-z0-9_]+)/', function ($match) {
            return $this->quoteIdentifier($this->get_tablename($match[1]));
        }, $sql);
    }

    public function get_version($table)
    {
        $row = $this->select('SELECT schemaversion, use_prefix FROM ' . $this->quoteIdentifier($this->master_tablename) . ' WHERE internal_name = :name', array(':name' => $table), 'assoc');
        return empty($row) ? 1 : (int)$row[0]['schemaversion'];
    }

    public function set_version($table, $version)
    {
        return $this->execute('UPDATE ' . $this->quoteIdentifier($this->master_tablename) . ' SET schemaversion = :version WHERE internal_name = :name', array(':version' => (int)$version, ':name' => $table));
    }

    public function update_table($table, $column, $action, $query)
    {
        // SQLite cannot alter a column definition in place. Its dynamic type
        // system makes these MySQL-only migrations unnecessary; the existing
        // column remains usable, so mark the migration as handled.
        if (preg_match('/\bALTER\s+TABLE\b.*\b(?:MODIFY|CHANGE|ADD\s+PRIMARY\s+KEY|DROP\s+PRIMARY\s+KEY)\b/i', $query)) {
            return true;
        }
        $name = $this->get_tablename($table);
        $fields = $this->CONN->query('PRAGMA table_info(' . $this->quoteIdentifier($name) . ')')->fetchAll(PDO::FETCH_ASSOC);
        $columns = array();
        foreach ($fields as $field) $columns[$field['name']] = true;
        $requested = is_array($column) ? $column : array($column);
        $exists = true;
        foreach ($requested as $item) if (!isset($columns[$item])) $exists = false;
        $action = strtolower($action);
        if (($action === 'add' && !$exists) || (($action === 'drop' || $action === 'alter' || $action === 'modify') && $exists) || $action === 'change') {
            return $this->query($query);
        }
        return true;
    }

    public function error($text, $fatal = false, $connected = true, $exception = null)
    {
        $this->error_count++;
        $this->last_error = $exception ? $exception->getMessage() : 'Database error';
        $context = $text ? ' SQL: ' . $text : '';
        if ($this->bot) $this->bot->log('SQLITE', 'ERROR', '(# ' . $this->error_count . ') ' . $this->last_error . $context, $connected);
        if ($fatal) throw new RuntimeException($this->last_error);
    }

    private function prepare($sql, array $params)
    {
        $sql = $this->normalize_sql($sql);
        $stmt = $this->CONN->prepare($this->add_prefix($sql));
        $stmt->execute($params);
        return $stmt;
    }

    private function normalize_sql($sql)
    {
        // Portable equivalent of MySQL TRUNCATE for SQLite. SQLite does not
        // implement TRUNCATE TABLE, but DELETE preserves the table schema.
        $sql = preg_replace('/^\s*TRUNCATE(?:\s+TABLE)?\s+([^\s;]+)\s*;?\s*$/i', 'DELETE FROM $1', $sql);
        // A few legacy modules still build SQL with MySQL-style backslash
        // escaping instead of using real_escape_string(). Convert that form
        // before PDO parses the SQLite string literal.
        $sql = str_replace("\\'", "''", $sql);
        // MySQL allows `ALTER TABLE old RENAME new`; SQLite requires TO.
        $sql = preg_replace('/(\bALTER\s+TABLE\s+[^\s;]+\s+RENAME\s+)([^\s;]+)(\s*;?\s*)$/i', '$1TO $2$3', $sql);
        // Common DDL emitted by the existing modules. SQLite uses an INTEGER
        // rowid for auto-incrementing primary keys and does not understand the
        // MySQL-only modifiers below.
        $sql = preg_replace(
            '/(\b[A-Za-z_][A-Za-z0-9_]*\b)\s+(?:BIG)?INT(?:\s*\(\s*\d+\s*\))?\s+(?:UNSIGNED\s+)?(?:NOT\s+NULL\s+)?AUTO_INCREMENT\s+PRIMARY\s+KEY/i',
            '$1 INTEGER PRIMARY KEY AUTOINCREMENT',
            $sql
        );
        $sql = preg_replace(
            '/(\b[A-Za-z_][A-Za-z0-9_]*\b)\s+(?:BIG)?INT(?:\s*\(\s*\d+\s*\))?\s+(?:UNSIGNED\s+)?(?:NOT\s+NULL\s+)?AUTO_INCREMENT\s+UNIQUE/i',
            '$1 INTEGER PRIMARY KEY AUTOINCREMENT',
            $sql
        );
        // Some legacy tables declare the primary key separately, and one
        // table uses INTEGER rather than INT. In SQLite INTEGER PRIMARY KEY
        // already receives rowid auto-increment semantics.
        $sql = preg_replace(
            '/(\b[A-Za-z_][A-Za-z0-9_]*\b)\s+(?:INTEGER|BIGINT|INT)(?:\s*\(\s*\d+\s*\))?\s+(?:UNSIGNED\s+)?(?:NOT\s+NULL\s+)?AUTO_INCREMENT\b/i',
            '$1 INTEGER',
            $sql
        );
        $sql = preg_replace('/\s+UNSIGNED\b/i', '', $sql);
        $sql = preg_replace('/\b(?:BIG)?INT\s*\(\s*\d+\s*\)/i', 'INTEGER', $sql);
        // SQLite has no ENUM type. BeBot validates these access/channel
        // values in PHP, so a bounded text column is the portable equivalent.
        $sql = preg_replace('/\bENUM\s*\([^)]*\)/i', 'VARCHAR(50)', $sql);
        $sql = preg_replace('/\s+COLLATE\s+[A-Za-z0-9_]+/i', '', $sql);
        // mysqldump table options start immediately after the closing
        // parenthesis in some exports: )ENGINE=... CHARACTER SET ...
        // SQLite only accepts the CREATE TABLE body itself.
        $sql = preg_replace('/\)\s*ENGINE\b.*$/is', ')', $sql);
        $sql = preg_replace('/\s+ENGINE\s*=\s*[A-Za-z0-9_]+/i', '', $sql);
        $sql = preg_replace('/\s+DEFAULT\s+CHARSET\s*=\s*[A-Za-z0-9_]+/i', '', $sql);
        $sql = preg_replace('/\s+CHARSET\s*=\s*[A-Za-z0-9_]+/i', '', $sql);
        $sql = preg_replace('/\s+ROW_FORMAT\s*=\s*[A-Za-z0-9_]+/i', '', $sql);
        // MySQL permits an AUTO_INCREMENT column to be UNIQUE while another
        // column is the table PRIMARY KEY. SQLite has only one rowid primary
        // key, so keep the generated id as that key and preserve the former
        // primary constraint as a UNIQUE constraint.
        if (stripos($sql, 'AUTOINCREMENT') !== false) {
            $sql = preg_replace('/\bPRIMARY\s+KEY\s*\(([^)]*)\)/i', 'UNIQUE ($1)', $sql);
        }
        // Catch legacy declarations where UNIQUE or another modifier appears
        // before AUTO_INCREMENT (for example: INT UNIQUE NOT NULL AUTO_INCREMENT).
        // The column remains valid SQLite typing and its explicit UNIQUE or
        // PRIMARY KEY constraint is preserved.
        $sql = preg_replace('/\bAUTO_INCREMENT\b/i', '', $sql);
        // MySQL permits inline INDEX declarations in CREATE TABLE; SQLite
        // requires CREATE INDEX statements instead, so omit these optional
        // performance indexes for the compatibility path.
        $sql = preg_replace('/,?\s*INDEX(?:\s+[A-Za-z_][A-Za-z0-9_`]*)?\s*\([^)]*\)/i', '', $sql);
        $sql = preg_replace('/,?\s*UNIQUE\s+KEY(?:\s+[A-Za-z_][A-Za-z0-9_`]*)?\s*\([^)]*\)/i', '', $sql);
        $sql = preg_replace('/,?\s*UNIQUE\s+INDEX(?:\s+[A-Za-z_][A-Za-z0-9_`]*)?\s*\([^)]*\)/i', '', $sql);
        // MySQL also accepts the shorthand `UNIQUE name (...)`.
        $sql = preg_replace('/,?\s*UNIQUE\s+[A-Za-z_][A-Za-z0-9_`]*\s*\([^)]*\)/i', '', $sql);
        $sql = preg_replace('/,?\s*KEY\s+[A-Za-z_][A-Za-z0-9_`]*\s*\([^)]*\)/i', '', $sql);
        $sql = preg_replace('/,?\s*(?<!PRIMARY\s)KEY\s*\([^)]*\)/i', '', $sql);
        $sql = preg_replace('/,?\s*UNIQUE\s*\(\s*\)/i', '', $sql);
        $sql = preg_replace('/,?\s*INDEX\s*\(\s*\)/i', '', $sql);
        $sql = preg_replace('/,\s*,/', ',', $sql);
        $sql = preg_replace('/,\s*\)/', ')', $sql);
        $sql = preg_replace('/\(\s*,/', '(', $sql);
        $sql = preg_replace('/\bALTER\s+IGNORE\s+TABLE\b/i', 'ALTER TABLE', $sql);
        $sql = preg_replace('/\bINSERT\s+IGNORE\s+INTO\b/i', 'INSERT OR IGNORE INTO', $sql);
        // SQLite equivalent of MySQL upsert syntax.
        $sql = preg_replace('/ON\s+DUPLICATE\s+KEY\s+UPDATE/i', 'ON CONFLICT DO UPDATE SET', $sql);
        $sql = preg_replace('/\bVALUES\s*\(\s*([A-Za-z_][A-Za-z0-9_]*)\s*\)/i', 'excluded.$1', $sql);
        return $sql;
    }

    private function quoteIdentifier($identifier)
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
?>
