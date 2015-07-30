WordPress Jumpstarter
=====================

The official Jumpstarter plugin for WordPress integration. It primarily allows you to sell Themes without writing any extra code for WordPress.

Read [the getting started guide here](https://github.com/jumpstarter-io/help/wiki/Getting-Started:-PHP-&-Wordpress-With-Jumpstarter-Console).

### How to install

The plugin should be placed in `/wp-content/plugins/jumpstarter`.

### Preconditions

This plugin has some expectations that must be fulfilled:

* SQLite must be used as a database with [the sqlite-integration plugin](https://wordpress.org/plugins/sqlite-integration/).
* The `js-init.php` script must be run successfully (return exit code 0) before the HTTP port is opened and the WordPress site can accept requests.
* TLS must be used (HTTPS).
* `DB_DIR` must be set to `"/app/code/wp-db"` in `wp-config.php`.
* The `/wp-content/database` folder must not exist as it's a security hazard.
* The user must not be able to change the theme base defined theme.

### What init does

When the `js-init.php` is run it does the following:

1. Install WordPress if `/app/code/wp-db` does not exist.
2. Sync the `/app/env.json` and `/app/code/wp-env.json` environments with WordPress.

Install is done the following way:

1. Clean up previous failed or aborted installations.
2. Install WordPress to RAM (in `/tmp`) to get rid of waiting for disk sync.
   This allows installing in a second or less.
3. Activating core plugins (`jumpstarter` and `sqlite-integration`).
4. Setting the theme specified in `wp-env.json`.
5. Run WordPress install hooks registered with `add_action("jumpstarter_install",...)`.
6. Atomically move the database in place. This allows the install to be idempotent.
7. Restart by execve'ing itself so environment sync can run.

Environment sync is done the following way:

1. Opening and parsing `/app/code/wp-env.json`.
2. If the siteurl has changed it performs a safe search/replace of $siteurl in `wp_posts`, `wp_postmeta` and `wp_options`.
3. Set theme specified in `theme`.
4. Update options specified in `options`.
5. Opening and parsing `/app/env.json`.
6. Update user details if they are admin default.
7. Call the hook `jumpstarter_sync_env` to let themes/plugins modify db state depending on the env.

It also prints logging and error information to `stderr`.

Install hooks:

If you need to do modifications to WordPress during the install phase you can take advantage of the install hook functionality provided by js-init. The registered hooks are executed at step 6 in the install process and as such they are run an the context of an initialized WordPress instance.

To register an install hook add the following to your theme or plugin:

```php
add_action("jumpstarter_install", function() {
    // Do your installation modifications here.
});
```

Alternative install:

If your WordPress app requires the database to be filled with example content it might be a good idea to speed up the process by running through the install before users starts their instances. These are the steps needed to do this kind of install:

1. Do a normal install by issuing `php /app/code/src/wp-content/plugins/jumpstarter/js-init.php`.
2. Make the db/content modifications needed (this could be done with the install hooks).
3. Create the directory `/app/code/js-init-state`.

When the user starts a new instance of the app the init script will use the files inside `/app/code/js-init-state` and then update the WordPress database with the users information.

## What the plugin does

When the plugin itself is run by WordPress after installing it does the following:

- Sandboxes all users (even super admins) and overrides any user capabilities defined in `wp-env.json`.
- Injects a login link to support Jumpstarter reflected login on `/wp-login.php`.
- Handles login requests from Jumpstarter by authenticating posts of `jumpstarter-auth-token`. On successful authentication the user is logged in as the admin user.
- Hooks in on set_url_scheme and uses the env to determine if the url should use http or https.
- Disables the possibility to delete the theme that's specified in the wp env.
- Rewrites urls passed to `wp_enqueue_script` and `wp_enqueue_style`.

## wp-env.json

WordPress settings that you want the Jumpstarter plugin to automatically sync with the install should be defined in `/app/code/wp-env.json`. The env file has the following format. If a field is omitted it will be ignored.


```json
{
	"theme": "",
	"disabled_capabilities": [],
	"options": {}
}
```

Field explanation:

* `theme` - A string containing the name of the folder containing the theme in wp-content/themes/
* `disabled_capabilities` - A list of WordPress capabilities. (["edit_posts", "edit_pages"]).
* `options` - An object of Key -> Val.
