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
        add_action( 'admin_post_bsxmh_repair_orphan_members', array( $this, 'repair_orphan_members' ) );
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
        add_submenu_page( 'bsxmh', 'Shortcodes', 'Shortcodes', 'bsxmh_manage_settings', 'bsxmh-shortcodes', array( $this, 'render_shortcodes' ) );
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
            'organization_website' => esc_url_raw( $input['organization_website'] ?? '' ),
            'support_email' => sanitize_email( $input['support_email'] ?? '' ),
            'support_phone' => sanitize_text_field( $input['support_phone'] ?? '' ),
            'portal_primary_color' => sanitize_hex_color( $input['portal_primary_color'] ?? '#183153' ) ?: '#183153',
            'portal_secondary_color' => sanitize_hex_color( $input['portal_secondary_color'] ?? '#2563eb' ) ?: '#2563eb',
            'portal_footer_text' => sanitize_text_field( $input['portal_footer_text'] ?? '' ),
            'currency' => strtoupper( sanitize_key( $input['currency'] ?? 'BDT' ) ),
            'timezone' => sanitize_text_field( $input['timezone'] ?? 'Asia/Dhaka' ),
            'organization_start_month' => min( 12, max( 1, absint( $input['organization_start_month'] ?? 1 ) ) ),
            'organization_start_year' => min( 9999, max( 1900, absint( $input['organization_start_year'] ?? current_time( 'Y' ) ) ) ),
            'default_monthly_fee' => number_format( max( 0, (float) ( $input['default_monthly_fee'] ?? 100 ) ), 2, '.', '' ),
            'default_membership_fund_id' => ( function() use ( $input ) { $id=absint($input['default_membership_fund_id']??0); $f=$id?BSXMH_Contributions::get_fund($id):null; return ($f&&'active'===$f->status)?$id:BSXMH_Contributions::default_membership_fund_id(); } )(),
            'member_id_prefix' => strtoupper( sanitize_key( $input['member_id_prefix'] ?? 'MH' ) ),
            'registration_enabled' => empty( $input['registration_enabled'] ) ? 0 : 1,
            'allow_member_profile_photo' => empty( $input['allow_member_profile_photo'] ) ? 0 : 1,
            'profile_photo_max_mb' => max( 1, min( 10, absint( $input['profile_photo_max_mb'] ?? 2 ) ) ),
            'registration_requires_approval' => empty( $input['registration_requires_approval'] ) ? 0 : 1,
            'registration_description' => sanitize_textarea_field( $input['registration_description'] ?? '' ),
            'login_description' => sanitize_textarea_field( $input['login_description'] ?? '' ),
            'public_dashboard_visibility' => in_array( $input['public_dashboard_visibility'] ?? '', array( 'public', 'members', 'admin', 'hidden' ), true ) ? $input['public_dashboard_visibility'] : 'members',
            'receipt_prefix' => strtoupper( sanitize_key( $input['receipt_prefix'] ?? 'BSXMH' ) ),
            'receipt_title' => sanitize_text_field( $input['receipt_title'] ?? 'Payment Receipt' ),
            'receipt_header' => sanitize_textarea_field( $input['receipt_header'] ?? '' ),
            'receipt_footer' => sanitize_textarea_field( $input['receipt_footer'] ?? '' ),
            'receipt_thank_you' => sanitize_text_field( $input['receipt_thank_you'] ?? 'Thank you for your payment.' ),
            'organization_logo_url' => esc_url_raw( $input['organization_logo_url'] ?? '' ),
            'voucher_prefix' => strtoupper( sanitize_key( $input['voucher_prefix'] ?? 'BSXV' ) ),
            'voucher_title' => sanitize_text_field( $input['voucher_title'] ?? 'Expense Voucher' ),
            'membership_card_enabled' => empty( $input['membership_card_enabled'] ) ? 0 : 1,
            'card_verification_enabled' => empty( $input['card_verification_enabled'] ) ? 0 : 1,
            'membership_card_title' => sanitize_text_field( $input['membership_card_title'] ?? 'Membership Card' ),
            'membership_card_accent' => sanitize_hex_color( $input['membership_card_accent'] ?? '#183153' ) ?: '#183153',
            'membership_card_footer' => sanitize_text_field( $input['membership_card_footer'] ?? '' ),
            'card_show_member_since' => empty( $input['card_show_member_since'] ) ? 0 : 1,
            'verification_show_photo' => empty( $input['verification_show_photo'] ) ? 0 : 1,
            'verification_show_member_since' => empty( $input['verification_show_member_since'] ) ? 0 : 1,
            'member_directory_enabled' => empty( $input['member_directory_enabled'] ) ? 0 : 1,
            'directory_show_photo' => empty( $input['directory_show_photo'] ) ? 0 : 1,
            'directory_show_member_id' => empty( $input['directory_show_member_id'] ) ? 0 : 1,
            'directory_show_join_date' => empty( $input['directory_show_join_date'] ) ? 0 : 1,
            'directory_show_status' => empty( $input['directory_show_status'] ) ? 0 : 1,
            'directory_show_tags' => empty( $input['directory_show_tags'] ) ? 0 : 1,
            'directory_allow_opt_out' => empty( $input['directory_allow_opt_out'] ) ? 0 : 1,
            'directory_members_per_page' => max( 6, min( 48, absint( $input['directory_members_per_page'] ?? 12 ) ) ),
            'directory_default_sort' => in_array( $input['directory_default_sort'] ?? '', array( 'name_asc', 'newest', 'member_id' ), true ) ? $input['directory_default_sort'] : 'name_asc',
        ) );
    }

    public function enqueue_assets( string $hook ): void {
        if ( false !== strpos( $hook, 'bsxmh' ) ) {
            wp_enqueue_style( 'bsxmh-admin', BSXMH_URL . 'assets/css/admin.css', array(), BSXMH_VERSION );
            wp_enqueue_script( 'bsxmh-admin-member-search', BSXMH_URL . 'assets/js/admin-member-search.js', array(), BSXMH_VERSION, true );

            global $wpdb;
            $rows = $wpdb->get_results(
                "SELECT m.id, m.user_id, m.member_number, m.profile_data, u.display_name, u.user_email
                 FROM " . BSXMH_DB::table( 'members' ) . " m
                 LEFT JOIN {$wpdb->users} u ON u.ID = m.user_id
                 WHERE m.status <> 'deleted'
                 ORDER BY u.display_name ASC"
            );
            $member_index = array();
            foreach ( (array) $rows as $row ) {
                $profile = json_decode( (string) ( $row->profile_data ?? '' ), true );
                $profile = is_array( $profile ) ? $profile : array();
                $phone = sanitize_text_field( $profile['phone'] ?? '' );
                if ( '' === $phone ) {
                    $phone = sanitize_text_field( get_user_meta( (int) $row->user_id, 'bsxmh_phone', true ) );
                }
                $member_index[] = array(
                    'id'     => (string) absint( $row->id ),
                    'label'  => trim( (string) $row->member_number . ' — ' . (string) $row->display_name ),
                    'search' => strtolower( trim( implode( ' ', array_filter( array(
                        (string) $row->member_number,
                        (string) $row->display_name,
                        (string) $row->user_email,
                        (string) $phone,
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
        if ( ! empty( $_FILES['profile_photo']['name'] ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $attachment_id = media_handle_upload( 'profile_photo', 0 );
            if ( is_wp_error( $attachment_id ) ) {
                $args = array( 'page' => 'bsxmh-members', 'action' => $id ? 'edit' : 'add', 'bsxmh_error' => rawurlencode( $attachment_id->get_error_message() ) );
                if ( $id ) { $args['member_id'] = $id; }
                wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) ); exit;
            }
            $data['profile_photo_id'] = (int) $attachment_id;
        }
        $previous_member = $id ? BSXMH_Members::get( $id ) : null;
        $previous_status = $previous_member ? (string) $previous_member->status : '';
        $result = $id ? BSXMH_Members::update( $id, $data ) : BSXMH_Members::create( $data );
        if ( ! is_wp_error( $result ) ) {
            $target_user_id = $id ? (int) BSXMH_Members::get( $id )->user_id : (int) $result;
            $custom_result = BSXMH_Form_Builder::validate_and_save( $target_user_id, $_POST, 'admin' );
            if ( is_wp_error( $custom_result ) ) {
                if ( ! $id ) { global $wpdb; $wpdb->delete( BSXMH_DB::table('members'), array('user_id'=>$target_user_id), array('%d') ); require_once ABSPATH.'wp-admin/includes/user.php'; wp_delete_user($target_user_id); }
                $result = $custom_result;
            }
        }
        if ( $id && ! is_wp_error( $result ) ) {
            $updated_member = BSXMH_Members::get( $id );
            if ( $updated_member && $previous_status !== (string) $updated_member->status ) {
                do_action( 'bsxmh_member_status_changed', (int) $updated_member->user_id, $previous_status, (string) $updated_member->status, $id );
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
        if ( $m ) {
            $u = get_userdata( (int) $m->user_id );
            $new_status = sanitize_key( $_GET['status'] ?? 'pending' );
            if ( $u && $new_status !== (string) $m->status ) {
                $updated = BSXMH_Members::update( $id, array( 'display_name'=>$u->display_name,'first_name'=>$u->first_name,'last_name'=>$u->last_name,'email'=>$u->user_email,'phone'=>get_user_meta((int)$m->user_id,'bsxmh_phone',true),'status'=>$new_status,'category_id'=>$m->category_id,'join_date'=>$m->join_date,'fee_start_date'=>$m->fee_start_date,'monthly_fee'=>$m->monthly_fee,'membership_fund_id'=>$m->membership_fund_id,'admin_notes'=>$m->admin_notes,'member_tags'=>BSXMH_Members::tags($m) ) );
                if ( ! is_wp_error( $updated ) ) do_action( 'bsxmh_member_status_changed', (int) $m->user_id, (string) $m->status, $new_status, $id );
            }
        }
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
        if ( isset($_GET['action']) && in_array($_GET['action'],array('add','edit','profile','statement'),true) ) { if('statement'===$_GET['action'])$this->render_member_statement();elseif('profile'===$_GET['action'])$this->render_member_profile();else $this->render_member_form(); return; }
        BSXMH_Payment_Control::render();
    }

    private function render_member_form():void{
        $id=absint($_GET['member_id']??0);$m=$id?BSXMH_Members::get($id):null;if($m&&('deleted'===$m->status||!get_userdata((int)$m->user_id))){echo '<div class="wrap bsxmh-wrap"><h1>Deleted Member</h1><div class="notice notice-warning"><p>This WordPress account no longer exists. Financial history is preserved, but this member cannot be edited or used for new payments.</p></div><p><a class="button" href="'.esc_url(admin_url('admin.php?page=bsxmh-members&view=deleted')).'">Back to Deleted Members</a></p></div>';return;}$u=$m?get_userdata((int)$m->user_id):null;$s=get_option('bsxmh_settings',array());$this->notices();echo '<div class="wrap bsxmh-wrap"><h1>'.($m?'Edit Member':'Add New Member').'</h1><form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="bsxmh_save_member"><input type="hidden" name="member_id" value="'.esc_attr($id).'">';wp_nonce_field('bsxmh_save_member');echo '<div class="bsxmh-panel"><table class="form-table">';$this->input('display_name','Full Name',$u?$u->display_name:'','text',true);$this->input('email','Email',$u?$u->user_email:'','email',true);$this->input('phone','Mobile Number',$m?get_user_meta((int)$m->user_id,'bsxmh_phone',true):'');if(!$m)$this->input('password','Password','','password');$photo_id=$m?BSXMH_Members::profile_photo_id($m):0;echo '<tr><th>Profile Photo</th><td><div class="bsxmh-photo-field">'.($m?BSXMH_Members::profile_photo_html($m,96,'bsxmh-photo-preview'):'').'<div><input type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp,image/gif"><p class="description">Upload JPG, PNG, WebP or GIF. A square image works best.</p>'.($photo_id?'<label><input type="checkbox" name="remove_profile_photo" value="1"> Remove current photo</label>':'').'</div></div></td></tr><tr><th>Status</th><td><select name="status">';$cur=$m?$m->status:'active';foreach($this->statuses() as $k=>$l)echo '<option value="'.esc_attr($k).'" '.selected($cur,$k,false).'>'.esc_html($l).'</option>';echo '</select></td></tr>';$this->input('join_date','Join Date',$m?$m->join_date:current_time('Y-m-d'),'date');$this->input('fee_start_date','Fee Start Date',$m?$m->fee_start_date:current_time('Y-m-d'),'date');$this->input('monthly_fee','Monthly Fee',$m?$m->monthly_fee:($s['default_monthly_fee']??'100.00'),'number');$funds=BSXMH_Contributions::funds(true);$selected_fund=$m?BSXMH_Contributions::membership_fund_for_member($m):BSXMH_Contributions::default_membership_fund_id();echo '<tr><th>Membership Fee Fund</th><td><select name="membership_fund_id" required>';foreach($funds as$f)echo '<option value="'.esc_attr($f->id).'" '.selected($selected_fund,$f->id,false).'>'.esc_html($f->name).'</option>';echo '</select><p class="description">Future monthly fee payments for this member will be credited to this fund.</p></td></tr>'; $member_tags=$m?implode(', ',BSXMH_Members::tags($m)):''; echo '<tr><th>Member Tags</th><td><input class="regular-text" name="member_tags" value="'.esc_attr($member_tags).'"><p class="description">Comma-separated tags, for example: Founder, Volunteer, VIP.</p></td></tr><tr><th>Admin Notes</th><td><textarea class="large-text" rows="4" name="admin_notes">'.esc_textarea($m?$m->admin_notes:'').'</textarea></td></tr></table><h2>Custom Profile Fields</h2>'.BSXMH_Form_Builder::render_fields('admin',$m?(int)$m->user_id:0).'</div>';submit_button($m?'Update Member':'Create Member');echo ' <a class="button" href="'.esc_url(admin_url('admin.php?page=bsxmh-members')).'">Cancel</a></form></div>';
    }

    private function render_member_profile(): void {
        global $wpdb;
        $member = BSXMH_Members::get( absint( $_GET['member_id'] ?? 0 ) );
        if ( ! $member ) { echo '<div class="wrap"><p>Member not found.</p></div>'; return; }
        $snapshot = BSXMH_Members::display_snapshot( $member );
        $statement = BSXMH_Payments::statement( $member );
        $symbol = BSXMH_Payments::currency_symbol();
        $fund = $member->membership_fund_id ? BSXMH_Contributions::get_fund( (int) $member->membership_fund_id ) : null;
        $last_payment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . BSXMH_DB::table( 'payments' ) . " WHERE user_id=%d AND status='paid' ORDER BY payment_date DESC,id DESC LIMIT 1", $member->user_id ) );
        $contributions = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(total_amount),0) FROM " . BSXMH_DB::table( 'payments' ) . " WHERE user_id=%d AND status='paid' AND payment_type IN ('extra_contribution','event_donation')", $member->user_id ) );
        $event_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . BSXMH_DB::table( 'payments' ) . " WHERE user_id=%d AND status='paid' AND payment_type='event_donation'", $member->user_id ) );
        $tabs = array( 'overview' => 'Overview', 'timeline' => 'Timeline', 'payments' => 'Payments', 'contributions' => 'Contributions', 'events' => 'Events', 'notes' => 'Notes', 'documents' => 'Documents', 'activity' => 'Activity' );
        $tab = sanitize_key( $_GET['tab'] ?? 'overview' ); if ( ! isset( $tabs[$tab] ) ) $tab='overview';
        $base = admin_url( 'admin.php?page=bsxmh-members&action=profile&member_id=' . (int) $member->id );
        $tags = BSXMH_Members::tags( $member );
        $tags_html = '';
        if ( ! empty( $tags ) ) {
            $tag_badges = array_map(
                static fn( $tag ) => '<span>' . esc_html( $tag ) . '</span>',
                $tags
            );
            $tags_html = '<div class="bsxmh-member-tags">' . implode( '', $tag_badges ) . '</div>';
        }
        echo '<div class="wrap bsxmh-wrap bsxmh-member360"><div class="bsxmh-360-header"><div class="bsxmh-360-identity">'.BSXMH_Members::profile_photo_html($member,120,'bsxmh-360-photo').'<div><h1>'.esc_html($snapshot['name']).'</h1><p><code>'.esc_html($member->member_number).'</code> <span class="bsxmh-status bsxmh-status-'.esc_attr($member->status).'">'.esc_html(ucfirst($member->status)).'</span></p><p>'.esc_html($snapshot['email']).($snapshot['phone']?' · '.esc_html($snapshot['phone']):'').'</p>'.$tags_html.'</div></div><div class="bsxmh-360-actions"><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=bsxmh-members&action=edit&member_id='.$member->id)).'">Edit Member</a><a class="button" href="'.esc_url(admin_url('admin.php?page=bsxmh-payments&action=add&member_id='.$member->id)).'">Add Payment</a><a class="button" target="_blank" href="'.esc_url(BSXMH_Membership_Card::verification_url($member)).'">Verify Card</a><a class="button" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=bsxmh_regenerate_card_token&member_id='.$member->id),'bsxmh_regenerate_card_token')).'" onclick="return confirm(\'Regenerate the QR token? All previously printed cards will stop verifying.\')">Regenerate QR</a><a class="button" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=bsxmh_toggle_card_verification&member_id='.$member->id),'bsxmh_toggle_card_verification')).'">Toggle Verification</a><a class="button" href="'.esc_url(admin_url('admin.php?page=bsxmh-members')).'">Back</a></div></div>';
        echo '<nav class="bsxmh-360-tabs">'; foreach($tabs as $key=>$label){ echo '<a class="'.($tab===$key?'current':'').'" href="'.esc_url(add_query_arg('tab',$key,$base)).'">'.esc_html($label).'</a>'; } echo '</nav>';
        if ( 'overview' === $tab ) {
            echo '<div class="bsxmh-cards">';
            foreach(array('Membership Fees Paid'=>$symbol.number_format_i18n((float)$statement['total_paid'],2),'Outstanding Due'=>$symbol.number_format_i18n((float)$statement['total_due'],2),'Contributions'=>$symbol.number_format_i18n($contributions,2),'Event Payments'=>number_format_i18n($event_count)) as $label=>$value){$this->card($label,(string)$value);} echo '</div>';
            echo '<div class="bsxmh-grid-2"><div class="bsxmh-panel"><h2>Membership Details</h2><dl class="bsxmh-detail-list"><dt>Member ID</dt><dd>'.esc_html($member->member_number).'</dd><dt>Status</dt><dd>'.esc_html(ucfirst($member->status)).'</dd><dt>Join Date</dt><dd>'.esc_html($member->join_date?:'—').'</dd><dt>Fee Start Date</dt><dd>'.esc_html($member->fee_start_date?:'—').'</dd><dt>Monthly Fee</dt><dd>'.esc_html($symbol.number_format_i18n((float)$member->monthly_fee,2)).'</dd><dt>Assigned Fund</dt><dd>'.esc_html($fund?$fund->name:'—').'</dd></dl></div>';
            echo '<div class="bsxmh-panel"><h2>Payment Summary</h2><dl class="bsxmh-detail-list"><dt>Paid Months</dt><dd>'.number_format_i18n(count($statement['paid'])).'</dd><dt>Due Months</dt><dd>'.number_format_i18n(count($statement['due'])).'</dd><dt>Advance Months</dt><dd>'.number_format_i18n(count($statement['advance'])).'</dd><dt>Last Payment</dt><dd>'.esc_html($last_payment?$last_payment->payment_date:'No payment yet').'</dd><dt>Next Due Month</dt><dd>'.esc_html(!empty($statement['due'])?BSXMH_Payments::month_label((int)$statement['due'][0]['year'],(int)$statement['due'][0]['month']):'No current due').'</dd></dl><p><a class="button" href="'.esc_url(admin_url('admin.php?page=bsxmh-members&action=statement&member_id='.$member->id)).'">Open Full Statement</a></p></div></div>';
        } elseif ( 'timeline' === $tab ) { $this->render_member_timeline_tab( $member, $base ); }
        elseif ( 'payments' === $tab ) { $this->render_member_statement_embedded($member); }
        elseif ( 'contributions' === $tab ) { $this->render_member_payment_type_tab( $member, 'extra_contribution', 'Contribution History' ); }
        elseif ( 'events' === $tab ) { $this->render_member_payment_type_tab( $member, 'event_donation', 'Event Payment History' ); }
        elseif ( 'activity' === $tab ) { $this->render_member_activity_tab( $member ); }
        elseif ( 'notes' === $tab ) { echo '<div class="bsxmh-panel"><h2>Admin Private Notes</h2><p>'.($member->admin_notes?nl2br(esc_html($member->admin_notes)):'No private notes have been added.').'</p><p><a class="button" href="'.esc_url(admin_url('admin.php?page=bsxmh-members&action=edit&member_id='.$member->id)).'">Edit Notes</a></p></div>'; }
        else { echo '<div class="bsxmh-panel"><h2>Documents</h2><p>Member Documents is reserved for v1.6.0. No document data is stored in this release.</p></div>'; }
        echo '</div>';
    }

    private function render_member_timeline_tab( $member, string $base ): void {
        $filters = array( 'all'=>'All', 'membership'=>'Membership', 'payments'=>'Payments', 'contributions'=>'Contributions', 'events'=>'Events', 'profile'=>'Profile', 'system'=>'System' );
        $filter = sanitize_key( $_GET['timeline_filter'] ?? 'all' ); if ( ! isset($filters[$filter]) ) $filter='all';
        echo '<div class="bsxmh-panel"><div class="bsxmh-panel-heading"><h2>Member Timeline</h2><div class="bsxmh-filter-links">';
        foreach($filters as$key=>$label){$url=add_query_arg(array('tab'=>'timeline','timeline_filter'=>$key),$base);echo '<a class="'.($filter===$key?'current':'').'" href="'.esc_url($url).'">'.esc_html($label).'</a>';}
        echo '</div></div>';
        $items=BSXMH_Timeline::combined($member,$filter,100);
        if(!$items){echo '<p>No timeline entries were found for this filter.</p></div>';return;}
        echo '<ol class="bsxmh-timeline">';
        foreach($items as$item){echo '<li class="bsxmh-timeline-item bsxmh-timeline-'.esc_attr($item['category']).'"><span class="bsxmh-timeline-dot"></span><div class="bsxmh-timeline-card"><div class="bsxmh-timeline-meta"><time>'.esc_html(mysql2date(get_option('date_format').' '.get_option('time_format'),$item['date'])).'</time><span>'.esc_html(ucfirst($item['category'])).'</span></div><h3>'.esc_html($item['title']).'</h3>'.($item['description']?'<p>'.esc_html($item['description']).'</p>':'').'<small>Recorded by '.esc_html($item['actor']).'</small></div></li>';}
        echo '</ol></div>';
    }

    private function render_member_payment_type_tab( $member, string $type, string $heading ): void {
        $rows=BSXMH_Timeline::payments($member,$type,200);$symbol=BSXMH_Payments::currency_symbol();
        echo '<div class="bsxmh-panel"><h2>'.esc_html($heading).'</h2><div class="table-responsive"><table class="widefat striped"><thead><tr><th>Date</th><th>Transaction</th><th>Description</th><th>Gateway</th><th>Status</th><th>Amount</th></tr></thead><tbody>';
        foreach($rows as$r){echo '<tr><td>'.esc_html($r->payment_date?:$r->created_at).'</td><td><code>'.esc_html($r->transaction_id).'</code></td><td>'.esc_html($r->item_descriptions?:ucwords(str_replace('_',' ',$r->payment_type))).'</td><td>'.esc_html(ucfirst($r->gateway)).'</td><td>'.esc_html(ucfirst($r->status)).'</td><td>'.esc_html($symbol.number_format_i18n((float)$r->total_amount,2)).'</td></tr>';}
        if(!$rows)echo '<tr><td colspan="6">No records found.</td></tr>';echo '</tbody></table></div></div>';
    }

    private function render_member_activity_tab( $member ): void {
        $rows=BSXMH_Timeline::activity($member,'all',200);
        echo '<div class="bsxmh-panel"><h2>Technical Activity Log</h2><p class="description">Administrative and system actions linked to this member. Sensitive details are visible only to authorized administrators.</p><div class="table-responsive"><table class="widefat striped"><thead><tr><th>Date</th><th>Actor</th><th>Action</th><th>Object</th><th>Details</th></tr></thead><tbody>';
        foreach($rows as$r){$d=json_decode((string)$r->details,true);echo '<tr><td>'.esc_html($r->created_at).'</td><td>'.esc_html($r->actor_name?:'System').'</td><td><code>'.esc_html($r->action).'</code></td><td>'.esc_html(trim((string)$r->object_type.($r->object_id?' #'.$r->object_id:''))).'</td><td><code>'.esc_html(is_array($d)?wp_json_encode($d):((string)$r->details)).'</code></td></tr>';}
        if(!$rows)echo '<tr><td colspan="5">No activity has been recorded yet.</td></tr>';echo '</tbody></table></div></div>';
    }

    private function render_member_statement_embedded( $member ): void {
        global $wpdb; $history=$wpdb->get_results($wpdb->prepare("SELECT * FROM ".BSXMH_DB::table('payments')." WHERE user_id=%d ORDER BY payment_date DESC,id DESC",$member->user_id));
        echo '<div class="bsxmh-panel"><h2>Payment History</h2>'.$this->history_table($history).'</div>';
    }

    private function render_member_statement():void{
        $m=BSXMH_Members::get(absint($_GET['member_id']??0));if(!$m){echo '<div class="wrap"><p>Member not found.</p></div>';return;}$u=get_userdata((int)$m->user_id);$s=BSXMH_Payments::statement($m);$sym=BSXMH_Payments::currency_symbol();global$wpdb;$history=$wpdb->get_results($wpdb->prepare("SELECT * FROM ".BSXMH_DB::table('payments')." WHERE user_id=%d ORDER BY payment_date DESC,id DESC",$m->user_id));
        echo '<div class="wrap bsxmh-wrap"><h1>Member Statement: '.esc_html($u?$u->display_name:$m->member_number).'</h1><p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=bsxmh-payments&action=add&member_id='.$m->id)).'">Add Payment</a> <a class="button" href="'.esc_url(admin_url('admin.php?page=bsxmh-members')).'">Back to Members</a></p><div class="bsxmh-cards">';foreach(array('Total Paid'=>$sym.number_format_i18n($s['total_paid'],2),'Total Due'=>$sym.number_format_i18n($s['total_due'],2),'Paid Months'=>count($s['paid']),'Due Months'=>count($s['due']),'Advance Months'=>count($s['advance']))as$l=>$v)$this->card($l,(string)$v);echo '</div><div class="bsxmh-panel"><h2>Due Months</h2><p>'.$this->period_badges($s['due'],'No dues.').'</p><h2>Paid Months</h2><p>'.$this->period_badges($s['paid'],'No paid months yet.').'</p><h2>Advance Months</h2><p>'.$this->period_badges($s['advance'],'No advance payments.').'</p></div><div class="bsxmh-panel"><h2>Payment Timeline</h2>'.$this->history_table($history).'</div></div>';
    }

    public function render_payments():void{
        if(isset($_GET['action'])&&'add'===$_GET['action']){$this->render_payment_form();return;}global$wpdb;$member_id=absint($_GET['member_id']??0);$month=sanitize_text_field($_GET['month']??'');$status=sanitize_key($_GET['status']??'');$from=sanitize_text_field($_GET['date_from']??'');$to=sanitize_text_field($_GET['date_to']??'');$where=array('1=1');$params=array();if($member_id){$m=BSXMH_Members::get($member_id);if($m){$where[]='p.user_id=%d';$params[]=$m->user_id;}}if($status){$where[]='p.status=%s';$params[]=$status;}if($from){$where[]='p.payment_date >= %s';$params[]=$from.' 00:00:00';}if($to){$where[]='p.payment_date <= %s';$params[]=$to.' 23:59:59';}if(preg_match('/^\d{4}-\d{2}$/',$month,$mm)){$where[]='EXISTS (SELECT 1 FROM '.BSXMH_DB::table('payment_items').' pi WHERE pi.payment_id=p.id AND CONCAT(pi.period_year,"-",LPAD(pi.period_month,2,"0"))=%s)';$params[]=$month;}$sql="SELECT p.*,u.display_name,m.member_number FROM ".BSXMH_DB::table('payments')." p LEFT JOIN {$wpdb->users} u ON u.ID=p.user_id LEFT JOIN ".BSXMH_DB::table('members')." m ON m.user_id=p.user_id WHERE ".implode(' AND ',$where).' ORDER BY p.payment_date DESC,p.id DESC';if($params)$sql=$wpdb->prepare($sql,$params);$rows=$wpdb->get_results($sql);$members=$wpdb->get_results("SELECT m.id,m.member_number,u.display_name FROM ".BSXMH_DB::table('members')." m LEFT JOIN {$wpdb->users} u ON u.ID=m.user_id WHERE m.status <> 'deleted' ORDER BY u.display_name");$this->notices();echo '<div class="wrap bsxmh-wrap"><h1>Payments <a class="page-title-action" href="'.esc_url(admin_url('admin.php?page=bsxmh-payments&action=add')).'">Add Manual Payment</a></h1><form method="get"><input type="hidden" name="page" value="bsxmh-payments"><select name="member_id" class="bsxmh-member-search"><option value="">All members</option>';foreach($members as$m)echo '<option value="'.$m->id.'" '.selected($member_id,$m->id,false).'>'.esc_html($m->member_number.' — '.$m->display_name).'</option>';echo '</select> <input type="month" name="month" value="'.esc_attr($month).'"> <select name="status"><option value="">All statuses</option>';foreach(array('paid','pending','failed','cancelled','refunded')as$st)echo '<option '.selected($status,$st,false).' value="'.$st.'">'.ucfirst($st).'</option>';echo '</select> <input type="date" name="date_from" value="'.esc_attr($from).'"> <input type="date" name="date_to" value="'.esc_attr($to).'"> <button class="button">Filter</button></form>'.$this->history_table($rows,true).'</div>';
    }

    private function render_payment_form():void{
        global$wpdb;$selected=absint($_GET['member_id']??0);$members=$wpdb->get_results("SELECT m.*,u.display_name FROM ".BSXMH_DB::table('members')." m LEFT JOIN {$wpdb->users} u ON u.ID=m.user_id WHERE m.status IN ('active','inactive','suspended') ORDER BY u.display_name");$chosen=$selected?BSXMH_Members::get($selected):null;$periods=$chosen?BSXMH_Payments::eligible_months($chosen,wp_date('Y-m',strtotime('+12 months',current_time('timestamp')))):array();$paid=$chosen?BSXMH_Payments::paid_month_keys((int)$chosen->user_id):array();$this->notices();echo '<div class="wrap bsxmh-wrap"><h1>Add Manual Payment</h1><div class="bsxmh-panel"><form method="get"><input type="hidden" name="page" value="bsxmh-payments"><input type="hidden" name="action" value="add"><label><strong>Select Member</strong></label> <select name="member_id" class="bsxmh-member-search" required><option value="">Choose member</option>';foreach($members as$m)echo '<option value="'.$m->id.'" '.selected($selected,$m->id,false).'>'.esc_html($m->member_number.' — '.$m->display_name).'</option>';echo '</select> <button class="button">Load Months</button></form></div>';if($chosen){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="bsxmh_save_manual_payment"><input type="hidden" name="member_id" value="'.$selected.'">';wp_nonce_field('bsxmh_save_manual_payment');echo '<div class="bsxmh-panel"><p><strong>Monthly fee:</strong> '.esc_html(BSXMH_Payments::currency_symbol().number_format_i18n((float)$chosen->monthly_fee,2)).'</p><table class="form-table"><tr><th>Payment Months</th><td><div class="bsxmh-month-grid">';foreach($periods as$p){$is=in_array($p['key'],$paid,true);echo '<label><input type="checkbox" name="periods[]" value="'.esc_attr($p['key']).'"> '.esc_html(BSXMH_Payments::month_label($p['year'],$p['month'])).($is?' <em>(Paid)</em>':'').'</label>';}echo '</div><p class="description">Current dues and up to 12 future months are available for advance payment.</p></td></tr>';$this->input('amount','Total Amount','','number');$this->input('payment_date','Payment Date',current_time('Y-m-d'),'date');echo '<tr><th>Payment Method</th><td><select name="payment_method"><option value="cash">Cash</option><option value="bank">Bank Transfer</option><option value="mobile_banking">Mobile Banking</option><option value="cheque">Cheque</option><option value="other">Other</option></select></td></tr>';$this->input('reference_number','Reference Number','');$this->input('received_by','Received By',wp_get_current_user()->display_name);echo '<tr><th>Notes</th><td><textarea class="large-text" rows="3" name="notes"></textarea></td></tr><tr><th>Duplicate Override</th><td><label><input type="checkbox" name="duplicate_override" value="1"> Allow a second paid entry for an already-paid month</label><p class="description">Use only for a deliberate adjustment. The override is recorded in the audit log.</p></td></tr></table></div>';submit_button('Save Paid Payment');echo '</form>';}echo '</div>';
    }

    public function render_reports():void{global$wpdb;$total=(float)$wpdb->get_var("SELECT COALESCE(SUM(total_amount),0) FROM ".BSXMH_DB::table('payments')." WHERE status='paid'");$extra=(float)$wpdb->get_var("SELECT COALESCE(SUM(total_amount),0) FROM ".BSXMH_DB::table('payments')." WHERE status='paid' AND payment_type='extra_contribution'");$event=(float)$wpdb->get_var("SELECT COALESCE(SUM(total_amount),0) FROM ".BSXMH_DB::table('payments')." WHERE status='paid' AND payment_type='event_donation'");$count=(int)$wpdb->get_var("SELECT COUNT(*) FROM ".BSXMH_DB::table('payments')." WHERE status='paid'");echo '<div class="wrap bsxmh-wrap"><h1>Membership Reports</h1><div class="bsxmh-cards">';$this->card('Paid Transactions',number_format_i18n($count));$this->card('Total Collection',BSXMH_Payments::currency_symbol().number_format_i18n($total,2));$this->card('Extra Contribution',BSXMH_Payments::currency_symbol().number_format_i18n($extra,2));$this->card('Event Collection',BSXMH_Payments::currency_symbol().number_format_i18n($event,2));echo '</div><div class="bsxmh-panel"><p>Detailed monthly, yearly and CSV reports will be expanded in the final reporting milestone. Member statements and payment filters are available now.</p></div></div>';}

    private function history_table(array $rows,bool $with_member=false):string{if(!$rows)return '<p>No payments found.</p>';$out='<table class="widefat striped"><thead><tr>'.($with_member?'<th>Member</th>':'').'<th>Transaction</th><th>Type</th><th>Date</th><th>Details</th><th>Amount</th><th>Method</th><th>Status</th><th>Receipt</th></tr></thead><tbody>';foreach($rows as$p){$items=BSXMH_Payments::items((int)$p->id);$labels=array_map(fn($i)=>$i->description,$items);$meta=json_decode((string)$p->metadata,true);$receipt=BSXMH_Receipts::get_by_payment((int)$p->id);$action=$receipt?'<a href="'.esc_url(BSXMH_Receipts::admin_url((int)$receipt->id)).'">View / Print</a> | <a target="_blank" href="'.esc_url(BSXMH_Receipts::public_url($receipt)).'">Verify</a>':'—';$out.='<tr>'.($with_member?'<td>'.esc_html(($p->member_number??'').' — '.($p->display_name??'')).'</td>':'').'<td><code>'.esc_html($p->transaction_id).'</code></td><td>'.esc_html(ucwords(str_replace('_',' ',$p->payment_type))).'</td><td>'.esc_html($p->payment_date).'</td><td>'.esc_html(implode(', ',$labels)).'</td><td>'.esc_html(BSXMH_Payments::currency_symbol().number_format_i18n((float)$p->total_amount,2)).'</td><td>'.esc_html(ucwords(str_replace('_',' ',$meta['method']??$p->gateway))).'</td><td>'.esc_html(ucfirst($p->status)).'</td><td>'.$action.'</td></tr>';}$out.='</tbody></table>';return$out;}
    private function period_badges(array $periods,string $empty):string{if(!$periods)return esc_html($empty);return implode(' ',array_map(fn($p)=>'<span class="bsxmh-badge">'.esc_html(BSXMH_Payments::month_label($p['year'],$p['month'])).'</span>',$periods));}
    private function input(string$key,string$label,$value,string$type='text',bool$required=false):void{echo '<tr><th><label for="'.esc_attr($key).'">'.esc_html($label).'</label></th><td><input class="regular-text" type="'.esc_attr($type).'" id="'.esc_attr($key).'" name="'.esc_attr($key).'" value="'.esc_attr($value).'"'.($required?' required':'').('number'===$type?' step="0.01" min="0"':'').'></td></tr>';}
    private function statuses():array{return array('pending'=>'Pending','active'=>'Active','inactive'=>'Inactive','suspended'=>'Suspended','deleted'=>'Deleted User');}
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
    public function render_settings(): void {
        $s=get_option('bsxmh_settings',array());
        $funds=BSXMH_Contributions::funds(true);
        $default_fund=BSXMH_Contributions::default_membership_fund_id();
        echo '<div class="wrap bsxmh-wrap"><h1>MemberHub Settings</h1><form method="post" action="options.php">';settings_fields('bsxmh_settings_group');
        echo '<div class="bsxmh-panel"><h2>Organization</h2><table class="form-table">';
        foreach(array('organization_name'=>'Organization Name','organization_email'=>'Email','organization_phone'=>'Phone','currency'=>'Currency','timezone'=>'Timezone','default_monthly_fee'=>'Default Monthly Fee','member_id_prefix'=>'Member ID Prefix','organization_start_month'=>'Start Month (1–12)','organization_start_year'=>'Start Year')as$k=>$l)$this->settings_input($k,$l,$s,in_array($k,array('default_monthly_fee','organization_start_month','organization_start_year'),true)?'number':($k==='organization_email'?'email':'text'));
        echo '<tr><th>Website</th><td><input class="regular-text" type="url" name="bsxmh_settings[organization_website]" value="'.esc_attr($s['organization_website']??'').'"></td></tr><tr><th>Support Email</th><td><input class="regular-text" type="email" name="bsxmh_settings[support_email]" value="'.esc_attr($s['support_email']??'').'"></td></tr><tr><th>Support Phone</th><td><input class="regular-text" name="bsxmh_settings[support_phone]" value="'.esc_attr($s['support_phone']??'').'"></td></tr>';
        echo '<tr><th>Default Membership Fee Fund</th><td><select name="bsxmh_settings[default_membership_fund_id]" required>';
        foreach($funds as$f)echo '<option value="'.esc_attr($f->id).'" '.selected((int)($s['default_membership_fund_id']??$default_fund),(int)$f->id,false).'>'.esc_html($f->name).'</option>';
        echo '</select><p class="description">Assigned to newly registered members. Their successful and manually recorded monthly fees are credited to this fund unless changed in the member profile.</p></td></tr><tr><th>Address</th><td><textarea class="large-text" rows="3" name="bsxmh_settings[organization_address]">'.esc_textarea($s['organization_address']??'').'</textarea></td></tr></table></div>';
        echo '<div class="bsxmh-panel"><h2>Organization Branding</h2><table class="form-table"><tr><th>Primary Color</th><td><input type="color" name="bsxmh_settings[portal_primary_color]" value="'.esc_attr($s['portal_primary_color']??'#183153').'"><p class="description">Used for portal navigation, primary buttons and highlights.</p></td></tr><tr><th>Secondary Color</th><td><input type="color" name="bsxmh_settings[portal_secondary_color]" value="'.esc_attr($s['portal_secondary_color']??'#2563eb').'"></td></tr><tr><th>Portal Footer</th><td><input class="large-text" name="bsxmh_settings[portal_footer_text]" value="'.esc_attr($s['portal_footer_text']??'').'"><p class="description">Shown below logged-in member portal pages.</p></td></tr></table></div>';
        echo '<div class="bsxmh-panel"><h2>Registration, Login & Visibility</h2><table class="form-table"><tr><th>Frontend Registration</th><td><input type="checkbox" name="bsxmh_settings[registration_enabled]" value="1" '.checked(!empty($s['registration_enabled']),true,false).'> Enabled</td></tr><tr><th>Admin Approval</th><td><input type="checkbox" name="bsxmh_settings[registration_requires_approval]" value="1" '.checked(!empty($s['registration_requires_approval']),true,false).'> Required</td></tr><tr><th>Member Profile Photo</th><td><label><input type="checkbox" name="bsxmh_settings[allow_member_profile_photo]" value="1" '.checked(!isset($s['allow_member_profile_photo'])||!empty($s['allow_member_profile_photo']),true,false).'> Allow members to upload, replace and remove their own profile photo</label></td></tr><tr><th>Maximum Photo Size</th><td><input type="number" min="1" max="10" name="bsxmh_settings[profile_photo_max_mb]" value="'.esc_attr($s['profile_photo_max_mb']??2).'"> MB</td></tr><tr><th>Registration Description</th><td><textarea class="large-text" rows="2" name="bsxmh_settings[registration_description]">'.esc_textarea($s['registration_description']??'Create your member account using the form below.').'</textarea><p class="description">Shown above the frontend registration form.</p></td></tr><tr><th>Login Description</th><td><textarea class="large-text" rows="2" name="bsxmh_settings[login_description]">'.esc_textarea($s['login_description']??'Log in to access your member dashboard.').'</textarea><p class="description">Shown above the frontend member login form.</p></td></tr><tr><th>Transparency Dashboard</th><td><select name="bsxmh_settings[public_dashboard_visibility]">';foreach(array('public'=>'Public','members'=>'Logged-in members','admin'=>'Admin only','hidden'=>'Hidden')as$k=>$l)echo '<option value="'.$k.'" '.selected($s['public_dashboard_visibility']??'members',$k,false).'>'.$l.'</option>';echo '</select><p class="description">Controls who can open the auto-created Transparency Dashboard page and whether it appears in member navigation.</p>'; $transparency_url=BSXMH_Portal::page_url('transparency_page_id','/transparency-dashboard/'); echo ' <a class="button button-secondary" target="_blank" href="'.esc_url($transparency_url).'">Open Transparency Page</a></td></tr></table></div>';
        echo '<div class="bsxmh-panel"><h2>Member Directory</h2><table class="form-table"><tr><th>Directory</th><td><label><input type="checkbox" name="bsxmh_settings[member_directory_enabled]" value="1" '.checked(!isset($s['member_directory_enabled'])||!empty($s['member_directory_enabled']),true,false).'> Enable logged-in member directory</label><p class="description">Only active members are listed. Email, phone, address and financial information are never displayed.</p></td></tr><tr><th>Visible Details</th><td><label><input type="checkbox" name="bsxmh_settings[directory_show_photo]" value="1" '.checked(!isset($s['directory_show_photo'])||!empty($s['directory_show_photo']),true,false).'> Profile photo</label><br><label><input type="checkbox" name="bsxmh_settings[directory_show_member_id]" value="1" '.checked(!isset($s['directory_show_member_id'])||!empty($s['directory_show_member_id']),true,false).'> Member ID</label><br><label><input type="checkbox" name="bsxmh_settings[directory_show_join_date]" value="1" '.checked(!isset($s['directory_show_join_date'])||!empty($s['directory_show_join_date']),true,false).'> Member since date</label><br><label><input type="checkbox" name="bsxmh_settings[directory_show_status]" value="1" '.checked(!isset($s['directory_show_status'])||!empty($s['directory_show_status']),true,false).'> Active status</label><br><label><input type="checkbox" name="bsxmh_settings[directory_show_tags]" value="1" '.checked(!isset($s['directory_show_tags'])||!empty($s['directory_show_tags']),true,false).'> Member tags / roles</label></td></tr><tr><th>Privacy</th><td><label><input type="checkbox" name="bsxmh_settings[directory_allow_opt_out]" value="1" '.checked(!isset($s['directory_allow_opt_out'])||!empty($s['directory_allow_opt_out']),true,false).'> Allow members to hide themselves from the directory</label></td></tr><tr><th>Members Per Page</th><td><input type="number" min="6" max="48" name="bsxmh_settings[directory_members_per_page]" value="'.esc_attr($s['directory_members_per_page']??12).'"></td></tr><tr><th>Default Sorting</th><td><select name="bsxmh_settings[directory_default_sort]">';foreach(array('name_asc'=>'Name A–Z','newest'=>'Newest members first','member_id'=>'Member ID')as$k=>$l)echo '<option value="'.esc_attr($k).'" '.selected($s['directory_default_sort']??'name_asc',$k,false).'>'.esc_html($l).'</option>';echo '</select>'; $directory_url=BSXMH_Portal::page_url('directory_page_id','/member-directory/'); echo ' <a class="button button-secondary" target="_blank" href="'.esc_url($directory_url).'">Open Directory</a></td></tr></table></div>';
        echo '<div class="bsxmh-panel"><h2>Membership Card & Verification</h2><table class="form-table"><tr><th>Digital Membership Card</th><td><label><input type="checkbox" name="bsxmh_settings[membership_card_enabled]" value="1" '.checked(!isset($s['membership_card_enabled'])||!empty($s['membership_card_enabled']),true,false).'> Enabled</label></td></tr><tr><th>Public QR Verification</th><td><label><input type="checkbox" name="bsxmh_settings[card_verification_enabled]" value="1" '.checked(!isset($s['card_verification_enabled'])||!empty($s['card_verification_enabled']),true,false).'> Enabled</label></td></tr><tr><th>Card Title</th><td><input class="regular-text" name="bsxmh_settings[membership_card_title]" value="'.esc_attr($s['membership_card_title']??'Membership Card').'"></td></tr><tr><th>Accent Color</th><td><input type="color" name="bsxmh_settings[membership_card_accent]" value="'.esc_attr($s['membership_card_accent']??'#183153').'"></td></tr><tr><th>Card Footer</th><td><input class="large-text" name="bsxmh_settings[membership_card_footer]" value="'.esc_attr($s['membership_card_footer']??'This card remains the property of the issuing organization.').'"></td></tr><tr><th>Visible Details</th><td><label><input type="checkbox" name="bsxmh_settings[card_show_member_since]" value="1" '.checked(!empty($s['card_show_member_since']),true,false).'> Member since on card</label><br><label><input type="checkbox" name="bsxmh_settings[verification_show_photo]" value="1" '.checked(!empty($s['verification_show_photo']),true,false).'> Photo on verification page</label><br><label><input type="checkbox" name="bsxmh_settings[verification_show_member_since]" value="1" '.checked(!empty($s['verification_show_member_since']),true,false).'> Member since on verification page</label></td></tr></table></div>';
        echo '<div class="bsxmh-panel"><h2>Receipt</h2><table class="form-table">';foreach(array('receipt_prefix'=>'Receipt Prefix','receipt_title'=>'Receipt Title','organization_logo_url'=>'Logo URL','receipt_thank_you'=>'Thank You Message','voucher_prefix'=>'Voucher Prefix','voucher_title'=>'Voucher Title')as$k=>$l)$this->settings_input($k,$l,$s);echo '<tr><th>Header Text</th><td><textarea class="large-text" rows="3" name="bsxmh_settings[receipt_header]">'.esc_textarea($s['receipt_header']??'').'</textarea></td></tr><tr><th>Footer Text</th><td><textarea class="large-text" rows="3" name="bsxmh_settings[receipt_footer]">'.esc_textarea($s['receipt_footer']??'').'</textarea></td></tr></table><p><a class="button" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=bsxmh_migrate_receipts'),'bsxmh_migrate_receipts')).'">Generate Missing Receipts</a></p></div>';
        echo '<div class="bsxmh-panel"><h2>Shortcodes</h2><p>View every available shortcode, parameters and examples from the dedicated <a href="'.esc_url(admin_url('admin.php?page=bsxmh-shortcodes')).'">Shortcodes page</a>.</p></div>';
        submit_button();echo '</form></div>';
    }

    public function render_shortcodes(): void {
        $rows=array(
            array('[bsxmh_login]','Member login form.','None','[bsxmh_login]'),
            array('[bsxmh_membership_card]','Logged-in member digital card with QR verification.','None','[bsxmh_membership_card]'),
            array('[bsxmh_member_verification]','Public QR/manual-code verification page.','bsxmh_verify or bsxmh_code in URL','[bsxmh_member_verification]'),
            array('[bsxmh_profile]','Logged-in member profile form.','None','[bsxmh_profile]'),
            array('[bsxmh_member_directory]','Privacy-safe directory for logged-in active members.','directory_search, directory_tag and directory_member in URL','[bsxmh_member_directory]'),
            array('[bsxmh_guest_payment]','Secure guest payment page used by reminder links.','token in URL','[bsxmh_guest_payment]'),
            array('[bsxmh_dashboard]','Member portal dashboard with overview, fees, contributions, events and profile.','None','[bsxmh_dashboard]'),
            array('[bsxmh_register]','Frontend member registration form.','None','[bsxmh_register]'),
            array('[bsxmh_payment]','Online monthly membership fee payment form.','None','[bsxmh_payment]'),
            array('[bsxmh_online_contribution]','Online contribution form with fund selection.','None','[bsxmh_online_contribution]'),
            array('[bsxmh_event_list]','Lists active fundraising events.','None','[bsxmh_event_list]'),
            array('[bsxmh_event]','Displays one event and its donation form.','id, slug','[bsxmh_event id="12"]'),
            array('[bsxmh_online_event_donation]','Online donation form for a specific event.','id','[bsxmh_online_event_donation id="12"]'),
            array('[bsxmh_collection_summary]','Public collection and finance summary.','None','[bsxmh_collection_summary]'),
            array('[bsxmh_finance_summary]','Alias of collection summary.','None','[bsxmh_finance_summary]'),
            array('[bsxmh_transparency_dashboard]','Configurable public transparency dashboard.','None','[bsxmh_transparency_dashboard]'),
        );
        echo '<div class="wrap bsxmh-wrap"><h1>MemberHub Shortcodes</h1><p>Copy a shortcode and paste it into a WordPress page, post or shortcode widget.</p><div class="bsxmh-panel"><table class="widefat striped"><thead><tr><th>Shortcode</th><th>Purpose</th><th>Parameters</th><th>Example</th><th></th></tr></thead><tbody>';
        foreach($rows as$r){$copy=esc_attr($r[3]);echo '<tr><td><code>'.esc_html($r[0]).'</code></td><td>'.esc_html($r[1]).'</td><td>'.esc_html($r[2]).'</td><td><code>'.esc_html($r[3]).'</code></td><td><button type="button" class="button bsxmh-copy-shortcode" data-copy="'.$copy.'">Copy</button></td></tr>';}
        echo '</tbody></table></div><script>document.addEventListener("click",function(e){if(!e.target.classList.contains("bsxmh-copy-shortcode"))return;var b=e.target,t=b.getAttribute("data-copy");navigator.clipboard.writeText(t).then(function(){var o=b.textContent;b.textContent="Copied";setTimeout(function(){b.textContent=o},1200);});});</script></div>';
    }

    private function settings_input(string$k,string$l,array$s,string$t='text'):void{echo '<tr><th>'.$l.'</th><td><input class="regular-text" type="'.$t.'" name="bsxmh_settings['.$k.']" value="'.esc_attr($s[$k]??'').'"'.($t==='number'?' step="0.01" min="0"':'').'></td></tr>';}
    public function render_health():void{
        global $wpdb;
        $cron=wp_next_scheduled('bsxmh_daily_scheduled_tasks');
        $orphans=BSXMH_Members::orphan_members();
        $deleted=(int)$wpdb->get_var("SELECT COUNT(*) FROM ".BSXMH_DB::table('members')." WHERE status='deleted'");
        echo '<div class="wrap bsxmh-wrap"><h1>System Health</h1>';
        $this->notices();
        echo '<div class="bsxmh-panel"><table class="widefat striped"><tbody>';
        foreach(array(
            'Plugin version'=>BSXMH_VERSION,
            'WordPress'=>get_bloginfo('version'),
            'PHP'=>PHP_VERSION,
            'Member records'=>(string)$wpdb->get_var('SELECT COUNT(*) FROM '.BSXMH_DB::table('members')),
            'Deleted member records'=>(string)$deleted,
            'Unrepaired orphan members'=>(string)count($orphans),
            'Payment records'=>(string)$wpdb->get_var('SELECT COUNT(*) FROM '.BSXMH_DB::table('payments')),
            'Daily cron'=>$cron?wp_date('Y-m-d H:i:s',$cron):'Not scheduled',
            'SSL'=>is_ssl()?'Enabled':'Not detected'
        )as$label=>$value){echo '<tr><td>'.esc_html($label).'</td><td>'.esc_html($value).'</td></tr>';}
        echo '</tbody></table></div><div class="bsxmh-panel"><h2>Member–WordPress User Sync</h2><p>Scan for MemberHub records whose linked WordPress user has been deleted. Repairing marks them as <strong>Deleted User</strong> while preserving payments, receipts, contributions and audit history.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="bsxmh_repair_orphan_members">';
        wp_nonce_field('bsxmh_repair_orphan_members');
        submit_button('Scan & Repair Orphan Members','secondary','submit',false);
        echo ' <a class="button" href="'.esc_url(admin_url('admin.php?page=bsxmh-members&view=deleted')).'">View Deleted Members</a></form></div></div>';
    }

    public function repair_orphan_members():void{
        if(!current_user_can('manage_bsxmh'))wp_die('Not allowed.');
        check_admin_referer('bsxmh_repair_orphan_members');
        $count=BSXMH_Members::repair_orphans();
        wp_safe_redirect(add_query_arg(array('page'=>'bsxmh-system-health','bsxmh_notice'=>'orphans-repaired','count'=>$count),admin_url('admin.php')));exit;
    }

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
        $members=$wpdb->get_results("SELECT m.id,m.member_number,u.display_name FROM ".BSXMH_DB::table('members')." m LEFT JOIN {$wpdb->users} u ON u.ID=m.user_id WHERE m.status <> 'deleted' ORDER BY u.display_name");$funds=BSXMH_Contributions::funds();
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
