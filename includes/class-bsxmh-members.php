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
        if ( ! in_array( $status, array( 'pending', 'active', 'inactive', 'suspended', 'deleted' ), true ) ) {
            $status = 'pending';
        }
        $join_date = self::valid_date( $data['join_date'] ?? '' ) ?: current_time( 'Y-m-d' );
        $fee_start_date = self::valid_date( $data['fee_start_date'] ?? '' ) ?: $join_date;
        $monthly_fee = isset( $data['monthly_fee'] ) && '' !== $data['monthly_fee'] ? max( 0, (float) $data['monthly_fee'] ) : (float) ( $settings['default_monthly_fee'] ?? 100 );
        $membership_fund_id = absint( $data['membership_fund_id'] ?? 0 );
        $fund = $membership_fund_id ? BSXMH_Contributions::get_fund( $membership_fund_id ) : null;
        if ( ! $fund || 'active' !== $fund->status ) $membership_fund_id = BSXMH_Contributions::default_membership_fund_id();
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
                'membership_fund_id' => $membership_fund_id ?: null,
                'profile_data'   => wp_json_encode( array(
                    'phone' => sanitize_text_field( $data['phone'] ?? '' ),
                    'profile_photo_id' => absint( $data['profile_photo_id'] ?? 0 ),
                    'tags' => self::sanitize_tags( $data['member_tags'] ?? '' ),
                ) ),
                'admin_notes'    => sanitize_textarea_field( $data['admin_notes'] ?? '' ),
                'created_at'     => $now,
                'updated_at'     => $now,
            ),
            array( '%d', '%s', '%d', '%s', '%s', '%s', '%f', '%d', '%s', '%s', '%s', '%s' )
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
        if ( ! in_array( $status, array( 'pending', 'active', 'inactive', 'suspended', 'deleted' ), true ) ) {
            $status = 'pending';
        }
        $profile = json_decode( (string) $member->profile_data, true );
        $profile = is_array( $profile ) ? $profile : array();
        $profile['phone'] = sanitize_text_field( $data['phone'] ?? '' );
        if ( array_key_exists( 'member_tags', $data ) ) {
            $profile['tags'] = self::sanitize_tags( $data['member_tags'] );
        }
        if ( ! empty( $data['remove_profile_photo'] ) ) {
            $profile['profile_photo_id'] = 0;
        } elseif ( isset( $data['profile_photo_id'] ) ) {
            $profile['profile_photo_id'] = absint( $data['profile_photo_id'] );
        }
        $membership_fund_id = absint( $data['membership_fund_id'] ?? 0 );
        $fund = $membership_fund_id ? BSXMH_Contributions::get_fund( $membership_fund_id ) : null;
        if ( ! $fund || 'active' !== $fund->status ) $membership_fund_id = BSXMH_Contributions::default_membership_fund_id();
        $updated = $wpdb->update(
            BSXMH_DB::table( 'members' ),
            array(
                'category_id'    => ! empty( $data['category_id'] ) ? absint( $data['category_id'] ) : null,
                'status'         => $status,
                'join_date'      => self::valid_date( $data['join_date'] ?? '' ),
                'fee_start_date' => self::valid_date( $data['fee_start_date'] ?? '' ),
                'monthly_fee'    => number_format( max( 0, (float) ( $data['monthly_fee'] ?? 0 ) ), 2, '.', '' ),
                'membership_fund_id' => $membership_fund_id ?: null,
                'profile_data'   => wp_json_encode( $profile ),
                'admin_notes'    => sanitize_textarea_field( $data['admin_notes'] ?? '' ),
                'updated_at'     => current_time( 'mysql' ),
            ),
            array( 'id' => $member_id ),
            array( '%d', '%s', '%s', '%s', '%f', '%d', '%s', '%s', '%s' ),
            array( '%d' )
        );
        if ( false === $updated ) {
            return new WP_Error( 'db_error', __( 'The member could not be updated.', 'bsx-memberhub' ) );
        }
        update_user_meta( (int) $member->user_id, 'bsxmh_member_status', $status );
        update_user_meta( (int) $member->user_id, 'bsxmh_phone', $profile['phone'] );
        self::log( (int) $member->user_id, 'member_updated', array( 'old_status' => (string) $member->status, 'status' => $status ) );
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


    /**
     * Preserve the MemberHub record and financial history when its linked
     * WordPress account is deleted.
     */
    public static function handle_deleted_user( int $user_id, ?int $reassign = null, $user = null ): void {
        global $wpdb;
        $member = self::get_by_user( $user_id );
        if ( ! $member ) {
            return;
        }
        $profile = json_decode( (string) $member->profile_data, true );
        $profile = is_array( $profile ) ? $profile : array();
        if ( $user instanceof WP_User ) {
            $profile['deleted_display_name'] = sanitize_text_field( $user->display_name );
            $profile['deleted_email'] = sanitize_email( $user->user_email );
            $phone = get_user_meta( $user_id, 'bsxmh_phone', true );
            if ( $phone ) {
                $profile['phone'] = sanitize_text_field( $phone );
            }
        }
        $now = current_time( 'mysql' );
        $wpdb->update(
            BSXMH_DB::table( 'members' ),
            array(
                'status'       => 'deleted',
                'profile_data' => wp_json_encode( $profile ),
                'deleted_at'   => $now,
                'updated_at'   => $now,
            ),
            array( 'id' => (int) $member->id ),
            array( '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );
        self::log( $user_id, 'wordpress_user_deleted', array(
            'member_id' => (int) $member->id,
            'reassigned_to' => $reassign,
            'history_preserved' => true,
        ) );
    }

    /** Return MemberHub records whose WordPress account no longer exists. */
    public static function orphan_members(): array {
        global $wpdb;
        return (array) $wpdb->get_results(
            "SELECT m.* FROM " . BSXMH_DB::table( 'members' ) . " m LEFT JOIN {$wpdb->users} u ON u.ID=m.user_id WHERE u.ID IS NULL AND m.status <> 'deleted' ORDER BY m.id ASC"
        );
    }

    /** Mark orphan records as deleted while preserving all related history. */
    public static function repair_orphans(): int {
        global $wpdb;
        $count = 0;
        foreach ( self::orphan_members() as $member ) {
            $now = current_time( 'mysql' );
            $updated = $wpdb->update(
                BSXMH_DB::table( 'members' ),
                array( 'status' => 'deleted', 'deleted_at' => $now, 'updated_at' => $now ),
                array( 'id' => (int) $member->id ),
                array( '%s', '%s', '%s' ),
                array( '%d' )
            );
            if ( false !== $updated ) {
                $count++;
                self::log( (int) $member->user_id, 'orphan_member_repaired', array( 'member_id' => (int) $member->id, 'history_preserved' => true ) );
            }
        }
        return $count;
    }

    public static function display_snapshot( $member ): array {
        $user = get_userdata( (int) $member->user_id );
        $profile = json_decode( (string) $member->profile_data, true );
        $profile = is_array( $profile ) ? $profile : array();
        return array(
            'name' => $user ? $user->display_name : ( $profile['deleted_display_name'] ?? $member->member_number ),
            'email' => $user ? $user->user_email : ( $profile['deleted_email'] ?? '' ),
            'phone' => $user ? (string) get_user_meta( (int) $member->user_id, 'bsxmh_phone', true ) : (string) ( $profile['phone'] ?? '' ),
        );
    }

    public static function profile_data( $member ): array {
        $profile = json_decode( (string) ( $member->profile_data ?? '' ), true );
        return is_array( $profile ) ? $profile : array();
    }

    public static function sanitize_tags( $value ): array {
        $raw = is_array( $value ) ? $value : preg_split( '/[,\r\n]+/', (string) $value );
        $tags = array();
        foreach ( (array) $raw as $tag ) {
            $tag = sanitize_text_field( trim( (string) $tag ) );
            if ( '' !== $tag ) { $tags[ strtolower( $tag ) ] = $tag; }
        }
        return array_values( $tags );
    }

    public static function tags( $member ): array {
        $profile = json_decode( (string) ( $member->profile_data ?? '' ), true );
        return self::sanitize_tags( is_array( $profile ) ? ( $profile['tags'] ?? array() ) : array() );
    }

    public static function set_profile_photo( int $member_id, int $attachment_id, bool $remove = false ) {
        global $wpdb;
        $member = self::get( $member_id );
        if ( ! $member ) return new WP_Error( 'not_found', __( 'Member not found.', 'bsx-memberhub' ) );
        $profile = json_decode( (string) $member->profile_data, true );
        $profile = is_array( $profile ) ? $profile : array();
        $old = absint( $profile['profile_photo_id'] ?? 0 );
        $profile['profile_photo_id'] = $remove ? 0 : absint( $attachment_id );
        $ok = $wpdb->update(
            BSXMH_DB::table( 'members' ),
            array( 'profile_data' => wp_json_encode( $profile ), 'updated_at' => current_time( 'mysql' ) ),
            array( 'id' => $member_id ), array( '%s', '%s' ), array( '%d' )
        );
        if ( false === $ok ) return new WP_Error( 'db_error', __( 'Profile photo could not be saved.', 'bsx-memberhub' ) );
        self::log( (int) $member->user_id, $remove ? 'profile_photo_removed' : 'profile_photo_updated', array( 'old_attachment_id' => $old, 'attachment_id' => $remove ? 0 : absint( $attachment_id ), 'source' => is_admin() ? 'admin' : 'member_portal' ) );
        return true;
    }

    public static function profile_photo_id( $member ): int {
        $profile = self::profile_data( $member );
        return absint( $profile['profile_photo_id'] ?? 0 );
    }

    public static function profile_photo_html( $member, int $size = 120, string $class = '' ): string {
        $attachment_id = self::profile_photo_id( $member );
        if ( $attachment_id ) {
            $image = wp_get_attachment_image( $attachment_id, array( $size, $size ), false, array(
                'class' => trim( 'bsxmh-member-photo ' . $class ),
                'alt'   => esc_attr__( 'Member profile photo', 'bsx-memberhub' ),
            ) );
            if ( $image ) {
                return $image;
            }
        }
        return get_avatar( (int) ( $member->user_id ?? 0 ), $size, '', '', array( 'class' => trim( 'bsxmh-member-photo ' . $class ) ) );
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
