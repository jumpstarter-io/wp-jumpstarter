<?php

/*
 * Routine for including the WordPress environment.
 */

// We don't want to run unless invoked by cli.
if (php_sapi_name() !== "cli")
    return;

global $JS_WP_INCLUDED;
if ($JS_WP_INCLUDED === true) {
    js_log("WordPress already included");
    return;
}

define("ABSPATH", "/app/code/src/");
// We pretend a user visited wordpress to install it through the generated domain.
define("WP_SITEURL", js_env_get_siteurl());
define("WP_ADMIN", true);
// Include wordpress from CLI.
$_SERVER['HTTPS'] = "on";
if (defined("WP_INSTALLING") && !defined("TEMPLATEPATH")) {
    define("TEMPLATEPATH", ABSPATH . "wp-content/themes/" . jswp_env_get_theme());
}
js_log("including wp-load.php");
require_once(ABSPATH . "wp-load.php");
js_log("including upgrade.php");
require_once(ABSPATH . "wp-admin/includes/upgrade.php");

// Dev sanity check: Ensure that DB_DIR is set to the right value.
$db_dir = js_db_dir();
if (!defined("DB_DIR") || DB_DIR !== $db_dir) {
    js_log("error: DB_DIR is not set to the correct value in wp-config.php, it should be set to [$db_dir]");
    exit;
}

// Dev sanity check: Ensure that there is no database folder or symlink.
$bad_db_path = (ABSPATH . "wp-content/database");
if (file_exists($bad_db_path) || is_link($bad_db_path)) {
    js_log("error: the path [$bad_db_path] exists, it's a security hazard and should be removed");
    exit;
}

$JS_WP_INCLUDED = true;
js_log("WordPress included");
