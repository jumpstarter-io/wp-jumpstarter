<?php

require_once("/app/code/js-php-env/js-env.php");

define("JSWP_ENV_PATH", "/app/code/wp-env.json");
define("JSWP_ENV_APC_KEY", "wp-env");

function jswp_get_env() {
    return js_get_env(JSWP_ENV_PATH, JSWP_ENV_APC_KEY);
}

function jswp_env_get_value($key_path) {
    return js_env_get_value($key_path, JSWP_ENV_PATH, JSWP_ENV_APC_KEY);
}

function jswp_env_get_val_or_array($path) {
    return js_env_get_value_or_array($path, JSWP_ENV_PATH, JSWP_ENV_APC_KEY);
}

function jswp_env_get_theme() {
    return jswp_env_get_value("theme");
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

