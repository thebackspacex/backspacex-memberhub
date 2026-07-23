<?php

defined( 'ABSPATH' ) || exit;

final class BSXMH_Gateways {
    public static function register(): void {
        add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
        add_action( 'admin_post_bsxmh_save_sslcommerz', array( __CLASS__, 'save_settings' ) );
        add_action( 'admin_post_bsxmh_start_online_payment', array( __CLASS__, 'handle_start_payment' ) );
        add_action( 'admin_post_nopriv_bsxmh_start_online_payment', array( __CLASS__, 'handle_start_payment' ) );
        add_action( 'admin_post_bsxmh_sslcommerz_return', array( __CLASS__, 'browser_return' ) );
        add_action( 'admin_post_nopriv_bsxmh_sslcommerz_return', array( __CLASS__, 'browser_return' ) );
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 20 );
        add_shortcode( 'bsxmh_payment', array( __CLASS__, 'payment_shortcode' ) );
        add_shortcode( 'bsxmh_online_contribution', array( __CLASS__, 'contribution_shortcode' ) );
        add_shortcode( 'bsxmh_online_event_donation', array( __CLASS__, 'event_shortcode' ) );
    }

    public static function admin_menu(): void {
        add_submenu_page( 'bsxmh', 'Payment Gateway', 'Payment Gateway', 'bsxmh_manage_settings', 'bsxmh-gateway', array( __CLASS__, 'render_settings' ) );
        add_submenu_page( 'bsxmh', 'Gateway Logs', 'Gateway Logs', 'bsxmh_manage_settings', 'bsxmh-gateway-logs', array( __CLASS__, 'render_logs' ) );
    }


    public static function render_logs(): void {
        global $wpdb;
        $rows = $wpdb->get_results( "SELECT l.*,p.transaction_id FROM " . BSXMH_DB::table( 'gateway_logs' ) . " l LEFT JOIN " . BSXMH_DB::table( 'payments' ) . " p ON p.id=l.payment_id ORDER BY l.id DESC LIMIT 200" );
        echo '<div class="wrap bsxmh-wrap"><h1>SSLCOMMERZ Gateway Logs</h1><div class="bsxmh-panel"><p>Passwords and card-related fields are redacted. Logs are intended for troubleshooting.</p><table class="widefat striped"><thead><tr><th>Time</th><th>Transaction</th><th>Event</th><th>HTTP</th><th>Response</th></tr></thead><tbody>';
        if ( ! $rows ) echo '<tr><td colspan="5">No gateway logs yet.</td></tr>';
        foreach ( $rows as $r ) echo '<tr><td>'.esc_html($r->created_at).'</td><td>'.esc_html($r->transaction_id?:'—').'</td><td>'.esc_html($r->event_type).'</td><td>'.esc_html((string)$r->http_code).'</td><td><details><summary>View</summary><pre style="white-space:pre-wrap;max-width:700px">'.esc_html((string)$r->response_data).'</pre></details></td></tr>';
        echo '</tbody></table></div></div>';
    }

    public static function save_settings(): void {
        if ( ! current_user_can( 'bsxmh_manage_settings' ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'bsxmh_save_sslcommerz' );
        $old = get_option( 'bsxmh_gateway_sslcommerz', array() );
        $password = trim( (string) wp_unslash( $_POST['store_password'] ?? '' ) );
        update_option( 'bsxmh_gateway_sslcommerz', array(
            'enabled' => empty( $_POST['enabled'] ) ? 0 : 1,
            'mode' => 'live' === ( $_POST['mode'] ?? 'sandbox' ) ? 'live' : 'sandbox',
            'store_id' => sanitize_text_field( wp_unslash( $_POST['store_id'] ?? '' ) ),
            'store_password' => '' === $password ? (string) ( $old['store_password'] ?? '' ) : sanitize_text_field( $password ),
            'debug' => empty( $_POST['debug'] ) ? 0 : 1,
        ), false );
        wp_safe_redirect( admin_url( 'admin.php?page=bsxmh-gateway&updated=1' ) ); exit;
    }

    public static function render_settings(): void {
        $s = get_option( 'bsxmh_gateway_sslcommerz', array() );
        echo '<div class="wrap bsxmh-wrap"><h1>SSLCOMMERZ Gateway</h1>';
        if ( isset( $_GET['updated'] ) ) echo '<div class="notice notice-success"><p>Gateway settings saved.</p></div>';
        echo '<div class="bsxmh-panel"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="bsxmh_save_sslcommerz">'; wp_nonce_field('bsxmh_save_sslcommerz');
        echo '<table class="form-table"><tr><th>Enable Gateway</th><td><label><input type="checkbox" name="enabled" value="1" '.checked(!empty($s['enabled']),true,false).'> Enable SSLCOMMERZ</label></td></tr>';
        echo '<tr><th>Mode</th><td><select name="mode"><option value="sandbox" '.selected($s['mode']??'sandbox','sandbox',false).'>Sandbox</option><option value="live" '.selected($s['mode']??'','live',false).'>Live</option></select></td></tr>';
        echo '<tr><th>Store ID</th><td><input class="regular-text" name="store_id" value="'.esc_attr($s['store_id']??'').'" autocomplete="off"></td></tr>';
        echo '<tr><th>Store Password</th><td><input class="regular-text" type="password" name="store_password" value="" placeholder="'.(!empty($s['store_password'])?'Saved — leave blank to keep':'Enter store password').'" autocomplete="new-password"></td></tr>';
        echo '<tr><th>Debug Logging</th><td><label><input type="checkbox" name="debug" value="1" '.checked(!empty($s['debug']),true,false).'> Save sanitized gateway request/response logs</label></td></tr></table>';
        submit_button('Save Gateway Settings'); echo '</form></div>';
        echo '<div class="bsxmh-panel"><h2>Callback URLs</h2><p><code>'.esc_html(rest_url('bsx-memberhub/v1/payment/sslcommerz/success')).'</code></p><p><code>'.esc_html(rest_url('bsx-memberhub/v1/payment/sslcommerz/ipn')).'</code></p><p>Hosted Checkout is used. Payment is marked paid only after server-side Validation API verification and exact transaction, currency and amount matching.</p></div></div>';
    }

    public static function rest_routes(): void {
        foreach ( array( 'success','fail','cancel','ipn' ) as $type ) {
            register_rest_route( 'bsx-memberhub/v1', '/payment/sslcommerz/'.$type, array( 'methods'=>array('GET','POST'), 'callback'=>static fn(WP_REST_Request $r)=>self::callback($type,$r), 'permission_callback'=>'__return_true' ) );
        }
    }

    public static function browser_return(): void {
        $type = sanitize_key( wp_unslash( $_REQUEST['type'] ?? '' ) );
        if ( ! in_array( $type, array( 'success', 'fail', 'cancel' ), true ) ) {
            wp_safe_redirect( add_query_arg( 'bsxmh_payment_result', 'failed', BSXMH_Portal::page_url( 'payment_page_id', '/member-payment/' ) ) );
            exit;
        }

        $request = new WP_REST_Request( strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) );
        foreach ( wp_unslash( $_REQUEST ) as $key => $value ) {
            $request->set_param( sanitize_key( (string) $key ), $value );
        }
        $response = self::callback( $type, $request );
        if ( $response instanceof WP_REST_Response ) {
            $location = $response->get_headers()['Location'] ?? '';
            if ( $location ) {
                wp_redirect( esc_url_raw( $location ), 302, 'BackspaceX MemberHub' );
                exit;
            }
            wp_die( esc_html( (string) $response->get_data() ), 'MemberHub Payment', array( 'response' => $response->get_status() ) );
        }
        wp_safe_redirect( BSXMH_Portal::page_url( 'payment_page_id', '/member-payment/' ) );
        exit;
    }

    public static function callback( string $type, WP_REST_Request $request ) {
        $data = array_merge( $request->get_query_params(), $request->get_body_params(), $request->get_params() );
        $tran = sanitize_text_field( $data['tran_id'] ?? $data['bsxmh_tran'] ?? '' );
        $payment = self::get_by_transaction( $tran );
        if ( ! $payment && ! empty( $data['value_a'] ) ) {
            $payment = BSXMH_Payments::get( absint( $data['value_a'] ) );
        }
        if ( ! $payment ) {
            if ( in_array( $type, array( 'fail', 'cancel' ), true ) ) {
                return self::redirect_response( add_query_arg( 'bsxmh_payment_result', $type, BSXMH_Portal::page_url( 'payment_page_id', '/member-payment/' ) ) );
            }
            return new WP_REST_Response( 'Unknown transaction.', 404 );
        }
        self::log( (int)$payment->id, 'callback_'.$type, array(), self::sanitize_log($data), 200 );
        if ( in_array( $type, array('fail','cancel'), true ) ) {
            self::set_status( (int)$payment->id, 'fail' === $type ? 'failed' : 'cancelled' );
            return self::redirect_response( self::result_url( $payment, $type ) );
        }
        $status = strtoupper( sanitize_text_field( $data['status'] ?? '' ) );
        $val_id = sanitize_text_field( $data['val_id'] ?? '' );
        if ( ! in_array( $status, array('VALID','VALIDATED'), true ) || '' === $val_id ) {
            if ( 'ipn' === $type ) return new WP_REST_Response( 'Ignored.', 200 );
            return self::redirect_response( self::result_url( $payment, 'failed' ) );
        }
        if ( 'paid' === $payment->status ) return 'ipn' === $type ? new WP_REST_Response('Already processed.',200) : self::redirect_response(self::result_url($payment,'success'));
        $gateway = new BSXMH_Gateway_SSLCommerz();
        $validated = $gateway->validate( $val_id );
        self::log( (int)$payment->id, 'validation', array('val_id'=>$val_id), is_wp_error($validated)?array('error'=>$validated->get_error_message()):self::sanitize_log($validated), is_wp_error($validated)?0:200 );
        if ( is_wp_error( $validated ) ) return 'ipn' === $type ? new WP_REST_Response('Validation failed.',400) : self::redirect_response(self::result_url($payment,'failed'));
        $valid_status = in_array( strtoupper((string)($validated['status']??'')), array('VALID','VALIDATED'), true );
        $valid_tran = hash_equals( (string)$payment->transaction_id, (string)($validated['tran_id']??'') );
        $valid_currency = strtoupper((string)$payment->currency) === strtoupper((string)($validated['currency']??$validated['currency_type']??''));
        $paid_amount = (float)($validated['currency_amount']??$validated['amount']??0);
        $valid_amount = abs( (float)$payment->total_amount - $paid_amount ) < 0.01;
        if ( ! $valid_status || ! $valid_tran || ! $valid_currency || ! $valid_amount ) {
            self::set_status( (int)$payment->id, 'failed' );
            return 'ipn' === $type ? new WP_REST_Response('Mismatch.',400) : self::redirect_response(self::result_url($payment,'failed'));
        }
        $risk = (int)($validated['risk_level']??0);
        if ( $risk > 0 ) {
            self::set_status( (int)$payment->id, 'processing', $validated );
            return 'ipn' === $type ? new WP_REST_Response('Risk review.',200) : self::redirect_response(self::result_url($payment,'processing'));
        }
        self::set_status( (int)$payment->id, 'paid', $validated );
        BSXMH_Receipts::create_for_payment( (int)$payment->id );
        $metadata = json_decode( (string) $payment->metadata, true );
        if ( ! empty( $metadata['guest_token_id'] ) ) BSXMH_Email_Automation::mark_token_used( (int) $metadata['guest_token_id'] );
        do_action( 'bsxmh_payment_completed', (int) $payment->id );
        return 'ipn' === $type ? new WP_REST_Response('OK',200) : self::redirect_response(self::result_url($payment,'success'));
    }

    private static function redirect_response( string $url ): WP_REST_Response {
        $response = new WP_REST_Response( null, 302 ); $response->header( 'Location', $url ); return $response;
    }
    private static function result_url( object $payment, string $result ): string {
        $settings=get_option('bsxmh_settings',array()); $base=!empty($settings['payment_return_url'])?$settings['payment_return_url']:BSXMH_Portal::page_url('payment_page_id','/member-payment/');
        return add_query_arg(array('bsxmh_payment_result'=>$result,'transaction'=>$payment->transaction_id),$base);
    }

    public static function payment_shortcode(): string {
        if ( ! is_user_logged_in() ) return '<div class="bsxmh-notice">Please log in to pay membership fees.</div>';
        $member=BSXMH_Members::get_by_user(get_current_user_id()); if(!$member||'active'!==$member->status)return '<div class="bsxmh-notice">An active member profile is required.</div>';
        $months = self::membership_month_options( $member, 12 );
        return self::render_form( 'membership', $months, array(), 0 );
    }
    public static function contribution_shortcode(): string {
        if(!is_user_logged_in())return '<div class="bsxmh-notice">Please log in to contribute.</div>';
        return self::render_form('extra_contribution',array(),BSXMH_Contributions::funds(true),0);
    }
    public static function event_shortcode( array $atts=array() ): string {
        $atts=shortcode_atts(array('id'=>0),$atts); return self::render_form('event_donation',array(),array(),absint($atts['id']));
    }

    public static function handle_start_payment(): void {
        $return_url = isset( $_POST['bsxmh_return_url'] ) ? esc_url_raw( wp_unslash( $_POST['bsxmh_return_url'] ) ) : home_url( '/' );
        $return_url = wp_validate_redirect( $return_url, home_url( '/' ) );
        $type = sanitize_key( wp_unslash( $_POST['bsxmh_online_type'] ?? '' ) );

        if ( ! isset( $_POST['bsxmh_online_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bsxmh_online_nonce'] ) ), 'bsxmh_online_payment' ) ) {
            self::payment_error_redirect( $return_url, 'Security verification failed.' );
        }
        if ( ! in_array( $type, array( 'membership', 'extra_contribution', 'event_donation' ), true ) ) {
            self::payment_error_redirect( $return_url, 'Invalid payment type.' );
        }
        $guest_token_id = 0;
        if ( 'membership' === $type && ! is_user_logged_in() && ! empty( $_POST['bsxmh_guest_token'] ) ) {
            $raw_token = sanitize_text_field( wp_unslash( $_POST['bsxmh_guest_token'] ) );
            $token = BSXMH_Email_Automation::validate_token( $raw_token );
            if ( is_wp_error( $token ) ) self::payment_error_redirect( $return_url, $token->get_error_message() );
            $payload = json_decode( (string) $token->payload, true );
            $allowed = array_values( (array) ( $payload['periods'] ?? array() ) );
            $posted = array_values( array_map( 'sanitize_text_field', (array) ( $_POST['periods'] ?? array() ) ) );
            if ( ! $posted || array_diff( $posted, $allowed ) ) self::payment_error_redirect( $return_url, 'The payment months do not match this secure link.' );
            wp_set_current_user( (int) $token->user_id );
            $guest_token_id = (int) $token->id;
            $_POST['bsxmh_guest_token_id'] = $guest_token_id;
        }
        if ( in_array( $type, array( 'membership', 'extra_contribution' ), true ) && ! is_user_logged_in() ) {
            self::payment_error_redirect( $return_url, 'Please log in before making this payment.' );
        }

        $result = self::start_payment( $type, $_POST );
        if ( is_wp_error( $result ) ) {
            self::payment_error_redirect( $return_url, $result->get_error_message() );
        }
        self::redirect_to_gateway( (string) $result, $return_url );
    }

    private static function payment_error_redirect( string $return_url, string $message ): void {
        $key = wp_generate_password( 20, false, false );
        set_transient( 'bsxmh_payment_error_' . $key, sanitize_text_field( $message ), 5 * MINUTE_IN_SECONDS );
        wp_safe_redirect( add_query_arg( 'bsxmh_payment_error', rawurlencode( $key ), $return_url ) );
        exit;
    }

    private static function redirect_to_gateway( string $url, string $fallback ): void {
        // SSLCOMMERZ may return HTML-encoded URLs and may use different
        // first-party checkout subdomains. Normalize before validating.
        $url   = trim( html_entity_decode( wp_unslash( $url ), ENT_QUOTES, 'UTF-8' ) );
        $parts = wp_parse_url( $url );
        $scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
        $host   = strtolower( rtrim( (string) ( $parts['host'] ?? '' ), '.' ) );

        $is_sslcommerz_host = (
            'sslcommerz.com' === $host ||
            str_ends_with( $host, '.sslcommerz.com' )
        );

        // Accept only normal web URLs on SSLCOMMERZ-owned hosts. The sandbox
        // service has historically returned both HTTP and HTTPS checkout URLs;
        // live checkout is expected to use HTTPS.
        if (
            ! in_array( $scheme, array( 'http', 'https' ), true ) ||
            ! $is_sslcommerz_host ||
            ! empty( $parts['user'] ) ||
            ! empty( $parts['pass'] )
        ) {
            self::payment_error_redirect( $fallback, 'The payment gateway returned an invalid checkout URL.' );
        }

        wp_redirect( esc_url_raw( $url ), 302, 'BackspaceX MemberHub' );
        exit;
    }

    private static function render_form(string $type,array $months,array $funds,int $event_id): string {
        wp_enqueue_style('bsxmh-public'); $message='';
        if ( isset( $_GET['bsxmh_payment_error'] ) ) {
            $key = sanitize_key( wp_unslash( $_GET['bsxmh_payment_error'] ) );
            $error = get_transient( 'bsxmh_payment_error_' . $key );
            if ( $error ) { delete_transient( 'bsxmh_payment_error_' . $key ); $message .= '<div class="bsxmh-notice bsxmh-error">' . esc_html( $error ) . '</div>'; }
        }
        if ( isset( $_GET['bsxmh_payment_result'] ) ) { $r=sanitize_key($_GET['bsxmh_payment_result']); $labels=array('success'=>'Payment verified successfully. Your receipt is now available.','failed'=>'Payment failed or could not be verified.','cancel'=>'Payment was cancelled.','processing'=>'Payment is under risk review.'); if(isset($labels[$r]))$message='<div class="bsxmh-notice '.('success'===$r?'bsxmh-success':'bsxmh-error').'">'.esc_html($labels[$r]).'</div>'.$message; }
        $return_url = get_permalink() ?: home_url( '/' );
        ob_start(); echo $message; echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="bsxmh-form">';wp_nonce_field('bsxmh_online_payment','bsxmh_online_nonce');echo '<input type="hidden" name="action" value="bsxmh_start_online_payment"><input type="hidden" name="bsxmh_return_url" value="'.esc_url($return_url).'"><input type="hidden" name="bsxmh_online_type" value="'.esc_attr($type).'">';
        if ( 'membership' === $type ) {
            echo '<h3>Pay Membership Fee</h3><div class="bsxmh-payment-months"><span class="bsxmh-field-label">Select Months</span>';
            foreach ( $months as $m ) {
                $key     = sprintf( '%04d-%02d', (int) $m['year'], (int) $m['month'] );
                $is_paid = ! empty( $m['paid'] );
                $classes = 'bsxmh-payment-month' . ( $is_paid ? ' is-paid' : '' );
                echo '<label class="' . esc_attr( $classes ) . '">';
                echo '<span class="bsxmh-payment-month-name">' . esc_html( BSXMH_Payments::month_label( (int) $m['year'], (int) $m['month'] ) ) . '</span>';
                echo '<span class="bsxmh-payment-month-control"><input type="checkbox" name="periods[]" value="' . esc_attr( $key ) . '"' . disabled( $is_paid, true, false ) . ' aria-label="' . esc_attr( $is_paid ? 'Already paid' : 'Select ' . BSXMH_Payments::month_label( (int) $m['year'], (int) $m['month'] ) ) . '">';
                if ( $is_paid ) {
                    echo '<span class="bsxmh-paid-badge">Paid ✓</span>';
                }
                echo '</span></label>';
            }
            echo '<p class="bsxmh-payment-help">Already-paid months are locked automatically. Use Contribution for any additional amount.</p></div>';
        }
        elseif('extra_contribution'===$type){echo '<h3>Extra Contribution</h3><div><label>Fund</label><select name="fund_id" required><option value="">Choose fund</option>';foreach($funds as$f)echo '<option value="'.$f->id.'">'.esc_html($f->name).'</option>';echo '</select></div><div><label>Amount</label><input type="number" step="0.01" min="1" name="amount" required></div>';}
        else{echo '<h3>Event Donation</h3><input type="hidden" name="event_id" value="'.esc_attr($event_id).'"><div><label>Amount</label><input type="number" step="0.01" min="1" name="amount" required></div>';if(!is_user_logged_in())echo '<div><label>Name</label><input name="guest_name" required></div><div><label>Email</label><input type="email" name="guest_email" required></div><div><label>Mobile</label><input name="guest_phone" required></div>';}
        echo '<button type="submit">Pay with SSLCOMMERZ</button></form>';return (string)ob_get_clean();
    }

    private static function start_payment(string $type,array $data): string|WP_Error {
        global $wpdb; $gateway=new BSXMH_Gateway_SSLCommerz(); if(!$gateway->is_enabled())return new WP_Error('disabled','Online payment is currently unavailable.');
        $user_id=get_current_user_id()?:null;$amount=0;$items=array();$product='MemberHub Payment';
        if('membership'===$type){$member=BSXMH_Members::get_by_user((int)$user_id);if(!$member)return new WP_Error('member','Member not found.');$periods=array_values(array_unique(array_map('sanitize_text_field',(array)($data['periods']??array()))));$paid=BSXMH_Payments::paid_month_keys((int)$user_id);foreach($periods as$p){if(!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/',$p,$m)||in_array($p,$paid,true))continue;$items[]=array('type'=>'membership','reference'=>(int)$member->id,'year'=>(int)$m[1],'month'=>(int)$m[2],'description'=>BSXMH_Payments::month_label((int)$m[1],(int)$m[2]),'amount'=>(float)$member->monthly_fee,'fund'=>self::fund_id('membership'));$amount+=(float)$member->monthly_fee;}if(!$items)return new WP_Error('months','Select at least one unpaid month.');$product='Membership Fee';}
        elseif('extra_contribution'===$type){$amount=max(0,(float)($data['amount']??0));$fund=BSXMH_Contributions::get_fund(absint($data['fund_id']??0));if(!$fund||'active'!==$fund->status||$amount<=0)return new WP_Error('invalid','Choose an active fund and valid amount.');$items[]=array('type'=>'extra_contribution','reference'=>(int)$fund->id,'description'=>$fund->name,'amount'=>$amount,'fund'=>(int)$fund->id);$product='Extra Contribution';}
        else{$amount=max(0,(float)($data['amount']??0));$event=BSXMH_Events::get(absint($data['event_id']??0));if(!$event||'active'!==$event->status||$amount<=0)return new WP_Error('event','Active event and valid amount are required.');$items[]=array('type'=>'event_donation','reference'=>(int)$event->id,'description'=>$event->title,'amount'=>$amount,'fund'=>(int)$event->fund_id);$product=$event->title;}
        $tran=BSXMH_Payments::transaction_id();$now=current_time('mysql');$meta=array('method'=>'sslcommerz','source'=>'frontend','guest_name'=>sanitize_text_field($data['guest_name']??''),'guest_email'=>sanitize_email($data['guest_email']??''),'guest_phone'=>sanitize_text_field($data['guest_phone']??''),'guest_token_id'=>absint($data['bsxmh_guest_token_id']??0));
        $wpdb->insert(BSXMH_DB::table('payments'),array('transaction_id'=>$tran,'user_id'=>$user_id,'payment_type'=>$type,'gateway'=>'sslcommerz','currency'=>'BDT','subtotal'=>number_format($amount,2,'.',''),'fee_amount'=>'0.00','total_amount'=>number_format($amount,2,'.',''),'status'=>'initiated','metadata'=>wp_json_encode($meta),'created_by'=>$user_id,'created_at'=>$now,'updated_at'=>$now));if(!$wpdb->insert_id)return new WP_Error('db','Could not create transaction.');$pid=(int)$wpdb->insert_id;
        foreach($items as$i)$wpdb->insert(BSXMH_DB::table('payment_items'),array('payment_id'=>$pid,'item_type'=>$i['type'],'reference_id'=>$i['reference'],'period_year'=>$i['year']??null,'period_month'=>$i['month']??null,'description'=>$i['description'],'amount'=>number_format($i['amount'],2,'.',''),'fund_id'=>$i['fund']?:null,'created_at'=>$now));
        $payment=BSXMH_Payments::get($pid);$u=$user_id?get_userdata((int)$user_id):null;$customer=array('name'=>$u?$u->display_name:$meta['guest_name'],'email'=>$u?$u->user_email:$meta['guest_email'],'phone'=>$u?get_user_meta((int)$user_id,'bsxmh_phone',true):$meta['guest_phone'],'product_name'=>$product);
        $session=$gateway->create_session($payment,$customer);if(is_wp_error($session)){self::set_status($pid,'failed');return $session;}self::set_status($pid,'pending');return esc_url_raw($session['GatewayPageURL']);
    }

    /**
     * Build the membership month selector timeline.
     *
     * Outstanding months are shown first, followed by the current month and
     * the configured number of advance months. Months already paid remain
     * visible but are marked and disabled so they cannot be submitted again.
     */
    private static function membership_month_options( object $member, int $advance_count = 12 ): array {
        $statement = BSXMH_Payments::statement( $member );
        $paid_keys = array_fill_keys( BSXMH_Payments::paid_month_keys( (int) $member->user_id ), true );
        $timezone  = wp_timezone();
        $current   = new DateTimeImmutable( 'first day of this month', $timezone );

        $settings  = get_option( 'bsxmh_settings', array() );
        $org_start = sprintf(
            '%04d-%02d-01',
            max( 1900, (int) ( $settings['organization_start_year'] ?? current_time( 'Y' ) ) ),
            min( 12, max( 1, (int) ( $settings['organization_start_month'] ?? 1 ) ) )
        );
        $fee_start = ! empty( $member->fee_start_date ) ? (string) $member->fee_start_date : $org_start;

        try {
            $eligible_start = new DateTimeImmutable( $fee_start, $timezone );
            $eligible_start = $eligible_start->modify( 'first day of this month' );
        } catch ( Exception $e ) {
            $eligible_start = $current;
        }

        $start = $current > $eligible_start ? $current : $eligible_start;
        if ( ! empty( $statement['due'] ) ) {
            $first_due = reset( $statement['due'] );
            if ( is_array( $first_due ) && ! empty( $first_due['key'] ) ) {
                try {
                    $due_start = new DateTimeImmutable( $first_due['key'] . '-01', $timezone );
                    if ( $due_start < $start ) {
                        $start = $due_start;
                    }
                } catch ( Exception $e ) {
                    // Keep the calculated start date.
                }
            }
        }

        $end_base = $current > $eligible_start ? $current : $eligible_start;
        $end      = $end_base->modify( '+' . max( 0, $advance_count ) . ' months' );
        $months   = array();
        $cursor   = $start;
        $guard    = 0;

        while ( $cursor <= $end && $guard < 600 ) {
            $key      = $cursor->format( 'Y-m' );
            $months[] = array(
                'year'  => (int) $cursor->format( 'Y' ),
                'month' => (int) $cursor->format( 'n' ),
                'key'   => $key,
                'paid'  => isset( $paid_keys[ $key ] ),
            );
            $cursor = $cursor->modify( '+1 month' );
            $guard++;
        }

        return $months;
    }

    private static function future_months(int $count): array{$out=array();$d=new DateTimeImmutable('first day of next month',wp_timezone());for($i=0;$i<$count;$i++){$out[]=array('year'=>(int)$d->format('Y'),'month'=>(int)$d->format('n'));$d=$d->modify('+1 month');}return $out;}
    private static function fund_id(string $slug): int{global $wpdb;return (int)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.BSXMH_DB::table('funds').' WHERE slug=%s',$slug));}
    private static function get_by_transaction(string $tran){global $wpdb;if(!$tran)return null;return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.BSXMH_DB::table('payments').' WHERE transaction_id=%s',$tran));}
    private static function set_status(int $id,string $status,array $validated=array()): void{global $wpdb;$data=array('status'=>$status,'updated_at'=>current_time('mysql'));if('paid'===$status)$data['payment_date']=current_time('mysql');if($validated){$data['gateway_transaction_id']=sanitize_text_field($validated['bank_tran_id']??'');$data['gateway_validation_id']=sanitize_text_field($validated['val_id']??'');}$wpdb->update(BSXMH_DB::table('payments'),$data,array('id'=>$id));}
    public static function log(int $payment_id,string $event,array $request,array $response,int $code): void{global $wpdb;$s=get_option('bsxmh_gateway_sslcommerz',array());if(empty($s['debug'])&&!str_starts_with($event,'callback_')&&'validation'!==$event)return;$wpdb->insert(BSXMH_DB::table('gateway_logs'),array('payment_id'=>$payment_id?:null,'gateway'=>'sslcommerz','event_type'=>$event,'request_data'=>wp_json_encode($request),'response_data'=>wp_json_encode($response),'http_code'=>$code?:null,'created_at'=>current_time('mysql')));}
    private static function sanitize_log(array $data): array{foreach(array('store_passwd','card_no','card_issuer','card_brand','card_type')as$k)if(isset($data[$k]))$data[$k]='[redacted]';return $data;}
}
