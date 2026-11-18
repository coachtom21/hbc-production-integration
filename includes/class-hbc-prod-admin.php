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

	/**
	 * Host column on Users. Only an administrator can open that screen.
	 *
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public static function users_columns( array $columns ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $columns;
		}
		$with_phone = array();
		foreach ( $columns as $key => $label ) {
			$with_phone[ $key ] = $label;
			if ( $key === 'email' ) {
				$with_phone['hbc_phone'] = 'Phone';
			}
		}
		if ( ! isset( $with_phone['hbc_phone'] ) ) {
			$with_phone['hbc_phone'] = 'Phone';
		}
		$with_phone['hbc_host'] = 'Host';
		return $with_phone;
	}

	/**
	 * @param string $output
	 * @param string $column
	 * @param int    $user_id
	 */
	public static function users_column( $output, $column, $user_id ): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			return (string) $output;
		}
		if ( $column === 'hbc_phone' ) {
			$phone = self::user_phone( (int) $user_id );
			return $phone !== '' ? esc_html( $phone ) : '—';
		}
		if ( $column !== 'hbc_host' ) {
			return (string) $output;
		}
		$identity = (string) get_user_meta( (int) $user_id, 'hbc_identity_uuid', true );
		$orgs     = $identity !== '' ? ( HBC_Prod_Store::host_organizations_by_identity()[ $identity ] ?? array() ) : array();
		if ( ! $orgs ) {
			return '—';
		}
		return 'Host — ' . implode( ', ', $orgs );
	}

	private static function user_phone( int $user_id ): string {
		if ( class_exists( 'Cpm_Humanblockchain_Device_Registry' ) ) {
			$saved = Cpm_Humanblockchain_Device_Registry::get_phone_for_user( $user_id );
			$saved = is_string( $saved ) ? trim( $saved ) : '';
			if ( $saved !== '' ) {
				return $saved;
			}
		}
		$identity = (string) get_user_meta( $user_id, 'hbc_identity_uuid', true );
		if ( $identity === '' || ! class_exists( 'HBC_Prod_Store' ) ) {
			return '';
		}
		global $wpdb;
		$sealed = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT phone_ciphertext FROM {$wpdb->prefix}hbc_identities WHERE identity_uuid = %s",
				$identity
			)
		);
		if ( ! is_string( $sealed ) || $sealed === '' ) {
			return '';
		}
		return trim( (string) HBC_Prod_Store::unseal( $sealed ) );
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
		if ( $action === 'delete_event' ) {
			self::handle_delete_event_post();
			return;
		}
		if ( $action === 'review_volunteer' ) {
			self::handle_review_volunteer_post();
			return;
		}
		if ( $action === 'register_org' ) {
			self::handle_register_org_post();
			return;
		}
		if ( $action === 'delete_org' ) {
			self::handle_delete_org_post();
			return;
		}
		if ( $action === 'reset_device' ) {
			self::handle_reset_device_post();
			return;
		}
		if ( $action === 'reconcile_snapshot' && current_user_can( 'hbc_admin' ) ) {
			check_admin_referer( 'hbc_reconcile_snapshot' );
			$snap = HBC_Prod_Orgs::reconcile_snapshot();
			self::redirect_ops( 'Reconciliation snapshot saved for ' . (string) ( $snap['period'] ?? '' ) . '.' );
		}
		if ( $action === 'appoint_super_admin' && current_user_can( 'manage_options' ) ) {
			self::handle_appoint_super_admin_post();
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

	private static function handle_delete_org_post(): void {
		$host_uuid = sanitize_text_field( (string) ( $_POST['host_uuid'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		check_admin_referer( 'hbc_delete_org_' . $host_uuid );
		if ( ! current_user_can( 'manage_options' ) ) {
			self::redirect_ops( '', 'An Administrator deletes an organization.' );
		}
		if ( ! class_exists( 'HBC_Prod_Store' ) || ! HBC_Prod_Store::is_uuid( $host_uuid ) ) {
			self::redirect_ops( '', 'That organization could not be deleted.' );
		}
		global $wpdb;
		$name = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT name FROM {$wpdb->prefix}hbc_hosts WHERE host_uuid = %s",
				$host_uuid
			)
		);
		if ( $name === '' ) {
			self::redirect_ops( '', 'That organization could not be deleted.' );
		}
		$identities = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT identity_uuid FROM {$wpdb->prefix}hbc_host_assignments WHERE host_uuid = %s AND role_key = 'host'",
				$host_uuid
			)
		);
		HBC_Prod_Orgs::delete_organization( $host_uuid );
		foreach ( is_array( $identities ) ? $identities : array() as $identity ) {
			self::drop_host_role_if_unused( (string) $identity );
		}
		self::redirect_ops( $name . ' was deleted. The host account remains.' );
	}

	private static function drop_host_role_if_unused( string $identity ): void {
		if ( ! class_exists( 'HBC_Prod_Store' ) || ! HBC_Prod_Store::is_uuid( $identity ) ) {
			return;
		}
		global $wpdb;
		$still = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}hbc_host_assignments WHERE identity_uuid = %s AND role_key = 'host'",
				$identity
			)
		);
		if ( $still > 0 ) {
			return;
		}
		$users = get_users(
			array(
				'meta_key'   => 'hbc_identity_uuid',
				'meta_value' => $identity,
				'fields'     => 'ID',
			)
		);
		foreach ( $users as $user_id ) {
			$user = get_userdata( (int) $user_id );
			if ( $user instanceof WP_User ) {
				$user->remove_role( 'hbc_host' );
			}
		}
	}

	private static function handle_reset_device_post(): void {
		$uuid = sanitize_text_field( (string) ( $_POST['device_uuid'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		check_admin_referer( 'hbc_reset_device_' . $uuid );
		if ( ! current_user_can( 'hbc_admin' ) || ! HBC_Prod_Store::revoke_device( $uuid ) ) {
			self::redirect_ops( '', 'That device could not be reset.' );
		}
		self::redirect_ops( 'Device reset. That browser must register again on /r.' );
	}

	private static function handle_appoint_super_admin_post(): void {
		check_admin_referer( 'hbc_appoint_super_admin' );
		if ( ! current_user_can( 'manage_options' ) ) {
			self::redirect_ops( '', 'An Administrator chooses the Super Admin.' );
		}
		$user_id = isset( $_POST['user_id'] ) ? (int) $_POST['user_id'] : 0;
		$result  = HBC_Prod_Orgs::appoint_super_admin( $user_id );
		if ( ! empty( $result['error']['message'] ) ) {
			self::redirect_ops( '', (string) $result['error']['message'] );
		}
		self::redirect_ops( (string) ( $result['message'] ?? 'Super Admin saved.' ) );
	}

	private static function redirect_ops( string $ok = '', string $err = '' ): void {
		$args = array( 'page' => 'hbc-human-gold' );
		$tab  = isset( $_POST['hbc_tab'] ) ? sanitize_key( (string) wp_unslash( $_POST['hbc_tab'] ) ) : '';
		if ( $tab !== '' && isset( self::ops_tabs()[ $tab ] ) ) {
			$args['tab'] = $tab;
		}
		if ( $ok !== '' ) {
			$args['hbc_ok'] = rawurlencode( $ok );
		}
		if ( $err !== '' ) {
			$args['hbc_err'] = rawurlencode( $err );
		}
		$edit = isset( $_POST['hbc_edit'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['hbc_edit'] ) ) : '';
		if ( class_exists( 'HBC_Prod_Store' ) && HBC_Prod_Store::is_uuid( $edit ) ) {
			$args['edit'] = $edit;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * @return array<string, string>
	 */
	private static function ops_tabs(): array {
		$tabs = array(
			'events'        => 'Events',
			'devices'       => 'Devices',
			'organizations' => 'Organizations',
			'print'         => 'Print',
			'records'       => 'Records',
			'status'        => 'Status',
		);
		return $tabs;
	}

	private static function current_ops_tab(): string {
		$tabs = self::ops_tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : 'events';
		return isset( $tabs[ $tab ] ) ? $tab : 'events';
	}

	private static function ops_url( string $tab, array $extra = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => 'hbc-human-gold',
					'tab'  => $tab,
				),
				$extra
			),
			admin_url( 'admin.php' )
		);
	}

	private static function hidden_tab( string $tab ): void {
		echo '<input type="hidden" name="hbc_tab" value="' . esc_attr( $tab ) . '" />';
	}

	private static function handle_create_event_post(): void {
		check_admin_referer( 'hbc_create_event' );

		$host_uuid = sanitize_text_field( (string) ( $_POST['host_uuid'] ?? '' ) );
		if ( ! HBC_Prod_Store::is_uuid( $host_uuid ) || ! apply_filters( 'hbc_user_can_access_host', false, get_current_user_id(), $host_uuid ) ) {
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

	private static function handle_delete_event_post(): void {
		$event_uuid = sanitize_text_field( (string) ( $_POST['event_uuid'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$host_uuid  = sanitize_text_field( (string) ( $_POST['host_uuid'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		check_admin_referer( 'hbc_delete_event_' . $event_uuid );
		$identity = (string) get_user_meta( get_current_user_id(), 'hbc_identity_uuid', true );
		$mine     = HBC_Prod_Store::host_uuids_for_identity( $identity );
		if ( ! in_array( $host_uuid, $mine, true ) ) {
			self::redirect_ops( '', 'Only the host of this organization can delete this event.' );
		}
		$result = HBC_Prod_Store::delete_event( $host_uuid, $event_uuid );
		if ( ! empty( $result['error']['message'] ) ) {
			self::redirect_ops( '', (string) $result['error']['message'] );
		}
		self::redirect_ops( (string) ( $result['message'] ?? 'Event deleted.' ) );
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

	private static function render_super_admin_picker(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$chosen = HBC_Prod_Orgs::designated_super_admin_id();
		$users  = get_users(
			array(
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'number'  => 200,
			)
		);
		echo '<form method="post" class="hbc-super-admin-pick" action="' . esc_url( self::ops_url( self::current_ops_tab() ) ) . '">';
		echo '<input type="hidden" name="hbc_action" value="appoint_super_admin" />';
		self::hidden_tab( self::current_ops_tab() );
		wp_nonce_field( 'hbc_appoint_super_admin' );
		echo '<label for="hbc_super_admin_user">Super Admin</label>';
		echo '<select name="user_id" id="hbc_super_admin_user">';
		echo '<option value="">Select a user</option>';
		foreach ( $users as $user ) {
			if ( ! ( $user instanceof WP_User ) ) {
				continue;
			}
			$label = (string) $user->user_login;
			if ( $user->display_name !== '' && $user->display_name !== $user->user_login ) {
				$label .= ' — ' . $user->display_name;
			}
			echo '<option value="' . esc_attr( (string) $user->ID ) . '"' . selected( $chosen, (int) $user->ID, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<button type="submit" class="button">Save</button>';
		echo '</form>';
	}

	public static function render_ops(): void {
		if ( ! current_user_can( 'hbc_admin' ) ) {
			wp_die( esc_html__( 'Protected access required.', 'hbc-prod' ) );
		}
		$tab     = self::current_ops_tab();
		$ok_msg  = isset( $_GET['hbc_ok'] ) ? sanitize_text_field( rawurldecode( (string) wp_unslash( $_GET['hbc_ok'] ) ) ) : '';
		$err_msg = isset( $_GET['hbc_err'] ) ? sanitize_text_field( rawurldecode( (string) wp_unslash( $_GET['hbc_err'] ) ) ) : '';

		echo '<div class="wrap hbc-admin-wrap">';
		echo '<h1>Human Gold</h1>';
		if ( $ok_msg !== '' ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $ok_msg ) . '</p></div>';
		}
		if ( $err_msg !== '' ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $err_msg ) . '</p></div>';
		}
		echo '<nav class="nav-tab-wrapper hbc-admin-tabs" aria-label="Human Gold sections">';
		foreach ( self::ops_tabs() as $key => $label ) {
			$class = $key === $tab ? ' nav-tab nav-tab-active' : ' nav-tab';
			echo '<a href="' . esc_url( self::ops_url( $key ) ) . '" class="' . esc_attr( trim( $class ) ) . '">' . esc_html( $label ) . '</a>';
		}
		if ( current_user_can( 'manage_options' ) ) {
			self::render_super_admin_picker();
		}
		if ( current_user_can( 'hbc_super_admin' ) ) {
			echo '<a class="button button-primary hbc-super-admin-link" href="' . esc_url( home_url( '/super-admin' ) ) . '">Open Super Admin Console</a>';
		}
		echo '</nav>';
		$panel_class = 'hbc-admin-panel' . ( in_array( $tab, array( 'organizations', 'events' ), true ) ? ' hbc-admin-panel-wide' : '' );
		echo '<div class="' . esc_attr( $panel_class ) . '">';
		if ( $tab === 'devices' ) {
			self::render_devices_tab();
		} elseif ( $tab === 'organizations' ) {
			self::render_organizations_tab();
		} elseif ( $tab === 'print' ) {
			self::render_print_tab();
		} elseif ( $tab === 'records' ) {
			self::render_records_tab();
		} elseif ( $tab === 'status' ) {
			self::render_status_tab();
		} else {
			self::render_events_tab();
		}
		echo '</div></div>';
		echo '<style>
			.hbc-admin-tabs{display:flex;align-items:center;flex-wrap:wrap;margin-bottom:0}
			.hbc-super-admin-pick{margin-left:auto;display:flex;align-items:center;gap:6px}
			.hbc-super-admin-pick select{max-width:220px}
			.hbc-admin-tabs .hbc-super-admin-link{margin-left:8px}
			.hbc-admin-panel{background:#fff;border:1px solid #c3c4c7;border-top:0;padding:4px 20px 20px;max-width:980px}
			.hbc-admin-panel-wide{max-width:1180px}
			.hbc-org-table td{vertical-align:middle}
			.hbc-org-table .hbc-status-cell{white-space:nowrap}
			.hbc-status-pill{display:inline-block;padding:3px 10px;border-radius:999px;background:#dcdcde;color:#1d2327;font-size:12px;font-weight:600;line-height:1.5;letter-spacing:.01em}
			.hbc-status-pill.is-active{background:#1f8a4c;color:#fff}
			.hbc-admin-panel h2{margin-top:1.2em}
			.hbc-admin-panel .hbc-admin-card{max-width:720px;background:#f6f7f7;border:1px solid #dcdcde;padding:4px 16px 8px;margin:12px 0 20px}
			.hbc-events-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:1.2em}
			.hbc-events-head h2{margin:0}
			.hbc-events-table td{vertical-align:middle}
			.hbc-events-table .hbc-event-name{font-weight:600}
			.hbc-event-sub{color:#646970;margin:2px 0 0;font-size:12px;line-height:1.4}
			.hbc-event-actions{white-space:nowrap;text-align:right}
			.hbc-event-actions form{display:inline}
			.hbc-event-actions .button{margin:0 0 0 6px}
			.hbc-events-head .button{flex:0 0 auto}
			.hbc-event-back{margin:14px 0 4px}
			.hbc-event-summary{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin:8px 0 16px}
			.hbc-event-summary h2{margin:0 0 4px}
			.hbc-event-kicker{display:flex;align-items:center;gap:8px;flex:0 0 auto}
			.hbc-event-split{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin:0 0 16px}
			.hbc-event-block h3{margin:0 0 8px;font-size:14px}
			.hbc-event-block .hbc-event-sub{margin:0}
			.hbc-event-edit{max-width:760px}
			.hbc-event-edit .form-table{margin-top:0}
			.hbc-event-edit .form-table th{width:140px;padding:10px 10px 10px 0}
			.hbc-event-edit input.regular-text,
			.hbc-event-edit input[type="datetime-local"],
			.hbc-event-edit select,
			.hbc-event-edit textarea{width:100%;max-width:460px}
			.hbc-check{display:inline-block;margin:0 16px 0 0}
			@media (max-width:900px){.hbc-event-split{grid-template-columns:1fr}.hbc-event-summary{flex-wrap:wrap}}
			.hbc-modal[hidden]{display:none}
			.hbc-modal{position:fixed;inset:0;z-index:100000;display:flex;align-items:flex-start;justify-content:center;padding:48px 16px}
			.hbc-modal-backdrop{position:absolute;inset:0;background:rgba(0,0,0,.55)}
			.hbc-modal-panel{position:relative;background:#fff;width:min(720px,100%);max-height:calc(100vh - 96px);overflow:auto;padding:8px 20px 16px;border-radius:4px;box-shadow:0 8px 32px rgba(0,0,0,.25)}
			.hbc-modal-close{position:absolute;top:8px;right:8px;border:0;background:transparent;font-size:24px;line-height:1;cursor:pointer;color:#1d2327}
			body.hbc-modal-open{overflow:hidden}
		</style>';
	}

	/**
	 * Events this account should see, each tagged with its organization.
	 * A host sees the organizations they host. An administrator also sees every other organization.
	 *
	 * @return array{events: list<array<string, mixed>>, my_hosts: list<array{host_uuid: string, name: string}>, is_host: bool}
	 */
	private static function events_for_viewer(): array {
		$user_id  = get_current_user_id();
		$identity = (string) get_user_meta( $user_id, 'hbc_identity_uuid', true );
		$mine     = HBC_Prod_Store::host_uuids_for_identity( $identity );
		$see_all  = current_user_can( 'manage_options' );
		$orgs     = class_exists( 'HBC_Prod_Orgs' ) ? HBC_Prod_Orgs::list_organizations() : array();
		$events   = array();
		$my_hosts = array();
		foreach ( $orgs as $org ) {
			$uuid   = (string) ( $org['host_uuid'] ?? '' );
			$is_mine = in_array( $uuid, $mine, true );
			if ( $is_mine ) {
				$my_hosts[] = array(
					'host_uuid' => $uuid,
					'name'      => (string) ( $org['name'] ?? '' ),
				);
			}
			if ( ! $see_all && ! $is_mine ) {
				continue;
			}
			$list = HBC_Prod_Store::list_host_events( $uuid );
			foreach ( is_array( $list['events'] ?? null ) ? $list['events'] : array() as $ev ) {
				$ev['organization_name'] = (string) ( $org['name'] ?? '' );
				$ev['host_uuid']         = $uuid;
				$ev['viewer_is_host']    = $is_mine;
				$events[]                = $ev;
			}
		}
		return array(
			'events'   => $events,
			'my_hosts' => $my_hosts,
			'is_host'  => $my_hosts !== array(),
		);
	}

	private static function render_events_tab(): void {
		$view      = self::events_for_viewer();
		$items     = $view['events'];
		$my_hosts  = $view['my_hosts'];
		$edit_uuid = isset( $_GET['edit'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['edit'] ) ) : '';
		$edit_ev   = null;
		foreach ( $items as $ev ) {
			if ( (string) ( $ev['event_uuid'] ?? '' ) === $edit_uuid ) {
				$edit_ev = $ev;
				break;
			}
		}

		if ( is_array( $edit_ev ) ) {
			self::render_event_detail( $edit_ev, (string) ( $edit_ev['host_uuid'] ?? '' ) );
			return;
		}

		echo '<div class="hbc-events-head"><h2>Events</h2>';
		if ( $my_hosts ) {
			echo '<button type="button" class="button button-primary" id="hbc-open-create-event">Create event</button>';
		}
		echo '</div>';
		if ( $view['is_host'] ) {
			$names = array();
			foreach ( $my_hosts as $host ) {
				$names[] = (string) $host['name'];
			}
			echo '<p>You host <strong>' . esc_html( implode( ', ', $names ) ) . '</strong>. A published event appears on <a href="' . esc_url( home_url( '/r' ) ) . '">/r</a>.';
			if ( current_user_can( 'manage_options' ) ) {
				echo ' Every other organization is listed in its own row.';
			}
			echo '</p>';
		} elseif ( current_user_can( 'manage_options' ) ) {
			echo '<p>This account is not a host. Each event belongs to the organization shown in its row.</p>';
		} else {
			echo '<p>This account is not a host, so it has no events.</p>';
		}

		$by_event = array();
		$seen     = array();
		foreach ( $items as $ev ) {
			$host_uuid = (string) ( $ev['host_uuid'] ?? '' );
			if ( $host_uuid === '' || isset( $seen[ $host_uuid ] ) ) {
				continue;
			}
			$seen[ $host_uuid ] = true;
			$vols = HBC_Prod_Store::list_volunteers( $host_uuid );
			foreach ( is_array( $vols['volunteers'] ?? null ) ? $vols['volunteers'] : array() as $v ) {
				$by_event[ (string) ( $v['event_uuid'] ?? '' ) ][] = $v;
			}
		}

		if ( $my_hosts ) {
			echo '<div id="hbc-create-event-dialog" class="hbc-modal" hidden>';
			echo '<div class="hbc-modal-backdrop" data-hbc-close></div>';
			echo '<div class="hbc-modal-panel" role="dialog" aria-modal="true" aria-labelledby="hbc-create-event-title">';
			echo '<button type="button" class="hbc-modal-close" data-hbc-close aria-label="Close">&times;</button>';
			echo '<form method="post" action="' . esc_url( self::ops_url( 'events' ) ) . '">';
			echo '<input type="hidden" name="hbc_action" value="create_event" />';
			self::hidden_tab( 'events' );
			if ( count( $my_hosts ) === 1 ) {
				echo '<input type="hidden" name="host_uuid" value="' . esc_attr( (string) $my_hosts[0]['host_uuid'] ) . '" />';
			}
			wp_nonce_field( 'hbc_create_event' );
			echo '<h2 id="hbc-create-event-title">Create event</h2>';
			echo '<p class="description">Optional short code lets people open the event without searching (like <code>STONE</code>).</p>';
			echo '<table class="form-table" role="presentation"><tbody>';
			if ( count( $my_hosts ) > 1 ) {
				echo '<tr><th scope="row"><label for="hbc_create_host">Organization</label></th><td><select name="host_uuid" id="hbc_create_host">';
				foreach ( $my_hosts as $host ) {
					echo '<option value="' . esc_attr( (string) $host['host_uuid'] ) . '">' . esc_html( (string) $host['name'] ) . '</option>';
				}
				echo '</select></td></tr>';
			}
			self::field_text( 'name', 'Event name', '', true, 'text', '', 'hbc_create_name' );
			echo '<tr><th scope="row"><label for="hbc_create_event_type">Type</label></th><td><select name="event_type" id="hbc_create_event_type">';
			echo '<option value="community">Community / LAUGH gathering</option>';
			echo '<option value="practice_faith_touchstone">Practice FAITH Touchstone</option>';
			echo '</select></td></tr>';
			echo '<tr><th scope="row"><label for="hbc_create_event_category">Category</label></th><td><select name="event_category" id="hbc_create_event_category">';
			foreach ( HBC_Prod_Store::event_categories() as $key => $label ) {
				echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>';
			}
			echo '</select><p class="description">A category, including Fundraiser, is a label only. It does not create a ticket, a product, or a charge. The only public charge is the $12 Miner membership.</p></td></tr>';
			self::field_text( 'location', 'Location', '', false, 'text', '', 'hbc_create_location' );
			echo '<tr><th scope="row"><label for="hbc_create_description">Description</label></th><td><textarea name="description" id="hbc_create_description" class="large-text" rows="3"></textarea></td></tr>';
			echo '<tr><th scope="row"><label for="hbc_create_starts_at">Starts</label></th><td><input type="datetime-local" name="starts_at" id="hbc_create_starts_at" /></td></tr>';
			echo '<tr><th scope="row"><label for="hbc_create_ends_at">Ends</label></th><td><input type="datetime-local" name="ends_at" id="hbc_create_ends_at" /></td></tr>';
			self::field_text( 'capacity', 'Capacity (optional)', '', false, 'number', '', 'hbc_create_capacity' );
			self::field_text( 'event_code', 'Short event code (optional)', '', false, 'text', '4–16 chars, e.g. STONE2', 'hbc_create_event_code' );
			echo '<tr><th scope="row"><label for="hbc_create_status">Status</label></th><td><select name="status" id="hbc_create_status">';
			echo '<option value="published" selected>Published (visible on /r)</option>';
			echo '<option value="draft">Draft (hidden from /r)</option>';
			echo '</select></td></tr>';
			echo '<tr><th scope="row">RSVP choices</th><td>';
			echo '<label><input type="checkbox" name="allow_observe" value="1" checked /> Observe</label><br />';
			echo '<label><input type="checkbox" name="allow_participate" value="1" checked /> Participate</label>';
			echo '</td></tr>';
			echo '</tbody></table>';
			echo '<p class="submit"><button type="submit" class="button button-primary">Create event</button> ';
			echo '<button type="button" class="button" data-hbc-close>Cancel</button></p>';
			echo '</form></div></div>';
			echo '<script>
			(function () {
				var dialog = document.getElementById("hbc-create-event-dialog");
				var open = document.getElementById("hbc-open-create-event");
				if (!dialog || !open) return;
				function show() {
					dialog.hidden = false;
					document.body.classList.add("hbc-modal-open");
					var first = dialog.querySelector("input, select, textarea");
					if (first) first.focus();
				}
				function hide() {
					dialog.hidden = true;
					document.body.classList.remove("hbc-modal-open");
					open.focus();
				}
				open.addEventListener("click", show);
				dialog.querySelectorAll("[data-hbc-close]").forEach(function (el) {
					el.addEventListener("click", hide);
				});
				document.addEventListener("keydown", function (event) {
					if (event.key === "Escape" && !dialog.hidden) hide();
				});
			})();
			</script>';
		}

		if ( ! $items ) {
			echo '<p>No events yet.</p>';
		} else {
			echo '<table class="widefat striped hbc-events-table"><thead><tr>';
			echo '<th>Event</th><th>Organization</th><th>When</th><th>Status</th><th>Requests</th><th>Actions</th>';
			echo '</tr></thead><tbody>';
			foreach ( $items as $ev ) {
				$event_uuid = (string) ( $ev['event_uuid'] ?? '' );
				$edit_url   = self::ops_url( 'events', array( 'edit' => $event_uuid ) );
				$requests   = $by_event[ $event_uuid ] ?? array();
				$location   = trim( (string) ( $ev['location'] ?? '' ) );
				$code       = (string) ( $ev['event_code'] ?? '' );
				$status     = (string) ( $ev['status'] ?? '' );
				$meta       = array( self::event_type_label( (string) ( $ev['event_type'] ?? '' ) ) );
				if ( $code !== '' ) {
					$meta[] = $code;
				}
				if ( $location !== '' ) {
					$meta[] = $location;
				}
				echo '<tr>';
				echo '<td><a class="hbc-event-name" href="' . esc_url( $edit_url ) . '">' . esc_html( (string) ( $ev['name'] ?? '' ) ) . '</a>';
				echo '<p class="hbc-event-sub">' . esc_html( implode( ' · ', $meta ) ) . '</p></td>';
				echo '<td>' . esc_html( (string) ( $ev['organization_name'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( self::format_when( (string) ( $ev['starts_at'] ?? '' ) ) ) . '</td>';
				echo '<td>' . self::status_pill( $status ) . '</td>';
				echo '<td><a href="' . esc_url( $edit_url ) . '">' . esc_html( self::request_label( $requests ) ) . '</a></td>';
				echo '<td class="hbc-event-actions">';
				if ( $status === 'published' && ! empty( $ev['viewer_is_host'] ) ) {
					echo '<a class="button button-primary" href="' . esc_url( self::encounter_start_url( $event_uuid ) ) . '">Start encounter</a>';
				}
				echo '<a class="button" href="' . esc_url( $edit_url ) . '">Open</a>';
				if ( ! empty( $ev['viewer_is_host'] ) ) {
					$ask = wp_json_encode( 'Delete ' . (string) ( $ev['name'] ?? 'this event' ) . '? Its RSVPs and encounters are removed.' );
					echo '<form method="post" action="' . esc_url( self::ops_url( 'events' ) ) . '" onsubmit="return confirm(' . esc_attr( (string) $ask ) . ');">';
					echo '<input type="hidden" name="hbc_action" value="delete_event" />';
					self::hidden_tab( 'events' );
					echo '<input type="hidden" name="host_uuid" value="' . esc_attr( (string) ( $ev['host_uuid'] ?? '' ) ) . '" />';
					echo '<input type="hidden" name="event_uuid" value="' . esc_attr( $event_uuid ) . '" />';
					wp_nonce_field( 'hbc_delete_event_' . $event_uuid );
					echo '<button type="submit" class="button button-link-delete">Delete</button>';
					echo '</form>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}

	/**
	 * @param array<string, mixed> $event
	 */
	private static function render_event_detail( array $event, string $host ): void {
		$event_uuid = (string) ( $event['event_uuid'] ?? '' );
		$status     = (string) ( $event['status'] ?? '' );
		$code       = (string) ( $event['event_code'] ?? '' );
		$location   = trim( (string) ( $event['location'] ?? '' ) );
		$meta       = array();
		if ( (string) ( $event['organization_name'] ?? '' ) !== '' ) {
			$meta[] = (string) $event['organization_name'];
		}
		$meta[] = self::event_type_label( (string) ( $event['event_type'] ?? '' ) );
		if ( $code !== '' ) {
			$meta[] = $code;
		}
		$when = self::format_when( (string) ( $event['starts_at'] ?? '' ) );
		if ( $when !== '—' ) {
			$meta[] = $when;
		}
		echo '<p class="hbc-event-back"><a href="' . esc_url( self::ops_url( 'events' ) ) . '">&larr; All events</a></p>';
		echo '<div class="hbc-event-summary">';
		echo '<div><h2>' . esc_html( (string) ( $event['name'] ?? '' ) ) . '</h2>';
		echo '<p class="hbc-event-sub">' . esc_html( implode( ' · ', $meta ) ) . '</p>';
		if ( $location !== '' ) {
			echo '<p class="hbc-event-sub">' . esc_html( $location ) . '</p>';
		}
		echo '</div><div class="hbc-event-kicker">';
		echo self::status_pill( $status );
		if ( $status === 'published' && ! empty( $event['viewer_is_host'] ) ) {
			echo '<a class="button button-primary" href="' . esc_url( self::encounter_start_url( $event_uuid ) ) . '">Start encounter</a>';
		}
		echo '</div></div>';
		self::render_event_activity( $event_uuid );
		echo '<form method="post" class="hbc-admin-card hbc-event-edit" action="' . esc_url( self::ops_url( 'events', array( 'edit' => $event_uuid ) ) ) . '">';
		echo '<input type="hidden" name="hbc_action" value="update_event" />';
		self::hidden_tab( 'events' );
		echo '<input type="hidden" name="hbc_edit" value="' . esc_attr( $event_uuid ) . '" />';
		echo '<input type="hidden" name="host_uuid" value="' . esc_attr( $host ) . '" />';
		echo '<input type="hidden" name="event_uuid" value="' . esc_attr( $event_uuid ) . '" />';
		wp_nonce_field( 'hbc_update_event' );
		echo '<h3>Edit event</h3>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::field_text( 'name', 'Event name', (string) ( $event['name'] ?? '' ), true );
		self::field_text( 'location', 'Location', (string) ( $event['location'] ?? '' ) );
		echo '<tr><th scope="row"><label for="hbc_description">Description</label></th><td><textarea name="description" id="hbc_description" rows="3">' . esc_textarea( (string) ( $event['description'] ?? '' ) ) . '</textarea></td></tr>';
		echo '<tr><th scope="row"><label for="hbc_starts_at">Starts</label></th><td><input type="datetime-local" name="starts_at" id="hbc_starts_at" value="' . esc_attr( self::datetime_local_value( (string) ( $event['starts_at'] ?? '' ) ) ) . '" /></td></tr>';
		echo '<tr><th scope="row"><label for="hbc_ends_at">Ends</label></th><td><input type="datetime-local" name="ends_at" id="hbc_ends_at" value="' . esc_attr( self::datetime_local_value( (string) ( $event['ends_at'] ?? '' ) ) ) . '" /></td></tr>';
		self::field_text( 'event_code', 'Short event code', (string) ( $event['event_code'] ?? '' ) );
		echo '<tr><th scope="row"><label for="hbc_status">Status</label></th><td><select name="status" id="hbc_status">';
		foreach ( array( 'published', 'draft', 'cancelled' ) as $st ) {
			echo '<option value="' . esc_attr( $st ) . '"' . selected( $status, $st, false ) . '>' . esc_html( ucfirst( $st ) ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th scope="row">RSVP choices</th><td>';
		echo '<label class="hbc-check"><input type="checkbox" name="allow_observe" value="1"' . checked( ! empty( $event['allow_observe'] ), true, false ) . ' /> Observe</label>';
		echo '<label class="hbc-check"><input type="checkbox" name="allow_participate" value="1"' . checked( ! empty( $event['allow_participate'] ), true, false ) . ' /> Participate</label>';
		echo '</td></tr></tbody></table>';
		echo '<p class="submit"><button type="submit" class="button button-primary">Save event</button></p></form>';
		if ( ! empty( $event['viewer_is_host'] ) ) {
			$ask = wp_json_encode( 'Delete ' . (string) ( $event['name'] ?? 'this event' ) . '? Its RSVPs and encounters are removed.' );
			echo '<form method="post" action="' . esc_url( self::ops_url( 'events' ) ) . '" onsubmit="return confirm(' . esc_attr( (string) $ask ) . ');">';
			echo '<input type="hidden" name="hbc_action" value="delete_event" />';
			self::hidden_tab( 'events' );
			echo '<input type="hidden" name="host_uuid" value="' . esc_attr( $host ) . '" />';
			echo '<input type="hidden" name="event_uuid" value="' . esc_attr( $event_uuid ) . '" />';
			wp_nonce_field( 'hbc_delete_event_' . $event_uuid );
			echo '<p><button type="submit" class="button button-link-delete">Delete event</button></p></form>';
		}
	}

	private static function render_event_activity( string $event_uuid ): void {
		$activity = HBC_Prod_Store::activity_for_event( $event_uuid );
		$requests = $activity['requests'];
		echo '<div class="hbc-event-split">';
		echo '<section class="hbc-event-block"><h3>Volunteer requests (' . count( $requests ) . ')</h3>';
		if ( ! $requests ) {
			echo '<p class="hbc-event-sub">None for this event.</p>';
		} else {
			echo '<table class="widefat striped hbc-events-table"><thead><tr><th>Request</th><th>State</th><th>When</th><th></th></tr></thead><tbody>';
			foreach ( $requests as $v ) {
				$choice = (string) ( $v['choice'] ?? '' );
				$choice = $choice === 'participate' ? 'Participate' : ( $choice === 'walk_away' ? 'Walk away' : ( $choice === 'observe' ? 'Observe' : ucfirst( $choice ) ) );
				$state  = (string) ( $v['state'] ?? '' );
				if ( $state === '' ) {
					$state = (string) ( $v['status'] ?? '' );
				}
				echo '<tr>';
				echo '<td>' . esc_html( $choice ) . '</td>';
				echo '<td>' . self::status_pill( $state ) . '</td>';
				echo '<td>' . esc_html( self::format_when( (string) ( $v['created_at'] ?? '' ) ) ) . '</td>';
				echo '<td class="hbc-event-actions">';
				if ( $state === 'requested' && (string) ( $v['volunteer_request_uuid'] ?? '' ) !== '' ) {
					echo '<form method="post" action="' . esc_url( self::ops_url( 'events', array( 'edit' => $event_uuid ) ) ) . '">';
					echo '<input type="hidden" name="hbc_action" value="review_volunteer" />';
					self::hidden_tab( 'events' );
					echo '<input type="hidden" name="hbc_edit" value="' . esc_attr( $event_uuid ) . '" />';
					echo '<input type="hidden" name="volunteer_uuid" value="' . esc_attr( (string) $v['volunteer_request_uuid'] ) . '" />';
					echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'hbc_review_volunteer' ) ) . '" />';
					echo '<button class="button button-primary" type="submit" name="decision" value="accepted">Accept</button>';
					echo '<button class="button" type="submit" name="decision" value="declined">Decline</button>';
					echo '</form>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</section>';
		$total = (int) $activity['encounter_total'];
		echo '<section class="hbc-event-block"><h3>Encounters (' . $total . ')</h3>';
		if ( ! $total ) {
			echo '<p class="hbc-event-sub">None for this event.</p></section></div>';
			return;
		}
		echo '<table class="widefat striped hbc-events-table"><thead><tr><th>Type</th><th>State</th><th>When</th></tr></thead><tbody>';
		foreach ( $activity['encounters'] as $encounter ) {
			$type = (string) ( $encounter['encounter_type'] ?? '' );
			$type = $type === 'presence' ? 'Presence' : ( $type === 'gratitude' ? 'Gratitude' : ( $type === 'trade' ? 'Trade' : ucfirst( $type ) ) );
			echo '<tr>';
			echo '<td>' . esc_html( $type ) . '</td>';
			echo '<td>' . self::status_pill( (string) ( $encounter['state'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( self::format_when( (string) ( $encounter['issued_at'] ?? '' ) ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table></section></div>';
	}

	private static function encounter_start_url( string $event_uuid ): string {
		return add_query_arg(
			array(
				'event'       => $event_uuid,
				'console'     => '1',
				'hbc_console' => wp_create_nonce( 'hbc_console_' . $event_uuid ),
			),
			home_url( '/r/' )
		);
	}

	private static function event_type_label( string $type ): string {
		if ( $type === 'practice_faith_touchstone' ) {
			return 'Practice FAITH';
		}
		if ( $type === 'community' || $type === 'ordinary' ) {
			return 'Community';
		}
		return $type !== '' ? $type : 'Event';
	}

	private static function status_pill( string $status ): string {
		$good  = in_array( strtolower( $status ), array( 'published', 'active', 'accepted' ), true );
		$class = 'hbc-status-pill' . ( $good ? ' is-active' : '' );
		$label = $status !== '' ? ucfirst( str_replace( '_', ' ', $status ) ) : '—';
		return '<span class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
	}

	private static function datetime_local_value( string $iso ): string {
		if ( $iso === '' ) {
			return '';
		}
		$ts = strtotime( $iso );
		if ( ! $ts ) {
			return '';
		}
		return wp_date( 'Y-m-d\TH:i', $ts );
	}

	private static function format_when( string $iso ): string {
		if ( $iso === '' ) {
			return '—';
		}
		$ts = strtotime( $iso );
		if ( ! $ts ) {
			return $iso;
		}
		return wp_date( 'M j, Y g:i A', $ts );
	}

	/**
	 * @param list<array<string, mixed>> $requests
	 */
	private static function request_label( array $requests ): string {
		if ( ! $requests ) {
			return 'None';
		}
		$open = 0;
		foreach ( $requests as $v ) {
			if ( ( $v['state'] ?? '' ) === 'requested' ) {
				++$open;
			}
		}
		if ( $open === 1 ) {
			return '1 open';
		}
		if ( $open > 1 ) {
			return $open . ' open';
		}
		$count = count( $requests );
		return $count === 1 ? '1 closed' : $count . ' closed';
	}

	private static function render_devices_tab(): void {
		$rows         = HBC_Prod_Store::list_saved_devices();
		$insert_error = (string) get_option( 'hbc_last_device_insert_error', '' );
		echo '<h2>Devices</h2>';
		echo '<p>Phones and browsers that finished the verification code on <a href="' . esc_url( home_url( '/r' ) ) . '">/r</a>. Reset removes that saved device. The browser must register again.</p>';
		if ( $insert_error !== '' ) {
			echo '<div class="notice notice-error"><p>' . esc_html( 'Last device save failed: ' . $insert_error ) . '</p></div>';
		}
		if ( ! $rows ) {
			echo '<p>No device has finished registration yet.</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>Account</th><th>Phone</th><th>Role</th><th>Status</th><th>Verified</th><th>Last seen</th><th>Action</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) $row['account'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['phone'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['role'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['status'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['verified_at'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['last_seen_at'] ) . '</td>';
			echo '<td>';
			if ( (string) $row['status'] === 'active' && (string) $row['device_uuid'] !== '' ) {
				echo '<form method="post" action="' . esc_url( self::ops_url( 'devices' ) ) . '" style="display:inline">';
				echo '<input type="hidden" name="hbc_action" value="reset_device" />';
				self::hidden_tab( 'devices' );
				echo '<input type="hidden" name="device_uuid" value="' . esc_attr( (string) $row['device_uuid'] ) . '" />';
				wp_nonce_field( 'hbc_reset_device_' . (string) $row['device_uuid'] );
				echo '<button type="submit" class="button" onclick="return confirm(\'Reset this device? That browser must register again.\');">Reset</button>';
				echo '</form>';
			} else {
				echo '—';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_organizations_tab(): void {
		echo '<div class="hbc-events-head"><h2>Organizations</h2>';
		echo '<button type="button" class="button button-primary" id="hbc-open-register-org">Register organization</button>';
		echo '</div>';
		echo '<p>Register a church or community here. Each one gets a public code for <code>/r?org=CODE</code>.</p>';
		$orgs    = HBC_Prod_Orgs::list_organizations();
		$members = HBC_Prod_Store::members_by_organization();
		if ( $orgs ) {
			echo '<table class="widefat striped hbc-org-table"><thead><tr><th>Name</th><th>Code</th><th>Entry</th><th>People</th><th>Email</th><th>Phone</th><th>Status</th><th></th></tr></thead><tbody>';
			foreach ( $orgs as $org ) {
				echo '<tr><td>' . esc_html( (string) $org['name'] ) . '</td>';
				echo '<td><code>' . esc_html( (string) ( $org['public_code'] ?: '—' ) ) . '</code></td>';
				echo '<td><a href="' . esc_url( (string) $org['org_entry'] ) . '">' . esc_html( (string) $org['org_entry'] ) . '</a></td>';
				$people = $members[ (string) $org['host_uuid'] ] ?? array();
				if ( ! $people ) {
					echo '<td>—</td><td>—</td><td>—</td>';
				} else {
					echo '<td>';
					foreach ( $people as $person ) {
						echo '<div style="margin:0 0 8px"><strong>' . esc_html( (string) $person['role'] ) . '</strong> ' . esc_html( (string) $person['username'] ) . '</div>';
					}
					echo '</td><td>';
					foreach ( $people as $person ) {
						echo '<div style="margin:0 0 8px">' . esc_html( (string) $person['email'] ) . '</div>';
					}
					echo '</td><td>';
					foreach ( $people as $person ) {
						echo '<div style="margin:0 0 8px">' . esc_html( (string) $person['phone'] ) . '</div>';
					}
					echo '</td>';
				}
				$status = (string) $org['status'];
				$pill   = 'hbc-status-pill' . ( $status === 'active' ? ' is-active' : '' );
				$label  = $status !== '' ? ucfirst( $status ) : '—';
				echo '<td class="hbc-status-cell"><span class="' . esc_attr( $pill ) . '">' . esc_html( $label ) . '</span></td>';
				echo '<td class="hbc-event-actions">';
				if ( current_user_can( 'manage_options' ) ) {
					$host_uuid = (string) $org['host_uuid'];
					$ask       = wp_json_encode( 'Delete ' . (string) $org['name'] . '? Its events and public code are removed. The host account stays.' );
					echo '<form method="post" action="' . esc_url( self::ops_url( 'organizations' ) ) . '" onsubmit="return confirm(' . esc_attr( (string) $ask ) . ');">';
					echo '<input type="hidden" name="hbc_action" value="delete_org" />';
					self::hidden_tab( 'organizations' );
					echo '<input type="hidden" name="host_uuid" value="' . esc_attr( $host_uuid ) . '" />';
					wp_nonce_field( 'hbc_delete_org_' . $host_uuid );
					echo '<button type="submit" class="button button-link-delete">Delete</button>';
					echo '</form>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>No organizations yet.</p>';
		}

		echo '<div id="hbc-register-org-dialog" class="hbc-modal" hidden>';
		echo '<div class="hbc-modal-backdrop" data-hbc-close></div>';
		echo '<div class="hbc-modal-panel" role="dialog" aria-modal="true" aria-labelledby="hbc-register-org-title">';
		echo '<button type="button" class="hbc-modal-close" data-hbc-close aria-label="Close">&times;</button>';
		echo '<form method="post" action="' . esc_url( self::ops_url( 'organizations' ) ) . '">';
		echo '<input type="hidden" name="hbc_action" value="register_org" />';
		self::hidden_tab( 'organizations' );
		wp_nonce_field( 'hbc_register_org' );
		echo '<h2 id="hbc-register-org-title">Register organization</h2>';
		echo '<table class="form-table"><tbody>';
		self::field_text( 'org_name', 'Organization name', '', true );
		echo '<tr><th scope="row"><label for="hbc_org_type">Type</label></th><td><select name="org_type" id="hbc_org_type">';
		echo '<option value="church">Church</option><option value="community">Community</option><option value="nonprofit">Nonprofit</option>';
		echo '</select></td></tr>';
		self::field_text( 'public_code', 'Public code (optional)', '', false, 'text', '4–16 characters. It must be different from every other organization. Leave blank to choose one automatically.' );
		echo '</tbody></table>';
		echo '<p class="submit"><button type="submit" class="button button-primary">Register organization</button> ';
		echo '<button type="button" class="button" data-hbc-close>Cancel</button></p>';
		echo '</form></div></div>';
		echo '<script>
		(function () {
			var dialog = document.getElementById("hbc-register-org-dialog");
			var open = document.getElementById("hbc-open-register-org");
			if (!dialog || !open) return;
			function show() {
				dialog.hidden = false;
				document.body.classList.add("hbc-modal-open");
				var first = dialog.querySelector("input[name=org_name]");
				if (first) first.focus();
			}
			function hide() {
				dialog.hidden = true;
				document.body.classList.remove("hbc-modal-open");
				open.focus();
			}
			open.addEventListener("click", show);
			dialog.querySelectorAll("[data-hbc-close]").forEach(function (el) {
				el.addEventListener("click", hide);
			});
			document.addEventListener("keydown", function (event) {
				if (event.key === "Escape" && !dialog.hidden) hide();
			});
		})();
		</script>';
	}

	private static function render_print_tab(): void {
		echo '<h2>Print</h2>';
		echo '<p>Postcards. The postcard QR points at <code>/r</code>.</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( home_url( '/print-studio' ) ) . '" target="_blank" rel="noopener noreferrer">Open Print Studio</a></p>';
	}

	private static function render_records_tab(): void {
		echo '<h2>Records</h2>';
		echo '<p>Save a snapshot of this month’s records.</p>';
		echo '<form method="post" action="' . esc_url( self::ops_url( 'records' ) ) . '">';
		echo '<input type="hidden" name="hbc_action" value="reconcile_snapshot" />';
		self::hidden_tab( 'records' );
		wp_nonce_field( 'hbc_reconcile_snapshot' );
		echo '<p><button type="submit" class="button button-primary">Generate this-month snapshot</button></p></form>';
		$snap = HBC_Prod_Orgs::reconcile_snapshot();
		echo '<pre style="max-width:720px;background:#f6f7f7;padding:12px;border:1px solid #dcdcde;overflow:auto">' . esc_html( wp_json_encode( $snap, JSON_PRETTY_PRINT ) ) . '</pre>';
	}

	private static function render_status_tab(): void {
		$report = class_exists( 'HBC_Laugh_Readiness' ) ? HBC_Laugh_Readiness::report() : array();
		global $wpdb;
		$events = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}hbc_events WHERE status = 'published'" );
		$rsvps  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}hbc_rsvps WHERE status = 'completed'" );
		$open   = class_exists( 'HBC_Laugh_Readiness' ) && HBC_Laugh_Readiness::registration_is_open();
		$trade  = HBC_Prod_Store::trade_is_unlocked() ? 'OPEN' : 'LOCKED';
		$host   = HBC_Prod_Store::host_uuid();
		$list   = $host ? HBC_Prod_Store::list_host_events( $host ) : array( 'events' => array(), 'host_name' => '' );

		echo '<h2>Status</h2>';
		echo '<p>RSVP entry is <a href="' . esc_url( home_url( '/r' ) ) . '"><code>/r</code></a>. Trade is <strong>' . esc_html( $trade ) . '</strong>.</p>';
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

		echo '<h2>Readiness</h2><ul>';
		foreach ( ( $report['checks'] ?? array() ) as $name => $check ) {
			$ok = ! empty( $check['ok'] );
			echo '<li>' . ( $ok ? 'OK' : 'WAIT' ) . ' — ' . esc_html( $name ) . ' — ' . esc_html( (string) ( $check['message'] ?? '' ) ) . '</li>';
		}
		echo '</ul>';
		echo '<p><strong>Still by hand:</strong> QRTiger three universal URLs, Twilio, Tom Stripe/PayPal, two-phone venue test, staging HTTPS, Gate 1 acceptance.</p>';
	}

	private static function field_text( string $name, string $label, string $value, bool $required = false, string $type = 'text', string $help = '', string $id = '' ): void {
		if ( $id === '' ) {
			$id = 'hbc_' . $name;
		}
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
