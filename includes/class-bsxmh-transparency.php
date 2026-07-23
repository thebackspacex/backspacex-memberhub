<?php

defined( 'ABSPATH' ) || exit;

final class BSXMH_Transparency {
    public static function register(): void {
        add_shortcode( 'bsxmh_transparency_dashboard', array( __CLASS__, 'shortcode' ) );
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
    }
    public static function ensure_defaults(): void {
        $defaults = array( 'members'=>1,'collection'=>1,'membership'=>1,'contribution'=>1,'events'=>1,'expense'=>1,'balance'=>1,'funds'=>1,'campaigns'=>1 );
        update_option( 'bsxmh_transparency_widgets', wp_parse_args( get_option( 'bsxmh_transparency_widgets', array() ), $defaults ), false );
    }
    public static function register_settings(): void { register_setting( 'bsxmh_transparency_group', 'bsxmh_transparency_widgets', array( __CLASS__, 'sanitize' ) ); }
    public static function sanitize( $input ): array { $out=array(); foreach(array('members','collection','membership','contribution','events','expense','balance','funds','campaigns') as $k)$out[$k]=empty($input[$k])?0:1; return $out; }
    public static function menu(): void { add_submenu_page( 'bsxmh', 'Transparency', 'Transparency', 'bsxmh_manage_settings', 'bsxmh-transparency', array( __CLASS__, 'page' ) ); }
    public static function page(): void {
        $w=get_option('bsxmh_transparency_widgets',array()); echo '<div class="wrap bsxmh-wrap"><h1>Public Transparency Dashboard</h1><div class="bsxmh-panel"><p>Use shortcode <code>[bsxmh_transparency_dashboard]</code> on any page.</p><form method="post" action="options.php">'; settings_fields('bsxmh_transparency_group'); echo '<table class="form-table">';
        foreach(array('members'=>'Active Members','collection'=>'Total Collection','membership'=>'Membership Collection','contribution'=>'Extra Contribution','events'=>'Event Collection','expense'=>'Total Expense','balance'=>'Current Balance','funds'=>'Fund Summary','campaigns'=>'Active Campaigns') as $k=>$label) echo '<tr><th>'.esc_html($label).'</th><td><label><input type="checkbox" name="bsxmh_transparency_widgets['.esc_attr($k).']" value="1" '.checked(!empty($w[$k]),true,false).'> Show</label></td></tr>';
        echo '</table>'; submit_button(); echo '</form></div></div>';
    }
    public static function shortcode(): string {
        wp_enqueue_style( 'bsxmh-public' );
        $settings=get_option('bsxmh_settings',array()); $vis=$settings['public_dashboard_visibility']??'public';
        if('hidden'===$vis||('members'===$vis&&!is_user_logged_in())||('admin'===$vis&&!current_user_can('manage_bsxmh')))return '';
        global $wpdb; $w=get_option('bsxmh_transparency_widgets',array()); $p=BSXMH_DB::table('payments');$e=BSXMH_DB::table('expenses');$m=BSXMH_DB::table('members');
        $q=function($type='')use($wpdb,$p){$sql="SELECT COALESCE(SUM(total_amount),0) FROM $p WHERE status='paid'".($type?$wpdb->prepare(' AND payment_type=%s',$type):'');return(float)$wpdb->get_var($sql);};
        $all=$q();$membership=$q('membership');$extra=$q('extra_contribution');$events=$q('event_donation');$expense=(float)$wpdb->get_var("SELECT COALESCE(SUM(amount),0) FROM $e WHERE status='paid'");$members=(int)$wpdb->get_var("SELECT COUNT(*) FROM $m WHERE status='active'");$sym=BSXMH_Payments::currency_symbol();
        $cards=array(); if(!empty($w['members']))$cards[]=['Active Members',number_format_i18n($members)]; if(!empty($w['collection']))$cards[]=['Total Collection',$sym.number_format_i18n($all,2)]; if(!empty($w['membership']))$cards[]=['Membership Collection',$sym.number_format_i18n($membership,2)]; if(!empty($w['contribution']))$cards[]=['Extra Contribution',$sym.number_format_i18n($extra,2)]; if(!empty($w['events']))$cards[]=['Event Collection',$sym.number_format_i18n($events,2)]; if(!empty($w['expense']))$cards[]=['Total Expense',$sym.number_format_i18n($expense,2)]; if(!empty($w['balance']))$cards[]=['Current Balance',$sym.number_format_i18n($all-$expense,2)];
        $html='<div class="bsxmh-summary bsxmh-transparency">';foreach($cards as$c)$html.='<div><span>'.esc_html($c[0]).'</span><strong>'.esc_html($c[1]).'</strong></div>';$html.='</div>';
        if(!empty($w['funds'])){$html.='<div class="bsxmh-public-card"><h3>Fund Summary</h3><div class="bsxmh-summary">';foreach(BSXMH_Contributions::fund_summary() as$r){$f=$r['fund'];if('hidden'===$f->visibility)continue;$html.='<div><span>'.esc_html($f->name).'</span><strong>'.esc_html($sym.number_format_i18n($r['balance'],2)).'</strong></div>';}$html.='</div></div>';}
        if(!empty($w['campaigns'])){$active=BSXMH_Events::all(true);if($active){$html.='<div class="bsxmh-public-card"><h3>Active Campaigns</h3><div class="bsxmh-event-grid">';foreach(array_slice($active,0,6)as$event){$s=BSXMH_Events::stats((int)$event->id);$html.='<article class="bsxmh-event-card"><h4>'.esc_html($event->title).'</h4><div class="bsxmh-progress"><span style="width:'.esc_attr((string)$s['percent']).'%"></span></div><p>'.esc_html($sym.number_format_i18n($s['collected'],2).' of '.$sym.number_format_i18n($s['target'],2)).'</p></article>';}$html.='</div></div>';}}
        return $html;
    }
}
