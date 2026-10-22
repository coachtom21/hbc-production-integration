<?php
/**
 * Plugin Name: Human Blockchain Production Integration
 * Description: Private fail-closed adapters for the Human Gold LAUGH coordinator. Wires existing HBC devices, OTP, $12 membership, and XP. Trade stays locked until Coach Tom accepts Gate 1.
 * Version: 0.2.0-local
 * Requires PHP: 8.1
 * Requires Plugins: hbc-laugh-event-coordinator
 */

defined( 'ABSPATH' ) || exit;

define( 'HBC_PROD_VERSION', '0.2.0-local' );
define( 'HBC_PROD_FILE', __FILE__ );
define( 'HBC_PROD_DIR', plugin_dir_path( __FILE__ ) );

if ( ! defined( 'HBC_LAUGH_VERSION' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>Activate Human Gold LAUGH Event Coordinator first.</p></div>';
		}
	);
	return;
}

require_once HBC_PROD_DIR . 'includes/class-hbc-prod-store.php';
require_once HBC_PROD_DIR . 'includes/class-hbc-prod-qr.php';
require_once HBC_PROD_DIR . 'includes/class-hbc-prod-orgs.php';
require_once HBC_PROD_DIR . 'includes/class-hbc-production-adapters.php';
require_once HBC_PROD_DIR . 'includes/class-hbc-prod-admin.php';

register_activation_hook(
	__FILE__,
	static function () {
		HBC_Prod_Store::activate();
		flush_rewrite_rules( false );
	}
);

add_action( 'plugins_loaded', array( 'HBC_Production_Adapters', 'register' ), 20 );
add_action( 'plugins_loaded', array( 'HBC_Prod_Orgs', 'register' ), 21 );
add_action( 'init', array( 'HBC_Prod_Store', 'ensure_admin_caps' ), 5 );
add_action( 'init', array( 'HBC_Prod_Store', 'maybe_seed' ), 30 );
add_action( 'init', array( 'HBC_Prod_Orgs', 'ensure_seed_code' ), 32 );
add_action( 'init', array( 'HBC_Prod_Admin', 'register_routes' ), 31 );
add_action(
	'init',
	static function () {
		if ( get_option( 'hbc_prod_rewrite_v', '' ) !== 'doors-orgs-1' ) {
			flush_rewrite_rules( false );
			update_option( 'hbc_prod_rewrite_v', 'doors-orgs-1', false );
		}
	},
	99
);
add_action( 'init', array( 'HBC_Prod_Store', 'maybe_open_local_registration' ), 40 );
add_action( 'admin_menu', array( 'HBC_Prod_Admin', 'menu' ) );
add_action( 'admin_init', array( 'HBC_Prod_Admin', 'maybe_handle_create_event' ) );
add_action( 'hbc_prod_orphan_sweep', array( 'HBC_Prod_Store', 'sweep_orphans' ) );
add_action( 'rest_api_init', array( 'HBC_Prod_Admin', 'register_rest' ) );
add_action( 'wp_head', array( 'HBC_Prod_Admin', 'hide_legacy_modals' ), 99 );
add_action( 'wp_footer', array( 'HBC_Prod_Admin', 'coordinator_signout' ), 20 );
