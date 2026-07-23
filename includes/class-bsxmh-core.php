<?php

defined( 'ABSPATH' ) || exit;

final class BSXMH_Core {
    public function run(): void {
        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
        add_action( 'init', array( $this, 'maybe_upgrade' ) );
        add_action( 'bsxmh_daily_scheduled_tasks', array( $this, 'run_daily_tasks' ) );

        if ( is_admin() ) {
            ( new BSXMH_Admin() )->register();
        }
        BSXMH_Form_Builder::register();
        BSXMH_Receipts::register();
        BSXMH_Finance::register();
        ( new BSXMH_Public() )->register();
        BSXMH_Portal::register();
        BSXMH_Gateways::register();
        BSXMH_Email_Automation::register();
        BSXMH_Roles::register();
        BSXMH_Reports::register();
        BSXMH_Transparency::register();
        BSXMH_System_Tools::register();
        BSXMH_Payment_Control::register();
    }

    public function load_textdomain(): void {
        load_plugin_textdomain( 'bsx-memberhub', false, dirname( BSXMH_BASENAME ) . '/languages' );
    }

    public function maybe_upgrade(): void {
        if ( get_option( 'bsxmh_db_version' ) !== BSXMH_VERSION ) {
            BSXMH_DB::install();
            $settings = get_option( 'bsxmh_settings', array() );
            if ( empty( $settings['member_id_prefix'] ) ) {
                $settings['member_id_prefix'] = 'MH';
                update_option( 'bsxmh_settings', $settings, false );
            }
            BSXMH_Form_Builder::ensure_defaults();
            BSXMH_Contributions::ensure_defaults();
            BSXMH_Finance::ensure_defaults();
            BSXMH_Receipts::ensure_all();
            BSXMH_Portal::create_pages();
            BSXMH_Email_Automation::ensure_defaults();
            BSXMH_Roles::ensure_roles();
            BSXMH_Transparency::ensure_defaults();
            flush_rewrite_rules( false );
            update_option( 'bsxmh_version', BSXMH_VERSION, false );
        }
    }

    public function run_daily_tasks(): void {
        do_action( 'bsxmh_process_email_queue' );
        do_action( 'bsxmh_generate_due_reminders' );
    }
}
