# meil.ro – Email & Newsletter for WordPress

The official WordPress plugin for [meil.ro](https://meil.ro), a Romanian service for transactional email and newsletters.

- Sends all WordPress mail (`wp_mail()`) through the meil.ro API: password resets, WooCommerce orders, form notifications.
- Adds a newsletter signup form with the `[meil_signup audience="<id>"]` shortcode.
- One settings screen, with a test send and links into your meil.ro dashboard.

Requires WordPress 5.9+ and PHP 7.4+.

## Install

1. Download the plugin zip from the [latest release](https://github.com/meilro/meil-wordpress/releases).
2. In WordPress, go to **Plugins → Add New → Upload Plugin** and upload it.
3. Open **meil.ro** in the admin menu and paste an API key from [meil.ro/api-keys](https://meil.ro/api-keys).

Setup steps, FAQ and changelog are in [readme.txt](readme.txt), which is also the WordPress.org listing.

## Build the zip

WordPress expects the folder inside the zip to be named `meil`:

```sh
git archive --prefix=meil/ -o meil.zip HEAD
```

Dev files (this README, `.phpcs.xml.dist`, git files) are left out automatically.

## License

GPL-2.0-or-later
