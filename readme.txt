=== BackspaceX MemberHub ===
Contributors: backspacex
Tags: membership, nonprofit, donation, finance, fundraising, sslcommerz
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.5.0
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

= 1.5.0 =
* Stable production release of the v1.5 Member Experience upgrade.
* Removed theme-generated underlines from all Member Portal navigation tabs in normal, hover, focus, active and visited states.
* Normalized navigation icon and label alignment and protected the tabs from aggressive theme link styles.
* Includes the redesigned Member Dashboard, Digital Membership Card with QR verification and PNG download, Member Finance Center, Notification Center, Profile Completion, unified Member Portal UI, Member Directory, and all beta stabilization fixes.
* No database reset is required when upgrading from v1.4.x or any v1.5 beta build.

= 1.5.0-beta8 =
* Code freeze stabilization release with no new member-facing modules.
* Added a concurrency-safe upgrade lock to prevent duplicate database migrations on simultaneous requests.
* Added self-healing daily cron scheduling for email queues and due reminders.
* Fixed the receipts table schema syntax so fresh installs and upgrades can create/repair the table reliably.
* Centralized early loading of the shared Member Portal design system across configured pages and shortcode pages.
* Added responsive overflow protection, keyboard focus states, reduced-motion support, touch-friendly controls, and print cleanup.
* Preserved all v1.5 beta data, settings, payment flows, cards, notifications, finance, profile completion, and directory features.


= 1.5.0-beta7.1 =
* Fixed the Member Directory rendering without its portal stylesheet.
* Added early asset loading for the auto-created Directory page and pages containing the directory shortcode.
* Added a safe shortcode-time asset fallback for page builders and non-standard themes.
* Ensured shared Member Portal JavaScript also loads on the Directory page.


= 1.5.0-beta7 =
* Added a logged-in, privacy-safe Member Directory.
* Added active-member cards with photo, Member ID, join date, status and member tags.
* Added member search, tag filters, pagination and configurable sorting.
* Added member-only profile previews without exposing email, phone, address or financial information.
* Added admin settings for directory visibility, fields, page size and member opt-out.
* Added a member profile privacy control to hide from the directory.
* Added an auto-created Member Directory page, member navigation tab and shortcode.


= 1.5.0-beta6.1 =
* Added Smart Profile Completion with weighted core and custom profile fields.
* Added a detailed completion checklist to the member Profile page.
* Added profile completion percentage, progress indicator and filters to the admin Members list.
* Improved the Profile page heading, information hierarchy and mobile layout.
* Reused one completion engine across Dashboard, Profile and admin views for consistent results.

= 1.5.0-beta6 =
* Complete member portal UI/UX redesign and unified design system.
* Redesigned Pay Fees with summary cards, selectable month cards, live selected total and sticky secure checkout.
* Redesigned Contribution and Event donation forms with suggested amount buttons.
* Redesigned Events, Transparency and Profile page headers and card styling.
* Added icon-based member navigation and improved mobile layouts.
* Added consistent buttons, badges, cards, form controls, empty states and hover/focus behavior.


= 1.5.0-beta5 =
* Added organization-wide member portal branding colors, website and support details.
* Added configurable member portal footer.
* Added profile completion progress and missing-field guidance on the member dashboard.
* Added notification search, read/unread/archived filters and member-side delete.
* Improved responsive polish for profile completion, notifications and portal footer.
* Fixed frontend profile phone saving for current profile_data-based member schemas.


= 1.5.0-beta4.1 =
* Fixed fatal error on the admin Notifications page caused by a call to a missing BSXMH_Members::all() method.
* Fixed admin member-search database error caused by querying the removed m.phone column.
* Phone search now reads the value safely from member profile data, with user-meta fallback.


= 1.5.0-beta3 =
* Added a complete member-facing My Finance Center.
* Added finance summary cards for membership paid, current due, contributions, event payments, transactions and last payment.
* Added secure member-only transaction history with type, status, year and reference filters.
* Added month-by-month membership fee history with paid/due status and quick payment links.
* Added dedicated contribution and event payment histories.
* Added a member receipt center with view, print and Save as PDF access.
* Added mobile-first transaction cards, finance navigation and dashboard shortcut.
* Preserved existing payment, contribution, event and receipt workflows.

= 1.5.0-beta1 =
* Introduced the new mobile-first Member Home Dashboard.
* Added member identity hero with profile photo, status, ID, organization and join date.
* Added membership, dues, contribution and event summary cards.
* Added next-payment panel with Pay Now and Pay All Due actions.
* Added quick actions for fees, contributions, events and profile.
* Added a compact recent activity feed with receipt links.
* Preserved existing payment, contribution, event, profile and portal navigation workflows.
= 1.4.1 =
* Fixed Members screen calculations not updating for membership payments linked through payment-item member references.
* Member monthly status, paid totals, due totals, collection cards and table rows now resolve payments by both WordPress user ID and MemberHub member ID.
* Preserved compatibility with legacy and current payment records.

= 1.4.0 =
* Stable release consolidating the complete v1.4 member experience and administration upgrade.
* Added Member 360° with responsive overview, profile photo, timeline, payments, contributions, events, notes, documents placeholder and activity views.
* Added member self-service profile photo upload, replacement and removal with secure validation and configurable limits.
* Added administrator-assigned member tags across Member 360°, member dashboard and profile.
* Added chronological timeline and technical audit history for member, payment, contribution, event and profile activity.
* Added multiple monthly reminder days, last-day-of-month support, due-member targeting and minimum-gap protection.
* Added a complete Help Center, contextual WordPress Help tabs and expanded System Health checks.
* Fixed tag rendering fatal errors and hardened empty, null and legacy member data handling.
* Preserved upgrade compatibility with v1.3.0 and all v1.4 beta releases.

= 1.4.0-beta4.1 =
* Fixed a fatal error when opening Member 360 for a member with one or more tags.
* Added null-safe and array-safe tag badge rendering in Member 360.
* Added administrator-assigned member tags to the member dashboard and frontend profile page.

= 1.4.0-beta4 =
* Added multiple membership fee reminder days and optional last-day-of-month scheduling.
* Scheduled reminders now target only active members with unpaid months.
* Added minimum-days-between-reminders protection to prevent duplicate reminder emails.
* Added a complete Help Center and contextual WordPress Help tabs across MemberHub admin screens.
* Expanded System Health checks for reminder scheduling and email sender configuration.



= 1.4.0-beta3 =
* Added member self-service profile photo upload, replace and remove controls to the frontend Profile page.
* Added secure nonce, ownership, MIME and configurable file-size validation for member photo uploads.
* Added Settings controls to enable/disable member photo uploads and set a 1–10 MB maximum size.
* Added automatic timeline/audit entries for member-initiated photo updates and removals.
* Added member tags to admin member editing and Member 360° identity header.
* Improved responsive profile photo UI for desktop and mobile.



= 1.4.0-beta2 =
* Activated the Member 360° Timeline, Contributions, Events and Activity tabs.
* Added a chronological timeline combining member actions with existing payment, contribution and event records.
* Added timeline filters for membership, payments, contributions, events, profile and system activity.
* Added technical per-member audit history with actor, action, object and stored details.
* Added dedicated contribution and event-payment history tables.
* Improved member update logs to preserve old and new status values.
* Kept Documents reserved for the later Documents & Verification release.

= 1.4.0-beta1 =
* Added Member 360° profile foundation with responsive tabbed navigation.
* Added member profile photo upload, replace and remove support.
* Added overview statistics, membership details and payment summary.
* Added Member 360° links and profile photos to the Members table.
* Reserved Timeline, Contributions, Events, Documents and Activity tabs for later v1.4.0 modules.
= 1.3.0 =
* Automatically creates and stores a published Transparency Dashboard page.
* Adds Transparency to the persistent member portal navigation when access permits.
* Adds Public, logged-in members, admin-only and hidden access modes.
* Wraps the dashboard in the unified centered member portal layout.
* Adds quick links from admin settings and the Transparency configuration page.
* Preserves configurable visibility for summary cards, funds and active campaigns.


= 1.2.7 =
* Fixed invisible active member navigation labels caused by currentColor resolving to white.
* Added explicit active background, border and text colors with theme-resistant selectors.
* Improved active navigation hover, focus and keyboard focus visibility.

= 1.2.6 =
* Added quick Pay buttons for each due month and Pay All Due on the member dashboard.
* Added persistent member portal navigation with active-page highlighting.
* Centered and standardized member portal page layouts across fees, contributions, events, and profile.
* Added responsive portal navigation and quick-payment layouts.

= 1.2.5 =
* Added member email notifications for every administrator status change.
* Added editable Pending, Inactive, Suspended and Deleted User email templates.
* Active status changes use the membership approval template.
* Status emails are queued only when the status actually changes.
* Added a setting to enable or disable member status-change notifications.

= 1.2.4 =
* Preserve MemberHub financial history when a linked WordPress user is deleted.
* Mark deleted accounts as Deleted User automatically.
* Add Deleted Users member filter and safe read-only statements.
* Add orphan member scan and repair in System Health.
* Add deletion and repair audit logging.


= 1.2.3 =
* Added member confirmation email after successful registration.
* Added administrator notification email for new registrations.
* Added multiple admin recipient support and notification toggles.
* Added editable new-registration email templates and prompt queue processing.


= 1.2.2 =
* Centered the complete member registration interface to match the login page.
* Kept the organization logo, description, required-fields notice and form in one responsive centered column.
* Improved registration layout consistency across desktop, tablet and mobile screens.

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
