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
        trigger_error($msg, E_USER_NOTICE);
        return false;
    }

    /**
     * Get the name of the child class
     *
     * @return string
     * The name of the child class
     */
    public static function get_name() {
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
        $model = self::_get_model_info();
        if (!$model) return false;

        $table = $model['table'];

        if (isset($arg2)) {
            $fields = "`$arg1` = :value";
            $bind = [':value' => $arg2];
        } else {
            if (is_array($arg1)) {
                $filters = [];
                $bind = [];
                foreach ($arg1 as $key => $value) {
                    $filters[] = "`$key` = :$key";
                    $bind[$key] = $value;
                }

                $fields = implode(' AND ', $filters);
            } else {
                $fields = "`id` = :value";
                $bind = [':value' => $arg1];
            }
        }

        return self::query_row("SELECT * FROM `$table` WHERE $fields AND active = 1", $bind);
    }

    public static function set_app($app) {
        if ($app) {
            self::$app = $app;
            return true;
        }

        return self::_error('app is not valid');
    }

    /**
     * Set the DB object to our base model class
     *
     * @param $db
     * The DB instance
     */
    public static function set_db($db) {
        if ($db) {
            self::$db = $db;
            return true;
        }

        return self::_error('db is not valid');
    }

    /**
     * Register a model for the base class to use proper property data types and some other preps in the future
     *
     * @return bool
     * Returns true if table exists and successfully registered to the Model class. Otherwise false.
     */
    public static function register($table) {
        if ($table_info = self::$db->get_info($table)) {
            self::$_models[self::get_name()] = [
                'fields' => self::$db->get_info($table),
                'table' => $table
            ];

            return true;
        }

        return self::_error('table "'.$table.'" for '.self::get_name().' not found in database.');
    }

    /**
     * Given that the model class is registered, this helper class gets information about the model stored in the static $_models property
     *
     * @return array
     * Returns the array of model information
     */
    private static function _get_model_info() {
        $class_name = self::get_name();
        if (isset(self::$_models[$class_name])) {
            return self::$_models[$class_name];
        }

        return self::_error(self::get_name().' must be registered with it\'s corresponding table name and database. Use '.self::get_name().'::register($db, $table)');
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
    private static function _set_type($property_name, &$value) {
        $model = self::_get_model_info();
        if (!$model) return false;

        if (!is_null($value) && isset($model['fields'][$property_name])) {
            $field_info = $model['fields'][$property_name];
            $type = $field_info['type'];

            switch ($type) {
                case 'datetime':
                    $value = date(\DateTime::ISO8601, strtotime($value));
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
        $model = self::_get_model_info();
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
    public static function format_dates($fields, $data, $format = 'Y-m-d H:i:s') {
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
        return self::$db->query($sql, $bind, self::get_name());
    }

    /**
     * Inherited static method to call DB::query_row(...)
     *
     * @param $sql
     * sql string
     *
     * @param $bind
     * Bind parameters as string or array
     *
     */
    public static function query_row($sql, $bind = null) {
        return self::$db->query_row($sql, $bind, self::get_name());
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
        $model = self::_get_model_info();
        if (!$model) return false;

        $table = $model['table'];
        $data = [];

        if (isset($arg1) && !isset($arg2)) {
            $data = $arg1;
        } elseif (isset($arg2)) {
            $data[$arg1] = $arg2;
        } else {
            return false;
        }

        $updated = self::$db->update($table, $data, 'id = :id', [':id' => $this->id]);
        if ($updated) {
            self::$db->query_row("SELECT * FROM `$table` WHERE id = :id", [':id' => $this->id], $this, \PDO::FETCH_INTO);
        }

        return $updated;
    }

    /**
     * Delete the model (deactivate). We are not actually deleting row but instead setting `active` = 1 (hence active field is required)
     *
     * @return int
     * Returns the number of rows affected from "update"
     */
    public function delete() {
        // we don't delete here :P
        return $this->update('active', 0);
    }

    /**
     * Used to properly set data type of each property.
     * This overload is disabled by default as it increases mapping (general output) to around 200%
     *
     * public function __set($name, $value) {
     *    $this->_set_type($name, $value);
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
        return json_encode($this->to_array(), $flags);
    }

    public function to_array() {
        $model = self::_get_model_info();
        if (!$model) return false;

        $result = [];
        $properties = get_object_vars($this);


        foreach ($properties as $field => $value) {
            $this->_set_type($field, $value);
            $result[$field] = $value;
        }

        return $result;
    }
}

?>