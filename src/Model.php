<?php

/**
 * @file
 * A minimal and simple base class for common database models. Models are required to have the following fields:
 * - id int(11) primary key
 * - active tinyint(1) default 1
 *
 * System Requirements:
 * - PHP 5.4+
 * - PDO Extension
 */

namespace Models;

/**
 * class Model
 */
class Model {
    public static $db;
    public static $app;
    public static $debug = false;

    /**
     * Store registered models globaly
     */
    private static $_models = [];

    /**
     * Debug helper
     *
     * @param  string $msg
     * The exception
     *
     * @return bool
     * Returns false by default
     */
    private static function _error($msg) {
        if ($debug) throw new \Exception($msg);
        else {
            trigger_error($msg, E_USER_NOTICE);
            return false;
        }
    }

    /**
     * Get the name of the child class
     *
     * @return string
     * The name of the child class
     */
    public static function getModelClassName() {
        return get_called_class();
    }

    /**
     * Get a single object. If arg 2 is provided, then it's assumed that arg 1 is a field :)
     * @param  mixed $arg1
     * Could be a field name or a value or array filter
     *
     * @param  string $arg2
     * Value if arg1 is field
     *
     * @return object
     * Returns the object
     */
    public static function instance($arg1, $arg2 = null) {
        $model = self::_getModelInfo();
        if (!$model) return false;

        $table = $model['table'];

        if (isset($arg2)) {
            $filter_str = "`$arg1` = :value";
            $binds = [':value' => $arg2];
        } else {
            if (is_array($arg1)) {
                $filter_str = self::createFilter($arg1, $binds, null);
            } else {
                $pk = $model['pk'];
                $filter_str = "`$pk` = :value";
                $binds = [':value' => $arg1];
            }
        }

        if (self::getField('active')) {
            $filter_str = 'active = 1 AND '.$filter_str;
        }

        return self::queryRow("SELECT * FROM `$table` WHERE $filter_str", $binds);
    }

    public static function connect($host, $database, $username, $password) {
        $db = new DB($host, $database, $username, $password);
        self::setDb($db);
    }

    /**
     * Set the DB object to our base model class
     *
     * @param $db
     * The DB instance
     */
    public static function setDb($db) {
        if ($db) {
            self::$db = $db;
            return true;
        }

        return self::_error('db is not valid');
    }

    /**
     * Get the DB object from the base Model
     *
     * @return DB
     */
    public static function getDb() {
        return self::$db;
    }

    /**
     * Register a model for the base class to use proper property data types and some other preps in the future
     *
     * @return bool
     * Returns true if table exists and successfully registered to the Model class. Otherwise false.
     */
    public static function register($table, $pk = null) {
        if ($fields = self::$db->getTableInfo($table)) {
            if (!$pk) {
                foreach ($fields as $field => $info) {
                    if ($info['primary'] === true) {
                        $pk = $field;
                        break;
                    }
                }
            }

            self::$_models[self::getModelClassName()] = [
                'fields' => $fields,
                'table' => $table,
                'pk' => $pk
            ];

            return true;
        }

        return self::_error('table "'.$table.'" for '.self::getModelClassName().' not found in database.');
    }

    /**
     * Given that the model class is registered, this helper class gets information about the model stored in the static $_models property
     *
     * @return array
     * Returns the array of model information
     */
    private static function _getModelInfo() {
        $class_name = self::getModelClassName();
        if (isset(self::$_models[$class_name])) {
            return self::$_models[$class_name];
        }

        return self::_error(self::getModelClassName().' must be registered with it\'s corresponding table name and database. Use \Models\\'.self::getModelClassName().'::register($db, $table)');
    }

    /**
     * Create an array given a field from the data set
     *
     * @param $field
     * the field to map
     *
     */
    public static function map($field = 'id', $filters = []) {
        $model = self::_getModelInfo();
        if (!$model) return false;
        if (!self::getField($field)) return false;

        if ($filters && !empty($filters[0]) && $filters[0] instanceof self) {
            $data = $filters;
        } else {
            $table = $model['table'];
            $active_field = self::getField('active') ? "active = 1" : "";

            $filters_str = self::createFilter($filters, $binds);
            $data = self::query("SELECT $field FROM `$table` WHERE $active_field $filters_str", $binds);
        }

        if (!$data) return [];

        return array_map(function($row) use ($field) {
            $value = is_array($row) ? $row[$field] : $row->{$field};
            self::_setDataType($field, $value);

            return $value;
        }, $data);
    }

    /**
     * Sets the type of a property value
     *
     * @param string $field_name
     * The field name
     *
     * @param mixed $value
     * The value to be set
     */
    private static function _setDataType($property_name, &$value) {
        $model = self::_getModelInfo();
        if (!$model) return false;

        if (!is_null($value) && isset($model['fields'][$property_name])) {
            $field_info = $model['fields'][$property_name];
            $type = $field_info['type'];

            switch ($type) {
                case 'datetime':
                    break;
                default:
                    settype($value, $type);
            }
        }
    }

    /**
     * Inherited static method to call DB::insert(...)
     * @param  array $data
     * The data to insert
     *
     * @return int
     * Returns the newly inserted ID
     */
    public static function insert($data) {
        $model = self::_getModelInfo();
        if (!$model) return false;

        $table = $model['table'];

        $id = self::$db->insert($table, $data);
        return $id ? self::instance($id) : false;
    }

    /**
     * tidy mysql date values
     *
     * @param array $fields
     * list of date fields to clean
     *
     * @param mixed $data
     * data source
     *
     * @return mixed
     * returns cleaned data
     */
    public static function formatDates($fields, $data, $format = 'Y-m-d H:i:s') {
        foreach ($fields as $field) {
            if (is_array($data) && isset($data[$field])) {
                $data[$field] = $data[$field] ? date($format, strtotime($data[$field])) : null;
            } else if (is_object($data) && isset($data->{$field})) {
                $data->{$field} = $data->{$field} ? date($format, strtotime($data->{$field})) : null;
            }
        }

        return $data;
    }

    /**
     * Inherited static method to call DB::query(...)
     *
     * @param $sql
     * sql string
     *
     * @param $bind
     * Bind parameters as string or array
     *
     */
    public static function query($sql, $bind = null) {
        return self::$db->query($sql, $bind, self::getModelClassName());
    }

    /**
     * Inherited static method to call DB::queryRow(...)
     *
     * @param $sql
     * sql string
     *
     * @param $bind
     * Bind parameters as string or array
     *
     */
    public static function queryRow($sql, $bind = null) {
        return self::$db->queryRow($sql, $bind, self::getModelClassName());
    }

    /**
     * Build filter string
     *
     * @param $info
     * Fields and values
     *
     * @param &$binds
     * Updated binds
     *
     * @param $prepend
     * WHERE or AND
     *
     */
    public static function createFilter($info, &$binds = [], $prepend = 'AND') {
        $filters = [];

        if (!$info) return '';
        if (is_string($info)) $info = [$info];

        foreach ($info as $field => $value) {
            if (is_int($field)) {
                $filters[] = $value;
            } else {
                $bind_key = str_replace(".", "_", $field);

                $field_info = explode('.', $field);
                $table = isset($field_info[1]) ? $field_info[0].'.' : '';
                $field_name = $field_info[1] ?? $field_info[0];

                $filters[] = $table."`$field_name` = :$bind_key";
                $binds[$bind_key] = $value;
            }
        }

        return $prepend.' '.implode(' AND ', $filters);
    }

    /**
     * Inherited method to call DB::update(...). Updates the object with new data
     *
     * @param  mixed $arg1
     * array if multiple fields are proived. Could be string for a field name
     *
     * @param  mixed $arg2
     * The value of $arg1 is a field name
     *
     * @return int
     * Returns the number of rows affected
     */
    public function update($arg1, $arg2 = null) {
        if (!$arg1) return false;

        $model = self::_getModelInfo();
        if (!$model) return false;

        $table = $model['table'];
        $pk = $model['pk'];
        $data = [];

        if (is_string($arg1)) {
            $data[$arg1] = $arg2;
        } else {
            $data = $arg1;
        }

        $updated = self::$db->update($table, $data, "$pk = :pk", [':pk' => $this->{$pk}]);
        if ($updated) {
            self::$db->queryRow("SELECT * FROM `$table` WHERE $pk = :pk", [':pk' => $this->{$pk}], $this, \PDO::FETCH_INTO);
        }

        return $updated;
    }

    /**
     * Get field info
     *
     * @param $field
     * Field name
     *
     * @return bool
     *
     */
    public static function getField($field) {
        $model = self::_getModelInfo();
        if (!$model) return false;

        return isset($model['fields'][$field]) ? $model['fields'][$field] : false;
    }

    /**
     * Is field a primary key
     *
     * @param @field
     * Field name
     *
     * @return bool
     *
     */
    public static function isPK($field) {
        $field = self::getField($field);
        return $field ? $field['primary'] : false;
    }

    /**
     * Delete the model (deactivate). We are not actually deleting row but instead setting `active` = 1 (hence active field is required)
     *
     * @return int
     * Returns the number of rows affected from "update"
     */
    public function delete() {
        $model = self::_getModelInfo();
        if (!$model) return false;

        $table = $model['table'];
        $pk = $model['pk'];

        // we don't delete here :P
        if (self::getField('active')) {
            $data = ['active' => 0];
            if (self::getField('deleted_at')) {
                $data['deleted_at'] = date('Y-m-d H:i:s');
            }

            return $this->update($data);
        } else {
            return self::$db->delete("DELETE FROM $table WHERE $pk = :pk", [':pk' => $this->{$pk}]);
        }
    }

    /**
     * Used to properly set data type of each property.
     * This overload is disabled by default as it increases mapping (general output) to around 200%
     *
     * public function __set($name, $value) {
     *    $this->_setDataType($name, $value);
     *    $this->{$name} = $value;
     *}
     */

    /**
     * Update correct data type and JSON encode
     *
     * @param mixed $flags
     * JSON flags
     *
     * @return string
     * JSON string
     */
    public function json($flags = JSON_PRETTY_PRINT) {
        return json_encode($this->toArray(), $flags);
    }

    public function toArray($get_fields = null) {
        $model = self::_getModelInfo();
        if (!$model) return false;

        $result = [];
        $properties = get_object_vars($this);

        foreach ($properties as $field => $value) {
            if (!$get_fields || ($get_fields && in_array($field, $get_fields))) {
                self::_setDataType($field, $value);
                $result[$field] = $value;
            }
        }

        return $result;
    }
}

?>