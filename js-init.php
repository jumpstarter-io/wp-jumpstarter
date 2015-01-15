<?php
/*
 * This Jumpstarter init file should be pre executed before the web server
 * starts. It indempotently handles automatic wordpress installation if the
 * app is starting for the first time in an instance.
 *
 * You should pre_exec this script before starting the web server by running:
 * php /app/code/src/wp-content/mu-plugins/jumpstarter/js-auto-install.php
 */

require_once "js_get_env.php";

// Log to stderr.
function js_log($msg) {
    fwrite(STDERR, "[js-init.php] $msg\n");
}

// Execute wrapper that throws exception and leaks output if command fails.
function js_eexec($cmd) {
    exec($cmd, $out, $ret);
    // The execute could have side effects on the file system that PHP
    // doesn't know about. We need to clear the stat cache.
    clearstatcache();
    if ($ret !== 0) {
        fwrite(STDERR, implode("\n", $out));
        throw new Exception("executing cmd [$cmd] failed");
    }
}

function js_db_state_dir() {
    return "/app/state/wp-db";
}

// Returns core wp-jumpstarter plugins that can never be disabled.
function js_core_plugins() {
    return array(
        "jumpstarter/jumpstarter.php",
        "sqlite-integration/sqlite-integration.php",
    );
}

function js_include_wp() {
    // Only include once.
    static $wp_included = false;
    if ($wp_included)
        return;

    // Include wordpress from CLI.
    define("ABSPATH", realpath(__DIR__ . "/../../../") . "/");
    $_SERVER['HTTPS'] = "on";
    js_log("including wp-load.php");
    require_once(ABSPATH . "wp-load.php");
    js_log("including upgrade.php");
    require_once(ABSPATH . "wp-admin/includes/upgrade.php");

    // Dev sanity check: Ensure that DISALLOW_FILE_MODS is set to the right value.
    if (!defined("DISALLOW_FILE_MODS") || DISALLOW_FILE_MODS !== true) {
        js_log("error: DISALLOW_FILE_MODS is not set to the correct value in wp-config.php, it should be set to [true]");
        exit;
    }

    // Dev sanity check: Ensure that DB_DIR is set to the right value.
    $db_state_dir = js_db_state_dir();
    if (!defined("DB_DIR") || DB_DIR !== $db_state_dir) {
        js_log("error: DB_DIR is not set to the correct value in wp-config.php, it should be set to [$db_state_dir]");
        exit;
    }

    // Dev sanity check: Ensure that there is no database folder or symlink.
    $bad_db_path = (ABSPATH . "wp-content/database");
    if (file_exists($bad_db_path) || is_link($bad_db_path)) {
        js_log("error: the path [$bad_db_path] exists, it's a security hazard and should be removed");
        exit;
    }

    // Wordpress is included now.
    $wp_included = true;
}

function js_activate_plugin($plugin_path) {
    $result = activate_plugin($plugin_path, "", false, true);
    if ($result !== null) {
        js_log("failed to activate plugin!\n" . json_encode($result->get_error_messages()));
        exit(1);
    }
}

function js_install_wp() {
    $db_state_dir = js_db_state_dir();
    // Create symlink to allow extremly fast ram based install.
    $db_tmp_dir = "/tmp/wp-db";
    if (file_exists($db_tmp_dir))
        js_eexec("rm -rf " . escapeshellarg($db_tmp_dir));
    js_eexec("mkdir " . escapeshellarg($db_tmp_dir));
    js_eexec("ln -s " . escapeshellarg($db_tmp_dir) . " " . escapeshellarg($db_state_dir));

    // When exiting before succesfull install, return bad error code.
    $install_ok = false;
    register_shutdown_function(function() use (&$install_ok) {
        if (!$install_ok)
            exit(1);
    });

    // Read and prepare configuration for automatic install.
    $env = js_get_env();
    $is_assembly = !empty($env["ident"]["container"]["is_assembly"]);
    $blog_title = "My blog";
    $user_name = "admin";
    $user_email = strval($env["ident"]["user"]["email"]);
    $public = true;
    $deprecated = null;
    // Let wordpress generate a random password for instances, for assemblies, use "test".
    $user_password = $is_assembly? "test": null;
    $language = null;

    // We pretend a user visited wordpress to install it through the generated domain.
    define("WP_SITEURL", js_get_env_siteurl($env));
    define("WP_INSTALLING", true);

    // Include wordpress definitions and config.
    js_include_wp();

    // Install wordpress now.
    js_log("running wordpress installer with name:[$user_name], email:[$user_email], password:[$user_password]");
    wp_install($blog_title, $user_name, $user_email, $public, $deprecated, $user_password, $language);

    // Silently activate all required plugins.
    // The developer should have removed plugins that should not be activated.
    js_log("activate plugins");
    foreach (js_core_plugins() as $plugin_key) {
        js_log("activating core plugin [$plugin_key]");
        js_activate_plugin($plugin_key);
    }

    // Copy the database over to the state and atomically move it in place to mark wordpress as installed.
    // We delete any old temporary db state to make the install indempotent.
    js_log("copying installed database from ram to state");
    $db_state_tmp_dir = "/app/state/wp-db.tmp";
    if (file_exists($db_state_tmp_dir))
        js_eexec("rm -rf " . escapeshellarg($db_state_tmp_dir));
    js_eexec("cp -rp ". escapeshellarg($db_tmp_dir) . " " . escapeshellarg($db_state_tmp_dir));
    js_eexec("rm -rf " . escapeshellarg($db_tmp_dir));
    js_eexec("rm " . escapeshellarg($db_state_dir));
    js_log("syncing data to state");
    js_eexec("sync");
    js_log("final atomic move");
    js_eexec("mv ". escapeshellarg($db_state_tmp_dir) . " " . escapeshellarg($db_state_dir));

    // Wait for sync before considering installation complete.
    js_eexec("sync");
    $install_ok = true;
    js_log("succesfully installed wordpress!");

    // Restart the CLI script to run the init phase again to test with an installed wordpress and sync env.
    global $argv;
    $execve_path = PHP_BINARY;
    $execve_args = $argv;
    js_log("****** restarting: [$execve_path], [" . implode(", ", $execve_args) . "] ******");
    pcntl_exec($execve_path, $execve_args);
    exit(1);
}

function js_update_siteurl($old_siteurl, $new_siteurl) {
    global $wpdb;
    $wpdb->query($wpdb->prepare("UPDATE wp_posts SET post_content = replace(post_content, %s, %s)", $old_siteurl, $new_siteurl));
    update_option("siteurl", $new_siteurl);
    update_option("home", $new_siteurl);
}

function js_get_env_siteurl($env) {
    // Primarily use top user domain if one is configured.
    $user_domains = isset($env["settings"]["core"]["user-domains"])? $env["settings"]["core"]["user-domains"]: array();
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
    if (empty($env["settings"]["core"]["auto-domain"]))
        throw new Exception("auto-domain not found in env");
    return "https://" . $env["settings"]["core"]["auto-domain"];
}

function js_get_env_theme($env) {
    if (!isset($env["ident"]["app"]["extra_env"]["theme"]))
        return null;
    return $env["ident"]["app"]["extra_env"]["theme"];
}

function js_get_env_plugins($env) {
    if (!isset($env["ident"]["app"]["extra_env"]["plugins"]))
        return array();
    $plugins = $env["ident"]["app"]["extra_env"]["plugins"];
    if (!is_array($plugins))
        return array();
    return $plugins;
}

function js_sync_wp_with_env() {
    js_log("starting env sync");
    $env = js_get_env();
    // Include wordpress definitions and config.
    js_include_wp();
    // Sync wordpress domain if it changed.
    $wp_siteurl = get_option("siteurl");
    $env_siteurl = js_get_env_siteurl($env);
    if ($wp_siteurl !== $env_siteurl) {
        js_log("siteurl change detected, migrating from [$wp_siteurl] to [$env_siteurl]");
        js_update_siteurl($wp_siteurl, $env_siteurl);
    } else {
        js_log("no siteurl change detected, keeping [$wp_siteurl]");
    }
    // Apply (sync) wordpress theme from env.
    $stylesheet = js_get_env_theme($env);
    if (is_string($stylesheet)) {
        js_log("setting theme [$stylesheet]");
        $theme = wp_get_theme($stylesheet);
        if (!$theme->exists())
            throw new Exception("theme to install [$stylesheet] not found!");
        switch_theme($theme->get_stylesheet());
    } else {
        js_log("no theme specified in env, nothing to set");
    }
    // Apply (sync) wordpress plugins from env.
    $core_plugins = js_core_plugins();
    $app_plugins = js_get_env_plugins($env);
    $installed_plugins = get_plugins();
    foreach ($installed_plugins as $i_plugin_path => $plugin) {
        if (in_array($i_plugin_path, $core_plugins))
            continue;
        if (in_array($i_plugin_path, $app_plugins))
            continue;
        js_log("deactivating app plugin [$i_plugin_path] ($plugin[Name])");
        deactivate_plugins(array($i_plugin_path), true);
    }
    foreach ($app_plugins as $app_plugin_path) {
        if (!isset($installed_plugins[$app_plugin_path]))
            throw new Exception("plugin to install [$app_plugin_path] not found!");
        js_log("activating app plugin [$app_plugin_path] (" . $installed_plugins[$app_plugin_path]["Name"] . ")");
        js_activate_plugin($app_plugin_path);
    }
    js_log("completed env sync");
}

call_user_func(function() {
    // We don"t want to run unless invoked by cli.
    if (php_sapi_name() !== "cli")
        return;

    // Check for previous failed installation.
    $db_state_dir = js_db_state_dir();
    if (is_link($db_state_dir)) {
        js_log("found old [$db_state_dir] symlink, assuming previous failed installation");
        js_eexec("rm " . escapeshellarg($db_state_dir));
    }

    // We don"t want to install if already installed.
    $throw_invalid_inode_type_fn = function() use ($db_state_dir) {
        throw new Exception("invalid inode type for path [$db_state_dir] (expected directory)");
    };
    if (file_exists($db_state_dir)) {
        if (!is_dir($db_state_dir))
            $throw_invalid_inode_type_fn();
        js_log("skipping wordpress install (already done)");
    } else {
        if (is_link($db_state_dir))
            $throw_invalid_inode_type_fn();
        js_log("installing wordpress (first run)");
        js_install_wp();
    }

    // Enforce wordpress configuration by environment.
    js_sync_wp_with_env();
});
