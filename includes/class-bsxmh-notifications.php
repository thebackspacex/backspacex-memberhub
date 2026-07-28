<?php

defined( 'ABSPATH' ) || exit;

final class BSXMH_Notifications {
    public static function register(): void {
        add_shortcode( 'bsxmh_notifications', array( __CLASS__, 'shortcode' ) );
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 30 );
        add_action( 'admin_post_bsxmh_send_notification', array( __CLASS__, 'send_admin_notification' ) );
        add_action( 'template_redirect', array( __CLASS__, 'handle_member_action' ), 2 );
        add_action( 'bsxmh_member_status_changed', array( __CLASS__, 'status_changed' ), 20, 4 );
        add_action( 'bsxmh_payment_completed', array( __CLASS__, 'payment_completed' ), 20 );
    }

    public static function admin_menu(): void {
        add_submenu_page( 'bsxmh', __( 'Notifications', 'bsx-memberhub' ), __( 'Notifications', 'bsx-memberhub' ), 'bsxmh_manage_members', 'bsxmh-notifications', array( __CLASS__, 'render_admin' ) );
    }

    public static function create( int $user_id, string $title, string $message, string $type = 'general', string $action_url = '', int $created_by = 0 ): int {
        global $wpdb;
        if ( $user_id <= 0 || ! get_userdata( $user_id ) ) return 0;
        $ok = $wpdb->insert( BSXMH_DB::table( 'notifications' ), array(
            'user_id' => $user_id,
            'title' => sanitize_text_field( $title ),
            'message' => wp_kses_post( $message ),
            'notification_type' => sanitize_key( $type ) ?: 'general',
            'action_url' => esc_url_raw( $action_url ),
            'is_read' => 0,
            'is_archived' => 0,
            'created_by' => $created_by ?: get_current_user_id(),
            'created_at' => current_time( 'mysql' ),
        ), array( '%d','%s','%s','%s','%s','%d','%d','%d','%s' ) );
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    public static function unread_count( int $user_id = 0 ): int {
        global $wpdb;
        $user_id = $user_id ?: get_current_user_id();
        if ( ! $user_id ) return 0;
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . BSXMH_DB::table( 'notifications' ) . " WHERE user_id=%d AND is_read=0 AND is_archived=0", $user_id ) );
    }

    public static function recent( int $user_id, int $limit = 20, bool $include_archived = false ): array {
        global $wpdb;
        $where = $include_archived ? '' : ' AND is_archived=0';
        return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM " . BSXMH_DB::table( 'notifications' ) . " WHERE user_id=%d{$where} ORDER BY created_at DESC,id DESC LIMIT %d", $user_id, max( 1, min( 100, $limit ) ) ) );
    }

    public static function handle_member_action(): void {
        if ( ! is_user_logged_in() || empty( $_GET['bsxmh_notification_action'] ) || empty( $_GET['notification_id'] ) ) return;
        $action = sanitize_key( wp_unslash( $_GET['bsxmh_notification_action'] ) );
        $id = absint( $_GET['notification_id'] );
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'bsxmh_notification_' . $action . '_' . $id ) ) return;
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . BSXMH_DB::table( 'notifications' ) . " WHERE id=%d AND user_id=%d", $id, get_current_user_id() ) );
        if ( ! $row ) return;
        if ( 'read' === $action ) $wpdb->update( BSXMH_DB::table( 'notifications' ), array( 'is_read'=>1, 'read_at'=>current_time('mysql') ), array( 'id'=>$id ), array('%d','%s'), array('%d') );
        if ( 'unread' === $action ) $wpdb->update( BSXMH_DB::table( 'notifications' ), array( 'is_read'=>0, 'read_at'=>null ), array( 'id'=>$id ), array('%d','%s'), array('%d') );
        if ( 'archive' === $action ) $wpdb->update( BSXMH_DB::table( 'notifications' ), array( 'is_archived'=>1 ), array( 'id'=>$id ), array('%d'), array('%d') );
        if ( 'delete' === $action ) $wpdb->delete( BSXMH_DB::table( 'notifications' ), array( 'id'=>$id, 'user_id'=>get_current_user_id() ), array('%d','%d') );
        wp_safe_redirect( BSXMH_Portal::page_url( 'notifications_page_id', '/member-notifications/' ) ); exit;
    }

    private static function action_link( int $id, string $action ): string {
        return wp_nonce_url( add_query_arg( array( 'bsxmh_notification_action'=>$action, 'notification_id'=>$id ), BSXMH_Portal::page_url( 'notifications_page_id', '/member-notifications/' ) ), 'bsxmh_notification_' . $action . '_' . $id );
    }

    public static function shortcode(): string {
        wp_enqueue_style( 'bsxmh-public' );
        if ( ! is_user_logged_in() ) return '<div class="bsxmh-notice">' . esc_html__( 'Please log in to view notifications.', 'bsx-memberhub' ) . '</div>';
        $filter = sanitize_key( wp_unslash( $_GET['notification_filter'] ?? 'all' ) );
        $search = sanitize_text_field( wp_unslash( $_GET['notification_search'] ?? '' ) );
        $rows = self::recent( get_current_user_id(), 100, 'archived' === $filter );
        $rows = array_values( array_filter( $rows, static function( $row ) use ( $filter, $search ) {
            if ( 'unread' === $filter && ! empty( $row->is_read ) ) return false;
            if ( 'read' === $filter && empty( $row->is_read ) ) return false;
            if ( 'archived' === $filter && empty( $row->is_archived ) ) return false;
            if ( 'all' === $filter && ! empty( $row->is_archived ) ) return false;
            if ( $search && false === stripos( $row->title . ' ' . wp_strip_all_tags( $row->message ), $search ) ) return false;
            return true;
        } ) );
        ob_start(); ?>
        <div class="bsxmh-notification-center">
            <div class="bsxmh-page-heading"><div><p class="bsxmh-eyebrow"><?php esc_html_e( 'Member Inbox', 'bsx-memberhub' ); ?></p><h2><?php esc_html_e( 'Notifications', 'bsx-memberhub' ); ?></h2></div><span class="bsxmh-unread-summary"><?php printf( esc_html__( '%d unread', 'bsx-memberhub' ), self::unread_count() ); ?></span></div>
            <form class="bsxmh-notification-filters" method="get">
                <input type="search" name="notification_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search notifications', 'bsx-memberhub' ); ?>">
                <select name="notification_filter"><option value="all" <?php selected($filter,'all'); ?>><?php esc_html_e('All','bsx-memberhub'); ?></option><option value="unread" <?php selected($filter,'unread'); ?>><?php esc_html_e('Unread','bsx-memberhub'); ?></option><option value="read" <?php selected($filter,'read'); ?>><?php esc_html_e('Read','bsx-memberhub'); ?></option><option value="archived" <?php selected($filter,'archived'); ?>><?php esc_html_e('Archived','bsx-memberhub'); ?></option></select>
                <button type="submit"><?php esc_html_e('Filter','bsx-memberhub'); ?></button>
            </form>
            <?php if ( ! $rows ) : ?><div class="bsxmh-empty-state"><strong><?php esc_html_e( 'You are all caught up', 'bsx-memberhub' ); ?></strong><p><?php esc_html_e( 'New notices, payment confirmations and membership updates will appear here.', 'bsx-memberhub' ); ?></p></div><?php endif; ?>
            <div class="bsxmh-notification-list">
            <?php foreach ( $rows as $row ) : ?>
                <article class="bsxmh-notification-item <?php echo empty($row->is_read)?'is-unread':'is-read'; ?>">
                    <div class="bsxmh-notification-dot" aria-hidden="true"></div>
                    <div class="bsxmh-notification-body"><div class="bsxmh-notification-meta"><span><?php echo esc_html( ucwords( str_replace('_',' ', $row->notification_type ) ) ); ?></span><time><?php echo esc_html( human_time_diff( strtotime($row->created_at), current_time('timestamp') ) . ' ' . __( 'ago', 'bsx-memberhub' ) ); ?></time></div><h3><?php echo esc_html( $row->title ); ?></h3><div><?php echo wp_kses_post( wpautop( $row->message ) ); ?></div><?php if($row->action_url): ?><p><a class="bsxmh-action-secondary" href="<?php echo esc_url($row->action_url); ?>"><?php esc_html_e('Open', 'bsx-memberhub'); ?></a></p><?php endif; ?></div>
                    <div class="bsxmh-notification-actions"><?php if(empty($row->is_read)): ?><a href="<?php echo esc_url(self::action_link((int)$row->id,'read')); ?>"><?php esc_html_e('Mark read','bsx-memberhub'); ?></a><?php else: ?><a href="<?php echo esc_url(self::action_link((int)$row->id,'unread')); ?>"><?php esc_html_e('Mark unread','bsx-memberhub'); ?></a><?php endif; ?><a href="<?php echo esc_url(self::action_link((int)$row->id,'archive')); ?>"><?php esc_html_e('Archive','bsx-memberhub'); ?></a><a class="bsxmh-danger-link" onclick="return confirm('<?php echo esc_js( __( 'Delete this notification?', 'bsx-memberhub' ) ); ?>')" href="<?php echo esc_url(self::action_link((int)$row->id,'delete')); ?>"><?php esc_html_e('Delete','bsx-memberhub'); ?></a></div>
                </article>
            <?php endforeach; ?>
            </div>
        </div><?php
        return BSXMH_Portal::wrap_member_page( (string) ob_get_clean(), 'notifications' );
    }

    public static function send_admin_notification(): void {
        if ( ! current_user_can( 'bsxmh_manage_members' ) ) wp_die( esc_html__( 'Permission denied.', 'bsx-memberhub' ) );
        check_admin_referer( 'bsxmh_send_notification' );
        $title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
        $message = wp_kses_post( wp_unslash( $_POST['message'] ?? '' ) );
        $audience = sanitize_key( wp_unslash( $_POST['audience'] ?? 'all' ) );
        $action_url = esc_url_raw( wp_unslash( $_POST['action_url'] ?? '' ) );
        $user_ids = array();
        if ( 'selected' === $audience ) $user_ids = array_filter( array_map( 'absint', (array) ($_POST['user_ids'] ?? array()) ) );
        else {
            global $wpdb;
            $status = 'active' === $audience ? " AND status='active'" : '';
            $user_ids = array_map( 'intval', (array) $wpdb->get_col( "SELECT user_id FROM " . BSXMH_DB::table('members') . " WHERE deleted_at IS NULL{$status}" ) );
        }
        $sent=0; foreach(array_unique($user_ids) as $uid){ if(self::create($uid,$title,$message,'announcement',$action_url)) $sent++; }
        wp_safe_redirect( add_query_arg( array('page'=>'bsxmh-notifications','sent'=>$sent), admin_url('admin.php') ) ); exit;
    }

    public static function render_admin(): void {
        if ( ! current_user_can( 'bsxmh_manage_members' ) ) return;
        global $wpdb;
        $members = (array) $wpdb->get_results(
            "SELECT m.id, m.user_id, m.member_number, m.status, u.display_name
             FROM " . BSXMH_DB::table( 'members' ) . " m
             LEFT JOIN {$wpdb->users} u ON u.ID = m.user_id
             WHERE m.status <> 'deleted' AND m.deleted_at IS NULL
             ORDER BY u.display_name ASC, m.member_number ASC
             LIMIT 500"
        );
        $recent = $wpdb->get_results( "SELECT n.*,u.display_name FROM " . BSXMH_DB::table('notifications') . " n LEFT JOIN {$wpdb->users} u ON u.ID=n.user_id ORDER BY n.created_at DESC,n.id DESC LIMIT 50" );
        echo '<div class="wrap bsxmh-wrap"><h1>'.esc_html__('Notifications & Member Inbox','bsx-memberhub').'</h1>';
        if(isset($_GET['sent'])) echo '<div class="notice notice-success is-dismissible"><p>'.sprintf(esc_html__('%d notifications sent.','bsx-memberhub'),absint($_GET['sent'])).'</p></div>';
        echo '<div class="bsxmh-grid-2"><div class="bsxmh-panel"><h2>'.esc_html__('Send Notification','bsx-memberhub').'</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="bsxmh_send_notification">'; wp_nonce_field('bsxmh_send_notification');
        echo '<table class="form-table"><tr><th><label for="bsxmh-notification-title">'.esc_html__('Title','bsx-memberhub').'</label></th><td><input class="regular-text" id="bsxmh-notification-title" name="title" required></td></tr><tr><th><label for="bsxmh-notification-message">'.esc_html__('Message','bsx-memberhub').'</label></th><td><textarea class="large-text" rows="7" id="bsxmh-notification-message" name="message" required></textarea></td></tr><tr><th>'.esc_html__('Audience','bsx-memberhub').'</th><td><select name="audience" id="bsxmh-notification-audience"><option value="all">'.esc_html__('All members','bsx-memberhub').'</option><option value="active">'.esc_html__('Active members only','bsx-memberhub').'</option><option value="selected">'.esc_html__('Selected members','bsx-memberhub').'</option></select><p><select class="bsxmh-member-select" name="user_ids[]" multiple size="8" style="min-width:320px">'; foreach($members as $m){$u=get_userdata((int)$m->user_id); if($u) echo '<option value="'.esc_attr($m->user_id).'">'.esc_html($u->display_name.' ('.$m->member_number.')').'</option>'; } echo '</select></p></td></tr><tr><th><label>'.esc_html__('Action URL','bsx-memberhub').'</label></th><td><input class="regular-text" type="url" name="action_url"><p class="description">'.esc_html__('Optional link shown as an Open button.','bsx-memberhub').'</p></td></tr></table>'; submit_button(__('Send Notification','bsx-memberhub')); echo '</form></div>';
        echo '<div class="bsxmh-panel"><h2>'.esc_html__('How it works','bsx-memberhub').'</h2><p>'.esc_html__('Messages appear inside each member’s portal. Payment confirmations and membership status changes are also created automatically.','bsx-memberhub').'</p><p>'.esc_html__('Members can mark messages as read or unread and archive them.','bsx-memberhub').'</p></div></div>';
        echo '<div class="bsxmh-panel"><h2>'.esc_html__('Recent Deliveries','bsx-memberhub').'</h2><table class="widefat striped"><thead><tr><th>'.esc_html__('Member','bsx-memberhub').'</th><th>'.esc_html__('Title','bsx-memberhub').'</th><th>'.esc_html__('Type','bsx-memberhub').'</th><th>'.esc_html__('Status','bsx-memberhub').'</th><th>'.esc_html__('Date','bsx-memberhub').'</th></tr></thead><tbody>'; if(!$recent) echo '<tr><td colspan="5">'.esc_html__('No notifications yet.','bsx-memberhub').'</td></tr>'; foreach($recent as $r) echo '<tr><td>'.esc_html($r->display_name?:('#'.$r->user_id)).'</td><td>'.esc_html($r->title).'</td><td>'.esc_html(ucwords(str_replace('_',' ',$r->notification_type))).'</td><td>'.esc_html($r->is_archived?'Archived':($r->is_read?'Read':'Unread')).'</td><td>'.esc_html($r->created_at).'</td></tr>'; echo '</tbody></table></div></div>';
    }

    public static function status_changed( int $user_id, string $old, string $new, int $member_id ): void {
        $labels = array('active'=>__('Membership Active','bsx-memberhub'),'pending'=>__('Membership Pending','bsx-memberhub'),'inactive'=>__('Membership Inactive','bsx-memberhub'),'suspended'=>__('Membership Suspended','bsx-memberhub'));
        self::create( $user_id, $labels[$new] ?? __('Membership Updated','bsx-memberhub'), sprintf( __('Your membership status changed from %1$s to %2$s.','bsx-memberhub'), ucfirst($old), ucfirst($new) ), 'membership', BSXMH_Portal::page_url('dashboard_page_id','/member-dashboard/') );
    }

    public static function payment_completed( int $payment_id ): void {
        global $wpdb; $p=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".BSXMH_DB::table('payments')." WHERE id=%d",$payment_id)); if(!$p||!$p->user_id) return;
        $receipt=BSXMH_Receipts::get_by_payment($payment_id); $url=$receipt?BSXMH_Receipts::public_url($receipt):BSXMH_Portal::page_url('finance_page_id','/member-finance/');
        self::create((int)$p->user_id,__('Payment Received','bsx-memberhub'),sprintf(__('We received your %1$s payment of %2$s.','bsx-memberhub'),ucwords(str_replace('_',' ',$p->payment_type)),BSXMH_Payments::currency_symbol().number_format_i18n((float)$p->total_amount,2)),'payment',$url);
    }
}
