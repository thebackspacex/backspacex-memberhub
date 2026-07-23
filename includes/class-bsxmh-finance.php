<?php

defined( 'ABSPATH' ) || exit;

final class BSXMH_Finance {
    public static function register(): void {
        add_action( 'admin_post_bsxmh_save_expense', array( __CLASS__, 'handle_save_expense' ) );
        add_action( 'admin_post_bsxmh_save_expense_category', array( __CLASS__, 'handle_save_category' ) );
        add_action( 'admin_post_bsxmh_expense_status', array( __CLASS__, 'handle_status' ) );
        add_action( 'admin_post_bsxmh_expense_voucher', array( __CLASS__, 'handle_voucher' ) );
        add_action( 'admin_post_bsxmh_export_finance_csv', array( __CLASS__, 'handle_export' ) );
    }

    public static function ensure_defaults(): void {
        global $wpdb;
        $table = BSXMH_DB::table( 'expense_categories' );
        $defaults = array( 'Office Rent', 'Utility Bill', 'Staff Salary', 'Event Expense', 'Printing', 'Transport', 'Relief Distribution', 'Miscellaneous' );
        foreach ( $defaults as $name ) {
            $slug = sanitize_title( $name );
            if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug=%s", $slug ) ) ) {
                $wpdb->insert( $table, array( 'name'=>$name, 'slug'=>$slug, 'description'=>'', 'status'=>'active', 'sort_order'=>0, 'created_at'=>current_time('mysql'), 'updated_at'=>current_time('mysql') ) );
            }
        }
        $missing = $wpdb->get_results( 'SELECT id FROM ' . BSXMH_DB::table( 'expenses' ) . " WHERE voucher_number IS NULL OR voucher_number='' ORDER BY id ASC" );
        foreach ( $missing ?: array() as $expense ) {
            $wpdb->update( BSXMH_DB::table( 'expenses' ), array( 'voucher_number'=>self::next_voucher_number(), 'updated_at'=>current_time('mysql') ), array( 'id'=>(int)$expense->id ) );
        }
    }

    public static function categories( bool $active_only = false ): array {
        global $wpdb;
        $sql = 'SELECT * FROM ' . BSXMH_DB::table( 'expense_categories' );
        if ( $active_only ) $sql .= " WHERE status='active'";
        $sql .= ' ORDER BY sort_order ASC,name ASC';
        return $wpdb->get_results( $sql ) ?: array();
    }

    public static function get_expense( int $id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . BSXMH_DB::table('expenses') . ' WHERE id=%d', $id ) );
        return $row ?: null;
    }

    public static function save_expense( array $raw ) {
        global $wpdb;
        $data = wp_unslash( $raw );
        $id = absint( $data['expense_id'] ?? 0 );
        $existing = $id ? self::get_expense( $id ) : null;
        if ( $id && ! $existing ) return new WP_Error( 'not_found', 'Expense not found.' );
        if ( $existing && 'paid' === $existing->status ) return new WP_Error( 'paid_locked', 'Paid expenses are locked. Cancel it and create a correcting entry instead.' );

        $amount = round( (float) ( $data['amount'] ?? 0 ), 2 );
        $fund_id = absint( $data['fund_id'] ?? 0 );
        $category_id = absint( $data['category_id'] ?? 0 );
        $event_id = absint( $data['event_id'] ?? 0 );
        $date = sanitize_text_field( $data['expense_date'] ?? '' );
        $status = sanitize_key( $data['status'] ?? 'paid' );
        $method = sanitize_key( $data['payment_method'] ?? 'cash' );
        if ( $amount <= 0 ) return new WP_Error( 'amount', 'Amount must be greater than zero.' );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) return new WP_Error( 'date', 'A valid expense date is required.' );
        if ( ! in_array( $status, array('draft','pending','approved','paid','cancelled'), true ) ) $status='pending';
        if ( ! in_array( $method, array('cash','bank_transfer','mobile_banking','cheque','card','other'), true ) ) $method='other';
        if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM '.BSXMH_DB::table('funds').' WHERE id=%d', $fund_id ) ) ) return new WP_Error( 'fund', 'Select a valid fund.' );
        if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM '.BSXMH_DB::table('expense_categories').' WHERE id=%d', $category_id ) ) ) return new WP_Error( 'category', 'Select a valid expense category.' );
        if ( $event_id && ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM '.BSXMH_DB::table('events').' WHERE id=%d', $event_id ) ) ) return new WP_Error( 'event', 'Selected event is invalid.' );

        $reference = sanitize_text_field( $data['reference_number'] ?? '' );
        if ( $reference && empty($data['duplicate_override']) ) {
            $dup_sql = 'SELECT id FROM '.BSXMH_DB::table('expenses').' WHERE reference_number=%s AND status<>\'cancelled\'' . ( $id ? ' AND id<>%d' : '' );
            $dup = $id ? $wpdb->get_var($wpdb->prepare($dup_sql,$reference,$id)) : $wpdb->get_var($wpdb->prepare($dup_sql,$reference));
            if ( $dup ) return new WP_Error( 'duplicate', 'This reference number already exists. Tick duplicate override only when intentional.' );
        }

        $attachment_id = $existing ? (int)$existing->attachment_id : 0;
        if ( ! empty($_FILES['receipt_file']['name']) ) {
            require_once ABSPATH.'wp-admin/includes/file.php'; require_once ABSPATH.'wp-admin/includes/media.php'; require_once ABSPATH.'wp-admin/includes/image.php';
            $allowed = array('application/pdf','image/jpeg','image/png','image/webp');
            $check = wp_check_filetype_and_ext( $_FILES['receipt_file']['tmp_name'], $_FILES['receipt_file']['name'] );
            if ( empty($check['type']) || ! in_array($check['type'],$allowed,true) ) return new WP_Error('upload','Only PDF, JPG, PNG and WebP files are allowed.');
            $uploaded = media_handle_upload( 'receipt_file', 0 );
            if ( is_wp_error($uploaded) ) return $uploaded;
            $attachment_id = (int)$uploaded;
        }

        $voucher = $existing ? $existing->voucher_number : self::next_voucher_number();
        $row = array(
            'fund_id'=>$fund_id, 'category_id'=>$category_id, 'event_id'=>$event_id ?: null,
            'voucher_number'=>$voucher, 'amount'=>$amount, 'expense_date'=>$date,
            'description'=>sanitize_textarea_field($data['description']??''), 'payment_method'=>$method,
            'reference_number'=>$reference, 'attachment_id'=>$attachment_id ?: null,
            'paid_by'=>sanitize_text_field($data['paid_by']??''), 'approved_by'=>sanitize_text_field($data['approved_by']??''),
            'notes'=>sanitize_textarea_field($data['notes']??''), 'status'=>$status,
            'updated_at'=>current_time('mysql'),
        );
        if ( ! $existing ) { $row['created_by']=get_current_user_id(); $row['created_at']=current_time('mysql'); }
        $ok = $existing ? $wpdb->update(BSXMH_DB::table('expenses'),$row,array('id'=>$id)) : $wpdb->insert(BSXMH_DB::table('expenses'),$row);
        if ( false === $ok ) return new WP_Error('db','Could not save expense.');
        $saved_id = $existing ? $id : (int)$wpdb->insert_id;
        self::log( $existing?'expense_updated':'expense_created', $saved_id, array('status'=>$status,'amount'=>$amount,'duplicate_override'=>!empty($data['duplicate_override'])) );
        return $saved_id;
    }

    private static function next_voucher_number(): string {
        global $wpdb;
        $settings=get_option('bsxmh_settings',array()); $prefix=strtoupper(sanitize_key($settings['voucher_prefix']??'BSXV')) ?: 'BSXV';
        $year=(int)current_time('Y');
        $count=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.BSXMH_DB::table('expenses').' WHERE YEAR(created_at)=%d',$year));
        do { $count++; $number=sprintf('%s-%d-%06d',$prefix,$year,$count); } while($wpdb->get_var($wpdb->prepare('SELECT id FROM '.BSXMH_DB::table('expenses').' WHERE voucher_number=%s',$number)));
        return $number;
    }

    public static function save_category( array $raw ) {
        global $wpdb; $d=wp_unslash($raw); $id=absint($d['category_id']??0); $name=sanitize_text_field($d['name']??'');
        if(!$name)return new WP_Error('name','Category name is required.'); $slug=sanitize_title($d['slug']??$name);
        $row=array('name'=>$name,'slug'=>$slug,'description'=>sanitize_textarea_field($d['description']??''),'status'=>in_array(($d['status']??''),array('active','inactive'),true)?$d['status']:'active','sort_order'=>intval($d['sort_order']??0),'updated_at'=>current_time('mysql'));
        if($id){$ok=$wpdb->update(BSXMH_DB::table('expense_categories'),$row,array('id'=>$id));}else{$row['created_at']=current_time('mysql');$ok=$wpdb->insert(BSXMH_DB::table('expense_categories'),$row);$id=(int)$wpdb->insert_id;}
        if(false===$ok)return new WP_Error('db','Could not save category.'); self::log('expense_category_saved',$id,array('name'=>$name)); return $id;
    }

    public static function summary( string $from='', string $to='' ): array {
        global $wpdb; $payments=BSXMH_DB::table('payments'); $expenses=BSXMH_DB::table('expenses');
        $pw="status='paid'"; $ew="status='paid'"; $params=array(); $eparams=array();
        if($from){$pw.=' AND payment_date >= %s';$params[]=$from.' 00:00:00';$ew.=' AND expense_date >= %s';$eparams[]=$from;}
        if($to){$pw.=' AND payment_date <= %s';$params[]=$to.' 23:59:59';$ew.=' AND expense_date <= %s';$eparams[]=$to;}
        $q="SELECT payment_type,COALESCE(SUM(total_amount),0) total FROM {$payments} WHERE {$pw} GROUP BY payment_type"; if($params)$q=$wpdb->prepare($q,$params);
        $income=array('membership'=>0.0,'extra_contribution'=>0.0,'event_donation'=>0.0,'other'=>0.0); foreach($wpdb->get_results($q)?:array() as$r){$k=array_key_exists($r->payment_type,$income)?$r->payment_type:'other';$income[$k]+=(float)$r->total;}
        $eq="SELECT COALESCE(SUM(amount),0) FROM {$expenses} WHERE {$ew}";if($eparams)$eq=$wpdb->prepare($eq,$eparams);$expense=(float)$wpdb->get_var($eq);
        $total=array_sum($income); return array('income'=>$income,'total_income'=>$total,'total_expense'=>$expense,'balance'=>$total-$expense);
    }

    public static function fund_statement(): array { return BSXMH_Contributions::fund_summary(); }

    public static function event_summary(): array {
        global $wpdb; $events=BSXMH_DB::table('events');$items=BSXMH_DB::table('payment_items');$payments=BSXMH_DB::table('payments');$expenses=BSXMH_DB::table('expenses');
        return $wpdb->get_results("SELECT e.*,f.name fund_name,COALESCE((SELECT SUM(i.amount) FROM {$items} i JOIN {$payments} p ON p.id=i.payment_id WHERE i.reference_id=e.id AND i.item_type='event_donation' AND p.status='paid'),0) collected,COALESCE((SELECT SUM(x.amount) FROM {$expenses} x WHERE x.event_id=e.id AND x.status='paid'),0) spent FROM {$events} e LEFT JOIN ".BSXMH_DB::table('funds')." f ON f.id=e.fund_id ORDER BY e.created_at DESC")?:array();
    }

    public static function handle_save_expense(): void { if(!current_user_can('bsxmh_manage_finance'))wp_die('Not allowed.');check_admin_referer('bsxmh_save_expense');$r=self::save_expense($_POST);$a=array('page'=>'bsxmh-finance','tab'=>'expenses');if(is_wp_error($r)){$a['action']='add';$a['bsxmh_error']=rawurlencode($r->get_error_message());}else{$a['bsxmh_notice']='expense-saved';}wp_safe_redirect(add_query_arg($a,admin_url('admin.php')));exit; }
    public static function handle_save_category(): void { if(!current_user_can('bsxmh_manage_finance'))wp_die('Not allowed.');check_admin_referer('bsxmh_save_expense_category');$r=self::save_category($_POST);$a=array('page'=>'bsxmh-finance','tab'=>'categories');if(is_wp_error($r))$a['bsxmh_error']=rawurlencode($r->get_error_message());else$a['bsxmh_notice']='category-saved';wp_safe_redirect(add_query_arg($a,admin_url('admin.php')));exit; }
    public static function handle_status(): void { if(!current_user_can('bsxmh_manage_finance'))wp_die('Not allowed.');$id=absint($_GET['expense_id']??0);check_admin_referer('bsxmh_expense_status_'.$id);$e=self::get_expense($id);$status=sanitize_key($_GET['status']??'');if($e&&in_array($status,array('pending','approved','paid','cancelled'),true)){global$wpdb;$row=array('status'=>$status,'updated_at'=>current_time('mysql'));if('approved'===$status)$row['approved_by']=wp_get_current_user()->display_name;$wpdb->update(BSXMH_DB::table('expenses'),$row,array('id'=>$id));self::log('expense_'.$status,$id,array());}wp_safe_redirect(admin_url('admin.php?page=bsxmh-finance&tab=expenses'));exit; }
    public static function handle_voucher(): void { if(!current_user_can('bsxmh_manage_finance'))wp_die('Not allowed.');$id=absint($_GET['expense_id']??0);check_admin_referer('bsxmh_expense_voucher_'.$id);$e=self::expense_details($id);if(!$e)wp_die('Voucher not found.');self::render_voucher($e);exit; }

    public static function expense_details(int$id):?object{global$wpdb;$sql='SELECT x.*,f.name fund_name,c.name category_name,e.title event_title FROM '.BSXMH_DB::table('expenses').' x LEFT JOIN '.BSXMH_DB::table('funds').' f ON f.id=x.fund_id LEFT JOIN '.BSXMH_DB::table('expense_categories').' c ON c.id=x.category_id LEFT JOIN '.BSXMH_DB::table('events').' e ON e.id=x.event_id WHERE x.id=%d';$r=$wpdb->get_row($wpdb->prepare($sql,$id));return$r?:null;}
    private static function render_voucher(object$e):void{$s=get_option('bsxmh_settings',array());?><!doctype html><html><head><meta charset="utf-8"><title><?php echo esc_html($e->voucher_number);?></title><style>body{font-family:Arial,sans-serif;color:#222;margin:30px}.box{max-width:800px;margin:auto;border:1px solid #bbb;padding:30px}h1{text-align:center;margin:0}.meta{width:100%;border-collapse:collapse;margin-top:25px}.meta td,.meta th{border:1px solid #ddd;padding:10px;text-align:left}.actions{text-align:center;margin:20px}@media print{.actions{display:none}.box{border:0}}</style></head><body><div class="actions"><button onclick="window.print()">Print / Save as PDF</button></div><div class="box"><h1><?php echo esc_html($s['voucher_title']??'Expense Voucher');?></h1><p style="text-align:center"><strong><?php echo esc_html($s['organization_name']??get_bloginfo('name'));?></strong><br><?php echo nl2br(esc_html($s['organization_address']??''));?></p><table class="meta"><tr><th>Voucher Number</th><td><?php echo esc_html($e->voucher_number);?></td><th>Status</th><td><?php echo esc_html(ucfirst($e->status));?></td></tr><tr><th>Date</th><td><?php echo esc_html($e->expense_date);?></td><th>Amount</th><td><?php echo esc_html(BSXMH_Payments::currency_symbol().number_format_i18n((float)$e->amount,2));?></td></tr><tr><th>Category</th><td><?php echo esc_html($e->category_name?:'—');?></td><th>Fund</th><td><?php echo esc_html($e->fund_name?:'—');?></td></tr><tr><th>Event</th><td><?php echo esc_html($e->event_title?:'—');?></td><th>Method</th><td><?php echo esc_html(ucwords(str_replace('_',' ',$e->payment_method)));?></td></tr><tr><th>Description</th><td colspan="3"><?php echo nl2br(esc_html($e->description));?></td></tr><tr><th>Reference</th><td><?php echo esc_html($e->reference_number?:'—');?></td><th>Paid By</th><td><?php echo esc_html($e->paid_by?:'—');?></td></tr><tr><th>Approved By</th><td><?php echo esc_html($e->approved_by?:'—');?></td><th>Attachment</th><td><?php echo $e->attachment_id?'<a href="'.esc_url(wp_get_attachment_url((int)$e->attachment_id)).'">Open document</a>':'—';?></td></tr></table><p style="margin-top:60px;display:flex;justify-content:space-between"><span>Paid by: __________________</span><span>Approved by: __________________</span></p></div></body></html><?php }

    public static function handle_export():void{if(!current_user_can('bsxmh_view_reports'))wp_die('Not allowed.');check_admin_referer('bsxmh_export_finance_csv');$type=sanitize_key($_GET['type']??'expenses');$from=sanitize_text_field($_GET['date_from']??'');$to=sanitize_text_field($_GET['date_to']??'');nocache_headers();header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename=memberhub-'.$type.'-'.gmdate('Ymd-His').'.csv');$out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");global$wpdb;if('income'===$type){fputcsv($out,array('Date','Transaction ID','Type','Amount','Status'));$where="status='paid'";$params=array();if($from){$where.=' AND payment_date>=%s';$params[]=$from.' 00:00:00';}if($to){$where.=' AND payment_date<=%s';$params[]=$to.' 23:59:59';}$q='SELECT * FROM '.BSXMH_DB::table('payments').' WHERE '.$where.' ORDER BY payment_date';if($params)$q=$wpdb->prepare($q,$params);foreach($wpdb->get_results($q)?:array()as$r)fputcsv($out,array($r->payment_date,$r->transaction_id,$r->payment_type,$r->total_amount,$r->status));}else{fputcsv($out,array('Date','Voucher','Category','Fund','Event','Description','Amount','Method','Reference','Status'));$where='1=1';$params=array();if($from){$where.=' AND x.expense_date>=%s';$params[]=$from;}if($to){$where.=' AND x.expense_date<=%s';$params[]=$to;}$q='SELECT x.*,f.name fund_name,c.name category_name,e.title event_title FROM '.BSXMH_DB::table('expenses').' x LEFT JOIN '.BSXMH_DB::table('funds').' f ON f.id=x.fund_id LEFT JOIN '.BSXMH_DB::table('expense_categories').' c ON c.id=x.category_id LEFT JOIN '.BSXMH_DB::table('events').' e ON e.id=x.event_id WHERE '.$where.' ORDER BY x.expense_date';if($params)$q=$wpdb->prepare($q,$params);foreach($wpdb->get_results($q)?:array()as$r)fputcsv($out,array($r->expense_date,$r->voucher_number,$r->category_name,$r->fund_name,$r->event_title,$r->description,$r->amount,$r->payment_method,$r->reference_number,$r->status));}fclose($out);exit;}

    public static function render_finance():void{
        if(!current_user_can('bsxmh_manage_finance'))wp_die('Not allowed.');global$wpdb;$tab=sanitize_key($_GET['tab']??'dashboard');$action=sanitize_key($_GET['action']??'');$from=sanitize_text_field($_GET['date_from']??'');$to=sanitize_text_field($_GET['date_to']??'');$sum=self::summary($from,$to);
        echo '<div class="wrap bsxmh-wrap"><h1>Finance Manager</h1>';self::notice();echo '<nav class="nav-tab-wrapper">';foreach(array('dashboard'=>'Dashboard','expenses'=>'Expenses','categories'=>'Categories','funds'=>'Fund Statements','events'=>'Event Finance')as$k=>$v)echo '<a class="nav-tab '.($tab===$k?'nav-tab-active':'').'" href="'.esc_url(admin_url('admin.php?page=bsxmh-finance&tab='.$k)).'">'.esc_html($v).'</a>';echo '</nav>';
        if('expenses'===$tab)self::render_expenses($action);elseif('categories'===$tab)self::render_categories();elseif('funds'===$tab)self::render_funds();elseif('events'===$tab)self::render_events();else self::render_dashboard($sum,$from,$to);echo '</div>';
    }
    private static function notice():void{if(!empty($_GET['bsxmh_error']))echo '<div class="notice notice-error"><p>'.esc_html(rawurldecode(wp_unslash($_GET['bsxmh_error']))).'</p></div>';if(!empty($_GET['bsxmh_notice']))echo '<div class="notice notice-success"><p>Saved successfully.</p></div>';}
    private static function cards(array$items):void{echo '<div class="bsxmh-cards">';foreach($items as$label=>$value)echo '<div class="bsxmh-card"><span>'.esc_html($label).'</span><strong>'.esc_html($value).'</strong></div>';echo '</div>';}
    private static function render_dashboard(array$s,string$from,string$to):void{$sym=BSXMH_Payments::currency_symbol();$month=self::summary(current_time('Y-m-01'),current_time('Y-m-d'));echo '<form method="get" style="margin:18px 0"><input type="hidden" name="page" value="bsxmh-finance"><input type="date" name="date_from" value="'.esc_attr($from).'"> <input type="date" name="date_to" value="'.esc_attr($to).'"> <button class="button">Apply</button></form>';self::cards(array('Total Collection'=>$sym.number_format_i18n($s['total_income'],2),'Membership Collection'=>$sym.number_format_i18n($s['income']['membership'],2),'Extra Contribution'=>$sym.number_format_i18n($s['income']['extra_contribution'],2),'Event Collection'=>$sym.number_format_i18n($s['income']['event_donation'],2),'Total Expense'=>$sym.number_format_i18n($s['total_expense'],2),'Current Balance'=>$sym.number_format_i18n($s['balance'],2),'This Month Income'=>$sym.number_format_i18n($month['total_income'],2),'This Month Expense'=>$sym.number_format_i18n($month['total_expense'],2)));echo '<div class="bsxmh-panel"><h2>Fund-wise Balance</h2>';self::fund_table();echo '</div>';}
    private static function render_expenses(string$action):void{global$wpdb;$id=absint($_GET['expense_id']??0);$current=$id?self::get_expense($id):null;if(in_array($action,array('add','edit'),true)){self::expense_form($current);return;}$where=array('1=1');$params=array();foreach(array('fund_id'=>'x.fund_id','category_id'=>'x.category_id','event_id'=>'x.event_id')as$key=>$col){$v=absint($_GET[$key]??0);if($v){$where[]="$col=%d";$params[]=$v;}}$status=sanitize_key($_GET['status']??'');if($status){$where[]='x.status=%s';$params[]=$status;}$from=sanitize_text_field($_GET['date_from']??'');$to=sanitize_text_field($_GET['date_to']??'');if($from){$where[]='x.expense_date>=%s';$params[]=$from;}if($to){$where[]='x.expense_date<=%s';$params[]=$to;}$q='SELECT x.*,f.name fund_name,c.name category_name,e.title event_title FROM '.BSXMH_DB::table('expenses').' x LEFT JOIN '.BSXMH_DB::table('funds').' f ON f.id=x.fund_id LEFT JOIN '.BSXMH_DB::table('expense_categories').' c ON c.id=x.category_id LEFT JOIN '.BSXMH_DB::table('events').' e ON e.id=x.event_id WHERE '.implode(' AND ',$where).' ORDER BY x.expense_date DESC,x.id DESC';if($params)$q=$wpdb->prepare($q,$params);$rows=$wpdb->get_results($q)?:array();echo '<p><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=bsxmh-finance&tab=expenses&action=add')).'">Add Expense</a></p><div class="bsxmh-panel"><table class="widefat striped"><thead><tr><th>Date / Voucher</th><th>Category / Fund</th><th>Description</th><th>Amount</th><th>Status</th><th>Actions</th></tr></thead><tbody>';if(!$rows)echo '<tr><td colspan="6">No expenses found.</td></tr>';foreach($rows as$r){$voucher=wp_nonce_url(admin_url('admin-post.php?action=bsxmh_expense_voucher&expense_id='.$r->id),'bsxmh_expense_voucher_'.$r->id);echo '<tr><td>'.esc_html($r->expense_date).'<br><code>'.esc_html($r->voucher_number).'</code></td><td>'.esc_html($r->category_name).'<br><small>'.esc_html($r->fund_name).($r->event_title?' / '.esc_html($r->event_title):'').'</small></td><td>'.esc_html(wp_trim_words($r->description,15)).'</td><td>'.esc_html(BSXMH_Payments::currency_symbol().number_format_i18n((float)$r->amount,2)).'</td><td>'.esc_html(ucfirst($r->status)).'</td><td>';if('paid'!==$r->status)echo '<a href="'.esc_url(admin_url('admin.php?page=bsxmh-finance&tab=expenses&action=edit&expense_id='.$r->id)).'">Edit</a> | ';echo '<a target="_blank" href="'.esc_url($voucher).'">Voucher</a>';if('approved'===$r->status)echo ' | <a href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=bsxmh_expense_status&status=paid&expense_id='.$r->id),'bsxmh_expense_status_'.$r->id)).'">Mark Paid</a>';if(!in_array($r->status,array('paid','cancelled'),true))echo ' | <a href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=bsxmh_expense_status&status=cancelled&expense_id='.$r->id),'bsxmh_expense_status_'.$r->id)).'">Cancel</a>';echo '</td></tr>';}echo '</tbody></table></div>';}
    private static function expense_form(?object$e):void{$funds=BSXMH_Contributions::funds(true);$cats=self::categories(true);$events=BSXMH_Events::all();echo '<div class="bsxmh-panel"><h2>'.($e?'Edit Expense':'Add Expense').'</h2><form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="bsxmh_save_expense"><input type="hidden" name="expense_id" value="'.esc_attr($e->id??0).'">';wp_nonce_field('bsxmh_save_expense');echo '<table class="form-table"><tr><th>Expense Date</th><td><input type="date" name="expense_date" required value="'.esc_attr($e->expense_date??current_time('Y-m-d')).'"></td></tr><tr><th>Amount</th><td><input type="number" min="0.01" step="0.01" name="amount" required value="'.esc_attr($e->amount??'').'"></td></tr><tr><th>Category</th><td><select name="category_id" required><option value="">Select</option>';foreach($cats as$c)echo '<option value="'.$c->id.'" '.selected($e->category_id??0,$c->id,false).'>'.esc_html($c->name).'</option>';echo '</select></td></tr><tr><th>Fund</th><td><select name="fund_id" required><option value="">Select</option>';foreach($funds as$f)echo '<option value="'.$f->id.'" '.selected($e->fund_id??0,$f->id,false).'>'.esc_html($f->name).'</option>';echo '</select></td></tr><tr><th>Related Event</th><td><select name="event_id"><option value="">None</option>';foreach($events as$v)echo '<option value="'.$v->id.'" '.selected($e->event_id??0,$v->id,false).'>'.esc_html($v->title).'</option>';echo '</select></td></tr><tr><th>Description</th><td><textarea class="large-text" rows="4" required name="description">'.esc_textarea($e->description??'').'</textarea></td></tr><tr><th>Payment Method</th><td><select name="payment_method">';foreach(array('cash'=>'Cash','bank_transfer'=>'Bank Transfer','mobile_banking'=>'Mobile Banking','cheque'=>'Cheque','card'=>'Card','other'=>'Other')as$k=>$v)echo '<option value="'.$k.'" '.selected($e->payment_method??'cash',$k,false).'>'.$v.'</option>';echo '</select></td></tr><tr><th>Reference Number</th><td><input class="regular-text" name="reference_number" value="'.esc_attr($e->reference_number??'').'"></td></tr><tr><th>Receipt / Document</th><td><input type="file" name="receipt_file" accept=".pdf,.jpg,.jpeg,.png,.webp">'.(!empty($e->attachment_id)?' <a target="_blank" href="'.esc_url(wp_get_attachment_url((int)$e->attachment_id)).'">Current file</a>':'').'</td></tr><tr><th>Paid By</th><td><input class="regular-text" name="paid_by" value="'.esc_attr($e->paid_by??'').'"></td></tr><tr><th>Approved By</th><td><input class="regular-text" name="approved_by" value="'.esc_attr($e->approved_by??'').'"></td></tr><tr><th>Status</th><td><select name="status">';foreach(array('draft'=>'Draft','pending'=>'Pending','approved'=>'Approved','paid'=>'Paid','cancelled'=>'Cancelled')as$k=>$v)echo '<option value="'.$k.'" '.selected($e->status??'paid',$k,false).'>'.$v.'</option>';echo '</select></td></tr><tr><th>Notes</th><td><textarea class="large-text" rows="3" name="notes">'.esc_textarea($e->notes??'').'</textarea></td></tr><tr><th>Duplicate Override</th><td><label><input type="checkbox" name="duplicate_override" value="1"> Allow intentional duplicate reference</label></td></tr></table>';submit_button($e?'Update Expense':'Save Expense');echo '</form></div>';}
    private static function render_categories():void{$cats=self::categories();$edit=absint($_GET['category_id']??0);$cur=null;foreach($cats as$c)if((int)$c->id===$edit)$cur=$c;echo '<div class="bsxmh-panel"><h2>'.($cur?'Edit':'Add').' Expense Category</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="bsxmh_save_expense_category"><input type="hidden" name="category_id" value="'.esc_attr($edit).'">';wp_nonce_field('bsxmh_save_expense_category');echo '<table class="form-table"><tr><th>Name</th><td><input class="regular-text" required name="name" value="'.esc_attr($cur->name??'').'"></td></tr><tr><th>Slug</th><td><input class="regular-text" name="slug" value="'.esc_attr($cur->slug??'').'"></td></tr><tr><th>Description</th><td><textarea class="large-text" name="description">'.esc_textarea($cur->description??'').'</textarea></td></tr><tr><th>Order</th><td><input type="number" name="sort_order" value="'.esc_attr($cur->sort_order??0).'"></td></tr><tr><th>Status</th><td><select name="status"><option value="active" '.selected($cur->status??'active','active',false).'>Active</option><option value="inactive" '.selected($cur->status??'','inactive',false).'>Inactive</option></select></td></tr></table>';submit_button('Save Category');echo '</form></div><div class="bsxmh-panel"><table class="widefat striped"><thead><tr><th>Name</th><th>Description</th><th>Status</th><th></th></tr></thead><tbody>';foreach($cats as$c)echo '<tr><td>'.esc_html($c->name).'</td><td>'.esc_html($c->description).'</td><td>'.esc_html(ucfirst($c->status)).'</td><td><a href="'.esc_url(admin_url('admin.php?page=bsxmh-finance&tab=categories&category_id='.$c->id)).'">Edit</a></td></tr>';echo '</tbody></table></div>';}
    private static function render_funds():void{echo '<div class="bsxmh-panel"><h2>Fund Statements</h2>';self::fund_table();echo '</div>';}
    private static function fund_table():void{$sym=BSXMH_Payments::currency_symbol();echo '<table class="widefat striped"><thead><tr><th>Fund</th><th>Opening</th><th>Collected</th><th>Spent</th><th>Balance</th></tr></thead><tbody>';foreach(self::fund_statement()as$r){$f=$r['fund'];echo '<tr><td>'.esc_html($f->name).'</td><td>'.esc_html($sym.number_format_i18n((float)$f->opening_balance,2)).'</td><td>'.esc_html($sym.number_format_i18n($r['collected'],2)).'</td><td>'.esc_html($sym.number_format_i18n($r['spent'],2)).'</td><td><strong>'.esc_html($sym.number_format_i18n($r['balance'],2)).'</strong></td></tr>';}echo '</tbody></table>';}
    private static function render_events():void{$sym=BSXMH_Payments::currency_symbol();echo '<div class="bsxmh-panel"><h2>Event Finance Summary</h2><table class="widefat striped"><thead><tr><th>Event</th><th>Fund</th><th>Target</th><th>Collected</th><th>Expense</th><th>Net Available</th></tr></thead><tbody>';foreach(self::event_summary()as$e)echo '<tr><td>'.esc_html($e->title).'</td><td>'.esc_html($e->fund_name?:'—').'</td><td>'.esc_html($sym.number_format_i18n((float)$e->target_amount,2)).'</td><td>'.esc_html($sym.number_format_i18n((float)$e->collected,2)).'</td><td>'.esc_html($sym.number_format_i18n((float)$e->spent,2)).'</td><td><strong>'.esc_html($sym.number_format_i18n((float)$e->collected-(float)$e->spent,2)).'</strong></td></tr>';echo '</tbody></table></div>';}

    public static function render_reports():void{if(!current_user_can('bsxmh_view_reports'))wp_die('Not allowed.');$from=sanitize_text_field($_GET['date_from']??'');$to=sanitize_text_field($_GET['date_to']??'');$s=self::summary($from,$to);$sym=BSXMH_Payments::currency_symbol();echo '<div class="wrap bsxmh-wrap"><h1>Finance Reports</h1><form method="get"><input type="hidden" name="page" value="bsxmh-reports"><input type="date" name="date_from" value="'.esc_attr($from).'"> <input type="date" name="date_to" value="'.esc_attr($to).'"> <button class="button">Filter</button></form>';self::cards(array('Income'=>$sym.number_format_i18n($s['total_income'],2),'Expense'=>$sym.number_format_i18n($s['total_expense'],2),'Balance'=>$sym.number_format_i18n($s['balance'],2)));$base=admin_url('admin-post.php?action=bsxmh_export_finance_csv&date_from='.rawurlencode($from).'&date_to='.rawurlencode($to));echo '<div class="bsxmh-panel"><h2>CSV Export</h2><p><a class="button" href="'.esc_url(wp_nonce_url($base.'&type=income','bsxmh_export_finance_csv')).'">Export Income</a> <a class="button" href="'.esc_url(wp_nonce_url($base.'&type=expenses','bsxmh_export_finance_csv')).'">Export Expenses</a></p></div><div class="bsxmh-panel"><h2>Fund-wise Report</h2>';self::fund_table();echo '</div><div class="bsxmh-panel"><h2>Category-wise Paid Expense</h2>';global$wpdb;$rows=$wpdb->get_results('SELECT c.name,COALESCE(SUM(x.amount),0) total FROM '.BSXMH_DB::table('expense_categories').' c LEFT JOIN '.BSXMH_DB::table('expenses')." x ON x.category_id=c.id AND x.status='paid' GROUP BY c.id ORDER BY total DESC");echo '<table class="widefat striped"><thead><tr><th>Category</th><th>Expense</th></tr></thead><tbody>';foreach($rows?:array()as$r)echo '<tr><td>'.esc_html($r->name).'</td><td>'.esc_html($sym.number_format_i18n((float)$r->total,2)).'</td></tr>';echo '</tbody></table></div></div>';}

    private static function log(string$action,int$id,array$details):void{global$wpdb;$ip=$_SERVER['REMOTE_ADDR']??'';$wpdb->insert(BSXMH_DB::table('activity_logs'),array('actor_user_id'=>get_current_user_id()?:null,'target_user_id'=>null,'action'=>$action,'object_type'=>'expense','object_id'=>$id,'details'=>wp_json_encode($details),'ip_hash'=>$ip?hash_hmac('sha256',$ip,wp_salt('auth')):null,'created_at'=>current_time('mysql')));}
}
