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

This plugin connects your site to [meil.ro](https://meil.ro), a Romanian service for transactional
email and newsletters.

* **Reliable wp_mail() delivery.** Every `wp_mail()` call (password resets, WooCommerce receipts,
  form plugin notifications, anything) goes out through the meil API instead of the server's
  local mail, with attachments, Cc/Bcc, Reply-To and custom headers carried across.
* **Nothing lost when meil.ro cannot be reached.** The email waits and is sent on its own a few
  minutes later, without ever going out twice. What meil.ro refuses is listed under
  **meil.ro > Unsent**, with the reason, so you can fix it and send it again.
* **Form notifications that arrive.** When a form plugin sends from an address meil.ro cannot send
  from (your Gmail address, or the visitor's), the email goes from your default address instead,
  with the original one as Reply-To.
* **Subscription forms.** Build forms under **meil.ro > Forms**: pick the audiences each form offers,
  give each one your own label, let visitors choose among them or sign them up to all of them, ask for a first and last name or not, and choose whether visitors
  confirm by email first, on meil.ro or on a page of your own site. Place a form anywhere with its shortcode, `[meil_form id="123"]`. Forms
  are sent in the background, with the answer shown in place. Without JavaScript they still work.
  In the block editor, add the **meil.ro form** block and pick the form.
* **A popup, slide-in or bar.** One of your forms can also show on top of your pages, after some
  seconds, some scrolling, or when the visitor is about to leave, set and previewed under
  **meil.ro > Settings > Popup**. It stays away from those who closed it or already signed up, and
  never covers the page on phones.
* **Your existing forms sign people up too.** Connect forms made with Contact Form 7, WPForms,
  Fluent Forms, Gravity Forms or Elementor Pro to your audiences under **meil.ro > Form plugins**:
  whoever ticks the form's "send me news" checkbox joins, with the checkbox text saved as proof.
* **Sent emails, inside WordPress.** **meil.ro > Sent emails** lists what went out, newest first,
  and what became of each (delivered, bounced, marked as spam), with the content while meil.ro
  keeps it.
* **A sign-up checkbox at checkout and registration.** "Send me news and offers by email" at
  WooCommerce checkout (classic or Checkout block) and on the registration forms. It starts
  unticked, and whoever ticks it joins the audiences you choose.
* **Email preferences for logged-in users.** Put the **meil.ro email preferences** block or
  `[meil_preferences]` on an account page: users see the public audiences of your domain and tick
  the ones they want.
* **Kept in step with your users.** When a user changes their email address, their lists move to
  the new one. Optionally, deleting a user deletes their meil.ro contact too.
* **Easy to restyle.** Every element has its own class, the plugin's styles can be turned off, and
  your theme can override the markup.
* **In English and Romanian**, following the site language, and translatable with Loco Translate,
  WPML, Polylang or any tool that reads WordPress translation files.

Broadcasts, templates and audiences are managed in the meil.ro dashboard.

The plugin talks only to the meil.ro API, and only once you save an API key. What it sends, and
when, is listed under External services below.

The plugin's admin screens use the IBM Plex Sans font, shipped with the plugin (no font is loaded
from elsewhere). Copyright 2017 IBM Corp., under the SIL Open Font License 1.1, in `assets/fonts/OFL.txt`.

== External services ==

This plugin is the WordPress client of meil.ro, an email service for transactional email and
newsletters. It needs a meil.ro account and an API key, and it sends data to the meil.ro API
(`https://api.meil.ro`) only after you save that key. Every call carries the key, and the plugin's
and WordPress's version numbers.

What is sent, and when:

* **WordPress mail**, while "Send WordPress mail through meil" is on: every email sent with
  `wp_mail()`, with its From, To, Cc, Bcc and Reply-To addresses, subject, content, custom headers
  and attachments, so that meil.ro can deliver it.
* **Sign-ups**, when a visitor sends one of the plugin's forms or its popup, ticks the sign-up
  checkbox at checkout or registration, or sends a form plugin's form you connected: their email
  address, their first and last name when the form asks for them, the audiences they chose, their
  answers to the form's checkboxes with the text of each, where they signed up, and the site
  language for the confirmation email. With confirmation by email, also the addresses of the
  confirmation and thank-you pages you picked. Without it, also the time and the visitor's IP
  address, as proof of consent.
* **Confirming on your site**: the token from the confirmation email when the page opens, and the
  token with the visitor's IP address when they press the button.
* **Email preferences**: the logged-in user's email address, to read and change their lists. When
  they join a list for the first time, also their name, the time and their IP address.
* **Users**: when a user changes their email address, the old and the new one, to move their lists.
  When a user is deleted and "Deleted users" is on, their email address, to delete their contact.
* **Admin screens**: when an administrator opens them, the plugin checks the API key and reads the
  audiences, the sent emails and their content from meil.ro.

meil.ro: [Terms of service](https://meil.ro/terms), [Privacy policy](https://meil.ro/privacy),
[Data processing agreement](https://meil.ro/dpa).

== Installation ==

1. Upload the `meil` folder to `/wp-content/plugins/`, or install the zip through Plugins → Add New → Upload Plugin.
2. Activate the plugin.
3. In meil.ro, go to **API Keys** and create a key, limited to your site's domain if the account has
   several sites. "Transactional only" is enough for `wp_mail()` delivery. Forms and the preferences
   page need "Full access".
4. In WordPress, open **meil.ro** in the admin menu and enter the API key.
5. Set a default from address on a domain the key covers, for example `wordpress@example.com`.
6. Send a test email from the bottom of the same screen.
7. Build a form under **meil.ro > Forms** and add it to a page with the **meil.ro form** block, or
   paste its shortcode.

== Frequently Asked Questions ==

= What happens if meil.ro cannot be reached? =

The email is kept on your site and `wp_mail()` returns `true`, because it is on its way. WP-Cron tries
it again 1, 5, 15 and 60 minutes later, then after 3, 6 and 12 hours. Each email carries its own
Idempotency-Key, so a try that timed out after meil.ro had in fact sent it never sends it a second
time. For a minute after meil.ro looked down, new emails are kept straight away, so a page that sends
several emails does not wait for each one.

If meil.ro refuses an email for a reason trying again cannot fix (an invalid address, say),
`wp_mail()` returns `false` and fires the standard `wp_mail_failed` action with a `WP_Error`, exactly
as WordPress core does on a local delivery failure. The email is listed under **meil.ro > Unsent**
with the reason, a notice tells the administrators, and you can send it again once the cause is fixed.

Kept emails, with their contents, stay in a table of their own: until they are sent, or for 30 days
when they failed. Sign-ups from the checkbox below are kept and tried again the same way.

= Which From address do emails use? =

The From address the email asks for, when meil.ro can send from it: the default from address, a From
header, or the `wp_mail_from` filter (WooCommerce's own "From address" setting uses it). meil.ro only
sends from domains verified in your account, so when a plugin asks for another one (a contact form
that sends "from" the visitor, or from the admin's Gmail address), the email goes from the default
from address instead, under the same name, and the address it asked for becomes the Reply-To. Pressing
Reply still answers the visitor.

= Can customers sign up at checkout or when they register? =

Yes. On the **meil.ro** screen, under "Sign-up checkbox", choose where it shows (WooCommerce checkout,
the registration forms, or both), which audiences it adds to, its text, and whether people confirm
by email first (recommended). It starts unticked. With the Checkout block it shows under the order
notes and needs WooCommerce 8.9 or later. The classic checkout shows it above the order button. Each
contact keeps where it signed up and the exact text it saw. The default text follows the site
language, and a text of your own can be translated per language in WPML String Translation or Polylang's
Strings translations. An order or a registration never waits
on meil.ro: when it cannot be reached, the sign-up is kept and tried again.

= Which API key do I need? =

It depends on what you use. Every key sends WordPress mail and shows the Sent emails list. The
forms, popups, the preferences page, the sign-up checkbox and the form plugins add contacts, so
they need a "Full access" key. With another key they stay hidden from visitors and the admin
screens say why. If you change a key's permission in meil.ro, the plugin notices within an hour,
on its next admin screen.

= Can my Contact Form 7, WPForms, Fluent Forms, Gravity Forms or Elementor Pro forms sign people up? =

Yes, under **meil.ro > Form plugins**. For each form pick the audiences, the field with the email
address (and the name, if you like), and who joins: only those who tick one of the form's checkboxes,
or everyone who sends it. The second is only for a form made to sign up. A contact form needs the
checkbox, because people who only wrote to you have not asked for your emails. Confirmation by email
is on unless you turn it off. The form itself works exactly as before: the sign-up never delays it,
and if anything goes wrong on our side it is written to the PHP error log, not shown to the visitor.

= How do popups work? =

Under **meil.ro > Settings > Popup**, pick the form it shows, how it shows (a popup in the middle, a
slide-in in the corner, or a bar at the bottom), when (after some seconds, after some scrolling, or
when the visitor is about to leave), on which pages, and after how many days it may come back once
closed. The preview there shows it as it opens, at computer and phone width. It never shows on the
cart, the checkout or the account pages, nor on a page that already shows the same form. Once someone signs up through any meil.ro form, popups stop
for them. On phones a popup shows as a sheet at the bottom, so it never covers the page. Works
with page caching.

To design it with your theme, turn on **Test mode** under **meil.ro > Settings > Forms look**:
administrators then see it on every page, right away, even after closing it, while visitors see it
as usual.

= Is there a block for the forms? =

Yes: **meil.ro form** shows one of your forms (pick it in the block), and **meil.ro email
preferences** shows the preferences. They work in pages, posts and block widget areas. The shortcodes
keep working as before.

= Can I turn off wp_mail() delivery and only use the forms? =

Yes: uncheck "Send WordPress mail through meil" on the meil.ro screen.

= Which domains does a key cover? =

A meil.ro key works with all your domains unless you limit it to one when you create it. Limited to
`example.com`, it also covers its subdomains, like `news.example.com`. If your meil.ro account sends
for more than one site, limit this site's key to its domain:
the forms and the preferences page then offer only this site's audiences. Mail from an address the
key does not cover is refused by meil.ro, and the error shows up wherever WordPress reports a failed
email.

= Can newsletters come from a subdomain, apart from the site's own mail? =

Yes, and it is a good idea: it keeps the reputation of your newsletters apart from your password
resets and order emails. Verify `news.example.com` in meil.ro and put your newsletter audiences on
it. The site's mail keeps going from the default from address (`wordpress@example.com`), while
confirmation emails and campaigns for those audiences go from `news.example.com`. One key for
`example.com` covers both.

= Should visitors confirm by email? =

We recommend it, and new forms have it on. The visitor gets one email, in your site's language, and
is subscribed only after pressing its button. Until then they get nothing, and a signup nobody
confirms is deleted after 7 days. This keeps typos and bots off your list and records when and from
where each person confirmed. Pick a page of your site for them to land on afterwards, or leave
the thank-you on the confirmation page.

= Can visitors confirm on my own site instead of on meil.ro? =

Yes. In the form editor, under "Where visitors confirm", pick a page of your site. The button in the
confirmation email then opens that page, which shows the visitor's address and the lists they are
confirming, with a button. Pressing it subscribes them, records when and from where, and takes them
to your thank-you page if the form has one. This shows above the page's own content. To show it somewhere
else on the page, put `[meil_confirm]` there. To see what visitors see there and design it with
your theme, turn on **Test mode** under **meil.ro > Settings > Forms look** and open the page.

Opening the page never subscribes anyone on its own, only pressing its button does: Outlook and
company mail filters open every link in an email to check it. The page must be published, without a
password, and on the domain your lists send from (or a subdomain of it). If meil.ro refuses it,
visitors are still signed up and confirm on meil.ro's page, and you see a warning in the admin until
you save the form again.

= What does the preferences page show? =

Every public audience of your key's domain, ticked when the logged-in user's address is on it.
Saving subscribes or unsubscribes them straight away. Logged-in users are not asked to confirm by
email. Visitors who are not logged in see a short message. The page is never cached.

= How do I change how the forms look? =

* Under **meil.ro > Settings > Forms look**, give the buttons, fields and checkboxes your theme's
  own classes (for example `btn btn-primary`, `form-control`, `form-check-input`). They are added
  to the plugin's forms, popups, preferences and confirmation pages, and to the sign-up checkbox.
* Override the CSS variables (`--meil-accent`, `--meil-border`, `--meil-radius` and others) on
  `.meil-form`, `.meil-prefs` or `.meil-confirm`. The confirmation box is a card, with
  `--meil-card` (its background), `--meil-card-border` and `--meil-card-radius`.
* Or turn off "Form styles" on the meil.ro screen and style the classes yourself. Each checkbox has
  a real input, hidden visually, followed by `.meil-form__checkbox` / `.meil-prefs__checkbox` to
  draw your own box. Style the ticked state with `input:checked + .meil-form__checkbox`.
* Or copy `templates/form.php`, `templates/preferences.php` or `templates/confirm.php` into your
  theme as `meil/form.php`, `meil/preferences.php` or `meil/confirm.php` and change the markup.
* The `meil_confirm_text` filter changes any text of the confirmation box.
* The `meil_preferences_choices` filter renames, reorders or hides lists on the preferences page.
* The form's own checkboxes (terms and the like) have `.meil-form__choice--consent`, and
  `--required` when they must be ticked.
* While a form is being sent, it and its button get a `--loading` class (`.meil-form__form--loading`,
  `.meil-form__button--loading`, and the same for `.meil-prefs`), and the button is disabled.

= Does it work on a site in several languages? =

Yes, with WPML or Polylang. Texts you leave empty use the plugin's own, which follow each visitor's
language (English and Romanian are included). Texts you write yourself are translated with the
site's other texts: a form's button, messages, list labels and checkbox texts in the "meil.ro forms"
group, once the form is saved, and the sign-up checkbox's text and the popup's title and text with
the plugin's settings. One form serves every language: visitors get the confirmation email in
Romanian or English, following the page, and land on the confirmation and thank-you pages in their
language when those pages are translated.

= Can a form ask visitors to accept my terms? =

Yes. In the form editor, under "Checkboxes", add one and write its text, for example "I agree to the
Terms and conditions", with the link button pointing to your terms page. Tick "Required" if the form
must not be sent without it. You can add up to five, for example a required one for the terms and
an optional one for offers. They start unticked. Each answer is saved on the contact, as `true` or
`false` under the name you choose (`terms`, say), together with the exact text the visitor saw
(`terms_text`).

= Does the form work with page caching? =

Yes. It has no expiring security token. A hidden field keeps simple bots out instead.

= How is the form protected from abuse? =

A hidden field turns away simple bots, one visitor can sign up at most 5 times in 10 minutes, and
with confirmation on, nobody is subscribed without pressing the button in the email. Checkbox texts
keep only bold, italic and web, email or phone links: anything else, scripts included, is removed
when the form is saved and again when it is shown.

== Changelog ==

= 1.3.0 =
* Emails and sign-ups wait and are tried again when meil.ro cannot be reached, for up to 22 hours, never sent twice. The ones meil.ro refused are listed under meil.ro > Unsent, with the reason, to send again or delete, and administrators see a notice.
* An email whose From address meil.ro cannot send from (a form plugin sending from the visitor's or the admin's Gmail address) goes from the default from address instead, with the original address as Reply-To. Before, it was refused.
* The `wp_mail_from` filter is honoured, so WooCommerce's "From address" is used when it is on a verified domain.
* A "send me news by email" checkbox at WooCommerce checkout (classic and Checkout block) and on the registration forms, set up on the meil.ro screen. A text of your own for it is translatable with WPML and Polylang.
* Blocks for the block editor: meil.ro form and meil.ro email preferences.
* A popup: one of your forms can also show as a popup, a slide-in or a bar, after some seconds, some scrolling or when the visitor is about to leave, set and previewed under meil.ro > Settings > Popup.
* Forms made with Contact Form 7, WPForms, Fluent Forms, Gravity Forms and Elementor Pro can sign people up, set under meil.ro > Form plugins.
* meil.ro > Sent emails: what went out through meil.ro and what became of each, with the content.
* Features that need a "Full access" key stay hidden from visitors with another key, and the key's permission is checked again every hour on the plugin's screens.
* The settings screen has tabs: General, Sign-up checkbox, Popup and Forms look.
* Your own CSS classes for the buttons, fields and checkboxes of every form the plugin draws, under Forms look.
* Behind a host's proxy or load balancer, the visitor's address is read from the address it passes on, so the limits against bots count each visitor apart and the consent record holds the right address. When the address cannot be known, those limits no longer lump every visitor together.
* The confirmation box on your own page is now a card in the middle of the page, with the lists in a light box and an icon next to each message.
* Test mode, under Forms look: administrators see the popup on every page, right away, and the confirmation page shows examples of what visitors see, to design both with the theme. Visitors see the site as usual.
* A form with several audiences can sign visitors up to all of them, without checkboxes. Saving a form while meil.ro cannot be reached keeps its audiences.
* Sites in several languages (WPML, Polylang): a form's own texts, list labels and checkbox texts, and the popup's title and text, are translated per language, so one form serves every language, with the confirmation and thank-you pages in the visitor's language.
* The plugin's admin screens have meil.ro's own look: panels, its buttons and fields, its font, also on the list of forms and in the form editor. A button shows it is working until the next page opens, so nothing is sent twice.
* A page sending email waits at most 10 seconds for meil.ro, then the email is kept and sent later.

= 1.2.0 =
* Visitors can confirm their subscription on a page of your site instead of on meil.ro: pick it under "Where visitors confirm" in the form editor. The page shows who is confirming which lists, with a button, and only the button subscribes. Place it with [meil_confirm], or it shows at the top of the page.
* If meil.ro refuses a form's confirmation page or thank-you page (a site on another domain, say), visitors are still signed up and confirm on meil.ro's page, and the admin is told why. Before, the signup failed.

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
