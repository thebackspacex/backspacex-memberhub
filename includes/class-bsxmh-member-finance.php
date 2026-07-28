<?php

defined( 'ABSPATH' ) || exit;

final class BSXMH_Member_Finance {
    public static function register(): void {
        add_shortcode( 'bsxmh_member_finance', array( __CLASS__, 'shortcode' ) );
    }

    public static function shortcode(): string {
        wp_enqueue_style( 'bsxmh-public' );
        if ( ! is_user_logged_in() ) {
            return '<div class="bsxmh-notice">' . esc_html__( 'Please log in as a member.', 'bsx-memberhub' ) . '</div>';
        }

        $user   = wp_get_current_user();
        $member = BSXMH_Members::get_by_user( (int) $user->ID );
        if ( ! $member ) {
            return '<div class="bsxmh-notice bsxmh-error">' . esc_html__( 'Member record not found.', 'bsx-memberhub' ) . '</div>';
        }

        $tab          = sanitize_key( wp_unslash( $_GET['finance_tab'] ?? 'overview' ) );
        $allowed_tabs = array( 'overview', 'transactions', 'fees', 'contributions', 'events', 'receipts' );
        if ( ! in_array( $tab, $allowed_tabs, true ) ) {
            $tab = 'overview';
        }

        $payments  = self::payments( (int) $user->ID );
        $statement = BSXMH_Payments::statement( $member );
        $summary   = self::summary( $payments, $statement );

        ob_start();
        ?>
        <div class="bsxmh-member-finance">
            <header class="bsxmh-finance-hero">
                <div>
                    <p class="bsxmh-eyebrow"><?php esc_html_e( 'Member Finance Center', 'bsx-memberhub' ); ?></p>
                    <h2><?php esc_html_e( 'My Finance', 'bsx-memberhub' ); ?></h2>
                    <p><?php esc_html_e( 'View your fees, contributions, event payments, transactions and receipts in one place.', 'bsx-memberhub' ); ?></p>
                </div>
                <a class="bsxmh-action-primary" href="<?php echo esc_url( BSXMH_Portal::page_url( 'payment_page_id', '/member-payment/' ) ); ?>"><?php esc_html_e( 'Pay Fees', 'bsx-memberhub' ); ?></a>
            </header>

            <?php echo self::summary_cards( $summary ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php echo self::tabs( $tab ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

            <?php
            if ( 'overview' === $tab ) {
                echo self::overview( $payments, $statement ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            } elseif ( 'transactions' === $tab ) {
                echo self::transactions( $payments ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            } elseif ( 'fees' === $tab ) {
                echo self::fees( $statement ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            } elseif ( 'contributions' === $tab ) {
                echo self::typed_payments( $payments, 'extra_contribution', __( 'My Contributions', 'bsx-memberhub' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            } elseif ( 'events' === $tab ) {
                echo self::typed_payments( $payments, 'event_donation', __( 'Event Payments', 'bsx-memberhub' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            } else {
                echo self::receipts( $payments ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
            ?>
        </div>
        <?php
        return BSXMH_Portal::wrap_member_page( (string) ob_get_clean(), 'finance' );
    }

    private static function payments( int $user_id ): array {
        global $wpdb;
        $table = BSXMH_DB::table( 'payments' );
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id=%d ORDER BY COALESCE(payment_date,created_at) DESC, id DESC", $user_id ) ) ?: array();
    }

    private static function summary( array $payments, array $statement ): array {
        $paid = 0.0; $contributions = 0.0; $events = 0.0; $last = '';
        foreach ( $payments as $payment ) {
            if ( 'paid' !== $payment->status ) continue;
            if ( ! $last && ! empty( $payment->payment_date ) ) $last = (string) $payment->payment_date;
            if ( 'membership' === $payment->payment_type ) $paid += (float) $payment->total_amount;
            if ( 'extra_contribution' === $payment->payment_type ) $contributions += (float) $payment->total_amount;
            if ( 'event_donation' === $payment->payment_type ) $events += (float) $payment->total_amount;
        }
        return array(
            'membership' => $paid,
            'due' => (float) $statement['total_due'],
            'contributions' => $contributions,
            'events' => $events,
            'transactions' => count( $payments ),
            'last' => $last,
        );
    }

    private static function summary_cards( array $summary ): string {
        $sym = BSXMH_Payments::currency_symbol();
        $last = $summary['last'] ? date_i18n( get_option( 'date_format' ), strtotime( $summary['last'] ) ) : __( 'No payment yet', 'bsx-memberhub' );
        ob_start(); ?>
        <section class="bsxmh-finance-summary-grid">
            <article><span><?php esc_html_e( 'Membership Paid', 'bsx-memberhub' ); ?></span><strong><?php echo esc_html( $sym . number_format_i18n( $summary['membership'], 2 ) ); ?></strong></article>
            <article><span><?php esc_html_e( 'Current Due', 'bsx-memberhub' ); ?></span><strong><?php echo esc_html( $sym . number_format_i18n( $summary['due'], 2 ) ); ?></strong></article>
            <article><span><?php esc_html_e( 'Contributions', 'bsx-memberhub' ); ?></span><strong><?php echo esc_html( $sym . number_format_i18n( $summary['contributions'], 2 ) ); ?></strong></article>
            <article><span><?php esc_html_e( 'Event Payments', 'bsx-memberhub' ); ?></span><strong><?php echo esc_html( $sym . number_format_i18n( $summary['events'], 2 ) ); ?></strong></article>
            <article><span><?php esc_html_e( 'Transactions', 'bsx-memberhub' ); ?></span><strong><?php echo esc_html( number_format_i18n( $summary['transactions'] ) ); ?></strong></article>
            <article><span><?php esc_html_e( 'Last Payment', 'bsx-memberhub' ); ?></span><strong class="bsxmh-finance-date"><?php echo esc_html( $last ); ?></strong></article>
        </section>
        <?php return (string) ob_get_clean();
    }

    private static function tabs( string $active ): string {
        $base = BSXMH_Portal::page_url( 'finance_page_id', '/member-finance/' );
        $tabs = array(
            'overview' => __( 'Overview', 'bsx-memberhub' ), 'transactions' => __( 'Transactions', 'bsx-memberhub' ),
            'fees' => __( 'Fee History', 'bsx-memberhub' ), 'contributions' => __( 'Contributions', 'bsx-memberhub' ),
            'events' => __( 'Events', 'bsx-memberhub' ), 'receipts' => __( 'Receipts', 'bsx-memberhub' ),
        );
        $html = '<nav class="bsxmh-finance-tabs" aria-label="' . esc_attr__( 'Finance sections', 'bsx-memberhub' ) . '">';
        foreach ( $tabs as $key => $label ) {
            $html .= '<a class="' . ( $active === $key ? 'is-active' : '' ) . '" href="' . esc_url( add_query_arg( 'finance_tab', $key, $base ) ) . '">' . esc_html( $label ) . '</a>';
        }
        return $html . '</nav>';
    }

    private static function overview( array $payments, array $statement ): string {
        $recent = array_slice( $payments, 0, 5 );
        ob_start(); ?>
        <div class="bsxmh-finance-columns">
            <section class="bsxmh-home-panel">
                <div class="bsxmh-panel-heading"><h3><?php esc_html_e( 'Recent Transactions', 'bsx-memberhub' ); ?></h3><a href="<?php echo esc_url( add_query_arg( 'finance_tab', 'transactions', BSXMH_Portal::page_url( 'finance_page_id', '/member-finance/' ) ) ); ?>"><?php esc_html_e( 'View all', 'bsx-memberhub' ); ?></a></div>
                <?php echo self::payment_cards( $recent, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </section>
            <section class="bsxmh-home-panel">
                <div class="bsxmh-panel-heading"><h3><?php esc_html_e( 'Membership Fee Status', 'bsx-memberhub' ); ?></h3><a href="<?php echo esc_url( add_query_arg( 'finance_tab', 'fees', BSXMH_Portal::page_url( 'finance_page_id', '/member-finance/' ) ) ); ?>"><?php esc_html_e( 'Full history', 'bsx-memberhub' ); ?></a></div>
                <div class="bsxmh-fee-overview"><strong><?php echo esc_html( sprintf( _n( '%s month due', '%s months due', count( $statement['due'] ), 'bsx-memberhub' ), number_format_i18n( count( $statement['due'] ) ) ) ); ?></strong><p><?php echo esc_html( BSXMH_Payments::currency_symbol() . number_format_i18n( (float) $statement['total_due'], 2 ) ); ?></p></div>
                <?php if ( $statement['due'] ) : ?><a class="bsxmh-action-primary" href="<?php echo esc_url( add_query_arg( 'bsxmh_pay_all_due', '1', BSXMH_Portal::page_url( 'payment_page_id', '/member-payment/' ) ) ); ?>"><?php esc_html_e( 'Pay All Due', 'bsx-memberhub' ); ?></a><?php else : ?><div class="bsxmh-notice bsxmh-success"><?php esc_html_e( 'Your membership fees are up to date.', 'bsx-memberhub' ); ?></div><?php endif; ?>
            </section>
        </div>
        <?php return (string) ob_get_clean();
    }

    private static function transactions( array $payments ): string {
        $type = sanitize_key( wp_unslash( $_GET['finance_type'] ?? '' ) );
        $status = sanitize_key( wp_unslash( $_GET['finance_status'] ?? '' ) );
        $year = absint( $_GET['finance_year'] ?? 0 );
        $search = sanitize_text_field( wp_unslash( $_GET['finance_search'] ?? '' ) );
        $filtered = array_filter( $payments, static function( $p ) use ( $type, $status, $year, $search ) {
            if ( $type && $p->payment_type !== $type ) return false;
            if ( $status && $p->status !== $status ) return false;
            $date = (string) ( $p->payment_date ?: $p->created_at );
            if ( $year && (int) substr( $date, 0, 4 ) !== $year ) return false;
            if ( $search && false === stripos( (string) $p->transaction_id . ' ' . (string) $p->gateway_transaction_id, $search ) ) return false;
            return true;
        } );
        ob_start(); ?>
        <section class="bsxmh-home-panel">
            <h3><?php esc_html_e( 'Transaction History', 'bsx-memberhub' ); ?></h3>
            <form class="bsxmh-finance-filter" method="get">
                <input type="hidden" name="finance_tab" value="transactions">
                <select name="finance_type"><option value=""><?php esc_html_e( 'All types', 'bsx-memberhub' ); ?></option><?php foreach ( array( 'membership'=>'Membership Fee','extra_contribution'=>'Contribution','event_donation'=>'Event Payment' ) as $k=>$v ) echo '<option value="'.esc_attr($k).'" '.selected($type,$k,false).'>'.esc_html__($v,'bsx-memberhub').'</option>'; ?></select>
                <select name="finance_status"><option value=""><?php esc_html_e( 'All statuses', 'bsx-memberhub' ); ?></option><?php foreach ( array( 'paid'=>'Paid','initiated'=>'Initiated','pending'=>'Pending','failed'=>'Failed','cancelled'=>'Cancelled' ) as $k=>$v ) echo '<option value="'.esc_attr($k).'" '.selected($status,$k,false).'>'.esc_html__($v,'bsx-memberhub').'</option>'; ?></select>
                <input type="number" name="finance_year" min="2000" max="2100" placeholder="<?php esc_attr_e( 'Year', 'bsx-memberhub' ); ?>" value="<?php echo esc_attr( $year ?: '' ); ?>">
                <input type="search" name="finance_search" placeholder="<?php esc_attr_e( 'Transaction/reference', 'bsx-memberhub' ); ?>" value="<?php echo esc_attr( $search ); ?>">
                <button type="submit"><?php esc_html_e( 'Filter', 'bsx-memberhub' ); ?></button>
            </form>
            <?php echo self::payment_cards( array_values( $filtered ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </section>
        <?php return (string) ob_get_clean();
    }

    private static function fees( array $statement ): string {
        $periods = array_reverse( $statement['eligible'] );
        $paid = wp_list_pluck( $statement['paid'], 'key' );
        ob_start(); ?>
        <section class="bsxmh-home-panel"><div class="bsxmh-panel-heading"><h3><?php esc_html_e( 'Membership Fee History', 'bsx-memberhub' ); ?></h3><a class="bsxmh-action-primary" href="<?php echo esc_url( BSXMH_Portal::page_url( 'payment_page_id', '/member-payment/' ) ); ?>"><?php esc_html_e( 'Pay Fees', 'bsx-memberhub' ); ?></a></div>
        <div class="bsxmh-fee-history">
        <?php if ( ! $periods ) : ?><div class="bsxmh-empty-state"><?php esc_html_e( 'No fee periods are available yet.', 'bsx-memberhub' ); ?></div><?php else : foreach ( $periods as $period ) : $is_paid = in_array( $period['key'], $paid, true ); ?>
            <article><div><strong><?php echo esc_html( BSXMH_Payments::month_label( (int) $period['year'], (int) $period['month'] ) ); ?></strong><small><?php echo esc_html( BSXMH_Payments::currency_symbol() . number_format_i18n( (float) $statement['monthly_fee'], 2 ) ); ?></small></div><span class="bsxmh-status-pill is-<?php echo $is_paid ? 'paid' : 'due'; ?>"><?php echo esc_html( $is_paid ? __( 'Paid', 'bsx-memberhub' ) : __( 'Due', 'bsx-memberhub' ) ); ?></span><?php if ( ! $is_paid ) : ?><a href="<?php echo esc_url( add_query_arg( 'bsxmh_pay_month', $period['key'], BSXMH_Portal::page_url( 'payment_page_id', '/member-payment/' ) ) ); ?>"><?php esc_html_e( 'Pay now', 'bsx-memberhub' ); ?></a><?php endif; ?></article>
        <?php endforeach; endif; ?>
        </div></section>
        <?php return (string) ob_get_clean();
    }

    private static function typed_payments( array $payments, string $type, string $title ): string {
        $rows = array_values( array_filter( $payments, static fn( $p ) => $p->payment_type === $type ) );
        ob_start(); ?><section class="bsxmh-home-panel"><h3><?php echo esc_html( $title ); ?></h3><?php echo self::payment_cards( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></section><?php return (string) ob_get_clean();
    }

    private static function receipts( array $payments ): string {
        $paid = array_values( array_filter( $payments, static fn( $p ) => 'paid' === $p->status ) );
        ob_start(); ?><section class="bsxmh-home-panel"><h3><?php esc_html_e( 'My Receipts', 'bsx-memberhub' ); ?></h3><div class="bsxmh-receipt-grid">
        <?php $found=false; foreach ( $paid as $payment ) : $receipt=BSXMH_Receipts::get_by_payment((int)$payment->id); if(!$receipt) continue; $found=true; ?>
            <article><div><span><?php echo esc_html( $receipt->receipt_number ); ?></span><strong><?php echo esc_html( self::type_label( $payment->payment_type ) ); ?></strong><small><?php echo esc_html( date_i18n( get_option('date_format'), strtotime((string)$payment->payment_date) ) ); ?></small></div><div><strong><?php echo esc_html( BSXMH_Payments::currency_symbol().number_format_i18n((float)$payment->total_amount,2) ); ?></strong><a target="_blank" rel="noopener" href="<?php echo esc_url(BSXMH_Receipts::public_url($receipt)); ?>"><?php esc_html_e('View / Print','bsx-memberhub'); ?></a></div></article>
        <?php endforeach; if(!$found) : ?><div class="bsxmh-empty-state"><?php esc_html_e('No receipts are available yet.','bsx-memberhub'); ?></div><?php endif; ?>
        </div></section><?php return (string) ob_get_clean();
    }

    private static function payment_cards( array $payments, bool $compact = false ): string {
        if ( ! $payments ) return '<div class="bsxmh-empty-state"><strong>' . esc_html__( 'No transactions found', 'bsx-memberhub' ) . '</strong></div>';
        $html = '<div class="bsxmh-transaction-list' . ( $compact ? ' is-compact' : '' ) . '">';
        foreach ( $payments as $payment ) {
            $items = BSXMH_Payments::items( (int) $payment->id );
            $desc = implode( ', ', array_filter( array_map( static fn( $i ) => (string) $i->description, $items ) ) );
            $receipt = 'paid' === $payment->status ? BSXMH_Receipts::get_by_payment( (int) $payment->id ) : null;
            $date = (string) ( $payment->payment_date ?: $payment->created_at );
            $html .= '<article><div class="bsxmh-transaction-main"><strong>' . esc_html( self::type_label( $payment->payment_type ) ) . '</strong><small>' . esc_html( $desc ?: $payment->transaction_id ) . '</small><time>' . esc_html( date_i18n( get_option( 'date_format' ), strtotime( $date ) ) ) . '</time></div><div class="bsxmh-transaction-side"><strong>' . esc_html( BSXMH_Payments::currency_symbol() . number_format_i18n( (float) $payment->total_amount, 2 ) ) . '</strong><span class="bsxmh-status-pill is-' . esc_attr( sanitize_html_class( $payment->status ) ) . '">' . esc_html( ucfirst( $payment->status ) ) . '</span>';
            if ( $receipt ) $html .= '<a target="_blank" rel="noopener" href="' . esc_url( BSXMH_Receipts::public_url( $receipt ) ) . '">' . esc_html__( 'Receipt', 'bsx-memberhub' ) . '</a>';
            $html .= '</div></article>';
        }
        return $html . '</div>';
    }

    private static function type_label( string $type ): string {
        $labels = array( 'membership'=>__( 'Membership Fee', 'bsx-memberhub' ), 'extra_contribution'=>__( 'Contribution', 'bsx-memberhub' ), 'event_donation'=>__( 'Event Payment', 'bsx-memberhub' ) );
        return $labels[$type] ?? ucwords( str_replace( '_', ' ', $type ) );
    }
}
