<?php

defined( 'ABSPATH' ) || exit;

final class BSXMH_Contributions {
    public static function ensure_defaults(): void {
        global $wpdb;
        $table = BSXMH_DB::table( 'funds' );
        $now = current_time( 'mysql' );
        $defaults = array(
            array( 'Membership Fund', 'membership', 'membership' ),
            array( 'General Donation', 'general-donation', 'general' ),
        );
        foreach ( $defaults as $fund ) {
            $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug=%s", $fund[1] ) );
            if ( ! $exists ) {
                $wpdb->insert( $table, array(
                    'name' => $fund[0], 'slug' => $fund[1], 'fund_type' => $fund[2],
                    'opening_balance' => '0.00', 'visibility' => 'public', 'sort_order' => 0,
                    'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
                ) );
            }
        }
        $membership_id = (int) $wpdb->get_var( "SELECT id FROM {$table} WHERE slug='membership' LIMIT 1" );
        if ( $membership_id ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE " . BSXMH_DB::table( 'payment_items' ) . " SET fund_id=%d WHERE item_type='membership' AND (fund_id IS NULL OR fund_id=0)",
                $membership_id
            ) );
        }
    }

    public static function funds( bool $active_only = false ): array {
        global $wpdb;
        $sql = 'SELECT * FROM ' . BSXMH_DB::table( 'funds' );
        if ( $active_only ) $sql .= " WHERE status='active'";
        return $wpdb->get_results( $sql . ' ORDER BY sort_order ASC, name ASC' );
    }

    public static function get_fund( int $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . BSXMH_DB::table( 'funds' ) . ' WHERE id=%d', $id ) );
    }

    public static function save_fund( array $data ) {
        global $wpdb;
        $id = absint( $data['fund_id'] ?? 0 );
        $name = sanitize_text_field( wp_unslash( $data['name'] ?? '' ) );
        if ( '' === $name ) return new WP_Error( 'fund_name', __( 'Fund name is required.', 'bsx-memberhub' ) );
        $requested_slug = sanitize_title( wp_unslash( $data['slug'] ?? '' ) );
        $slug = self::unique_fund_slug( $requested_slug ?: sanitize_title( $name ), $id );
        $record = array(
            'name' => $name,
            'slug' => $slug,
            'description' => sanitize_textarea_field( wp_unslash( $data['description'] ?? '' ) ),
            'fund_type' => sanitize_key( $data['fund_type'] ?? 'general' ),
            'opening_balance' => number_format( max( 0, (float) ( $data['opening_balance'] ?? 0 ) ), 2, '.', '' ),
            'visibility' => in_array( $data['visibility'] ?? '', array( 'public', 'members', 'admin', 'hidden' ), true ) ? $data['visibility'] : 'public',
            'sort_order' => (int) ( $data['sort_order'] ?? 0 ),
            'status' => in_array( $data['status'] ?? '', array( 'active', 'inactive' ), true ) ? $data['status'] : 'active',
            'updated_at' => current_time( 'mysql' ),
        );
        if ( $id ) {
            $ok = $wpdb->update( BSXMH_DB::table( 'funds' ), $record, array( 'id' => $id ) );
        } else {
            $record['created_at'] = current_time( 'mysql' );
            $ok = $wpdb->insert( BSXMH_DB::table( 'funds' ), $record );
            $id = (int) $wpdb->insert_id;
        }
        if ( false === $ok ) return new WP_Error( 'fund_db', __( 'Fund could not be saved. The name or slug may already exist.', 'bsx-memberhub' ) );
        self::log( 'fund_saved', 'fund', $id, $record );
        return $id;
    }


    private static function unique_fund_slug( string $slug, int $exclude_id = 0 ): string {
        global $wpdb;
        $table = BSXMH_DB::table( 'funds' );
        $base = $slug ?: 'fund';
        $candidate = $base;
        $suffix = 2;
        while ( true ) {
            if ( $exclude_id ) {
                $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug=%s AND id<>%d LIMIT 1", $candidate, $exclude_id ) );
            } else {
                $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug=%s LIMIT 1", $candidate ) );
            }
            if ( ! $exists ) return $candidate;
            $candidate = $base . '-' . $suffix;
            $suffix++;
        }
    }

    public static function create( array $data ) {
        global $wpdb;
        $member_id = absint( $data['member_id'] ?? 0 );
        $member = BSXMH_Members::get( $member_id );
        if ( ! $member ) return new WP_Error( 'member_missing', __( 'Please select a valid member.', 'bsx-memberhub' ) );
        $fund = self::get_fund( absint( $data['fund_id'] ?? 0 ) );
        if ( ! $fund || 'active' !== $fund->status ) return new WP_Error( 'fund_missing', __( 'Please select an active fund.', 'bsx-memberhub' ) );
        $amount = max( 0, (float) ( $data['amount'] ?? 0 ) );
        if ( $amount <= 0 ) return new WP_Error( 'amount', __( 'Contribution amount must be greater than zero.', 'bsx-memberhub' ) );
        $reference = sanitize_text_field( wp_unslash( $data['reference_number'] ?? '' ) );
        if ( $reference ) {
            $like = '%"reference":"' . $wpdb->esc_like( $reference ) . '"%';
            $duplicate = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM " . BSXMH_DB::table( 'payments' ) . " WHERE payment_type='extra_contribution' AND metadata LIKE %s LIMIT 1", $like ) );
            if ( $duplicate && empty( $data['duplicate_override'] ) ) return new WP_Error( 'duplicate_reference', __( 'This reference number already exists. Use the override only when intentional.', 'bsx-memberhub' ) );
        }
        $date = sanitize_text_field( wp_unslash( $data['payment_date'] ?? current_time( 'Y-m-d' ) ) );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) $date = current_time( 'Y-m-d' );
        $meta = array(
            'method' => sanitize_key( $data['payment_method'] ?? 'cash' ),
            'reference' => $reference,
            'notes' => sanitize_textarea_field( wp_unslash( $data['notes'] ?? '' ) ),
            'received_by' => sanitize_text_field( wp_unslash( $data['received_by'] ?? '' ) ),
            'contribution_type' => sanitize_text_field( wp_unslash( $data['contribution_type'] ?? 'Extra Contribution' ) ),
            'duplicate_override' => ! empty( $data['duplicate_override'] ),
        );
        $now = current_time( 'mysql' );
        $wpdb->insert( BSXMH_DB::table( 'payments' ), array(
            'transaction_id' => BSXMH_Payments::transaction_id(), 'user_id' => (int) $member->user_id,
            'payment_type' => 'extra_contribution', 'gateway' => 'manual',
            'currency' => strtoupper( sanitize_key( get_option( 'bsxmh_settings', array() )['currency'] ?? 'BDT' ) ),
            'subtotal' => number_format( $amount, 2, '.', '' ), 'fee_amount' => '0.00', 'total_amount' => number_format( $amount, 2, '.', '' ),
            'status' => 'paid', 'payment_date' => $date . ' ' . current_time( 'H:i:s' ), 'metadata' => wp_json_encode( $meta ),
            'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now,
        ) );
        $payment_id = (int) $wpdb->insert_id;
        if ( ! $payment_id ) return new WP_Error( 'payment_db', __( 'Contribution could not be saved.', 'bsx-memberhub' ) );
        $wpdb->insert( BSXMH_DB::table( 'payment_items' ), array(
            'payment_id' => $payment_id, 'item_type' => 'extra_contribution', 'reference_id' => $member_id,
            'description' => sanitize_text_field( $meta['contribution_type'] ) . ' — ' . $fund->name,
            'amount' => number_format( $amount, 2, '.', '' ), 'fund_id' => (int) $fund->id, 'created_at' => $now,
        ) );
        BSXMH_Receipts::create_for_payment( $payment_id );
        do_action( 'bsxmh_payment_completed', $payment_id );
        self::log( 'extra_contribution_created', 'payment', $payment_id, array( 'member_id' => $member_id, 'fund_id' => (int) $fund->id, 'amount' => $amount ) );
        return $payment_id;
    }

    public static function member_total( int $user_id ): float {
        global $wpdb;
        return (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(total_amount),0) FROM " . BSXMH_DB::table( 'payments' ) . " WHERE user_id=%d AND status='paid' AND payment_type='extra_contribution'", $user_id ) );
    }

    public static function fund_summary(): array {
        global $wpdb;
        $rows = array();
        foreach ( self::funds() as $fund ) {
            $collected = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(i.amount),0) FROM " . BSXMH_DB::table( 'payment_items' ) . " i INNER JOIN " . BSXMH_DB::table( 'payments' ) . " p ON p.id=i.payment_id WHERE i.fund_id=%d AND p.status='paid'", $fund->id ) );
            $spent = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(amount),0) FROM " . BSXMH_DB::table( 'expenses' ) . " WHERE fund_id=%d AND status='paid'", $fund->id ) );
            $rows[] = array( 'fund' => $fund, 'collected' => $collected, 'spent' => $spent, 'balance' => (float) $fund->opening_balance + $collected - $spent );
        }
        return $rows;
    }

    private static function log( string $action, string $type, int $id, array $details ): void {
        global $wpdb;
        $wpdb->insert( BSXMH_DB::table( 'activity_logs' ), array(
            'actor_user_id' => get_current_user_id(), 'action' => $action, 'object_type' => $type, 'object_id' => $id,
            'details' => wp_json_encode( $details ), 'created_at' => current_time( 'mysql' ),
        ) );
    }
}
