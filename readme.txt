=== meil.ro – Email & Newsletter ===
Contributors: meil
Tags: email, smtp, transactional email, newsletter, wp_mail
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sends WordPress mail through meil.ro instead of PHP mail() or an SMTP relay.

== Description ==

This plugin connects your site to [meil.ro](https://meil.ro), a Romanian service for transactional
email and newsletters.

* **Reliable wp_mail() delivery.** Every `wp_mail()` call (password resets, WooCommerce receipts,
  form plugin notifications, anything) goes out through the meil API instead of the server's
  local mail, with attachments, Cc/Bcc, Reply-To and custom headers carried across.
* **One settings screen** with a test send and buttons into your meil.ro dashboard.
* **Newsletter signup form.** A `[meil_signup audience="<id>"]` shortcode adds a visitor to a meil
  audience, with the consent time recorded. The settings screen lists your audiences with their
  shortcodes, ready to copy.

Broadcasts, templates and audiences are managed in the meil.ro dashboard.

This plugin does not talk to any third party; it only calls the meil.ro API.

== Installation ==

1. Upload the `meil` folder to `/wp-content/plugins/`, or install the zip through Plugins → Add New → Upload Plugin.
2. Activate the plugin.
3. In meil.ro, go to **API Keys** and create a key. "Email sending" is enough for `wp_mail()`
   delivery; use "Full access" for the signup form too.
4. In WordPress, open **meil.ro** in the admin menu and enter the API key.
5. Set a default from address on a domain that is verified in meil.ro (**Domains**).
6. Send a test email from the bottom of the same screen.

== Frequently Asked Questions ==

= What happens if the API call fails? =

`wp_mail()` returns `false` and fires the standard `wp_mail_failed` action with a `WP_Error`, exactly
as WordPress core does on a local delivery failure, so any plugin that already handles that hook keeps
working unchanged.

= Can I turn off wp_mail() delivery and only use the signup form? =

Yes: uncheck "Send WordPress mail through meil" on the meil.ro screen.

= Where do I find the shortcode for an audience? =

On the **meil.ro** screen, under "Signup form", with a "Full access" API key.

= Can I change the form's text? =

Yes, with shortcode options: `button_text`, `placeholder`, `success_message`, and
`show_name_fields="true"` to also ask for a first name. For example:
`[meil_signup audience="<id>" button_text="Abonează-te" success_message="Mulțumim!"]`

= Does the signup form work with page caching? =

Yes. It has no expiring security token; a hidden field keeps simple bots out instead.

== Changelog ==

= 1.0.0 =
* First release: wp_mail() delivery through meil.ro, settings screen with a test send, and the [meil_signup] newsletter form.
