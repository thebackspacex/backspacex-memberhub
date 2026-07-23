<?php

defined( 'ABSPATH' ) || exit;

final class BSXMH_Admin {
    public function register(): void {
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_filter( 'plugin_action_links_' . BSXMH_BASENAME, array( $this, 'action_links' ) );
        add_action( 'admin_post_bsxmh_save_member', array( $this, 'save_member' ) );
        add_action( 'admin_post_bsxmh_member_status', array( $this, 'change_member_status' ) );
        add_action( 'admin_post_bsxmh_save_manual_payment', array( $this, 'save_manual_payment' ) );
        add_action( 'admin_post_bsxmh_view_receipt', array( $this, 'view_receipt' ) );
        add_action( 'admin_post_bsxmh_migrate_receipts', array( $this, 'migrate_receipts' ) );
        add_action( 'admin_post_bsxmh_save_contribution', array( $this, 'save_contribution' ) );
        add_action( 'admin_post_bsxmh_save_fund', array( $this, 'save_fund' ) );
        add_action( 'admin_post_bsxmh_save_event', array( $this, 'save_event' ) );
        add_action( 'admin_post_bsxmh_save_event_donation', array( $this, 'save_event_donation' ) );
    }

    public function admin_menu(): void {
        add_menu_page( 'MemberHub', 'MemberHub', 'manage_bsxmh', 'bsxmh', array( $this, 'render_dashboard' ), 'dashicons-groups', 26 );
        add_submenu_page( 'bsxmh', 'Dashboard', 'Dashboard', 'manage_bsxmh', 'bsxmh', array( $this, 'render_dashboard' ) );
        add_submenu_page( 'bsxmh', 'Members', 'Members', 'bsxmh_manage_members', 'bsxmh-members', array( $this, 'render_members' ) );
        add_submenu_page( 'bsxmh', 'Payments', 'Payments', 'bsxmh_manage_payments', 'bsxmh-payments', array( $this, 'render_payments' ) );
        add_submenu_page( 'bsxmh', 'Contributions', 'Contributions', 'bsxmh_manage_payments', 'bsxmh-contributions', array( $this, 'render_contributions' ) );
        add_submenu_page( 'bsxmh', 'Funds', 'Funds', 'bsxmh_manage_events', 'bsxmh-funds', array( $this, 'render_funds' ) );
        add_submenu_page( 'bsxmh', 'Events', 'Events', 'bsxmh_manage_events', 'bsxmh-events', array( $this, 'render_events' ) );
        add_submenu_page( 'bsxmh', 'Event Donations', 'Event Donations', 'bsxmh_manage_events', 'bsxmh-event-donations', array( $this, 'render_event_donations' ) );
        add_submenu_page( 'bsxmh', 'Finance', 'Finance', 'bsxmh_manage_finance', 'bsxmh-finance', array( 'BSXMH_Finance', 'render_finance' ) );
        add_submenu_page( 'bsxmh', 'Reports', 'Reports', 'bsxmh_view_reports', 'bsxmh-reports', array( 'BSXMH_Finance', 'render_reports' ) );
        add_submenu_page( 'bsxmh', 'Form Builder', 'Form Builder', 'bsxmh_manage_settings', 'bsxmh-form-builder', array( $this, 'render_form_builder' ) );
        add_submenu_page( 'bsxmh', 'Settings', 'Settings', 'bsxmh_manage_settings', 'bsxmh-settings', array( $this, 'render_settings' ) );
        add_submenu_page( 'bsxmh', 'System Health', 'System Health', 'manage_bsxmh', 'bsxmh-system-health', array( $this, 'render_health' ) );
    }

    public function register_settings(): void { register_setting( 'bsxmh_settings_group', 'bsxmh_settings', array( $this, 'sanitize_settings' ) ); }
    public function sanitize_settings( array $input ): array {
        $old = get_option( 'bsxmh_settings', array() );
        return array_merge( $old, array(
            'organization_name' => sanitize_text_field( $input['organization_name'] ?? '' ),
            'organization_email' => sanitize_email( $input['organization_email'] ?? '' ),
            'organization_phone' => sanitize_text_field( $input['organization_phone'] ?? '' ),
            'organization_address' => sanitize_textarea_field( $input['organization_address'] ?? '' ),
            'currency' => strtoupper( sanitize_key( $input['currency'] ?? 'BDT' ) ),
            'timezone' => sanitize_text_field( $input['timezone'] ?? 'Asia/Dhaka' ),
            'organization_start_month' => min( 12, max( 1, absint( $input['organization_start_month'] ?? 1 ) ) ),
            'organization_start_year' => min( 9999, max( 1900, absint( $input['organization_start_year'] ?? current_time( 'Y' ) ) ) ),
            'default_monthly_fee' => number_format( max( 0, (float) ( $input['default_monthly_fee'] ?? 100 ) ), 2, '.', '' ),
            'member_id_prefix' => strtoupper( sanitize_key( $input['member_id_prefix'] ?? 'MH' ) ),
            'registration_enabled' => empty( $input['registration_enabled'] ) ? 0 : 1,
            'registration_requires_approval' => empty( $input['registration_requires_approval'] ) ? 0 : 1,
            'public_dashboard_visibility' => in_array( $input['public_dashboard_visibility'] ?? '', array( 'public', 'members', 'admin', 'hidden' ), true ) ? $input['public_dashboard_visibility'] : 'public',
            'receipt_prefix' => strtoupper( sanitize_key( $input['receipt_prefix'] ?? 'BSXMH' ) ),
            'receipt_title' => sanitize_text_field( $input['receipt_title'] ?? 'Payment Receipt' ),
            'receipt_header' => sanitize_textarea_field( $input['receipt_header'] ?? '' ),
            'receipt_footer' => sanitize_textarea_field( $input['receipt_footer'] ?? '' ),
            'receipt_thank_you' => sanitize_text_field( $input['receipt_thank_you'] ?? 'Thank you for your payment.' ),
            'organization_logo_url' => esc_url_raw( $input['organization_logo_url'] ?? '' ),
            'voucher_prefix' => strtoupper( sanitize_key( $input['voucher_prefix'] ?? 'BSXV' ) ),
            'voucher_title' => sanitize_text_field( $input['voucher_title'] ?? 'Expense Voucher' ),
        ) );
    }

    public function enqueue_assets( string $hook ): void {
        if ( false !== strpos( $hook, 'bsxmh' ) ) {
            wp_enqueue_style( 'bsxmh-admin', BSXMH_URL . 'assets/css/admin.css', array(), BSXMH_VERSION );
            wp_enqueue_script( 'bsxmh-admin-member-search', BSXMH_URL . 'assets/js/admin-member-search.js', array(), BSXMH_VERSION, true );

            global $wpdb;
            $rows = $wpdb->get_results(
                "SELECT m.id, m.member_number, m.phone, u.display_name, u.user_email
                 FROM " . BSXMH_DB::table( 'members' ) . " m
                 LEFT JOIN {$wpdb->users} u ON u.ID = m.user_id
                 ORDER BY u.display_name ASC"
            );
            $member_index = array();
            foreach ( (array) $rows as $row ) {
                $member_index[] = array(
                    'id'     => (string) absint( $row->id ),
                    'label'  => trim( (string) $row->member_number . ' — ' . (string) $row->display_name ),
                    'search' => strtolower( trim( implode( ' ', array_filter( array(
                        (string) $row->member_number,
                        (string) $row->display_name,
                        (string) $row->user_email,
                        (string) $row->phone,
                    ) ) ) ) ),
                );
            }
            wp_localize_script( 'bsxmh-admin-member-search', 'BSXMHMemberSearch', array(
                'members'    => $member_index,
                'placeholder'=> __( 'Search by name, member ID, email or mobile', 'bsx-memberhub' ),
                'noResults'  => __( 'No matching members found.', 'bsx-memberhub' ),
                'clear'      => __( 'Clear search', 'bsx-memberhub' ),
            ) );
        }
        if ( false !== strpos( $hook, 'bsxmh-form-builder' ) ) {
            wp_enqueue_script( 'jquery-ui-sortable' );
        }
    }
    public function action_links( array $links ): array { array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=bsxmh-settings' ) ) . '">Settings</a>' ); return $links; }

    public function save_member(): void {
        if ( ! current_user_can( 'bsxmh_manage_members' ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'bsxmh_save_member' );
        $data = wp_unslash( $_POST ); $id = absint( $data['member_id'] ?? 0 );
        $result = $id ? BSXMH_Members::update( $id, $data ) : BSXMH_Members::create( $data );
        if ( ! is_wp_error( $result ) ) {
            $target_user_id = $id ? (int) BSXMH_Members::get( $id )->user_id : (int) $result;
            $custom_result = BSXMH_Form_Builder::validate_and_save( $target_user_id, $_POST, 'admin' );
            if ( is_wp_error( $custom_result ) ) {
                if ( ! $id ) { global $wpdb; $wpdb->delete( BSXMH_DB::table('members'), array('user_id'=>$target_user_id), array('%d') ); require_once ABSPATH.'wp-admin/includes/user.php'; wp_delete_user($target_user_id); }
                $result = $custom_result;
            }
        }
        $args = array( 'page' => 'bsxmh-members' );
        if ( is_wp_error( $result ) ) { $args['bsxmh_error'] = rawurlencode( $result->get_error_message() ); $args['action'] = $id ? 'edit' : 'add'; if ( $id ) $args['member_id'] = $id; }
        else $args['bsxmh_notice'] = $id ? 'updated' : 'created';
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) ); exit;
    }

    public function change_member_status(): void {
        if ( ! current_user_can( 'bsxmh_manage_members' ) ) wp_die( 'Not allowed.' );
        $id = absint( $_GET['member_id'] ?? 0 ); check_admin_referer( 'bsxmh_member_status_' . $id );
        $m = BSXMH_Members::get( $id );
        if ( $m ) { $u = get_userdata( (int) $m->user_id ); $new_status=sanitize_key($_GET['status']??'pending'); BSXMH_Members::update( $id, array( 'display_name'=>$u->display_name,'first_name'=>$u->first_name,'last_name'=>$u->last_name,'email'=>$u->user_email,'phone'=>get_user_meta((int)$m->user_id,'bsxmh_phone',true),'status'=>$new_status,'category_id'=>$m->category_id,'join_date'=>$m->join_date,'fee_start_date'=>$m->fee_start_date,'monthly_fee'=>$m->monthly_fee,'admin_notes'=>$m->admin_notes ) ); if('active'===$new_status&&'active'!==$m->status)do_action('bsxmh_member_approved',(int)$m->user_id); }
        wp_safe_redirect( admin_url( 'admin.php?page=bsxmh-members&bsxmh_notice=status' ) ); exit;
    }

    public function save_manual_payment(): void {
        if ( ! current_user_can( 'bsxmh_manage_payments' ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'bsxmh_save_manual_payment' );
        $result = BSXMH_Payments::create_manual( $_POST );
        $args = array( 'page' => 'bsxmh-payments' );
        if ( is_wp_error( $result ) ) { $args['action']='add'; $args['member_id']=absint($_POST['member_id']??0); $args['bsxmh_error']=rawurlencode($result->get_error_message()); }
        else { $args['bsxmh_notice']='payment-created'; $args['payment_id']=(int)$result; }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) ); exit;
    }

    public function view_receipt(): void {
        if ( ! current_user_can( 'bsxmh_manage_payments' ) ) wp_die( 'Not allowed.' );
        $id = absint( $_GET['receipt_id'] ?? 0 );
        check_admin_referer( 'bsxmh_view_receipt_' . $id );
        $receipt = BSXMH_Receipts::get( $id );
        if ( ! $receipt ) wp_die( esc_html__( 'Receipt not found.', 'bsx-memberhub' ) );
        BSXMH_Receipts::render( $receipt, false );
    }

    public function migrate_receipts(): void {
        if ( ! current_user_can( 'bsxmh_manage_payments' ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'bsxmh_migrate_receipts' );
        $count = BSXMH_Receipts::ensure_all();
        wp_safe_redirect( add_query_arg( array( 'page'=>'bsxmh-payments', 'bsxmh_notice'=>'receipts-migrated', 'count'=>$count ), admin_url( 'admin.php' ) ) ); exit;
    }

    public function render_dashboard(): void {
        global $wpdb; $sym=BSXMH_Payments::currency_symbol();
        $members=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.BSXMH_DB::table('members'));
        $active=(int)$wpdb->get_var("SELECT COUNT(*) FROM ".BSXMH_DB::table('members')." WHERE status='active'");
        $pending=(int)$wpdb->get_var("SELECT COUNT(*) FROM ".BSXMH_DB::table('members')." WHERE status='pending'");
        $income=(float)$wpdb->get_var("SELECT COALESCE(SUM(total_amount),0) FROM ".BSXMH_DB::table('payments')." WHERE status='paid'");$membership=(float)$wpdb->get_var("SELECT COALESCE(SUM(total_amount),0) FROM ".BSXMH_DB::table('payments')." WHERE status='paid' AND payment_type='membership'");$extra=(float)$wpdb->get_var("SELECT COALESCE(SUM(total_amount),0) FROM ".BSXMH_DB::table('payments')." WHERE status='paid' AND payment_type='extra_contribution'");$event=(float)$wpdb->get_var("SELECT COALESCE(SUM(total_amount),0) FROM ".BSXMH_DB::table('payments')." WHERE status='paid' AND payment_type='event_donation'");
        $month=(float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(total_amount),0) FROM ".BSXMH_DB::table('payments')." WHERE status='paid' AND payment_date >= %s", current_time('Y-m-01 00:00:00')));
        $due=0; $due_members=0; $rows=$wpdb->get_results("SELECT * FROM ".BSXMH_DB::table('members')." WHERE status='active'"); foreach($rows as $m){$s=BSXMH_Payments::statement($m);$due+=$s['total_due'];if($s['due'])$due_members++;}
        echo '<div class="wrap bsxmh-wrap"><h1>BackspaceX MemberHub <span class="bsxmh-version">v'.esc_html(BSXMH_VERSION).'</span></h1><div class="bsxmh-cards">';
        foreach(array('Total Members'=>number_format_i18n($members),'Active Members'=>number_format_i18n($active),'Pending Approval'=>number_format_i18n($pending),'This Month Collection'=>$sym.number_format_i18n($month,2),'Membership Collection'=>$sym.number_format_i18n($membership,2),'Extra Contribution'=>$sym.number_format_i18n($extra,2),'Total Collection'=>$sym.number_format_i18n($income,2),'Total Due'=>$sym.number_format_i18n($due,2),'Due Members'=>number_format_i18n($due_members)) as $l=>$v)$this->card($l,$v);
        echo '</div><div class="bsxmh-panel"><h2>Membership, Contributions & Funds</h2><p>Membership fees, extra contributions, fund allocation, receipts and fund balances are active.</p></div></div>';
    }
    private function card(string $l,string $v):void{echo '<div class="bsxmh-card"><span>'.esc_html($l).'</span><strong>'.esc_html($v).'</strong></div>';}

    public function render_members(): void {
        if ( isset($_GET['action']) && in_array($_GET['action'],array('add','edit','statement'),true) ) { if('statement'===$_GET['action'])$this->render_member_statement();else $this->render_member_form(); return; }
        BSXMH_Payment_Control::render();
    }

    private function render_member_form():void{
        $id=absint($_GET['member_id']??0);$m=$id?BSXMH_Members::get($id):null;$u=$m?get_userdata((int)$m->user_id):null;$s=get_option('bsxmh_settings',array());$this->notices();echo '<div class="wrap bsxmh-wrap"><h1>'.($m?'Edit Member':'Add New Member').'</h1><form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="bsxmh_save_member"><input type="hidden" name="member_id" value="'.esc_attr($id).'">';wp_nonce_field('bsxmh_save_member');echo '<div class="bsxmh-panel"><table class="form-table">';$this->input('display_name','Full Name',$u?$u->display_name:'','text',true);$this->input('email','Email',$u?$u->user_email:'','email',true);$this->input('phone','Mobile Number',$m?get_user_meta((int)$m->user_id,'bsxmh_phone',true):'');if(!$m)$this->input('password','Password','','password');echo '<tr><th>Status</th><td><select name="status">';$cur=$m?$m->status:'active';foreach($this->statuses() as $k=>$l)echo '<option value="'.esc_attr($k).'" '.selected($cur,$k,false).'>'.esc_html($l).'</option>';echo '</select></td></tr>';$this->input('join_date','Join Date',$m?$m->join_date:current_time('Y-m-d'),'date');$this->input('fee_start_date','Fee Start Date',$m?$m->fee_start_date:current_time('Y-m-d'),'date');$this->input('monthly_fee','Monthly Fee',$m?$m->monthly_fee:($s['default_monthly_fee']??'100.00'),'number');echo '<tr><th>Admin Notes</th><td><textarea class="large-text" rows="4" name="admin_notes">'.esc_textarea($m?$m->admin_notes:'').'</textarea></td></tr></table><h2>Custom Profile Fields</h2>'.BSXMH_Form_Builder::render_fields('admin',$m?(int)$m->user_id:0).'</div>';submit_button($m?'Update Member':'Create Member');echo ' <a class="button" href="'.esc_url(admin_url('admin.php?page=bsxmh-members')).'">Cancel</a></form></div>';
    }

    private function render_member_statement():void{
        $m=BSXMH_Members::get(absint($_GET['member_id']??0));if(!$m){echo '<div class="wrap"><p>Member not found.</p></div>';return;}$u=get_userdata((int)$m->user_id);$s=BSXMH_Payments::statement($m);$sym=BSXMH_Payments::currency_symbol();global$wpdb;$history=$wpdb->get_results($wpdb->prepare("SELECT * FROM ".BSXMH_DB::table('payments')." WHERE user_id=%d ORDER BY payment_date DESC,id DESC",$m->user_id));
        echo '<div class="wrap bsxmh-wrap"><h1>Member Statement: '.esc_html($u?$u->display_name:$m->member_number).'</h1><p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=bsxmh-payments&action=add&member_id='.$m->id)).'">Add Payment</a> <a class="button" href="'.esc_url(admin_url('admin.php?page=bsxmh-members')).'">Back to Members</a></p><div class="bsxmh-cards">';foreach(array('Total Paid'=>$sym.number_format_i18n($s['total_paid'],2),'Total Due'=>$sym.number_format_i18n($s['total_due'],2),'Paid Months'=>count($s['paid']),'Due Months'=>count($s['due']),'Advance Months'=>count($s['advance']))as$l=>$v)$this->card($l,(string)$v);echo '</div><div class="bsxmh-panel"><h2>Due Months</h2><p>'.$this->period_badges($s['due'],'No dues.').'</p><h2>Paid Months</h2><p>'.$this->period_badges($s['paid'],'No paid months yet.').'</p><h2>Advance Months</h2><p>'.$this->period_badges($s['advance'],'No advance payments.').'</p></div><div class="bsxmh-panel"><h2>Payment Timeline</h2>'.$this->history_table($history).'</div></div>';
    }

    public function render_payments():void{
        if(isset($_GET['action'])&&'add'===$_GET['action']){$this->render_payment_form();return;}global$wpdb;$member_id=absint($_GET['member_id']??0);$month=sanitize_text_field($_GET['month']??'');$status=sanitize_key($_GET['status']??'');$from=sanitize_text_field($_GET['date_from']??'');$to=sanitize_text_field($_GET['date_to']??'');$where=array('1=1');$params=array();if($member_id){$m=BSXMH_Members::get($member_id);if($m){$where[]='p.user_id=%d';$params[]=$m->user_id;}}if($status){$where[]='p.status=%s';$params[]=$status;}if($from){$where[]='p.payment_date >= %s';$params[]=$from.' 00:00:00';}if($to){$where[]='p.payment_date <= %s';$params[]=$to.' 23:59:59';}if(preg_match('/^\d{4}-\d{2}$/',$month,$mm)){$where[]='EXISTS (SELECT 1 FROM '.BSXMH_DB::table('payment_items').' pi WHERE pi.payment_id=p.id AND CONCAT(pi.period_year,"-",LPAD(pi.period_month,2,"0"))=%s)';$params[]=$month;}$sql="SELECT p.*,u.display_name,m.member_number FROM ".BSXMH_DB::table('payments')." p LEFT JOIN {$wpdb->users} u ON u.ID=p.user_id LEFT JOIN ".BSXMH_DB::table('members')." m ON m.user_id=p.user_id WHERE ".implode(' AND ',$where).' ORDER BY p.payment_date DESC,p.id DESC';if($params)$sql=$wpdb->prepare($sql,$params);$rows=$wpdb->get_results($sql);$members=$wpdb->get_results("SELECT m.id,m.member_number,u.display_name FROM ".BSXMH_DB::table('members')." m LEFT JOIN {$wpdb->users} u ON u.ID=m.user_id ORDER BY u.display_name");$this->notices();echo '<div class="wrap bsxmh-wrap"><h1>Payments <a class="page-title-action" href="'.esc_url(admin_url('admin.php?page=bsxmh-payments&action=add')).'">Add Manual Payment</a></h1><form method="get"><input type="hidden" name="page" value="bsxmh-payments"><select name="member_id" class="bsxmh-member-search"><option value="">All members</option>';foreach($members as$m)echo '<option value="'.$m->id.'" '.selected($member_id,$m->id,false).'>'.esc_html($m->member_number.' — '.$m->display_name).'</option>';echo '</select> <input type="month" name="month" value="'.esc_attr($month).'"> <select name="status"><option value="">All statuses</option>';foreach(array('paid','pending','failed','cancelled','refunded')as$st)echo '<option '.selected($status,$st,false).' value="'.$st.'">'.ucfirst($st).'</option>';echo '</select> <input type="date" name="date_from" value="'.esc_attr($from).'"> <input type="date" name="date_to" value="'.esc_attr($to).'"> <button class="button">Filter</button></form>'.$this->history_table($rows,true).'</div>';
    }

    private function render_payment_form():void{
        global$wpdb;$selected=absint($_GET['member_id']??0);$members=$wpdb->get_results("SELECT m.*,u.display_name FROM ".BSXMH_DB::table('members')." m LEFT JOIN {$wpdb->users} u ON u.ID=m.user_id WHERE m.status IN ('active','inactive','suspended') ORDER BY u.display_name");$chosen=$selected?BSXMH_Members::get($selected):null;$periods=$chosen?BSXMH_Payments::eligible_months($chosen,wp_date('Y-m',strtotime('+12 months',current_time('timestamp')))):array();$paid=$chosen?BSXMH_Payments::paid_month_keys((int)$chosen->user_id):array();$this->notices();echo '<div class="wrap bsxmh-wrap"><h1>Add Manual Payment</h1><div class="bsxmh-panel"><form method="get"><input type="hidden" name="page" value="bsxmh-payments"><input type="hidden" name="action" value="add"><label><strong>Select Member</strong></label> <select name="member_id" class="bsxmh-member-search" required><option value="">Choose member</option>';foreach($members as$m)echo '<option value="'.$m->id.'" '.selected($selected,$m->id,false).'>'.esc_html($m->member_number.' — '.$m->display_name).'</option>';echo '</select> <button class="button">Load Months</button></form></div>';if($chosen){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="bsxmh_save_manual_payment"><input type="hidden" name="member_id" value="'.$selected.'">';wp_nonce_field('bsxmh_save_manual_payment');echo '<div class="bsxmh-panel"><p><strong>Monthly fee:</strong> '.esc_html(BSXMH_Payments::currency_symbol().number_format_i18n((float)$chosen->monthly_fee,2)).'</p><table class="form-table"><tr><th>Payment Months</th><td><div class="bsxmh-month-grid">';foreach($periods as$p){$is=in_array($p['key'],$paid,true);echo '<label><input type="checkbox" name="periods[]" value="'.esc_attr($p['key']).'"> '.esc_html(BSXMH_Payments::month_label($p['year'],$p['month'])).($is?' <em>(Paid)</em>':'').'</label>';}echo '</div><p class="description">Current dues and up to 12 future months are available for advance payment.</p></td></tr>';$this->input('amount','Total Amount','','number');$this->input('payment_date','Payment Date',current_time('Y-m-d'),'date');echo '<tr><th>Payment Method</th><td><select name="payment_method"><option value="cash">Cash</option><option value="bank">Bank Transfer</option><option value="mobile_banking">Mobile Banking</option><option value="cheque">Cheque</option><option value="other">Other</option></select></td></tr>';$this->input('reference_number','Reference Number','');$this->input('received_by','Received By',wp_get_current_user()->display_name);echo '<tr><th>Notes</th><td><textarea class="large-text" rows="3" name="notes"></textarea></td></tr><tr><th>Duplicate Override</th><td><label><input type="checkbox" name="duplicate_override" value="1"> Allow a second paid entry for an already-paid month</label><p class="description">Use only for a deliberate adjustment. The override is recorded in the audit log.</p></td></tr></table></div>';submit_button('Save Paid Payment');echo '</form>';}echo '</div>';
    }

    public function render_reports():void{global$wpdb;$total=(float)$wpdb->get_var("SELECT COALESCE(SUM(total_amount),0) FROM ".BSXMH_DB::table('payments')." WHERE status='paid'");$extra=(float)$wpdb->get_var("SELECT COALESCE(SUM(total_amount),0) FROM ".BSXMH_DB::table('payments')." WHERE status='paid' AND payment_type='extra_contribution'");$event=(float)$wpdb->get_var("SELECT COALESCE(SUM(total_amount),0) FROM ".BSXMH_DB::table('payments')." WHERE status='paid' AND payment_type='event_donation'");$count=(int)$wpdb->get_var("SELECT COUNT(*) FROM ".BSXMH_DB::table('payments')." WHERE status='paid'");echo '<div class="wrap bsxmh-wrap"><h1>Membership Reports</h1><div class="bsxmh-cards">';$this->card('Paid Transactions',number_format_i18n($count));$this->card('Total Collection',BSXMH_Payments::currency_symbol().number_format_i18n($total,2));$this->card('Extra Contribution',BSXMH_Payments::currency_symbol().number_format_i18n($extra,2));$this->card('Event Collection',BSXMH_Payments::currency_symbol().number_format_i18n($event,2));echo '</div><div class="bsxmh-panel"><p>Detailed monthly, yearly and CSV reports will be expanded in the final reporting milestone. Member statements and payment filters are available now.</p></div></div>';}

    private function history_table(array $rows,bool $with_member=false):string{if(!$rows)return '<p>No payments found.</p>';$out='<table class="widefat striped"><thead><tr>'.($with_member?'<th>Member</th>':'').'<th>Transaction</th><th>Type</th><th>Date</th><th>Details</th><th>Amount</th><th>Method</th><th>Status</th><th>Receipt</th></tr></thead><tbody>';foreach($rows as$p){$items=BSXMH_Payments::items((int)$p->id);$labels=array_map(fn($i)=>$i->description,$items);$meta=json_decode((string)$p->metadata,true);$receipt=BSXMH_Receipts::get_by_payment((int)$p->id);$action=$receipt?'<a href="'.esc_url(BSXMH_Receipts::admin_url((int)$receipt->id)).'">View / Print</a> | <a target="_blank" href="'.esc_url(BSXMH_Receipts::public_url($receipt)).'">Verify</a>':'—';$out.='<tr>'.($with_member?'<td>'.esc_html(($p->member_number??'').' — '.($p->display_name??'')).'</td>':'').'<td><code>'.esc_html($p->transaction_id).'</code></td><td>'.esc_html(ucwords(str_replace('_',' ',$p->payment_type))).'</td><td>'.esc_html($p->payment_date).'</td><td>'.esc_html(implode(', ',$labels)).'</td><td>'.esc_html(BSXMH_Payments::currency_symbol().number_format_i18n((float)$p->total_amount,2)).'</td><td>'.esc_html(ucwords(str_replace('_',' ',$meta['method']??$p->gateway))).'</td><td>'.esc_html(ucfirst($p->status)).'</td><td>'.$action.'</td></tr>';}$out.='</tbody></table>';return$out;}
    private function period_badges(array $periods,string $empty):string{if(!$periods)return esc_html($empty);return implode(' ',array_map(fn($p)=>'<span class="bsxmh-badge">'.esc_html(BSXMH_Payments::month_label($p['year'],$p['month'])).'</span>',$periods));}
    private function input(string$key,string$label,$value,string$type='text',bool$required=false):void{echo '<tr><th><label for="'.esc_attr($key).'">'.esc_html($label).'</label></th><td><input class="regular-text" type="'.esc_attr($type).'" id="'.esc_attr($key).'" name="'.esc_attr($key).'" value="'.esc_attr($value).'"'.($required?' required':'').('number'===$type?' step="0.01" min="0"':'').'></td></tr>';}
    private function statuses():array{return array('pending'=>'Pending','active'=>'Active','inactive'=>'Inactive','suspended'=>'Suspended');}
    private function notices():void{if(!empty($_GET['bsxmh_error']))echo '<div class="notice notice-error"><p>'.esc_html(rawurldecode(sanitize_text_field(wp_unslash($_GET['bsxmh_error'])))).'</p></div>';elseif(!empty($_GET['bsxmh_notice']))echo '<div class="notice notice-success is-dismissible"><p>Information saved successfully.</p></div>';}
    public function render_form_builder(): void { BSXMH_Form_Builder::render_admin(); }

    public function save_event(): void {
        if ( ! current_user_can( 'bsxmh_manage_events' ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'bsxmh_save_event' );
        $result = BSXMH_Events::save( $_POST );
        $args = array( 'page' => 'bsxmh-events' );
        if ( is_wp_error( $result ) ) { $args['bsxmh_error'] = rawurlencode( $result->get_error_message() ); $args['action']='edit'; $args['event_id']=absint($_POST['event_id']??0); }
        else $args['bsxmh_notice']='event-saved';
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) ); exit;
    }

    public function save_event_donation(): void {
        if ( ! current_user_can( 'bsxmh_manage_events' ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'bsxmh_save_event_donation' );
        $result = BSXMH_Events::create_donation( $_POST );
        $args = array( 'page' => 'bsxmh-event-donations' );
        if ( is_wp_error( $result ) ) { $args['bsxmh_error']=rawurlencode($result->get_error_message()); $args['action']='add'; }
        else { $args['bsxmh_notice']='donation-created'; $args['payment_id']=(int)$result; }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) ); exit;
    }

    public function render_events(): void {
        $action=sanitize_key($_GET['action']??''); $id=absint($_GET['event_id']??0); $current=$id?BSXMH_Events::get($id):null; $funds=BSXMH_Contributions::funds(true);
        $this->notices(); echo '<div class="wrap bsxmh-wrap"><h1>Events & Campaigns <a class="page-title-action" href="'.esc_url(admin_url('admin.php?page=bsxmh-events&action=add')).'">Add Event</a></h1>';
        if(in_array($action,array('add','edit'),true)){
            echo '<div class="bsxmh-panel"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="bsxmh_save_event"><input type="hidden" name="event_id" value="'.esc_attr($id).'">'; wp_nonce_field('bsxmh_save_event');
            echo '<table class="form-table">'; $this->input('title','Event Title',$current->title??'','text',true); $this->input('slug','Slug',$current->slug??'');
            echo '<tr><th>Linked Fund</th><td><select name="fund_id" required><option value="">Select fund</option>'; foreach($funds as$f)echo '<option value="'.$f->id.'" '.selected($current->fund_id??0,$f->id,false).'>'.esc_html($f->name).'</option>'; echo '</select></td></tr>';
            $this->input('target_amount','Target Amount',$current->target_amount??'0','number'); $this->input('start_date','Start Date',$current->start_date??'','date'); $this->input('end_date','End Date',$current->end_date??'','date'); $this->input('image_id','Banner Attachment ID',$current->image_id??'','number');
            echo '<tr><th>Description</th><td><textarea class="large-text" rows="5" name="description">'.esc_textarea($current->description??'').'</textarea></td></tr><tr><th>Visibility</th><td><select name="visibility">'; foreach(array('public'=>'Public','members'=>'Members','admin'=>'Admin','hidden'=>'Hidden')as$k=>$v)echo '<option value="'.$k.'" '.selected($current->visibility??'public',$k,false).'>'.$v.'</option>'; echo '</select></td></tr><tr><th>Status</th><td><select name="status">'; foreach(array('draft'=>'Draft','active'=>'Active','inactive'=>'Inactive','closed'=>'Closed')as$k=>$v)echo '<option value="'.$k.'" '.selected($current->status??'active',$k,false).'>'.$v.'</option>'; echo '</select></td></tr></table>'; submit_button($current?'Update Event':'Create Event'); echo '</form></div>';
        }
        $events=BSXMH_Events::all(); echo '<div class="bsxmh-panel"><table class="widefat striped"><thead><tr><th>Event</th><th>Fund</th><th>Target</th><th>Collected</th><th>Progress</th><th>Dates</th><th>Status</th><th></th></tr></thead><tbody>'; if(!$events)echo '<tr><td colspan="8">No events yet.</td></tr>'; foreach($events as$e){$st=BSXMH_Events::stats((int)$e->id); echo '<tr><td><strong>'.esc_html($e->title).'</strong></td><td>'.esc_html($e->fund_name?:'—').'</td><td>'.esc_html(BSXMH_Payments::currency_symbol().number_format_i18n($st['target'],2)).'</td><td>'.esc_html(BSXMH_Payments::currency_symbol().number_format_i18n($st['collected'],2)).'</td><td>'.esc_html(number_format_i18n($st['percent'],1)).'%</td><td>'.esc_html(($e->start_date?:'—').' to '.($e->end_date?:'—')).'</td><td>'.esc_html(ucfirst($e->status)).'</td><td><a href="'.esc_url(admin_url('admin.php?page=bsxmh-events&action=edit&event_id='.$e->id)).'">Edit</a> | <a href="'.esc_url(admin_url('admin.php?page=bsxmh-event-donations&action=add&event_id='.$e->id)).'">Add Donation</a></td></tr>'; } echo '</tbody></table></div></div>';
    }

    public function render_event_donations(): void {
        global $wpdb; $action=sanitize_key($_GET['action']??''); $events=BSXMH_Events::all(); $members=$wpdb->get_results("SELECT m.id,m.member_number,u.display_name FROM ".BSXMH_DB::table('members')." m LEFT JOIN {$wpdb->users} u ON u.ID=m.user_id WHERE m.status='active' ORDER BY u.display_name"); $this->notices();
        echo '<div class="wrap bsxmh-wrap"><h1>Event Donations <a class="page-title-action" href="'.esc_url(admin_url('admin.php?page=bsxmh-event-donations&action=add')).'">Add Donation</a></h1>';
        if('add'===$action){$selected=absint($_GET['event_id']??0); echo '<div class="bsxmh-panel"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="bsxmh_save_event_donation">'; wp_nonce_field('bsxmh_save_event_donation'); echo '<table class="form-table"><tr><th>Event</th><td><select name="event_id" required><option value="">Select event</option>'; foreach($events as$e)if('active'===$e->status)echo '<option value="'.$e->id.'" '.selected($selected,$e->id,false).'>'.esc_html($e->title).'</option>'; echo '</select></td></tr><tr><th>Member (optional)</th><td><select name="member_id" class="bsxmh-member-search"><option value="">Guest donor</option>'; foreach($members as$m)echo '<option value="'.$m->id.'">'.esc_html($m->member_number.' — '.$m->display_name).'</option>'; echo '</select></td></tr>'; $this->input('guest_name','Guest Name','','text'); $this->input('guest_email','Guest Email','','email'); $this->input('guest_mobile','Guest Mobile',''); $this->input('amount','Amount','','number',true); $this->input('payment_date','Payment Date',current_time('Y-m-d'),'date',true); echo '<tr><th>Payment Method</th><td><select name="payment_method"><option value="cash">Cash</option><option value="bank_transfer">Bank Transfer</option><option value="mobile_banking">Mobile Banking</option><option value="cheque">Cheque</option><option value="other">Other</option></select></td></tr>'; $this->input('reference_number','Reference Number',''); $this->input('received_by','Received By',wp_get_current_user()->display_name); echo '<tr><th>Options</th><td><label><input type="checkbox" name="anonymous" value="1"> Anonymous donation</label><br><label><input type="checkbox" name="duplicate_override" value="1"> Allow duplicate reference intentionally</label></td></tr><tr><th>Notes</th><td><textarea class="large-text" rows="3" name="notes"></textarea></td></tr></table>'; submit_button('Save Donation'); echo '</form></div>'; }
        $rows=$wpdb->get_results("SELECT p.*,i.reference_id AS event_id,e.title AS event_title,u.display_name FROM ".BSXMH_DB::table('payments')." p LEFT JOIN ".BSXMH_DB::table('payment_items')." i ON i.payment_id=p.id LEFT JOIN ".BSXMH_DB::table('events')." e ON e.id=i.reference_id LEFT JOIN {$wpdb->users} u ON u.ID=p.user_id WHERE p.payment_type='event_donation' ORDER BY p.payment_date DESC,p.id DESC"); echo '<div class="bsxmh-panel"><table class="widefat striped"><thead><tr><th>Date</th><th>Event</th><th>Donor</th><th>Amount</th><th>Method</th><th>Reference</th><th>Receipt</th></tr></thead><tbody>'; if(!$rows)echo '<tr><td colspan="7">No event donations yet.</td></tr>'; foreach($rows as$r){$m=json_decode((string)$r->metadata,true);$receipt=BSXMH_Receipts::get_by_payment((int)$r->id);$donor=!empty($m['anonymous'])?'Anonymous':($r->display_name?:($m['guest_name']??'Guest'));echo '<tr><td>'.esc_html($r->payment_date).'</td><td>'.esc_html($r->event_title?:'—').'</td><td>'.esc_html($donor).'</td><td>'.esc_html(BSXMH_Payments::currency_symbol().number_format_i18n((float)$r->total_amount,2)).'</td><td>'.esc_html(ucwords(str_replace('_',' ',$m['method']??'manual'))).'</td><td>'.esc_html($m['reference']??'—').'</td><td>'.($receipt?'<a target="_blank" href="'.esc_url(BSXMH_Receipts::public_url($receipt)).'">View</a>':'—').'</td></tr>'; } echo '</tbody></table></div></div>';
    }

    public function render_placeholder():void{echo '<div class="wrap bsxmh-wrap"><h1>'.esc_html(get_admin_page_title()).'</h1><div class="notice notice-info inline"><p>This module foundation is ready for a later release.</p></div></div>';}
    public function render_settings():void{$s=get_option('bsxmh_settings',array());echo '<div class="wrap bsxmh-wrap"><h1>MemberHub Settings</h1><form method="post" action="options.php">';settings_fields('bsxmh_settings_group');echo '<div class="bsxmh-panel"><h2>Organization</h2><table class="form-table">';foreach(array('organization_name'=>'Organization Name','organization_email'=>'Email','organization_phone'=>'Phone','currency'=>'Currency','timezone'=>'Timezone','default_monthly_fee'=>'Default Monthly Fee','member_id_prefix'=>'Member ID Prefix','organization_start_month'=>'Start Month (1–12)','organization_start_year'=>'Start Year')as$k=>$l)$this->settings_input($k,$l,$s,in_array($k,array('default_monthly_fee','organization_start_month','organization_start_year'),true)?'number':($k==='organization_email'?'email':'text'));echo '<tr><th>Address</th><td><textarea class="large-text" rows="3" name="bsxmh_settings[organization_address]">'.esc_textarea($s['organization_address']??'').'</textarea></td></tr></table></div><div class="bsxmh-panel"><h2>Registration & Visibility</h2><table class="form-table"><tr><th>Frontend Registration</th><td><input type="checkbox" name="bsxmh_settings[registration_enabled]" value="1" '.checked(!empty($s['registration_enabled']),true,false).'> Enabled</td></tr><tr><th>Admin Approval</th><td><input type="checkbox" name="bsxmh_settings[registration_requires_approval]" value="1" '.checked(!empty($s['registration_requires_approval']),true,false).'> Required</td></tr><tr><th>Transparency Dashboard</th><td><select name="bsxmh_settings[public_dashboard_visibility]">';foreach(array('public'=>'Public','members'=>'Logged-in members','admin'=>'Admin only','hidden'=>'Hidden')as$k=>$l)echo '<option value="'.$k.'" '.selected($s['public_dashboard_visibility']??'public',$k,false).'>'.$l.'</option>';echo '</select></td></tr></table></div><div class="bsxmh-panel"><h2>Receipt</h2><table class="form-table">';foreach(array('receipt_prefix'=>'Receipt Prefix','receipt_title'=>'Receipt Title','organization_logo_url'=>'Logo URL','receipt_thank_you'=>'Thank You Message','voucher_prefix'=>'Voucher Prefix','voucher_title'=>'Voucher Title')as$k=>$l)$this->settings_input($k,$l,$s);echo '<tr><th>Header Text</th><td><textarea class="large-text" rows="3" name="bsxmh_settings[receipt_header]">'.esc_textarea($s['receipt_header']??'').'</textarea></td></tr><tr><th>Footer Text</th><td><textarea class="large-text" rows="3" name="bsxmh_settings[receipt_footer]">'.esc_textarea($s['receipt_footer']??'').'</textarea></td></tr></table><p><a class="button" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=bsxmh_migrate_receipts'),'bsxmh_migrate_receipts')).'">Generate Missing Receipts</a></p></div>';submit_button();echo '</form></div>';}
    private function settings_input(string$k,string$l,array$s,string$t='text'):void{echo '<tr><th>'.$l.'</th><td><input class="regular-text" type="'.$t.'" name="bsxmh_settings['.$k.']" value="'.esc_attr($s[$k]??'').'"'.($t==='number'?' step="0.01" min="0"':'').'></td></tr>';}
    public function render_health():void{global$wpdb;$cron=wp_next_scheduled('bsxmh_daily_scheduled_tasks');echo '<div class="wrap bsxmh-wrap"><h1>System Health</h1><div class="bsxmh-panel"><table class="widefat striped"><tbody><tr><td>Plugin version</td><td>'.esc_html(BSXMH_VERSION).'</td></tr><tr><td>WordPress</td><td>'.esc_html(get_bloginfo('version')).'</td></tr><tr><td>PHP</td><td>'.esc_html(PHP_VERSION).'</td></tr><tr><td>Member records</td><td>'.esc_html((string)$wpdb->get_var('SELECT COUNT(*) FROM '.BSXMH_DB::table('members'))).'</td></tr><tr><td>Payment records</td><td>'.esc_html((string)$wpdb->get_var('SELECT COUNT(*) FROM '.BSXMH_DB::table('payments'))).'</td></tr><tr><td>Contribution records</td><td>'.esc_html((string)$wpdb->get_var("SELECT COUNT(*) FROM ".BSXMH_DB::table('payments')." WHERE payment_type='extra_contribution'")).'</td></tr><tr><td>Fund records</td><td>'.esc_html((string)$wpdb->get_var('SELECT COUNT(*) FROM '.BSXMH_DB::table('funds'))).'</td></tr><tr><td>Receipt records</td><td>'.esc_html((string)$wpdb->get_var('SELECT COUNT(*) FROM '.BSXMH_DB::table('receipts'))).'</td></tr><tr><td>Expense records</td><td>'.esc_html((string)$wpdb->get_var('SELECT COUNT(*) FROM '.BSXMH_DB::table('expenses'))).'</td></tr><tr><td>Daily cron</td><td>'.($cron?esc_html(wp_date('Y-m-d H:i:s',$cron)):'Not scheduled').'</td></tr><tr><td>SSL</td><td>'.(is_ssl()?'Enabled':'Not detected').'</td></tr></tbody></table></div></div>';}

    public function save_contribution(): void {
        if ( ! current_user_can( 'bsxmh_manage_payments' ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'bsxmh_save_contribution' );
        $result = BSXMH_Contributions::create( $_POST );
        $args = array( 'page' => 'bsxmh-contributions' );
        if ( is_wp_error( $result ) ) { $args['action']='add'; $args['bsxmh_error']=rawurlencode($result->get_error_message()); }
        else { $args['bsxmh_notice']='contribution-created'; $args['payment_id']=(int)$result; }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) ); exit;
    }

    public function save_fund(): void {
        if ( ! current_user_can( 'bsxmh_manage_events' ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'bsxmh_save_fund' );
        $result = BSXMH_Contributions::save_fund( $_POST );
        $args = array( 'page' => 'bsxmh-funds' );
        if ( is_wp_error( $result ) ) $args['bsxmh_error']=rawurlencode($result->get_error_message()); else $args['bsxmh_notice']='fund-saved';
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) ); exit;
    }

    public function render_contributions(): void {
        global $wpdb;
        if ( isset($_GET['action']) && 'add' === $_GET['action'] ) { $this->render_contribution_form(); return; }
        $member_id=absint($_GET['member_id']??0);$fund_id=absint($_GET['fund_id']??0);$from=sanitize_text_field($_GET['date_from']??'');$to=sanitize_text_field($_GET['date_to']??'');
        $where=array("p.payment_type='extra_contribution'");$params=array();
        if($member_id){$m=BSXMH_Members::get($member_id);if($m){$where[]='p.user_id=%d';$params[]=$m->user_id;}}
        if($fund_id){$where[]='EXISTS (SELECT 1 FROM '.BSXMH_DB::table('payment_items').' x WHERE x.payment_id=p.id AND x.fund_id=%d)';$params[]=$fund_id;}
        if($from){$where[]='p.payment_date >= %s';$params[]=$from.' 00:00:00';}if($to){$where[]='p.payment_date <= %s';$params[]=$to.' 23:59:59';}
        $sql="SELECT p.*,u.display_name,m.member_number FROM ".BSXMH_DB::table('payments')." p LEFT JOIN {$wpdb->users} u ON u.ID=p.user_id LEFT JOIN ".BSXMH_DB::table('members')." m ON m.user_id=p.user_id WHERE ".implode(' AND ',$where).' ORDER BY p.payment_date DESC,p.id DESC';if($params)$sql=$wpdb->prepare($sql,$params);$rows=$wpdb->get_results($sql);
        $members=$wpdb->get_results("SELECT m.id,m.member_number,u.display_name FROM ".BSXMH_DB::table('members')." m LEFT JOIN {$wpdb->users} u ON u.ID=m.user_id ORDER BY u.display_name");$funds=BSXMH_Contributions::funds();
        $this->notices();echo '<div class="wrap bsxmh-wrap"><h1>Extra Contributions <a class="page-title-action" href="'.esc_url(admin_url('admin.php?page=bsxmh-contributions&action=add')).'">Add Contribution</a></h1><form method="get"><input type="hidden" name="page" value="bsxmh-contributions"><select name="member_id" class="bsxmh-member-search"><option value="">All members</option>';foreach($members as$m)echo '<option value="'.$m->id.'" '.selected($member_id,$m->id,false).'>'.esc_html($m->member_number.' — '.$m->display_name).'</option>';echo '</select> <select name="fund_id"><option value="">All funds</option>';foreach($funds as$f)echo '<option value="'.$f->id.'" '.selected($fund_id,$f->id,false).'>'.esc_html($f->name).'</option>';echo '</select> <input type="date" name="date_from" value="'.esc_attr($from).'"> <input type="date" name="date_to" value="'.esc_attr($to).'"> <button class="button">Filter</button></form>'.$this->history_table($rows,true).'</div>';
    }

    private function render_contribution_form(): void {
        global $wpdb;$members=$wpdb->get_results("SELECT m.id,m.member_number,u.display_name FROM ".BSXMH_DB::table('members')." m LEFT JOIN {$wpdb->users} u ON u.ID=m.user_id WHERE m.status='active' ORDER BY u.display_name");$funds=BSXMH_Contributions::funds(true);
        $this->notices();echo '<div class="wrap bsxmh-wrap"><h1>Add Extra Contribution</h1><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="bsxmh_save_contribution">';wp_nonce_field('bsxmh_save_contribution');echo '<div class="bsxmh-panel"><table class="form-table"><tr><th>Member</th><td><select name="member_id" class="bsxmh-member-search" required><option value="">Choose member</option>';foreach($members as$m)echo '<option value="'.$m->id.'">'.esc_html($m->member_number.' — '.$m->display_name).'</option>';echo '</select></td></tr><tr><th>Fund</th><td><select name="fund_id" required><option value="">Choose fund</option>';foreach($funds as$f)echo '<option value="'.$f->id.'">'.esc_html($f->name).'</option>';echo '</select></td></tr>';$this->input('contribution_type','Contribution Type','Extra Contribution');$this->input('amount','Amount','','number',true);$this->input('payment_date','Contribution Date',current_time('Y-m-d'),'date',true);echo '<tr><th>Payment Method</th><td><select name="payment_method"><option value="cash">Cash</option><option value="bank">Bank Transfer</option><option value="mobile_banking">Mobile Banking</option><option value="cheque">Cheque</option><option value="other">Other</option></select></td></tr>';$this->input('reference_number','Reference Number','');$this->input('received_by','Received By',wp_get_current_user()->display_name);echo '<tr><th>Notes</th><td><textarea class="large-text" rows="3" name="notes"></textarea></td></tr><tr><th>Duplicate Override</th><td><label><input type="checkbox" name="duplicate_override" value="1"> Allow an intentional duplicate reference</label></td></tr></table></div>';submit_button('Save Contribution & Generate Receipt');echo '</form></div>';
    }

    public function render_funds(): void {
        $edit=absint($_GET['fund_id']??0);$current=$edit?BSXMH_Contributions::get_fund($edit):null;$summary=BSXMH_Contributions::fund_summary();$this->notices();echo '<div class="wrap bsxmh-wrap"><h1>Fund Accounting</h1><div class="bsxmh-cards">';foreach($summary as$r)$this->card($r['fund']->name,BSXMH_Payments::currency_symbol().number_format_i18n($r['balance'],2));echo '</div><div class="bsxmh-panel"><h2>'.($current?'Edit Fund':'Add Fund').'</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="bsxmh_save_fund"><input type="hidden" name="fund_id" value="'.esc_attr($edit).'">';wp_nonce_field('bsxmh_save_fund');echo '<table class="form-table">';$this->input('name','Fund Name',$current->name??'','text',true);$this->input('slug','Slug',$current->slug??'');$this->input('opening_balance','Opening Balance',$current->opening_balance??'0','number');echo '<tr><th>Description</th><td><textarea class="large-text" rows="3" name="description">'.esc_textarea($current->description??'').'</textarea></td></tr><tr><th>Type</th><td><select name="fund_type"><option value="general" '.selected($current->fund_type??'general','general',false).'>General</option><option value="membership" '.selected($current->fund_type??'','membership',false).'>Membership</option><option value="welfare" '.selected($current->fund_type??'','welfare',false).'>Welfare</option></select></td></tr><tr><th>Visibility</th><td><select name="visibility">';foreach(array('public'=>'Public','members'=>'Members','admin'=>'Admin','hidden'=>'Hidden')as$k=>$v)echo '<option value="'.$k.'" '.selected($current->visibility??'public',$k,false).'>'.$v.'</option>';echo '</select></td></tr><tr><th>Status</th><td><select name="status"><option value="active" '.selected($current->status??'active','active',false).'>Active</option><option value="inactive" '.selected($current->status??'','inactive',false).'>Inactive</option></select></td></tr></table>';submit_button($current?'Update Fund':'Add Fund');echo '</form></div><div class="bsxmh-panel"><h2>Fund Summary</h2><table class="widefat striped"><thead><tr><th>Fund</th><th>Opening</th><th>Collected</th><th>Spent</th><th>Balance</th><th>Status</th><th></th></tr></thead><tbody>';foreach($summary as$r){$f=$r['fund'];echo '<tr><td>'.esc_html($f->name).'</td><td>'.esc_html(BSXMH_Payments::currency_symbol().number_format_i18n((float)$f->opening_balance,2)).'</td><td>'.esc_html(BSXMH_Payments::currency_symbol().number_format_i18n($r['collected'],2)).'</td><td>'.esc_html(BSXMH_Payments::currency_symbol().number_format_i18n($r['spent'],2)).'</td><td><strong>'.esc_html(BSXMH_Payments::currency_symbol().number_format_i18n($r['balance'],2)).'</strong></td><td>'.esc_html(ucfirst($f->status)).'</td><td><a href="'.esc_url(admin_url('admin.php?page=bsxmh-funds&fund_id='.$f->id)).'">Edit</a></td></tr>';}echo '</tbody></table></div></div>';
    }

}
