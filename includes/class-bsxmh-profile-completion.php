<?php

defined( 'ABSPATH' ) || exit;

/**
 * Calculates and renders member profile completion information.
 */
final class BSXMH_Profile_Completion {
    /**
     * Return the completion result for a member user.
     *
     * Required custom fields receive twice the weight of optional fields.
     * Developers may alter the field list through bsxmh_profile_completion_fields.
     */
    public static function calculate( int $user_id, $member = null ): array {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return array( 'percent' => 0, 'completed_weight' => 0, 'total_weight' => 0, 'items' => array(), 'missing' => array() );
        }
        $member = $member ?: BSXMH_Members::get_by_user( $user_id );
        $core = BSXMH_Form_Builder::core_settings();
        $phone = trim( (string) get_user_meta( $user_id, 'bsxmh_phone', true ) );
        if ( '' === $phone && $member ) {
            $profile = json_decode( (string) $member->profile_data, true );
            if ( is_array( $profile ) ) $phone = trim( (string) ( $profile['phone'] ?? '' ) );
        }

        $items = array(
            array( 'key' => 'full_name', 'label' => __( 'Full name', 'bsx-memberhub' ), 'complete' => '' !== trim( (string) $user->display_name ), 'weight' => 2, 'anchor' => 'bsxmh-display-name' ),
            array( 'key' => 'email', 'label' => __( 'Email address', 'bsx-memberhub' ), 'complete' => is_email( $user->user_email ), 'weight' => 2, 'anchor' => 'bsxmh-profile-email' ),
            array( 'key' => 'photo', 'label' => __( 'Profile photo', 'bsx-memberhub' ), 'complete' => $member && BSXMH_Members::profile_photo_id( $member ) > 0, 'weight' => 1, 'anchor' => 'bsxmh-profile-photo' ),
        );
        if ( ! empty( $core['phone_enabled'] ) ) {
            $items[] = array( 'key' => 'phone', 'label' => (string) ( $core['phone_label'] ?: __( 'Phone number', 'bsx-memberhub' ) ), 'complete' => '' !== $phone, 'weight' => ! empty( $core['phone_required'] ) ? 2 : 1, 'anchor' => 'bsxmh-profile-phone' );
        }

        $values = BSXMH_Form_Builder::values( $user_id );
        foreach ( BSXMH_Form_Builder::fields( true ) as $field ) {
            if ( empty( $field->member_editable ) || in_array( $field->visibility, array( 'admin', 'hidden' ), true ) || in_array( $field->field_type, array( 'heading', 'html' ), true ) ) continue;
            $value = $values[ $field->field_key ] ?? '';
            $complete = is_array( $value ) ? ! empty( array_filter( $value, static fn( $v ) => '' !== trim( (string) $v ) ) ) : '' !== trim( (string) $value );
            $items[] = array(
                'key' => 'custom_' . sanitize_key( $field->field_key ),
                'label' => (string) $field->label,
                'complete' => $complete,
                'weight' => ! empty( $field->is_required ) ? 2 : 1,
                'anchor' => 'bsxmh-field-' . sanitize_html_class( $field->field_key ),
            );
        }

        $items = apply_filters( 'bsxmh_profile_completion_fields', $items, $user_id, $member );
        $total = 0; $completed = 0; $missing = array();
        foreach ( $items as &$item ) {
            $item['weight'] = max( 1, absint( $item['weight'] ?? 1 ) );
            $item['complete'] = ! empty( $item['complete'] );
            $total += $item['weight'];
            if ( $item['complete'] ) $completed += $item['weight']; else $missing[] = $item;
        }
        unset( $item );
        $percent = $total ? (int) round( ( $completed / $total ) * 100 ) : 100;
        return array( 'percent' => min( 100, max( 0, $percent ) ), 'completed_weight' => $completed, 'total_weight' => $total, 'items' => $items, 'missing' => $missing );
    }

    public static function render_card( int $user_id, $member = null, bool $show_checklist = false ): string {
        $result = self::calculate( $user_id, $member );
        $percent = (int) $result['percent'];
        $profile_url = BSXMH_Portal::page_url( 'profile_page_id', '/member-profile/' );
        ob_start(); ?>
        <section class="bsxmh-profile-completion<?php echo $show_checklist ? ' is-detailed' : ''; ?>" aria-label="<?php esc_attr_e( 'Profile completion', 'bsx-memberhub' ); ?>">
            <div class="bsxmh-completion-copy">
                <p class="bsxmh-eyebrow"><?php esc_html_e( 'Profile completion', 'bsx-memberhub' ); ?></p>
                <h3><?php echo esc_html( sprintf( __( '%d%% complete', 'bsx-memberhub' ), $percent ) ); ?></h3>
                <?php if ( 100 === $percent ) : ?><p><?php esc_html_e( 'Your profile is complete. Thank you for keeping your information up to date.', 'bsx-memberhub' ); ?></p>
                <?php else : ?><p><?php echo esc_html( sprintf( _n( '%d item still needs attention.', '%d items still need attention.', count( $result['missing'] ), 'bsx-memberhub' ), count( $result['missing'] ) ) ); ?></p><?php endif; ?>
            </div>
            <div class="bsxmh-completion-action">
                <div class="bsxmh-progress-meta"><span><?php esc_html_e( 'Progress', 'bsx-memberhub' ); ?></span><strong><?php echo esc_html( $percent . '%' ); ?></strong></div>
                <div class="bsxmh-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( $percent ); ?>"><span style="width:<?php echo esc_attr( $percent ); ?>%"></span></div>
                <?php if ( ! $show_checklist && 100 !== $percent ) : ?><a class="bsxmh-action-secondary" href="<?php echo esc_url( $profile_url ); ?>"><?php esc_html_e( 'Complete Profile', 'bsx-memberhub' ); ?></a><?php endif; ?>
            </div>
            <?php if ( $show_checklist ) : ?>
                <div class="bsxmh-completion-checklist">
                    <?php foreach ( $result['items'] as $item ) : ?>
                        <div class="bsxmh-completion-item <?php echo $item['complete'] ? 'is-complete' : 'is-missing'; ?>">
                            <span aria-hidden="true"><?php echo $item['complete'] ? '✓' : '○'; ?></span>
                            <strong><?php echo esc_html( $item['label'] ); ?></strong>
                            <small><?php echo $item['complete'] ? esc_html__( 'Complete', 'bsx-memberhub' ) : esc_html__( 'Missing', 'bsx-memberhub' ); ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php return (string) ob_get_clean();
    }
}
