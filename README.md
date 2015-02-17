Wordpress Jumpstarter
=====================

The official Jumpstarter plugin for Wordpress integration. It primarily allows you to sell Themes without writing any extra code for Wordpress.

Read [the getting started guide here](https://github.com/jumpstarter-io/help/wiki/Getting-Started:-PHP-&-Wordpress).

### How to install

The plugin should be placed in `/wp-content/plugins/jumpstarter`.

### Preconditions

This plugin has some expectations that must be fulfilled:

* SQLite must be used as a database with [the sqlite-integration plugin](https://wordpress.org/plugins/sqlite-integration/).
* The `js-init.php` script must be run successfully (return exit code 0) before the HTTP port is opened and the Wordpress site can accept requests.
* TLS must be used (HTTPS).
* Plugin uploads and code changes must be disabled with `DISALLOW_FILE_MODS` in `wp-config.php`.
* `DB_DIR` must be set to `"/app/state/wp-db"` in `wp-config.php`.
* The `/wp-content/database` folder must not exist as it's a security hazard.
* The user must not be able to change the theme or add or remove themes or plugins.

### What init does

When the `js-init.php` is run it does the following:

1. Install wordpress if `/app/state/wp-db` does not exist.
2. Sync the `env.json` environment with Wordpress.

Install is done the following way:

1. Clean up previous failed or aborted installations.
2. Install Wordpress to RAM (in `/tmp`) to get rid of waiting for disk sync.
   This allows installing in a second or less.
3. Activating core plugins (`jumpstarter` and `sqlite-integration`).
4. Run install hooks specified in `/app/code/js-install-hooks/*.php`.
5. Atomically move the database in place.
   This allows the install to be idempotent.
6. Restart by execve'ing itself so environment sync can run.

Environment sync is done the following way:

1. Opening and parsing `/app/env.json`.
2. Set the domain. On change the `wp_posts.post_content` column is migrated by find/replace and the `siteurl` and `home` options updated.
3. Set the theme specified in `ident.app.extra_env.theme`.
4. Activate the plugins specified in `ident.app.extra_env.plugins`.

It also prints logging and error information to `stderr`.

Install hooks:

If you neeed to do custom modifications to wordpress during the install phase you can take advantage of the install hook functionality provided by js-init. These hooks are executed at step 4 in the install and as such the hooks are run in the context of an initialized Wordpress instance. 

To use install hooks place them in `/app/state/js-install-hooks`. A hook is implemented like this:

```php
    js_install_hook("Name of the hook", function() {
	    // This is where the magic happens.
	    // Remember to return true if the operation was successful.
	    return true;
    });
```

Alternative install:

If your Wordpress app requires the database to be filled with example content it might be a good idea to speed up the process by running through the install before the user starts its instance. These are the steps needed to do this kind of install:

1. Do a normal install by issuing `php /app/code/src/wp-content/plugins/jumpstarter/js-init.php`.
2. Make the db/content modifications needed (this could be done with the install hooks).
3. Create the directory `/app/code/js-init-state`.
4. Copy the files needed from `/app/state/` to `/app/code/js-init-state/`.

When the user starts a new instance of the app the init script will use the files inside `/app/code/js-init-state` and then update the wordpress database with the user's information.

## What the plugin does

When the plugin itself is run by Wordpress after installing it does the following:

- Activates all app plugins defined by the env automatically, deactivates the rest.
- Prevents users/admins from manually activating or deactivating plugins.
- Hides the plugin page in the admin panel to avoid confusion for end users.
- Sandboxes all users (even super admins) and overrides the `switch_themes` capability, disabling it. This allows no one to switch themes or see the installed themes. This is done by extending the `WP_User` class and overriding the current user from the `set_current_user` action.
- Injects a login link to support Jumpstarter reflected login on `/wp-login.php`.
- Handles login requests from Jumpstarter by authenticating posts of `jumpstarter-auth-token`. On successful authentication the user is logged in as one of the super admins (exactly which one is currently undefined).
