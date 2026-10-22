# meil.ro – Email & Newsletter for WordPress

The official WordPress plugin for [meil.ro](https://meil.ro), a Romanian service for transactional email and newsletters.

- Sends all WordPress mail (`wp_mail()`) through the meil.ro API: password resets, WooCommerce orders, form notifications.
- Subscription forms built under **meil.ro > Forms** (audiences with your own labels, optional confirmation by email), placed with `[meil_form id="…"]`.
- `[meil_preferences]` for account pages: logged-in users choose which lists they get.
- Moves a user's lists when they change their email, and can delete the contact when the user is deleted.
- Restyle with CSS variables, your own CSS, or template overrides in your theme (`yourtheme/meil/form.php`, `yourtheme/meil/preferences.php`).
- English and Romanian, translatable with the usual WordPress tools (`languages/meil.pot`).

Requires WordPress 5.9+ and PHP 7.4+.

## Install

1. Download the plugin zip from the [latest release](https://github.com/meilro/meil-wordpress/releases).
2. In WordPress, go to **Plugins → Add New → Upload Plugin** and upload it.
3. Open **meil.ro** in the admin menu and paste an API key from [meil.ro/api-keys](https://meil.ro/api-keys).

Setup steps, FAQ and changelog are in [readme.txt](readme.txt), which is also the WordPress.org listing.

## Translations

After changing texts, rebuild the template and the Romanian file:

```sh
xgettext --language=PHP --from-code=UTF-8 --add-comments=translators: --keyword=__ --keyword=_e \
  --keyword=esc_html__ --keyword=esc_html_e --keyword=esc_attr__ --keyword=esc_attr_e \
  -o languages/meil.pot meil.php includes/*.php templates/*.php
msgmerge --update languages/meil-ro_RO.po languages/meil.pot
msgfmt -o languages/meil-ro_RO.mo languages/meil-ro_RO.po
```

## Build the zip

WordPress expects the folder inside the zip to be named `meil`:

```sh
git archive --prefix=meil/ -o meil.zip HEAD
```

Dev files (this README, `.phpcs.xml.dist`, git files) are left out automatically.

## License

GPL-2.0-or-later
