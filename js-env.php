<?php

// Don't load directly unless in cli.
if (php_sapi_name() !== "cli" && !defined("ABSPATH"))
    die("-1");

define("JS_ENV_PATH", "/app/env.json");
define("JS_ENV_APC_KEY", "jumpstarter-env");

function js_get_env($env_path = JS_ENV_PATH, $apc_key = JS_ENV_APC_KEY) {
    // Fetch parsed environment from request cache.
    static $envs = array();
    if (isset($envs[$env_path]) && $envs[$env_path] !== null)
        return $envs[$env_path];
    // Fetch parsed environment from server cache.
    if (function_exists("apc_fetch")) {
        $env = apc_fetch($apc_key);
        if (is_array($env)) {
            $envs[$env_path] = $env;
            return $env;
        }
    }
    // Read environment and parse it.
    $env = json_decode(file_get_contents($env_path), true);
    if (!is_array($env))
        throw new Exception("could not parse $env_path (not jumpstarter container?)");
    // Store parsed environment in server cache.
    if (function_exists("apc_store")) {
        apc_store($apc_key, $env);
    }
    $envs[$env_path] = $env;
    return $env;
}

function js_env_get_value($key_path, $env_path = JS_ENV_PATH, $apc_key = JS_ENV_APC_KEY) {
    static $cache = array();
    if (isset($cache[$env_path][$key_path]))
        return $cache[$env_path][$key_path];
    $env = js_get_env($env_path, $apc_key);
    $path_arr = explode(".", $key_path);
    if (count($path_arr) === 0)
        return null;
    $obj = $env;
    foreach($path_arr as $part) {
        if (!isset($obj[$part])) {
            $cache[$env_path][$key_path] = null;
            return null;
        }
        $obj = $obj[$part];
    }
    $cache[$env_path][$key_path] = $obj;
    return $obj;
}

function js_env_get_value_or_array($path, $env_path = JS_ENV_PATH, $apc_key = JS_ENV_APC_KEY) {
    $obj = js_env_get_value($path, $env_path, $apc_key);
    return is_array($obj)? $obj: array();
}

function js_env_get_siteurl() {
    // Primarily use top user domain if one is configured.
    $user_domains = js_env_get_value_or_array("settings.core.user-domains");
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

function js_env_token_auth_verify($z) {
    $x = js_env_get_value("ident.container.session_key");
    // Sanity check $x and $z.
    if (!is_string($z) || !is_string($x))
        return false;
    if (strlen($z) != 144 || strlen($x) != 64)
        return false;
    if (!ctype_xdigit($z) || !ctype_xdigit($x))
        return false;
    // Decode all cookie parameters.
    $z_raw = hex2bin($z);
    $e_raw = substr($z_raw, 0, 8);
    // Expire time.
    $e = implode(unpack("N", substr($e_raw, 4, 4)));
    // Random salt.
    $y = substr($z_raw, 8, 32);
    // sha256 hash signature.
    $h = substr($z_raw, 40);
    // Cookie must not have expired.
    if ($e < time())
        return false;
    // Calculate the signature we expect.
    $h_challenge = hash("sha256", ($e_raw . $y . hex2bin($x)), true);
    // Sanity check before comparing.
    if (!is_string($h) || strlen($h) != 32)
        return false;
    if (!is_string($h_challenge) || strlen($h_challenge) != 32)
        return false;
    // Final authorization.
    return ($h === $h_challenge);
}
