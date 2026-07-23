<?php

defined( 'ABSPATH' ) || exit;

final class BSXMH_Payment_Control {
    public static function register(): void {
        add_action( 'admin_post_bsxmh_send_member_reminder', array( __CLASS__, 'send_member_reminder' ) );
        add_action( 'admin_post_bsxmh_bulk_member_reminder', array( __CLASS__, 'bulk_member_reminder' ) );
        add_action( 'admin_post_bsxmh_copy_member_payment_link', array( __CLASS__, 'copy_payment_link' ) );
    }

    public static function render(): void {
        if ( ! current_user_can( 'bsxmh_manage_members' ) ) {
            wp_die( esc_html__( 'You are not allowed to access this page.', 'bsx-memberhub' ) );
        }

        $month = self::valid_month( sanitize_text_field( wp_unslash( $_GET['month'] ?? '' ) ) ) ?: current_time( 'Y-m' );
        $tab = sanitize_key( $_GET['view'] ?? 'all' );
        if ( ! in_array( $tab, array( 'all', 'paid', 'unpaid', 'not_applicable' ), true ) ) {
            $tab = 'all';
        }
        $search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
        $status_filter = sanitize_key( $_GET['status'] ?? '' );
        $reminder_filter = sanitize_key( $_GET['reminder'] ?? '' );

        $rows = self::member_rows( $month );
        $summary = self::summary( $rows );
        $filtered = array_values( array_filter( $rows, static function ( array $row ) use ( $tab, $search, $status_filter, $reminder_filter ): bool {
            if ( 'all' !== $tab && $row['month_status'] !== $tab ) {
                return false;
            }
            if ( $status_filter && $row['member']->status !== $status_filter ) {
                return false;
            }
            if ( $search ) {
                $haystack = strtolower( $row['member']->member_number . ' ' . $row['display_name'] . ' ' . $row['email'] . ' ' . $row['phone'] );
                if ( false === strpos( $haystack, strtolower( $search ) ) ) {
                    return false;
                }
            }
            if ( 'never' === $reminder_filter && $row['last_reminder'] ) {
                return false;
            }
            if ( 'sent' === $reminder_filter && ! $row['last_reminder'] ) {
                return false;
            }
            return true;
        } ) );

        $sym = BSXMH_Payments::currency_symbol();
        $month_label = self::month_label_from_key( $month );
        $prev = wp_date( 'Y-m', strtotime( $month . '-01 -1 month' ) );
        $next = wp_date( 'Y-m', strtotime( $month . '-01 +1 month' ) );

        echo '<div class="wrap bsxmh-wrap"><h1>Members <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=bsxmh-members&action=add' ) ) . '">Add New</a></h1>';
        self::render_notice();
        echo '<style>
        .bsxmh-control-head{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:14px 0}.bsxmh-control-head form{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.bsxmh-status-paid{color:#087b36;font-weight:700}.bsxmh-status-unpaid{color:#b42318;font-weight:700}.bsxmh-status-not_applicable{color:#667085}.bsxmh-due-list{max-width:300px}.bsxmh-due-list details summary{cursor:pointer}.bsxmh-actions{display:flex;gap:6px;flex-wrap:wrap}.bsxmh-severity{display:inline-block;padding:2px 7px;border-radius:10px;font-size:11px;font-weight:700;background:#f2f4f7}.bsxmh-severity-high,.bsxmh-severity-critical{background:#fee4e2;color:#b42318}.bsxmh-severity-medium{background:#fef0c7;color:#93370d}.bsxmh-severity-low{background:#ecfdf3;color:#027a48}.bsxmh-tabs{margin:15px 0}.bsxmh-tabs a{display:inline-block;padding:8px 12px;text-decoration:none;border:1px solid #c3c4c7;background:#fff;margin-right:4px}.bsxmh-tabs a.current{background:#2271b1;color:#fff;border-color:#2271b1}.bsxmh-month-nav{display:flex;gap:8px;align-items:center}.bsxmh-table-wrap{overflow:auto}.bsxmh-table-wrap table{min-width:1250px}
        </style>';

        echo '<div class="bsxmh-cards">';
        self::card( 'Active Members', number_format_i18n( $summary['active'] ) );
        self::card( 'Paid — ' . $month_label, number_format_i18n( $summary['paid'] ) );
        self::card( 'Unpaid — ' . $month_label, number_format_i18n( $summary['unpaid'] ) );
        self::card( 'Expected Collection', $sym . number_format_i18n( $summary['expected'], 2 ) );
        self::card( 'Collected', $sym . number_format_i18n( $summary['collected'], 2 ) );
        self::card( 'Outstanding', $sym . number_format_i18n( $summary['outstanding'], 2 ) );
        self::card( 'Collection Rate', number_format_i18n( $summary['percentage'], 1 ) . '%' );
        self::card( 'All-time Due', $sym . number_format_i18n( $summary['all_due'], 2 ) );
        echo '</div>';

        echo '<div class="bsxmh-panel"><div class="bsxmh-control-head"><div class="bsxmh-month-nav"><a class="button" href="' . esc_url( self::page_url( array( 'month' => $prev, 'view' => $tab ) ) ) . '">&larr; Previous</a><strong>' . esc_html( $month_label ) . '</strong><a class="button" href="' . esc_url( self::page_url( array( 'month' => $next, 'view' => $tab ) ) ) . '">Next &rarr;</a></div>';
        echo '<form method="get"><input type="hidden" name="page" value="bsxmh-members"><input type="hidden" name="view" value="' . esc_attr( $tab ) . '"><input type="month" name="month" value="' . esc_attr( $month ) . '"><input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="Name, email, mobile or ID"><select name="status"><option value="">All member statuses</option>';
        foreach ( array( 'active' => 'Active', 'pending' => 'Pending', 'inactive' => 'Inactive', 'suspended' => 'Suspended' ) as $key => $label ) {
            echo '<option value="' . esc_attr( $key ) . '" ' . selected( $status_filter, $key, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select><select name="reminder"><option value="">Any reminder status</option><option value="never" ' . selected( $reminder_filter, 'never', false ) . '>Never reminded</option><option value="sent" ' . selected( $reminder_filter, 'sent', false ) . '>Reminder sent</option></select><button class="button">Apply</button></form></div>';

        echo '<div class="bsxmh-tabs">';
        foreach ( array( 'all' => 'All Members', 'paid' => 'Paid Members', 'unpaid' => 'Unpaid Members', 'not_applicable' => 'Not Applicable' ) as $key => $label ) {
            $count = 'all' === $key ? count( $rows ) : count( array_filter( $rows, static fn( $r ) => $r['month_status'] === $key ) );
            echo '<a class="' . ( $tab === $key ? 'current' : '' ) . '" href="' . esc_url( self::page_url( array( 'month' => $month, 'view' => $key, 's' => $search, 'status' => $status_filter, 'reminder' => $reminder_filter ) ) ) . '">' . esc_html( $label ) . ' (' . number_format_i18n( $count ) . ')</a>';
        }
        echo '</div>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="bsxmh_bulk_member_reminder"><input type="hidden" name="month" value="' . esc_attr( $month ) . '"><input type="hidden" name="return_view" value="' . esc_attr( $tab ) . '">';
        wp_nonce_field( 'bsxmh_bulk_member_reminder' );
        echo '<p><button class="button button-primary" name="mode" value="selected">Send Reminder to Selected Unpaid Members</button> <button class="button" name="mode" value="all_unpaid">Send Reminder to All Unpaid Members</button></p>';
        echo '<div class="bsxmh-table-wrap"><table class="widefat striped"><thead><tr><th><input type="checkbox" onclick="document.querySelectorAll(\'.bsxmh-member-check\').forEach(c=>c.checked=this.checked)"></th><th>Member</th><th>Contact</th><th>Member Status</th><th>' . esc_html( $month_label ) . '</th><th>Monthly Fee</th><th>Total Paid</th><th>Total Due</th><th>Due Months</th><th>Payment Details</th><th>Last Reminder</th><th>Actions</th></tr></thead><tbody>';
        if ( ! $filtered ) {
            echo '<tr><td colspan="12">No members match the selected filters.</td></tr>';
        }
        foreach ( $filtered as $row ) {
            self::render_row( $row, $month );
        }
        echo '</tbody></table></div></form></div></div>';
    }

    private static function render_row( array $row, string $month ): void {
        $m = $row['member'];
        $sym = BSXMH_Payments::currency_symbol();
        $status_label = ucwords( str_replace( '_', ' ', $row['month_status'] ) );
        $status_class = 'bsxmh-status-' . sanitize_html_class( $row['month_status'] );
        $due_labels = array_map( static fn( $p ) => BSXMH_Payments::month_label( (int) $p['year'], (int) $p['month'] ), $row['statement']['due'] );
        $due_count = count( $due_labels );
        $severity = self::severity( $due_count );
        $payment = $row['payment'];
        $details = '—';
        if ( $payment ) {
            $receipt = BSXMH_Receipts::get_by_payment( (int) $payment->id );
            $meta = json_decode( (string) $payment->metadata, true );
            $method = ucwords( str_replace( '_', ' ', (string) ( $meta['method'] ?? $payment->gateway ) ) );
            $details = esc_html( BSXMH_Payments::currency_symbol() . number_format_i18n( (float) $payment->item_amount, 2 ) ) . '<br><small>' . esc_html( $payment->payment_date . ' · ' . $method ) . '</small>';
            if ( $receipt ) {
                $details .= '<br><a href="' . esc_url( BSXMH_Receipts::admin_url( (int) $receipt->id ) ) . '">Receipt</a>';
            }
        }
        $due_html = '—';
        if ( $due_count ) {
            $due_html = '<div class="bsxmh-due-list"><strong>' . number_format_i18n( $due_count ) . ' month(s)</strong> <span class="bsxmh-severity bsxmh-severity-' . esc_attr( $severity ) . '">' . esc_html( ucfirst( $severity ) ) . '</span><details><summary>View months</summary>' . esc_html( implode( ', ', $due_labels ) ) . '</details></div>';
        }
        $actions = '<div class="bsxmh-actions"><a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=bsxmh-members&action=statement&member_id=' . $m->id ) ) . '">Statement</a><a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=bsxmh-payments&action=add&member_id=' . $m->id ) ) . '">Add Payment</a><a class="button button-small" href="' . esc_url( admin_url( 'admin.php?page=bsxmh-members&action=edit&member_id=' . $m->id ) ) . '">Edit</a>';
        if ( 'unpaid' === $row['month_status'] && 'active' === $m->status ) {
            $send_url = wp_nonce_url( admin_url( 'admin-post.php?action=bsxmh_send_member_reminder&member_id=' . $m->id . '&month=' . rawurlencode( $month ) ), 'bsxmh_send_member_reminder_' . $m->id );
            $link_url = wp_nonce_url( admin_url( 'admin-post.php?action=bsxmh_copy_member_payment_link&member_id=' . $m->id . '&month=' . rawurlencode( $month ) ), 'bsxmh_copy_member_payment_link_' . $m->id );
            $actions .= '<a class="button button-small button-primary" href="' . esc_url( $send_url ) . '">Send Reminder</a><a class="button button-small" href="' . esc_url( $link_url ) . '">Create Payment Link</a>';
        }
        $actions .= '</div>';

        echo '<tr><td>' . ( 'unpaid' === $row['month_status'] && 'active' === $m->status ? '<input class="bsxmh-member-check" type="checkbox" name="member_ids[]" value="' . absint( $m->id ) . '">' : '' ) . '</td>';
        echo '<td><strong>' . esc_html( $row['display_name'] ) . '</strong><br><code>' . esc_html( $m->member_number ) . '</code></td>';
        echo '<td>' . esc_html( $row['email'] ) . ( $row['phone'] ? '<br>' . esc_html( $row['phone'] ) : '' ) . '</td>';
        echo '<td>' . esc_html( ucfirst( $m->status ) ) . '</td>';
        echo '<td><span class="' . esc_attr( $status_class ) . '">' . esc_html( $status_label ) . '</span></td>';
        echo '<td>' . esc_html( $sym . number_format_i18n( (float) $m->monthly_fee, 2 ) ) . '</td>';
        echo '<td>' . esc_html( $sym . number_format_i18n( (float) $row['statement']['total_paid'], 2 ) ) . '</td>';
        echo '<td><strong>' . esc_html( $sym . number_format_i18n( (float) $row['statement']['total_due'], 2 ) ) . '</strong></td>';
        echo '<td>' . $due_html . '</td><td>' . $details . '</td>';
        echo '<td>' . ( $row['last_reminder'] ? esc_html( $row['last_reminder']->created_at ) . '<br><small>' . esc_html( ucfirst( $row['last_reminder']->status ) ) . '</small>' : 'Never' ) . '</td><td>' . $actions . '</td></tr>';
    }

    public static function send_member_reminder(): void {
        if ( ! current_user_can( 'bsxmh_send_reminders' ) ) {
            wp_die( 'Not allowed.' );
        }
        $member_id = absint( $_GET['member_id'] ?? 0 );
        check_admin_referer( 'bsxmh_send_member_reminder_' . $member_id );
        $month = self::valid_month( sanitize_text_field( wp_unslash( $_GET['month'] ?? '' ) ) ) ?: current_time( 'Y-m' );
        $result = self::queue_reminder_for_member( $member_id );
        self::redirect_back( $month, is_wp_error( $result ) ? 'error' : 'reminder_sent', is_wp_error( $result ) ? $result->get_error_message() : '' );
    }

    public static function bulk_member_reminder(): void {
        if ( ! current_user_can( 'bsxmh_send_reminders' ) ) {
            wp_die( 'Not allowed.' );
        }
        check_admin_referer( 'bsxmh_bulk_member_reminder' );
        $month = self::valid_month( sanitize_text_field( wp_unslash( $_POST['month'] ?? '' ) ) ) ?: current_time( 'Y-m' );
        $mode = sanitize_key( $_POST['mode'] ?? 'selected' );
        $ids = array_map( 'absint', (array) ( $_POST['member_ids'] ?? array() ) );
        if ( 'all_unpaid' === $mode ) {
            $ids = array_map( static fn( $row ) => (int) $row['member']->id, array_filter( self::member_rows( $month ), static fn( $row ) => 'unpaid' === $row['month_status'] && 'active' === $row['member']->status ) );
        }
        $ids = array_values( array_unique( array_filter( $ids ) ) );
        $count = 0;
        foreach ( $ids as $id ) {
            if ( ! is_wp_error( self::queue_reminder_for_member( $id ) ) ) {
                $count++;
            }
        }
        self::redirect_back( $month, 'bulk_sent', (string) $count );
    }

    public static function copy_payment_link(): void {
        if ( ! current_user_can( 'bsxmh_send_reminders' ) ) {
            wp_die( 'Not allowed.' );
        }
        $member_id = absint( $_GET['member_id'] ?? 0 );
        check_admin_referer( 'bsxmh_copy_member_payment_link_' . $member_id );
        $month = self::valid_month( sanitize_text_field( wp_unslash( $_GET['month'] ?? '' ) ) ) ?: current_time( 'Y-m' );
        $member = BSXMH_Members::get( $member_id );
        if ( ! $member ) {
            self::redirect_back( $month, 'error', 'Member not found.' );
        }
        $statement = BSXMH_Payments::statement( $member );
        $periods = array_column( $statement['due'], 'key' );
        $link = BSXMH_Email_Automation::create_payment_link( (int) $member->user_id, $periods );
        if ( is_wp_error( $link ) ) {
            self::redirect_back( $month, 'error', $link->get_error_message() );
        }
        self::redirect_back( $month, 'payment_link', $link['url'] );
    }

    private static function queue_reminder_for_member( int $member_id ) {
        $member = BSXMH_Members::get( $member_id );
        if ( ! $member || 'active' !== $member->status ) {
            return new WP_Error( 'member', 'Only active members can receive payment reminders.' );
        }
        $statement = BSXMH_Payments::statement( $member );
        if ( empty( $statement['due'] ) ) {
            return new WP_Error( 'no_due', 'This member currently has no unpaid months.' );
        }
        $periods = array_column( $statement['due'], 'key' );
        $link = BSXMH_Email_Automation::create_payment_link( (int) $member->user_id, $periods );
        if ( is_wp_error( $link ) ) {
            return $link;
        }
        $queued = BSXMH_Email_Automation::queue_template( 'payment_reminder', (int) $member->user_id, array(
            'due_months' => (string) count( $periods ),
            'due_amount' => BSXMH_Payments::currency_symbol() . number_format_i18n( (float) $statement['total_due'], 2 ),
            'payment_link' => $link['url'],
            'link_expiry' => $link['expires'],
        ), (int) $link['id'] );
        return $queued ? true : new WP_Error( 'queue', 'The reminder could not be added to the email queue.' );
    }

    private static function member_rows( string $month ): array {
        global $wpdb;
        $members = $wpdb->get_results( "SELECT m.*,u.display_name,u.user_email FROM " . BSXMH_DB::table( 'members' ) . " m LEFT JOIN {$wpdb->users} u ON u.ID=m.user_id ORDER BY u.display_name ASC" );
        $payments = self::payment_map( $month );
        $last_reminders = self::last_reminder_map();
        $rows = array();
        foreach ( $members as $m ) {
            $profile = json_decode( (string) $m->profile_data, true );
            $profile = is_array( $profile ) ? $profile : array();
            $eligible = ! empty( BSXMH_Payments::eligible_months( $m, $month ) );
            $paid = isset( $payments[ (int) $m->user_id ] );
            $month_status = 'not_applicable';
            if ( 'active' === $m->status && $eligible ) {
                $month_status = $paid ? 'paid' : 'unpaid';
            } elseif ( $paid ) {
                $month_status = 'paid';
            }
            $rows[] = array(
                'member' => $m,
                'display_name' => $m->display_name ?: $m->member_number,
                'email' => (string) $m->user_email,
                'phone' => (string) ( get_user_meta( (int) $m->user_id, 'bsxmh_phone', true ) ?: ( $profile['phone'] ?? '' ) ),
                'month_status' => $month_status,
                'payment' => $payments[ (int) $m->user_id ] ?? null,
                'statement' => BSXMH_Payments::statement( $m ),
                'last_reminder' => $last_reminders[ (int) $m->user_id ] ?? null,
            );
        }
        return $rows;
    }

    private static function payment_map( string $month ): array {
        global $wpdb;
        list( $year, $mon ) = array_map( 'intval', explode( '-', $month ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.*,i.amount item_amount FROM " . BSXMH_DB::table( 'payments' ) . " p INNER JOIN " . BSXMH_DB::table( 'payment_items' ) . " i ON i.payment_id=p.id WHERE p.status='paid' AND p.payment_type='membership' AND i.item_type='membership' AND i.period_year=%d AND i.period_month=%d ORDER BY p.payment_date DESC,p.id DESC",
            $year,
            $mon
        ) );
        $map = array();
        foreach ( $rows as $row ) {
            if ( $row->user_id && ! isset( $map[ (int) $row->user_id ] ) ) {
                $map[ (int) $row->user_id ] = $row;
            }
        }
        return $map;
    }

    private static function last_reminder_map(): array {
        global $wpdb;
        $rows = $wpdb->get_results( "SELECT e.* FROM " . BSXMH_DB::table( 'email_logs' ) . " e INNER JOIN (SELECT user_id,MAX(id) max_id FROM " . BSXMH_DB::table( 'email_logs' ) . " WHERE email_type='payment_reminder' AND user_id IS NOT NULL GROUP BY user_id) x ON x.max_id=e.id" );
        $map = array();
        foreach ( $rows as $row ) {
            $map[ (int) $row->user_id ] = $row;
        }
        return $map;
    }

    private static function summary( array $rows ): array {
        $s = array( 'active' => 0, 'paid' => 0, 'unpaid' => 0, 'expected' => 0.0, 'collected' => 0.0, 'outstanding' => 0.0, 'percentage' => 0.0, 'all_due' => 0.0 );
        foreach ( $rows as $row ) {
            $m = $row['member'];
            if ( 'active' === $m->status ) {
                $s['active']++;
            }
            if ( in_array( $row['month_status'], array( 'paid', 'unpaid' ), true ) && 'active' === $m->status ) {
                $s['expected'] += (float) $m->monthly_fee;
            }
            if ( 'paid' === $row['month_status'] ) {
                $s['paid']++;
                $s['collected'] += $row['payment'] ? (float) $row['payment']->item_amount : (float) $m->monthly_fee;
            } elseif ( 'unpaid' === $row['month_status'] ) {
                $s['unpaid']++;
                $s['outstanding'] += (float) $m->monthly_fee;
            }
            if ( 'active' === $m->status ) {
                $s['all_due'] += (float) $row['statement']['total_due'];
            }
        }
        $s['percentage'] = $s['expected'] > 0 ? ( $s['collected'] / $s['expected'] ) * 100 : 0;
        return $s;
    }

    private static function severity( int $months ): string {
        if ( $months >= 7 ) return 'critical';
        if ( $months >= 4 ) return 'high';
        if ( $months >= 2 ) return 'medium';
        return 'low';
    }

    private static function valid_month( string $month ): ?string {
        return preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $month ) ? $month : null;
    }

    private static function month_label_from_key( string $month ): string {
        list( $year, $mon ) = array_map( 'intval', explode( '-', $month ) );
        return BSXMH_Payments::month_label( $year, $mon );
    }

    private static function page_url( array $args = array() ): string {
        return add_query_arg( array_filter( array_merge( array( 'page' => 'bsxmh-members' ), $args ), static fn( $v ) => '' !== $v && null !== $v ), admin_url( 'admin.php' ) );
    }

    private static function redirect_back( string $month, string $notice, string $detail = '' ): void {
        $args = array( 'month' => $month, 'notice' => $notice );
        if ( '' !== $detail ) {
            $args['detail'] = rawurlencode( $detail );
        }
        wp_safe_redirect( self::page_url( $args ) );
        exit;
    }

    private static function render_notice(): void {
        $notice = sanitize_key( $_GET['notice'] ?? '' );
        $detail = rawurldecode( sanitize_text_field( wp_unslash( $_GET['detail'] ?? '' ) ) );
        if ( 'reminder_sent' === $notice ) {
            echo '<div class="notice notice-success is-dismissible"><p>Payment reminder and secure payment link were added to the email queue.</p></div>';
        } elseif ( 'bulk_sent' === $notice ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . absint( $detail ) . ' reminder email(s) were added to the queue.</p></div>';
        } elseif ( 'payment_link' === $notice && $detail ) {
            echo '<div class="notice notice-success"><p><strong>Secure payment link created:</strong></p><p><input class="large-text" readonly onclick="this.select()" value="' . esc_attr( $detail ) . '"></p></div>';
        } elseif ( 'error' === $notice ) {
            echo '<div class="notice notice-error"><p>' . esc_html( $detail ?: 'The requested action could not be completed.' ) . '</p></div>';
        }
    }

    private static function card( string $label, string $value ): void {
        echo '<div class="bsxmh-card"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong></div>';
    }
}
