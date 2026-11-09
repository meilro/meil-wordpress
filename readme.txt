=== meil.ro – Transactional Email & Newsletters ===
Contributors: meil
Tags: email, romania, transactional email, newsletter, subscription form
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sends WordPress emails through meil.ro and gathers subscribers with forms, a popup and a checkbox at checkout.

== Description ==

Connects your site to [meil.ro](https://meil.ro), a Romanian service for transactional email and newsletters. You need a meil.ro account to use it.

* All WordPress email goes through meil.ro: password resets, WooCommerce orders, form notifications.
* If meil.ro can't be reached, emails wait and go out later. Nothing is sent twice.
* Emails meil.ro refuses show up under meil.ro > Unsent, where you can send them again.
* If a plugin sends from an address meil.ro can't send from (a visitor's Gmail, for example), the email goes from your default address and the original one becomes the Reply-To.
* Subscription forms, as a block or a shortcode, with or without email confirmation.
* Any form can also show as a popup, a slide-in or a bar.
* A sign-up checkbox at WooCommerce checkout and on registration.
* Forms made with Contact Form 7, WPForms, Fluent Forms, Gravity Forms or Elementor Pro can add subscribers too.
* A list of sent emails, with what happened to each one.
* An email preferences page for logged-in users.
* Works with WPML and Polylang.

Campaigns, templates and audiences are managed in the meil.ro dashboard.

The admin screens use the IBM Plex Sans font, bundled with the plugin (SIL Open Font License 1.1, see `assets/fonts/OFL.txt`).

== External services ==

The plugin talks to the meil.ro API (`https://api.meil.ro`), and only after you save an API key. Every request includes the key and the plugin and WordPress versions.

What it sends:

* **Emails**, when "Send WordPress mail through meil" is on: every email sent with `wp_mail()`, with its addresses, subject, content, headers and attachments.
* **Sign-ups**, from the plugin's forms and popup, the sign-up checkbox and connected form plugins: the email address, the name if the form asks for it, the chosen lists, the checkbox answers with their text, where they signed up and the site language. With email confirmation, also the confirmation and thank-you page addresses. Without it, the time and the visitor's IP address, as proof of consent.
* **Confirming on your site**: the token from the confirmation email, plus the visitor's IP address when they press the button.
* **Email preferences**: the logged-in user's email address. When they join a list for the first time, also their name, the time and their IP address.
* **User changes**: the old and new address when a user changes their email, and a deleted user's address if "Deleted users" is on.
* **Admin screens**: the API key check, the audiences and the sent emails.

meil.ro: [Terms of service](https://meil.ro/terms), [Privacy policy](https://meil.ro/privacy), [Data processing agreement](https://meil.ro/dpa).

== Installation ==

1. Go to **Plugins → Add New**, search for **meil.ro**, install it and activate it.
2. In meil.ro, create an API key under **API Keys**. "Transactional only" is enough for sending email. Forms and sign-ups need "Full access".
3. In WordPress, open **meil.ro**, paste the key and set a default from address, like `wordpress@example.com`.
4. Send a test email from the same screen.
5. Build a form under **meil.ro > Forms** and add it to a page with the **meil.ro form** block or its shortcode.

== Frequently Asked Questions ==

= What happens if meil.ro is down? =

Emails wait on your site and are retried for up to 22 hours. Nothing is sent twice. Sign-ups from the checkout checkbox and form plugins wait the same way.

If meil.ro refuses an email (a bad address, for example), it shows up under **meil.ro > Unsent** with the reason, and you can send it again from there. `wp_mail()` returns `false` and `wp_mail_failed` fires, like in WordPress itself.

= Which From address is used? =

The one the email asks for, as long as its domain is verified in meil.ro. WooCommerce's "From address" setting works too.

If the domain isn't verified (a contact form sending "from" the visitor, for example), the email goes from your default address and the original one becomes the Reply-To. Replying still reaches the visitor.

= Which API key do I need? =

Any key can send email. Forms, popups, the sign-up checkbox, form plugins and the preferences page add contacts, so they need "Full access".

If your meil.ro account sends for more than one site, limit the key to this site's domain. It also covers its subdomains, and the forms only show this site's audiences.

= Can I use only the forms, without sending WordPress email through meil.ro? =

Yes. Uncheck "Send WordPress mail through meil" in the settings.

= Can customers sign up at checkout or on registration? =

Yes. Set it up under **meil.ro > Settings > Sign-up checkbox**. The checkbox starts unticked. With the Checkout block it needs WooCommerce 8.9 or later. Orders and registrations never wait on meil.ro.

= Can my Contact Form 7, WPForms, Fluent Forms, Gravity Forms or Elementor Pro forms add subscribers? =

Yes, under **meil.ro > Form plugins**. For each form, pick the lists, the email field, and who joins: everyone who sends it, or only those who tick a checkbox. Contact forms should use the checkbox, since people writing to you haven't asked for your newsletter.

The form keeps working even if something goes wrong on meil.ro's side.

= How do popups work? =

Set one up under **meil.ro > Settings > Popup**. You pick the form, the style, when it opens, on which pages and how many days it stays away after being closed. It never shows on the cart, checkout or account pages, and stops once someone signs up. On phones it opens at the bottom of the screen.

= Should visitors confirm by email? =

We recommend it, and new forms have it on. People are only subscribed after they press the button in the email, which keeps typos and bots off your list.

= Can visitors confirm on my own site? =

Yes. In the form editor, under "Where visitors confirm", pick a page. The confirmation email then opens that page, which shows the visitor's address, their lists and a button. It shows above the page content, or wherever you put `[meil_confirm]`.

The page must be published and on the domain your lists send from, or a subdomain of it.

= How do I change how the forms look? =

* Under **meil.ro > Settings > Forms look**, add your theme's classes to buttons, fields and checkboxes (for example `btn btn-primary` or `form-control`).
* Override the CSS variables on `.meil-form`, `.meil-prefs` or `.meil-confirm`, like `--meil-accent`, `--meil-border`, `--meil-radius` or `--meil-card`.
* Turn off "Form styles" under Forms look and write your own CSS.
* Copy `form.php`, `preferences.php` or `confirm.php` from the plugin's `templates` folder into `yourtheme/meil/` to change the markup.
* Use the `meil_confirm_text` and `meil_preferences_choices` filters.

Turn on **Test mode** under Forms look while you work. Administrators then see the popup on every page and examples on the confirmation page. Visitors don't see anything different.

= Does it work on a multilingual site? =

Yes, with WPML or Polylang. Your form texts, list labels and popup text are translated with the rest of your site's strings, so one form works in every language.

= Can a form ask visitors to accept my terms? =

Yes. Add a checkbox in the form editor, link it to your terms and make it required. Each answer is saved on the contact, along with the text the visitor saw.

= Does it work with page caching? =

Yes, forms and popups both do.

= Is there spam protection? =

Yes. Forms have a hidden field that catches bots and a limit on sign-ups per visitor. With email confirmation on, nobody is subscribed without pressing the button.

== Changelog ==

= 1.3.0 =
* First release on WordPress.org.
* Emails and sign-ups are retried when meil.ro can't be reached. Refused emails are listed under meil.ro > Unsent.
* Emails from an address meil.ro can't send from go from the default address, with the original as Reply-To.
* WooCommerce's From address is used when it's on a verified domain.
* Sign-up checkbox at WooCommerce checkout and registration.
* Blocks for forms and email preferences.
* Popups, slide-ins and bars.
* Contact Form 7, WPForms, Fluent Forms, Gravity Forms and Elementor Pro support.
* Sent emails list.
* Forms can sign visitors up to all their lists at once.
* WPML and Polylang support for form and popup texts.
* Test mode for designing the popup and the confirmation page.
* New look for the admin screens and the confirmation box.
* Better visitor IP detection behind proxies.

= 1.2.0 =
* Visitors can confirm on a page of your site instead of on meil.ro.

= 1.1.0 =
* Subscription forms, built under meil.ro > Forms. They replace the [meil_signup] shortcode.
* Email preferences page for logged-in users.
* Form checkboxes for terms and consent.
* A user's lists follow them when they change their email. Deleting a user can delete their contact.
* Romanian translation.

= 1.0.0 =
* First release, with wp_mail() delivery through meil.ro.
