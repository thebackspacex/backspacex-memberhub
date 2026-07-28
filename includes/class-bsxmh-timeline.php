<?php

defined( 'ABSPATH' ) || exit;

final class BSXMH_Timeline {
    public static function activity( $member, string $filter = 'all', int $limit = 100 ): array {
        global $wpdb;
        $logs = BSXMH_DB::table( 'activity_logs' );
        $users = $wpdb->users;
        $where = $wpdb->prepare( 'l.target_user_id=%d', (int) $member->user_id );
        $allowed = array( 'membership', 'payments', 'contributions', 'events', 'profile', 'system' );
        if ( in_array( $filter, $allowed, true ) ) {
            $actions = self::actions_for_filter( $filter );
            if ( $actions ) {
                $quoted = implode( ',', array_fill( 0, count( $actions ), '%s' ) );
                $where .= $wpdb->prepare( " AND l.action IN ($quoted)", ...$actions );
            }
        }
        return $wpdb->get_results( "SELECT l.*,u.display_name actor_name FROM {$logs} l LEFT JOIN {$users} u ON u.ID=l.actor_user_id WHERE {$where} ORDER BY l.created_at DESC,l.id DESC LIMIT " . absint( $limit ) );
    }

    public static function payments( $member, ?string $type = null, int $limit = 200 ): array {
        global $wpdb;
        $sql = "SELECT p.*,GROUP_CONCAT(i.description ORDER BY i.id SEPARATOR ', ') item_descriptions FROM " . BSXMH_DB::table('payments') . " p LEFT JOIN " . BSXMH_DB::table('payment_items') . " i ON i.payment_id=p.id WHERE p.user_id=%d";
        $args = array( (int) $member->user_id );
        if ( $type ) { $sql .= ' AND p.payment_type=%s'; $args[] = $type; }
        $sql .= ' GROUP BY p.id ORDER BY COALESCE(p.payment_date,p.created_at) DESC,p.id DESC LIMIT ' . absint($limit);
        return $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) );
    }

    public static function combined( $member, string $filter = 'all', int $limit = 100 ): array {
        $items = array();
        foreach ( self::activity( $member, $filter, $limit ) as $log ) {
            $items[] = self::log_item( $log );
        }
        $payment_types = array();
        if ( 'payments' === $filter || 'membership' === $filter ) $payment_types = array('membership');
        elseif ( 'contributions' === $filter ) $payment_types = array('extra_contribution');
        elseif ( 'events' === $filter ) $payment_types = array('event_donation');
        elseif ( in_array($filter,array('profile','system'),true) ) $payment_types = array();
        else $payment_types = array('membership','extra_contribution','event_donation');
        foreach ( $payment_types as $type ) {
            foreach ( self::payments( $member, $type, $limit ) as $payment ) {
                $items[] = self::payment_item( $payment );
            }
        }
        usort($items,static fn($a,$b)=>strcmp($b['date'],$a['date']));
        $seen=array();$out=array();
        foreach($items as$item){$key=$item['key'];if(isset($seen[$key]))continue;$seen[$key]=1;$out[]=$item;if(count($out)>=$limit)break;}
        return $out;
    }

    private static function payment_item( $p ): array {
        $labels=array('membership'=>'Membership fee received','extra_contribution'=>'Contribution received','event_donation'=>'Event payment received');
        $symbol=BSXMH_Payments::currency_symbol();
        $title=$labels[$p->payment_type]??'Payment recorded';
        $description=$symbol.number_format_i18n((float)$p->total_amount,2).' · '.ucfirst((string)$p->status);
        if(!empty($p->item_descriptions))$description.=' · '.$p->item_descriptions;
        return array('key'=>'payment-'.$p->id,'date'=>(string)($p->payment_date?:$p->created_at),'category'=>self::category_for_payment($p->payment_type),'title'=>$title,'description'=>$description,'actor'=>'System','object'=>'Payment #'.(int)$p->id);
    }

    private static function log_item( $log ): array {
        $details=json_decode((string)$log->details,true);$details=is_array($details)?$details:array();
        $map=array(
            'member_created'=>'Member registered','member_updated'=>'Member profile updated','wordpress_user_deleted'=>'WordPress user deleted','orphan_member_repaired'=>'Orphan member repaired',
            'manual_payment_created'=>'Membership payment recorded','extra_contribution_created'=>'Contribution recorded','event_donation_created'=>'Event payment recorded','profile_photo_updated'=>'Profile photo updated','profile_photo_removed'=>'Profile photo removed'
        );
        $desc=self::details_text($details);
        return array('key'=>'log-'.$log->id,'date'=>(string)$log->created_at,'category'=>self::category_for_action((string)$log->action),'title'=>$map[$log->action]??ucwords(str_replace('_',' ',(string)$log->action)),'description'=>$desc,'actor'=>$log->actor_name?:'System','object'=>trim((string)$log->object_type.($log->object_id?' #'.$log->object_id:'')));
    }

    private static function details_text(array $d):string{
        $parts=array();
        if(isset($d['old_status'],$d['status']))$parts[]='Status: '.$d['old_status'].' → '.$d['status'];elseif(isset($d['status']))$parts[]='Status: '.$d['status'];
        if(isset($d['amount']))$parts[]='Amount: '.BSXMH_Payments::currency_symbol().number_format_i18n((float)$d['amount'],2);
        if(!empty($d['transaction_id']))$parts[]='Transaction: '.$d['transaction_id'];
        if(!empty($d['periods'])&&is_array($d['periods']))$parts[]='Periods: '.implode(', ',$d['periods']);
        return implode(' · ',$parts);
    }
    private static function category_for_payment(string $type):string{return 'membership'===$type?'payments':('extra_contribution'===$type?'contributions':'events');}
    private static function category_for_action(string $action):string{if(str_contains($action,'payment'))return 'payments';if(str_contains($action,'contribution'))return 'contributions';if(str_contains($action,'event'))return 'events';if(str_contains($action,'member')||str_contains($action,'profile')||str_contains($action,'photo'))return 'profile';return 'system';}
    private static function actions_for_filter(string $filter):array{
        $all=array('membership'=>array('manual_payment_created'),'payments'=>array('manual_payment_created'),'contributions'=>array('extra_contribution_created'),'events'=>array('event_donation_created'),'profile'=>array('member_created','member_updated','profile_photo_updated','profile_photo_removed'),'system'=>array('wordpress_user_deleted','orphan_member_repaired'));
        return $all[$filter]??array();
    }
}
