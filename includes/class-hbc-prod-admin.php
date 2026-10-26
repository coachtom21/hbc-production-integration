<?php
defined( 'ABSPATH' ) || exit;

/**
 * Super Admin shell, Human Gold ops screen, encounter QR, and live-door rewrites.
 */
final class HBC_Prod_Admin {
	public static function register_routes(): void {
		add_rewrite_rule( '^super-admin/?$', 'index.php?hbc_super_admin=1', 'top' );
		add_rewrite_rule( '^print-studio/?$', 'index.php?hbc_print_studio=1', 'top' );
		add_rewrite_rule( '^hbc-qr/([A-Za-z0-9_-]+)/?$', 'index.php?hbc_qr_token=$matches[1]', 'top' );
		add_rewrite_rule( '^door/(rsvp|trade|gratitude|encounter)/?$', 'index.php?hbc_door=$matches[1]', 'top' );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'template_include', array( __CLASS__, 'template' ), 20 );
		add_action( 'template_redirect', array( __CLASS__, 'serve_qr' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'serve_door_qr' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_stubs' ) );
	}

	public static function query_vars( array $vars ): array {
		$vars[] = 'hbc_super_admin';
		$vars[] = 'hbc_print_studio';
		$vars[] = 'hbc_qr_token';
		$vars[] = 'hbc_door';
		return $vars;
	}

	public static function serve_qr(): void {
		$token = sanitize_text_field( (string) get_query_var( 'hbc_qr_token' ) );
		if ( $token === '' ) {
			return;
		}
		if ( ! HBC_Prod_Store::session_by_token( $token ) ) {
			status_header( 404 );
			exit;
		}
		header( 'Content-Type: image/svg+xml; charset=UTF-8' );
		header( 'Cache-Control: no-store' );
		echo HBC_Prod_QR::svg( home_url( '/e/' . $token ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Self-hosted door QRs (RSVP / Trade / Gratitude / Encounter) — no QRTiger.
	 */
	public static function serve_door_qr(): void {
		$door = sanitize_key( (string) get_query_var( 'hbc_door' ) );
		if ( $door === '' ) {
			return;
		}
		$targets = HBC_Prod_Orgs::door_targets();
		if ( ! isset( $targets[ $door ] ) ) {
			status_header( 404 );
			exit;
		}
		$format = isset( $_GET['format'] ) ? sanitize_key( (string) wp_unslash( $_GET['format'] ) ) : 'svg'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $format === 'redirect' ) {
			wp_safe_redirect( $targets[ $door ]['url'], 302 );
			exit;
		}
		header( 'Content-Type: image/svg+xml; charset=UTF-8' );
		header( 'Cache-Control: public, max-age=3600' );
		echo HBC_Prod_QR::svg( $targets[ $door ]['url'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public static function template( string $template ): string {
		if ( get_query_var( 'hbc_super_admin' ) ) {
			return HBC_PROD_DIR . 'templates/super-admin.php';
		}
		if ( get_query_var( 'hbc_print_studio' ) ) {
			return HBC_PROD_DIR . 'templates/print-studio.php';
		}
		return $template;
	}

	public static function redirect_stubs(): void {
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}
		if ( ! is_page( array( 'laugh-events', 'host-a-laugh', 'organizer-dashboard', 'make-a-move' ) ) ) {
			return;
		}
		$slug = get_post_field( 'post_name', get_queried_object_id() );
		if ( in_array( $slug, array( 'host-a-laugh', 'organizer-dashboard' ), true ) ) {
			wp_safe_redirect( home_url( '/organize' ), 302 );
			exit;
		}
		wp_safe_redirect( home_url( '/r' ), 302 );
		exit;
	}

	public static function hide_legacy_modals(): void {
		if ( ! get_query_var( 'hbc_universal_entry' ) && ! get_query_var( 'hbc_trade_entry' ) && ! get_query_var( 'hbc_organize' ) && ! get_query_var( 'hbc_encounter_token' ) && ! get_query_var( 'hbc_super_admin' ) ) {
			return;
		}
		echo '<style id="hbc-prod-hide-legacy">#cpm-hb-membership-modal,#cpm-hb-membership-contact-modal,.cpm-hb-get-started-wrap{display:none!important}</style>';
	}

	public static function coordinator_signout(): void {
		if ( ! get_query_var( 'hbc_universal_entry' ) && ! get_query_var( 'hbc_trade_entry' ) && ! get_query_var( 'hbc_organize' ) && ! get_query_var( 'hbc_encounter_token' ) ) {
			return;
		}
		$url = function_exists( 'hb_signout_url' ) ? hb_signout_url() : home_url( '/?hbc_signout=1' );
		echo '<p class="hbc-device-signout" style="text-align:center;margin:20px 0 36px"><a href="' . esc_url( $url ) . '">Sign out of this device</a></p>';
	}

	public static function menu(): void {
		add_menu_page(
			'Human Gold',
			'Human Gold',
			'hbc_admin',
			'hbc-human-gold',
			array( __CLASS__, 'render_ops' ),
			'dashicons-superhero',
			58
		);
		add_submenu_page(
			'hbc-human-gold',
			'Print Materials',
			'Print Materials',
			'hbc_admin',
			'hbc-print-studio',
			array( __CLASS__, 'render_print_studio_admin' )
		);
		add_submenu_page(
			'hbc-human-gold',
			'Super Admin Console',
			'Super Admin Console',
			'hbc_super_admin',
			'hbc-super-admin-console',
			array( __CLASS__, 'redirect_to_super_admin' )
		);
	}

	/** Send WP Admin users straight to the front-end Super Admin console. */
	public static function redirect_to_super_admin(): void {
		if ( ! current_user_can( 'hbc_super_admin' ) ) {
			wp_die( esc_html__( 'Protected Super Admin access is required.', 'hbc-prod' ) );
		}
		wp_safe_redirect( home_url( '/super-admin' ) );
		exit;
	}

	public static function render_print_studio_admin(): void {
		if ( ! current_user_can( 'hbc_admin' ) ) {
			wp_die( esc_html__( 'Protected access required.', 'hbc-prod' ) );
		}
		$url = home_url( '/print-studio' );
		echo '<div class="wrap"><h1>Print Materials Studio</h1>';
		echo '<p>Design and download Human Gold RSVP postcards. The postcard fixture QR is theme-hosted (<code>Human_Gold_RSVP</code>) and points at <code>/r</code> — not QRTiger.</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">Open Print Studio</a></p>';
		echo '<h2>Self-hosted door QRs (SVG)</h2><ul>';
		foreach ( HBC_Prod_Orgs::door_targets() as $key => $door ) {
			$svg = home_url( '/door/' . $key );
			echo '<li><strong>' . esc_html( $door['label'] ) . '</strong> — <a href="' . esc_url( $svg ) . '" target="_blank">' . esc_html( $svg ) . '</a> → ' . esc_html( $door['url'] ) . '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Handle create-event POST on the Human Gold screen (not admin-post.php).
	 * admin-post with a late-registered hook often renders a blank page.
	 */
	public static function maybe_handle_create_event(): void {
		if ( ! is_admin() || ! current_user_can( 'hbc_admin' ) ) {
			return;
		}
		if ( ( $_GET['page'] ?? '' ) !== 'hbc-human-gold' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			return;
		}
		$action = sanitize_key( (string) ( $_POST['hbc_action'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $action === 'create_event' ) {
			self::handle_create_event_post();
			return;
		}
		if ( $action === 'update_event' ) {
			self::handle_update_event_post();
			return;
		}
		if ( $action === 'review_volunteer' ) {
			self::handle_review_volunteer_post();
			return;
		}
		if ( $action === 'trade_gate' && current_user_can( 'hbc_super_admin' ) ) {
			self::handle_trade_gate_post();
			return;
		}
		if ( $action === 'register_org' ) {
			self::handle_register_org_post();
			return;
		}
		if ( $action === 'super_admin_allowlist' && current_user_can( 'hbc_super_admin' ) ) {
			self::handle_super_admin_allowlist_post();
			return;
		}
		if ( $action === 'reconcile_snapshot' && current_user_can( 'hbc_admin' ) ) {
			check_admin_referer( 'hbc_reconcile_snapshot' );
			$snap = HBC_Prod_Orgs::reconcile_snapshot();
			self::redirect_ops( 'Reconciliation snapshot saved for ' . (string) ( $snap['period'] ?? '' ) . '.' );
		}
	}

	private static function handle_register_org_post(): void {
		check_admin_referer( 'hbc_register_org' );
		if ( ! current_user_can( 'hbc_admin' ) ) {
			self::redirect_ops( '', 'Admin access required.' );
		}
		$result = HBC_Prod_Orgs::register_organization(
			array(
				'name'        => isset( $_POST['org_name'] ) ? wp_unslash( (string) $_POST['org_name'] ) : '',
				'type'        => (string) ( $_POST['org_type'] ?? 'church' ),
				'public_code' => (string) ( $_POST['public_code'] ?? '' ),
			),
			get_current_user_id()
		);
		if ( ! empty( $result['error']['message'] ) ) {
			self::redirect_ops( '', (string) $result['error']['message'] );
		}
		self::redirect_ops( (string) ( $result['message'] ?? 'Organization registered.' ) );
	}

	private static function handle_super_admin_allowlist_post(): void {
		check_admin_referer( 'hbc_super_admin_allowlist' );
		$raw = sanitize_text_field( (string) ( $_POST['user_ids'] ?? '' ) );
		$ids = array_filter( array_map( 'intval', preg_split( '/[\s,]+/', $raw ) ?: array() ) );
		$result = HBC_Prod_Orgs::set_super_admin_allowlist( $ids );
		if ( ! empty( $result['error']['message'] ) ) {
			self::redirect_ops( '', (string) $result['error']['message'] );
		}
		self::redirect_ops( (string) ( $result['message'] ?? 'Allowlist updated.' ) );
	}

	private static function redirect_ops( string $ok = '', string $err = '' ): void {
		$args = array( 'page' => 'hbc-human-gold' );
		if ( $ok !== '' ) {
			$args['hbc_ok'] = rawurlencode( $ok );
		}
		if ( $err !== '' ) {
			$args['hbc_err'] = rawurlencode( $err );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function handle_create_event_post(): void {
		check_admin_referer( 'hbc_create_event' );

		$host_uuid = sanitize_text_field( (string) ( $_POST['host_uuid'] ?? '' ) );
		if ( ! HBC_Prod_Store::is_uuid( $host_uuid ) ) {
			$host_uuid = HBC_Prod_Store::host_uuid();
		}
		if ( $host_uuid === '' || ! apply_filters( 'hbc_user_can_access_host', false, get_current_user_id(), $host_uuid ) ) {
			self::redirect_ops( '', 'This account is not assigned to that organization.' );
		}

		$result = HBC_Prod_Store::create_event(
			$host_uuid,
			array(
				'name'              => isset( $_POST['name'] ) ? wp_unslash( (string) $_POST['name'] ) : '',
				'event_type'        => (string) ( $_POST['event_type'] ?? 'community' ),
				'description'       => isset( $_POST['description'] ) ? wp_unslash( (string) $_POST['description'] ) : '',
				'location'          => isset( $_POST['location'] ) ? wp_unslash( (string) $_POST['location'] ) : '',
				'starts_at'         => (string) ( $_POST['starts_at'] ?? '' ),
				'ends_at'           => (string) ( $_POST['ends_at'] ?? '' ),
				'capacity'          => (string) ( $_POST['capacity'] ?? '' ),
				'status'            => (string) ( $_POST['status'] ?? 'published' ),
				'event_code'        => (string) ( $_POST['event_code'] ?? '' ),
				'allow_observe'     => ! empty( $_POST['allow_observe'] ),
				'allow_participate' => ! empty( $_POST['allow_participate'] ),
			)
		);
		if ( ! empty( $result['error']['message'] ) ) {
			self::redirect_ops( '', (string) $result['error']['message'] );
		}
		self::redirect_ops( (string) ( $result['message'] ?? 'Event saved.' ) );
	}

	private static function handle_update_event_post(): void {
		check_admin_referer( 'hbc_update_event' );
		$host_uuid  = sanitize_text_field( (string) ( $_POST['host_uuid'] ?? '' ) );
		$event_uuid = sanitize_text_field( (string) ( $_POST['event_uuid'] ?? '' ) );
		if ( ! apply_filters( 'hbc_user_can_access_host', false, get_current_user_id(), $host_uuid ) ) {
			self::redirect_ops( '', 'This account is not assigned to that organization.' );
		}
		$result = HBC_Prod_Store::update_event(
			$host_uuid,
			$event_uuid,
			array(
				'name'         => isset( $_POST['name'] ) ? wp_unslash( (string) $_POST['name'] ) : '',
				'location'     => isset( $_POST['location'] ) ? wp_unslash( (string) $_POST['location'] ) : '',
				'description'  => isset( $_POST['description'] ) ? wp_unslash( (string) $_POST['description'] ) : '',
				'starts_at'    => (string) ( $_POST['starts_at'] ?? '' ),
				'ends_at'      => (string) ( $_POST['ends_at'] ?? '' ),
				'status'       => (string) ( $_POST['status'] ?? 'published' ),
				'event_code'   => (string) ( $_POST['event_code'] ?? '' ),
				'allow_observe'     => ! empty( $_POST['allow_observe'] ),
				'allow_participate' => ! empty( $_POST['allow_participate'] ),
			)
		);
		if ( ! empty( $result['error']['message'] ) ) {
			self::redirect_ops( '', (string) $result['error']['message'] );
		}
		self::redirect_ops( (string) ( $result['message'] ?? 'Event updated.' ) );
	}

	private static function handle_review_volunteer_post(): void {
		check_admin_referer( 'hbc_review_volunteer' );
		$uuid     = sanitize_text_field( (string) ( $_POST['volunteer_uuid'] ?? '' ) );
		$decision = sanitize_key( (string) ( $_POST['decision'] ?? '' ) );
		$reviewer = (string) get_user_meta( get_current_user_id(), 'hbc_identity_uuid', true );
		$result   = HBC_Prod_Store::review_volunteer( $uuid, $decision, $reviewer );
		if ( ! empty( $result['error']['message'] ) ) {
			self::redirect_ops( '', (string) $result['error']['message'] );
		}
		self::redirect_ops( (string) ( $result['message'] ?? 'Volunteer updated.' ) );
	}

	private static function handle_trade_gate_post(): void {
		check_admin_referer( 'hbc_trade_gate' );
		$action = sanitize_key( (string) ( $_POST['trade_action'] ?? '' ) );
		$reason = sanitize_text_field( (string) ( $_POST['reason'] ?? '' ) );
		$actor  = wp_get_current_user()->user_login ?: 'super_admin';
		if ( $action === 'emergency' ) {
			$result = HBC_Prod_Store::trade_emergency_shutdown( $reason, $actor );
		} elseif ( $action === 'on' || $action === 'off' ) {
			$result = HBC_Prod_Store::set_trade_unlocked( $action === 'on', $reason, $actor );
		} elseif ( $action === 'authorize_seller' || $action === 'revoke_seller' ) {
			$result = HBC_Prod_Store::set_seller_trade_authorization(
				sanitize_text_field( (string) ( $_POST['identity_uuid'] ?? '' ) ),
				$action === 'authorize_seller',
				$reason,
				$actor
			);
		} else {
			self::redirect_ops( '', 'Unknown Trade action.' );
		}
		if ( ! empty( $result['error']['message'] ) ) {
			self::redirect_ops( '', (string) $result['error']['message'] );
		}
		self::redirect_ops( 'Trade controls updated.' );
	}

	public static function register_rest(): void {
		register_rest_route(
			'hbc-prod/v1',
			'/qr',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'qr' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function qr( WP_REST_Request $request ) {
		$token = sanitize_text_field( (string) $request->get_param( 't' ) );
		if ( $token === '' || ! HBC_Prod_Store::session_by_token( $token ) ) {
			return new WP_Error( 'hbc_qr_missing', 'This Identity QR is unavailable.', array( 'status' => 404 ) );
		}
		$url = home_url( '/e/' . $token );
		$svg = HBC_Prod_QR::svg( $url );
		$response = new WP_REST_Response( $svg );
		$response->header( 'Content-Type', 'image/svg+xml; charset=UTF-8' );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public static function render_ops(): void {
		if ( ! current_user_can( 'hbc_admin' ) ) {
			wp_die( esc_html__( 'Protected access required.', 'hbc-prod' ) );
		}
		$report = class_exists( 'HBC_Laugh_Readiness' ) ? HBC_Laugh_Readiness::report() : array();
		global $wpdb;
		$events = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}hbc_events WHERE status = 'published'" );
		$rsvps  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}hbc_rsvps WHERE status = 'completed'" );
		$open   = class_exists( 'HBC_Laugh_Readiness' ) && HBC_Laugh_Readiness::registration_is_open();
		$trade  = HBC_Prod_Store::trade_is_unlocked() ? 'OPEN' : 'LOCKED';
		$host   = HBC_Prod_Store::host_uuid();
		$list   = $host ? HBC_Prod_Store::list_host_events( $host ) : array( 'events' => array(), 'host_name' => '' );

		$ok_msg  = isset( $_GET['hbc_ok'] ) ? sanitize_text_field( rawurldecode( (string) wp_unslash( $_GET['hbc_ok'] ) ) ) : '';
		$err_msg = isset( $_GET['hbc_err'] ) ? sanitize_text_field( rawurldecode( (string) wp_unslash( $_GET['hbc_err'] ) ) ) : '';

		echo '<div class="wrap"><h1>Human Gold</h1>';
		if ( current_user_can( 'hbc_super_admin' ) ) {
			echo '<p><a class="button button-primary button-hero" href="' . esc_url( home_url( '/super-admin' ) ) . '">Open Super Admin Console →</a></p>';
		}
		if ( $ok_msg !== '' ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $ok_msg ) . '</p></div>';
		}
		if ( $err_msg !== '' ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $err_msg ) . '</p></div>';
		}
		echo '<p>Gate 1 is RSVP at <code>/r</code>. Trade is <strong>' . esc_html( $trade ) . '</strong>. Super Admins can flip Trade without deploy.</p>';
		echo '<table class="widefat striped" style="max-width:720px"><tbody>';
		self::row( 'Registration', $open ? ( HBC_Prod_Store::is_local() ? 'open (local)' : 'open' ) : 'closed' );
		self::row( 'Schema', class_exists( 'HBC_Laugh_Schema' ) ? (string) HBC_Laugh_Schema::installed_version() : 'missing' );
		self::row( 'Published events', (string) $events );
		self::row( 'Completed RSVPs', (string) $rsvps );
		self::row( 'Pilot host', $host !== '' ? $host : 'not seeded' );
		self::row( 'Host name', (string) ( $list['host_name'] ?? '' ) );
		self::row( 'Community event', HBC_Prod_Store::community_event_uuid() );
		self::row( 'Seed event code', 'STONE' );
		self::row( 'Practice FAITH event', HBC_Prod_Store::faith_event_uuid() );
		self::row( 'Trade', $trade );
		self::row( '$12 checkout', HBC_Production_Adapters::miner_checkout_url() );
		self::row( 'Last orphan sweep', (string) get_option( 'hbc_prod_last_sweep', 'not yet' ) );
		self::row( 'Universal entry', home_url( '/r' ) );
		self::row( 'Organize', home_url( '/organize' ) );
		self::row( 'Trade page', home_url( '/trade' ) );
		self::row( 'Super Admin', home_url( '/super-admin' ) );
		self::row( 'Print studio', home_url( '/print-studio' ) );
		self::row( 'Door QR RSVP', home_url( '/door/rsvp' ) );
		echo '</tbody></table>';

		echo '<h2>Organizations (multi-host)</h2>';
		echo '<p>New churches and communities register here. Unity / Kingdom are not hard-coded — they were demo labels only. Each org gets a unique public code for <code>/r?org=CODE</code>.</p>';
		$orgs = HBC_Prod_Orgs::list_organizations();
		if ( $orgs ) {
			echo '<table class="widefat striped" style="max-width:960px"><thead><tr><th>Name</th><th>Code</th><th>Entry</th><th>Status</th></tr></thead><tbody>';
			foreach ( $orgs as $org ) {
				echo '<tr><td>' . esc_html( (string) $org['name'] ) . '</td>';
				echo '<td><code>' . esc_html( (string) ( $org['public_code'] ?: '—' ) ) . '</code></td>';
				echo '<td><a href="' . esc_url( (string) $org['org_entry'] ) . '">' . esc_html( (string) $org['org_entry'] ) . '</a></td>';
				echo '<td>' . esc_html( (string) $org['status'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=hbc-human-gold' ) ) . '" style="max-width:640px;background:#fff;border:1px solid #c3c4c7;padding:16px 20px;margin:12px 0 24px">';
		echo '<input type="hidden" name="hbc_action" value="register_org" />';
		wp_nonce_field( 'hbc_register_org' );
		echo '<table class="form-table"><tbody>';
		self::field_text( 'org_name', 'Organization name', '', true );
		echo '<tr><th scope="row"><label for="hbc_org_type">Type</label></th><td><select name="org_type" id="hbc_org_type">';
		echo '<option value="church">Church</option><option value="community">Community</option><option value="nonprofit">Nonprofit</option>';
		echo '</select></td></tr>';
		self::field_text( 'public_code', 'Public code (optional)', '', false, 'text', '4–16 chars; unique. Leave blank to auto-suggest.' );
		echo '</tbody></table>';
		echo '<p class="submit"><button type="submit" class="button button-primary">Register organization</button></p></form>';

		echo '<h2>Print Materials</h2>';
		echo '<p><a class="button" href="' . esc_url( home_url( '/print-studio' ) ) . '" target="_blank">Open Print Studio</a> ';
		echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=hbc-print-studio' ) ) . '">Door QR links</a></p>';

		echo '<h2>Records &amp; reconciliation</h2>';
		echo '<form method="post" style="margin:12px 0">';
		echo '<input type="hidden" name="hbc_action" value="reconcile_snapshot" />';
		wp_nonce_field( 'hbc_reconcile_snapshot' );
		echo '<button type="submit" class="button">Generate this-month snapshot</button></form>';
		$snap = HBC_Prod_Orgs::reconcile_snapshot();
		echo '<pre style="max-width:720px;background:#fff;padding:12px;border:1px solid #c3c4c7;overflow:auto">' . esc_html( wp_json_encode( $snap, JSON_PRETTY_PRINT ) ) . '</pre>';

		if ( current_user_can( 'hbc_super_admin' ) ) {
			$allowed = HBC_Prod_Orgs::allowed_super_admin_ids();
			echo '<h2>Super Admin allowlist (max 2)</h2>';
			echo '<p>Only Coach Tom and the implementer. Enter up to two WordPress user IDs.</p>';
			echo '<form method="post"><input type="hidden" name="hbc_action" value="super_admin_allowlist" />';
			wp_nonce_field( 'hbc_super_admin_allowlist' );
			echo '<p><input class="regular-text" name="user_ids" value="' . esc_attr( implode( ', ', $allowed ) ) . '" /> ';
			echo '<button class="button button-primary" type="submit">Save allowlist</button></p></form>';
		}

		if ( current_user_can( 'hbc_super_admin' ) ) {
			$tstatus = HBC_Prod_Store::trade_status();
			echo '<h2>Trade Controls (Super Admin)</h2>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=hbc-human-gold' ) ) . '" style="max-width:640px;background:#fff;border:1px solid #c3c4c7;padding:16px 20px;margin:12px 0 24px">';
			echo '<input type="hidden" name="hbc_action" value="trade_gate" />';
			wp_nonce_field( 'hbc_trade_gate' );
			echo '<p>Platform: <strong>' . ( ! empty( $tstatus['platform_on'] ) ? 'LIVE' : 'OFF' ) . '</strong>';
			if ( ! empty( $tstatus['emergency_locked'] ) ) {
				echo ' · emergency until ' . esc_html( (string) $tstatus['emergency_lock_until'] );
			}
			echo '</p>';
			echo '<p><label>Reason <input class="regular-text" type="text" name="reason" required /></label></p>';
			echo '<p><label>Seller identity UUID (for authorize/revoke) <input class="regular-text" type="text" name="identity_uuid" /></label></p>';
			echo '<p>';
			echo '<button class="button button-primary" type="submit" name="trade_action" value="on">Turn ON</button> ';
			echo '<button class="button" type="submit" name="trade_action" value="off">Turn OFF</button> ';
			echo '<button class="button" type="submit" name="trade_action" value="emergency" onclick="return confirm(\'Emergency lock Trade OFF for 24 hours?\');">Emergency</button> ';
			echo '<button class="button" type="submit" name="trade_action" value="authorize_seller">Authorize seller</button> ';
			echo '<button class="button" type="submit" name="trade_action" value="revoke_seller">Revoke seller</button>';
			echo '</p></form>';
		}

		echo '<h2>Create event</h2>';
		echo '<p>Published events appear in <code>/r</code> search. Optional short code lets people open the event without searching (like <code>STONE</code>).</p>';
		if ( $host === '' ) {
			echo '<div class="notice notice-warning"><p>Pilot host is not seeded yet. Activate the production-integration plugin, then reload.</p></div>';
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=hbc-human-gold' ) ) . '" style="max-width:640px;background:#fff;border:1px solid #c3c4c7;padding:16px 20px;margin:12px 0 24px">';
			echo '<input type="hidden" name="hbc_action" value="create_event" />';
			echo '<input type="hidden" name="host_uuid" value="' . esc_attr( $host ) . '" />';
			wp_nonce_field( 'hbc_create_event' );
			echo '<table class="form-table" role="presentation"><tbody>';
			self::field_text( 'name', 'Event name', '', true );
			echo '<tr><th scope="row"><label for="hbc_event_type">Type</label></th><td><select name="event_type" id="hbc_event_type">';
			echo '<option value="community">Community / LAUGH gathering</option>';
			echo '<option value="practice_faith_touchstone">Practice FAITH Touchstone</option>';
			echo '</select></td></tr>';
			echo '<tr><th scope="row"><label for="hbc_event_category">Category</label></th><td><select name="event_category" id="hbc_event_category">';
			foreach ( HBC_Prod_Store::event_categories() as $key => $label ) {
				echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>';
			}
			echo '</select><p class="description">A category, including Fundraiser, is a label only. It does not create a ticket, a product, or a charge. The only public charge is the $12 Miner membership.</p></td></tr>';
			self::field_text( 'location', 'Location', '' );
			echo '<tr><th scope="row"><label for="hbc_description">Description</label></th><td><textarea name="description" id="hbc_description" class="large-text" rows="3"></textarea></td></tr>';
			echo '<tr><th scope="row"><label for="hbc_starts_at">Starts</label></th><td><input type="datetime-local" name="starts_at" id="hbc_starts_at" /></td></tr>';
			echo '<tr><th scope="row"><label for="hbc_ends_at">Ends</label></th><td><input type="datetime-local" name="ends_at" id="hbc_ends_at" /></td></tr>';
			self::field_text( 'capacity', 'Capacity (optional)', '', false, 'number' );
			self::field_text( 'event_code', 'Short event code (optional)', '', false, 'text', '4–16 chars, e.g. STONE2' );
			echo '<tr><th scope="row"><label for="hbc_status">Status</label></th><td><select name="status" id="hbc_status">';
			echo '<option value="published" selected>Published (visible on /r)</option>';
			echo '<option value="draft">Draft (hidden from /r)</option>';
			echo '</select></td></tr>';
			echo '<tr><th scope="row">RSVP choices</th><td>';
			echo '<label><input type="checkbox" name="allow_observe" value="1" checked /> Observe</label><br />';
			echo '<label><input type="checkbox" name="allow_participate" value="1" checked /> Participate</label>';
			echo '</td></tr>';
			echo '</tbody></table>';
			echo '<p class="submit"><button type="submit" class="button button-primary">Create event</button></p>';
			echo '</form>';
		}

		echo '<h2>Events &amp; calendar edit</h2>';
		$items = is_array( $list['events'] ?? null ) ? $list['events'] : array();
		if ( ! $items ) {
			echo '<p>No events yet.</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:960px"><thead><tr>';
			echo '<th>Name</th><th>Type</th><th>Status</th><th>Code</th><th>Starts</th><th>Location</th>';
			echo '</tr></thead><tbody>';
			foreach ( $items as $ev ) {
				echo '<tr>';
				echo '<td>' . esc_html( (string) ( $ev['name'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $ev['event_type'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $ev['status'] ?? '' ) ) . '</td>';
				echo '<td><code>' . esc_html( (string) ( $ev['event_code'] ?? '—' ) ) . '</code></td>';
				echo '<td>' . esc_html( (string) ( $ev['starts_at'] ?? '—' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $ev['location'] ?? '—' ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';

			$edit_uuid = isset( $_GET['edit'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['edit'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$edit_ev   = null;
			foreach ( $items as $ev ) {
				if ( (string) ( $ev['event_uuid'] ?? '' ) === $edit_uuid ) {
					$edit_ev = $ev;
					break;
				}
			}
			echo '<p>Edit an event: ';
			foreach ( $items as $ev ) {
				$url = add_query_arg(
					array(
						'page' => 'hbc-human-gold',
						'edit' => (string) ( $ev['event_uuid'] ?? '' ),
					),
					admin_url( 'admin.php' )
				);
				echo '<a href="' . esc_url( $url ) . '">' . esc_html( (string) ( $ev['name'] ?? 'Event' ) ) . '</a> · ';
			}
			echo '</p>';
			if ( is_array( $edit_ev ) && $host !== '' ) {
				$starts = preg_replace( '/:\d{2}$/', '', str_replace( ' ', 'T', substr( (string) ( $edit_ev['starts_at'] ?? '' ), 0, 16 ) ) );
				$ends   = preg_replace( '/:\d{2}$/', '', str_replace( ' ', 'T', substr( (string) ( $edit_ev['ends_at'] ?? '' ), 0, 16 ) ) );
				echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=hbc-human-gold&edit=' . rawurlencode( (string) $edit_ev['event_uuid'] ) ) ) . '" style="max-width:640px;background:#fff;border:1px solid #c3c4c7;padding:16px 20px;margin:12px 0 24px">';
				echo '<input type="hidden" name="hbc_action" value="update_event" />';
				echo '<input type="hidden" name="host_uuid" value="' . esc_attr( $host ) . '" />';
				echo '<input type="hidden" name="event_uuid" value="' . esc_attr( (string) $edit_ev['event_uuid'] ) . '" />';
				wp_nonce_field( 'hbc_update_event' );
				echo '<h3>Edit: ' . esc_html( (string) ( $edit_ev['name'] ?? '' ) ) . '</h3>';
				echo '<table class="form-table" role="presentation"><tbody>';
				self::field_text( 'name', 'Event name', (string) ( $edit_ev['name'] ?? '' ), true );
				self::field_text( 'location', 'Location', (string) ( $edit_ev['location'] ?? '' ) );
				echo '<tr><th scope="row"><label for="hbc_description">Description</label></th><td><textarea name="description" id="hbc_description" class="large-text" rows="3">' . esc_textarea( (string) ( $edit_ev['description'] ?? '' ) ) . '</textarea></td></tr>';
				echo '<tr><th scope="row"><label for="hbc_starts_at">Starts</label></th><td><input type="datetime-local" name="starts_at" id="hbc_starts_at" value="' . esc_attr( (string) $starts ) . '" /></td></tr>';
				echo '<tr><th scope="row"><label for="hbc_ends_at">Ends</label></th><td><input type="datetime-local" name="ends_at" id="hbc_ends_at" value="' . esc_attr( (string) $ends ) . '" /></td></tr>';
				self::field_text( 'event_code', 'Short event code', (string) ( $edit_ev['event_code'] ?? '' ) );
				echo '<tr><th scope="row"><label for="hbc_status">Status</label></th><td><select name="status" id="hbc_status">';
				foreach ( array( 'published', 'draft', 'cancelled' ) as $st ) {
					echo '<option value="' . esc_attr( $st ) . '"' . selected( (string) ( $edit_ev['status'] ?? '' ), $st, false ) . '>' . esc_html( $st ) . '</option>';
				}
				echo '</select></td></tr>';
				echo '<tr><th scope="row">RSVP choices</th><td>';
				echo '<label><input type="checkbox" name="allow_observe" value="1"' . checked( ! empty( $edit_ev['allow_observe'] ), true, false ) . ' /> Observe</label><br />';
				echo '<label><input type="checkbox" name="allow_participate" value="1"' . checked( ! empty( $edit_ev['allow_participate'] ), true, false ) . ' /> Participate</label>';
				echo '</td></tr></tbody></table>';
				echo '<p class="submit"><button type="submit" class="button button-primary">Save event</button></p></form>';
			}
		}

		if ( $host !== '' ) {
			$vols = HBC_Prod_Store::list_volunteers( $host );
			echo '<h2>Volunteer requests</h2>';
			$vitems = $vols['volunteers'] ?? array();
			if ( ! $vitems ) {
				echo '<p>No volunteer requests yet.</p>';
			} else {
				echo '<table class="widefat striped" style="max-width:960px"><thead><tr><th>Event</th><th>State</th><th>Identity</th><th>Actions</th></tr></thead><tbody>';
				foreach ( $vitems as $v ) {
					echo '<tr>';
					echo '<td>' . esc_html( (string) ( $v['event_name'] ?? '' ) ) . '</td>';
					echo '<td>' . esc_html( (string) ( $v['state'] ?? '' ) ) . '</td>';
					echo '<td><code>' . esc_html( (string) ( $v['identity_uuid'] ?? '' ) ) . '</code></td>';
					echo '<td>';
					if ( ( $v['state'] ?? '' ) === 'requested' ) {
						echo '<form method="post" style="display:inline">';
						echo '<input type="hidden" name="hbc_action" value="review_volunteer" />';
						echo '<input type="hidden" name="volunteer_uuid" value="' . esc_attr( (string) $v['volunteer_request_uuid'] ) . '" />';
						wp_nonce_field( 'hbc_review_volunteer' );
						echo '<button class="button button-primary" type="submit" name="decision" value="accepted">Accept</button> ';
						echo '<button class="button" type="submit" name="decision" value="declined">Decline</button>';
						echo '</form>';
					} else {
						echo '—';
					}
					echo '</td></tr>';
				}
				echo '</tbody></table>';
			}
		}

		echo '<h2>Readiness</h2><ul>';
		foreach ( ( $report['checks'] ?? array() ) as $name => $check ) {
			$ok = ! empty( $check['ok'] );
			echo '<li>' . ( $ok ? 'OK' : 'WAIT' ) . ' — ' . esc_html( $name ) . ' — ' . esc_html( (string) ( $check['message'] ?? '' ) ) . '</li>';
		}
		echo '</ul>';
		echo '<p><strong>Still by hand:</strong> QRTiger three universal URLs, Twilio, Tom Stripe/PayPal, two-phone venue test, staging HTTPS, Gate 1 acceptance.</p>';
		echo '</div>';
	}

	private static function field_text( string $name, string $label, string $value, bool $required = false, string $type = 'text', string $help = '' ): void {
		$id = 'hbc_' . $name;
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="' . esc_attr( $type ) . '" class="regular-text" name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '" value="' . esc_attr( $value ) . '"' . ( $required ? ' required' : '' ) . ' />';
		if ( $help !== '' ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	private static function row( string $label, string $value ): void {
		echo '<tr><th style="width:220px">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}
}
