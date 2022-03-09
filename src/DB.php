<?php
/**
 * @file
 * A minimal extension for PHP's PDO class designed to make running SQL
 * statements easier.
 *
 * This is a forked code from lonalore/php-pdo-wrapper-class
 *
 * http://www.imavex.com/php-pdo-wrapper-class/
 * https://github.com/lonalore/php-pdo-wrapper-class/blob/master/db.class.php
 *
 * Project Overview
 *
 * This project provides a minimal extension for PHP's PDO (PHP Data Objects)
 * class designed for ease-of-use and saving development time/effort. This is
 * achieved by providing methods - SELECT, INSERT, UPDATE, DELETE - for quickly
 * building common SQL statements, handling exceptions when SQL errors are
 * produced, and automatically returning results/number of affected rows for
 * the appropriate SQL statement types.
 *
 * System Requirements:
 * - PHP 7.3
 * - PDO Extension
 * - Appropriate PDO Driver(s) - PDO_SQLITE, PDO_MYSQL
 * - Only MySQL and SQLite database types are currently supported.
 */

namespace Models;

/**
 * Class DB.
 */

class DB extends \PDO {
    private $_error;
    private $_sql;
    private $_bind;
    private $_error_callback;
    private $_prefix;

    private $_host;
    private $_database;

    const TYPE_STRING = 'string';
    const TYPE_INT = 'int';
    const TYPE_BOOL = 'bool';
    const TYPE_FLOAT = 'float';
    const TYPE_DATETIME = 'datetime';
    const TYPE_DATE = 'date';
    const TYPE_SPATIAL = 'spatial';

    const DRIVER_MYSQL = 'mysql';
    const DRIVER_SQLITE = 'sqlite';

    /**
    * Class constructor.
    *
    * @param string $dsn
    *  More information can be found on how to set the dsn parameter by following
    *  the links provided below.
    *
    *  - MySQL - http://us3.php.net/manual/en/ref.pdo-mysql.connection.php
    *  - SQLite - http://us3.php.net/manual/en/ref.pdo-sqlite.connection.php
    *
    * @param string $user
    *  Username for database connection.
    *
    * @param string $passwd
    *  Password for database connection.
    *
    * @param string $prefix
    *  Prefix for database tables.
    */
    public function __construct($host = '', $database = '', $username = '', $password = '', $port = 3306, $driver = self::DRIVER_MYSQL, $pdo_options = null) {
        $options = [
            \PDO::ATTR_PERSISTENT => true,
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
        ];

        if ($pdo_options) {
            if (!is_array($pdo_options)) throw new \PDOException('PDO options should be an array');
            $options = array_merge($options, $pdo_options);
        }

        $this->_host = $host;
        $this->_database = $database;

        switch ($driver) {
            case self::DRIVER_MYSQL:
                $dsn = "mysql:host=$host;port=$port;dbname=$database";
                break;

            case self::DRIVER_SQLITE:
                $dsn = "sqlite:$host";
                break;
        }

        parent::__construct($dsn, $username, $password, $options);
    }

    public function is($sql, $what) {
        $what = is_array($what) ? $what : [$what];
        return preg_match('/^\s*('.implode('|', $what).')\s+/i', $sql) ? true : false;
    }

    /**
    * INSERT statement.
    *
    * @param string $table
    *  Table name or INSERT statement
    *
    * @param array $data
    *  Associative array with field names and values
    *
    * @return array|bool|int
    *  If no SQL errors are produced, this method will return with the the last
    *  inserted ID. Otherwise 0.
    */
    public function insert($sql = '', $data = []) {
        if ($this->is($sql, 'insert')) {
            return $this->run($sql, $data);
        } else {
            $table = $this->_prefix.$sql;
            $data_fields = array_keys($data);

            $sql = "INSERT INTO `$table` (`".implode("`, `", $data_fields)."`)";

            $fields = $this->getFields($table);

            $bind = [];
            $values = [];

            foreach ($data_fields as $field) {
                if (isset($fields[$field])) {
                    $type = $fields[$field]['type'];

                    switch ($type) {
                        // direct value inject
                        case self::TYPE_SPATIAL:
                            $values[] = $data[$field];
                            break;

                        default:
                            $bind[":$field"] = $data[$field];
                            $values[] = ":$field";
                            break;
                    }
                }
            }

            $sql .= " VALUES (".implode(", ", $values).")";
            return $this->run($sql, $bind);
        }
    }

    /**
     * Allias of run
    */
    public function select($sql, $bind = null, $args = null, $style = null) {
        return $this->run($sql, $bind, $args, $style);
    }

    /**
    * UPDATE statement.
    *
    * @param string $sql
    *  Table name.
    *
    * @param array $info
    *  Associated array with fields and their values.
    *
    * @param string $where
    *  WHERE conditions.
    *
    * @param mixed $bind
    *  Bind parameters as string or array.
    *
    * @return array|bool|int
    *  If no SQL errors are produced, this method will return the number of rows
    *  affected by the UPDATE statement.
    */
    public function update($sql = '', $data = [], $where = '', $bind = '') {
        if ($this->is($sql, 'update')) {
            return $this->run($sql, $data);
        } else if (is_array($data)) {
            $table = $this->_prefix.$sql;
            $data_fields = array_keys($data);
            $sql = "UPDATE $table";

            $fields = $this->getFields($table, $data_fields);

            $set_fields = [];
            $bind = $this->cleanup($bind);
            foreach ($data_fields as $field) {
                if (isset($fields[$field])) {
                    $type = $fields[$field]['type'];
                    switch ($type) {
                        // direct value inject
                        case self::TYPE_SPATIAL:
                            $set_fields[] = "`$field` = $data[$field]";
                            break;
                        default:
                            $set_fields[] = "`$field` = :update_$field";
                            $bind[":update_$field"] = $data[$field];
                            break;
                    }
                }
            }

            $sql .= " SET ".implode(", ", $set_fields);
            if ($where) $sql .= " WHERE $where";

            return $this->run($sql, $bind);
        } else {
            $this->_error = 'Invalid update parameters';
            $this->debug();
            return false;
        }
    }

    /**
    * DELETE statement.
    *
    * @param string $sql
    *  Table name or DELETE statement
    *
    * @param string $info
    *  Where conditions or bind information
    *
    * @param mixed $bind
    *  Bind parameters as string or array.
    *
    * @return array
    *  If no SQL errors are produced, this method will return the number of rows
    *  affected by the DELETE statement.
    */
    public function delete($sql_or_table = '', $info = '', $bind = '') {
        if ($this->is($sql_or_table, 'delete')) {
            return $this->run($sql_or_table, $info);
        } else {
            $sql = $this->_prefix.$sql_or_table;
            $sql = "DELETE FROM $sql WHERE $info;";
            return $this->run($sql, $bind);
        }
    }

    /**
     * Fetch a single row
     *
     * @param string $sql
     *  Query string
     *
     * @param mixed $bind
     *  Bind parameters as string or array
     *
     * @param mixed $args
     *  Additional arguments for fetch
     *
     * @param long $style
     *  Fetch style
     *
     * @return mixed
     * If no SQL errors are procuded, this method will return the object. Otherwise returns false.
     */
    public function row($sql, $bind = null, $args = null, $style = null) {
        $smt = $this->stmt($sql, $bind, $args, $style);
        if ($smt !== false) {
            if (isset($args)) {
                $smt->setFetchMode($style ?: \PDO::FETCH_CLASS, $args);
            } else {
                $smt->setFetchMode($style ?: \PDO::FETCH_OBJ);
            }

            return $smt->fetch();
        }

        return false;
    }

    /**
    * This method is used to run free-form SQL statements
    *
    * @param string $sql
    *  SQL query.
    *
    * @param mixed $bind
    *  Bind parameters as string or array.
    *
    * @param mixed $style
    *  Fetch style
    *
    * @param mixed $args
    *  Additional arguments
    *
    * @return array|bool|int
    *  If no SQL errors are produced, this method will return the number of
    *  affected rows for DELETE and UPDATE statements, the last inserted ID for
    *  INSERT statement, or an associate array of results for SELECT, DESCRIBE,
    *  and PRAGMA statements. Otherwise false.
    */
    public function run($sql = '', $bind = null, $args = null, $style = null) {
        $smt = $this->stmt($sql, $bind, $args, $style);
        if ($smt !== false) {
            if ($this->is($this->_sql, ['delete', 'update'])) {
                return $smt->rowCount();
            } elseif ($this->is($this->_sql, 'insert')) {
                return $this->lastInsertId();
            } else {
                if (isset($args)) {
                    return $smt->fetchAll($style ?: \PDO::FETCH_CLASS, $args);
                } else {
                    return $smt->fetchAll($style ?: \PDO::FETCH_OBJ);
                }
            }
        }

        return false;
    }

    /**
     * Execute and return the statement
     */
    public function stmt($sql = '', $bind = null, $args = null, $style = null) {
        $this->_sql = trim($sql);
        $this->_bind = $this->cleanup($bind);
        $this->_error = '';

        try {
            $smt = $this->prepare($this->_sql);
            $smt->execute($this->_bind);

            return $smt;
        } catch (\PDOException $e) {
            $this->_error = $e->getMessage();
            $this->debug();
            return false;
        }

        return false;
    }

    /**
    * When a SQL error occurs, this project will send an error message to a
    * callback function specified through the onError method.
    * The callback function's name should be supplied as a string without
    * parenthesis.
    *
    * If no SQL errors are produced, this method will return an associative
    * array of results.
    *
    * @param $error_callback
    *  Callback function.
    */
    public function onError($callback) {
        if (is_string($callback)) {
            // Variable functions for won't work with language constructs such as echo
            // and print, so these are replaced with print_r.
            if (in_array(strtolower($callback), ['echo', 'print'])) {
                $callback = 'print_r';
            }

            if (function_exists($callback)) {
                $this->_error_callback = $callback;
            }
        } else {
            $this->_error_callback = $callback;
        }
    }

    public function getQuery() {
        $info = $this->getInfo();
        $query = $info['statement'];

        if (!empty($info['bind'])) {
            foreach ($info['bind'] as $field => $value) {
                $query = str_replace(':'.$field, $this->quote($value), $query);
            }
        }

        return $query;
    }

    public function getInfo() {
        $info = [];

        if (!empty($this->_sql)) {
            $info['statement'] = $this->_sql;
        }

        if (!empty($this->_bind)) {
            $info['bind'] = $this->_bind;
        }

        return $info;
    }

    /**
    * Debug.
    */
    private function debug() {
        if (!empty($this->_error_callback)) {
            $error = ['Error' => $this->_error];

            if (!empty($this->_sql)) {
                $error['SQL Statement'] = $this->_sql;
            }

            if (!empty($this->_bind)) {
                $error['Bind Parameters'] = trim(print_r($this->_bind, true));
            }

            $backtrace = debug_backtrace();
            if (!empty($backtrace)) {
                $backtraces = [];
                foreach ($backtrace as $info) {
                    if (isset($info['file']) && $info['file'] != __FILE__) {
                        $backtraces[] = $info['file'] . ' at line ' . $info['line'];
                    }
                }

                if ($backtraces) {
                    $error['Backtrace'] = implode(PHP_EOL, $backtraces);
                }
            }

            $msg = 'SQL Error' . PHP_EOL . str_repeat('-', 50);
            foreach ($error as $key => $val) {
                $msg .= PHP_EOL . PHP_EOL . $key . ':' . PHP_EOL . $val;
            }

            $func = $this->_error_callback;
            $func(new \PDOException($msg));
        }
    }

    /**
     * Map a datatype based upon the given driver
     *
     * @return string
     * Returns the equevalent php type
    */
    private function _mapDataType($driver_type) {
        $map = [];
        $driver = $this->getAttribute(\PDO::ATTR_DRIVER_NAME);
        switch ($driver) {
            case self::DRIVER_MYSQL:
                $map = [
                    self::TYPE_INT => ['smallint', 'mediumint', 'int', 'bigint'],
                    self::TYPE_BOOL => ['tinyint'],
                    self::TYPE_FLOAT => ['float', 'double', 'decimal'],
                    self::TYPE_DATETIME => ['datetime'],
                    self::TYPE_DATE => ['date'],
                    self::TYPE_SPATIAL => ['point', 'geometry', 'polygon', 'multipolygon', 'multipoint']
                ];

                break;
            case self::DRIVER_SQLITE:
                $map = [
                    self::TYPE_INT => ['integer'],
                    self::TYPE_FLOAT => ['real'],
                    self::TYPE_BOOL => ['boolean']
                ];
        }

        foreach ($map as $type => $driver_types) {
            if (in_array(strtolower($driver_type), $driver_types)) {
                return $type;
                break;
            }
        }

        return self::TYPE_STRING;
    }

    /**
     * Get table info
     * @param  string $table
     * The table name
     *
     * @return array
     * Returns array of field information about the table
     */
    public function getFields($table) {
        $table = $this->_prefix . $table;
        $driver = $this->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $fields = [];

        if ($driver === self::DRIVER_SQLITE) {
            $sql = "PRAGMA table_info('$table');";
        } else {
            $sql = "SELECT column_name AS name, data_type AS type, IF(column_key = 'PRI', 1, 0) AS pk FROM information_schema.columns";
            $sql .= " WHERE table_name = '$table' AND table_schema = '$this->_database';";
        }

        if ($data = $this->run($sql)) {
            foreach ($data as $column_info) {
                $field_info = [
                    'type' => $this->_mapDataType($column_info->type),
                    'primary' => !empty($column_info->pk)
                ];

                $fields[$column_info->name] = $field_info;
            }
        }

        return $fields;
    }

    /**
    * Cleanup parameters.
    *
    * @param mixed $bind
    *  Bind parameters as string/array.
    *
    * @return array
    *  Bind parameters as array.
    */
     private function cleanup($bind = '') {
        if (!is_array($bind)) {
            if (!empty($bind)) {
                $bind = [$bind];
            } else {
                $bind = [];
            }
        }

        return $bind;
    }
}
