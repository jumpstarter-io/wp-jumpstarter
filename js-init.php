<?php
/*
 * This Jumpstarter init file should be pre executed before the web server
 * starts. It idempotently handles automatic wordpress installation if the
 * app is starting for the first time in an instance.
 *
 * You should pre_exec this script before starting the web server by running:
 * php /app/code/src/wp-content/plugins/jumpstarter/js-init.php
 */

// We don't want to run unless invoked by cli.
if (php_sapi_name() !== "cli")
    return;

require_once(dirname(__FILE__) . "/inc/jswp-env.php");
require_once(dirname(__FILE__) . "/inc/jswp-util.php");
require_once(dirname(__FILE__) . "/inc/init/common.php");

function js_install_wp() {
    js_install_set_defines();
    js_install_init_tmp_db();
    // When exiting before succesfull install, return bad error code.
    $install_ok = false;
    register_shutdown_function(function() use (&$install_ok) {
        if (!$install_ok)
            exit(1);
    });

    // Read and prepare configuration for automatic install.
    $blog_title = "My blog";
    $user_name = "admin";
    $user_email = strval(js_env_get_value("ident.user.email"));
    $deprecated = null;
    // Let wordpress generate a random password for instances, for assemblies, use "test".
    $user_password = js_is_assembly()? "test": null;
    $language = null;

    // Include wordpress definitions and config.
    require_once(dirname(__FILE__) . "/inc/init/js-include-wp.php");

    // Install wordpress now.
    js_log("running wordpress installer with name:[$user_name], email:[$user_email], password:[$user_password]");
    wp_install($blog_title, $user_name, $user_email, true, $deprecated, $user_password, $language);
    js_install_update_user_info();
    // Set the new current user.
    js_set_current_user();
    // Activate core and developer specified plugins.
    js_log("activate plugins");
    js_sync_plugins();

    // Search for install scripts and run them.
    require_once(dirname(__FILE__) . "/inc/init/js-run-install-scripts.php");
    // Set theme.
    require_once(dirname(__FILE__) . "/inc/init/js-sync-theme.php");
    // Try to load the theme functions.php to enable running of install hook.
    require_once(dirname(__FILE__) . "/inc/init/js-load-theme-functions.php");
    if (jswp_env_get_value("run_theme_init_hooks")) {
        // Tell the theme that it's alive.
        do_action("after_setup_theme");
        // Trigger any admin_init listeners that're waiting for theme initialization.
        do_action("admin_init");
    }
    // Set the container key.
    js_set_db_container_id();
    // Trigger jumpstarter install hooks.
    do_action("jumpstarter_install");
    // Finalize installation by moving the database files to the correct location.
    js_install_finalize_db();
    $install_ok = true;
    js_log("succesfully installed wordpress!");
    // Restart the js-init script.
    js_restart_init_script();
}

function js_sync_wp_with_env() {
    js_log("starting env sync");
    // Include wordpress definitions and config.
    require_once(dirname(__FILE__) . "/inc/init/js-include-wp.php");
    // Switch to using the JS pdo classes.
    js_use_js_pdo();
    global $wpdb;
    // Start a transaction that spans all queries until explicit commit instead
    // of the sqlite plugin's standard begin/commit around each query.
    // This ensures that we don't store partial sync phases in case of script failure.
    try {
        $wpdb->begin();
    } catch (Exception $e) {
        js_log($e->getMessage());
        exit(1);
    }
    // Sync wordpress domain if it changed.
    $wp_siteurl = js_get_siteurl();
    $env_siteurl = js_env_get_siteurl();
    if ($wp_siteurl !== $env_siteurl) {
        js_log("siteurl change detected, migrating from [$wp_siteurl] to [$env_siteurl]");
        js_update_siteurls($wp_siteurl, $env_siteurl);
        // Set previous siteurl to allow for changes in themes/plugins on "jumpstarter_sync_env" hook.
        update_option("js_siteurl_old", $wp_siteurl);
    } else {
        js_log("no siteurl change detected, keeping [$wp_siteurl]");
    }
    // Set the current user.
    js_set_current_user();
    // Order the plugins list if needed.
    js_sync_plugins_load_order();
    // Apply (sync) wordpress plugins from env.
    js_sync_plugins();
    // Apply (sync) wordpress theme from env.
    $synced_theme = require_once(dirname(__FILE__) . "/inc/init/js-sync-theme.php");
    if ($synced_theme) {
        // Tell the theme we're up and running.
        if (jswp_env_get_value("run_theme_init_hooks")) {
            do_action("after_setup_theme");
        }
    }
    js_maybe_run_theme_admin_init();
    // No need to load the theme functions since it's already done by the wp include.
    // Apply (sync) wordpress options from env.
    js_log("syncing options with env");
    foreach (jswp_env_get_options() as $option => $value) {
        js_log("setting option [$option]: " . json_encode($value));
        update_option($option, $value);
    }
    // Run theme/plugin hooks for env sync phase.
    do_action("jumpstarter_sync_env");
    // Remove the siteurl option since it no longer should be used.
    delete_option("js_siteurl_old");
    // Commit all changes to db in one go.
    try {
        $wpdb->commit();
    } catch (Exception $e) {
        js_log($e->getMessage());
        js_log("could not sync env with wp");
        exit(1);
    }
    js_log("completed env sync");
}

// Check for previous failed installation.
$db_dir = js_db_dir();
if (is_link($db_dir)) {
    js_log("found old [$db_dir] symlink, assuming previous failed installation");
    js_eexec("rm " . escapeshellarg($db_dir));
}

$throw_invalid_inode_type_fn = function() use ($db_dir) {
    throw new Exception("invalid inode type for path [$db_dir] (expected directory)");
};
// Maybe set config salts.
js_set_config_salts();
// We don't want to install if already installed.
if (file_exists($db_dir)) {
    if (!is_dir($db_dir))
        $throw_invalid_inode_type_fn();
    // Make sure that this installation belongs to this container.
    require_once(dirname(__FILE__) . "/inc/init/js-include-wp.php");
    $oid = js_get_db_container_id();
    $cid = js_env_get_value(js_env_container_id_key_path());
    if (js_is_short_id($oid) && $oid !== $cid) {
        js_log("container id changed from $oid to $cid");
        js_eexec("rm -rf " . escapeshellarg(js_db_dir()));
        js_restart_init_script();
    }
    js_log("skipping wordpress install (already done)");
} else {
    $init_state_dir = js_init_state_dir();
    $init_state_db_dir = "$init_state_dir/wp-db";
    if (file_exists($init_state_dir) && file_exists($init_state_db_dir)) {
        // Not installed but we've got a js-init-state directory.
        js_log("installing wordpress from init state directory");
        js_install_set_defines();
        js_install_init_tmp_db();
        $db_dir = js_db_dir();
        js_eexec("cp -r $init_state_db_dir/. " . escapeshellarg("$db_dir/"));
        if (file_exists("$init_state_dir/uploads")) {
            $uploads_pdir = "/app/code/src/wp-content/";
            js_eexec("cp -r $init_state_dir/uploads $uploads_pdir");
        }
        require_once(dirname(__FILE__) . "/inc/init/js-include-wp.php");
        js_use_js_pdo();
        try {
            js_log("syncing_data");
            global $wpdb;
            // Start transaction that spans all queries until explicit commit.
            $wpdb->begin();
            // Set a random password for the admin account.
            $admin_user = get_user_by("login", "admin");
            wp_set_password(js_is_assembly()? "test": wp_generate_password(), $admin_user->id);
            // Since we've installed a db copy we need to update the user info.
            js_install_update_user_info();
            // Set current container id.
            js_set_db_container_id();
            // Commit all database changes.
            $wpdb->commit();
        } catch (Exception $e) {
            js_log($e->getMessage());
            exit(1);
        }
        // Make final atomic move of db.
        js_install_finalize_db();
        // Restart the init script.
        js_restart_init_script();
    } else {
        // Wordpress is not installed.
        js_log("installing wordpress (first run)");
        js_install_wp();
    }
}

// Configure whether to say to WordPress if we use https or not.
js_update_fastcgi_params();

// Enforce wordpress configuration by environment.
js_sync_wp_with_env();
