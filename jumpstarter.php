<?php
/**
 * Plugin Name: Jumpstarter
 * Plugin URI: https://github.com/jumpstarter-io/
 * Description: Wordpress Jumpstarter integration.
 * Author: Jumpstarter
 * Author URI: https://jumpstarter.io/
 * License: Public Domain
 */

// Don't load directly.
if (!defined("ABSPATH"))
    die("-1");

require_once "jswp-env.php";

// Only allow full access to plugin activation/deactivation from cli.
// Allow activation/deactivation of plugins specified in the app env.
if (php_sapi_name() !== "cli") {
    add_action("activate_plugin", function($plugin_key) {
        if (!in_array($plugin_key, jswp_env_get_user_plugins()))
            wp_die(__("Plugin activation not allowed."));
    });
    add_action("deactivate_plugin", function($plugin_key) {
        if (!in_array($plugin_key, jswp_env_get_user_plugins()))
            wp_die(__("Plugin deactivation not allowed."));
    });
}

// If the app env has specified user plugins we show the plugins tab.
if (!empty(jswp_env_get_user_plugins())) {
    // Setup filter function for only showing the plugins specified in app env.
    function filter_user_plugins($plugins) {
        $user_plugins = jswp_env_get_user_plugins();
        foreach($plugins as $plugin_key => $plugin) {
            if (!in_array($plugin_key, $user_plugins))
                unset($plugins[$plugin_key]);
        }
        return $plugins;
    }
    add_filter("all_plugins", "filter_user_plugins");
} else {
    add_action("admin_menu", function() {
        remove_menu_page("plugins.php");
    });
}

// Sandboxed Jumpstarter Wordpress user.
class JS_WP_User extends WP_User {
    public function __construct(WP_User $raw_wp_user) {
        foreach (get_object_vars($raw_wp_user) as $key => $var)
            $this->$key = $var;
    }

    public function has_cap($in) {
        $cap = (is_numeric($in)? $this->translate_level_to_cap($cap): $in);
        if (in_array($cap, jswp_env_get_disabled_capabilities()))
            return false;
        return call_user_func_array(array($this, "parent::" . __FUNCTION__), func_get_args());
    }
}

// Validates the cookie z with the session id x.
function js_auth_verify($x, $z) {
    // Sanity check input.
    if (!is_string($z) || !is_string($x))
        return false;
    if (strlen($z) != 144 || strlen($x) != 64)
        return false;
    if (!ctype_xdigit($z) || !ctype_xdigit($x))
        return false;

    // Decode all cookie parameters.
    $z_raw = hex2bin($z);
    $e_raw = substr($z_raw, 0, 8);
    $e = implode(unpack("N", substr($e_raw, 4, 4))); // expire time
    $y = substr($z_raw, 8, 32); // random salt
    $h = substr($z_raw, 40); // sha256 hash signature

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

function js_auth_get_x() {
    return js_env_get_value("ident.container.session_key");
}

function js_route_reflected_login() {
    $reflected_url = js_env_get_value("ident.user.login_url");
    $ref = js_env_get_siteurl() . "/wp-login.php";
    $redirect = "$reflected_url?ref=$ref";
    wp_redirect($redirect);
    exit;
}

function js_request_is($type) {
    return strtolower($_SERVER["REQUEST_METHOD"]) == strtolower($type);
}

add_action('login_init', function() {
    // On get request with "reflected-login" set we want to redirect
    // the request to the js reflected-login page.
    if (js_request_is("GET") && isset($_GET["reflected-login"]))
        return js_route_reflected_login();
    // Attempt to login automatically via jumpstarter token at /wp-login.php
    if (!js_request_is("POST") || !isset($_POST["jumpstarter-auth-token"]))
        return;
    $z = $_POST["jumpstarter-auth-token"];
    $x = js_auth_get_x();
    if (!js_auth_verify($x, $z))
        wp_die(__("Jumpstarter login failed: authorization failed (old token?)."));
    foreach (get_super_admins() as $admin_login) {
        $user = get_user_by('login', $admin_login);
        if (is_object($user)) {
            $redirect_to = apply_filters( 'login_redirect', admin_url(), admin_url(), $user );
            wp_set_auth_cookie($user->ID);
            wp_redirect($redirect_to);
            exit;
        }
    }
    wp_die(__("Jumpstarter login failed: no valid account found."));
});

add_action("login_footer", function () {
    $login_url = js_env_get_value("ident.user.login_url");
    ?>
        <div id="js-login" style="clear: both; padding-top: 20px; margin-bottom: -15px;">
            <a target="_parent" href="<?php _e($login_url) ?>">
                Login with Jumpstarter
            </a>
        </div>
        <script type="text/javascript">
            var jsl = document.getElementById("js-login");
            var lgf = document.getElementById("loginform");
            lgf.appendChild(jsl);
        </script>
    <?php
});

add_action("set_current_user", function() {
    global $current_user;
    if (!is_object($current_user))
        return;
    // Sandbox the current user per the Jumpstarter environment.
    $current_user = new JS_WP_User($current_user);
});

// Filter for correctly determining whether a url should be using HTTPS or HTTP.
// Currently the WordPress engine replaces the url scheme if it detects that the
// global $_SERVER['HTTPS'] is set to "on" or 1. This works fine for auto configured
// js domains as they use https but fails when the user has added a non secure domain.
function js_set_url_scheme($url, $scheme, $orig_scheme) {
    // Get the siteurl configured for this container. This will include the correct
    // scheme to use. If auto domain or configured secure domain this will use the
    // https scheme.
    $js_siteurl = js_env_get_siteurl();
    $https_pattern = "/^https/";
    // If the given url and the js siteurl both uses https then return as is.
    if ($scheme === "https" && preg_match($https_pattern, $js_siteurl)) {
        return $url;
    }
    // If a domain is added to Jumpstarter that isn't secure we need to transform the
    // url into a http version.
    return preg_replace($https_pattern, "http", $url);
}

add_filter("set_url_scheme", "js_set_url_scheme", 100, 3);

// Short-circuit URLs to emulate hard coded configuration.
// We need to check if these are already defined as the installer sets these
// directly in js-init.php and later runs this script when the jumpstarter
// plugin is activated.
if (!defined("WP_SITEURL"))
    define("WP_SITEURL", get_option("siteurl"));
if (!defined("WP_HOME"))
    define("WP_HOME", get_option("home"));
