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

function js_db_dir() {
    return "/app/code/wp-db";
}

function js_db_tmp_dir() {
    return "/tmp/wp-db";
}

function js_init_state_dir() {
    return "/app/code/js-init-state";
}

function js_container_option_key() {
    return "js_container_id";
}

function js_env_container_id_key_path() {
    return "ident.container.id";
}

function js_is_assembly() {
    $is_assembly = js_env_get_value("ident.container.is_assembly");
    return $is_assembly === true;
}

function js_include_wp() {
    // Only include once.
    static $wp_included = false;
    if ($wp_included)
        return;
    define("ABSPATH", "/app/code/src/");
    // We pretend a user visited wordpress to install it through the generated domain.
    define("WP_SITEURL", js_env_get_siteurl());
    define("WP_ADMIN", true);
    // Include wordpress from CLI.
    $_SERVER['HTTPS'] = "on";
    if (defined("WP_INSTALLING") && !defined("TEMPLATEPATH")) {
        define("TEMPLATEPATH", ABSPATH . "wp-content/themes/" . jswp_env_get_theme());
    }
    js_log("including wp-load.php");
    require_once(ABSPATH . "wp-load.php");
    js_log("including upgrade.php");
    require_once(ABSPATH . "wp-admin/includes/upgrade.php");

    // Dev sanity check: Ensure that DB_DIR is set to the right value.
    $db_dir = js_db_dir();
    if (!defined("DB_DIR") || DB_DIR !== $db_dir) {
        js_log("error: DB_DIR is not set to the correct value in wp-config.php, it should be set to [$db_dir]");
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

function js_run_install_scripts() {
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
}

function js_sync_theme() {
    $stylesheet = get_option("stylesheet");
    $env_stylesheet = jswp_env_get_theme();
    // If were not installing and the user has changed stylesheet, then that's fine.
    if (!defined("WP_INSTALLING") && $stylesheet !== $env_stylesheet) {
        js_log("using user defined theme [$stylesheet]");
        return;
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
}

function js_load_theme_functions() {
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
}

// This function is used to make sure that the Jumpstarter core plugins
// gets loaded before any other plugins to make sure that url modifying filters
// in the Jumpstarter plugin gets registered before WordPress internal functions
// are called by 3d party plugins.
function js_sync_plugins_load_order() {
    js_log("syncing plugins load order");
    function set_item_at_arr_pos(&$arr, $item, $pos) {
        $item_pos = array_search($item, $arr);
        if ($item_pos !== FALSE && $item_pos !== $pos) {
            array_splice($arr, $item_pos, 1);
            array_splice($arr, $pos, 0, array($item));
        }
    }
    $active_plugins = get_option("active_plugins");
    $idx = 0;
    foreach (jswp_env_core_plugins() as $plugin) {
        set_item_at_arr_pos($active_plugins, $plugin, $idx++);
    }
    update_option("active_plugins", $active_plugins);
}

function js_activate_plugin($plugin_path) {
    $result = activate_plugin($plugin_path);
    if ($result !== null) {
        js_log("failed to activate plugin [$plugin_path]: " . json_encode(array($result->get_error_messages(), $result->get_error_data())));
        exit(1);
    }
}

function js_sync_plugins() {
    wp_clean_plugins_cache();
    $plugins = jswp_env_core_plugins();
    $installed_plugins = get_plugins();
    // If we're installing we make sure that all installed plugins get activated. Later
    // on it's up to the user to say if a plugin should be activated or not.
    if (defined("WP_INSTALLING")) {
        $plugins = array_merge($plugins, array_keys($installed_plugins));
    }
    foreach ($plugins as $plugin_key) {
        if (!isset($installed_plugins[$plugin_key])) {
            throw new Exception("plugin to install [$plugin_key] not found!");
        }
        js_log("activating app plugin [$plugin_key] ({$installed_plugins[$plugin_key]['Name']})");
        js_activate_plugin($plugin_key);
    }
}

function js_install_set_defines() {
    if (!defined("WP_INSTALLING"))
        define("WP_INSTALLING", true);
}

function js_install_init_tmp_db() {
    $db_dir = js_db_dir();
    // Create symlink to allow extremly fast ram based install.
    //$db_tmp_dir = "/tmp/wp-db";
    $db_tmp_dir = js_db_tmp_dir();
    if (file_exists($db_tmp_dir))
        js_eexec("rm -rf " . escapeshellarg($db_tmp_dir));
    js_eexec("mkdir " . escapeshellarg($db_tmp_dir));
    js_eexec("ln -s " . escapeshellarg($db_tmp_dir) . " " . escapeshellarg($db_dir));
}

function js_install_finalize_db() {
    // Copy the database over to the state and atomically move it in place to mark wordpress as installed.
    // We delete any old temporary db state to make the install idempotent.
    js_log("copying installed database from ram to state");
    $db_dir = js_db_dir();
    $db_code_tmp_dir = "/app/code/wp-db.tmp";
    $db_tmp_dir = js_db_tmp_dir();
    if (file_exists($db_code_tmp_dir))
        js_eexec("rm -rf " . escapeshellarg($db_code_tmp_dir));
    js_eexec("cp -rp ". escapeshellarg($db_tmp_dir) . " " . escapeshellarg($db_code_tmp_dir));
    js_eexec("rm -rf " . escapeshellarg($db_tmp_dir));
    js_eexec("rm " . escapeshellarg($db_dir));
    js_log("syncing data to state");
    js_eexec("sync");
    js_log("final atomic move");
    js_eexec("mv ". escapeshellarg($db_code_tmp_dir) . " " . escapeshellarg($db_dir));
    // Wait for sync before considering installation complete.
    js_eexec("sync");
}

function js_restart_init_script() {
    // Restart the CLI script to run the init phase again to test with an installed wordpress and sync env.
    global $argv;
    $execve_path = PHP_BINARY;
    $execve_args = $argv;
    js_log("****** restarting: [$execve_path], [" . implode(", ", $execve_args) . "] ******");
    pcntl_exec($execve_path, $execve_args);
    exit(1);
}

function js_install_update_user_info() {
    // Set user information during install.
    if (!defined("WP_INSTALLING"))
        return;
    js_log("updating user info");
    $admin_user = get_user_by("login", "admin");
    $env_name = js_env_get_value("ident.user.name");
    $admin_default_name = "admin";
    $user_name = empty($env_name)? $admin_default_name: $env_name;
    $env_email = js_env_get_value("ident.user.email");
    $user_name_arr = explode(" ", $user_name);
    wp_update_user(array(
        "ID" => $admin_user->ID,
        "user_email" => $env_email,
        "user_nicename" => $user_name,
        "display_name" => $user_name,
        "first_name" => reset($user_name_arr),
        "last_name" => end($user_name_arr)
    ));
}

function js_set_db_container_id() {
    update_option(js_container_option_key(), js_env_get_value(js_env_container_id_key_path()));
}

function js_get_db_container_id() {
    return get_option(js_container_option_key());
}

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
    js_include_wp();

    // Install wordpress now.
    js_log("running wordpress installer with name:[$user_name], email:[$user_email], password:[$user_password]");
    wp_install($blog_title, $user_name, $user_email, true, $deprecated, $user_password, $language);
    js_install_update_user_info();
    // Activate core and developer specified plugins.
    js_log("activate plugins");
    js_sync_plugins();

    // Search for install scripts and run them.
    js_run_install_scripts();
    // Set theme.
    js_sync_theme();
    // Try to load the theme functions.php to enable running of install hook.
    js_load_theme_functions();
    // Tell the theme that it's alive.
    do_action("after_setup_theme");
    // Trigger any admin_init listeners that're waiting for theme initialization.
    do_action("admin_init");
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

function rec_replace_string($search_string, $replacement, $data, $serialized = false) {
    try {
        if (is_string($data) && ($unserialized = @unserialize($data)) !== false) {
            $data = rec_replace_string($search_string, $replacement, $unserialized, true);
        } else if (is_array($data)) {
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

function js_update_siteurl($old_siteurl, $new_siteurl, $table_name, $id_column, $columns) {
    global $wpdb;
    $rows = $wpdb->get_results(sprintf("SELECT $id_column, %s from $table_name", implode(",", $columns)));
    foreach ($rows as $row) {
        foreach ($columns as $column) {
            $value = $row->$column;
            $modified_value = rec_replace_string($old_siteurl, $new_siteurl, $value);
            if ($value !== $modified_value) {
                $wpdb->query($wpdb->prepare("UPDATE $table_name SET $column = %s WHERE $id_column = %s", $modified_value, $row->$id_column));
            }
        }
    }
}

function js_update_siteurls($old_siteurl, $new_siteurl) {
    // Update post contents with the new siteurl. We do not update the GUID column as
    // this would cause feed readers to think that the post is new when already read.
    js_update_siteurl($old_siteurl, $new_siteurl, "wp_posts", "ID", array("post_content"));
    // Update postmeta with the new siteurl.
    js_update_siteurl($old_siteurl, $new_siteurl, "wp_postmeta", "meta_id", array("meta_value"));
    // Update options with the new siteurl.
    js_update_siteurl($old_siteurl, $new_siteurl, "wp_options", "option_id", array("option_value"));
}

function js_get_siteurl() {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s LIMIT 1", "siteurl"));
    if (is_object( $row ))
       return $row->option_value;
    throw new Exception("wordpress option [siteurl] is not set");
}

function js_use_js_pdo() {
    static $using_js_pdo = false;
    if ($using_js_pdo === true)
        return;
    require_once(dirname(__FILE__) . "/inc/js-pdo.php");
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
    // Order the plugins list if needed.
    js_sync_plugins_load_order();
    // Apply (sync) wordpress plugins from env.
    js_sync_plugins();
    // Apply (sync) wordpress theme from env.
    js_sync_theme();
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

function js_gen_salt_string($length = 64) {
    $pchars = "Z>Y;wb+NeILx:!sCcm9}Kt|jXu8i.MWP5zd2$]l4=7E %/R6A^#F,V0T3o(k_*-DyfS&g`qHQJ1?pUv@na)G<~BhOr{[";
    $pchars_len = strlen($pchars);
    $salt = "";
    for ($i = 0; $i < $length; $i++) {
        $salt .= $pchars[mt_rand() % $pchars_len];
    }
    return $salt;
}

function js_set_config_salts() {
    js_log("Settings wp config salts");
    // Check if config salts are needed or if we've already set them up.
    $config_path = "/app/code/src/wp-config.php";
    $config = file_get_contents($config_path);
    $js_config_salts_set = "/* DO NOT REMOVE: js_config_salts_set: DO NOT REMOVE */";
    if (strpos($config, $js_config_salts_set) !== false) {
        js_log("Salts already set");
        return;
    }
    // Generate all config salts.
    $salts = array(
        "AUTH_KEY" => js_gen_salt_string(),
        "SECURE_AUTH_KEY" => js_gen_salt_string(),
        "LOGGED_IN_KEY" => js_gen_salt_string(),
        "NONCE_KEY" => js_gen_salt_string(),
        "AUTH_SALT" => js_gen_salt_string(),
        "SECURE_AUTH_SALT" => js_gen_salt_string(),
        "LOGGED_IN_SALT" => js_gen_salt_string(),
        "NONCE_SALT" => js_gen_salt_string()
    );
    // Replace config salts in wp-config.
    foreach ($salts as $k => $v) {
        $rep_cb = function() use($k, $v) {
            return "define('" . $k . "',    '" . $v . "');";
        };
        $config = preg_replace_callback("/define\('" . $k . "',\s+'[^\']*'\);/", $rep_cb, $config);
    }
    // Put the js bom in the config file.
    $config = $config . $js_config_salts_set;
    // Write the config file to disk.
    file_put_contents($config_path, $config);
    // Restart the script to use the new salts for installation.
    js_log("Salts set, restarting");
    js_restart_init_script();
}

function js_update_fastcgi_params() {
    $file_path = "/app/code/nginx/fastcgi.conf";
    $config = file_get_contents($file_path);
    $is_https = js_domain_is_https();
    js_log("Turning " . ($is_https? "on": "off") . " fastcgi HTTPS config");
    $param_regx = "/fastcgi_param\s+HTTPS\s+\"(on|off)\";/";
    $replacement = "fastcgi_param HTTPS \"" . ($is_https? "on": "off") . "\";";
    if (!preg_match($param_regx, $config)) {
        $new_config = $config . "\n" . $replacement;
    } else {
        $new_config = preg_replace($param_regx, $replacement, $config);
    }
    file_put_contents($file_path, $new_config);
}

call_user_func(function() {
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
        js_include_wp();
        $oid = js_get_db_container_id();
        $cid = js_env_get_value(js_env_container_id_key_path());
        if ($oid !== $cid) {
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
            js_include_wp();
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
});
