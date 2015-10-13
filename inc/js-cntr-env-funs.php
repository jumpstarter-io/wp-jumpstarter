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

add_action("login_form_login", function() {
    add_action("login_footer", function() {
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
        <div id="js-insecure-domain" style="display: none;">
          <h2>Jumpstarter auto login disabled</h2>
          <p>We are all about security and don't want to send your password over an insecure connection. You can still login with your username and password below.</p>
          <p>If you haven't set any password yet, please click on the learn more link below</p>
          <p style="text-align: center;"><a href="#" id="js-auth-learn-more">Learn more &raquo;</a></p>
          <br/>
          <div id="js-insecure-domain-learn-more" style="display: none;">
              <hr/>
              <br/>
              <h3>Why is this connection insecure?</h3>
              <p>Most likely you've added a custom domain to your site. We do not support the possibility to add SSL certificates yet, but we're working on it.</p>
              <br/>
              <h3>I don't have a password for my site yet.</h3>
              <p>Click the link below and we'll send a "reset password" link to the email you used for signing up to Jumpstarter.</p>
              <br/>
              <br/>
              <div style="width: 34%; margin-left: auto; margin-right: auto;">
                <div style="float: left;">
                  <a id="js-insecure-domain-btn-send" class="button button-primary button-large" style="float: none;">Get password email</a>
                </div>
                <div style="float: left;">
                  <div id="js-insecure-domain-spinner" class="js-spinner" style="float: right; margin-left: 10px; margin-top: 7px; display: none;"></div>
                </div>
                <div style="display: block; clear: both;"></div>
              </div>
              <div id="js-insecure-reset-ok" class="js-err">
                <br/>
                <p>A reset password link has been sent to your registered Jumpstarter email. Check your inbox for a password reset link.</p>
              </div>
              <div id="js-insecure-reset-err-too-often" class="js-err">
                <br/>
                <p>Please slow down. There is no point in clicking this button several times.</p>
              </div>
              <div id="js-insecure-reset-err-gen" class="js-err"><br/><p></p></div>
          </div>
        </div>
        <?php
        js_register("script", "jswp-get-params", "jswp-get-params.js", false);
        js_enqueue("script", "jswp-login", "jswp-login.js", array("jquery", "jswp-get-params"));
        js_enqueue("style", "jswp-login-css", "jswp-login.css", false);
        wp_localize_script( "jswp-login", "reset_ajax", array("url" => admin_url("admin-ajax.php")));
    });
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
    // Check if we got a faulty HTTP_HOST in the request.
    if (php_sapi_name() != "cli") {
        $curr_domain_raw = js_env_get_siteurl();
        $curr_domain = preg_replace("/^https?:\/\//", "", $curr_domain_raw);
        if (strpos($_SERVER["HTTP_HOST"], $curr_domain) === false) {
            // The HTTP_HOST in didn't match the configured domain.
            // Redirect to the currently configured domain instead.
            $red_addr = $curr_domain_raw . $_SERVER["REQUEST_URI"];
            header("Location: $red_addr", true, 302);
            exit();
        }
    }
});

function js_perform_rest_call($url, $method, $data = null, $ignore_ssl_verification = false) {
    $ch = curl_init();
    switch ($method) {
    case "POST": {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        if ($data) {
            $json_data = json_encode($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                "content-type: application/json",
                "content-length: " . strlen($json_data)
            ));
        }
        break;
    } case "GET": {
        if ($data) {
            $url = sprintf("%s?%s", $url, http_build_query($data));
        }
        break;
    } default: {
        return false;
    }}
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    if ($ignore_ssl_verification === true) {
        // This is needed when curling to the development api.
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    }
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}

/// Ajax action for non authenticated users to send a reset
/// password link via the Jumpstarter API.
add_action("wp_ajax_nopriv_js_send_reset_email", function() {
    global $wpdb;
    // Check latest reset request time.
    $last_reset_req_at = get_option("js_pwd_reset_req");
    // Update the reset request every time. Don't allow spamming.
    update_option("js_pwd_reset_req", time(), false);
    if ($last_reset_req_at !== false) {
        // Validate req time here. Should not be too often.
        $when = intval($last_reset_req_at);
        if ($when > (time() - 60 * 3)) {
            echo json_encode(array(
                "status" => "fail-too-often"
            ));
            wp_die();
        }
    }
    // Build api address.
    $ignore_ssl_verification = false;
    $parsed_login_url = parse_url(js_env_get_value("ident.user.login_url"));
    if ($parsed_login_url["scheme"] !== "https") {
        echo json_encode(array(
            "status" => "fail",
            "err_msg" => "Invalid url scheme. Only HTTPS is supported for api communication."
        ));
        wp_die();
    }
    $api_addr = $parsed_login_url["scheme"] . "://";
    $api_addr .= $parsed_login_url["host"];
    if (isset($parsed_login_url["port"])) {
        // If port is specified it means that we're running in Jumpstarter
        // dev environment.
        $api_addr .= ":" . $parsed_login_url["port"];
        $ignore_ssl_verification = true;
    }
    $api_addr .= "/api/v1/instance/send-reset-password";
    // Create a reset hash.
    // This follows the procedure in wp-login.php:277 retrieve_password()
    require_once(ABSPATH . WPINC . "/class-phpass.php");
    $hasher = new PasswordHash(8, true);
    $reset_key = wp_generate_password(20, false);
    $reset_hash = time() . ':' . $hasher->HashPassword($reset_key);
    $wpdb->update($wpdb->users, array("user_activation_key" => $reset_hash), array("user_login" => "admin"));
    // Create the reset address.
    $reset_addr = network_site_url("wp-login.php?action=rp&key=$reset_key&login=admin");
    // Generate a token that the Jumpstarter api can use to validate the request.
    $auth_token = js_env_token_auth_generate();
    // Compile an api request and send it.
    $post_data = array(
        "id" => js_env_get_value("ident.container.id"),
        "token" => $auth_token,
        "url" => $reset_addr
    );
    $rest_res = js_perform_rest_call($api_addr, "POST", $post_data, true);
    if (is_string($rest_res)) {
        // Response is text representation of json.
        echo $rest_res;
    } else {
        // The response is erroneous. We didn't get a json string back from
        // the api. Anything could be wrong. Respond with an error.
        echo json_encode(array(
            "status" => "fail",
            "err_msg" => "Could not communicate with the api"
        ));
    }
    wp_die();
});
