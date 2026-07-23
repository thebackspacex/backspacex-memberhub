<?php

defined( 'ABSPATH' ) || exit;

final class BSXMH_Events {
    public static function all( bool $public_only = false ): array {
        global $wpdb;

        // Always fetch the rows first and apply public visibility rules in PHP.
        // This avoids older installs / strict SQL modes returning an empty list
        // because of status or DATE comparisons in the database query.
        $sql = 'SELECT e.*, f.name AS fund_name FROM ' . BSXMH_DB::table( 'events' ) . ' e LEFT JOIN ' . BSXMH_DB::table( 'funds' ) . ' f ON f.id=e.fund_id ORDER BY e.created_at DESC, e.id DESC';
        $events = $wpdb->get_results( $sql );

        if ( ! is_array( $events ) ) {
            return array();
        }
        if ( ! $public_only ) {
            return $events;
        }

        $today     = current_time( 'Y-m-d' );
        $logged_in = is_user_logged_in();

        return array_values( array_filter( $events, static function ( $event ) use ( $today, $logged_in ): bool {
            $status = strtolower( trim( (string) ( $event->status ?? '' ) ) );
            $active_statuses = array( 'active', 'publish', 'published', 'open', 'enabled', '1' );
            if ( ! in_array( $status, $active_statuses, true ) ) {
                return false;
            }

            $visibility = strtolower( trim( (string) ( $event->visibility ?? 'public' ) ) );
            if ( '' === $visibility ) {
                $visibility = 'public';
            }

            if ( in_array( $visibility, array( 'hidden', 'admin', 'private' ), true ) ) {
                return false;
            }
            if ( in_array( $visibility, array( 'member', 'members', 'member-only', 'members-only', 'logged-in' ), true ) && ! $logged_in ) {
                return false;
            }
            if ( ! in_array( $visibility, array( 'public', 'member', 'members', 'member-only', 'members-only', 'logged-in' ), true ) ) {
                return false;
            }

            $start = trim( (string) ( $event->start_date ?? '' ) );
            $end   = trim( (string) ( $event->end_date ?? '' ) );
            $start = $start ? substr( $start, 0, 10 ) : '';
            $end   = $end ? substr( $end, 0, 10 ) : '';

            if ( $start && '0000-00-00' !== $start && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) && $start > $today ) {
                return false;
            }
            if ( $end && '0000-00-00' !== $end && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end ) && $end < $today ) {
                return false;
            }

            return true;
        } ) );
    }

    public static function get( int $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( 'SELECT e.*, f.name AS fund_name FROM ' . BSXMH_DB::table( 'events' ) . ' e LEFT JOIN ' . BSXMH_DB::table( 'funds' ) . ' f ON f.id=e.fund_id WHERE e.id=%d', $id ) );
    }

    public static function get_by_slug( string $slug ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( 'SELECT e.*, f.name AS fund_name FROM ' . BSXMH_DB::table( 'events' ) . ' e LEFT JOIN ' . BSXMH_DB::table( 'funds' ) . ' f ON f.id=e.fund_id WHERE e.slug=%s', sanitize_title( $slug ) ) );
    }

    public static function save( array $data ) {
        global $wpdb;
        $id = absint( $data['event_id'] ?? 0 );
        $title = sanitize_text_field( wp_unslash( $data['title'] ?? '' ) );
        if ( '' === $title ) return new WP_Error( 'event_title', __( 'Event title is required.', 'bsx-memberhub' ) );
        $fund = BSXMH_Contributions::get_fund( absint( $data['fund_id'] ?? 0 ) );
        if ( ! $fund ) return new WP_Error( 'event_fund', __( 'Please select a valid linked fund.', 'bsx-memberhub' ) );
        $start = sanitize_text_field( wp_unslash( $data['start_date'] ?? '' ) );
        $end = sanitize_text_field( wp_unslash( $data['end_date'] ?? '' ) );
        $requested_slug = sanitize_title( wp_unslash( $data['slug'] ?? '' ) );
        $slug = self::unique_event_slug( $requested_slug ?: sanitize_title( $title ), $id );
        $record = array(
            'fund_id' => (int) $fund->id,
            'title' => $title,
            'slug' => $slug,
            'description' => wp_kses_post( wp_unslash( $data['description'] ?? '' ) ),
            'image_id' => absint( $data['image_id'] ?? 0 ),
            'target_amount' => number_format( max( 0, (float) ( $data['target_amount'] ?? 0 ) ), 2, '.', '' ),
            'start_date' => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) ? $start : null,
            'end_date' => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end ) ? $end : null,
            'visibility' => in_array( $data['visibility'] ?? '', array( 'public', 'members', 'admin', 'hidden' ), true ) ? $data['visibility'] : 'public',
            'status' => in_array( $data['status'] ?? '', array( 'draft', 'active', 'inactive', 'closed' ), true ) ? $data['status'] : 'active',
            'updated_at' => current_time( 'mysql' ),
        );
        if ( $id ) $ok = $wpdb->update( BSXMH_DB::table( 'events' ), $record, array( 'id' => $id ) );
        else { $record['created_by'] = get_current_user_id(); $record['created_at'] = current_time( 'mysql' ); $ok = $wpdb->insert( BSXMH_DB::table( 'events' ), $record ); $id = (int) $wpdb->insert_id; }
        if ( false === $ok ) return new WP_Error( 'event_db', __( 'Event could not be saved. The slug may already exist.', 'bsx-memberhub' ) );
        self::log( 'event_saved', 'event', $id, $record );
        return $id;
    }


    private static function unique_event_slug( string $slug, int $exclude_id = 0 ): string {
        global $wpdb;
        $table = BSXMH_DB::table( 'events' );
        $base = $slug ?: 'event';
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

    public static function create_donation( array $data ) {
        global $wpdb;
        $event = self::get( absint( $data['event_id'] ?? 0 ) );
        if ( ! $event ) return new WP_Error( 'event_missing', __( 'Please select a valid event.', 'bsx-memberhub' ) );
        if ( 'active' !== $event->status ) return new WP_Error( 'event_closed', __( 'This event is not accepting donations.', 'bsx-memberhub' ) );
        $today = current_time( 'Y-m-d' );
        if ( $event->end_date && $event->end_date < $today ) return new WP_Error( 'event_expired', __( 'This event has ended.', 'bsx-memberhub' ) );
        $amount = max( 0, (float) ( $data['amount'] ?? 0 ) );
        if ( $amount <= 0 ) return new WP_Error( 'amount', __( 'Donation amount must be greater than zero.', 'bsx-memberhub' ) );
        $member_id = absint( $data['member_id'] ?? 0 ); $member = $member_id ? BSXMH_Members::get( $member_id ) : null;
        $guest_name = sanitize_text_field( wp_unslash( $data['guest_name'] ?? '' ) );
        if ( ! $member && '' === $guest_name ) return new WP_Error( 'donor', __( 'Select a member or enter the guest donor name.', 'bsx-memberhub' ) );
        $reference = sanitize_text_field( wp_unslash( $data['reference_number'] ?? '' ) );
        if ( $reference ) {
            $like = '%"reference":"' . $wpdb->esc_like( $reference ) . '"%';
            $duplicate = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM " . BSXMH_DB::table( 'payments' ) . " WHERE payment_type='event_donation' AND metadata LIKE %s LIMIT 1", $like ) );
            if ( $duplicate && empty( $data['duplicate_override'] ) ) return new WP_Error( 'duplicate_reference', __( 'This reference number already exists.', 'bsx-memberhub' ) );
        }
        $date = sanitize_text_field( wp_unslash( $data['payment_date'] ?? $today ) ); if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) $date = $today;
        $meta = array(
            'method' => sanitize_key( $data['payment_method'] ?? 'cash' ), 'reference' => $reference,
            'notes' => sanitize_textarea_field( wp_unslash( $data['notes'] ?? '' ) ), 'received_by' => sanitize_text_field( wp_unslash( $data['received_by'] ?? '' ) ),
            'event_id' => (int) $event->id, 'event_title' => $event->title, 'guest_name' => $guest_name,
            'guest_email' => sanitize_email( wp_unslash( $data['guest_email'] ?? '' ) ), 'guest_mobile' => sanitize_text_field( wp_unslash( $data['guest_mobile'] ?? '' ) ),
            'anonymous' => ! empty( $data['anonymous'] ), 'duplicate_override' => ! empty( $data['duplicate_override'] ),
        );
        $now = current_time( 'mysql' );
        $wpdb->insert( BSXMH_DB::table( 'payments' ), array(
            'transaction_id' => BSXMH_Payments::transaction_id(), 'user_id' => $member ? (int) $member->user_id : null,
            'payment_type' => 'event_donation', 'gateway' => 'manual', 'currency' => strtoupper( sanitize_key( get_option( 'bsxmh_settings', array() )['currency'] ?? 'BDT' ) ),
            'subtotal' => number_format( $amount, 2, '.', '' ), 'fee_amount' => '0.00', 'total_amount' => number_format( $amount, 2, '.', '' ),
            'status' => 'paid', 'payment_date' => $date . ' ' . current_time( 'H:i:s' ), 'metadata' => wp_json_encode( $meta ),
            'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now,
        ) );
        $payment_id = (int) $wpdb->insert_id; if ( ! $payment_id ) return new WP_Error( 'payment_db', __( 'Donation could not be saved.', 'bsx-memberhub' ) );
        $wpdb->insert( BSXMH_DB::table( 'payment_items' ), array(
            'payment_id' => $payment_id, 'item_type' => 'event_donation', 'reference_id' => (int) $event->id,
            'description' => 'Event Donation — ' . $event->title, 'amount' => number_format( $amount, 2, '.', '' ), 'fund_id' => (int) $event->fund_id, 'created_at' => $now,
        ) );
        BSXMH_Receipts::create_for_payment( $payment_id );
        do_action( 'bsxmh_payment_completed', $payment_id ); self::log( 'event_donation_created', 'payment', $payment_id, array( 'event_id'=>(int)$event->id, 'amount'=>$amount ) );
        return $payment_id;
    }

    public static function stats( int $event_id ): array {
        global $wpdb;
        $collected = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(i.amount),0) FROM " . BSXMH_DB::table( 'payment_items' ) . " i INNER JOIN " . BSXMH_DB::table( 'payments' ) . " p ON p.id=i.payment_id WHERE i.item_type='event_donation' AND i.reference_id=%d AND p.status='paid'", $event_id ) );
        $donors = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT p.id) FROM " . BSXMH_DB::table( 'payment_items' ) . " i INNER JOIN " . BSXMH_DB::table( 'payments' ) . " p ON p.id=i.payment_id WHERE i.item_type='event_donation' AND i.reference_id=%d AND p.status='paid'", $event_id ) );
        $event = self::get( $event_id ); $target = $event ? (float) $event->target_amount : 0; $percent = $target > 0 ? min( 100, ( $collected / $target ) * 100 ) : 0;
        return array( 'collected'=>$collected, 'donors'=>$donors, 'target'=>$target, 'remaining'=>max(0,$target-$collected), 'percent'=>$percent );
    }

    public static function member_total( int $user_id ): float { global $wpdb; return (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(total_amount),0) FROM " . BSXMH_DB::table( 'payments' ) . " WHERE user_id=%d AND status='paid' AND payment_type='event_donation'", $user_id ) ); }

    private static function log( string $action, string $type, int $id, array $details ): void { global $wpdb; $wpdb->insert( BSXMH_DB::table( 'activity_logs' ), array( 'actor_user_id'=>get_current_user_id(), 'action'=>$action, 'object_type'=>$type, 'object_id'=>$id, 'details'=>wp_json_encode($details), 'created_at'=>current_time('mysql') ) ); }
}
