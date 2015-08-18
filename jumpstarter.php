<?php
/**
 * Author: Jumpstarter
 * Author URI: https://jumpstarter.io/
 * Description: Jumpstarter WordPress integration plugin that simplifies running WordPress in a container environment.
 * Plugin Name: Jumpstarter
 * Plugin URI: https://github.com/jumpstarter-io/wp-jumpstarter
 * Version: 17.2
 * License: Unlicense
 * License URI: http://unlicense.org
 */

// Don't load directly.
if (!defined("ABSPATH"))
    die("-1");

require_once(dirname(__FILE__) . "/inc/jswp-env.php");
require_once(dirname(__FILE__) . "/inc/jswp-util.php");
require_once(dirname(__FILE__) . "/inc/js-cntr-env-funs.php");

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
    // be loaded over https since browsers should block non https calls on https sites.
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
    // be loaded over https since browsers should block non https calls on https sites.
    class JSWP_Scripts extends WP_Scripts {
        public function add($handle, $src, $deps = array(), $ver = false, $args = null) {
            return parent::add($handle, js_resource_scheme($src), $deps, $ver, $args);
        }
    }
    // Replace global $wp_scripts with our own class.
    global $wp_scripts;
    $wp_scripts = new JSWP_Scripts;
});
