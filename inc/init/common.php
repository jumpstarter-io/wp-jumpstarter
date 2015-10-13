<?php
/*
 * Lib of common functionality needed for the init routine.
 */

// We don't want to run unless invoked by cli.
if (php_sapi_name() !== "cli")
    return;

require_once(dirname(dirname(__FILE__)) . "/jswp-env.php");

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

function js_maybe_run_theme_admin_init() {
    $stylesheet = get_option("stylesheet");
    $env_stylesheet = jswp_env_get_theme();
    if ($stylesheet === $env_stylesheet && jswp_env_get_value("run_theme_init_hooks")) {
        do_action("admin_init");
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
        $plugins = array_unique(array_merge($plugins, array_keys($installed_plugins)));
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

function js_set_current_user() {
    global $current_user;
    $current_user = get_user_by("login", "admin");
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
    if (function_exists("js_log")) {
        js_log("updating table: $table_name");
    }
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
    global $table_prefix;
    // Update post contents with the new siteurl. We do not update the GUID column as
    // this would cause feed readers to think that the post is new when already read.
    js_update_siteurl($old_siteurl, $new_siteurl, $table_prefix . "posts", "ID", array("post_content"));
    // Update postmeta with the new siteurl.
    js_update_siteurl($old_siteurl, $new_siteurl, $table_prefix . "postmeta", "meta_id", array("meta_value"));
    // Update options with the new siteurl.
    js_update_siteurl($old_siteurl, $new_siteurl, $table_prefix . "options", "option_id", array("option_value"));
    // Update legacy table wp_links.
    js_update_siteurl($old_siteurl, $new_siteurl, $table_prefix . "links", "link_id", array("link_url"));
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
    require_once(dirname(dirname(__FILE__)) . "/js-pdo.php");
    unset($GLOBALS["wpdb"]);
    $GLOBALS["wpdb"] = new JSPDODB();
    // Initialize the new db connection with the wp table information.
    wp_set_wpdb_vars();
    $using_js_pdo = true;
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
