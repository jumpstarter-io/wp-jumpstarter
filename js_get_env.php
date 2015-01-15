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
