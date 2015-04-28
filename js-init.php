<?php
/*
 * This Jumpstarter init file should be pre executed before the web server
 * starts. It indempotently handles automatic wordpress installation if the
 * app is starting for the first time in an instance.
 *
 * You should pre_exec this script before starting the web server by running:
 * php /app/code/src/wp-content/plugins/jumpstarter/js-init.php
 */

require_once "jswp-env.php";

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

function js_init_state_dir() {
    return "/app/code/js-init-state";
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
    $result = activate_plugin($plugin_path);
    if ($result !== null) {
        js_log("failed to activate plugin [$plugin_path]: " . json_encode(array($result->get_error_messages(), $result->get_error_data())));
        exit(1);
    }
}

function js_run_install_scripts() {
    global $js_install_scripts;
    $js_install_scripts = array();
    function js_install_script($name, $fn) {
        $GLOBALS["js_install_scripts"][] = array("name" => $name, "fn" => $fn);
    }
    foreach(glob("/app/code/js-install-scripts/*.php") as $file_path) {
        require_once $file_path;
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
}

function js_db_tmp_dir() {
    return "/tmp/wp-db";
}

function js_sync_theme() {
    $stylesheet = jswp_env_get_theme();
    if (is_string($stylesheet)) {
        js_log("setting theme [$stylesheet]");
        $theme = wp_get_theme($stylesheet);
        if (!$theme->exists())
            throw new Exception("theme to install [$stylesheet] not found!");
        switch_theme($theme->get_stylesheet());
    } else {
        js_log("no theme specified in env, nothing to set");
    }
    // For legacy reasons we define TEMPLATEPATH if not already defined
    // to point at the root directory of the current theme.
    if (!defined('TEMPLATEPATH')) {
        define(TEMPLATEPATH, get_template_directory());
    }
}

function js_load_theme_functions() {
    $stylesheet = jswp_env_get_theme();
    if (is_string($stylesheet)) {
        $root = get_theme_root($stylesheet);
        try {
            include_once("$root/$stylesheet/functions.php");
        } catch (Exception $ex) {
            js_log($ex->getMessage());
            js_log("could not include functions.php");
        }
    }
}

function js_sync_plugins() {
    wp_clean_plugins_cache();
    $core_plugins = js_core_plugins();
    $app_plugins = jswp_env_get_plugins();
    $user_plugins = jswp_env_get_user_plugins();
    $installed_plugins = get_plugins();
    $active_plugins = get_option("active_plugins");
    foreach ($installed_plugins as $i_plugin_path => $plugin) {
        if (in_array($i_plugin_path, $core_plugins))
            continue;
        if (in_array($i_plugin_path, $app_plugins))
            continue;
        if (in_array($i_plugin_path, $user_plugins))
            continue;
        js_log("deactivating app plugin [$i_plugin_path] ($plugin[Name])");
        deactivate_plugins(array($i_plugin_path), true);
    }
    foreach ($app_plugins as $app_plugin_path) {
        if (!isset($installed_plugins[$app_plugin_path]))
            throw new Exception("plugin to install [$app_plugin_path] not found!");
        if (!defined("WP_INSTALL") &&
            in_array($app_plugin_path, $user_plugins) &&
            !in_array($app_plugin_path, $active_plugins)) {
            continue;
        }
        js_log("activating app plugin [$app_plugin_path] (" . $installed_plugins[$app_plugin_path]["Name"] . ")");
        js_activate_plugin($app_plugin_path);
    }
}

function js_install_init_tmp_db() {
    $db_state_dir = js_db_state_dir();
    // Create symlink to allow extremly fast ram based install.
    //$db_tmp_dir = "/tmp/wp-db";
    $db_tmp_dir = js_db_tmp_dir();
    if (file_exists($db_tmp_dir))
        js_eexec("rm -rf " . escapeshellarg($db_tmp_dir));
    js_eexec("mkdir " . escapeshellarg($db_tmp_dir));
    js_eexec("ln -s " . escapeshellarg($db_tmp_dir) . " " . escapeshellarg($db_state_dir));
}

function js_install_finalize_db() {
    // Copy the database over to the state and atomically move it in place to mark wordpress as installed.
    // We delete any old temporary db state to make the install indempotent.
    js_log("copying installed database from ram to state");
    $db_state_dir = js_db_state_dir();
    $db_state_tmp_dir = "/app/state/wp-db.tmp";
    $db_tmp_dir = js_db_tmp_dir();
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
}

function js_restart_script() {
    // Restart the CLI script to run the init phase again to test with an installed wordpress and sync env.
    global $argv;
    $execve_path = PHP_BINARY;
    $execve_args = $argv;
    js_log("****** restarting: [$execve_path], [" . implode(", ", $execve_args) . "] ******");
    pcntl_exec($execve_path, $execve_args);
    exit(1);
}

function js_install_wp() {
    js_install_init_tmp_db();
    // When exiting before succesfull install, return bad error code.
    $install_ok = false;
    register_shutdown_function(function() use (&$install_ok) {
        if (!$install_ok)
            exit(1);
    });

    // Read and prepare configuration for automatic install.
    $is_assembly = !empty(js_env_get_value("ident.container.is_assembly"));
    $blog_title = "My blog";
    $user_name = "admin";
    $user_email = strval(js_env_get_value("ident.user.email"));
    $public = true;
    $deprecated = null;
    // Let wordpress generate a random password for instances, for assemblies, use "test".
    $user_password = $is_assembly? "test": null;
    $language = null;

    // We pretend a user visited wordpress to install it through the generated domain.
    define("WP_SITEURL", js_env_get_siteurl());
    define("WP_INSTALLING", true);
    define("WP_ADMIN", true);

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

    // Search for install scripts and run them.
    js_run_install_scripts();
    // Activate plugins
    js_sync_plugins();
    // Set theme
    js_sync_theme();
    // Try to load the theme functions.php to enable running of install hook.
    js_load_theme_functions();
    // Trigger jumpstarter install hooks.
    do_action("jumpstarter_install");

    js_install_finalize_db();
    $install_ok = true;
    js_log("succesfully installed wordpress!");
    // Restart the js-init script.
    js_restart_script();
}

function rec_replace_string($search_string, $replacement, $data, $serialized = false) {
    try {
        if (is_string($data) && ($unserialized = @unserialize($data)) !== false) {
            $data = rec_replace_string($search_string, $replacement, $unserialized, true);
        } elseif (is_array($data)) {
            $tmp = array();
            foreach($data as $key => $value) {
                $tmp[$key] = rec_replace_string($search_string, $replacement, $value, false);
            }
            $data = $tmp;
            unset($tmp);
        } else {
            if (is_string($data)) {
                $data = str_replace($search_string, $replacement, $data);
            }
        }
        if ($serialized) {
            return serialize($data);
        }
    } catch (Exception $ex) {

    }
    return $data;
}

function js_update_siteurl($old_siteurl, $new_siteurl) {
    global $wpdb;
    // Update post contents.
    $posts = $wpdb->get_results("SELECT ID, post_content FROM wp_posts");
    foreach($posts as $post) {
        $post_content = $post->post_content;
        $modified = rec_replace_string($old_siteurl, $new_siteurl, $post_content);
        if ($post_content !== $modified) {
            $wpdb->query($wpdb->prepare("UPDATE wp_posts SET post_content = %s WHERE ID = %s", $modified, $post->ID));
        }
    }
    // Update post meta.
    $post_metas = $wpdb->get_results("SELECT meta_id, meta_value FROM wp_postmeta");
    foreach($post_metas as $post_meta) {
        $meta_value = $post_meta->meta_value;
        $modified = rec_replace_string($old_siteurl, $new_siteurl, $meta_value);
        if ($meta_value !== $modified) {
            $wpdb->query($wpdb->prepare("UPDATE wp_postmeta SET meta_value = %s WHERE meta_id = %s", $modified, $post_meta->meta_id));
        }
    }
    // Update options.
    $options = $wpdb->get_results("SELECT option_id, option_value FROM wp_options");
    foreach($options as $option) {
        $option_value = $option->option_value;
        $modified = rec_replace_string($old_siteurl, $new_siteurl, $option_value);
        if ($option_value !== $modified) {
            $wpdb->query($wpdb->prepare("UPDATE wp_options SET option_value = %s WHERE option_id = %s", $modified, $option->option_id));
        }
    }
    update_option("siteurl", $new_siteurl);
    update_option("home", $new_siteurl);
}

function js_use_js_pdo() {
    static $using_js_pdo = false;
    if ($using_js_pdo === true)
        return;
    require_once dirname(__FILE__) . "/js-pdo.php";
    unset($GLOBALS["wpdb"]);
    $GLOBALS["wpdb"] = new JSPDODB();
    // Initialize the new db connection with the wp table information.
    wp_set_wpdb_vars();
    $using_js_pdo = true;
}

function js_sync_wp_with_env() {
    js_log("starting env sync");
    // Include wordpress definitions and config.
    js_include_wp();
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
    $wp_siteurl = get_option("siteurl");
    $env_siteurl = js_env_get_siteurl();
    if ($wp_siteurl !== $env_siteurl) {
        js_log("siteurl change detected, migrating from [$wp_siteurl] to [$env_siteurl]");
        js_update_siteurl($wp_siteurl, $env_siteurl);
    } else {
        js_log("no siteurl change detected, keeping [$wp_siteurl]");
    }
    // Apply (sync) wordpress theme from env.
    js_sync_theme();
    // Allow for custom theme hooks to be run later on.
    js_load_theme_functions();
    // Apply (sync) wordpress plugins from env.
    js_sync_plugins();
    // Apply (sync) wordpress options from env.
    js_log("syncing options with env");
    foreach (jswp_env_get_options() as $option => $value) {
        js_log("setting option [$option]: " . json_encode($value));
        update_option($option, $value);
    }
    // Apply (sync) user information from env.
    $admin_user = get_user_by("login", "admin");
    js_log("updating user info");
    $admin_name = "admin";
    $env_user_name = empty(js_env_get_value("ident.user.name"))? $admin_name: js_env_get_value("ident.user.name");
    $env_user_email = js_env_get_value("ident.user.email");
    $user_nicename = ($admin_user->user_nicename !== $admin_name)? $admin_user->user_nicename: $env_user_name;
    $user_displayname = ($admin_user->display_name !== $admin_name)? $admin_user->display_name: $env_user_name;
    wp_update_user(array("ID" => $admin_user->id, "user_email" => $env_user_email, "user_nicename" => $user_nicename, "display_name" => $user_displayname));
    $first_name = "first_name";
    $last_name = "last_name";
    $meta_first_name = get_user_meta($admin_user->ID, $first_name, true);
    $meta_last_name = get_user_meta($admin_user->ID, $last_name, true);
    $user_name_arr = explode(" ", $env_user_name);
    if ($env_user_name !== $admin_name && empty($meta_first_name) && count($user_name_arr) > 0) {
        update_user_meta($admin_user->ID, $first_name, reset($user_name_arr));
    }
    if ($env_user_name !== $admin_name && empty($meta_last_name) && count($user_name_arr) > 1) {
        update_user_meta($admin_user->ID, $last_name, end($user_name_arr));
    }
    // Run theme/plugin hooks for env sync phase.
    do_action("jumpstarter_sync_env");
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

call_user_func(function() {
    // We don't want to run unless invoked by cli.
    if (php_sapi_name() !== "cli")
        return;

    // Check for previous failed installation.
    $db_state_dir = js_db_state_dir();
    if (is_link($db_state_dir)) {
        js_log("found old [$db_state_dir] symlink, assuming previous failed installation");
        js_eexec("rm " . escapeshellarg($db_state_dir));
    }

    $throw_invalid_inode_type_fn = function() use ($db_state_dir) {
        throw new Exception("invalid inode type for path [$db_state_dir] (expected directory)");
    };
    // We don't want to install if already installed.
    if (file_exists($db_state_dir)) {
        if (!is_dir($db_state_dir))
            $throw_invalid_inode_type_fn();
        js_log("skipping wordpress install (already done)");
    } else {
        if (is_link($db_state_dir))
            $throw_invalid_inode_type_fn();
        $init_state_dir = js_init_state_dir();
        $init_state_db_dir = "$init_state_dir/wp-db";
        if (file_exists($init_state_dir) && file_exists($init_state_db_dir)) {
            // Not installed but we've got a js-init-state directory.
            js_log("installing wordpress from init state directory");
            js_install_init_tmp_db();
            $db_state_dir = js_db_state_dir();
            js_eexec("cp -r $init_state_db_dir/. " . escapeshellarg("$db_state_dir/"));
            js_include_wp();
            js_use_js_pdo();
            try {
                js_log("syncing_data");
                global $wpdb;
                // Start transaction that spans all queries until explicit commit.
                $wpdb->begin();
                // Since we've installed a db copy we need to update the user's email.
                $admin_user = get_user_by("login", "admin");
                $admin_name = "admin";
                wp_update_user(array("ID" => $admin_user->id, "user_email" => js_env_get_value("ident.user.email"), "user_nicename" => $admin_name, "display_name" => $admin_name));
                update_user_meta($admin_user->ID, "first_name", "");
                update_user_meta($admin_user->ID, "last_name", "");
                // Also set a random password for the admin account.
                wp_set_password(wp_generate_password(), $admin_user->id);

                $wpdb->commit();
            } catch (Exception $e) {
                js_log($e->getMessage());
                exit(1);
            }
            // Copy everything in the init-state dir that's not the database.
            js_eexec("rsync -r --exclude 'wp-db' $init_state_dir/* /app/state/");
            // Make final atomic move of db.
            js_install_finalize_db();
            // Restart the init script.
            js_restart_script();
        } else {
            // Wordpress is not installed.
            js_log("installing wordpress (first run)");
            js_install_wp();
        }
    }

    // Enforce wordpress configuration by environment.
    js_sync_wp_with_env();
});
