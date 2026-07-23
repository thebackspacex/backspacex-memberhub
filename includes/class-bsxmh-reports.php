<?php

defined( 'ABSPATH' ) || exit;

final class BSXMH_Reports {
    public static function register(): void {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 25 );
        add_action( 'admin_post_bsxmh_export_report', array( __CLASS__, 'export' ) );
    }
    public static function menu(): void { add_submenu_page('bsxmh','Advanced Reports','Advanced Reports','bsxmh_view_reports','bsxmh-advanced-reports',array(__CLASS__,'page')); }
    private static function range(): array { $from=sanitize_text_field($_GET['from']??gmdate('Y-01-01'));$to=sanitize_text_field($_GET['to']??current_time('Y-m-d'));return[$from,$to]; }
    public static function page(): void {
        global $wpdb;[$from,$to]=self::range();$p=BSXMH_DB::table('payments');$x=BSXMH_DB::table('expenses');$m=BSXMH_DB::table('members');$sym=BSXMH_Payments::currency_symbol();
        $income=(float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(total_amount),0) FROM $p WHERE status='paid' AND DATE(payment_date) BETWEEN %s AND %s",$from,$to));
        $expense=(float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount),0) FROM $x WHERE status='paid' AND expense_date BETWEEN %s AND %s",$from,$to));
        $due=0;$due_members=0;foreach($wpdb->get_results("SELECT * FROM $m WHERE status='active'")as$member){$s=BSXMH_Payments::statement($member);$member_due=(float)($s['total_due']??0);$due+=$member_due;if($member_due>0)$due_members++;}
        $monthly=$wpdb->get_results($wpdb->prepare("SELECT DATE_FORMAT(payment_date,'%%Y-%%m') period,SUM(total_amount) total FROM $p WHERE status='paid' AND DATE(payment_date) BETWEEN %s AND %s GROUP BY period ORDER BY period",$from,$to));
        echo '<div class="wrap bsxmh-wrap"><h1>Advanced Reports</h1><form method="get"><input type="hidden" name="page" value="bsxmh-advanced-reports"><input type="date" name="from" value="'.esc_attr($from).'"> <input type="date" name="to" value="'.esc_attr($to).'"> <button class="button">Apply</button></form><div class="bsxmh-cards">';
        foreach(array('Income'=>$income,'Expense'=>$expense,'Net Balance'=>$income-$expense,'Outstanding Due'=>$due)as$label=>$val)echo '<div class="bsxmh-card"><span>'.esc_html($label).'</span><strong>'.esc_html($sym.number_format_i18n($val,2)).'</strong></div>';echo '<div class="bsxmh-card"><span>Due Members</span><strong>'.esc_html((string)$due_members).'</strong></div></div>';
        echo '<div class="bsxmh-panel"><h2>Monthly Collection Trend</h2><table class="widefat striped"><thead><tr><th>Month</th><th>Collection</th></tr></thead><tbody>';foreach($monthly as$r)echo '<tr><td>'.esc_html($r->period).'</td><td>'.esc_html($sym.number_format_i18n((float)$r->total,2)).'</td></tr>';if(!$monthly)echo '<tr><td colspan="2">No data.</td></tr>';echo '</tbody></table></div>';
        $url=wp_nonce_url(add_query_arg(array('action'=>'bsxmh_export_report','from'=>$from,'to'=>$to),admin_url('admin-post.php')),'bsxmh_export_report');echo '<p><a class="button button-primary" href="'.esc_url($url).'">Export Combined CSV</a> <button class="button" onclick="window.print();return false;">Print Report</button></p></div>';
    }
    public static function export(): void {
        if(!current_user_can('bsxmh_view_reports'))wp_die('Not allowed.');check_admin_referer('bsxmh_export_report');global$wpdb;$from=sanitize_text_field($_GET['from']??'1900-01-01');$to=sanitize_text_field($_GET['to']??'2999-12-31');
        nocache_headers();header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename=memberhub-report-'.gmdate('Ymd-His').'.csv');$o=fopen('php://output','w');fwrite($o,"\xEF\xBB\xBF");fputcsv($o,array('Record Type','Date','Reference','Description','Income','Expense','Status'));
        $rows=$wpdb->get_results($wpdb->prepare("SELECT payment_date d,transaction_id ref,payment_type descr,total_amount amount,status FROM ".BSXMH_DB::table('payments')." WHERE DATE(payment_date) BETWEEN %s AND %s UNION ALL SELECT CONCAT(expense_date,' 00:00:00'),COALESCE(voucher_number,''),description,0-amount,status FROM ".BSXMH_DB::table('expenses')." WHERE expense_date BETWEEN %s AND %s ORDER BY d",$from,$to,$from,$to));
        foreach($rows as$r)fputcsv($o,array(((float)$r->amount>=0?'Income':'Expense'),$r->d,$r->ref,$r->descr,((float)$r->amount>=0?$r->amount:''),((float)$r->amount<0?abs((float)$r->amount):''),$r->status));fclose($o);exit;
    }
}
