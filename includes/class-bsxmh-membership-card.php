<?php

defined( 'ABSPATH' ) || exit;

final class BSXMH_Membership_Card {
    public static function register(): void {
        add_shortcode( 'bsxmh_membership_card', array( __CLASS__, 'render_card' ) );
        add_shortcode( 'bsxmh_member_verification', array( __CLASS__, 'render_verification' ) );
        add_action( 'admin_post_bsxmh_regenerate_card_token', array( __CLASS__, 'regenerate_token' ) );
        add_action( 'admin_post_bsxmh_toggle_card_verification', array( __CLASS__, 'toggle_verification' ) );
        add_action( 'template_redirect', array( __CLASS__, 'no_cache_verification' ) );
    }

    public static function enabled(): bool {
        $s = get_option( 'bsxmh_settings', array() );
        return ! isset( $s['membership_card_enabled'] ) || ! empty( $s['membership_card_enabled'] );
    }

    public static function verification_enabled(): bool {
        $s = get_option( 'bsxmh_settings', array() );
        return ! isset( $s['card_verification_enabled'] ) || ! empty( $s['card_verification_enabled'] );
    }

    private static function profile( $member ): array {
        $profile = json_decode( (string) ( $member->profile_data ?? '' ), true );
        return is_array( $profile ) ? $profile : array();
    }

    private static function save_profile( $member, array $profile ): bool {
        global $wpdb;
        return false !== $wpdb->update(
            BSXMH_DB::table( 'members' ),
            array( 'profile_data' => wp_json_encode( $profile ), 'updated_at' => current_time( 'mysql' ) ),
            array( 'id' => (int) $member->id ),
            array( '%s', '%s' ),
            array( '%d' )
        );
    }

    public static function token( $member, bool $create = true ): string {
        $profile = self::profile( $member );
        $token = sanitize_text_field( $profile['card_verification_token'] ?? '' );
        if ( ! $token && $create ) {
            $token = bin2hex( random_bytes( 24 ) );
            $profile['card_verification_token'] = $token;
            if ( ! isset( $profile['card_verification_disabled'] ) ) {
                $profile['card_verification_disabled'] = 0;
            }
            self::save_profile( $member, $profile );
        }
        return $token;
    }

    public static function manual_code( $member ): string {
        return 'MH-' . strtoupper( substr( hash_hmac( 'sha256', self::token( $member ), wp_salt( 'auth' ) ), 0, 4 ) . '-' . substr( hash_hmac( 'sha256', self::token( $member ), wp_salt( 'secure_auth' ) ), 0, 4 ) );
    }

    public static function verification_url( $member ): string {
        return add_query_arg( 'bsxmh_verify', rawurlencode( self::token( $member ) ), BSXMH_Portal::page_url( 'verification_page_id', '/member-verification/' ) );
    }

    public static function no_cache_verification(): void {
        if ( isset( $_GET['bsxmh_verify'] ) || is_page( absint( get_option( 'bsxmh_settings', array() )['verification_page_id'] ?? 0 ) ) ) {
            nocache_headers();
            header( 'X-Robots-Tag: noindex, nofollow', true );
        }
    }

    public static function render_card(): string {
        wp_enqueue_style( 'bsxmh-public' );
        wp_enqueue_script( 'bsxmh-qrcode' );
        wp_enqueue_script( 'bsxmh-card' );
        if ( ! self::enabled() ) return '<div class="bsxmh-notice">' . esc_html__( 'Digital membership cards are currently disabled.', 'bsx-memberhub' ) . '</div>';
        if ( ! is_user_logged_in() ) return '<div class="bsxmh-notice">' . esc_html__( 'Please log in to view your membership card.', 'bsx-memberhub' ) . '</div>';
        $user = wp_get_current_user();
        $member = BSXMH_Members::get_by_user( (int) $user->ID );
        if ( ! $member ) return '<div class="bsxmh-notice">' . esc_html__( 'No membership profile is linked to this account.', 'bsx-memberhub' ) . '</div>';
        return BSXMH_Portal::wrap_member_page( self::card_markup( $member, $user ), 'card' );
    }

    private static function card_markup( $member, WP_User $user ): string {
        $s = get_option( 'bsxmh_settings', array() );
        $org = $s['organization_name'] ?? get_bloginfo( 'name' );
        $title = $s['membership_card_title'] ?? __( 'Membership Card', 'bsx-memberhub' );
        $profile = self::profile( $member );
        $disabled = ! empty( $profile['card_verification_disabled'] );
        $status = sanitize_key( (string) $member->status );
        $status_label = ucfirst( $status );
        $url = self::verification_url( $member );
        ob_start(); ?>
        <div class="bsxmh-card-page">
            <div class="bsxmh-card-page-heading"><div><p class="bsxmh-eyebrow"><?php esc_html_e( 'Digital ID', 'bsx-memberhub' ); ?></p><h2><?php esc_html_e( 'My Membership Card', 'bsx-memberhub' ); ?></h2></div><div class="bsxmh-card-actions"><button type="button" class="bsxmh-action-primary bsxmh-download-card" data-filename="<?php echo esc_attr( 'Membership-Card-' . sanitize_file_name( (string) $member->member_number ) . '.png' ); ?>"><?php esc_html_e( 'Download PNG', 'bsx-memberhub' ); ?></button><button type="button" class="bsxmh-action-secondary bsxmh-print-card" onclick="window.print()"><?php esc_html_e( 'Print / Save PDF', 'bsx-memberhub' ); ?></button></div></div>
            <article class="bsxmh-membership-card" style="--card-accent:<?php echo esc_attr( $s['membership_card_accent'] ?? '#183153' ); ?>">
                <header class="bsxmh-card-header">
                    <div class="bsxmh-card-brand"><?php if ( ! empty( $s['organization_logo_url'] ) ) : ?><img src="<?php echo esc_url( $s['organization_logo_url'] ); ?>" alt=""><?php endif; ?><div><strong><?php echo esc_html( $org ); ?></strong><span><?php echo esc_html( $title ); ?></span></div></div>
                    <span class="bsxmh-card-status is-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $status_label ); ?></span>
                </header>
                <div class="bsxmh-card-body">
                    <div class="bsxmh-card-person"><?php echo wp_kses_post( BSXMH_Members::profile_photo_html( $member, 116, 'bsxmh-card-photo' ) ); ?><div><small><?php esc_html_e( 'Member', 'bsx-memberhub' ); ?></small><h3><?php echo esc_html( $user->display_name ); ?></h3><p><span><?php esc_html_e( 'Member ID', 'bsx-memberhub' ); ?></span><strong><?php echo esc_html( $member->member_number ); ?></strong></p><?php if ( ! empty( $s['card_show_member_since'] ) && ! empty( $member->join_date ) ) : ?><p><span><?php esc_html_e( 'Member Since', 'bsx-memberhub' ); ?></span><strong><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $member->join_date ) ) ); ?></strong></p><?php endif; ?></div></div>
                    <div class="bsxmh-card-qr-wrap"><div class="bsxmh-qr-code" data-qr="<?php echo esc_attr( $url ); ?>" aria-label="<?php esc_attr_e( 'Membership verification QR code', 'bsx-memberhub' ); ?>"></div><small><?php echo $disabled ? esc_html__( 'Verification disabled', 'bsx-memberhub' ) : esc_html__( 'Scan to verify membership', 'bsx-memberhub' ); ?></small><code><?php echo esc_html( self::manual_code( $member ) ); ?></code></div>
                </div>
                <footer><?php echo esc_html( $s['membership_card_footer'] ?? __( 'This card remains the property of the issuing organization.', 'bsx-memberhub' ) ); ?></footer>
            </article>
            <?php if ( $disabled ) : ?><div class="bsxmh-notice bsxmh-error"><?php esc_html_e( 'Public verification for this card has been disabled by an administrator.', 'bsx-memberhub' ); ?></div><?php endif; ?>
        </div>
        <?php return (string) ob_get_clean();
    }

    public static function render_verification(): string {
        wp_enqueue_style( 'bsxmh-public' );
        if ( ! self::verification_enabled() ) return self::verification_result( 'unavailable', __( 'Membership verification is currently unavailable.', 'bsx-memberhub' ) );
        $token = sanitize_text_field( wp_unslash( $_GET['bsxmh_verify'] ?? '' ) );
        $code = strtoupper( sanitize_text_field( wp_unslash( $_GET['bsxmh_code'] ?? '' ) ) );
        if ( ! $token && ! $code ) {
            ob_start(); ?><div class="bsxmh-verify-shell"><h2><?php esc_html_e( 'Verify Membership', 'bsx-memberhub' ); ?></h2><p><?php esc_html_e( 'Scan a MemberHub card QR code or enter its verification code.', 'bsx-memberhub' ); ?></p><form method="get"><label><?php esc_html_e( 'Verification Code', 'bsx-memberhub' ); ?><input name="bsxmh_code" placeholder="MH-XXXX-XXXX" required></label><button type="submit"><?php esc_html_e( 'Verify', 'bsx-memberhub' ); ?></button></form></div><?php return (string) ob_get_clean();
        }
        global $wpdb;
        $members = $wpdb->get_results( "SELECT * FROM " . BSXMH_DB::table( 'members' ) . " WHERE status <> 'deleted'" );
        $found = null;
        foreach ( (array) $members as $member ) {
            if ( $token && hash_equals( self::token( $member, false ), $token ) ) { $found = $member; break; }
            if ( $code && hash_equals( self::manual_code( $member ), $code ) ) { $found = $member; break; }
        }
        if ( ! $found ) return self::verification_result( 'invalid', __( 'Membership could not be verified.', 'bsx-memberhub' ) );
        $profile = self::profile( $found );
        if ( ! empty( $profile['card_verification_disabled'] ) ) return self::verification_result( 'invalid', __( 'This membership card is no longer valid for public verification.', 'bsx-memberhub' ) );
        $user = get_userdata( (int) $found->user_id );
        if ( ! $user ) return self::verification_result( 'invalid', __( 'Membership could not be verified.', 'bsx-memberhub' ) );
        $s = get_option( 'bsxmh_settings', array() );
        $status = sanitize_key( (string) $found->status );
        $message = 'active' === $status ? __( 'Membership Verified', 'bsx-memberhub' ) : __( 'Membership Record Found', 'bsx-memberhub' );
        ob_start(); ?>
        <div class="bsxmh-verify-shell is-<?php echo esc_attr( $status ); ?>">
            <?php if ( ! empty( $s['organization_logo_url'] ) ) : ?><img class="bsxmh-verify-logo" src="<?php echo esc_url( $s['organization_logo_url'] ); ?>" alt=""><?php endif; ?>
            <div class="bsxmh-verify-icon"><?php echo 'active' === $status ? '✓' : '!'; ?></div><h2><?php echo esc_html( $message ); ?></h2><p class="bsxmh-verify-status"><?php echo esc_html( ucfirst( $status ) ); ?></p>
            <div class="bsxmh-verify-member"><?php if ( ! empty( $s['verification_show_photo'] ) ) echo wp_kses_post( BSXMH_Members::profile_photo_html( $found, 96, 'bsxmh-verify-photo' ) ); ?><div><strong><?php echo esc_html( $user->display_name ); ?></strong><span><?php echo esc_html( $found->member_number ); ?></span><?php if ( ! empty( $s['verification_show_member_since'] ) && ! empty( $found->join_date ) ) : ?><small><?php echo esc_html( sprintf( __( 'Member since %s', 'bsx-memberhub' ), date_i18n( get_option( 'date_format' ), strtotime( $found->join_date ) ) ) ); ?></small><?php endif; ?></div></div>
            <p class="bsxmh-verify-time"><?php echo esc_html( sprintf( __( 'Verified on %s', 'bsx-memberhub' ), current_time( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) ); ?></p>
        </div><?php return (string) ob_get_clean();
    }

    private static function verification_result( string $type, string $message ): string {
        return '<div class="bsxmh-verify-shell is-' . esc_attr( $type ) . '"><div class="bsxmh-verify-icon">×</div><h2>' . esc_html( $message ) . '</h2></div>';
    }

    public static function regenerate_token(): void {
        if ( ! current_user_can( 'bsxmh_manage_members' ) ) wp_die( esc_html__( 'Permission denied.', 'bsx-memberhub' ) );
        check_admin_referer( 'bsxmh_regenerate_card_token' );
        $member = BSXMH_Members::get( absint( $_GET['member_id'] ?? 0 ) );
        if ( $member ) { $p = self::profile( $member ); $p['card_verification_token'] = bin2hex( random_bytes( 24 ) ); self::save_profile( $member, $p ); }
        wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=bsxmh-members' ) ); exit;
    }

    public static function toggle_verification(): void {
        if ( ! current_user_can( 'bsxmh_manage_members' ) ) wp_die( esc_html__( 'Permission denied.', 'bsx-memberhub' ) );
        check_admin_referer( 'bsxmh_toggle_card_verification' );
        $member = BSXMH_Members::get( absint( $_GET['member_id'] ?? 0 ) );
        if ( $member ) { $p = self::profile( $member ); $p['card_verification_disabled'] = empty( $p['card_verification_disabled'] ) ? 1 : 0; self::save_profile( $member, $p ); }
        wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=bsxmh-members' ) ); exit;
    }
}
