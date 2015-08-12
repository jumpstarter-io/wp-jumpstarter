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

require_once(dirname(__FILE__) . "/jswp-env.php");
require_once(dirname(__FILE__) . "/jswp-util.php");

// Sandboxed Jumpstarter WordPress user.
class JS_WP_User extends WP_User {
    public function __construct(WP_User $raw_wp_user) {
        foreach (get_object_vars($raw_wp_user) as $key => $var)
            $this->$key = $var;
    }

    public function has_cap($in) {
        $cap = (is_numeric($in)? $this->translate_level_to_cap($in): $in);
        return (in_array($cap, jswp_env_get_disabled_capabilities()))? false:
                call_user_func_array(array($this, "parent::" . __FUNCTION__), func_get_args());
    }
}

add_action("set_current_user", function() {
    global $current_user;
    if (!is_object($current_user))
        return;
    // Sandbox the current user per the Jumpstarter environment.
    $current_user = new JS_WP_User($current_user);
});

function js_route_reflected_login() {
    $reflected_url = js_env_get_value("ident.user.login_url");
    $ref = js_env_get_siteurl() . "/wp-login.php";
    $redirect = "$reflected_url?ref=$ref";
    wp_redirect($redirect);
    exit;
}

add_action('login_init', function() {
    // On get request with "reflected-login" set we want to redirect
    // the request to the js reflected-login page.
    if (js_request_is("GET") && isset($_GET["reflected-login"]))
        return js_route_reflected_login();
    $err_key = "jumpstarter-error";
    if (js_request_is("POST") && isset($_POST[$err_key]) && $_POST[$err_key] === "insecure-domain") {
        wp_redirect("/wp-login.php?insecure-domain");
        exit;
    }
    // Attempt to login automatically via jumpstarter token at /wp-login.php
    if (!js_request_is("POST") || !isset($_POST["jumpstarter-auth-token"]))
        return;
    $z = $_POST["jumpstarter-auth-token"];
    if (!js_domain_is_https() || !js_env_token_auth_verify($z))
        wp_die(__("Jumpstarter login failed: authorization failed (old token?)."));
    $user = get_user_by("login", "admin");
    if (is_object($user) && ($user instanceof WP_User)) {
        $redirect_to = apply_filters("login_redirect", admin_url(), admin_url(), $user);
        wp_set_auth_cookie($user->ID);
        wp_redirect($redirect_to);
        exit;
    }
    wp_die(__("Jumpstarter login failed: no valid account found."));
});

add_action("login_footer", function() {
    $login_action = isset($_REQUEST["action"]) ? $_REQUEST["action"] : "login";
    if ($login_action != "login")
        return;
    $login_url = js_domain_is_https()? js_env_get_value("ident.user.login_url"): "#";
    $site_url = "https://jumpstarter.io/site/" . js_env_get_value("ident.container.id");
    $profile_url = "https://" . js_env_get_value("settings.core.auto-domain") . "/wp-admin/profile.php";
    $insecure_domain_wiki_url = "https://github.com/jumpstarter-io/help/wiki/WordPress-with-insecure-domain";
    ?>
    <div id="js-login" style="display: none; clear: both; padding-top: 20px; margin-bottom: -15px;">
        <a id="js-login-read-more" href="<?php _e($insecure_domain_wiki_url) ?>" target="_new">Unable to login?</a>
        <br/><br/>
        <a id="js-login-reflected" target="_parent" href="<?php _e($login_url) ?>">Login with Jumpstarter</a>
    </div>
    <?php if (!js_domain_is_https()): ?>
    <div id="js-insecure-domain" style="display: none;">
        <h2>Insecure Domain</h2>
        <p>This page was not loaded using HTTPS. As such Jumpstarter cannot ensure that your credentials are safe during login and
          therefore automatic login has been disabled for this site. If you want to continue using this domain you are free to do so, but beware that
          your communication with the site will not be secure.</p>
        <p>If you haven't set a password yet, please follow these steps:</p>
        <ul>
            <li>Go to your <a href="<?php _e($site_url) ?>" target="_new">site</a></li>
            <li>Remove all domains</li>
            <li>Login using automatic login</li>
            <li>Set your password by navigating to your <a href="<?php _e($profile_url) ?>">profile</a></li>
            <li>Re-add your domain</li>
        </ul>
        <p>Upon completion you should be able to log in to your WordPress site.</p>
        <p><a href="<?php _e($insecure_domain_wiki_url) ?>" target="_new">Read more</a></p>
    </div>
    <?php
        js_register("script", "jswp-get-params", "jswp-get-params.js", false);
        js_enqueue("script", "jswp-login", "jswp-login.js", array("jquery", "jswp-get-params"));
        js_enqueue("style", "jswp-login-css", "jswp-login.css", false);
    endif; ?>
    <?php
});

// Filter for correctly determining whether a url should be using HTTPS or HTTP.
// Currently the WordPress engine replaces the url scheme if it detects that the
// global $_SERVER['HTTPS'] is set to "on" or 1. This works fine for auto configured
// js domains as they use https but fails when the user has added a non secure domain.
add_filter("set_url_scheme", function($url, $scheme, $orig_scheme) {
    // If the given url and the js siteurl both uses https then return as is.
    if ($scheme === "https" && js_domain_is_https()) {
        return $url;
    }
    // If a domain is added to Jumpstarter that isn't secure we need to transform the
    // url into a http version.
    return preg_replace(js_https_preg_regx(), "http", $url);
}, 100, 3);

// Short-circuit URLs to emulate hard coded configuration.
// We need to check if these are already defined as the installer sets these
// directly in js-init.php and later runs this script when the jumpstarter
// plugin is activated.
if (!defined("WP_SITEURL"))
    define("WP_SITEURL", get_option("siteurl"));
if (!defined("WP_HOME"))
    define("WP_HOME", get_option("home"));

call_user_func(function() {
    // Make sure that we don't try to uninstall the default theme that is
    // specified in the wp-env.json file.
    if (strpos($_SERVER["REQUEST_URI"], "/wp-admin/themes.php") !== FALSE && $_GET["action"] === "delete") {
        if (!isset($_GET["stylesheet"]))
            return;
        $theme = wp_get_theme($_GET["stylesheet"]);
        if (!$theme->exists())
            return;
        $default_theme = jswp_env_get_theme();
        if ($theme->stylesheet === $default_theme || $theme->template === $default_theme)
            wp_die(_("You are not allowed to delete this theme."));
    }
});

call_user_func(function() {
    function js_resource_scheme($src) {
        // Replace URL scheme to https if the siteurl from env is https.
        if (js_domain_is_https() && !preg_match(js_https_preg_regx(), $src)) {
            $src = preg_replace("/^http/", "https", $src);
        }
        return $src;
    }
    // Subclass WP_Styles to hijack requests to wp_enqueue_style. This is done to
    // change http resources to https if the container is running https. If the requested
    // resource uses http and the site runs https it doesn't matter if the resource can't
    // be loaded over https since browsers will block non https calls on https sites.
    class JSWP_Styles extends WP_Styles {
        public function add($handle, $src, $deps = array(), $ver = false, $args = null) {
            return parent::add($handle, js_resource_scheme($src), $deps, $ver, $args);
	}
    }
    // Replace global $wp_styles with our own class.
    global $wp_styles;
    $wp_styles = new JSWP_Styles;
    // Subclass WP_Scripts to hijack requests to wp_enqueue_script. This is done to
    // change http resources to https if the container is running https. If the requested
    // resource uses http and the site runs https it doesn't matter if the resource can't
    // be loaded over https since browsers will block non https calls on https sites.
    class JSWP_Scripts extends WP_Scripts {
        public function add($handle, $src, $deps = array(), $ver = false, $args = null) {
            return parent::add($handle, js_resource_scheme($src), $deps, $ver, $args);
        }
    }
    // Replace global $wp_scripts with our own class.
    global $wp_scripts;
    $wp_scripts = new JSWP_Scripts;
});
