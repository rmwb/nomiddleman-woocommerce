<?php
if (!defined('ABSPATH')) { exit; }

/** Explicit upgrade bridge; no legacy PHP classes or functions are redefined. */
class NMMPRO_Compat {
    private static function legacy($name) { return strpos($name, 'nmmpro_') === 0 ? 'nmm_' . substr($name, 7) : $name; }
    public static function get_option($name, $default = false) {
        $missing = new stdClass();
        $value = get_option($name, $missing);
        if ($value !== $missing) { return $value; }
        $old = self::legacy($name);
        if ($old === $name) { return $default; }
        $value = get_option($old, $missing);
        if ($value === $missing) { return $default; }
        // Preserve the old record for rollback. Failed copy still reads the old value.
        add_option($name, $value, '', false);
        return $value;
    }
    public static function update_option($name, $value, $autoload = null) { return update_option($name, $value, $autoload); }
    public static function delete_option($name) {
        $result = delete_option($name);
        $old = self::legacy($name);
        if ($old !== $name) { $result = delete_option($old) || $result; }
        return $result;
    }
    public static function filter($tag, $value, ...$args) {
        $old = self::legacy($tag);
        if ($old !== $tag) { $value = apply_filters($old, $value, ...$args); }
        return apply_filters($tag, $value, ...$args);
    }
    public static function action($tag, ...$args) {
        $old = self::legacy($tag);
        if ($old !== $tag) { do_action($old, ...$args); }
        do_action($tag, ...$args);
    }
    public static function config($name, $default = null) {
        if (defined($name)) { return constant($name); }
        $old = strpos($name, 'NMMPRO_') === 0 ? 'NMM_' . substr($name, 7) : $name;
        return defined($old) ? constant($old) : $default;
    }
}
