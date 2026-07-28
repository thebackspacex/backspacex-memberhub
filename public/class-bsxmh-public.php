<?php

defined( 'ABSPATH' ) || exit;

final class BSXMH_Public {
    public function register(): void {
        add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
        add_shortcode( 'bsxmh_dashboard', array( $this, 'member_dashboard' ) );
        add_shortcode( 'bsxmh_collection_summary', array( $this, 'collection_summary' ) );
        add_shortcode( 'bsxmh_register', array( $this, 'registration_form' ) );
        add_shortcode( 'bsxmh_payment', array( $this, 'coming_soon' ) );
        add_shortcode( 'bsxmh_event_list', array( $this, 'event_list' ) );
        add_shortcode( 'bsxmh_event', array( $this, 'single_event' ) );
        add_shortcode( 'bsxmh_finance_summary', array( $this, 'collection_summary' ) );
    }

    public function register_assets(): void {
        wp_register_style( 'bsxmh-public', BSXMH_URL . 'assets/css/public.css', array(), BSXMH_VERSION );
        $settings = get_option( 'bsxmh_settings', array() );
        $primary = sanitize_hex_color( $settings['portal_primary_color'] ?? '#183153' ) ?: '#183153';
        $secondary = sanitize_hex_color( $settings['portal_secondary_color'] ?? '#2563eb' ) ?: '#2563eb';
        wp_add_inline_style( 'bsxmh-public', ':root{--bsxmh-primary:' . $primary . ';--bsxmh-secondary:' . $secondary . ';}' );
        wp_register_script( 'bsxmh-public', BSXMH_URL . 'assets/js/public.js', array(), BSXMH_VERSION, true );
        wp_register_script( 'bsxmh-qrcode', BSXMH_URL . 'assets/js/qrcode-model.js', array(), BSXMH_VERSION, true );
        wp_register_script( 'bsxmh-card', BSXMH_URL . 'assets/js/membership-card.js', array( 'bsxmh-qrcode' ), BSXMH_VERSION, true );

        // Load the shared design system early on every MemberHub frontend page.
        // This prevents unstyled shortcode output in themes/page builders that
        // print styles before shortcode callbacks execute.
        if ( $this->is_memberhub_page() ) {
            wp_enqueue_style( 'bsxmh-public' );
            wp_enqueue_script( 'bsxmh-public' );
        }
    }

    private function is_memberhub_page(): bool {
        if ( is_admin() || ! is_singular() ) {
            return false;
        }

        $settings = get_option( 'bsxmh_settings', array() );
        $settings = is_array( $settings ) ? $settings : array();
        $page_keys = array(
            'dashboard_page_id', 'payment_page_id', 'contribution_page_id',
            'events_page_id', 'profile_page_id', 'login_page_id',
            'registration_page_id', 'transparency_page_id', 'card_page_id',
            'finance_page_id', 'notifications_page_id', 'directory_page_id',
        );
        $current_id = get_queried_object_id();
        foreach ( $page_keys as $key ) {
            if ( $current_id && $current_id === absint( $settings[ $key ] ?? 0 ) ) {
                return true;
            }
        }

        $post = get_queried_object();
        if ( ! $post instanceof WP_Post ) {
            return false;
        }

        $shortcodes = array(
            'bsxmh_dashboard', 'bsxmh_register', 'bsxmh_payment',
            'bsxmh_event_list', 'bsxmh_event', 'bsxmh_collection_summary',
            'bsxmh_finance_summary', 'bsxmh_member_card',
            'bsxmh_member_finance', 'bsxmh_notifications',
            'bsxmh_member_directory', 'bsxmh_transparency',
        );
        foreach ( $shortcodes as $shortcode ) {
            if ( has_shortcode( (string) $post->post_content, $shortcode ) ) {
                return true;
            }
        }
        return false;
    }

    public function registration_form(): string {
        wp_enqueue_style( 'bsxmh-public' );
        wp_enqueue_script( 'bsxmh-public' );
        $settings = get_option( 'bsxmh_settings', array() );
        if ( empty( $settings['registration_enabled'] ) ) {
            return '<div class="bsxmh-notice">' . esc_html__( 'Member registration is currently closed.', 'bsx-memberhub' ) . '</div>';
        }
        if ( is_user_logged_in() ) {
            return '<div class="bsxmh-notice">' . esc_html__( 'You are already logged in.', 'bsx-memberhub' ) . '</div>';
        }
        $message = '';
        if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['bsxmh_front_register'] ) ) {
            if ( ! isset( $_POST['bsxmh_register_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bsxmh_register_nonce'] ) ), 'bsxmh_front_register' ) ) {
                $message = '<div class="bsxmh-notice bsxmh-error">' . esc_html__( 'Security verification failed. Please try again.', 'bsx-memberhub' ) . '</div>';
            } else {
                $status = ! empty( $settings['registration_requires_approval'] ) ? 'pending' : 'active';
                $result = BSXMH_Members::create( array(
                    'display_name' => sanitize_text_field( wp_unslash( $_POST['display_name'] ?? '' ) ),
                    'email' => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
                    'phone' => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
                    'password' => isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '',
                    'status' => $status,
                    'join_date' => current_time( 'Y-m-d' ),
                    'fee_start_date' => current_time( 'Y-m-d' ),
                    'monthly_fee' => $settings['default_monthly_fee'] ?? '100.00',
                ) );
                if ( is_wp_error( $result ) ) {
                    $message = '<div class="bsxmh-notice bsxmh-error">' . esc_html( $result->get_error_message() ) . '</div>';
                } else {
                    $custom_result = BSXMH_Form_Builder::validate_and_save( (int) $result, $_POST, 'registration' );
                    if ( is_wp_error( $custom_result ) ) {
                        global $wpdb; $wpdb->delete( BSXMH_DB::table( 'members' ), array( 'user_id' => (int) $result ), array( '%d' ) );
                        require_once ABSPATH . 'wp-admin/includes/user.php';
                        wp_delete_user( (int) $result );
                        $message = '<div class="bsxmh-notice bsxmh-error">' . esc_html( implode( ' ', $custom_result->get_error_messages() ) ) . '</div>';
                    } else {
                    do_action( 'bsxmh_member_registered', (int) $result );
                    if ( 'active' === $status ) do_action( 'bsxmh_member_approved', (int) $result );
                    $login_url = BSXMH_Portal::page_url( 'login_page_id', '/member-login/' );
                    $message = '<div class="bsxmh-notice bsxmh-success">' . esc_html( 'pending' === $status ? __( 'Registration completed. Your account is waiting for administrator approval.', 'bsx-memberhub' ) : __( 'Registration completed successfully. You may now log in.', 'bsx-memberhub' ) ) . '</div>';
                    $message .= '<p><a class="bsxmh-secondary-button" href="' . esc_url( $login_url ) . '">' . esc_html__( 'Go to Member Login', 'bsx-memberhub' ) . '</a></p>';
                    return $message;
                    }
                }
            }
        }
        $core_fields = BSXMH_Form_Builder::core_settings();
        ob_start();
        echo $message;
        ?>
        <div class="bsxmh-auth-shell">
            <div class="bsxmh-auth-header">
                <?php if ( ! empty( $settings['organization_logo_url'] ) ) : ?><img class="bsxmh-auth-logo" src="<?php echo esc_url( $settings['organization_logo_url'] ); ?>" alt="<?php echo esc_attr( $settings['organization_name'] ?? get_bloginfo( 'name' ) ); ?>"><?php endif; ?>
                <?php if ( ! empty( $settings['registration_description'] ) ) : ?><p><?php echo esc_html( $settings['registration_description'] ); ?></p><?php endif; ?>
                <p class="bsxmh-required-note"><span aria-hidden="true">*</span> <?php esc_html_e( 'Required fields', 'bsx-memberhub' ); ?></p>
            </div>
            <form method="post" enctype="multipart/form-data" class="bsxmh-form bsxmh-registration-form">
                <?php wp_nonce_field( 'bsxmh_front_register', 'bsxmh_register_nonce' ); ?><input type="hidden" name="bsxmh_front_register" value="1">
                <div><label for="bsxmh-name"><?php esc_html_e( 'Full Name', 'bsx-memberhub' ); ?> <span class="bsxmh-required" aria-hidden="true">*</span></label><input id="bsxmh-name" name="display_name" type="text" autocomplete="name" required></div>
                <div><label for="bsxmh-email"><?php esc_html_e( 'Email Address', 'bsx-memberhub' ); ?> <span class="bsxmh-required" aria-hidden="true">*</span></label><input id="bsxmh-email" name="email" type="email" autocomplete="email" required></div>
                <?php if ( ! empty( $core_fields['phone_enabled'] ) ) : ?><div><label for="bsxmh-phone"><?php echo esc_html( $core_fields['phone_label'] ); ?><?php if ( ! empty( $core_fields['phone_required'] ) ) : ?> <span class="bsxmh-required" aria-hidden="true">*</span><?php endif; ?></label><input id="bsxmh-phone" name="phone" type="text" autocomplete="tel" <?php echo ! empty( $core_fields['phone_required'] ) ? 'required' : ''; ?>></div><?php endif; ?>
                <?php echo BSXMH_Form_Builder::render_fields( 'registration' ); ?>
                <div><label for="bsxmh-password"><?php esc_html_e( 'Password', 'bsx-memberhub' ); ?> <span class="bsxmh-required" aria-hidden="true">*</span></label><div class="bsxmh-password-wrap"><input id="bsxmh-password" name="password" type="password" minlength="8" autocomplete="new-password" required data-bsxmh-password><button class="bsxmh-password-toggle" type="button" data-bsxmh-toggle-password="bsxmh-password" aria-label="<?php esc_attr_e( 'Show password', 'bsx-memberhub' ); ?>"><?php esc_html_e( 'Show', 'bsx-memberhub' ); ?></button></div><div class="bsxmh-password-strength" data-bsxmh-strength aria-live="polite"><?php esc_html_e( 'Use at least 8 characters.', 'bsx-memberhub' ); ?></div></div>
                <button type="submit"><?php esc_html_e( 'Register as Member', 'bsx-memberhub' ); ?></button>
                <div class="bsxmh-auth-switch"><span><?php esc_html_e( 'Already have an account?', 'bsx-memberhub' ); ?></span> <a class="bsxmh-secondary-button" href="<?php echo esc_url( BSXMH_Portal::page_url( 'login_page_id', '/member-login/' ) ); ?>"><?php esc_html_e( 'Member Login', 'bsx-memberhub' ); ?></a></div>
            </form>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public function member_dashboard(): string {
        wp_enqueue_style( 'bsxmh-public' );
        if ( ! is_user_logged_in() ) {
            return '<div class="bsxmh-notice">' . esc_html__( 'Please log in to view your member dashboard.', 'bsx-memberhub' ) . ' <a href="' . esc_url( BSXMH_Portal::page_url( 'login_page_id', '/member-login/' ) ) . '">' . esc_html__( 'Member Login', 'bsx-memberhub' ) . '</a></div>';
        }

        $user   = wp_get_current_user();
        $member = BSXMH_Members::get_by_user( (int) $user->ID );
        if ( ! $member ) {
            return '<div class="bsxmh-notice">' . esc_html__( 'No MemberHub profile is linked to this account.', 'bsx-memberhub' ) . '</div>';
        }

        if ( 'active' !== $member->status ) {
            $messages = array(
                'pending'   => __( 'Your membership is waiting for administrator approval.', 'bsx-memberhub' ),
                'inactive'  => __( 'Your membership is inactive. Please contact the organization.', 'bsx-memberhub' ),
                'suspended' => __( 'Your membership has been suspended. Please contact the organization.', 'bsx-memberhub' ),
            );
            return BSXMH_Portal::wrap_member_page( '<div class="bsxmh-notice bsxmh-error">' . esc_html( $messages[ $member->status ] ?? __( 'Your account is not active.', 'bsx-memberhub' ) ) . '</div>', 'overview' );
        }

        $statement         = BSXMH_Payments::statement( $member );
        $sym               = BSXMH_Payments::currency_symbol();
        $extra_total       = BSXMH_Contributions::member_total( (int) $user->ID );
        $event_total       = BSXMH_Events::member_total( (int) $user->ID );
        $grand_total       = (float) $statement['total_paid'] + $extra_total + $event_total;
        $payment_page      = BSXMH_Portal::page_url( 'payment_page_id', '/member-payment/' );
        $contribution_page = BSXMH_Portal::page_url( 'contribution_page_id', '/member-contribution/' );
        $events_page       = BSXMH_Portal::page_url( 'events_page_id', '/member-events/' );
        $profile_page      = BSXMH_Portal::page_url( 'profile_page_id', '/member-profile/' );
        $card_page         = BSXMH_Portal::page_url( 'membership_card_page_id', '/membership-card/' );
        $finance_page      = BSXMH_Portal::page_url( 'finance_page_id', '/member-finance/' );
        $member_tags       = BSXMH_Members::tags( $member );
        $next_due          = ! empty( $statement['due'] ) ? reset( $statement['due'] ) : null;
        $next_due_label    = $next_due ? BSXMH_Payments::month_label( (int) $next_due['year'], (int) $next_due['month'] ) : __( 'No dues', 'bsx-memberhub' );
        $next_due_key      = $next_due ? sprintf( '%04d-%02d', (int) $next_due['year'], (int) $next_due['month'] ) : '';
        $settings          = get_option( 'bsxmh_settings', array() );
        $organization      = (string) ( $settings['organization_name'] ?? get_bloginfo( 'name' ) );
        $hour              = (int) current_time( 'G' );
        $greeting          = $hour < 12 ? __( 'Good morning', 'bsx-memberhub' ) : ( $hour < 18 ? __( 'Good afternoon', 'bsx-memberhub' ) : __( 'Good evening', 'bsx-memberhub' ) );

        global $wpdb;
        $history = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . BSXMH_DB::table( 'payments' ) . " WHERE user_id=%d AND status='paid' ORDER BY payment_date DESC,id DESC LIMIT 8",
                $user->ID
            )
        );
        $events_joined = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT i.reference_id) FROM " . BSXMH_DB::table( 'payment_items' ) . " i INNER JOIN " . BSXMH_DB::table( 'payments' ) . " p ON p.id=i.payment_id WHERE p.user_id=%d AND p.status='paid' AND i.item_type='event_donation'",
                $user->ID
            )
        );

        ob_start(); ?>
        <div class="bsxmh-member-home">
            <section class="bsxmh-member-hero">
                <div class="bsxmh-member-identity">
                    <?php echo wp_kses_post( BSXMH_Members::profile_photo_html( $member, 104, 'bsxmh-dashboard-avatar' ) ); ?>
                    <div>
                        <p class="bsxmh-eyebrow"><?php echo esc_html( $greeting ); ?></p>
                        <h2><?php echo esc_html( $user->display_name ); ?></h2>
                        <p><?php echo esc_html( $organization ); ?></p>
                        <?php if ( ! empty( $member_tags ) ) : ?>
                            <div class="bsxmh-member-tags bsxmh-member-tags-public" aria-label="<?php esc_attr_e( 'Member tags', 'bsx-memberhub' ); ?>">
                                <?php foreach ( $member_tags as $member_tag ) : ?><span><?php echo esc_html( $member_tag ); ?></span><?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="bsxmh-member-hero-meta">
                    <span class="bsxmh-status-pill is-active"><?php esc_html_e( 'Active Member', 'bsx-memberhub' ); ?></span>
                    <small><?php esc_html_e( 'Member ID', 'bsx-memberhub' ); ?></small>
                    <strong><?php echo esc_html( $member->member_number ); ?></strong>
                    <?php if ( ! empty( $member->join_date ) ) : ?><small><?php echo esc_html( sprintf( __( 'Member since %s', 'bsx-memberhub' ), date_i18n( get_option( 'date_format' ), strtotime( $member->join_date ) ) ) ); ?></small><?php endif; ?>
                </div>
            </section>

            <?php echo wp_kses_post( BSXMH_Profile_Completion::render_card( (int) $user->ID, $member, false ) ); ?>

            <section class="bsxmh-home-stats" aria-label="<?php esc_attr_e( 'Membership summary', 'bsx-memberhub' ); ?>">
                <article><span><?php esc_html_e( 'Total Due', 'bsx-memberhub' ); ?></span><strong><?php echo esc_html( $sym . number_format_i18n( (float) $statement['total_due'], 2 ) ); ?></strong><small><?php echo esc_html( $next_due_label ); ?></small></article>
                <article><span><?php esc_html_e( 'Membership Paid', 'bsx-memberhub' ); ?></span><strong><?php echo esc_html( $sym . number_format_i18n( (float) $statement['total_paid'], 2 ) ); ?></strong><small><?php echo esc_html( sprintf( _n( '%s paid month', '%s paid months', count( $statement['paid'] ), 'bsx-memberhub' ), number_format_i18n( count( $statement['paid'] ) ) ) ); ?></small></article>
                <article><span><?php esc_html_e( 'Contributions', 'bsx-memberhub' ); ?></span><strong><?php echo esc_html( $sym . number_format_i18n( $extra_total + $event_total, 2 ) ); ?></strong><small><?php esc_html_e( 'Extra and event giving', 'bsx-memberhub' ); ?></small></article>
                <article><span><?php esc_html_e( 'Grand Total', 'bsx-memberhub' ); ?></span><strong><?php echo esc_html( $sym . number_format_i18n( $grand_total, 2 ) ); ?></strong><small><?php echo esc_html( sprintf( _n( '%s event supported', '%s events supported', $events_joined, 'bsx-memberhub' ), number_format_i18n( $events_joined ) ) ); ?></small></article>
            </section>

            <div class="bsxmh-home-grid">
                <section class="bsxmh-home-panel bsxmh-next-due-panel">
                    <div class="bsxmh-panel-heading"><div><p class="bsxmh-eyebrow"><?php esc_html_e( 'Next payment', 'bsx-memberhub' ); ?></p><h3><?php echo esc_html( $next_due_label ); ?></h3></div><strong><?php echo esc_html( $next_due ? $sym . number_format_i18n( (float) $member->monthly_fee, 2 ) : '✓' ); ?></strong></div>
                    <?php if ( $next_due ) : ?>
                        <p><?php esc_html_e( 'Keep your membership up to date with a quick online payment.', 'bsx-memberhub' ); ?></p>
                        <div class="bsxmh-panel-actions"><a class="bsxmh-action-primary" href="<?php echo esc_url( add_query_arg( 'bsxmh_pay_month', $next_due_key, $payment_page ) ); ?>"><?php esc_html_e( 'Pay Now', 'bsx-memberhub' ); ?></a><?php if ( count( $statement['due'] ) > 1 ) : ?><a class="bsxmh-action-secondary" href="<?php echo esc_url( add_query_arg( 'bsxmh_pay_all_due', '1', $payment_page ) ); ?>"><?php esc_html_e( 'Pay All Due', 'bsx-memberhub' ); ?></a><?php endif; ?></div>
                    <?php else : ?><p><?php esc_html_e( 'You are fully up to date. Thank you!', 'bsx-memberhub' ); ?></p><?php endif; ?>
                </section>

                <section class="bsxmh-home-panel">
                    <div class="bsxmh-panel-heading"><div><p class="bsxmh-eyebrow"><?php esc_html_e( 'Shortcuts', 'bsx-memberhub' ); ?></p><h3><?php esc_html_e( 'Quick Actions', 'bsx-memberhub' ); ?></h3></div></div>
                    <div class="bsxmh-quick-actions">
                        <a href="<?php echo esc_url( $payment_page ); ?>"><span aria-hidden="true">৳</span><strong><?php esc_html_e( 'Pay Fees', 'bsx-memberhub' ); ?></strong></a>
                        <a href="<?php echo esc_url( $contribution_page ); ?>"><span aria-hidden="true">♥</span><strong><?php esc_html_e( 'Contribute', 'bsx-memberhub' ); ?></strong></a>
                        <a href="<?php echo esc_url( $events_page ); ?>"><span aria-hidden="true">◆</span><strong><?php esc_html_e( 'Events', 'bsx-memberhub' ); ?></strong></a>
                        <a href="<?php echo esc_url( $card_page ); ?>"><span>▣</span><strong><?php esc_html_e( 'My Card', 'bsx-memberhub' ); ?></strong><small><?php esc_html_e( 'Digital membership ID', 'bsx-memberhub' ); ?></small></a>
                        <a href="<?php echo esc_url( $finance_page ); ?>"><span>▤</span><strong><?php esc_html_e( 'My Finance', 'bsx-memberhub' ); ?></strong><small><?php esc_html_e( 'Transactions and receipts', 'bsx-memberhub' ); ?></small></a>
                        <a href="<?php echo esc_url( $notifications_page ); ?>"><span>●</span><strong><?php esc_html_e( 'Notifications', 'bsx-memberhub' ); ?></strong><small><?php echo esc_html( sprintf( __( '%d unread', 'bsx-memberhub' ), BSXMH_Notifications::unread_count() ) ); ?></small></a>
                        <a href="<?php echo esc_url( $profile_page ); ?>"><span aria-hidden="true">●</span><strong><?php esc_html_e( 'My Profile', 'bsx-memberhub' ); ?></strong></a>
                    </div>
                </section>
            </div>

            <section class="bsxmh-home-panel bsxmh-activity-panel">
                <div class="bsxmh-panel-heading"><div><p class="bsxmh-eyebrow"><?php esc_html_e( 'Your account', 'bsx-memberhub' ); ?></p><h3><?php esc_html_e( 'Recent Activity', 'bsx-memberhub' ); ?></h3></div><a href="<?php echo esc_url( $payment_page ); ?>"><?php esc_html_e( 'View payments', 'bsx-memberhub' ); ?></a></div>
                <div class="bsxmh-activity-list">
                    <?php if ( ! $history ) : ?>
                        <div class="bsxmh-empty-state"><strong><?php esc_html_e( 'No activity yet', 'bsx-memberhub' ); ?></strong><p><?php esc_html_e( 'Your successful payments and contributions will appear here.', 'bsx-memberhub' ); ?></p></div>
                    <?php else : foreach ( $history as $payment ) :
                        $items   = BSXMH_Payments::items( (int) $payment->id );
                        $receipt = BSXMH_Receipts::get_by_payment( (int) $payment->id );
                        $details = implode( ', ', array_map( static fn( $item ) => (string) $item->description, $items ) );
                        ?>
                        <article class="bsxmh-activity-item">
                            <span class="bsxmh-activity-icon" aria-hidden="true">✓</span>
                            <div><strong><?php echo esc_html( ucwords( str_replace( '_', ' ', $payment->payment_type ) ) ); ?></strong><small><?php echo esc_html( $details ?: __( 'Payment received', 'bsx-memberhub' ) ); ?></small><time><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( (string) $payment->payment_date ) ) ); ?></time></div>
                            <div class="bsxmh-activity-amount"><strong><?php echo esc_html( $sym . number_format_i18n( (float) $payment->total_amount, 2 ) ); ?></strong><?php if ( $receipt ) : ?><a target="_blank" rel="noopener" href="<?php echo esc_url( BSXMH_Receipts::public_url( $receipt ) ); ?>"><?php esc_html_e( 'Receipt', 'bsx-memberhub' ); ?></a><?php endif; ?></div>
                        </article>
                    <?php endforeach; endif; ?>
                </div>
            </section>

            <?php echo BSXMH_Portal::announcements(); ?>
        </div>
        <?php
        return BSXMH_Portal::wrap_member_page( (string) ob_get_clean(), 'overview' );
    }

    public function collection_summary(): string {
        wp_enqueue_style( 'bsxmh-public' );
        $settings = get_option( 'bsxmh_settings', array() );
        $visibility = $settings['public_dashboard_visibility'] ?? 'public';
        if ( 'hidden' === $visibility || ( 'members' === $visibility && ! is_user_logged_in() ) || ( 'admin' === $visibility && ! current_user_can( 'manage_bsxmh' ) ) ) {
            return '';
        }
        global $wpdb;
        $income = (float) $wpdb->get_var( "SELECT COALESCE(SUM(total_amount),0) FROM " . BSXMH_DB::table( 'payments' ) . " WHERE status='paid'" );
        $membership = (float) $wpdb->get_var( "SELECT COALESCE(SUM(total_amount),0) FROM " . BSXMH_DB::table( 'payments' ) . " WHERE status='paid' AND payment_type='membership'" );
        $extra = (float) $wpdb->get_var( "SELECT COALESCE(SUM(total_amount),0) FROM " . BSXMH_DB::table( 'payments' ) . " WHERE status='paid' AND payment_type='extra_contribution'" );
        $event_total = (float) $wpdb->get_var( "SELECT COALESCE(SUM(total_amount),0) FROM " . BSXMH_DB::table( 'payments' ) . " WHERE status='paid' AND payment_type='event_donation'" );
        $expense = (float) $wpdb->get_var( "SELECT COALESCE(SUM(amount),0) FROM " . BSXMH_DB::table( 'expenses' ) . " WHERE status='paid'" );
        $members = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . BSXMH_DB::table( 'members' ) . " WHERE status='active'" );
        $html = '<div class="bsxmh-summary"><div><span>Membership collection</span><strong>' . esc_html( BSXMH_Payments::currency_symbol() . number_format_i18n( $membership, 2 ) ) . '</strong></div><div><span>Extra contribution</span><strong>' . esc_html( BSXMH_Payments::currency_symbol() . number_format_i18n( $extra, 2 ) ) . '</strong></div><div><span>Event collection</span><strong>' . esc_html( BSXMH_Payments::currency_symbol() . number_format_i18n( $event_total, 2 ) ) . '</strong></div><div><span>Total collected</span><strong>' . esc_html( BSXMH_Payments::currency_symbol() . number_format_i18n( $income, 2 ) ) . '</strong></div><div><span>Total expense</span><strong>' . esc_html( BSXMH_Payments::currency_symbol() . number_format_i18n( $expense, 2 ) ) . '</strong></div><div><span>Current balance</span><strong>' . esc_html( BSXMH_Payments::currency_symbol() . number_format_i18n( $income - $expense, 2 ) ) . '</strong></div><div><span>Active members</span><strong>' . esc_html( number_format_i18n( $members ) ) . '</strong></div></div>';
        $funds = BSXMH_Contributions::fund_summary();
        if ( $funds ) { $html .= '<div class="bsxmh-public-card"><h3>Fund Summary</h3><div class="bsxmh-summary">'; foreach ( $funds as $row ) { $f=$row['fund']; if ( 'hidden' === $f->visibility || ( 'members' === $f->visibility && ! is_user_logged_in() ) || ( 'admin' === $f->visibility && ! current_user_can( 'manage_bsxmh' ) ) ) continue; $html .= '<div><span>'.esc_html($f->name).'</span><strong>'.esc_html(BSXMH_Payments::currency_symbol().number_format_i18n($row['balance'],2)).'</strong></div>'; } $html .= '</div></div>'; }
        return $html;
    }


    public function event_list(): string {
        wp_enqueue_style( 'bsxmh-public' );

        // The auto-created Member Events page contains this list shortcode. When a
        // View Event link adds an event query parameter, render the single-event
        // view on the same page instead of showing the list again.
        if ( isset( $_GET['bsxmh_event'] ) || isset( $_GET['bsxmh_event_id'] ) ) {
            return $this->single_event();
        }

        $events = BSXMH_Events::all( true );
        if ( ! $events ) return BSXMH_Portal::wrap_member_page( '<div class="bsxmh-notice">' . esc_html__( 'No active fundraising events are available.', 'bsx-memberhub' ) . '</div>', 'events' );
        $html = '<section class="bsxmh-page-hero"><div class="bsxmh-page-icon" aria-hidden="true">◇</div><div><p class="bsxmh-eyebrow">Member Portal</p><h2>' . esc_html__( 'Events & Campaigns', 'bsx-memberhub' ) . '</h2><p>' . esc_html__( 'Discover active events, follow fundraising progress and participate online.', 'bsx-memberhub' ) . '</p></div></section><div class="bsxmh-event-grid">';
        foreach ( $events as $event ) {
            if ( in_array( $event->visibility, array( 'members', 'member' ), true ) && ! is_user_logged_in() ) continue;
            $stats = BSXMH_Events::stats( (int) $event->id );
            $image = $event->image_id ? wp_get_attachment_image_url( (int) $event->image_id, 'large' ) : '';
            $html .= '<article class="bsxmh-public-card bsxmh-event-card">';
            if ( $image ) $html .= '<img src="'.esc_url($image).'" alt="'.esc_attr($event->title).'">';
            $html .= '<h3>'.esc_html($event->title).'</h3><p>'.esc_html(wp_trim_words(wp_strip_all_tags($event->description),28)).'</p>';
            $html .= '<div class="bsxmh-progress"><span style="width:'.esc_attr((string)$stats['percent']).'%"></span></div>';
            $html .= '<p><strong>'.esc_html(BSXMH_Payments::currency_symbol().number_format_i18n($stats['collected'],2)).'</strong> of '.esc_html(BSXMH_Payments::currency_symbol().number_format_i18n($stats['target'],2)).' collected</p>';
            $events_page = BSXMH_Portal::page_url( 'events_page_id', '/member-events/' );
            $url = add_query_arg( 'bsxmh_event', rawurlencode( (string) $event->slug ), $events_page );
            $html .= '<a class="button" href="'.esc_url($url).'">View Event</a></article>';
        }
        return BSXMH_Portal::wrap_member_page( $html . '</div>', 'events' );
    }

    public function single_event( array $atts = array() ): string {
        wp_enqueue_style( 'bsxmh-public' );
        $atts = shortcode_atts( array( 'id'=>0, 'slug'=>'' ), $atts, 'bsxmh_event' );
        $query_slug = isset( $_GET['bsxmh_event'] ) ? sanitize_title( wp_unslash( $_GET['bsxmh_event'] ) ) : '';
        $query_id   = isset( $_GET['bsxmh_event_id'] ) ? absint( $_GET['bsxmh_event_id'] ) : 0;
        $slug       = $query_slug ?: sanitize_title( (string) $atts['slug'] );
        $event      = $slug ? BSXMH_Events::get_by_slug( $slug ) : BSXMH_Events::get( $query_id ?: absint( $atts['id'] ) );
        if ( ! $event ) return '<div class="bsxmh-notice">'.esc_html__('Event not found.','bsx-memberhub').'</div>';
        if ( 'active' !== $event->status || 'hidden' === $event->visibility || 'admin' === $event->visibility || ( in_array( $event->visibility, array( 'members', 'member' ), true ) && ! is_user_logged_in() ) ) return '';
        $stats=BSXMH_Events::stats((int)$event->id); $image=$event->image_id?wp_get_attachment_image_url((int)$event->image_id,'large'):'';
        $html='<div class="bsxmh-public-card bsxmh-single-event">'; if($image)$html.='<img src="'.esc_url($image).'" alt="'.esc_attr($event->title).'">';
        $html.='<h2>'.esc_html($event->title).'</h2><div>'.wp_kses_post(wpautop($event->description)).'</div><div class="bsxmh-progress"><span style="width:'.esc_attr((string)$stats['percent']).'%"></span></div>';
        $html.='<div class="bsxmh-summary"><div><span>Target</span><strong>'.esc_html(BSXMH_Payments::currency_symbol().number_format_i18n($stats['target'],2)).'</strong></div><div><span>Collected</span><strong>'.esc_html(BSXMH_Payments::currency_symbol().number_format_i18n($stats['collected'],2)).'</strong></div><div><span>Remaining</span><strong>'.esc_html(BSXMH_Payments::currency_symbol().number_format_i18n($stats['remaining'],2)).'</strong></div><div><span>Donations</span><strong>'.esc_html(number_format_i18n($stats['donors'])).'</strong></div></div>';
        $html .= do_shortcode( '[bsxmh_online_event_donation id="' . absint( $event->id ) . '"]' ); $html .= '</div>'; return BSXMH_Portal::wrap_member_page( $html, 'events' );
    }

    public function coming_soon(): string {
        wp_enqueue_style( 'bsxmh-public' );
        return '<div class="bsxmh-notice">' . esc_html__( 'This MemberHub module is being prepared.', 'bsx-memberhub' ) . '</div>';
    }
}
