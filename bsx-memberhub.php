<?php
/**
 * Plugin Name:       BackspaceX MemberHub
 * Plugin URI:        https://backspacex.com/
 * Description:       Complete membership, donation, event fundraising and finance management for WordPress.
 * Version:           1.5.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            BackspaceX
 * Author URI:        https://backspacex.com/
 * Text Domain:       bsx-memberhub
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'BSXMH_VERSION', '1.5.0' );
define( 'BSXMH_FILE', __FILE__ );
define( 'BSXMH_PATH', plugin_dir_path( __FILE__ ) );
define( 'BSXMH_URL', plugin_dir_url( __FILE__ ) );
define( 'BSXMH_BASENAME', plugin_basename( __FILE__ ) );

require_once BSXMH_PATH . 'includes/class-bsxmh-activator.php';
require_once BSXMH_PATH . 'includes/class-bsxmh-deactivator.php';
require_once BSXMH_PATH . 'includes/class-bsxmh-db.php';
require_once BSXMH_PATH . 'includes/class-bsxmh-members.php';
require_once BSXMH_PATH . 'includes/class-bsxmh-profile-completion.php';
require_once BSXMH_PATH . 'includes/class-bsxmh-timeline.php';
require_once BSXMH_PATH . 'includes/class-bsxmh-form-builder.php';
require_once BSXMH_PATH . 'includes/class-bsxmh-payments.php';
require_once BSXMH_PATH . 'includes/class-bsxmh-receipts.php';
require_once BSXMH_PATH . 'includes/class-bsxmh-contributions.php';
require_once BSXMH_PATH . 'includes/class-bsxmh-events.php';
require_once BSXMH_PATH . 'includes/class-bsxmh-finance.php';
require_once BSXMH_PATH . 'includes/interface-bsxmh-gateway.php';
require_once BSXMH_PATH . 'includes/class-bsxmh-gateway-sslcommerz.php';
require_once BSXMH_PATH . 'includes/class-bsxmh-gateways.php';
require_once BSXMH_PATH . 'includes/class-bsxmh-portal.php';
require_once BSXMH_PATH . 'includes/class-bsxmh-membership-card.php';
require_once BSXMH_PATH . 'includes/class-bsxmh-member-finance.php';
require_once BSXMH_PATH . 'includes/class-bsxmh-notifications.php';
require_once BSXMH_PATH . 'includes/class-bsxmh-member-directory.php';
require_once BSXMH_PATH . 'includes/class-bsxmh-email-automation.php';
require_once BSXMH_PATH . 'includes/class-bsxmh-roles.php';
require_once BSXMH_PATH . 'includes/class-bsxmh-reports.php';
require_once BSXMH_PATH . 'includes/class-bsxmh-transparency.php';
require_once BSXMH_PATH . 'includes/class-bsxmh-system-tools.php';
require_once BSXMH_PATH . 'includes/class-bsxmh-help.php';
require_once BSXMH_PATH . 'includes/class-bsxmh-payment-control.php';
require_once BSXMH_PATH . 'includes/class-bsxmh-core.php';
require_once BSXMH_PATH . 'admin/class-bsxmh-admin.php';
require_once BSXMH_PATH . 'public/class-bsxmh-public.php';

register_activation_hook( __FILE__, array( 'BSXMH_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BSXMH_Deactivator', 'deactivate' ) );

function bsxmh_run_plugin(): void {
    $plugin = new BSXMH_Core();
    $plugin->run();
}

bsxmh_run_plugin();
