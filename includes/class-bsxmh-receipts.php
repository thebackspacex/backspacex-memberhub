<?php

defined( 'ABSPATH' ) || exit;

final class BSXMH_Receipts {
    public static function register(): void {
        add_action( 'init', array( __CLASS__, 'add_rewrite' ) );
        add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
        add_action( 'template_redirect', array( __CLASS__, 'maybe_render_public' ) );
    }

    public static function add_rewrite(): void {
        add_rewrite_rule( '^memberhub/receipt/([A-Za-z0-9_-]+)/?$', 'index.php?bsxmh_receipt_token=$matches[1]', 'top' );
    }

    public static function query_vars( array $vars ): array {
        $vars[] = 'bsxmh_receipt_token';
        return $vars;
    }

    public static function create_for_payment( int $payment_id ) {
        global $wpdb;
        $table = BSXMH_DB::table( 'receipts' );
        $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE payment_id=%d", $payment_id ) );
        if ( $existing ) return (int) $existing->id;
        $payment = BSXMH_Payments::get( $payment_id );
        if ( ! $payment || 'paid' !== $payment->status ) return new WP_Error( 'invalid_payment', __( 'Only paid transactions can receive a receipt.', 'bsx-memberhub' ) );
        $number = self::next_number();
        $token = wp_generate_password( 32, false, false );
        $snapshot = self::snapshot( $payment );
        $ok = $wpdb->insert( $table, array(
            'payment_id' => $payment_id,
            'receipt_number' => $number,
            'verification_token' => $token,
            'status' => 'valid',
            'revision' => 1,
            'template_snapshot' => wp_json_encode( $snapshot ),
            'created_at' => current_time( 'mysql' ),
            'updated_at' => current_time( 'mysql' ),
        ) );
        if ( false === $ok ) return new WP_Error( 'receipt_db', __( 'Receipt could not be created.', 'bsx-memberhub' ) );
        set_transient( 'bsxmh_receipt_plain_' . (int) $wpdb->insert_id, $token, DAY_IN_SECONDS );
        return (int) $wpdb->insert_id;
    }

    public static function ensure_all(): int {
        global $wpdb;
        $payments = BSXMH_DB::table( 'payments' );
        $receipts = BSXMH_DB::table( 'receipts' );
        $ids = $wpdb->get_col( "SELECT p.id FROM {$payments} p LEFT JOIN {$receipts} r ON r.payment_id=p.id WHERE p.status='paid' AND r.id IS NULL" );
        $count = 0;
        foreach ( $ids as $id ) if ( ! is_wp_error( self::create_for_payment( (int) $id ) ) ) $count++;
        return $count;
    }

    public static function get_by_payment( int $payment_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . BSXMH_DB::table( 'receipts' ) . ' WHERE payment_id=%d', $payment_id ) );
    }

    public static function get( int $receipt_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . BSXMH_DB::table( 'receipts' ) . ' WHERE id=%d', $receipt_id ) );
    }

    public static function public_url( $receipt ): string {
        $plain = (string) ( $receipt->verification_token ?? '' );
        if ( '' === $plain || 64 === strlen( $plain ) ) {
            $plain = wp_generate_password( 32, false, false );
            global $wpdb;
            $wpdb->update( BSXMH_DB::table( 'receipts' ), array( 'verification_token' => $plain, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $receipt->id ) );
        }
        return home_url( '/memberhub/receipt/' . rawurlencode( $plain ) . '/' );
    }

    public static function admin_url( int $receipt_id, bool $print = false ): string {
        return wp_nonce_url( admin_url( 'admin-post.php?action=bsxmh_view_receipt&receipt_id=' . $receipt_id . ( $print ? '&print=1' : '' ) ), 'bsxmh_view_receipt_' . $receipt_id );
    }

    public static function render( $receipt, bool $public = false ): void {
        $snap = json_decode( (string) $receipt->template_snapshot, true );
        if ( ! is_array( $snap ) ) $snap = array();
        $settings = get_option( 'bsxmh_settings', array() );
        status_header( 200 ); nocache_headers();
        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>' . esc_html( $receipt->receipt_number ) . '</title><style>body{font-family:Arial,"Noto Sans Bengali",sans-serif;background:#f3f4f6;margin:0;padding:30px;color:#111}.receipt{max-width:760px;margin:auto;background:#fff;padding:38px;border:1px solid #ddd}.head{text-align:center;border-bottom:2px solid #111;padding-bottom:18px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:24px 0}.row{padding:10px;border-bottom:1px solid #eee}.amount{font-size:26px;font-weight:700}.actions{text-align:center;margin:20px}.valid{color:#08783f;font-weight:700}.invalid{color:#b42318;font-weight:700}@media print{body{background:#fff;padding:0}.receipt{border:0;max-width:none}.actions{display:none}}</style></head><body>';
        echo '<div class="actions"><button onclick="window.print()">Print / Save as PDF</button></div><div class="receipt"><div class="head">';
        if ( ! empty( $settings['organization_logo_url'] ) ) echo '<img alt="Logo" style="max-height:80px;max-width:220px" src="' . esc_url( $settings['organization_logo_url'] ) . '">';
        echo '<h1>' . esc_html( $settings['receipt_title'] ?? 'Payment Receipt' ) . '</h1><h2>' . esc_html( $settings['organization_name'] ?? get_bloginfo( 'name' ) ) . '</h2>';
        if ( ! empty( $settings['receipt_header'] ) ) echo '<p>' . nl2br( esc_html( $settings['receipt_header'] ) ) . '</p>';
        echo '</div><div class="grid"><div class="row"><strong>Receipt Number</strong><br>' . esc_html( $receipt->receipt_number ) . '</div><div class="row"><strong>Status</strong><br><span class="' . ( 'valid' === $receipt->status ? 'valid' : 'invalid' ) . '">' . esc_html( ucfirst( $receipt->status ) ) . '</span></div>';
        $member_name   = trim( (string) ( $snap['member_name'] ?? '' ) );
        $member_number = trim( (string) ( $snap['member_number'] ?? '' ) );

        // Receipts are verification documents, so the payer/member identity must
        // remain visible on both admin printouts and public verification links.
        if ( '' !== $member_name || '' !== $member_number ) {
            $member_display = '' !== $member_name ? $member_name : __( 'Member', 'bsx-memberhub' );
            if ( '' !== $member_number ) {
                $member_display .= ' (' . $member_number . ')';
            }
            echo '<div class="row"><strong>' . esc_html__( 'Member', 'bsx-memberhub' ) . '</strong><br>' . esc_html( $member_display ) . '</div>';
        }
        echo '<div class="row"><strong>Payment Date</strong><br>' . esc_html( $snap['payment_date'] ?? '' ) . '</div><div class="row"><strong>Details</strong><br>' . esc_html( implode( ', ', $snap['months'] ?? array() ) ) . '</div><div class="row"><strong>Payment Type</strong><br>' . esc_html( ucwords( str_replace( '_', ' ', $snap['payment_type'] ?? 'membership' ) ) ) . '</div><div class="row"><strong>Fund</strong><br>' . esc_html( implode( ', ', $snap['funds'] ?? array() ) ?: '—' ) . '</div><div class="row"><strong>Payment Method</strong><br>' . esc_html( $snap['method'] ?? '' ) . '</div><div class="row"><strong>Transaction</strong><br>' . esc_html( $snap['transaction_id'] ?? '' ) . '</div><div class="row"><strong>Amount</strong><br><span class="amount">' . esc_html( BSXMH_Payments::currency_symbol() . number_format_i18n( (float) ( $snap['amount'] ?? 0 ), 2 ) ) . '</span></div></div>';
        if ( ! empty( $settings['receipt_thank_you'] ) ) echo '<p style="text-align:center"><strong>' . esc_html( $settings['receipt_thank_you'] ) . '</strong></p>';
        if ( ! empty( $settings['receipt_footer'] ) ) echo '<hr><p>' . nl2br( esc_html( $settings['receipt_footer'] ) ) . '</p>';
        echo '</div></body></html>'; exit;
    }

    public static function maybe_render_public(): void {
        $token = (string) get_query_var( 'bsxmh_receipt_token' );
        if ( '' === $token ) return;
        global $wpdb;
        $receipt = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . BSXMH_DB::table( 'receipts' ) . ' WHERE verification_token=%s', sanitize_text_field( $token ) ) );
        if ( ! $receipt ) { status_header( 404 ); wp_die( esc_html__( 'Receipt not found or verification link is invalid.', 'bsx-memberhub' ) ); }
        self::render( $receipt, true );
    }

    private static function snapshot( $payment ): array {
        $user = get_userdata( (int) $payment->user_id );
        $member = BSXMH_Members::get_by_user( (int) $payment->user_id );
        $items = BSXMH_Payments::items( (int) $payment->id );
        $meta = json_decode( (string) $payment->metadata, true );
        return array(
            'transaction_id' => $payment->transaction_id,
            'member_name' => $user ? $user->display_name : '',
            'member_number' => $member ? $member->member_number : '',
            'payment_date' => $payment->payment_date,
            'payment_type' => (string) $payment->payment_type,
            'months' => array_values( array_map( static fn( $i ) => (string) $i->description, $items ) ),
            'funds' => array_values( array_unique( array_filter( array_map( static function( $i ) { $f = ! empty( $i->fund_id ) ? BSXMH_Contributions::get_fund( (int) $i->fund_id ) : null; return $f ? $f->name : ''; }, $items ) ) ) ),
            'amount' => (float) $payment->total_amount,
            'method' => ucwords( str_replace( '_', ' ', $meta['method'] ?? $payment->gateway ) ),
            'reference' => $meta['reference'] ?? '',
            'notes' => $meta['notes'] ?? '',
        );
    }

    private static function next_number(): string {
        global $wpdb;
        $settings = get_option( 'bsxmh_settings', array() );
        $prefix = sanitize_key( $settings['receipt_prefix'] ?? 'BSXMH' );
        $prefix = strtoupper( $prefix ?: 'BSXMH' );
        $year = current_time( 'Y' );
        $count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . BSXMH_DB::table( 'receipts' ) . ' WHERE receipt_number LIKE %s', $wpdb->esc_like( $prefix . '-' . $year . '-' ) . '%' ) );
        do { $number = sprintf( '%s-%s-%06d', $prefix, $year, ++$count ); $exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . BSXMH_DB::table( 'receipts' ) . ' WHERE receipt_number=%s', $number ) ); } while ( $exists );
        return $number;
    }
}
