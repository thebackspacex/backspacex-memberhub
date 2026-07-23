<?php

defined( 'ABSPATH' ) || exit;

final class BSXMH_Members {
    public static function generate_member_number(): string {
        global $wpdb;
        $settings = get_option( 'bsxmh_settings', array() );
        $prefix = strtoupper( sanitize_key( $settings['member_id_prefix'] ?? 'MH' ) );
        $prefix = $prefix ?: 'MH';
        $last_id = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . BSXMH_DB::table( 'members' ) );
        $sequence = $last_id + 1;
        do {
            $number = sprintf( '%s-%05d', $prefix, $sequence );
            $exists = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . BSXMH_DB::table( 'members' ) . ' WHERE member_number=%s', $number ) );
            $sequence++;
        } while ( $exists );
        return $number;
    }

    public static function create( array $data ) {
        global $wpdb;
        $settings = get_option( 'bsxmh_settings', array() );
        $email = sanitize_email( $data['email'] ?? '' );
        $name = sanitize_text_field( $data['display_name'] ?? '' );
        if ( ! is_email( $email ) ) {
            return new WP_Error( 'invalid_email', __( 'A valid email address is required.', 'bsx-memberhub' ) );
        }
        if ( email_exists( $email ) ) {
            return new WP_Error( 'email_exists', __( 'An account already exists with this email address.', 'bsx-memberhub' ) );
        }
        if ( '' === $name ) {
            return new WP_Error( 'missing_name', __( 'Member name is required.', 'bsx-memberhub' ) );
        }

        $base = sanitize_user( strtolower( strtok( $email, '@' ) ), true ) ?: 'member';
        $username = $base;
        $suffix = 1;
        while ( username_exists( $username ) ) {
            $username = $base . $suffix;
            $suffix++;
        }
        $password = (string) ( $data['password'] ?? '' );
        $generated_password = '' === $password;
        if ( $generated_password ) {
            $password = wp_generate_password( 14, true );
        }

        $user_id = wp_insert_user( array(
            'user_login'   => $username,
            'user_email'   => $email,
            'display_name' => $name,
            'first_name'   => sanitize_text_field( $data['first_name'] ?? $name ),
            'last_name'    => sanitize_text_field( $data['last_name'] ?? '' ),
            'user_pass'    => $password,
            'role'         => sanitize_key( $settings['default_registration_role'] ?? 'bsxmh_member' ),
        ) );
        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }

        $status = sanitize_key( $data['status'] ?? 'pending' );
        if ( ! in_array( $status, array( 'pending', 'active', 'inactive', 'suspended' ), true ) ) {
            $status = 'pending';
        }
        $join_date = self::valid_date( $data['join_date'] ?? '' ) ?: current_time( 'Y-m-d' );
        $fee_start_date = self::valid_date( $data['fee_start_date'] ?? '' ) ?: $join_date;
        $monthly_fee = isset( $data['monthly_fee'] ) && '' !== $data['monthly_fee'] ? max( 0, (float) $data['monthly_fee'] ) : (float) ( $settings['default_monthly_fee'] ?? 100 );
        $now = current_time( 'mysql' );

        $inserted = $wpdb->insert(
            BSXMH_DB::table( 'members' ),
            array(
                'user_id'        => $user_id,
                'member_number'  => self::generate_member_number(),
                'category_id'    => ! empty( $data['category_id'] ) ? absint( $data['category_id'] ) : null,
                'status'         => $status,
                'join_date'      => $join_date,
                'fee_start_date' => $fee_start_date,
                'monthly_fee'    => number_format( $monthly_fee, 2, '.', '' ),
                'profile_data'   => wp_json_encode( array( 'phone' => sanitize_text_field( $data['phone'] ?? '' ) ) ),
                'admin_notes'    => sanitize_textarea_field( $data['admin_notes'] ?? '' ),
                'created_at'     => $now,
                'updated_at'     => $now,
            ),
            array( '%d', '%s', '%d', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s' )
        );
        if ( false === $inserted ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user( $user_id );
            return new WP_Error( 'db_error', __( 'The member record could not be created.', 'bsx-memberhub' ) );
        }

        update_user_meta( $user_id, 'bsxmh_member_status', $status );
        update_user_meta( $user_id, 'bsxmh_phone', sanitize_text_field( $data['phone'] ?? '' ) );
        if ( $generated_password && ! empty( $data['send_notification'] ) ) {
            wp_new_user_notification( $user_id, null, 'user' );
        }
        self::log( $user_id, 'member_created', array( 'status' => $status ) );
        return $user_id;
    }

    public static function update( int $member_id, array $data ) {
        global $wpdb;
        $member = self::get( $member_id );
        if ( ! $member ) {
            return new WP_Error( 'not_found', __( 'Member not found.', 'bsx-memberhub' ) );
        }
        $email = sanitize_email( $data['email'] ?? '' );
        $name = sanitize_text_field( $data['display_name'] ?? '' );
        if ( ! is_email( $email ) || '' === $name ) {
            return new WP_Error( 'invalid_data', __( 'A valid name and email address are required.', 'bsx-memberhub' ) );
        }
        $existing = email_exists( $email );
        if ( $existing && (int) $existing !== (int) $member->user_id ) {
            return new WP_Error( 'email_exists', __( 'This email address belongs to another user.', 'bsx-memberhub' ) );
        }
        $user_result = wp_update_user( array(
            'ID'           => (int) $member->user_id,
            'user_email'   => $email,
            'display_name' => $name,
            'first_name'   => sanitize_text_field( $data['first_name'] ?? $name ),
            'last_name'    => sanitize_text_field( $data['last_name'] ?? '' ),
        ) );
        if ( is_wp_error( $user_result ) ) {
            return $user_result;
        }
        $status = sanitize_key( $data['status'] ?? 'pending' );
        if ( ! in_array( $status, array( 'pending', 'active', 'inactive', 'suspended' ), true ) ) {
            $status = 'pending';
        }
        $profile = json_decode( (string) $member->profile_data, true );
        $profile = is_array( $profile ) ? $profile : array();
        $profile['phone'] = sanitize_text_field( $data['phone'] ?? '' );
        $updated = $wpdb->update(
            BSXMH_DB::table( 'members' ),
            array(
                'category_id'    => ! empty( $data['category_id'] ) ? absint( $data['category_id'] ) : null,
                'status'         => $status,
                'join_date'      => self::valid_date( $data['join_date'] ?? '' ),
                'fee_start_date' => self::valid_date( $data['fee_start_date'] ?? '' ),
                'monthly_fee'    => number_format( max( 0, (float) ( $data['monthly_fee'] ?? 0 ) ), 2, '.', '' ),
                'profile_data'   => wp_json_encode( $profile ),
                'admin_notes'    => sanitize_textarea_field( $data['admin_notes'] ?? '' ),
                'updated_at'     => current_time( 'mysql' ),
            ),
            array( 'id' => $member_id ),
            array( '%d', '%s', '%s', '%s', '%f', '%s', '%s', '%s' ),
            array( '%d' )
        );
        if ( false === $updated ) {
            return new WP_Error( 'db_error', __( 'The member could not be updated.', 'bsx-memberhub' ) );
        }
        update_user_meta( (int) $member->user_id, 'bsxmh_member_status', $status );
        update_user_meta( (int) $member->user_id, 'bsxmh_phone', $profile['phone'] );
        self::log( (int) $member->user_id, 'member_updated', array( 'status' => $status ) );
        return true;
    }

    public static function get( int $member_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . BSXMH_DB::table( 'members' ) . ' WHERE id=%d', $member_id ) );
    }

    public static function get_by_user( int $user_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . BSXMH_DB::table( 'members' ) . ' WHERE user_id=%d', $user_id ) );
    }

    private static function valid_date( string $date ): ?string {
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            return null;
        }
        $parts = array_map( 'intval', explode( '-', $date ) );
        return checkdate( $parts[1], $parts[2], $parts[0] ) ? $date : null;
    }

    private static function log( int $user_id, string $action, array $details ): void {
        global $wpdb;
        $wpdb->insert( BSXMH_DB::table( 'activity_logs' ), array(
            'actor_user_id'  => get_current_user_id() ?: null,
            'target_user_id' => $user_id,
            'action'         => $action,
            'object_type'    => 'member',
            'object_id'      => $user_id,
            'details'        => wp_json_encode( $details ),
            'ip_hash'        => isset( $_SERVER['REMOTE_ADDR'] ) ? hash( 'sha256', sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) . wp_salt( 'auth' ) ) : '',
            'created_at'     => current_time( 'mysql' ),
        ) );
    }
}
