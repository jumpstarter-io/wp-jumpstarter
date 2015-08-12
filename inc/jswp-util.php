<?php

// Don't load directly unless in cli.
if (php_sapi_name() !== "cli" && !defined("ABSPATH"))
    die("-1");

require_once(dirname(__FILE__) . "/jswp-env.php");

function js_https_preg_regx() {
    return "/^https/";
}

// Checks if the currently assigned domain is using https.
function js_domain_is_https() {
    static $is_https = null;
    if ($is_https === null)
        $is_https = preg_match(js_https_preg_regx(), js_env_get_siteurl());
    return $is_https;
}

function js_request_is($type) {
    return strtolower($_SERVER["REQUEST_METHOD"]) == strtolower($type);
}

function js_filemtime($file_path) {
    return filemtime(plugin_dir_path(__FILE__) . $file_path);
}

function js_file_url($file_path) {
    return plugins_url($file_path, __FILE__);
}

function js_plugin_file_path($type, $file_name) {
    $assets_rel_path = "../assets/";
    switch($type) {
        case "script":
            return $assets_rel_path . "js/" . $file_name;
        case "style":
            return $assets_rel_path . "css/" . $file_name;
        case "image":
            return $assets_rel_path . "images/" . $file_name;
        default:
            throw new Exception("invalid asset: " . $type);
    }
}

// Convenience function for registering scripts and styles.
function js_register($type, $handle, $file, $deps) {
    $file_path = js_plugin_file_path($type, $file);
    if ($type === "script") {
        wp_register_script($handle, js_file_url($file_path), $deps, js_filemtime($file_path));
    } else {
        wp_register_style($handle, js_file_url($file_path), $deps, js_filemtime($file_path));
    }
}

// Convenience function for enqueueing scripts and styles.
function js_enqueue($type, $handle, $file, $deps = false) {
    $file_path = js_plugin_file_path($type, $file);
    if ($type === "script") {
        wp_enqueue_script($handle, js_file_url($file_path), $deps, js_filemtime($file_path));
    } else {
        wp_enqueue_style($handle, js_file_url($file_path), $deps, js_filemtime($file_path));
    }
}
