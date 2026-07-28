<?php

defined( 'ABSPATH' ) || exit;

final class BSXMH_Transparency {
    public static function register(): void {
        add_shortcode( 'bsxmh_transparency_dashboard', array( __CLASS__, 'shortcode' ) );
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
    }

    public static function ensure_defaults(): void {
        $defaults = array(
            'members' => 1,
            'collection' => 1,
            'membership' => 1,
            'contribution' => 1,
            'events' => 1,
            'expense' => 1,
            'balance' => 1,
            'funds' => 1,
            'campaigns' => 1,
        );
        update_option( 'bsxmh_transparency_widgets', wp_parse_args( get_option( 'bsxmh_transparency_widgets', array() ), $defaults ), false );
    }

    public static function visibility(): string {
        $settings = get_option( 'bsxmh_settings', array() );
        $visibility = (string) ( $settings['public_dashboard_visibility'] ?? 'members' );
        return in_array( $visibility, array( 'public', 'members', 'admin', 'hidden' ), true ) ? $visibility : 'members';
    }

    public static function can_view(): bool {
        $visibility = self::visibility();
        if ( 'hidden' === $visibility ) return false;
        if ( 'public' === $visibility ) return true;
        if ( 'members' === $visibility ) return is_user_logged_in();
        return current_user_can( 'manage_bsxmh' );
    }

    public static function show_in_member_navigation(): bool {
        return in_array( self::visibility(), array( 'public', 'members' ), true );
    }

    public static function register_settings(): void {
        register_setting( 'bsxmh_transparency_group', 'bsxmh_transparency_widgets', array( __CLASS__, 'sanitize' ) );
    }

    public static function sanitize( $input ): array {
        $out = array();
        foreach ( array( 'members', 'collection', 'membership', 'contribution', 'events', 'expense', 'balance', 'funds', 'campaigns' ) as $key ) {
            $out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
        }
        return $out;
    }

    public static function menu(): void {
        add_submenu_page( 'bsxmh', 'Transparency', 'Transparency', 'bsxmh_manage_settings', 'bsxmh-transparency', array( __CLASS__, 'page' ) );
    }

    public static function page(): void {
        $widgets = get_option( 'bsxmh_transparency_widgets', array() );
        $page_url = BSXMH_Portal::page_url( 'transparency_page_id', '/transparency-dashboard/' );
        echo '<div class="wrap bsxmh-wrap"><h1>Transparency Dashboard</h1><div class="bsxmh-panel">';
        echo '<p>The plugin automatically creates a published <strong>Transparency Dashboard</strong> page using <code>[bsxmh_transparency_dashboard]</code>.</p>';
        echo '<p><a class="button button-primary" target="_blank" href="' . esc_url( $page_url ) . '">Open Transparency Page</a> <a class="button" href="' . esc_url( admin_url( 'admin.php?page=bsxmh-settings' ) ) . '">Access Settings</a></p>';
        echo '<form method="post" action="options.php">';
        settings_fields( 'bsxmh_transparency_group' );
        echo '<table class="form-table">';
        foreach ( array( 'members'=>'Active Members', 'collection'=>'Total Collection', 'membership'=>'Membership Collection', 'contribution'=>'Extra Contribution', 'events'=>'Event Collection', 'expense'=>'Total Expense', 'balance'=>'Current Balance', 'funds'=>'Fund Summary', 'campaigns'=>'Active Campaigns' ) as $key => $label ) {
            echo '<tr><th>' . esc_html( $label ) . '</th><td><label><input type="checkbox" name="bsxmh_transparency_widgets[' . esc_attr( $key ) . ']" value="1" ' . checked( ! empty( $widgets[ $key ] ), true, false ) . '> Show</label></td></tr>';
        }
        echo '</table>';
        submit_button();
        echo '</form></div></div>';
    }

    public static function shortcode(): string {
        wp_enqueue_style( 'bsxmh-public' );

        $visibility = self::visibility();
        if ( ! self::can_view() ) {
            if ( 'members' === $visibility && ! is_user_logged_in() ) {
                $login_url = BSXMH_Portal::page_url( 'login_page_id', '/member-login/' );
                return '<div class="bsxmh-portal-shell"><div class="bsxmh-notice">' . esc_html__( 'Please log in as a member to view the Transparency Dashboard.', 'bsx-memberhub' ) . ' <a href="' . esc_url( $login_url ) . '">' . esc_html__( 'Member Login', 'bsx-memberhub' ) . '</a></div></div>';
            }
            return '';
        }

        global $wpdb;
        $widgets = get_option( 'bsxmh_transparency_widgets', array() );
        $payments_table = BSXMH_DB::table( 'payments' );
        $expenses_table = BSXMH_DB::table( 'expenses' );
        $members_table = BSXMH_DB::table( 'members' );
        $sum_payments = static function( string $type = '' ) use ( $wpdb, $payments_table ): float {
            $sql = "SELECT COALESCE(SUM(total_amount),0) FROM $payments_table WHERE status='paid'";
            if ( $type ) $sql .= $wpdb->prepare( ' AND payment_type=%s', $type );
            return (float) $wpdb->get_var( $sql );
        };

        $all = $sum_payments();
        $membership = $sum_payments( 'membership' );
        $extra = $sum_payments( 'extra_contribution' );
        $events = $sum_payments( 'event_donation' );
        $expense = (float) $wpdb->get_var( "SELECT COALESCE(SUM(amount),0) FROM $expenses_table WHERE status='paid'" );
        $members = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $members_table WHERE status='active'" );
        $symbol = BSXMH_Payments::currency_symbol();

        $cards = array();
        if ( ! empty( $widgets['members'] ) ) $cards[] = array( __( 'Active Members', 'bsx-memberhub' ), number_format_i18n( $members ) );
        if ( ! empty( $widgets['collection'] ) ) $cards[] = array( __( 'Total Collection', 'bsx-memberhub' ), $symbol . number_format_i18n( $all, 2 ) );
        if ( ! empty( $widgets['membership'] ) ) $cards[] = array( __( 'Membership Collection', 'bsx-memberhub' ), $symbol . number_format_i18n( $membership, 2 ) );
        if ( ! empty( $widgets['contribution'] ) ) $cards[] = array( __( 'Extra Contribution', 'bsx-memberhub' ), $symbol . number_format_i18n( $extra, 2 ) );
        if ( ! empty( $widgets['events'] ) ) $cards[] = array( __( 'Event Collection', 'bsx-memberhub' ), $symbol . number_format_i18n( $events, 2 ) );
        if ( ! empty( $widgets['expense'] ) ) $cards[] = array( __( 'Total Expense', 'bsx-memberhub' ), $symbol . number_format_i18n( $expense, 2 ) );
        if ( ! empty( $widgets['balance'] ) ) $cards[] = array( __( 'Current Balance', 'bsx-memberhub' ), $symbol . number_format_i18n( $all - $expense, 2 ) );

        $html = '<section class="bsxmh-page-hero"><div class="bsxmh-page-icon" aria-hidden="true">◫</div><div><p class="bsxmh-eyebrow">Organization Overview</p><h2>' . esc_html__( 'Transparency Dashboard', 'bsx-memberhub' ) . '</h2><p>' . esc_html__( 'View key collection, expense, fund and campaign information in one place.', 'bsx-memberhub' ) . '</p></div></section><div class="bsxmh-public-card bsxmh-transparency-card"><div class="bsxmh-summary bsxmh-transparency">';
        foreach ( $cards as $card ) {
            $html .= '<div><span>' . esc_html( $card[0] ) . '</span><strong>' . esc_html( $card[1] ) . '</strong></div>';
        }
        $html .= '</div>';

        if ( ! empty( $widgets['funds'] ) ) {
            $html .= '<section class="bsxmh-transparency-section"><h3>' . esc_html__( 'Fund Summary', 'bsx-memberhub' ) . '</h3><div class="bsxmh-summary">';
            foreach ( BSXMH_Contributions::fund_summary() as $row ) {
                $fund = $row['fund'];
                if ( 'hidden' === $fund->visibility ) continue;
                if ( 'members' === $fund->visibility && ! is_user_logged_in() ) continue;
                if ( 'admin' === $fund->visibility && ! current_user_can( 'manage_bsxmh' ) ) continue;
                $html .= '<div><span>' . esc_html( $fund->name ) . '</span><strong>' . esc_html( $symbol . number_format_i18n( $row['balance'], 2 ) ) . '</strong></div>';
            }
            $html .= '</div></section>';
        }

        if ( ! empty( $widgets['campaigns'] ) ) {
            $active = BSXMH_Events::all( true );
            if ( $active ) {
                $html .= '<section class="bsxmh-transparency-section"><h3>' . esc_html__( 'Active Campaigns', 'bsx-memberhub' ) . '</h3><div class="bsxmh-event-grid">';
                foreach ( array_slice( $active, 0, 6 ) as $event ) {
                    $stats = BSXMH_Events::stats( (int) $event->id );
                    $html .= '<article class="bsxmh-event-card"><h4>' . esc_html( $event->title ) . '</h4><div class="bsxmh-progress"><span style="width:' . esc_attr( (string) $stats['percent'] ) . '%"></span></div><p>' . esc_html( $symbol . number_format_i18n( $stats['collected'], 2 ) . ' of ' . $symbol . number_format_i18n( $stats['target'], 2 ) ) . '</p></article>';
                }
                $html .= '</div></section>';
            }
        }
        $html .= '</div>';

        if ( is_user_logged_in() && BSXMH_Members::get_by_user( get_current_user_id() ) ) {
            return BSXMH_Portal::wrap_member_page( $html, 'transparency' );
        }
        return '<div class="bsxmh-portal-shell"><main class="bsxmh-portal-content">' . $html . '</main></div>';
    }
}
