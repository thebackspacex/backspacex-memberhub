<?php

defined( 'ABSPATH' ) || exit;

final class BSXMH_Roles {
    private const ROLE_CAPS = array(
        'bsxmh_treasurer' => array( 'read', 'manage_bsxmh', 'bsxmh_manage_payments', 'bsxmh_manage_finance', 'bsxmh_view_reports' ),
        'bsxmh_accountant' => array( 'read', 'manage_bsxmh', 'bsxmh_manage_finance', 'bsxmh_view_reports' ),
        'bsxmh_membership_officer' => array( 'read', 'manage_bsxmh', 'bsxmh_manage_members', 'bsxmh_view_reports' ),
        'bsxmh_event_manager' => array( 'read', 'manage_bsxmh', 'bsxmh_manage_events', 'bsxmh_view_reports' ),
        'bsxmh_viewer' => array( 'read', 'manage_bsxmh', 'bsxmh_view_reports' ),
    );

    public static function register(): void {
        add_action( 'admin_init', array( __CLASS__, 'ensure_roles' ) );
    }

    public static function ensure_roles(): void {
        $labels = array(
            'bsxmh_treasurer' => 'MemberHub Treasurer',
            'bsxmh_accountant' => 'MemberHub Accountant',
            'bsxmh_membership_officer' => 'MemberHub Membership Officer',
            'bsxmh_event_manager' => 'MemberHub Event Manager',
            'bsxmh_viewer' => 'MemberHub Viewer',
        );
        foreach ( self::ROLE_CAPS as $slug => $caps ) {
            $role = get_role( $slug );
            if ( ! $role ) $role = add_role( $slug, $labels[ $slug ], array( 'read' => true ) );
            if ( $role ) {
                foreach ( array( 'manage_bsxmh','bsxmh_manage_members','bsxmh_manage_payments','bsxmh_manage_events','bsxmh_manage_finance','bsxmh_view_reports','bsxmh_manage_settings' ) as $cap ) $role->remove_cap( $cap );
                foreach ( $caps as $cap ) $role->add_cap( $cap );
            }
        }
        $admin = get_role( 'administrator' );
        if ( $admin ) foreach ( array( 'manage_bsxmh','bsxmh_manage_members','bsxmh_manage_payments','bsxmh_manage_events','bsxmh_manage_finance','bsxmh_view_reports','bsxmh_manage_settings' ) as $cap ) $admin->add_cap( $cap );
    }
}
