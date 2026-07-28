<?php

defined( 'ABSPATH' ) || exit;

final class BSXMH_Portal {
    public static function register(): void {
        add_action( 'init', array( __CLASS__, 'register_announcement_type' ) );
        add_action( 'init', array( __CLASS__, 'ensure_pages' ), 20 );
        add_action( 'admin_init', array( __CLASS__, 'redirect_members_from_admin' ) );
        add_action( 'template_redirect', array( __CLASS__, 'process_frontend_actions' ), 1 );
        add_filter( 'show_admin_bar', array( __CLASS__, 'hide_member_admin_bar' ) );
        add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 10, 3 );
        add_action( 'wp_logout', array( __CLASS__, 'logout_redirect' ) );
        add_shortcode( 'bsxmh_login', array( __CLASS__, 'login_form' ) );
        add_shortcode( 'bsxmh_profile', array( __CLASS__, 'profile_form' ) );
    }


    public static function process_frontend_actions(): void {
        if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
            return;
        }

        if ( isset( $_POST['bsxmh_login_submit'] ) ) {
            self::process_login();
        }

        if ( isset( $_POST['bsxmh_profile_submit'] ) ) {
            self::process_profile_update();
        }
    }

    private static function process_login(): void {
        $login_url = self::page_url( 'login_page_id', '/member-login/' );
        $nonce = sanitize_text_field( wp_unslash( $_POST['bsxmh_login_nonce'] ?? '' ) );

        if ( ! wp_verify_nonce( $nonce, 'bsxmh_front_login' ) ) {
            wp_safe_redirect( add_query_arg( 'bsxmh_login_error', 'security', $login_url ) );
            exit;
        }

        $creds = array(
            'user_login'    => sanitize_text_field( wp_unslash( $_POST['log'] ?? '' ) ),
            'user_password' => (string) wp_unslash( $_POST['pwd'] ?? '' ),
            'remember'      => ! empty( $_POST['rememberme'] ),
        );
        $user = wp_signon( $creds, is_ssl() );

        if ( is_wp_error( $user ) ) {
            wp_safe_redirect( add_query_arg( 'bsxmh_login_error', 'invalid', $login_url ) );
            exit;
        }

        if ( ! self::is_member_user( $user ) ) {
            wp_logout();
            wp_safe_redirect( add_query_arg( 'bsxmh_login_error', 'not_member', $login_url ) );
            exit;
        }

        wp_safe_redirect( self::page_url( 'dashboard_page_id', '/member-dashboard/' ) );
        exit;
    }

    private static function process_profile_update(): void {
        if ( ! is_user_logged_in() || ! self::is_member_user() ) {
            return;
        }

        $profile_url = self::page_url( 'profile_page_id', '/member-profile/' );
        $nonce = sanitize_text_field( wp_unslash( $_POST['bsxmh_profile_nonce'] ?? '' ) );
        if ( ! wp_verify_nonce( $nonce, 'bsxmh_front_profile' ) ) {
            wp_safe_redirect( add_query_arg( 'bsxmh_profile_status', 'security', $profile_url ) );
            exit;
        }

        $user = wp_get_current_user();
        $member = BSXMH_Members::get_by_user( (int) $user->ID );
        $display_name = sanitize_text_field( wp_unslash( $_POST['display_name'] ?? '' ) );
        $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        if ( ! $display_name || ! is_email( $email ) ) {
            wp_safe_redirect( add_query_arg( 'bsxmh_profile_status', 'invalid', $profile_url ) );
            exit;
        }

        $result = wp_update_user( array( 'ID' => $user->ID, 'display_name' => $display_name, 'user_email' => $email ) );
        if ( is_wp_error( $result ) ) {
            wp_safe_redirect( add_query_arg( 'bsxmh_profile_status', 'failed', $profile_url ) );
            exit;
        }

        $phone = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );

        // Phone is stored inside profile_data in current MemberHub schemas.
        if ( $member ) {
            global $wpdb;
            $profile_data = json_decode( (string) ( $member->profile_data ?? '' ), true );
            $profile_data = is_array( $profile_data ) ? $profile_data : array();
            $profile_data['phone'] = $phone;
            $wpdb->update(
                BSXMH_DB::table( 'members' ),
                array( 'profile_data' => wp_json_encode( $profile_data ) ),
                array( 'user_id' => $user->ID ),
                array( '%s' ),
                array( '%d' )
            );
        }

        // Keep a user-meta fallback for compatibility with older versions.
        update_user_meta( $user->ID, 'bsxmh_phone', $phone );
        $directory_settings = get_option( 'bsxmh_settings', array() );
        if ( ! isset( $directory_settings['directory_allow_opt_out'] ) || ! empty( $directory_settings['directory_allow_opt_out'] ) ) {
            update_user_meta( $user->ID, 'bsxmh_directory_hidden', empty( $_POST['directory_hidden'] ) ? '0' : '1' );
        }

        $settings = get_option( 'bsxmh_settings', array() );
        $allow_photo = ! isset( $settings['allow_member_profile_photo'] ) || ! empty( $settings['allow_member_profile_photo'] );
        if ( $allow_photo && $member ) {
            if ( ! empty( $_POST['remove_profile_photo'] ) ) {
                $photo_result = BSXMH_Members::set_profile_photo( (int) $member->id, 0, true );
                if ( is_wp_error( $photo_result ) ) {
                    wp_safe_redirect( add_query_arg( 'bsxmh_profile_status', 'photo_failed', $profile_url ) ); exit;
                }
            } elseif ( ! empty( $_FILES['profile_photo']['name'] ) ) {
                $max_mb = max( 1, min( 10, absint( $settings['profile_photo_max_mb'] ?? 2 ) ) );
                if ( ! empty( $_FILES['profile_photo']['size'] ) && (int) $_FILES['profile_photo']['size'] > $max_mb * MB_IN_BYTES ) {
                    wp_safe_redirect( add_query_arg( 'bsxmh_profile_status', 'photo_too_large', $profile_url ) ); exit;
                }
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';
                $allowed = array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' );
                $attachment_id = media_handle_upload( 'profile_photo', 0, array(), array( 'test_form' => false, 'mimes' => $allowed ) );
                if ( is_wp_error( $attachment_id ) ) {
                    set_transient( 'bsxmh_profile_photo_error_' . (int) $user->ID, $attachment_id->get_error_message(), 5 * MINUTE_IN_SECONDS );
                    wp_safe_redirect( add_query_arg( 'bsxmh_profile_status', 'photo_failed', $profile_url ) ); exit;
                }
                $mime = (string) get_post_mime_type( $attachment_id );
                if ( ! in_array( $mime, array_values( $allowed ), true ) ) {
                    wp_delete_attachment( $attachment_id, true );
                    wp_safe_redirect( add_query_arg( 'bsxmh_profile_status', 'photo_type', $profile_url ) ); exit;
                }
                $photo_result = BSXMH_Members::set_profile_photo( (int) $member->id, (int) $attachment_id, false );
                if ( is_wp_error( $photo_result ) ) {
                    wp_delete_attachment( $attachment_id, true );
                    wp_safe_redirect( add_query_arg( 'bsxmh_profile_status', 'photo_failed', $profile_url ) ); exit;
                }
            }
        }

        // Validate and persist all enabled member-editable custom fields.
        $custom_result = BSXMH_Form_Builder::validate_and_save(
            (int) $user->ID,
            $_POST,
            'profile'
        );

        if ( is_wp_error( $custom_result ) ) {
            set_transient(
                'bsxmh_profile_errors_' . (int) $user->ID,
                $custom_result->get_error_messages(),
                5 * MINUTE_IN_SECONDS
            );
            wp_safe_redirect( add_query_arg( 'bsxmh_profile_status', 'custom_failed', $profile_url ) );
            exit;
        }

        if ( ! empty( $_POST['new_password'] ) ) {
            wp_set_password( (string) wp_unslash( $_POST['new_password'] ), $user->ID );
            wp_set_auth_cookie( $user->ID, true, is_ssl() );
        }

        wp_safe_redirect( add_query_arg( 'bsxmh_profile_status', 'updated', $profile_url ) );
        exit;
    }

    public static function register_announcement_type(): void {
        register_post_type( 'bsxmh_announcement', array(
            'labels' => array(
                'name' => __( 'Announcements', 'bsx-memberhub' ),
                'singular_name' => __( 'Announcement', 'bsx-memberhub' ),
                'add_new_item' => __( 'Add Announcement', 'bsx-memberhub' ),
                'edit_item' => __( 'Edit Announcement', 'bsx-memberhub' ),
            ),
            'public' => false,
            'show_ui' => current_user_can( 'manage_bsxmh' ),
            'show_in_menu' => 'bsxmh',
            'supports' => array( 'title', 'editor' ),
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ) );
    }

    public static function ensure_pages(): void {
        if ( get_transient( 'bsxmh_pages_checked_' . BSXMH_VERSION ) ) {
            return;
        }
        self::create_pages();
        set_transient( 'bsxmh_pages_checked_' . BSXMH_VERSION, 12 * HOUR_IN_SECONDS );
    }

    public static function create_pages(): void {
        $pages = array(
            'login_page_id' => array( 'Member Login', 'member-login', '[bsxmh_login]' ),
            'dashboard_page_id' => array( 'Member Dashboard', 'member-dashboard', '[bsxmh_dashboard]' ),
            'registration_page_id' => array( 'Member Registration', 'member-registration', '[bsxmh_register]' ),
            'payment_page_id' => array( 'Member Payment', 'member-payment', '[bsxmh_payment]' ),
            'contribution_page_id' => array( 'Member Contribution', 'member-contribution', '[bsxmh_online_contribution]' ),
            'events_page_id' => array( 'Member Events', 'member-events', '[bsxmh_event_list]' ),
            'transparency_page_id' => array( 'Transparency Dashboard', 'transparency-dashboard', '[bsxmh_transparency_dashboard]' ),
            'profile_page_id' => array( 'Member Profile', 'member-profile', '[bsxmh_profile]' ),
            'membership_card_page_id' => array( 'My Membership Card', 'membership-card', '[bsxmh_membership_card]' ),
            'finance_page_id' => array( 'My Finance', 'member-finance', '[bsxmh_member_finance]' ),
            'notifications_page_id' => array( 'Member Notifications', 'member-notifications', '[bsxmh_notifications]' ),
            'directory_page_id' => array( 'Member Directory', 'member-directory', '[bsxmh_member_directory]' ),
            'verification_page_id' => array( 'Member Verification', 'member-verification', '[bsxmh_member_verification]' ),
        );
        $settings = get_option( 'bsxmh_settings', array() );
        foreach ( $pages as $key => $page ) {
            $existing_id = absint( $settings[ $key ] ?? 0 );
            if ( $existing_id && 'trash' !== get_post_status( $existing_id ) ) {
                continue;
            }
            $found = get_page_by_path( $page[1], OBJECT, 'page' );
            if ( $found ) {
                $settings[ $key ] = (int) $found->ID;
                continue;
            }
            $id = wp_insert_post( array(
                'post_title' => $page[0],
                'post_name' => $page[1],
                'post_content' => $page[2],
                'post_status' => 'publish',
                'post_type' => 'page',
                'comment_status' => 'closed',
            ), true );
            if ( ! is_wp_error( $id ) ) {
                $settings[ $key ] = (int) $id;
            }
        }
        update_option( 'bsxmh_settings', $settings, false );
    }

    public static function page_url( string $key, string $fallback = '/' ): string {
        $settings = get_option( 'bsxmh_settings', array() );
        $id = absint( $settings[ $key ] ?? 0 );
        $url = $id ? get_permalink( $id ) : '';
        return $url ?: home_url( $fallback );
    }

    private static function is_member_user( $user = null ): bool {
        $user = $user instanceof WP_User ? $user : wp_get_current_user();
        return $user->exists() && in_array( 'bsxmh_member', (array) $user->roles, true );
    }

    public static function redirect_members_from_admin(): void {
        if ( ! is_user_logged_in() || ! self::is_member_user() || wp_doing_ajax() ) {
            return;
        }
        global $pagenow;
        $allowed = array( 'admin-ajax.php', 'async-upload.php' );
        if ( in_array( $pagenow, $allowed, true ) ) {
            return;
        }

        // Frontend forms submit through admin-post.php before any page output.
        // Allow only the MemberHub actions that members legitimately need.
        if ( 'admin-post.php' === $pagenow ) {
            $action = sanitize_key( wp_unslash( $_REQUEST['action'] ?? '' ) );
            $member_actions = array(
                'bsxmh_start_online_payment',
                'bsxmh_sslcommerz_return',
            );
            if ( in_array( $action, $member_actions, true ) ) {
                return;
            }
        }

        wp_safe_redirect( self::page_url( 'dashboard_page_id', '/member-dashboard/' ) );
        exit;
    }

    public static function hide_member_admin_bar( bool $show ): bool {
        return self::is_member_user() ? false : $show;
    }

    public static function login_redirect( string $redirect_to, string $requested, $user ): string {
        if ( $user instanceof WP_User && self::is_member_user( $user ) ) {
            return self::page_url( 'dashboard_page_id', '/member-dashboard/' );
        }
        return $redirect_to;
    }

    public static function logout_redirect(): void {
        wp_safe_redirect( add_query_arg( 'logged_out', '1', self::page_url( 'login_page_id', '/member-login/' ) ) );
        exit;
    }

    public static function login_form(): string {
        wp_enqueue_style( 'bsxmh-public' );
        wp_enqueue_script( 'bsxmh-public' );
        if ( is_user_logged_in() ) {
            return '<div class="bsxmh-notice bsxmh-success">' . esc_html__( 'You are already logged in.', 'bsx-memberhub' ) . ' <a href="' . esc_url( self::page_url( 'dashboard_page_id', '/member-dashboard/' ) ) . '">' . esc_html__( 'Open dashboard', 'bsx-memberhub' ) . '</a></div>';
        }
        $error = '';
        $error_code = sanitize_key( wp_unslash( $_GET['bsxmh_login_error'] ?? '' ) );
        if ( 'security' === $error_code ) {
            $error = __( 'Security verification failed. Please try again.', 'bsx-memberhub' );
        } elseif ( 'not_member' === $error_code ) {
            $error = __( 'This login page is for MemberHub members only.', 'bsx-memberhub' );
        } elseif ( 'invalid' === $error_code ) {
            $error = __( 'Invalid username/email or password.', 'bsx-memberhub' );
        }
        ob_start();
        ?>
        <?php $settings = get_option( 'bsxmh_settings', array() ); ?>
        <div class="bsxmh-login-shell bsxmh-auth-shell">
            <div class="bsxmh-auth-header">
                <?php if ( ! empty( $settings['organization_logo_url'] ) ) : ?><img class="bsxmh-auth-logo" src="<?php echo esc_url( $settings['organization_logo_url'] ); ?>" alt="<?php echo esc_attr( $settings['organization_name'] ?? get_bloginfo( 'name' ) ); ?>"><?php endif; ?>
                <?php if ( ! empty( $settings['login_description'] ) ) : ?><p><?php echo esc_html( $settings['login_description'] ); ?></p><?php endif; ?>
            </div>
            <form method="post" class="bsxmh-form bsxmh-login-form">
                <h2><?php esc_html_e( 'Member Login', 'bsx-memberhub' ); ?></h2>
                <?php if ( $error ) : ?><div class="bsxmh-notice bsxmh-error"><?php echo esc_html( $error ); ?></div><?php endif; ?>
                <?php if ( isset( $_GET['logged_out'] ) ) : ?><div class="bsxmh-notice bsxmh-success"><?php esc_html_e( 'You have been logged out successfully.', 'bsx-memberhub' ); ?></div><?php endif; ?>
                <?php wp_nonce_field( 'bsxmh_front_login', 'bsxmh_login_nonce' ); ?>
                <input type="hidden" name="bsxmh_login_submit" value="1">
                <div><label for="bsxmh-log"><?php esc_html_e( 'Username or Email', 'bsx-memberhub' ); ?> <span class="bsxmh-required" aria-hidden="true">*</span></label><input id="bsxmh-log" name="log" type="text" autocomplete="username" required></div>
                <div><label for="bsxmh-pwd"><?php esc_html_e( 'Password', 'bsx-memberhub' ); ?> <span class="bsxmh-required" aria-hidden="true">*</span></label><div class="bsxmh-password-wrap"><input id="bsxmh-pwd" name="pwd" type="password" autocomplete="current-password" required><button class="bsxmh-password-toggle" type="button" data-bsxmh-toggle-password="bsxmh-pwd" aria-label="<?php esc_attr_e( 'Show password', 'bsx-memberhub' ); ?>"><?php esc_html_e( 'Show', 'bsx-memberhub' ); ?></button></div></div>
                <label class="bsxmh-check"><input type="checkbox" name="rememberme" value="1"> <?php esc_html_e( 'Remember me', 'bsx-memberhub' ); ?></label>
                <button type="submit"><?php esc_html_e( 'Log In', 'bsx-memberhub' ); ?></button>
                <div class="bsxmh-login-links"><a href="<?php echo esc_url( wp_lostpassword_url( self::page_url( 'login_page_id', '/member-login/' ) ) ); ?>"><?php esc_html_e( 'Forgot password?', 'bsx-memberhub' ); ?></a></div>
                <div class="bsxmh-auth-switch"><span><?php esc_html_e( "Don't have an account?", 'bsx-memberhub' ); ?></span> <a class="bsxmh-secondary-button" href="<?php echo esc_url( self::page_url( 'registration_page_id', '/member-registration/' ) ); ?>"><?php esc_html_e( 'Register as Member', 'bsx-memberhub' ); ?></a></div>
            </form>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public static function profile_form(): string {
        wp_enqueue_style( 'bsxmh-public' );
        if ( ! is_user_logged_in() || ! self::is_member_user() ) {
            return '<div class="bsxmh-notice">' . esc_html__( 'Please log in as a member.', 'bsx-memberhub' ) . '</div>';
        }
        $user = wp_get_current_user();
        $member = BSXMH_Members::get_by_user( (int) $user->ID );
        $message = '';
        $profile_status = sanitize_key( wp_unslash( $_GET['bsxmh_profile_status'] ?? '' ) );
        if ( 'updated' === $profile_status ) {
            $message = '<div class="bsxmh-notice bsxmh-success">' . esc_html__( 'Profile updated successfully.', 'bsx-memberhub' ) . '</div>';
            $user = wp_get_current_user();
            $member = BSXMH_Members::get_by_user( (int) $user->ID );
        } elseif ( 'security' === $profile_status ) {
            $message = '<div class="bsxmh-notice bsxmh-error">' . esc_html__( 'Security verification failed.', 'bsx-memberhub' ) . '</div>';
        } elseif ( 'invalid' === $profile_status ) {
            $message = '<div class="bsxmh-notice bsxmh-error">' . esc_html__( 'Please enter a valid name and email address.', 'bsx-memberhub' ) . '</div>';
        } elseif ( 'custom_failed' === $profile_status ) {
            $errors = get_transient( 'bsxmh_profile_errors_' . (int) $user->ID ); delete_transient( 'bsxmh_profile_errors_' . (int) $user->ID );
            $message = '<div class="bsxmh-notice bsxmh-error">' . esc_html( implode( ' ', (array) $errors ) ) . '</div>';
        } elseif ( 'photo_too_large' === $profile_status ) {
            $message = '<div class="bsxmh-notice bsxmh-error">' . esc_html__( 'The profile photo is larger than the allowed upload size.', 'bsx-memberhub' ) . '</div>';
        } elseif ( 'photo_type' === $profile_status ) {
            $message = '<div class="bsxmh-notice bsxmh-error">' . esc_html__( 'Please upload a JPG, PNG or WebP image.', 'bsx-memberhub' ) . '</div>';
        } elseif ( 'photo_failed' === $profile_status ) {
            $photo_error = get_transient( 'bsxmh_profile_photo_error_' . (int) $user->ID ); delete_transient( 'bsxmh_profile_photo_error_' . (int) $user->ID );
            $message = '<div class="bsxmh-notice bsxmh-error">' . esc_html( $photo_error ?: __( 'The profile photo could not be updated.', 'bsx-memberhub' ) ) . '</div>';
        } elseif ( 'failed' === $profile_status ) {
            $message = '<div class="bsxmh-notice bsxmh-error">' . esc_html__( 'Profile update failed. Please try again.', 'bsx-memberhub' ) . '</div>';
        }
        $core_fields = BSXMH_Form_Builder::core_settings();
        ob_start(); echo $message; ?>
        <div class="bsxmh-page-heading"><div><p class="bsxmh-eyebrow"><?php esc_html_e( 'Account & identity', 'bsx-memberhub' ); ?></p><h2><?php esc_html_e( 'My Profile', 'bsx-memberhub' ); ?></h2><p><?php esc_html_e( 'Keep your contact and membership information accurate.', 'bsx-memberhub' ); ?></p></div></div>
        <?php echo wp_kses_post( BSXMH_Profile_Completion::render_card( (int) $user->ID, $member, true ) ); ?>
        <form method="post" enctype="multipart/form-data" class="bsxmh-form bsxmh-profile-form">
            <h3><?php esc_html_e( 'Profile Information', 'bsx-memberhub' ); ?></h3>
            <?php wp_nonce_field( 'bsxmh_front_profile', 'bsxmh_profile_nonce' ); ?><input type="hidden" name="bsxmh_profile_submit" value="1">
            <?php $photo_settings = get_option( 'bsxmh_settings', array() ); $allow_photo = ! isset( $photo_settings['allow_member_profile_photo'] ) || ! empty( $photo_settings['allow_member_profile_photo'] ); ?>
            <?php if ( $allow_photo && $member ) : ?>
            <section class="bsxmh-profile-photo-card">
                <div class="bsxmh-profile-photo-preview"><?php echo BSXMH_Members::profile_photo_html( $member, 128, 'bsxmh-profile-self-photo' ); ?></div>
                <div class="bsxmh-profile-photo-controls">
                    <h3><?php esc_html_e( 'Profile Photo', 'bsx-memberhub' ); ?></h3>
                    <input type="file" name="profile_photo" id="bsxmh-profile-photo" accept="image/jpeg,image/png,image/webp">
                    <small><?php printf( esc_html__( 'JPG, PNG or WebP. Maximum %d MB. A square photo works best.', 'bsx-memberhub' ), max( 1, min( 10, absint( $photo_settings['profile_photo_max_mb'] ?? 2 ) ) ) ); ?></small>
                    <?php if ( BSXMH_Members::profile_photo_id( $member ) ) : ?><label class="bsxmh-remove-photo"><input type="checkbox" name="remove_profile_photo" value="1"> <?php esc_html_e( 'Remove current photo', 'bsx-memberhub' ); ?></label><?php endif; ?>
                </div>
            </section>
            <?php endif; ?>
            <div><label><?php esc_html_e( 'Member ID', 'bsx-memberhub' ); ?></label><input type="text" value="<?php echo esc_attr( $member->member_number ?? '' ); ?>" disabled></div>
            <?php $member_tags = $member ? BSXMH_Members::tags( $member ) : array(); ?>
            <?php if ( ! empty( $member_tags ) ) : ?><div class="bsxmh-profile-tags-field"><label><?php esc_html_e( 'Member Tags', 'bsx-memberhub' ); ?></label><div class="bsxmh-member-tags bsxmh-member-tags-public"><?php foreach ( $member_tags as $member_tag ) : ?><span><?php echo esc_html( $member_tag ); ?></span><?php endforeach; ?></div><small><?php esc_html_e( 'Tags are assigned by an administrator.', 'bsx-memberhub' ); ?></small></div><?php endif; ?>
            <div><label for="bsxmh-display-name"><?php esc_html_e( 'Full Name', 'bsx-memberhub' ); ?></label><input id="bsxmh-display-name" name="display_name" value="<?php echo esc_attr( $user->display_name ); ?>" required></div>
            <div><label for="bsxmh-profile-email"><?php esc_html_e( 'Email', 'bsx-memberhub' ); ?></label><input id="bsxmh-profile-email" type="email" name="email" value="<?php echo esc_attr( $user->user_email ); ?>" required></div>
            <?php if ( ! empty( $core_fields['phone_enabled'] ) ) : ?><div><label for="bsxmh-profile-phone"><?php echo esc_html( $core_fields['phone_label'] ); ?></label><input id="bsxmh-profile-phone" name="phone" value="<?php echo esc_attr( get_user_meta( (int) $user->ID, 'bsxmh_phone', true ) ); ?>" <?php echo ! empty( $core_fields['phone_required'] ) ? 'required' : ''; ?>></div><?php endif; ?>
            <?php echo BSXMH_Form_Builder::render_fields( 'profile', (int) $user->ID ); ?>
            <div><label for="bsxmh-new-password"><?php esc_html_e( 'New Password', 'bsx-memberhub' ); ?></label><input id="bsxmh-new-password" type="password" name="new_password" minlength="8"><small><?php esc_html_e( 'Leave blank to keep the current password.', 'bsx-memberhub' ); ?></small></div>
            <?php $directory_settings = get_option( 'bsxmh_settings', array() ); if ( ! isset( $directory_settings['directory_allow_opt_out'] ) || ! empty( $directory_settings['directory_allow_opt_out'] ) ) : ?><div class="bsxmh-directory-privacy-setting"><label><input type="checkbox" name="directory_hidden" value="1" <?php checked( get_user_meta( get_current_user_id(), 'bsxmh_directory_hidden', true ), '1' ); ?>> <span><strong><?php esc_html_e( 'Hide me from the Member Directory', 'bsx-memberhub' ); ?></strong><small><?php esc_html_e( 'Your private contact and payment details are never shown in the directory.', 'bsx-memberhub' ); ?></small></span></label></div><?php endif; ?>
            <button type="submit"><?php esc_html_e( 'Update Profile', 'bsx-memberhub' ); ?></button>
        </form>
        <?php return self::wrap_member_page( (string) ob_get_clean(), 'profile' );
    }

    public static function wrap_member_page( string $content, string $active = '' ): string {
        if ( ! is_user_logged_in() || ! self::is_member_user() ) {
            return $content;
        }
        $settings = get_option( 'bsxmh_settings', array() );
        $footer = trim( (string) ( $settings['portal_footer_text'] ?? '' ) );
        $support = array();
        if ( ! empty( $settings['support_email'] ) ) $support[] = '<a href="mailto:' . esc_attr( $settings['support_email'] ) . '">' . esc_html( $settings['support_email'] ) . '</a>';
        if ( ! empty( $settings['support_phone'] ) ) $support[] = '<span>' . esc_html( $settings['support_phone'] ) . '</span>';
        $footer_html = ( $footer || $support ) ? '<footer class="bsxmh-portal-footer"><span>' . esc_html( $footer ) . '</span>' . ( $support ? '<span>' . implode( ' · ', $support ) . '</span>' : '' ) . '</footer>' : '';
        return '<div class="bsxmh-portal-shell">' . self::nav( $active ) . '<main class="bsxmh-portal-content">' . $content . $footer_html . '</main></div>';
    }

    public static function nav( string $active = '' ): string {
        if ( ! is_user_logged_in() || ! self::is_member_user() ) return '';
        $items = array(
            'overview'     => array( self::page_url( 'dashboard_page_id', '/member-dashboard/' ), __( 'Overview', 'bsx-memberhub' ), '⌂' ),
            'payment'      => array( self::page_url( 'payment_page_id', '/member-payment/' ), __( 'Pay Fees', 'bsx-memberhub' ), '৳' ),
            'contribution' => array( self::page_url( 'contribution_page_id', '/member-contribution/' ), __( 'Contribution', 'bsx-memberhub' ), '♥' ),
            'events'       => array( self::page_url( 'events_page_id', '/member-events/' ), __( 'Events', 'bsx-memberhub' ), '◇' ),
            'card'         => array( self::page_url( 'membership_card_page_id', '/membership-card/' ), __( 'My Card', 'bsx-memberhub' ), '▣' ),
            'finance'      => array( self::page_url( 'finance_page_id', '/member-finance/' ), __( 'My Finance', 'bsx-memberhub' ), '▤' ),
            'notifications'=> array( self::page_url( 'notifications_page_id', '/member-notifications/' ), __( 'Notifications', 'bsx-memberhub' ), '●' ),
            'directory'    => array( self::page_url( 'directory_page_id', '/member-directory/' ), __( 'Directory', 'bsx-memberhub' ), '◎' ),
        );
        if ( class_exists( 'BSXMH_Member_Directory' ) && ! BSXMH_Member_Directory::enabled() ) { unset( $items['directory'] ); }
        if ( BSXMH_Transparency::show_in_member_navigation() ) {
            $items['transparency'] = array( self::page_url( 'transparency_page_id', '/transparency-dashboard/' ), __( 'Transparency', 'bsx-memberhub' ), '◫' );
        }
        $items['profile'] = array( self::page_url( 'profile_page_id', '/member-profile/' ), __( 'Profile', 'bsx-memberhub' ), '●' );
        $items['logout'] = array( wp_logout_url( self::page_url( 'login_page_id', '/member-login/' ) ), __( 'Logout', 'bsx-memberhub' ), '↗' );
        $html = '<nav class="bsxmh-portal-nav" aria-label="' . esc_attr__( 'Member portal navigation', 'bsx-memberhub' ) . '">';
        foreach ( $items as $key => $item ) {
            $class = $active === $key ? ' class="is-active" aria-current="page"' : '';
            $label = '<span class="bsxmh-nav-icon" aria-hidden="true">' . esc_html( $item[2] ?? '•' ) . '</span><span class="bsxmh-nav-label">' . esc_html( $item[1] ) . '</span>';
            if ( 'notifications' === $key && class_exists( 'BSXMH_Notifications' ) ) { $count = BSXMH_Notifications::unread_count(); if ( $count ) $label .= ' <span class="bsxmh-nav-badge">' . number_format_i18n( $count ) . '</span>'; }
            $html .= '<a' . $class . ' href="' . esc_url( $item[0] ) . '">' . $label . '</a>';
        }
        return $html . '</nav>';
    }

    public static function announcements(): string {
        $posts = get_posts( array( 'post_type' => 'bsxmh_announcement', 'post_status' => 'publish', 'posts_per_page' => 5, 'orderby' => 'date', 'order' => 'DESC' ) );
        if ( ! $posts ) return '';
        $html = '<section class="bsxmh-announcements"><h3>' . esc_html__( 'Announcements', 'bsx-memberhub' ) . '</h3>';
        foreach ( $posts as $post ) $html .= '<article><strong>' . esc_html( get_the_title( $post ) ) . '</strong><div>' . wp_kses_post( wpautop( $post->post_content ) ) . '</div></article>';
        return $html . '</section>';
    }
}
