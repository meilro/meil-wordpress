=== meil.ro – Email & Newsletter ===
Contributors: meil
Tags: email, smtp, transactional email, newsletter, subscription form
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sends WordPress mail through meil.ro, with subscription forms and an email preferences page.

== Description ==

This plugin connects your site to [meil.ro](https://meil.ro), a Romanian service for transactional
email and newsletters.

* **Reliable wp_mail() delivery.** Every `wp_mail()` call (password resets, WooCommerce receipts,
  form plugin notifications, anything) goes out through the meil API instead of the server's
  local mail, with attachments, Cc/Bcc, Reply-To and custom headers carried across.
* **Subscription forms.** Build forms under **meil.ro > Forms**: pick the audiences each form offers,
  give each one your own label, ask for a first and last name or not, and choose whether visitors
  confirm by email first. Place a form anywhere with its shortcode, `[meil_form id="123"]`. Forms
  are sent in the background, with the answer shown in place; without JavaScript they still work.
* **Email preferences for logged-in users.** Put `[meil_preferences]` on an account page: users see
  the public audiences of your domain and tick the ones they want.
* **Kept in step with your users.** When a user changes their email address, their lists move to
  the new one. Optionally, deleting a user deletes their meil.ro contact too.
* **Easy to restyle.** Every element has its own class, the plugin's styles can be turned off, and
  your theme can override the markup.
* **In English and Romanian**, following the site language, and translatable with Loco Translate,
  WPML, Polylang or any tool that reads WordPress translation files.

Broadcasts, templates and audiences are managed in the meil.ro dashboard.

This plugin does not talk to any third party; it only calls the meil.ro API.

== Installation ==

1. Upload the `meil` folder to `/wp-content/plugins/`, or install the zip through Plugins → Add New → Upload Plugin.
2. Activate the plugin.
3. In meil.ro, go to **API Keys** and create a key, limited to your site's domain if the account has
   several sites. "Transactional only" is enough for `wp_mail()` delivery; forms and the preferences
   page need "Full access".
4. In WordPress, open **meil.ro** in the admin menu and enter the API key.
5. Set a default from address on a domain the key covers, for example `wordpress@yourdomain.ro`.
6. Send a test email from the bottom of the same screen.
7. Build a form under **meil.ro > Forms** and paste its shortcode into a page.

== Frequently Asked Questions ==

= What happens if the API call fails? =

`wp_mail()` returns `false` and fires the standard `wp_mail_failed` action with a `WP_Error`, exactly
as WordPress core does on a local delivery failure, so any plugin that already handles that hook keeps
working unchanged.

= Can I turn off wp_mail() delivery and only use the forms? =

Yes: uncheck "Send WordPress mail through meil" on the meil.ro screen.

= Which domains does a key cover? =

A meil.ro key works with all your domains unless you limit it to one when you create it. Limited to
`yourdomain.ro`, it also covers `news.yourdomain.ro`; limited to `news.yourdomain.ro`, only that
subdomain. If your meil.ro account sends for more than one site, limit this site's key to its domain:
the forms and the preferences page then offer only this site's audiences. Mail from an address the
key does not cover is refused by meil.ro, and the error shows up wherever WordPress reports a failed
email.

= Can newsletters come from a subdomain, apart from the site's own mail? =

Yes, and it is a good idea: it keeps the reputation of your newsletters apart from your password
resets and order emails. Verify `news.yourdomain.ro` in meil.ro and put your newsletter audiences on
it. The site's mail keeps going from the default from address (`wordpress@yourdomain.ro`), while
confirmation emails and campaigns for those audiences go from `news.yourdomain.ro`. One key for
`yourdomain.ro` covers both. If the site should never send from the main domain, use a key for
`news.yourdomain.ro` and a default from address on it.

= Should visitors confirm by email? =

We recommend it, and new forms have it on. The visitor gets one email, in your site's language, and
is subscribed only after pressing its button. Until then they get nothing, and a signup nobody
confirms is deleted after 7 days. This keeps typos and bots off your list and records when and from
where each person confirmed. Pick a page of your site for them to land on afterwards, or leave
meil.ro's own confirmation page.

= What does the preferences page show? =

Every public audience of your key's domain, ticked when the logged-in user's address is on it.
Saving subscribes or unsubscribes them straight away; logged-in users are not asked to confirm by
email. Visitors who are not logged in see a short message. The page is never cached.

= How do I change how the forms look? =

* Override the CSS variables (`--meil-accent`, `--meil-border`, `--meil-radius` and others) on
  `.meil-form` or `.meil-prefs`.
* Or turn off "Form styles" on the meil.ro screen and style the classes yourself. Each checkbox has
  a real input, hidden visually, followed by `.meil-form__checkbox` / `.meil-prefs__checkbox` to
  draw your own box; style the ticked state with `input:checked + .meil-form__checkbox`.
* Or copy `templates/form.php` or `templates/preferences.php` into your theme as `meil/form.php` or
  `meil/preferences.php` and change the markup.
* The `meil_preferences_choices` filter renames, reorders or hides lists on the preferences page.
* The form's own checkboxes (terms and the like) have `.meil-form__choice--consent`, and
  `--required` when they must be ticked.
* While a form is being sent, it and its button get a `--loading` class (`.meil-form__form--loading`,
  `.meil-form__button--loading`, and the same for `.meil-prefs`), and the button is disabled.

= Can a form ask visitors to accept my terms? =

Yes. In the form editor, under "Checkboxes", add one and write its text, for example "I agree to the
Terms and conditions", with the link button pointing to your terms page. Tick "Required" if the form
must not be sent without it. You can add up to five, for example a required one for the terms and
an optional one for offers. They start unticked. Each answer is saved on the contact, as `true` or
`false` under the name you choose (`terms`, say), together with the exact text the visitor saw
(`terms_text`).

= Does the form work with page caching? =

Yes. It has no expiring security token; a hidden field keeps simple bots out instead.

= How is the form protected from abuse? =

A hidden field turns away simple bots, one visitor can sign up at most 5 times in 10 minutes, and
with confirmation on, nobody is subscribed without pressing the button in the email. Checkbox texts
keep only bold, italic and web, email or phone links: anything else, scripts included, is removed
when the form is saved and again when it is shown.

== Changelog ==

= 1.1.0 =
* Forms and the preferences page are sent in the background (admin-ajax.php), with a loading state on the button and the answer shown in place.
* Forms can have up to five checkboxes of their own (terms, privacy, offers), with links in their text, required or optional, each answer saved on the contact with the text the visitor saw.
* Subscription forms, built under meil.ro > Forms: audiences with your own labels, an optional first and last name, confirmation by email and a thank-you page. Placed with [meil_form id="…"].
* [meil_preferences]: logged-in users choose which public lists they get.
* A user's email change moves their lists to the new address. Optionally, deleting a user deletes their meil.ro contact.
* The API key is checked with meil.ro on save, and the settings show which domain and permission it has.
* Romanian translation included. All texts are translatable.
* Removed the [meil_signup] shortcode, replaced by the forms.

= 1.0.0 =
* First release: wp_mail() delivery through meil.ro, settings screen with a test send, and the [meil_signup] newsletter form.
