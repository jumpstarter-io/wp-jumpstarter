<?php

require_once("/app/code/js-php-env/js-env.php");

function jswp_get_env() {
    // Fetch parsed environment from request cache.
    static $env = null;
    if ($env !== null)
        return $env;
    // Fetch parsed environment from server cache.
    if (function_exists("apc_fetch")) {
        $key = "wp-env";
        $env = apc_fetch($key);
        if (is_array($env))
            return $env;
    }
    // Read environment and parse it.
    $env = json_decode(file_get_contents("/app/code/wp-env.json"), true);
    if (!is_array($env))
        $env = array();
    // Store parsed environment in server cache.
    if (function_exists("apc_store")) {
        $key = "wp-env";
        apc_store($key, $env);
    }
    return $env;
}

function jswp_env_get_value($path) {
    static $cache = array();
    $env = jswp_get_env();
    if (isset($cache[$path]))
        return $cache[$path];
    $path_arr = explode(".", $path);
    if (count($path_arr) == 0)
        return null;
    if (!is_array($env))
        return null;
    $obj = $env;
    foreach($path_arr as $part) {
        if (!isset($obj[$part])) {
            $cache[$path] = null;
            return null;
        }
        $obj = $obj[$part];
    }
    $cache[$path] = $obj;
    return $obj;
}

function jswp_env_get_theme() {
    return jswp_env_get_value("theme");
}

function jswp_env_get_val_or_array($path) {
    $obj = jswp_env_get_value($path);
    return is_array($obj)? $obj: array();
}

function jswp_env_get_plugins() {
    return jswp_env_get_val_or_array("plugins");
}

function jswp_env_get_options() {
    return jswp_env_get_val_or_array("options");
}

function jswp_env_get_user_plugins() {
    return jswp_env_get_val_or_array("user_plugins");
}

function jswp_env_get_disabled_capabilities() {
    $dcaps = jswp_env_get_val_or_array("disabled_capabilities");
    // We don't want the user to be able to switch themes.
    // The theme is set in the app config.
    array_push($dcaps, "switch_themes");
    return $dcaps;
}

