<?php

defined( 'ABSPATH' ) || exit;

final class BSXMH_Gateway_SSLCommerz implements BSXMH_Gateway_Interface {
    private array $settings;

    public function __construct() {
        $this->settings = get_option( 'bsxmh_gateway_sslcommerz', array() );
    }

    public function id(): string { return 'sslcommerz'; }
    public function is_enabled(): bool { return ! empty( $this->settings['enabled'] ) && ! empty( $this->settings['store_id'] ) && ! empty( $this->settings['store_password'] ); }
    public function is_sandbox(): bool { return 'live' !== ( $this->settings['mode'] ?? 'sandbox' ); }

    public function create_session( object $payment, array $customer ): array|WP_Error {
        if ( ! $this->is_enabled() ) return new WP_Error( 'gateway_disabled', __( 'SSLCOMMERZ is not configured or enabled.', 'bsx-memberhub' ) );
        $endpoint = $this->is_sandbox() ? 'https://sandbox.sslcommerz.com/gwprocess/v4/api.php' : 'https://securepay.sslcommerz.com/gwprocess/v4/api.php';
        $browser_callback = admin_url( 'admin-post.php' );
        $rest_callback    = rest_url( 'bsx-memberhub/v1/payment/sslcommerz/' );
        $return_args      = array(
            'action'     => 'bsxmh_sslcommerz_return',
            'bsxmh_tran' => (string) $payment->transaction_id,
        );
        $payload = array(
            'store_id' => (string) $this->settings['store_id'],
            'store_passwd' => (string) $this->settings['store_password'],
            'total_amount' => number_format( (float) $payment->total_amount, 2, '.', '' ),
            'currency' => (string) $payment->currency,
            'tran_id' => (string) $payment->transaction_id,
            'success_url' => add_query_arg( array_merge( $return_args, array( 'type' => 'success' ) ), $browser_callback ),
            'fail_url' => add_query_arg( array_merge( $return_args, array( 'type' => 'fail' ) ), $browser_callback ),
            'cancel_url' => add_query_arg( array_merge( $return_args, array( 'type' => 'cancel' ) ), $browser_callback ),
            'ipn_url' => add_query_arg( 'bsxmh_tran', (string) $payment->transaction_id, $rest_callback . 'ipn' ),
            'cus_name' => sanitize_text_field( $customer['name'] ?? 'MemberHub Payer' ),
            'cus_email' => sanitize_email( $customer['email'] ?? get_option( 'admin_email' ) ),
            'cus_add1' => sanitize_text_field( $customer['address'] ?? 'Bangladesh' ),
            'cus_city' => sanitize_text_field( $customer['city'] ?? 'Dhaka' ),
            'cus_state' => sanitize_text_field( $customer['state'] ?? 'Dhaka' ),
            'cus_postcode' => sanitize_text_field( $customer['postcode'] ?? '1200' ),
            'cus_country' => sanitize_text_field( $customer['country'] ?? 'Bangladesh' ),
            'cus_phone' => sanitize_text_field( $customer['phone'] ?? '01700000000' ),
            'shipping_method' => 'NO',
            'product_name' => sanitize_text_field( $customer['product_name'] ?? 'MemberHub Payment' ),
            'product_category' => sanitize_text_field( $payment->payment_type ),
            'product_profile' => 'non-physical-goods',
            'value_a' => (string) $payment->id,
            'value_b' => wp_hash( $payment->transaction_id ),
        );
        $response = wp_remote_post( $endpoint, array( 'timeout' => 45, 'body' => $payload ) );
        BSXMH_Gateways::log( (int) $payment->id, 'session_request', self::mask( $payload ), is_wp_error( $response ) ? array( 'error' => $response->get_error_message() ) : json_decode( wp_remote_retrieve_body( $response ), true ), is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response ) );
        if ( is_wp_error( $response ) ) return $response;
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( 200 !== wp_remote_retrieve_response_code( $response ) || empty( $body['GatewayPageURL'] ) || 'SUCCESS' !== strtoupper( (string) ( $body['status'] ?? '' ) ) ) {
            return new WP_Error( 'session_failed', sanitize_text_field( $body['failedreason'] ?? __( 'SSLCOMMERZ could not create a payment session.', 'bsx-memberhub' ) ) );
        }
        return $body;
    }

    public function validate( string $validation_id ): array|WP_Error {
        if ( ! $this->is_enabled() ) return new WP_Error( 'gateway_disabled', 'SSLCOMMERZ is disabled.' );
        $base = $this->is_sandbox() ? 'https://sandbox.sslcommerz.com/validator/api/validationserverAPI.php' : 'https://securepay.sslcommerz.com/validator/api/validationserverAPI.php';
        $url = add_query_arg( array( 'val_id'=>$validation_id, 'store_id'=>$this->settings['store_id'], 'store_passwd'=>$this->settings['store_password'], 'v'=>'1', 'format'=>'json' ), $base );
        $response = wp_remote_get( $url, array( 'timeout'=>45 ) );
        if ( is_wp_error( $response ) ) return $response;
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( 200 !== wp_remote_retrieve_response_code( $response ) || ! is_array( $body ) ) return new WP_Error( 'validation_failed', 'Invalid validation response.' );
        return $body;
    }

    private static function mask( array $data ): array {
        if ( isset( $data['store_passwd'] ) ) $data['store_passwd'] = '********';
        return $data;
    }
}
