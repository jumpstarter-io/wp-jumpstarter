<?php

/*
 * Routine for syncing the environment theme with WordPress.
 */

// We don't want to run unless invoked by cli.
if (php_sapi_name() !== "cli")
    return;

if (jswp_env_get_value("run_theme_init_hooks")) {
    do_action("before_setup_theme");
}
$stylesheet = get_option("stylesheet");
$env_stylesheet = jswp_env_get_theme();
if (!defined("WP_INSTALLING") && $stylesheet === $env_stylesheet) {
    js_log("using pre defined theme [$stylesheet]");
    return false;
}
// If were not installing and the user has changed stylesheet, then that's fine.
if (!defined("WP_INSTALLING") && $stylesheet !== $env_stylesheet) {
    js_log("using user defined theme [$stylesheet]");
    return false;
}
if (is_string($env_stylesheet)) {
    js_log("setting theme [$env_stylesheet]");
    $theme = wp_get_theme($env_stylesheet);
    if (!$theme->exists())
        throw new Exception("theme to install [$env_stylesheet] not found!");
    switch_theme($theme->get_stylesheet());
} else {
    js_log("no theme specified in env, nothing to set");
}
// For legacy reasons we define TEMPLATEPATH if not already defined
// to point at the root directory of the current theme.
if (!defined('TEMPLATEPATH')) {
    define(TEMPLATEPATH, get_template_directory());
}
return true;
