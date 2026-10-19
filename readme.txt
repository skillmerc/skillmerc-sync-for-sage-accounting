=== Skillmerc Sync for Sage Accounting ===
Contributors: skillmerc
Tags: sage, sage accounting, woocommerce, invoices, bookkeeping
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send paid WooCommerce orders to Sage Accounting as a customer, a sales invoice with VAT per line, and a payment.

== Description ==

Every paid WooCommerce order becomes a proper sales invoice in Sage Accounting, so you stop typing orders into your books by hand.

When an order reaches Processing or Completed (you choose the statuses), the plugin:

1. Finds the customer in Sage by reference, or creates them with their billing address, email and phone.
2. Creates a sales invoice with one line per product and fee, a separate shipping line, and the VAT that WooCommerce charged on each line.
3. Records the payment as a customer receipt in the bank account you choose, so the invoice shows as paid.

Each step saves its Sage ID on the order, and the order notes show the invoice number. If Sage is down or a token expires, the order is retried in the background up to 5 times (after 5 minutes, 30 minutes, 2 hours and 6 hours). A retry never creates a second invoice for the same order. You can also send any order by hand with the "Sync to Sage now" order action.

= What you set up =

* Your own free Sage developer app (Client ID and Client Secret). This keeps your keys in your own site. Nothing goes through our servers.
* The sales ledger account for products and fees, and the ledger account for shipping.
* The bank account where payments are recorded.
* The Sage VAT rate for taxed and untaxed lines, and optionally a Sage VAT rate for each WooCommerce tax rate (for example 5% reduced rate items).

= Requirements and limits in version 0.1 =

* Sage Accounting through Sage's Accounting API v3.1. Built and tested with Sage Accounting UK. Sage South Africa uses a different API and is not supported yet.
* Orders must be in your Sage base currency. Orders in other currencies are skipped with an order note.
* Refunds are not sent to Sage yet. Record credit notes in Sage by hand for now.
* Orders paid before you installed the plugin are not sent automatically. Use the order action for each one.
* Works with the WooCommerce block checkout, the classic checkout and High-Performance Order Storage (HPOS).

= External service =

This plugin connects to Sage Accounting, a service run by The Sage Group plc, so it can create contacts, invoices and payments in your Sage business. It sends data only after you connect your own Sage app on the settings screen:

* When you click Connect, your browser goes to the Sage sign-in page (www.sageone.com), and the plugin exchanges the returned code for tokens at oauth.accounting.sage.com.
* When an order is sent, the plugin sends the customer's name, company, email, phone and billing address, the order number, date, line items, prices, VAT and shipping, and the payment amount and method name to api.accounting.sage.com.

Sage terms: https://www.sage.com/en-gb/legal/terms-and-conditions/
Sage privacy notice: https://www.sage.com/en-gb/legal/privacy/

This plugin is not made, endorsed or supported by Sage. Sage and Sage Accounting are trademarks of The Sage Group plc.

== Installation ==

1. Install and activate the plugin. WooCommerce must be active.
2. Go to WooCommerce > Settings > Sage Sync. Copy the callback URL shown there.
3. Sign in at https://developerselfservice.sageone.com/ (GitHub or email), click Create App, give it a name and paste the callback URL. Save.
4. Copy the app's Client ID and Client Secret into the plugin settings and save.
5. Click Connect to Sage and sign in with the Sage login you use for your books. Allow access.
6. Check the sales, shipping and bank accounts and the VAT rates that appear, then save.
7. Place a test order and check the order notes for the Sage invoice number.

== Frequently Asked Questions ==

= Why do I need my own Sage developer app? =

A Client Secret cannot be kept secret inside a plugin that anyone can download. With your own app, your keys stay in your site and Sage only gives access to the businesses you allow. Creating the app is free and takes about two minutes.

= Which orders are sent? =

Orders that reach one of the statuses you pick (Processing and Completed by default) after you connect. Each order is sent once. Changing the status again does not create a second invoice.

= An order note says the Sage total differs from the order total. =

Usually a WooCommerce tax rate maps to the wrong Sage VAT rate. Map each WooCommerce tax rate to its Sage rate in the settings, then fix that invoice in Sage.

= Where do I see errors? =

In the order notes of the order, and in WooCommerce > Status > Logs under the source skillmerc-sync-for-sage-accounting. Turn on the debug log for more detail.

= Does it work with Sage 50 or Sage Business Cloud Accounting South Africa? =

No. Sage 50 desktop and the South African Sage Accounting use different APIs.

== Changelog ==

= 0.1.0 =
* First release: connect with your own Sage app, send paid orders as contact, sales invoice and customer receipt, VAT per line, shipping line, background retries, manual order action.
