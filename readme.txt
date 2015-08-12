=== Jumpstarter ===
Contributors: jumpstarter, zeuclas
Tags: jumpstarter, url handling
Requires at least: 4.2
Tested up to: 4.2.5-alpha
Stable tag: 17.0
License: Unlicense
License URI: http://unlicense.org

Jumpstarter WordPress integration plugin that simplifies running WordPress in a container environment.

== Description ==

This is a plugin for WordPress installations in a Jumpstarter container environment. The main purpose is to
combat the problems one encounters when running WordPress in a container environment under nginx behind multiple
http proxy layers.

The plugin is divided into two distinct parts.

1. The installer/environment synchronizer (`js-init.php`).
2. The actual plugin (`jumpstarter.php`).

= The installer =

The installer takes care of the following:

1. Install WordPress if `/app/code/wp-db` does not exist.
2. Sync the `/app/env.json` and `/app/code/wp-env.json` environments with WordPress.

Install is done the following way:

1. Configure security salts in `wp-config.php` if not done already.
2. Clean up previous failed or aborted installations.
3. Install WordPress to RAM (in `/tmp`) to get rid of waiting for disk sync.
4. Activating core plugins (`jumpstarter` and `sqlite-integration`).
5. Setting the theme specified in `wp-env.json`.
6. Run WordPress install hooks registered with `add_action("jumpstarter_install",...)`.
7. Atomically move the database in place. This allows the install to be idempotent.
8. Restart by execve'ing itself so environment sync can run.

Environment sync is done the following way:

1. Setting nginx `fastcgi_param HTTPS` to "on"/"off" depending on configured domains for the container.
2. Opening and parsing `/app/code/wp-env.json`.
3. If the `siteurl` has changed it performs a safe search/replace of `siteurl` in `wp_posts`, `wp_postmeta` and `wp_options`.
4. Set theme specified in `theme` if not changed by the user.
5. Update options specified in `options`.
6. Opening and parsing `/app/env.json`.
7. Update user details if they are admin default.
8. Call the hook `jumpstarter_sync_env` to let themes/plugins modify database state depending on the env.

It also prints logging and error information to `stderr`.

= The plugin =

The plugin takes care of the following:

- Sandboxes all users and overrides any user capabilities defined in `/app/code/wp-env.json`.
- Injects a login link to support Jumpstarter [reflected login](https://github.com/jumpstarter-io/help/wiki/App-Portals#reflected-login) on `/wp-login.php`.
- Handles login requests from Jumpstarter by authenticating posts of `jumpstarter-auth-token`. On successful authentication the user is logged in as the admin user.
- Hooks in on `set_url_scheme` and uses the env to determine if the url should use http or https.
- Disables the possibility to delete the theme that's specified in the wp env.
- Rewrites urls passed to `wp_enqueue_script` and `wp_enqueue_style` depending on if SSL is on or not.

== Installation ==

= Installation Procedure =
1. Unzip into `/wp-content/plugins/` directory.
2. Activate the plugin in the WordPress admin panel.

== Frequently Asked Questions ==

= Can this plugin be used outside of the Jumpstarter environment? =

Yes. It is possible to use the plugin in any WordPress installation. However, when not running in
a Jumpstarter container environment the functionality of the plugin is reduced.

Features when not running in a Jumpstarter container:

- Hooks in on `set_url_scheme` and uses the env to determine if the url should use http or https.
- Rewrites urls passed to `wp_enqueue_script` and `wp_enqueue_style` depending on if SSL is on or not.

== Changelog ==

= 17.0 =
* Remove the last restrictions on user plugin management.
* Add help on login page for the event of using a non-secure domain.
* Improve code documentation.
* Add compatibility mode for non Jumpstarter container environments.

= 16.0 =
* Open up the plugin for the new Jumpstarter architecture changes (increase the freedom).
* Auto generate WordPress security salts on install.
* Modify nginx `fastcgi_param HTTPS` on init run.

= 15.0 =
* Refactor token authentication functionality, move out to common library.

= 14.0 =
* jumpstarter: bugfix JS_WP_User::has_cap. call parent function with all arguments.
* Store old siteurl as "js_siteurl_old" in env sync phase if siteurl change.
* js-init: ensure core plugins load order on env sync.

= 13.0 =
* Add "jumpstarter_install" hook in install stage.
* Enable users to deactivate plugins that are specified in both plugins and user_plugins.
* js-init: add `jumpstarter_sync_env` action at end of env sync to allow plugins/themes to run env change dependent code.

= 12.0 =
* Wrap sync of env with WordPress in transaction.
* Use js subclasses of sqlite-integration for multiple statement transactions.

= 11.0 =
* Take care of serialized values in updating of meta and options.

= 10.0 =
* Update post meta and options on change of site url.

= 9.0 =
* Enable user plugins.
* Add support for login_redirect filter on token auth.
* Set user information to defaults on install from state db.

= 8.0 =
* Add support for install hooks that are run while db in memory.
* Add support for installing instance from init state.

= 7.0 =
* Allow reflected login link to work in session expired iframe.

= 6.0 =
* Add support for specifying wp options in env.

= 5.0 =
* Allow plugin activation/deactivation from cli.
* Run hooks when activating plugins.

= 4.0 =
* Fix error when activating jumpstarter plugin from redefining WP_SITEURL.

= 3.0 =
* Break out and optimize js_get_env().

= 2.0 =
* js-init: always use admin username on install.
* Let env define the plugins to activate, hide plugins in admin menu.
* Update readme to reflect 2.0 changes.

= 1.0 =
* Initial version

== Upgrade Notice ==

= 17.0 =
This update adds login help and removes the last plugin restrictions.

= 16.0 =
Addresses some issues when running WordPress on a non HTTPS domain in the container environment.
