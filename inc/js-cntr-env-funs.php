<?php

/*
 * js-cntr-env-funs.php
 *
 * This file contains functions that only really makes sense in the
 * Jumpstarter container environment when running WordPress.
 */

require_once(dirname(__FILE__) . "/jswp-env.php");

// If this WordPress installation isn't running in a container
// environment there's no point in continuing.
if (!js_is_container_env())
    return;

// Short-circuit URLs to emulate hard coded configuration.
// We need to check if these are already defined as the installer sets these
// directly in js-init.php and later runs this script when the jumpstarter
// plugin is activated.
if (!defined("WP_SITEURL"))
    define("WP_SITEURL", get_option("siteurl"));
if (!defined("WP_HOME"))
    define("WP_HOME", get_option("home"));

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

// Redirects the visitor to the Jumpstarter reflected login page.
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
    // Check for jumpstarter-error in the post data.
    // We do not allow reflected login on non HTTPS domains.
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
    // Authentication success, now get the admin user and set current session as logged in.
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
    // We only want to show the extra login footer on the actual login page.
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
    endif;
    ?>
    <?php
    js_register("script", "jswp-get-params", "jswp-get-params.js", false);
    js_enqueue("script", "jswp-login", "jswp-login.js", array("jquery", "jswp-get-params"));
    js_enqueue("style", "jswp-login-css", "jswp-login.css", false);
});

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
