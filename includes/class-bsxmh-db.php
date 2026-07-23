<?php

defined( 'ABSPATH' ) || exit;

final class BSXMH_DB {
    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        foreach ( self::schemas( $wpdb->prefix, $charset ) as $sql ) {
            dbDelta( $sql );
        }
        update_option( 'bsxmh_db_version', BSXMH_VERSION, false );
    }

    public static function table( string $name ): string {
        global $wpdb;
        return $wpdb->prefix . 'bsxmh_' . $name;
    }

    private static function schemas( string $prefix, string $charset ): array {
        $p = $prefix . 'bsxmh_';
        return array(
            "CREATE TABLE {$p}members (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint unsigned NOT NULL,
                member_number varchar(80) NOT NULL,
                category_id bigint unsigned NULL,
                status varchar(30) NOT NULL DEFAULT 'pending',
                join_date date NULL,
                fee_start_date date NULL,
                monthly_fee decimal(18,2) NULL,
                profile_data longtext NULL,
                admin_notes longtext NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY user_id (user_id),
                UNIQUE KEY member_number (member_number),
                KEY status (status)
            ) {$charset};",
            "CREATE TABLE {$p}member_categories (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                name varchar(190) NOT NULL,
                slug varchar(190) NOT NULL,
                monthly_fee decimal(18,2) NULL,
                description longtext NULL,
                status varchar(20) NOT NULL DEFAULT 'active',
                sort_order int NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY slug (slug)
            ) {$charset};",
            "CREATE TABLE {$p}form_fields (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                field_key varchar(120) NOT NULL,
                label varchar(190) NOT NULL,
                field_type varchar(50) NOT NULL,
                field_options longtext NULL,
                validation_rules longtext NULL,
                visibility varchar(30) NOT NULL DEFAULT 'member',
                is_required tinyint(1) NOT NULL DEFAULT 0,
                is_enabled tinyint(1) NOT NULL DEFAULT 1,
                member_editable tinyint(1) NOT NULL DEFAULT 1,
                sort_order int NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY field_key (field_key)
            ) {$charset};",
            "CREATE TABLE {$p}payments (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                transaction_id varchar(120) NOT NULL,
                user_id bigint unsigned NULL,
                payment_type varchar(40) NOT NULL,
                gateway varchar(50) NOT NULL DEFAULT 'manual',
                currency varchar(10) NOT NULL DEFAULT 'BDT',
                subtotal decimal(18,2) NOT NULL DEFAULT 0,
                fee_amount decimal(18,2) NOT NULL DEFAULT 0,
                total_amount decimal(18,2) NOT NULL DEFAULT 0,
                status varchar(30) NOT NULL DEFAULT 'initiated',
                gateway_transaction_id varchar(190) NULL,
                gateway_validation_id varchar(190) NULL,
                payment_date datetime NULL,
                metadata longtext NULL,
                created_by bigint unsigned NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY transaction_id (transaction_id),
                KEY user_id (user_id),
                KEY status (status),
                KEY payment_type (payment_type)
            ) {$charset};",
            "CREATE TABLE {$p}payment_items (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                payment_id bigint unsigned NOT NULL,
                item_type varchar(40) NOT NULL,
                reference_id bigint unsigned NULL,
                period_year smallint unsigned NULL,
                period_month tinyint unsigned NULL,
                description varchar(255) NULL,
                amount decimal(18,2) NOT NULL DEFAULT 0,
                fund_id bigint unsigned NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY payment_id (payment_id),
                KEY period (period_year,period_month),
                KEY fund_id (fund_id)
            ) {$charset};",
            "CREATE TABLE {$p}guest_tokens (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                token_hash char(64) NOT NULL,
                user_id bigint unsigned NOT NULL,
                purpose varchar(50) NOT NULL DEFAULT 'due_payment',
                payload longtext NULL,
                expires_at datetime NULL,
                used_at datetime NULL,
                revoked_at datetime NULL,
                created_by bigint unsigned NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY token_hash (token_hash),
                KEY user_id (user_id),
                KEY expires_at (expires_at)
            ) {$charset};",
            "CREATE TABLE {$p}receipts (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                payment_id bigint unsigned NOT NULL,
                receipt_number varchar(120) NOT NULL,
                verification_token varchar(64) NULL,
                status varchar(30) NOT NULL DEFAULT 'valid',
                revision int unsigned NOT NULL DEFAULT 1,
                template_snapshot longtext NULL,
                pdf_path text NULL,
                created_at datetime NOT NULL,
                updated_at datetime NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY payment_id (payment_id),
                UNIQUE KEY receipt_number (receipt_number),
                UNIQUE KEY verification_token (verification_token),
                KEY status (status)
            ) {$charset};",
            "CREATE TABLE {$p}funds (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                name varchar(190) NOT NULL,
                slug varchar(190) NOT NULL,
                description longtext NULL,
                fund_type varchar(40) NOT NULL DEFAULT 'general',
                opening_balance decimal(18,2) NOT NULL DEFAULT 0,
                visibility varchar(30) NOT NULL DEFAULT 'public',
                sort_order int NOT NULL DEFAULT 0,
                status varchar(20) NOT NULL DEFAULT 'active',
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY slug (slug)
            ) {$charset};",
            "CREATE TABLE {$p}events (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                fund_id bigint unsigned NULL,
                title varchar(255) NOT NULL,
                slug varchar(190) NOT NULL,
                description longtext NULL,
                image_id bigint unsigned NULL,
                target_amount decimal(18,2) NULL,
                start_date date NULL,
                end_date date NULL,
                visibility varchar(30) NOT NULL DEFAULT 'public',
                status varchar(30) NOT NULL DEFAULT 'draft',
                created_by bigint unsigned NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY slug (slug),
                KEY fund_id (fund_id),
                KEY status (status)
            ) {$charset};",
            "CREATE TABLE {$p}expense_categories (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                name varchar(190) NOT NULL,
                slug varchar(190) NOT NULL,
                description longtext NULL,
                status varchar(20) NOT NULL DEFAULT 'active',
                sort_order int NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY slug (slug)
            ) {$charset};",
            "CREATE TABLE {$p}expenses (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                fund_id bigint unsigned NULL,
                category_id bigint unsigned NULL,
                event_id bigint unsigned NULL,
                voucher_number varchar(120) NULL,
                amount decimal(18,2) NOT NULL,
                expense_date date NOT NULL,
                description longtext NULL,
                payment_method varchar(40) NOT NULL DEFAULT 'cash',
                reference_number varchar(120) NULL,
                attachment_id bigint unsigned NULL,
                paid_by varchar(190) NULL,
                approved_by varchar(190) NULL,
                notes longtext NULL,
                status varchar(30) NOT NULL DEFAULT 'pending',
                created_by bigint unsigned NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY voucher_number (voucher_number),
                KEY fund_id (fund_id),
                KEY category_id (category_id),
                KEY event_id (event_id),
                KEY expense_date (expense_date),
                KEY status (status)
            ) {$charset};",
            "CREATE TABLE {$p}email_logs (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint unsigned NULL,
                recipient varchar(190) NOT NULL,
                email_type varchar(60) NOT NULL,
                subject varchar(255) NOT NULL,
                body longtext NULL,
                status varchar(30) NOT NULL DEFAULT 'queued',
                related_id bigint unsigned NULL,
                attempts int unsigned NOT NULL DEFAULT 0,
                scheduled_at datetime NULL,
                error_message text NULL,
                sent_at datetime NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY user_id (user_id),
                KEY status (status),
                KEY email_type (email_type)
            ) {$charset};",
            "CREATE TABLE {$p}gateway_logs (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                payment_id bigint unsigned NULL,
                gateway varchar(50) NOT NULL,
                event_type varchar(80) NOT NULL,
                request_data longtext NULL,
                response_data longtext NULL,
                http_code int NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY payment_id (payment_id),
                KEY gateway (gateway)
            ) {$charset};",
            "CREATE TABLE {$p}activity_logs (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                actor_user_id bigint unsigned NULL,
                target_user_id bigint unsigned NULL,
                action varchar(120) NOT NULL,
                object_type varchar(80) NULL,
                object_id bigint unsigned NULL,
                details longtext NULL,
                ip_hash char(64) NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY target_user_id (target_user_id),
                KEY action (action),
                KEY created_at (created_at)
            ) {$charset};"
        );
    }
}
