<?php

defined( 'ABSPATH' ) || exit;

final class BSXMH_Payments {
    public static function currency_symbol(): string {
        $settings = get_option( 'bsxmh_settings', array() );
        return 'BDT' === ( $settings['currency'] ?? 'BDT' ) ? '৳' : (string) ( $settings['currency'] ?? 'BDT' ) . ' ';
    }

    public static function month_label( int $year, int $month ): string {
        return wp_date( 'F Y', mktime( 0, 0, 0, $month, 1, $year ) );
    }

    public static function eligible_months( $member, ?string $through = null ): array {
        $settings = get_option( 'bsxmh_settings', array() );
        $org_start = sprintf( '%04d-%02d-01', max( 1900, (int) ( $settings['organization_start_year'] ?? current_time( 'Y' ) ) ), min( 12, max( 1, (int) ( $settings['organization_start_month'] ?? 1 ) ) ) );
        $member_start = ! empty( $member->fee_start_date ) ? substr( (string) $member->fee_start_date, 0, 7 ) . '-01' : $org_start;
        $start = max( $org_start, $member_start );
        $end = $through && preg_match( '/^\d{4}-\d{2}$/', $through ) ? $through . '-01' : current_time( 'Y-m' ) . '-01';
        if ( $start > $end ) {
            return array();
        }
        $months = array();
        $cursor = new DateTimeImmutable( $start, wp_timezone() );
        $last = new DateTimeImmutable( $end, wp_timezone() );
        while ( $cursor <= $last ) {
            $months[] = array( 'year' => (int) $cursor->format( 'Y' ), 'month' => (int) $cursor->format( 'n' ), 'key' => $cursor->format( 'Y-m' ) );
            $cursor = $cursor->modify( '+1 month' );
        }
        return $months;
    }

    public static function paid_month_keys( int $user_id, int $member_id = 0 ): array {
        global $wpdb;
        $items = BSXMH_DB::table( 'payment_items' );
        $payments = BSXMH_DB::table( 'payments' );
        if ( $member_id > 0 ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT i.period_year, i.period_month FROM {$items} i INNER JOIN {$payments} p ON p.id=i.payment_id WHERE (p.user_id=%d OR i.reference_id=%d) AND p.status='paid' AND p.payment_type='membership' AND i.item_type='membership' AND i.period_year IS NOT NULL AND i.period_month IS NOT NULL",
                $user_id,
                $member_id
            ) );
        } else {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT i.period_year, i.period_month FROM {$items} i INNER JOIN {$payments} p ON p.id=i.payment_id WHERE p.user_id=%d AND p.status='paid' AND p.payment_type='membership' AND i.item_type='membership' AND i.period_year IS NOT NULL AND i.period_month IS NOT NULL",
                $user_id
            ) );
        }
        $keys = array();
        foreach ( $rows as $row ) {
            $keys[] = sprintf( '%04d-%02d', (int) $row->period_year, (int) $row->period_month );
        }
        return array_values( array_unique( $keys ) );
    }

    public static function statement( $member ): array {
        $eligible = self::eligible_months( $member );
        $paid_keys = self::paid_month_keys( (int) $member->user_id, (int) $member->id );
        $paid = array();
        $due = array();
        foreach ( $eligible as $period ) {
            if ( in_array( $period['key'], $paid_keys, true ) ) {
                $paid[] = $period;
            } else {
                $due[] = $period;
            }
        }
        $advance = array();
        $current = current_time( 'Y-m' );
        foreach ( $paid_keys as $key ) {
            if ( $key > $current ) {
                list( $y, $m ) = array_map( 'intval', explode( '-', $key ) );
                $advance[] = array( 'year' => $y, 'month' => $m, 'key' => $key );
            }
        }
        usort( $advance, static fn( $a, $b ) => strcmp( $a['key'], $b['key'] ) );
        $fee = (float) $member->monthly_fee;
        $total_paid = self::member_total_paid( (int) $member->user_id, (int) $member->id );
        return array(
            'eligible' => $eligible,
            'paid' => $paid,
            'due' => $due,
            'advance' => $advance,
            'monthly_fee' => $fee,
            'total_due' => count( $due ) * $fee,
            'total_paid' => $total_paid,
        );
    }

    /**
     * Backward-compatible statement helper accepting a MemberHub member ID.
     *
     * @param int $member_id MemberHub members-table ID.
     * @return array<string,mixed>
     */
    public static function member_statement( int $member_id ): array {
        $member = BSXMH_Members::get( $member_id );
        if ( ! $member ) {
            return array(
                'eligible' => array(),
                'paid' => array(),
                'due' => array(),
                'advance' => array(),
                'monthly_fee' => 0.0,
                'total_due' => 0.0,
                'total_paid' => 0.0,
            );
        }
        return self::statement( $member );
    }

    public static function member_total_paid( int $user_id, int $member_id = 0 ): float {
        global $wpdb;
        $payments = BSXMH_DB::table( 'payments' );
        if ( $member_id <= 0 ) {
            return (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(total_amount),0) FROM {$payments} WHERE user_id=%d AND status='paid' AND payment_type='membership'", $user_id ) );
        }
        $items = BSXMH_DB::table( 'payment_items' );
        return (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(x.total_amount),0) FROM (SELECT DISTINCT p.id,p.total_amount FROM {$payments} p LEFT JOIN {$items} i ON i.payment_id=p.id WHERE p.status='paid' AND p.payment_type='membership' AND (p.user_id=%d OR (i.item_type='membership' AND i.reference_id=%d))) x",
            $user_id,
            $member_id
        ) );
    }

    public static function create_manual( array $data ) {
        global $wpdb;
        $member_id = absint( $data['member_id'] ?? 0 );
        $member = BSXMH_Members::get( $member_id );
        if ( ! $member ) {
            return new WP_Error( 'member_missing', __( 'Please select a valid member.', 'bsx-memberhub' ) );
        }
        $periods = isset( $data['periods'] ) && is_array( $data['periods'] ) ? array_values( array_unique( array_map( 'sanitize_text_field', wp_unslash( $data['periods'] ) ) ) ) : array();
        $valid_periods = array();
        foreach ( $periods as $period ) {
            if ( preg_match( '/^(\d{4})-(0[1-9]|1[0-2])$/', $period, $m ) ) {
                $valid_periods[] = array( 'key' => $period, 'year' => (int) $m[1], 'month' => (int) $m[2] );
            }
        }
        if ( empty( $valid_periods ) ) {
            return new WP_Error( 'period_missing', __( 'Select at least one payment month.', 'bsx-memberhub' ) );
        }
        $existing = self::paid_month_keys( (int) $member->user_id );
        $duplicates = array();
        foreach ( $valid_periods as $period ) {
            if ( in_array( $period['key'], $existing, true ) ) {
                $duplicates[] = self::month_label( $period['year'], $period['month'] );
            }
        }
        $override = ! empty( $data['duplicate_override'] );
        if ( $duplicates && ! $override ) {
            return new WP_Error( 'duplicate_period', sprintf( __( 'Already paid: %s. Enable the duplicate override only when an adjustment is intentional.', 'bsx-memberhub' ), implode( ', ', $duplicates ) ) );
        }
        $amount = isset( $data['amount'] ) && '' !== $data['amount'] ? max( 0, (float) $data['amount'] ) : count( $valid_periods ) * (float) $member->monthly_fee;
        if ( $amount <= 0 ) {
            return new WP_Error( 'invalid_amount', __( 'Payment amount must be greater than zero.', 'bsx-memberhub' ) );
        }
        $payment_date = self::valid_datetime( (string) ( $data['payment_date'] ?? '' ) ) ?: current_time( 'mysql' );
        $transaction_id = self::transaction_id();
        $metadata = array(
            'method' => sanitize_key( $data['payment_method'] ?? 'cash' ),
            'reference' => sanitize_text_field( $data['reference_number'] ?? '' ),
            'notes' => sanitize_textarea_field( $data['notes'] ?? '' ),
            'received_by' => sanitize_text_field( $data['received_by'] ?? '' ),
            'duplicate_override' => $override,
            'duplicate_periods' => $duplicates,
        );
        $now = current_time( 'mysql' );
        $ok = $wpdb->insert( BSXMH_DB::table( 'payments' ), array(
            'transaction_id' => $transaction_id,
            'user_id' => (int) $member->user_id,
            'payment_type' => 'membership',
            'gateway' => 'manual',
            'currency' => strtoupper( sanitize_key( get_option( 'bsxmh_settings', array() )['currency'] ?? 'BDT' ) ),
            'subtotal' => number_format( $amount, 2, '.', '' ),
            'fee_amount' => '0.00',
            'total_amount' => number_format( $amount, 2, '.', '' ),
            'status' => 'paid',
            'payment_date' => $payment_date,
            'metadata' => wp_json_encode( $metadata ),
            'created_by' => get_current_user_id(),
            'created_at' => $now,
            'updated_at' => $now,
        ) );
        if ( false === $ok ) {
            return new WP_Error( 'db_error', __( 'The payment could not be saved.', 'bsx-memberhub' ) );
        }
        $payment_id = (int) $wpdb->insert_id;
        $membership_fund_id = BSXMH_Contributions::membership_fund_for_member( $member );
        $base = floor( ( $amount / count( $valid_periods ) ) * 100 ) / 100;
        $remaining = $amount;
        foreach ( $valid_periods as $index => $period ) {
            $item_amount = ( $index === count( $valid_periods ) - 1 ) ? $remaining : $base;
            $remaining -= $item_amount;
            $wpdb->insert( BSXMH_DB::table( 'payment_items' ), array(
                'payment_id' => $payment_id,
                'item_type' => 'membership',
                'reference_id' => $member_id,
                'period_year' => $period['year'],
                'period_month' => $period['month'],
                'description' => self::month_label( $period['year'], $period['month'] ),
                'amount' => number_format( $item_amount, 2, '.', '' ),
                'fund_id' => $membership_fund_id ?: null,
                'created_at' => $now,
            ) );
        }
        BSXMH_Receipts::create_for_payment( $payment_id );
        do_action( 'bsxmh_payment_completed', $payment_id );
        self::log( (int) $member->user_id, 'manual_payment_created', $payment_id, array( 'transaction_id' => $transaction_id, 'periods' => array_column( $valid_periods, 'key' ), 'amount' => $amount, 'override' => $override ) );
        return $payment_id;
    }

    public static function get( int $payment_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . BSXMH_DB::table( 'payments' ) . ' WHERE id=%d', $payment_id ) );
    }

    public static function items( int $payment_id ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . BSXMH_DB::table( 'payment_items' ) . ' WHERE payment_id=%d ORDER BY period_year, period_month, id', $payment_id ) );
    }

    public static function transaction_id(): string {
        return 'BSXMH-' . gmdate( 'YmdHis' ) . '-' . strtoupper( wp_generate_password( 6, false, false ) );
    }

    private static function valid_datetime( string $value ): ?string {
        $value = sanitize_text_field( $value );
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
            $value .= ' ' . current_time( 'H:i:s' );
        }
        $date = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, wp_timezone() );
        return $date && $date->format( 'Y-m-d H:i:s' ) === $value ? $value : null;
    }

    private static function log( int $target_user, string $action, int $object_id, array $details ): void {
        global $wpdb;
        $wpdb->insert( BSXMH_DB::table( 'activity_logs' ), array(
            'actor_user_id' => get_current_user_id() ?: null,
            'target_user_id' => $target_user,
            'action' => $action,
            'object_type' => 'payment',
            'object_id' => $object_id,
            'details' => wp_json_encode( $details ),
            'ip_hash' => isset( $_SERVER['REMOTE_ADDR'] ) ? hash( 'sha256', sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) . wp_salt( 'auth' ) ) : '',
            'created_at' => current_time( 'mysql' ),
        ) );
    }
}
