<?php

defined( 'ABSPATH' ) || exit;

interface BSXMH_Gateway_Interface {
    public function id(): string;
    public function is_enabled(): bool;
    public function create_session( object $payment, array $customer ): array|WP_Error;
    public function validate( string $validation_id ): array|WP_Error;
}
