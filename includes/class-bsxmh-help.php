<?php

defined( 'ABSPATH' ) || exit;

final class BSXMH_Help {
    public static function register(): void {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 90 );
        add_action( 'current_screen', array( __CLASS__, 'contextual_help' ) );
    }

    public static function menu(): void {
        add_submenu_page( 'bsxmh', 'MemberHub Help', 'Help', 'bsxmh_manage_settings', 'bsxmh-help', array( __CLASS__, 'render' ) );
    }

    public static function contextual_help( $screen ): void {
        if ( ! $screen || false === strpos( (string) $screen->id, 'bsxmh' ) ) return;
        $screen->add_help_tab( array(
            'id'      => 'bsxmh_quick_help',
            'title'   => 'MemberHub Help',
            'content' => '<p><strong>BackspaceX MemberHub</strong> centralizes members, fees, contributions, events, finance, email automation and transparency.</p><p>Open <a href="' . esc_url( admin_url( 'admin.php?page=bsxmh-help' ) ) . '">MemberHub → Help</a> for complete guidance.</p>',
        ) );
        $screen->set_help_sidebar( '<p><strong>Quick checks</strong></p><p>Use <a href="' . esc_url( admin_url( 'admin.php?page=bsxmh-repair' ) ) . '">System Health & Repair</a> when pages, cron, email or database setup needs attention.</p>' );
    }

    public static function render(): void {
        $sections = array(
            'getting-started' => array( 'Getting Started', 'Run the Setup Wizard, verify portal pages, choose the default membership fund, configure monthly fees, set up email delivery through an SMTP plugin, and test the payment gateway before production use.' ),
            'members' => array( 'Members & Member 360°', 'Create, approve, suspend and manage members from the Members screen. Member 360° combines profile details, photo, fee status, timeline, payments, contributions, events, notes and audit activity.' ),
            'fees' => array( 'Membership Fees', 'Set the default monthly fee globally or override it per member. Fee statements calculate paid, due and advance months from the member fee start date.' ),
            'reminders' => array( 'Smart Fee Reminders', 'Choose multiple reminder dates, optionally include the last day of the month, and set a minimum interval between emails. Scheduled reminders are queued only for active members who still have unpaid months.' ),
            'funds' => array( 'Funds & Contributions', 'Funds separate membership income, extra contributions and campaign collections. The default membership fund receives membership fee income unless a different fund is assigned to a member.' ),
            'events' => array( 'Events', 'Create fundraising events, accept event contributions, review event totals and manage active or draft status.' ),
            'email' => array( 'Email Automation', 'MemberHub sends through WordPress wp_mail(). Configure WP Mail SMTP, FluentSMTP, Post SMTP or another mail plugin for reliable delivery. Templates support registration, status, reminder and receipt variables.' ),
            'transparency' => array( 'Transparency Dashboard', 'The Transparency page can be public, member-only, admin-only or hidden. Control which totals, funds, campaigns and member counts are visible.' ),
            'reports' => array( 'Reports & Finance', 'Use Finance and Reports for income, expense, transaction and fund summaries. Financial ledger upgrades are planned for v1.5.0.' ),
            'shortcodes' => array( 'Shortcodes', 'Open MemberHub → Shortcodes for the complete shortcode list, purpose, parameters and copy-ready examples.' ),
            'troubleshooting' => array( 'Troubleshooting', 'If reminders do not run, verify WP-Cron and the selected reminder dates. If email is queued but not delivered, check the SMTP plugin log. If a portal page is missing, use Repair Missing Pages.' ),
        );
        echo '<div class="wrap bsxmh-wrap"><h1>MemberHub Help Center</h1><p>Documentation for the current plugin features and settings.</p><div class="bsxmh-help-layout" style="display:grid;grid-template-columns:minmax(190px,240px) minmax(0,1fr);gap:24px;align-items:start">';
        echo '<div class="bsxmh-panel" style="position:sticky;top:48px"><strong>Contents</strong><ul>';
        foreach ( $sections as $id => $data ) echo '<li><a href="#' . esc_attr( $id ) . '">' . esc_html( $data[0] ) . '</a></li>';
        echo '</ul></div><div>';
        foreach ( $sections as $id => $data ) echo '<section id="' . esc_attr( $id ) . '" class="bsxmh-panel" style="scroll-margin-top:50px"><h2>' . esc_html( $data[0] ) . '</h2><p>' . esc_html( $data[1] ) . '</p></section>';
        echo '<section class="bsxmh-panel"><h2>Recommended Production Checklist</h2><ol><li>Complete Setup Wizard.</li><li>Test registration and approval emails.</li><li>Send a test email from Email Automation.</li><li>Verify scheduled reminder dates and WP-Cron.</li><li>Test one sandbox payment and its receipt.</li><li>Review Transparency access before sharing the page.</li><li>Run System Health after every major upgrade.</li></ol></section>';
        echo '</div></div></div>';
    }
}
