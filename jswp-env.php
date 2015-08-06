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

// Returns core wp-jumpstarter plugins that can never be disabled.
function jswp_env_core_plugins() {
    return array(
        "sqlite-integration/sqlite-integration.php",
        "jumpstarter/jumpstarter.php"
    );
}

function jswp_env_get_theme() {
    return jswp_env_get_value("theme");
}

function jswp_env_get_options() {
    return jswp_env_get_val_or_array("options");
}

function jswp_env_get_disabled_capabilities() {
    return jswp_env_get_val_or_array("disabled_capabilities");
}

function js_https_preg_regx() {
    return "/^https/";
}

function js_domain_is_https() {
    static $is_https = null;
    if ($is_https === null)
        $is_https = preg_match(js_https_preg_regx (), js_env_get_siteurl ());
    return $is_https;
}
