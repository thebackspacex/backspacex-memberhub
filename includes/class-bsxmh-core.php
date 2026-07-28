<?php

defined( 'ABSPATH' ) || exit;

final class BSXMH_Core {
    private const UPGRADE_LOCK = 'bsxmh_upgrade_lock';

    public function run(): void {
        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
        add_action( 'init', array( $this, 'maybe_upgrade' ), 5 );
        add_action( 'init', array( $this, 'ensure_cron' ), 20 );
        add_action( 'bsxmh_daily_scheduled_tasks', array( $this, 'run_daily_tasks' ) );
        add_action( 'deleted_user', array( 'BSXMH_Members', 'handle_deleted_user' ), 10, 3 );

        if ( is_admin() ) {
            ( new BSXMH_Admin() )->register();
        }
        BSXMH_Form_Builder::register();
        BSXMH_Receipts::register();
        BSXMH_Finance::register();
        ( new BSXMH_Public() )->register();
        BSXMH_Portal::register();
        BSXMH_Membership_Card::register();
        BSXMH_Member_Finance::register();
        BSXMH_Notifications::register();
        BSXMH_Member_Directory::register();
        BSXMH_Gateways::register();
        BSXMH_Email_Automation::register();
        BSXMH_Roles::register();
        BSXMH_Reports::register();
        BSXMH_Transparency::register();
        BSXMH_System_Tools::register();
        BSXMH_Help::register();
        BSXMH_Payment_Control::register();
    }

    public function load_textdomain(): void {
        load_plugin_textdomain( 'bsx-memberhub', false, dirname( BSXMH_BASENAME ) . '/languages' );
    }

    /**
     * Run database and data migrations once per plugin version.
     *
     * A short-lived lock prevents two concurrent frontend requests from running
     * dbDelta(), receipt rebuilding, and page creation at the same time.
     */
    public function maybe_upgrade(): void {
        if ( (string) get_option( 'bsxmh_db_version', '' ) === BSXMH_VERSION ) {
            return;
        }

        if ( get_transient( self::UPGRADE_LOCK ) ) {
            return;
        }

        set_transient( self::UPGRADE_LOCK, time(), 5 * MINUTE_IN_SECONDS );

        try {
            BSXMH_DB::install();

            $settings = get_option( 'bsxmh_settings', array() );
            $settings = is_array( $settings ) ? $settings : array();
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
            BSXMH_Members::repair_orphans();
            $this->ensure_cron();

            flush_rewrite_rules( false );
            update_option( 'bsxmh_version', BSXMH_VERSION, false );
            update_option( 'bsxmh_db_version', BSXMH_VERSION, false );
            do_action( 'bsxmh_plugin_upgraded', BSXMH_VERSION );
        } finally {
            delete_transient( self::UPGRADE_LOCK );
        }
    }

    /** Keep the daily queue/reminder schedule self-healing after migrations. */
    public function ensure_cron(): void {
        if ( ! wp_next_scheduled( 'bsxmh_daily_scheduled_tasks' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'bsxmh_daily_scheduled_tasks' );
        }
    }

    public function run_daily_tasks(): void {
        do_action( 'bsxmh_process_email_queue' );
        do_action( 'bsxmh_generate_due_reminders' );
    }
}
