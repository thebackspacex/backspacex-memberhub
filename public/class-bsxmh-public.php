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
    }

    public function registration_form(): string {
        wp_enqueue_style( 'bsxmh-public' );
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
                    $message = '<div class="bsxmh-notice bsxmh-success">' . esc_html( 'pending' === $status ? __( 'Registration completed. Your account is waiting for administrator approval.', 'bsx-memberhub' ) : __( 'Registration completed successfully. You may now log in.', 'bsx-memberhub' ) ) . '</div>';
                    return $message;
                    }
                }
            }
        }
        $core_fields = BSXMH_Form_Builder::core_settings();
        ob_start();
        echo $message;
        ?>
        <form method="post" enctype="multipart/form-data" class="bsxmh-form">
            <?php wp_nonce_field( 'bsxmh_front_register', 'bsxmh_register_nonce' ); ?><input type="hidden" name="bsxmh_front_register" value="1">
            <div><label for="bsxmh-name"><?php esc_html_e( 'Full Name', 'bsx-memberhub' ); ?></label><input id="bsxmh-name" name="display_name" type="text" required></div>
            <div><label for="bsxmh-email"><?php esc_html_e( 'Email Address', 'bsx-memberhub' ); ?></label><input id="bsxmh-email" name="email" type="email" required></div>
            <?php if ( ! empty( $core_fields['phone_enabled'] ) ) : ?><div><label for="bsxmh-phone"><?php echo esc_html( $core_fields['phone_label'] ); ?></label><input id="bsxmh-phone" name="phone" type="text" <?php echo ! empty( $core_fields['phone_required'] ) ? 'required' : ''; ?>></div><?php endif; ?>
            <?php echo BSXMH_Form_Builder::render_fields( 'registration' ); ?>
            <div><label for="bsxmh-password"><?php esc_html_e( 'Password', 'bsx-memberhub' ); ?></label><input id="bsxmh-password" name="password" type="password" minlength="8" required></div>
            <button type="submit"><?php esc_html_e( 'Register as Member', 'bsx-memberhub' ); ?></button>
        </form>
        <?php
        return (string) ob_get_clean();
    }

    public function member_dashboard(): string {
        wp_enqueue_style( 'bsxmh-public' );
        if ( ! is_user_logged_in() ) {
            return '<div class="bsxmh-notice">' . esc_html__( 'Please log in to view your member dashboard.', 'bsx-memberhub' ) . ' <a href="' . esc_url( BSXMH_Portal::page_url( 'login_page_id', '/member-login/' ) ) . '">' . esc_html__( 'Member Login', 'bsx-memberhub' ) . '</a></div>';
        }
        $user = wp_get_current_user();
        $member = BSXMH_Members::get_by_user( (int) $user->ID );
        if ( ! $member ) {
            return '<div class="bsxmh-notice">' . esc_html__( 'No MemberHub profile is linked to this account.', 'bsx-memberhub' ) . '</div>';
        }
        if ( 'active' !== $member->status ) {
            $messages = array( 'pending' => __( 'Your membership is waiting for administrator approval.', 'bsx-memberhub' ), 'inactive' => __( 'Your membership is inactive. Please contact the organization.', 'bsx-memberhub' ), 'suspended' => __( 'Your membership has been suspended. Please contact the organization.', 'bsx-memberhub' ) );
            return BSXMH_Portal::nav() . '<div class="bsxmh-notice bsxmh-error">' . esc_html( $messages[ $member->status ] ?? __( 'Your account is not active.', 'bsx-memberhub' ) ) . '</div>';
        }
        $statement = BSXMH_Payments::statement( $member );
        global $wpdb;
        $history = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM " . BSXMH_DB::table( 'payments' ) . " WHERE user_id=%d AND status='paid' ORDER BY payment_date DESC,id DESC LIMIT 20", $user->ID ) );
        $status_labels = array( 'pending' => __( 'Pending Approval', 'bsx-memberhub' ), 'active' => __( 'Active', 'bsx-memberhub' ), 'inactive' => __( 'Inactive', 'bsx-memberhub' ), 'suspended' => __( 'Suspended', 'bsx-memberhub' ) );
        $sym = BSXMH_Payments::currency_symbol();
        $extra_total = BSXMH_Contributions::member_total( (int) $user->ID );
        $event_total = BSXMH_Events::member_total( (int) $user->ID );
        ob_start(); ?>
        <?php echo BSXMH_Portal::nav(); ?>
        <div class="bsxmh-public-card bsxmh-dashboard-card"><h2><?php echo esc_html( sprintf( __( 'Welcome, %s', 'bsx-memberhub' ), $user->display_name ) ); ?></h2>
            <div class="bsxmh-profile-grid"><div><span><?php esc_html_e( 'Member ID', 'bsx-memberhub' ); ?></span><strong><?php echo esc_html( $member->member_number ); ?></strong></div><div><span><?php esc_html_e( 'Status', 'bsx-memberhub' ); ?></span><strong><?php echo esc_html( $status_labels[ $member->status ] ?? $member->status ); ?></strong></div><div><span><?php esc_html_e( 'Monthly Fee', 'bsx-memberhub' ); ?></span><strong><?php echo esc_html( $sym . number_format_i18n( (float) $member->monthly_fee, 2 ) ); ?></strong></div><div><span><?php esc_html_e( 'Fee Start', 'bsx-memberhub' ); ?></span><strong><?php echo esc_html( $member->fee_start_date ?: '—' ); ?></strong></div><div><span>Membership Paid</span><strong><?php echo esc_html( $sym . number_format_i18n( $statement['total_paid'], 2 ) ); ?></strong></div><div><span>Extra Contribution</span><strong><?php echo esc_html( $sym . number_format_i18n( $extra_total, 2 ) ); ?></strong></div><div><span>Event Donations</span><strong><?php echo esc_html( $sym . number_format_i18n( $event_total, 2 ) ); ?></strong></div><div><span>Grand Total Contributed</span><strong><?php echo esc_html( $sym . number_format_i18n( $statement['total_paid'] + $extra_total + $event_total, 2 ) ); ?></strong></div><div><span>Total Due</span><strong><?php echo esc_html( $sym . number_format_i18n( $statement['total_due'], 2 ) ); ?></strong></div></div>
            <h3>Due Months</h3><div class="bsxmh-periods"><?php if ( empty( $statement['due'] ) ) : ?><span class="bsxmh-notice">No dues.</span><?php else : foreach ( $statement['due'] as $period ) : ?><span class="bsxmh-badge"><?php echo esc_html( BSXMH_Payments::month_label( $period['year'], $period['month'] ) ); ?></span><?php endforeach; endif; ?></div>
            <h3>Paid Months</h3><div class="bsxmh-periods"><?php if ( empty( $statement['paid'] ) ) : ?><span>No paid months yet.</span><?php else : foreach ( $statement['paid'] as $period ) : ?><span class="bsxmh-badge"><?php echo esc_html( BSXMH_Payments::month_label( $period['year'], $period['month'] ) ); ?></span><?php endforeach; endif; ?></div>
            <h3>Payment History</h3><div class="bsxmh-table-wrap"><table class="bsxmh-table"><thead><tr><th>Date</th><th>Type</th><th>Details</th><th>Amount</th><th>Method</th><th>Receipt</th></tr></thead><tbody><?php if ( ! $history ) : ?><tr><td colspan="6">No payment history yet.</td></tr><?php else : foreach ( $history as $payment ) : $items = BSXMH_Payments::items( (int) $payment->id ); $meta = json_decode( (string) $payment->metadata, true ); $receipt = BSXMH_Receipts::get_by_payment( (int) $payment->id ); ?><tr><td><?php echo esc_html( $payment->payment_date ); ?></td><td><?php echo esc_html( ucwords( str_replace( '_', ' ', $payment->payment_type ) ) ); ?></td><td><?php echo esc_html( implode( ', ', array_map( static fn( $item ) => $item->description, $items ) ) ); ?></td><td><?php echo esc_html( $sym . number_format_i18n( (float) $payment->total_amount, 2 ) ); ?></td><td><?php echo esc_html( ucwords( str_replace( '_', ' ', $meta['method'] ?? $payment->gateway ) ) ); ?></td><td><?php if ( $receipt ) : ?><a target="_blank" href="<?php echo esc_url( BSXMH_Receipts::public_url( $receipt ) ); ?>">View / Print</a><?php else : ?>—<?php endif; ?></td></tr><?php endforeach; endif; ?></tbody></table></div>
            <?php echo BSXMH_Portal::announcements(); ?>
        </div><?php return (string) ob_get_clean();
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
        if ( ! $events ) return '<div class="bsxmh-notice">' . esc_html__( 'No active fundraising events are available.', 'bsx-memberhub' ) . '</div>';
        $html = '<div class="bsxmh-event-grid">';
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
        return $html.'</div>';
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
        $html .= do_shortcode( '[bsxmh_online_event_donation id="' . absint( $event->id ) . '"]' ); $html .= '</div>'; return $html;
    }

    public function coming_soon(): string {
        wp_enqueue_style( 'bsxmh-public' );
        return '<div class="bsxmh-notice">' . esc_html__( 'This MemberHub module is being prepared.', 'bsx-memberhub' ) . '</div>';
    }
}
