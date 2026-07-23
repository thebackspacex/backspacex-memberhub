<?php

defined( 'ABSPATH' ) || exit;

final class BSXMH_Deactivator {
    public static function deactivate(): void {
        wp_clear_scheduled_hook( 'bsxmh_daily_scheduled_tasks' );
        wp_clear_scheduled_hook( 'bsxmh_hourly_email_queue' );
        flush_rewrite_rules();
    }
}
