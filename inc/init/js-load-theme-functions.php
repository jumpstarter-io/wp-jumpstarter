<?php

/*
 * Routine for loading the theme functions file/s.
 */

// We don't want to run unless invoked by cli.
if (php_sapi_name() !== "cli")
    return;

if (jswp_env_get_value("load_theme_functions") === false) {
    return;
}
// If the theme we're using is a child theme we should try to load
// the parents functions.php first.
$stylesheet_dir = get_stylesheet_directory();
$template_dir = get_template_directory();
$functions_paths = array_map(function($dir_path) {
    return "$dir_path/functions.php";
}, ($stylesheet_dir !== $template_dir) ? array($template_dir, $stylesheet_dir): array($stylesheet_dir));
try {
    foreach ($functions_paths as $functions_path) {
        if (file_exists($functions_path)) {
            require_once($functions_path);
        }
    }
} catch (Exception $ex) {
    js_log($ex->getMessage());
    js_log("could not include functions.php");
}
