<?php

defined( 'ABSPATH' ) || exit;

/**
 * Logged-in member directory with privacy-safe profile previews.
 */
final class BSXMH_Member_Directory {
    public static function register(): void {
        add_shortcode( 'bsxmh_member_directory', array( __CLASS__, 'shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 20 );
    }

    /**
     * Load the shared Member Portal assets before wp_head is printed.
     * Shortcode-only enqueueing can happen too late on some themes, which
     * causes the directory to render as unstyled HTML.
     */
    public static function enqueue_assets(): void {
        if ( is_admin() ) {
            return;
        }

        $should_load = false;
        $settings = get_option( 'bsxmh_settings', array() );
        $directory_page_id = absint( $settings['directory_page_id'] ?? 0 );

        if ( $directory_page_id && is_page( $directory_page_id ) ) {
            $should_load = true;
        }

        if ( ! $should_load && is_singular() ) {
            $post = get_queried_object();
            if ( $post instanceof WP_Post && has_shortcode( (string) $post->post_content, 'bsxmh_member_directory' ) ) {
                $should_load = true;
            }
        }

        if ( $should_load ) {
            self::enqueue_shared_assets();
        }
    }

    /** Ensure the public stylesheet is registered and enqueued. */
    private static function enqueue_shared_assets(): void {
        if ( ! wp_style_is( 'bsxmh-public', 'registered' ) ) {
            wp_register_style( 'bsxmh-public', BSXMH_URL . 'assets/css/public.css', array(), BSXMH_VERSION );
            $settings  = get_option( 'bsxmh_settings', array() );
            $primary   = sanitize_hex_color( $settings['portal_primary_color'] ?? '#183153' ) ?: '#183153';
            $secondary = sanitize_hex_color( $settings['portal_secondary_color'] ?? '#2563eb' ) ?: '#2563eb';
            wp_add_inline_style( 'bsxmh-public', ':root{--bsxmh-primary:' . $primary . ';--bsxmh-secondary:' . $secondary . ';}' );
        }

        wp_enqueue_style( 'bsxmh-public' );

        if ( ! wp_script_is( 'bsxmh-public', 'registered' ) ) {
            wp_register_script( 'bsxmh-public', BSXMH_URL . 'assets/js/public.js', array(), BSXMH_VERSION, true );
        }
        wp_enqueue_script( 'bsxmh-public' );
    }

    public static function enabled(): bool {
        $settings = get_option( 'bsxmh_settings', array() );
        return ! isset( $settings['member_directory_enabled'] ) || ! empty( $settings['member_directory_enabled'] );
    }

    public static function member_can_access(): bool {
        if ( ! is_user_logged_in() ) {
            return false;
        }
        $user = wp_get_current_user();
        return current_user_can( 'manage_bsxmh' ) || in_array( 'bsxmh_member', (array) $user->roles, true );
    }

    public static function shortcode(): string {
        // Fallback for page builders/themes that render the shortcode outside the normal query lifecycle.
        self::enqueue_shared_assets();
        if ( ! self::enabled() ) {
            return BSXMH_Portal::wrap_member_page(
                '<div class="bsxmh-empty-state"><span aria-hidden="true">◎</span><h3>' . esc_html__( 'Member Directory is unavailable', 'bsx-memberhub' ) . '</h3><p>' . esc_html__( 'The organization has temporarily disabled the member directory.', 'bsx-memberhub' ) . '</p></div>',
                'directory'
            );
        }
        if ( ! is_user_logged_in() ) {
            return '<div class="bsxmh-notice bsxmh-warning">' . esc_html__( 'Please log in to view the member directory.', 'bsx-memberhub' ) . ' <a href="' . esc_url( BSXMH_Portal::page_url( 'login_page_id', '/member-login/' ) ) . '">' . esc_html__( 'Member Login', 'bsx-memberhub' ) . '</a></div>';
        }
        if ( ! self::member_can_access() ) {
            return '<div class="bsxmh-notice bsxmh-error">' . esc_html__( 'You do not have permission to view this directory.', 'bsx-memberhub' ) . '</div>';
        }

        $profile_id = absint( $_GET['directory_member'] ?? 0 );
        if ( $profile_id ) {
            return BSXMH_Portal::wrap_member_page( self::profile_view( $profile_id ), 'directory' );
        }
        return BSXMH_Portal::wrap_member_page( self::directory_view(), 'directory' );
    }

    private static function settings(): array {
        return wp_parse_args( get_option( 'bsxmh_settings', array() ), array(
            'directory_show_photo' => 1,
            'directory_show_member_id' => 1,
            'directory_show_join_date' => 1,
            'directory_show_status' => 1,
            'directory_show_tags' => 1,
            'directory_allow_opt_out' => 1,
            'directory_members_per_page' => 12,
            'directory_default_sort' => 'name_asc',
        ) );
    }

    private static function directory_view(): string {
        global $wpdb;
        $settings = self::settings();
        $search = sanitize_text_field( wp_unslash( $_GET['directory_search'] ?? '' ) );
        $tag = sanitize_text_field( wp_unslash( $_GET['directory_tag'] ?? '' ) );
        $page = max( 1, absint( $_GET['directory_page'] ?? 1 ) );
        $per_page = max( 6, min( 48, absint( $settings['directory_members_per_page'] ) ) );
        $offset = ( $page - 1 ) * $per_page;

        $where = array( "m.status = 'active'", "u.ID IS NOT NULL" );
        $args = array();
        if ( $search !== '' ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where[] = '(u.display_name LIKE %s OR m.member_number LIKE %s OR m.profile_data LIKE %s)';
            array_push( $args, $like, $like, $like );
        }
        if ( $tag !== '' ) {
            $where[] = 'm.profile_data LIKE %s';
            $args[] = '%"' . $wpdb->esc_like( $tag ) . '"%';
        }
        if ( ! empty( $settings['directory_allow_opt_out'] ) ) {
            $where[] = "NOT EXISTS (SELECT 1 FROM {$wpdb->usermeta} um WHERE um.user_id=m.user_id AND um.meta_key='bsxmh_directory_hidden' AND um.meta_value='1')";
        }

        $order = 'u.display_name ASC';
        if ( 'newest' === $settings['directory_default_sort'] ) $order = 'm.join_date DESC, u.display_name ASC';
        if ( 'member_id' === $settings['directory_default_sort'] ) $order = 'm.member_number ASC';

        $base_sql = ' FROM ' . BSXMH_DB::table( 'members' ) . " m INNER JOIN {$wpdb->users} u ON u.ID=m.user_id WHERE " . implode( ' AND ', $where );
        $count_sql = 'SELECT COUNT(*)' . $base_sql;
        $count = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) : $wpdb->get_var( $count_sql ) );
        $query_sql = 'SELECT m.*, u.display_name' . $base_sql . " ORDER BY {$order} LIMIT %d OFFSET %d";
        $query_args = array_merge( $args, array( $per_page, $offset ) );
        $members = $wpdb->get_results( $wpdb->prepare( $query_sql, $query_args ) );
        $tags = self::available_tags();

        ob_start(); ?>
        <section class="bsxmh-page-hero bsxmh-directory-hero">
            <span class="bsxmh-page-icon" aria-hidden="true">◎</span>
            <div><h2><?php esc_html_e( 'Member Directory', 'bsx-memberhub' ); ?></h2><p><?php esc_html_e( 'Find and connect with active members of your organization.', 'bsx-memberhub' ); ?></p></div>
            <span class="bsxmh-directory-count"><?php echo esc_html( sprintf( _n( '%s member', '%s members', $count, 'bsx-memberhub' ), number_format_i18n( $count ) ) ); ?></span>
        </section>
        <form class="bsxmh-directory-filters" method="get">
            <label><span><?php esc_html_e( 'Search members', 'bsx-memberhub' ); ?></span><input type="search" name="directory_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Name or Member ID', 'bsx-memberhub' ); ?>"></label>
            <?php if ( $tags ) : ?><label><span><?php esc_html_e( 'Group or tag', 'bsx-memberhub' ); ?></span><select name="directory_tag"><option value=""><?php esc_html_e( 'All members', 'bsx-memberhub' ); ?></option><?php foreach ( $tags as $item ) : ?><option value="<?php echo esc_attr( $item ); ?>" <?php selected( $tag, $item ); ?>><?php echo esc_html( $item ); ?></option><?php endforeach; ?></select></label><?php endif; ?>
            <button type="submit"><?php esc_html_e( 'Search', 'bsx-memberhub' ); ?></button>
            <?php if ( $search || $tag ) : ?><a class="bsxmh-button-secondary" href="<?php echo esc_url( BSXMH_Portal::page_url( 'directory_page_id', '/member-directory/' ) ); ?>"><?php esc_html_e( 'Reset', 'bsx-memberhub' ); ?></a><?php endif; ?>
        </form>
        <?php if ( ! $members ) : ?>
            <div class="bsxmh-empty-state"><span aria-hidden="true">⌕</span><h3><?php esc_html_e( 'No members found', 'bsx-memberhub' ); ?></h3><p><?php esc_html_e( 'Try another name, Member ID, or group filter.', 'bsx-memberhub' ); ?></p></div>
        <?php else : ?>
            <div class="bsxmh-directory-grid">
                <?php foreach ( $members as $member ) echo self::member_card( $member, $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>
            <?php echo self::pagination( $page, (int) ceil( $count / $per_page ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        <?php endif;
        return (string) ob_get_clean();
    }

    private static function member_card( object $member, array $settings ): string {
        $profile = json_decode( (string) ( $member->profile_data ?? '' ), true );
        $profile = is_array( $profile ) ? $profile : array();
        $tags = BSXMH_Members::tags( $member );
        $url = add_query_arg( 'directory_member', (int) $member->id, BSXMH_Portal::page_url( 'directory_page_id', '/member-directory/' ) );
        $photo = ! empty( $settings['directory_show_photo'] ) ? BSXMH_Members::profile_photo_html( $member, 110, 'bsxmh-directory-photo' ) : '<span class="bsxmh-directory-avatar">' . esc_html( self::initials( $member->display_name ) ) . '</span>';
        $meta = array();
        if ( ! empty( $settings['directory_show_member_id'] ) ) $meta[] = '<code>' . esc_html( $member->member_number ) . '</code>';
        if ( ! empty( $settings['directory_show_join_date'] ) && $member->join_date ) $meta[] = esc_html( sprintf( __( 'Member since %s', 'bsx-memberhub' ), wp_date( get_option( 'date_format' ), strtotime( $member->join_date ) ) ) );
        $html = '<article class="bsxmh-directory-card"><div class="bsxmh-directory-photo-wrap">' . $photo;
        if ( ! empty( $settings['directory_show_status'] ) ) $html .= '<span class="bsxmh-directory-active">' . esc_html__( 'Active', 'bsx-memberhub' ) . '</span>';
        $html .= '</div><h3>' . esc_html( $member->display_name ) . '</h3>';
        if ( $meta ) $html .= '<p class="bsxmh-directory-meta">' . implode( '<span>·</span>', $meta ) . '</p>';
        if ( ! empty( $settings['directory_show_tags'] ) && $tags ) $html .= '<div class="bsxmh-directory-tags">' . implode( '', array_map( static fn( $v ) => '<span>' . esc_html( $v ) . '</span>', array_slice( $tags, 0, 3 ) ) ) . '</div>';
        return $html . '<a class="bsxmh-directory-profile-link" href="' . esc_url( $url ) . '">' . esc_html__( 'View Profile', 'bsx-memberhub' ) . ' <span aria-hidden="true">→</span></a></article>';
    }

    private static function profile_view( int $member_id ): string {
        $settings = self::settings();
        $member = BSXMH_Members::get( $member_id );
        if ( ! $member || 'active' !== $member->status || ! get_userdata( (int) $member->user_id ) ) return self::not_found();
        if ( ! empty( $settings['directory_allow_opt_out'] ) && '1' === get_user_meta( (int) $member->user_id, 'bsxmh_directory_hidden', true ) ) return self::not_found();
        $user = get_userdata( (int) $member->user_id );
        $tags = BSXMH_Members::tags( $member );
        $back = BSXMH_Portal::page_url( 'directory_page_id', '/member-directory/' );
        ob_start(); ?>
        <p><a class="bsxmh-directory-back" href="<?php echo esc_url( $back ); ?>">← <?php esc_html_e( 'Back to Directory', 'bsx-memberhub' ); ?></a></p>
        <article class="bsxmh-directory-profile">
            <header>
                <?php if ( ! empty( $settings['directory_show_photo'] ) ) echo BSXMH_Members::profile_photo_html( $member, 150, 'bsxmh-directory-profile-photo' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <div><span class="bsxmh-directory-profile-status"><?php esc_html_e( 'Active Member', 'bsx-memberhub' ); ?></span><h2><?php echo esc_html( $user->display_name ); ?></h2><?php if ( ! empty( $settings['directory_show_member_id'] ) ) : ?><code><?php echo esc_html( $member->member_number ); ?></code><?php endif; ?></div>
            </header>
            <div class="bsxmh-directory-profile-details">
                <?php if ( ! empty( $settings['directory_show_join_date'] ) && $member->join_date ) : ?><div><span><?php esc_html_e( 'Member Since', 'bsx-memberhub' ); ?></span><strong><?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $member->join_date ) ) ); ?></strong></div><?php endif; ?>
                <?php if ( ! empty( $settings['directory_show_status'] ) ) : ?><div><span><?php esc_html_e( 'Membership Status', 'bsx-memberhub' ); ?></span><strong><?php esc_html_e( 'Active', 'bsx-memberhub' ); ?></strong></div><?php endif; ?>
            </div>
            <?php if ( ! empty( $settings['directory_show_tags'] ) && $tags ) : ?><section><h3><?php esc_html_e( 'Groups & Roles', 'bsx-memberhub' ); ?></h3><div class="bsxmh-directory-tags is-large"><?php foreach ( $tags as $item ) : ?><span><?php echo esc_html( $item ); ?></span><?php endforeach; ?></div></section><?php endif; ?>
            <p class="bsxmh-directory-privacy-note"><?php esc_html_e( 'Only organization-approved directory information is displayed. Contact and financial details remain private.', 'bsx-memberhub' ); ?></p>
        </article>
        <?php return (string) ob_get_clean();
    }

    private static function not_found(): string {
        return '<div class="bsxmh-empty-state"><span aria-hidden="true">!</span><h3>' . esc_html__( 'Member profile unavailable', 'bsx-memberhub' ) . '</h3><p>' . esc_html__( 'This member is not currently visible in the directory.', 'bsx-memberhub' ) . '</p><a class="bsxmh-button-secondary" href="' . esc_url( BSXMH_Portal::page_url( 'directory_page_id', '/member-directory/' ) ) . '">' . esc_html__( 'Return to Directory', 'bsx-memberhub' ) . '</a></div>';
    }

    private static function available_tags(): array {
        global $wpdb;
        $rows = $wpdb->get_col( "SELECT profile_data FROM " . BSXMH_DB::table( 'members' ) . " WHERE status='active'" );
        $tags = array();
        foreach ( $rows as $json ) {
            $profile = json_decode( (string) $json, true );
            foreach ( (array) ( $profile['tags'] ?? array() ) as $tag ) if ( is_string( $tag ) && trim( $tag ) !== '' ) $tags[] = trim( $tag );
        }
        $tags = array_values( array_unique( $tags ) );
        natcasesort( $tags );
        return array_values( $tags );
    }

    private static function pagination( int $current, int $total ): string {
        if ( $total <= 1 ) return '';
        $html = '<nav class="bsxmh-directory-pagination" aria-label="' . esc_attr__( 'Directory pages', 'bsx-memberhub' ) . '">';
        for ( $i = 1; $i <= $total; $i++ ) {
            if ( $i > 2 && $i < $current - 2 ) { if ( $i === 3 ) $html .= '<span>…</span>'; continue; }
            if ( $i < $total - 1 && $i > $current + 2 ) { if ( $i === $total - 2 ) $html .= '<span>…</span>'; continue; }
            $url = add_query_arg( 'directory_page', $i );
            $html .= '<a ' . ( $i === $current ? 'class="is-current" aria-current="page"' : '' ) . ' href="' . esc_url( $url ) . '">' . number_format_i18n( $i ) . '</a>';
        }
        return $html . '</nav>';
    }

    private static function initials( string $name ): string {
        $parts = preg_split( '/\s+/u', trim( $name ) );
        $letters = '';
        foreach ( array_slice( array_filter( (array) $parts ), 0, 2 ) as $part ) $letters .= function_exists( 'mb_substr' ) ? mb_substr( $part, 0, 1 ) : substr( $part, 0, 1 );
        return strtoupper( $letters ?: 'M' );
    }
}
