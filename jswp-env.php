<?php

// Don't load directly.
if (php_sapi_name() !== "cli" && !defined("ABSPATH"))
    die("-1");

require_once("/app/code/js-php-env/js-env.php");
require_once(dirname(__FILE__) . "/jswp-util.php");

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
