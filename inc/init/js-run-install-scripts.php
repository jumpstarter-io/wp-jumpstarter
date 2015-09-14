<?php

/*
 * Routine for running finding and running php install scripts in
 * the WordPress environment.
 */

// We don't want to run unless invoked by cli.
if (php_sapi_name() !== "cli")
    return;

global $js_install_scripts;
$js_install_scripts = array();
function js_install_script($name, $fn) {
    $GLOBALS["js_install_scripts"][] = array("name" => $name, "fn" => $fn);
}
foreach(glob("/app/code/js-install-scripts/*.php") as $file_path) {
    require_once($file_path);
}
foreach($js_install_scripts as $sc_arr) {
    js_log("running install script: $sc_arr[name]");
    try {
        if (!$sc_arr["fn"]()) {
            js_log("error running script: $sc_arr[name]");
            exit(1);
        }
    } catch (Exception $ex) {
        js_log($ex->getMessage());
        exit(1);
    }
}
