=== BackspaceX MemberHub ===
Contributors: backspacex
Tags: membership, nonprofit, donation, finance, fundraising, sslcommerz
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.1.6
License: GPLv2 or later

Complete membership, subscription, donation, fundraising and finance management for WordPress.

== Description ==
BackspaceX MemberHub is a standalone WordPress management system for associations, alumni groups, clubs, charities, mosque committees and nonprofit organizations.

Core features include frontend registration and profile builder, member approval, recurring fees, manual and SSLCOMMERZ payments, secure guest payment links, receipts, contributions, fund accounting, fundraising events, expense management, email automation, reports and public transparency.

== Shortcodes ==
* [bsxmh_login]
* [bsxmh_register]
* [bsxmh_dashboard]
* [bsxmh_profile]
* [bsxmh_payment]
* [bsxmh_guest_payment]
* [bsxmh_collection_summary]
* [bsxmh_transparency_dashboard]
* [bsxmh_event_list]
* [bsxmh_event]
* [bsxmh_finance_summary]

== Changelog ==

= 1.1.1 =
* Fixed SSLCOMMERZ browser return handling for success, failure, and cancellation.
* Added transaction fallback parameters and reliable frontend redirects.


= 1.1.0 =
* Added Member Payment Control Center with monthly paid/unpaid lists.
* Added at-a-glance due months, totals, payment details and reminder history.
* Added one-click and bulk payment reminders with secure payment links.
* Added month selector defaulting to the current month, filters and collection summary.
= 1.0.0 =
* Added Advanced Reports with date ranges, monthly trends, printable output and combined CSV export.
* Added configurable Public Transparency Dashboard and shortcode.
* Added Setup Wizard and missing-page/data repair tools.
* Added Treasurer, Accountant, Membership Officer, Event Manager and Viewer roles.
* Added system diagnostics for versions, cron, REST API, HTTPS and upload permissions.
* Added an admin Audit Log viewer.
* Preserved all v0.9.6 member, payment, fund, event, receipt, gateway, form and email data.

= 0.9.6 =
* Added email templates, queue, logs, reminders and secure guest payment links.

= 0.9.5.1 =
* Fixed frontend profile custom field and mobile-number saving.

== 1.1.5 ==
* Added Member Dashboard Contribution navigation and auto-created contribution page.
* Added searchable member selectors across MemberHub admin screens.
* Confirmed wp_mail()-based compatibility with WP Mail SMTP and similar plugins.
* Added mail provider status and Send Test Email tool.


== 1.1.6 ==
* Prevented duplicate monthly membership fee selection in the frontend payment form.
* Kept already-paid months visible with a disabled checkbox and Paid badge.
* Added server-side duplicate-payment protection confirmation for all membership payment submissions.
* Added guidance to use the Contribution module for additional payments.
* Confirmed compatibility with successful SSLCOMMERZ sandbox checkout flow.
