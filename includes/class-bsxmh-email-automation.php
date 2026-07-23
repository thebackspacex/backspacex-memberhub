<?php

defined( 'ABSPATH' ) || exit;

final class BSXMH_Email_Automation {
    private const TEMPLATES_OPTION = 'bsxmh_email_templates';
    private const SETTINGS_OPTION  = 'bsxmh_email_settings';

    public static function register(): void {
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 25 );
        add_action( 'admin_post_bsxmh_save_email_settings', array( __CLASS__, 'save_settings' ) );
        add_action( 'admin_post_bsxmh_send_test_email', array( __CLASS__, 'send_test_email' ) );
        add_action( 'admin_post_bsxmh_queue_reminders', array( __CLASS__, 'queue_reminders' ) );
        add_action( 'admin_post_bsxmh_retry_email', array( __CLASS__, 'retry_email' ) );
        add_action( 'admin_post_bsxmh_generate_payment_link', array( __CLASS__, 'generate_link_action' ) );
        add_action( 'admin_post_bsxmh_revoke_payment_link', array( __CLASS__, 'revoke_link_action' ) );
        add_action( 'bsxmh_process_email_queue', array( __CLASS__, 'process_queue' ) );
        add_action( 'bsxmh_generate_due_reminders', array( __CLASS__, 'scheduled_reminders' ) );
        add_action( 'bsxmh_hourly_email_queue', array( __CLASS__, 'process_queue' ) );
        add_shortcode( 'bsxmh_guest_payment', array( __CLASS__, 'guest_payment_shortcode' ) );
        add_action( 'bsxmh_member_registered', static function( int $user_id ): void { self::queue_template( 'registration_pending', $user_id ); } );
        add_action( 'bsxmh_member_approved', static function( int $user_id ): void { self::queue_template( 'registration_approved', $user_id ); } );
        add_action( 'bsxmh_payment_completed', static function( int $payment_id ): void { self::queue_payment_success( $payment_id ); } );
    }

    public static function ensure_defaults(): void {
        $defaults = array(
            'registration_pending' => array( 'subject' => 'Registration received - {{organization_name}}', 'body' => "Hello {{member_name}},\n\nYour registration has been received and is awaiting approval.\n\nMember ID: {{member_id}}\n\n{{organization_name}}" ),
            'registration_approved' => array( 'subject' => 'Membership approved - {{organization_name}}', 'body' => "Hello {{member_name}},\n\nYour membership has been approved.\n\nMember ID: {{member_id}}\nDashboard: {{dashboard_url}}" ),
            'payment_reminder' => array( 'subject' => 'Membership fee reminder - {{organization_name}}', 'body' => "Hello {{member_name}},\n\nYou currently have {{due_months}} due month(s), totalling {{due_amount}}.\n\nPay securely without logging in: {{payment_link}}\n\nThis link expires on {{link_expiry}}." ),
            'payment_success' => array( 'subject' => 'Payment received - {{organization_name}}', 'body' => "Hello {{member_name}},\n\nWe received your payment of {{payment_amount}}.\nTransaction: {{transaction_id}}\nReceipt: {{receipt_url}}\n\nThank you." ),
        );
        $current = get_option( self::TEMPLATES_OPTION, array() );
        update_option( self::TEMPLATES_OPTION, array_replace_recursive( $defaults, is_array( $current ) ? $current : array() ), false );
        $settings = wp_parse_args( get_option( self::SETTINGS_OPTION, array() ), array(
            'enabled' => 1, 'from_name' => get_bloginfo( 'name' ), 'from_email' => get_option( 'admin_email' ),
            'queue_batch' => 20, 'link_expiry_days' => 7, 'scheduled_enabled' => 0, 'reminder_day' => 5,
            'minimum_due' => 0, 'last_scheduled_month' => '',
        ) );
        update_option( self::SETTINGS_OPTION, $settings, false );
        self::ensure_page();
        if ( ! wp_next_scheduled( 'bsxmh_hourly_email_queue' ) ) wp_schedule_event( time() + 300, 'hourly', 'bsxmh_hourly_email_queue' );
    }

    private static function ensure_page(): void {
        $settings = get_option( 'bsxmh_settings', array() );
        $page_id = absint( $settings['guest_payment_page_id'] ?? 0 );
        if ( $page_id && get_post_status( $page_id ) ) return;
        $existing = get_page_by_path( 'member-guest-payment' );
        if ( $existing ) $page_id = (int) $existing->ID;
        else $page_id = (int) wp_insert_post( array( 'post_title' => 'Secure Member Payment', 'post_name' => 'member-guest-payment', 'post_status' => 'publish', 'post_type' => 'page', 'post_content' => '[bsxmh_guest_payment]' ) );
        if ( $page_id ) { $settings['guest_payment_page_id'] = $page_id; update_option( 'bsxmh_settings', $settings, false ); }
    }

    public static function admin_menu(): void {
        add_submenu_page( 'bsxmh', 'Email Automation', 'Email Automation', 'bsxmh_send_reminders', 'bsxmh-email', array( __CLASS__, 'render_page' ) );
        add_submenu_page( 'bsxmh', 'Payment Links', 'Payment Links', 'bsxmh_send_reminders', 'bsxmh-payment-links', array( __CLASS__, 'render_links' ) );
        add_submenu_page( 'bsxmh', 'Email Logs', 'Email Logs', 'bsxmh_send_reminders', 'bsxmh-email-logs', array( __CLASS__, 'render_logs' ) );
    }

    public static function save_settings(): void {
        if ( ! current_user_can( 'bsxmh_send_reminders' ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'bsxmh_save_email_settings' );
        $old = get_option( self::SETTINGS_OPTION, array() );
        $settings = array_merge( $old, array(
            'enabled' => empty( $_POST['enabled'] ) ? 0 : 1,
            'from_name' => sanitize_text_field( wp_unslash( $_POST['from_name'] ?? '' ) ),
            'from_email' => sanitize_email( wp_unslash( $_POST['from_email'] ?? '' ) ),
            'queue_batch' => min( 100, max( 1, absint( $_POST['queue_batch'] ?? 20 ) ) ),
            'link_expiry_days' => min( 90, max( 1, absint( $_POST['link_expiry_days'] ?? 7 ) ) ),
            'scheduled_enabled' => empty( $_POST['scheduled_enabled'] ) ? 0 : 1,
            'reminder_day' => min( 28, max( 1, absint( $_POST['reminder_day'] ?? 5 ) ) ),
            'minimum_due' => max( 0, (float) ( $_POST['minimum_due'] ?? 0 ) ),
        ) );
        update_option( self::SETTINGS_OPTION, $settings, false );
        $templates = get_option( self::TEMPLATES_OPTION, array() );
        foreach ( array_keys( $templates ) as $key ) {
            $templates[ $key ]['subject'] = sanitize_text_field( wp_unslash( $_POST['templates'][ $key ]['subject'] ?? $templates[ $key ]['subject'] ) );
            $templates[ $key ]['body'] = sanitize_textarea_field( wp_unslash( $_POST['templates'][ $key ]['body'] ?? $templates[ $key ]['body'] ) );
        }
        update_option( self::TEMPLATES_OPTION, $templates, false );
        wp_safe_redirect( admin_url( 'admin.php?page=bsxmh-email&updated=1' ) ); exit;
    }

    public static function render_page(): void {
        $s = get_option( self::SETTINGS_OPTION, array() ); $t = get_option( self::TEMPLATES_OPTION, array() );
        echo '<div class="wrap bsxmh-wrap"><h1>Email Automation</h1>';
        if ( isset( $_GET['updated'] ) ) echo '<div class="notice notice-success"><p>Email settings saved.</p></div>';
        if ( isset( $_GET['queued'] ) ) echo '<div class="notice notice-success"><p>'.absint($_GET['queued']).' reminder email(s) queued.</p></div>';
        if ( isset( $_GET['test_sent'] ) ) echo '<div class="notice notice-success"><p>Test email accepted by wp_mail(). Check the recipient inbox and your SMTP plugin log.</p></div>';
        if ( isset( $_GET['test_failed'] ) ) echo '<div class="notice notice-error"><p>wp_mail() returned false. Check your SMTP plugin configuration and logs.</p></div>';
        $provider=self::mail_provider();
        echo '<div class="bsxmh-panel"><h2>WordPress Mail Status</h2><p><strong>Delivery method:</strong> wp_mail()</p><p><strong>SMTP/Mail plugin:</strong> '.esc_html($provider).'</p><p class="description">MemberHub does not store SMTP credentials. WP Mail SMTP, FluentSMTP, Post SMTP and similar plugins automatically handle MemberHub email because delivery uses WordPress wp_mail().</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="bsxmh_send_test_email">'; wp_nonce_field('bsxmh_send_test_email'); echo '<input type="email" name="test_email" value="'.esc_attr(wp_get_current_user()->user_email).'" required> '; submit_button('Send Test Email','secondary','submit',false); echo '</form></div>';
        echo '<div class="bsxmh-panel"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="bsxmh_save_email_settings">'; wp_nonce_field('bsxmh_save_email_settings');
        echo '<h2>Delivery & Reminder Settings</h2><table class="form-table">';
        echo '<tr><th>Enable Email</th><td><label><input type="checkbox" name="enabled" value="1" '.checked(!empty($s['enabled']),true,false).'> Enable queue delivery through wp_mail()</label></td></tr>';
        foreach(array('from_name'=>'From Name','from_email'=>'From Email','queue_batch'=>'Queue Batch Size','link_expiry_days'=>'Payment Link Expiry (days)','reminder_day'=>'Monthly Reminder Day','minimum_due'=>'Minimum Due Amount') as $k=>$l) echo '<tr><th>'.$l.'</th><td><input class="regular-text" name="'.$k.'" value="'.esc_attr($s[$k]??'').'"'.(in_array($k,array('queue_batch','link_expiry_days','reminder_day','minimum_due'),true)?' type="number" min="0"':'').'></td></tr>';
        echo '<tr><th>Scheduled Reminder</th><td><label><input type="checkbox" name="scheduled_enabled" value="1" '.checked(!empty($s['scheduled_enabled']),true,false).'> Queue reminders automatically each month</label></td></tr></table>';
        echo '<h2>Email Templates</h2><p>Variables: <code>{{member_name}}</code> <code>{{member_id}}</code> <code>{{organization_name}}</code> <code>{{due_months}}</code> <code>{{due_amount}}</code> <code>{{payment_link}}</code> <code>{{link_expiry}}</code> <code>{{payment_amount}}</code> <code>{{transaction_id}}</code> <code>{{receipt_url}}</code> <code>{{dashboard_url}}</code></p>';
        foreach($t as$key=>$template){echo '<div class="bsxmh-panel"><h3>'.esc_html(ucwords(str_replace('_',' ',$key))).'</h3><p><input class="large-text" name="templates['.esc_attr($key).'][subject]" value="'.esc_attr($template['subject']).'"></p><p><textarea class="large-text" rows="7" name="templates['.esc_attr($key).'][body]">'.esc_textarea($template['body']).'</textarea></p></div>';}
        submit_button('Save Email Automation'); echo '</form></div>';
        echo '<div class="bsxmh-panel"><h2>Bulk Due Reminder</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="bsxmh_queue_reminders">';wp_nonce_field('bsxmh_queue_reminders');echo '<p><label>Only members owing at least <input type="number" step="0.01" min="0" name="minimum_due" value="'.esc_attr($s['minimum_due']??0).'"></label></p>';submit_button('Queue Reminders for All Due Members','secondary');echo '</form></div></div>';
    }

    public static function queue_reminders(): void {
        if ( ! current_user_can( 'bsxmh_send_reminders' ) ) wp_die( 'Not allowed.' ); check_admin_referer('bsxmh_queue_reminders');
        $count = self::queue_due_reminders( max( 0, (float) ( $_POST['minimum_due'] ?? 0 ) ) );
        wp_safe_redirect( admin_url( 'admin.php?page=bsxmh-email&queued='.$count ) ); exit;
    }

    public static function scheduled_reminders(): void {
        $s=get_option(self::SETTINGS_OPTION,array()); if(empty($s['enabled'])||empty($s['scheduled_enabled']))return;
        $month=current_time('Y-m'); if(($s['last_scheduled_month']??'')===$month || (int)current_time('j') < (int)($s['reminder_day']??5))return;
        self::queue_due_reminders((float)($s['minimum_due']??0)); $s['last_scheduled_month']=$month; update_option(self::SETTINGS_OPTION,$s,false);
    }

    private static function queue_due_reminders(float $minimum): int {
        global $wpdb; $members=$wpdb->get_results("SELECT * FROM ".BSXMH_DB::table('members')." WHERE status='active' ORDER BY id ASC"); $count=0;
        foreach($members as$m){$statement=BSXMH_Payments::statement($m);$due=(float)($statement['total_due']??0);if($due<=$minimum||empty($statement['due']))continue;$link=self::create_payment_link((int)$m->user_id,array_column($statement['due'],'key'));if(is_wp_error($link))continue;$vars=self::member_vars((int)$m->user_id);$vars['due_months']=(string)count($statement['due']);$vars['due_amount']=BSXMH_Payments::currency_symbol().number_format_i18n($due,2);$vars['payment_link']=$link['url'];$vars['link_expiry']=$link['expires'];if(self::queue('payment_reminder',(int)$m->user_id,$vars,(int)$link['id']))$count++;}
        return $count;
    }

    public static function create_payment_link(int $user_id,array $periods=array()): array|WP_Error {
        global $wpdb; $member=BSXMH_Members::get_by_user($user_id); if(!$member||'active'!==$member->status)return new WP_Error('member','Active member required.');
        if(!$periods){$st=BSXMH_Payments::statement($member);$periods=array_column($st['due'],'key');} if(!$periods)return new WP_Error('due','No due months found.');
        $raw=bin2hex(random_bytes(24));$hash=hash('sha256',$raw);$s=get_option(self::SETTINGS_OPTION,array());$days=max(1,absint($s['link_expiry_days']??7));$expires=gmdate('Y-m-d H:i:s',time()+$days*DAY_IN_SECONDS);
        $wpdb->insert(BSXMH_DB::table('guest_tokens'),array('token_hash'=>$hash,'user_id'=>$user_id,'purpose'=>'due_payment','payload'=>wp_json_encode(array('periods'=>array_values($periods))),'expires_at'=>$expires,'created_by'=>get_current_user_id()?:null,'created_at'=>current_time('mysql',true)));
        if(!$wpdb->insert_id)return new WP_Error('db','Could not create payment link.');$settings=get_option('bsxmh_settings',array());$page=absint($settings['guest_payment_page_id']??0);$base=$page?get_permalink($page):home_url('/member-guest-payment/');
        return array('id'=>(int)$wpdb->insert_id,'url'=>add_query_arg('token',$raw,$base),'expires'=>get_date_from_gmt($expires,get_option('date_format').' '.get_option('time_format')));
    }

    public static function validate_token(string $raw) {
        global $wpdb; if(!preg_match('/^[a-f0-9]{48}$/',$raw))return new WP_Error('token','Invalid payment link.');$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.BSXMH_DB::table('guest_tokens').' WHERE token_hash=%s',hash('sha256',$raw)));if(!$row)return new WP_Error('token','Payment link not found.');if($row->revoked_at)return new WP_Error('token','This payment link has been revoked.');if($row->used_at)return new WP_Error('token','This payment link has already been used.');if($row->expires_at&&strtotime($row->expires_at.' UTC')<time())return new WP_Error('token','This payment link has expired.');return $row;
    }

    public static function guest_payment_shortcode(): string {
        $raw=sanitize_text_field(wp_unslash($_GET['token']??''));$token=self::validate_token($raw);if(is_wp_error($token))return '<div class="bsxmh-notice bsxmh-error">'.esc_html($token->get_error_message()).'</div>';$member=BSXMH_Members::get_by_user((int)$token->user_id);$u=get_userdata((int)$token->user_id);$payload=json_decode((string)$token->payload,true);$periods=(array)($payload['periods']??array());$paid=BSXMH_Payments::paid_month_keys((int)$token->user_id);$valid=array_values(array_diff($periods,$paid));if(!$valid)return '<div class="bsxmh-notice">All months in this link have already been paid.</div>';$amount=count($valid)*(float)$member->monthly_fee;
        ob_start();echo '<div class="bsxmh-form"><h3>Secure Membership Payment</h3><p><strong>Member:</strong> '.esc_html($u->display_name).' ('.esc_html($member->member_number).')</p><p><strong>Months:</strong> '.esc_html(implode(', ',array_map(static function($p){[$y,$m]=array_map('intval',explode('-',$p));return BSXMH_Payments::month_label($y,$m);},$valid))).'</p><p><strong>Total:</strong> '.esc_html(BSXMH_Payments::currency_symbol().number_format_i18n($amount,2)).'</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bsxmh_online_payment','bsxmh_online_nonce');echo '<input type="hidden" name="action" value="bsxmh_start_online_payment"><input type="hidden" name="bsxmh_online_type" value="membership"><input type="hidden" name="bsxmh_guest_token" value="'.esc_attr($raw).'">';foreach($valid as$p)echo '<input type="hidden" name="periods[]" value="'.esc_attr($p).'">';echo '<input type="hidden" name="bsxmh_return_url" value="'.esc_url(get_permalink()).'"><button type="submit">Pay with SSLCOMMERZ</button></form></div>';return (string)ob_get_clean();
    }

    public static function mark_token_used(int $token_id): void { global $wpdb;$wpdb->update(BSXMH_DB::table('guest_tokens'),array('used_at'=>current_time('mysql',true)),array('id'=>$token_id)); }

    public static function queue_template(string $type,int $user_id,array $vars=array(),int $related_id=0): bool { return self::queue($type,$user_id,array_merge(self::member_vars($user_id),$vars),$related_id); }
    private static function queue(string $type,int $user_id,array $vars,int $related_id=0): bool { global $wpdb;$templates=get_option(self::TEMPLATES_OPTION,array());if(empty($templates[$type]))return false;$u=get_userdata($user_id);if(!$u||!is_email($u->user_email))return false;$subject=self::replace($templates[$type]['subject'],$vars);$body=self::replace($templates[$type]['body'],$vars);return false!==$wpdb->insert(BSXMH_DB::table('email_logs'),array('user_id'=>$user_id,'recipient'=>$u->user_email,'email_type'=>$type,'subject'=>$subject,'body'=>$body,'status'=>'queued','related_id'=>$related_id?:null,'attempts'=>0,'scheduled_at'=>current_time('mysql'),'created_at'=>current_time('mysql'))); }
    private static function replace(string $text,array $vars): string {foreach($vars as$k=>$v)$text=str_replace('{{'.$k.'}}',(string)$v,$text);return $text;}
    private static function member_vars(int $user_id): array {$u=get_userdata($user_id);$m=BSXMH_Members::get_by_user($user_id);$s=get_option('bsxmh_settings',array());$dash=!empty($s['dashboard_page_id'])?get_permalink((int)$s['dashboard_page_id']):home_url('/member-dashboard/');return array('member_name'=>$u?$u->display_name:'','member_id'=>$m?$m->member_number:'','organization_name'=>$s['organization_name']??get_bloginfo('name'),'dashboard_url'=>$dash);}
    private static function queue_payment_success(int $payment_id): void {$p=BSXMH_Payments::get($payment_id);if(!$p||!$p->user_id)return;$r=BSXMH_Receipts::get_by_payment($payment_id);self::queue_template('payment_success',(int)$p->user_id,array('payment_amount'=>BSXMH_Payments::currency_symbol().number_format_i18n((float)$p->total_amount,2),'transaction_id'=>$p->transaction_id,'receipt_url'=>$r?BSXMH_Receipts::public_url($r):''),$payment_id);}

    public static function process_queue(): void {global$wpdb;$s=get_option(self::SETTINGS_OPTION,array());if(empty($s['enabled']))return;$limit=min(100,max(1,absint($s['queue_batch']??20)));$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM ".BSXMH_DB::table('email_logs')." WHERE status IN ('queued','retry') AND (scheduled_at IS NULL OR scheduled_at<=%s) ORDER BY id ASC LIMIT %d",current_time('mysql'),$limit));foreach($rows as$r){$wpdb->update(BSXMH_DB::table('email_logs'),array('status'=>'processing','attempts'=>(int)$r->attempts+1),array('id'=>$r->id));$headers=array('Content-Type: text/plain; charset=UTF-8');if(!empty($s['from_email']))$headers[]='From: '.sanitize_text_field($s['from_name']).' <'.sanitize_email($s['from_email']).'>';$ok=wp_mail($r->recipient,$r->subject,$r->body,$headers);$wpdb->update(BSXMH_DB::table('email_logs'),array('status'=>$ok?'sent':'failed','error_message'=>$ok?null:'wp_mail() returned false','sent_at'=>$ok?current_time('mysql'):null),array('id'=>$r->id));}}

    public static function retry_email(): void {if(!current_user_can('bsxmh_send_reminders'))wp_die('Not allowed.');$id=absint($_GET['id']??0);check_admin_referer('bsxmh_retry_email_'.$id);global$wpdb;$wpdb->update(BSXMH_DB::table('email_logs'),array('status'=>'retry','error_message'=>null),array('id'=>$id));wp_safe_redirect(admin_url('admin.php?page=bsxmh-email-logs'));exit;}
    public static function render_logs(): void {global$wpdb;$rows=$wpdb->get_results('SELECT * FROM '.BSXMH_DB::table('email_logs').' ORDER BY id DESC LIMIT 300');echo '<div class="wrap bsxmh-wrap"><h1>Email Logs</h1><div class="bsxmh-panel"><table class="widefat striped"><thead><tr><th>Created</th><th>Recipient</th><th>Type</th><th>Subject</th><th>Status</th><th>Attempts</th><th>Action</th></tr></thead><tbody>';if(!$rows)echo '<tr><td colspan="7">No email logs yet.</td></tr>';foreach($rows as$r){$retry=in_array($r->status,array('failed','queued'),true)?'<a href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=bsxmh_retry_email&id='.$r->id),'bsxmh_retry_email_'.$r->id)).'">Retry</a>':'—';echo '<tr><td>'.esc_html($r->created_at).'</td><td>'.esc_html($r->recipient).'</td><td>'.esc_html($r->email_type).'</td><td>'.esc_html($r->subject).'</td><td>'.esc_html($r->status).($r->error_message?'<br><small>'.esc_html($r->error_message).'</small>':'').'</td><td>'.absint($r->attempts).'</td><td>'.$retry.'</td></tr>';}echo '</tbody></table></div></div>';}

    public static function render_links(): void {global$wpdb;$members=$wpdb->get_results("SELECT m.*,u.display_name,u.user_email FROM ".BSXMH_DB::table('members')." m JOIN {$wpdb->users} u ON u.ID=m.user_id WHERE m.status='active' ORDER BY u.display_name");$links=$wpdb->get_results("SELECT t.*,u.display_name FROM ".BSXMH_DB::table('guest_tokens')." t LEFT JOIN {$wpdb->users} u ON u.ID=t.user_id WHERE t.purpose='due_payment' ORDER BY t.id DESC LIMIT 200");echo '<div class="wrap bsxmh-wrap"><h1>Secure Payment Links</h1>';if(isset($_GET['link']))echo '<div class="notice notice-success"><p>Payment link: <input class="large-text" readonly value="'.esc_attr(rawurldecode($_GET['link'])).'"></p></div>';echo '<div class="bsxmh-panel"><h2>Generate Link</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="bsxmh_generate_payment_link">';wp_nonce_field('bsxmh_generate_payment_link');echo '<select name="user_id" required><option value="">Choose active member</option>';foreach($members as$m)echo '<option value="'.$m->user_id.'">'.esc_html($m->display_name.' — '.$m->member_number).'</option>';echo '</select> ';submit_button('Generate Due Payment Link','primary','submit',false);echo '</form></div><div class="bsxmh-panel"><table class="widefat striped"><thead><tr><th>Member</th><th>Created</th><th>Expires</th><th>Status</th><th>Action</th></tr></thead><tbody>';if(!$links)echo '<tr><td colspan="5">No links yet.</td></tr>';foreach($links as$l){$status=$l->revoked_at?'Revoked':($l->used_at?'Used':(strtotime($l->expires_at.' UTC')<time()?'Expired':'Active'));$action='Active'===$status?'<a href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=bsxmh_revoke_payment_link&id='.$l->id),'bsxmh_revoke_payment_link_'.$l->id)).'">Revoke</a>':'—';echo '<tr><td>'.esc_html($l->display_name?:('User #'.$l->user_id)).'</td><td>'.esc_html($l->created_at).'</td><td>'.esc_html($l->expires_at).'</td><td>'.esc_html($status).'</td><td>'.$action.'</td></tr>';}echo '</tbody></table></div></div>';}
    public static function generate_link_action(): void {if(!current_user_can('bsxmh_send_reminders'))wp_die('Not allowed.');check_admin_referer('bsxmh_generate_payment_link');$link=self::create_payment_link(absint($_POST['user_id']??0));$url=is_wp_error($link)?admin_url('admin.php?page=bsxmh-payment-links&error='.rawurlencode($link->get_error_message())):admin_url('admin.php?page=bsxmh-payment-links&link='.rawurlencode($link['url']));wp_safe_redirect($url);exit;}
    public static function revoke_link_action(): void {if(!current_user_can('bsxmh_send_reminders'))wp_die('Not allowed.');$id=absint($_GET['id']??0);check_admin_referer('bsxmh_revoke_payment_link_'.$id);global$wpdb;$wpdb->update(BSXMH_DB::table('guest_tokens'),array('revoked_at'=>current_time('mysql',true)),array('id'=>$id));wp_safe_redirect(admin_url('admin.php?page=bsxmh-payment-links'));exit;}

    public static function mail_provider(): string {
        if ( ! function_exists( 'is_plugin_active' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $known = array(
            'wp-mail-smtp/wp_mail_smtp.php' => 'WP Mail SMTP',
            'fluent-smtp/fluent-smtp.php' => 'FluentSMTP',
            'post-smtp/postman-smtp.php' => 'Post SMTP',
            'easy-wp-smtp/easy-wp-smtp.php' => 'Easy WP SMTP',
            'brevo/brevo.php' => 'Brevo',
        );
        foreach ( $known as $plugin => $label ) if ( is_plugin_active( $plugin ) ) return $label . ' detected';
        return 'No recognized SMTP plugin detected (WordPress wp_mail() will still be used)';
    }

    public static function send_test_email(): void {
        if ( ! current_user_can( 'bsxmh_send_reminders' ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'bsxmh_send_test_email' );
        $to = sanitize_email( wp_unslash( $_POST['test_email'] ?? '' ) );
        if ( ! is_email( $to ) ) wp_safe_redirect( admin_url( 'admin.php?page=bsxmh-email&test_failed=1' ) );
        $settings = get_option( self::SETTINGS_OPTION, array() );
        $headers = array( 'Content-Type: text/plain; charset=UTF-8' );
        if ( ! empty( $settings['from_email'] ) ) $headers[] = 'From: ' . sanitize_text_field( $settings['from_name'] ?? get_bloginfo('name') ) . ' <' . sanitize_email( $settings['from_email'] ) . '>';
        $ok = wp_mail( $to, 'MemberHub test email - ' . get_bloginfo( 'name' ), "This is a test email from BackspaceX MemberHub.\n\nIf you received it, WordPress wp_mail() and your configured mail/SMTP plugin are working." , $headers );
        wp_safe_redirect( admin_url( 'admin.php?page=bsxmh-email&' . ( $ok ? 'test_sent=1' : 'test_failed=1' ) ) ); exit;
    }

}
