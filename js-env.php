<?php

function js_get_env() {
    // Fetch parsed environment from request cache.
    static $env = null;
    if ($env !== null)
        return $env;
    // Fetch parsed environment from server cache.
    if (function_exists("apc_fetch")) {
        $key = "jumpstarter-env";
        $env = apc_fetch($key);
        if (is_array($env))
            return $env;
    }
    // Read environment and parse it.
    $env = json_decode(file_get_contents("/app/env.json"), true);
    if (!is_array($env))
        throw new Exception("could not parse /app/env.json (not jumpstarter container?)");
    // Store parsed environment in server cache.
    if (function_exists("apc_store")) {
        $key = "jumpstarter-env";
        apc_store($key, $env);
    }
    return $env;
}

function js_env_get_value($path) {
    static $cache = array();
    $env = js_get_env();
    if (isset($cache[$path]))
        return $cache[$path];
    $path_arr = explode(".", $path);
    if (count($path_arr) == 0)
        return NULL;
    $obj = $env;
    foreach($path_arr as $part) {
        if (!isset($obj[$part])) {
            $cache[$path] = NULL;
            return NULL;
        }
        $obj = $obj[$part];
    }
    $cache[$path] = $obj;
    return $obj;
}

function js_env_get_theme() {
    return js_env_get_value("ident.app.extra_env.theme");
}

function js_env_get_val_or_array($path) {
    $obj = js_env_get_value($path);
    return is_array($obj)? $obj: array();
}

function js_env_get_plugins() {
    return js_env_get_val_or_array("ident.app.extra_env.plugins");
}

function js_env_get_options() {
    return js_env_get_val_or_array("ident.app.extra_env.options");
}

function js_env_get_user_plugins() {
    return js_env_get_val_or_array("ident.app.extra_env.user_plugins");
}

function js_env_get_disabled_capabilities() {
    $dcaps = js_env_get_val_or_array("ident.app.extra_env.disabled_capabilities");
    // We don't want the user to be able to switch themes.
    // The theme is set in the app config.
    array_push($dcaps, "switch_themes");
    return $dcaps;
}

function js_env_get_siteurl() {
    // Primarily use top user domain if one is configured.
    $env_user_domains = js_env_get_val_or_array("settings.core.user-domains");
    if (is_array($user_domains) && count($user_domains) > 0) {
        $preferred = reset($user_domains);
        foreach ($user_domains as $domain) {
            if ($domain["preferred"]) {
                $preferred = $domain;
                break;
            }
        }
        if (empty($preferred["name"]))
            throw new Exception("corrupt env: preferred domain has no name");
        return ($domain["secure"]? "https": "http") . "://" . $preferred["name"];
    }
    // Fall back to auto domain (always encrypted).
    $auto_domain = js_env_get_value("settings.core.auto-domain");
    if (empty($auto_domain))
        throw new Exception("auto-domain not found in env");
    return "https://" . $auto_domain;
}
