<?php

defined( 'ABSPATH' ) || exit;

final class BSXMH_Activator {
    public static function activate(): void {
        self::check_requirements();
        self::add_roles();
        BSXMH_DB::install();
        BSXMH_Contributions::ensure_defaults();
        BSXMH_Finance::ensure_defaults();
        self::add_default_options();
        BSXMH_Portal::create_pages();
        BSXMH_Email_Automation::ensure_defaults();
        BSXMH_Roles::ensure_roles();
        BSXMH_Transparency::ensure_defaults();
        self::schedule_events();
        BSXMH_Receipts::add_rewrite();
        flush_rewrite_rules();
    }

    private static function check_requirements(): void {
        if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
            deactivate_plugins( BSXMH_BASENAME );
            wp_die( esc_html__( 'BackspaceX MemberHub requires PHP 8.1 or newer.', 'bsx-memberhub' ) );
        }
        global $wp_version;
        if ( version_compare( $wp_version, '6.5', '<' ) ) {
            deactivate_plugins( BSXMH_BASENAME );
            wp_die( esc_html__( 'BackspaceX MemberHub requires WordPress 6.5 or newer.', 'bsx-memberhub' ) );
        }
    }

    private static function add_roles(): void {
        add_role(
            'bsxmh_member',
            __( 'Member', 'bsx-memberhub' ),
            array(
                'read' => true,
                'bsxmh_view_own_dashboard' => true,
                'bsxmh_pay_own_fees' => true,
                'bsxmh_view_own_receipts' => true,
            )
        );

        $admin = get_role( 'administrator' );
        if ( $admin ) {
            foreach ( self::admin_capabilities() as $capability ) {
                $admin->add_cap( $capability );
            }
        }
    }

    private static function admin_capabilities(): array {
        return array(
            'manage_bsxmh', 'bsxmh_manage_members', 'bsxmh_manage_payments',
            'bsxmh_manage_events', 'bsxmh_manage_finance', 'bsxmh_view_reports',
            'bsxmh_manage_settings', 'bsxmh_send_reminders', 'bsxmh_view_audit_log',
        );
    }

    private static function add_default_options(): void {
        $defaults = array(
            'organization_name' => get_bloginfo( 'name' ),
            'organization_email' => get_option( 'admin_email' ),
            'currency' => 'BDT',
            'timezone' => wp_timezone_string() ?: 'Asia/Dhaka',
            'financial_year_start_month' => 1,
            'organization_start_month' => (int) current_time( 'n' ),
            'organization_start_year' => (int) current_time( 'Y' ),
            'default_monthly_fee' => '100.00',
            'default_registration_role' => 'bsxmh_member',
            'member_id_prefix' => 'MH',
            'registration_enabled' => 1,
            'registration_requires_approval' => 1,
            'public_dashboard_visibility' => 'public',
            'receipt_prefix' => 'BSXMH',
            'receipt_title' => 'Payment Receipt',
            'receipt_header' => '',
            'receipt_footer' => '',
            'receipt_thank_you' => 'Thank you for your payment.',
            'organization_logo_url' => '',
            'voucher_prefix' => 'BSXV',
            'voucher_title' => 'Expense Voucher',
            'schema_version' => BSXMH_VERSION,
        );
        add_option( 'bsxmh_settings', $defaults, '', false );
        add_option( 'bsxmh_version', BSXMH_VERSION, '', false );
    }

    private static function schedule_events(): void {
        if ( ! wp_next_scheduled( 'bsxmh_hourly_email_queue' ) ) { wp_schedule_event( time() + 300, 'hourly', 'bsxmh_hourly_email_queue' ); }
        if ( ! wp_next_scheduled( 'bsxmh_daily_scheduled_tasks' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'bsxmh_daily_scheduled_tasks' );
        }
    }
}
