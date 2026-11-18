<?php
defined( 'ABSPATH' ) || exit;

/**
 * Multi-organization registry, Super Admin cap, door QRs, and reconciliation snapshots.
 * Extends the live LAUGH stack — does not replace pod-ledger.
 */
final class HBC_Prod_Orgs {
	public const ORG_CODES_OPTION     = 'hbc_org_public_codes';
	public const SUPER_ADMIN_MAX      = 1;
	public const SUPER_ADMIN_ALLOWLIST = 'hbc_super_admin_user_ids';
	public const RECONCILE_OPTION     = 'hbc_reconcile_snapshots';

	public static function register(): void {
		add_action( 'user_register', array( __CLASS__, 'guard_super_admin_cap' ), 20 );
		add_action( 'profile_update', array( __CLASS__, 'guard_super_admin_cap' ), 20 );
		add_action( 'set_user_role', array( __CLASS__, 'on_set_role' ), 20, 3 );
		add_action( 'delete_user', array( __CLASS__, 'delete_user_organizations' ) );
		add_filter( 'hbc_list_public_events', array( __CLASS__, 'filter_public_events_by_org' ), 5, 2 );
	}

	/**
	 * Remove every organization this user hosts. Their public code and events go with it.
	 */
	public static function delete_user_organizations( int $user_id ): void {
		$identity = (string) get_user_meta( $user_id, 'hbc_identity_uuid', true );
		if ( class_exists( 'HBC_Prod_Store' ) && HBC_Prod_Store::is_uuid( $identity ) ) {
			global $wpdb;
			$host_uuids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT host_uuid FROM {$wpdb->prefix}hbc_host_assignments WHERE identity_uuid = %s AND role_key = 'host'",
					$identity
				)
			);
			foreach ( is_array( $host_uuids ) ? $host_uuids : array() as $host_uuid ) {
				self::delete_organization( (string) $host_uuid );
			}
			$wpdb->delete(
				$wpdb->prefix . 'hbc_host_assignments',
				array( 'identity_uuid' => $identity )
			);
		}
		$allowed = self::allowed_super_admin_ids();
		if ( in_array( $user_id, $allowed, true ) ) {
			update_option( self::SUPER_ADMIN_ALLOWLIST, array(), false );
		}
	}

	public static function delete_organization( string $host_uuid ): void {
		if ( ! class_exists( 'HBC_Prod_Store' ) || ! HBC_Prod_Store::is_uuid( $host_uuid ) ) {
			return;
		}
		global $wpdb;
		$prefix = $wpdb->prefix;
		if ( self::table_exists( $prefix . 'hbc_events' ) && self::table_exists( $prefix . 'hbc_event_codes' ) ) {
			$event_ids = $wpdb->get_col( $wpdb->prepare( "SELECT event_uuid FROM {$prefix}hbc_events WHERE host_uuid = %s", $host_uuid ) );
			if ( is_array( $event_ids ) && $event_ids ) {
				$placeholders = implode( ',', array_fill( 0, count( $event_ids ), '%s' ) );
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}hbc_event_codes WHERE event_uuid IN ({$placeholders})", ...$event_ids ) );
			}
		}
		foreach ( array( 'hbc_rsvps', 'hbc_volunteer_requests', 'hbc_attendance_sessions', 'hbc_access_requests', 'hbc_events', 'hbc_host_assignments', 'hbc_hosts' ) as $table ) {
			self::delete_rows_for_host( $prefix . $table, $host_uuid );
		}
		$code = self::public_code_for_host( $host_uuid );
		if ( $code !== '' ) {
			self::release_code( $code, $host_uuid );
		}
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	private static function delete_rows_for_host( string $table, string $host_uuid ): void {
		global $wpdb;
		if ( ! self::table_exists( $table ) ) {
			return;
		}
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
		if ( ! is_array( $columns ) || ! in_array( 'host_uuid', $columns, true ) ) {
			return;
		}
		$wpdb->delete( $table, array( 'host_uuid' => $host_uuid ) );
	}

	/**
	 * @return array<string, string> public_code => host_uuid
	 */
	public static function code_map(): array {
		$raw = get_option( self::ORG_CODES_OPTION, array() );
		return is_array( $raw ) ? $raw : array();
	}

	public static function public_code_for_host( string $host_uuid ): string {
		foreach ( self::code_map() as $code => $uuid ) {
			if ( $uuid === $host_uuid ) {
				return (string) $code;
			}
		}
		return '';
	}

	public static function host_uuid_for_code( string $code ): string {
		$code = self::normalize_code( $code );
		if ( $code === '' ) {
			return '';
		}
		foreach ( self::code_map() as $existing => $uuid ) {
			if ( self::normalize_code( (string) $existing ) === $code && (string) $uuid !== '' ) {
				return (string) $uuid;
			}
		}
		return '';
	}

	public static function normalize_code( string $code ): string {
		$clean = preg_replace( '/[^A-Z0-9-]/', '', strtoupper( $code ) );
		return is_string( $clean ) ? $clean : '';
	}

	/**
	 * Register a new organization/host with a unique public QR code.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public static function register_organization( array $payload, int $actor_user_id = 0 ): array {
		$name = sanitize_text_field( (string) ( $payload['name'] ?? '' ) );
		$type = sanitize_key( (string) ( $payload['type'] ?? 'church' ) );
		$supplied = self::normalize_code( (string) ( $payload['public_code'] ?? '' ) );
		if ( $name === '' || strlen( $name ) > 180 ) {
			return array(
				'error' => array(
					'code'    => 'validation_error',
					'message' => 'Enter an organization name (max 180 characters).',
				),
			);
		}
		if ( $supplied === '' ) {
			$code = self::suggest_code( $name );
			if ( $code === '' ) {
				return array(
					'error' => array(
						'code'    => 'duplicate_public_code',
						'message' => 'A unique public code could not be chosen. Enter one that no other organization uses.',
					),
				);
			}
		} else {
			$code = $supplied;
			if ( strlen( $code ) < 4 || strlen( $code ) > 16 ) {
				return array(
					'error' => array(
						'code'    => 'validation_error',
						'message' => 'Public organization code must be 4–16 letters/numbers.',
					),
				);
			}
			if ( self::host_uuid_for_code( $code ) !== '' ) {
				return array(
					'error' => array(
						'code'    => 'duplicate_public_code',
						'message' => 'That public code is already used by another organization. Choose a different code.',
					),
				);
			}
		}

		$creator = '00000000-0000-4000-8000-000000000001';
		if ( $actor_user_id > 0 ) {
			$meta = (string) get_user_meta( $actor_user_id, 'hbc_identity_uuid', true );
			if ( HBC_Prod_Store::is_uuid( $meta ) ) {
				$creator = $meta;
			}
		}

		$host_uuid = HBC_Prod_Store::uuid();
		$now       = gmdate( 'Y-m-d H:i:s' );
		if ( ! self::claim_code( $code, $host_uuid ) ) {
			return array(
				'error' => array(
					'code'    => 'duplicate_public_code',
					'message' => 'That public code is already used by another organization. Choose a different code.',
				),
			);
		}
		global $wpdb;
		$ok = $wpdb->insert(
			$wpdb->prefix . 'hbc_hosts',
			array(
				'host_uuid'               => $host_uuid,
				'name'                    => $name,
				'type'                    => in_array( $type, array( 'church', 'community', 'nonprofit' ), true ) ? $type : 'church',
				'status'                  => 'active',
				'timezone'                => 'America/New_York',
				'created_by_identity_uuid'=> $creator,
				'created_at'              => $now,
			)
		);
		if ( ! $ok ) {
			self::release_code( $code, $host_uuid );
			return array(
				'error' => array(
					'code'    => 'create_failed',
					'message' => 'Could not create the organization.',
				),
			);
		}

		// Assign creator as host for this org when they have an identity.
		if ( HBC_Prod_Store::is_uuid( $creator ) && $creator !== '00000000-0000-4000-8000-000000000001' ) {
			$wpdb->insert(
				$wpdb->prefix . 'hbc_host_assignments',
				array(
					'assignment_uuid' => HBC_Prod_Store::uuid(),
					'host_uuid'       => $host_uuid,
					'identity_uuid'   => $creator,
					'role_key'        => 'host',
					'scope_type'      => 'organization',
					'scope_uuid'      => $host_uuid,
					'starts_at'       => $now,
					'ends_at'         => null,
					'revoked_at'      => null,
				)
			);
		}

		// The person who registers the organization is its Host.
		if ( $actor_user_id > 0 ) {
			$user = new WP_User( $actor_user_id );
			if ( $user->exists() ) {
				$user->add_role( 'hbc_host' );
			}
		}

		return array(
			'host_uuid'   => $host_uuid,
			'name'        => $name,
			'public_code' => $code,
			'org_entry'   => home_url( '/r?org=' . rawurlencode( $code ) ),
			'message'     => 'Organization registered. Share public code ' . $code . ' or the /r?org= link.',
		);
	}

	private static function suggest_code( string $name ): string {
		$base = preg_replace( '/[^A-Z0-9]/', '', strtoupper( $name ) );
		$base = substr( is_string( $base ) && $base !== '' ? $base : 'ORG', 0, 8 );
		if ( strlen( $base ) < 4 ) {
			$base = str_pad( $base, 4, 'X' );
		}
		if ( self::host_uuid_for_code( $base ) === '' ) {
			return $base;
		}
		for ( $n = 2; $n < 100; $n++ ) {
			$code = substr( $base, 0, 6 ) . $n;
			if ( self::host_uuid_for_code( $code ) === '' ) {
				return $code;
			}
		}
		for ( $n = 0; $n < 20; $n++ ) {
			$code = self::normalize_code( substr( $base, 0, 6 ) . wp_generate_password( 4, false, false ) );
			if ( strlen( $code ) >= 4 && strlen( $code ) <= 16 && self::host_uuid_for_code( $code ) === '' ) {
				return $code;
			}
		}
		return '';
	}

	/**
	 * Reserve a public code for this organization before the row is saved.
	 * A code already used by another organization is left untouched.
	 */
	private static function claim_code( string $code, string $host_uuid ): bool {
		$code = self::normalize_code( $code );
		if ( $code === '' || $host_uuid === '' ) {
			return false;
		}
		$map = array();
		foreach ( self::code_map() as $existing => $uuid ) {
			$key = self::normalize_code( (string) $existing );
			$uuid = (string) $uuid;
			if ( $key === '' || $uuid === '' || isset( $map[ $key ] ) ) {
				continue;
			}
			if ( $key === $code ) {
				return false;
			}
			$map[ $key ] = $uuid;
		}
		$map[ $code ] = $host_uuid;
		update_option( self::ORG_CODES_OPTION, $map, false );
		$saved = self::host_uuid_for_code( $code );
		return $saved === $host_uuid;
	}

	private static function release_code( string $code, string $host_uuid ): void {
		$code = self::normalize_code( $code );
		if ( $code === '' || self::host_uuid_for_code( $code ) !== $host_uuid ) {
			return;
		}
		$map = self::code_map();
		foreach ( array_keys( $map ) as $existing ) {
			if ( self::normalize_code( (string) $existing ) === $code ) {
				unset( $map[ $existing ] );
			}
		}
		update_option( self::ORG_CODES_OPTION, $map, false );
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function list_organizations(): array {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT host_uuid, name, type, status, created_at FROM {$wpdb->prefix}hbc_hosts ORDER BY name ASC LIMIT 200" );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'host_uuid'   => $row->host_uuid,
				'name'        => $row->name,
				'type'        => $row->type,
				'status'      => $row->status,
				'public_code' => self::public_code_for_host( (string) $row->host_uuid ),
				'org_entry'   => self::public_code_for_host( (string) $row->host_uuid )
					? home_url( '/r?org=' . rawurlencode( self::public_code_for_host( (string) $row->host_uuid ) ) )
					: home_url( '/r' ),
			);
		}
		return $out;
	}

	/**
	 * Ensure seed host has a public code (KINGCITY sample only as first code, not exclusive).
	 */
	public static function ensure_seed_code(): void {
		$host = HBC_Prod_Store::host_uuid();
		if ( $host === '' || self::public_code_for_host( $host ) !== '' ) {
			return;
		}
		$map            = self::code_map();
		$code           = isset( $map['STONE'] ) ? 'PILOT' : 'STONE';
		if ( isset( $map[ $code ] ) ) {
			$code = 'ORG1';
		}
		$map[ $code ] = $host;
		update_option( self::ORG_CODES_OPTION, $map, false );
	}

	/**
	 * Prefetch org filter into list_public_events args (adapter still does the query).
	 *
	 * @param mixed                $default
	 * @param array<string, mixed> $args
	 * @return mixed
	 */
	public static function filter_public_events_by_org( $default, $args ) {
		// Handled inside store when host_uuid passed — see list_public_events update.
		return $default;
	}

	public static function allowed_super_admin_ids(): array {
		$ids = get_option( self::SUPER_ADMIN_ALLOWLIST, array() );
		if ( ! is_array( $ids ) ) {
			return array();
		}
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		return array_slice( $ids, 0, self::SUPER_ADMIN_MAX );
	}

	public static function designated_super_admin_id(): int {
		$users = get_users(
			array(
				'role'   => 'hbc_super_admin',
				'fields' => 'ID',
				'number' => 1,
				'orderby'=> 'ID',
				'order'  => 'ASC',
			)
		);
		return $users ? (int) $users[0] : 0;
	}

	/**
	 * A WordPress Administrator appoints one user as Super Admin.
	 * That person keeps Host or Subscriber. The previous Super Admin loses only that role.
	 *
	 * @return array<string, mixed>
	 */
	public static function appoint_super_admin( int $user_id ): array {
		$user = get_userdata( $user_id );
		if ( ! ( $user instanceof WP_User ) ) {
			return array(
				'error' => array(
					'code'    => 'validation_error',
					'message' => 'Choose a user to make Super Admin.',
				),
			);
		}
		$others = get_users(
			array(
				'role'    => 'hbc_super_admin',
				'fields'  => 'ID',
				'number'  => 50,
				'exclude' => array( $user_id ),
			)
		);
		foreach ( $others as $id ) {
			$old = new WP_User( (int) $id );
			$old->remove_role( 'hbc_super_admin' );
		}
		$chosen = new WP_User( $user_id );
		$chosen->add_role( 'hbc_super_admin' );
		update_option( self::SUPER_ADMIN_ALLOWLIST, array( $user_id ), false );
		$name = $chosen->display_name !== '' ? $chosen->display_name : $chosen->user_login;
		return array(
			'user_id' => $user_id,
			'message' => $name . ' is the Super Admin.',
		);
	}

	public static function set_super_admin_allowlist( array $user_ids ): array {
		$user_ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );
		if ( count( $user_ids ) !== 1 ) {
			return array(
				'error' => array(
					'code'    => 'super_admin_limit',
					'message' => 'Choose one user to be Super Admin.',
				),
			);
		}
		return self::appoint_super_admin( (int) $user_ids[0] );
	}

	public static function guard_super_admin_cap( int $user_id ): void {
		$user = new WP_User( $user_id );
		if ( ! in_array( 'hbc_super_admin', (array) $user->roles, true ) ) {
			return;
		}
		if ( self::designated_super_admin_id() === $user_id && in_array( $user_id, self::allowed_super_admin_ids(), true ) ) {
			return;
		}
		self::appoint_super_admin( $user_id );
	}

	public static function on_set_role( int $user_id, string $role, array $old ): void {
		unset( $role, $old );
		self::guard_super_admin_cap( $user_id );
	}

	/**
	 * Roles are Super Admin, WordPress Administrator, and Host.
	 * Host is the person who has an organization registered.
	 */
	public static function ensure_roles(): void {
		$roles   = wp_roles();
		$changed = false;
		if ( ! $roles->is_role( 'hbc_super_admin' ) ) {
			add_role(
				'hbc_super_admin',
				'Super Admin',
				array(
					'read'            => true,
					'hbc_super_admin' => true,
				)
			);
			$roles = wp_roles();
		} elseif ( ( $roles->role_names['hbc_super_admin'] ?? '' ) !== 'Super Admin' ) {
			$roles->roles['hbc_super_admin']['name'] = 'Super Admin';
			$roles->role_names['hbc_super_admin']    = 'Super Admin';
			$changed                                 = true;
		}
		if ( ! $roles->is_role( 'hbc_host' ) ) {
			add_role(
				'hbc_host',
				'Host',
				array(
					'read'      => true,
					'hbc_admin' => true,
				)
			);
			$roles = wp_roles();
		} elseif ( ( $roles->role_names['hbc_host'] ?? '' ) !== 'Host' ) {
			$roles->roles['hbc_host']['name'] = 'Host';
			$roles->role_names['hbc_host']    = 'Host';
			$changed                          = true;
		}
		if ( $changed ) {
			update_option( $roles->role_key, $roles->roles );
		}
		if ( get_option( 'hbc_roles_simplified' ) === '1' ) {
			if ( $roles->is_role( 'hbc_admin' ) ) {
				remove_role( 'hbc_admin' );
			}
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'hbc_host_assignments';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}
		self::simplify_user_roles();
		remove_role( 'hbc_admin' );
		update_option( 'hbc_roles_simplified', '1', false );
	}

	private static function simplify_user_roles(): void {
		foreach ( array_keys( HBC_Prod_Store::host_organizations_by_identity() ) as $identity ) {
			$ids = get_users(
				array(
					'meta_key'   => 'hbc_identity_uuid',
					'meta_value' => $identity,
					'fields'     => 'ID',
					'number'     => 20,
				)
			);
			foreach ( $ids as $id ) {
				$user = new WP_User( (int) $id );
				if ( $user->exists() ) {
					$user->add_role( 'hbc_host' );
				}
			}
		}
		$former = get_users(
			array(
				'role'   => 'hbc_admin',
				'fields' => 'ID',
				'number' => 500,
			)
		);
		foreach ( $former as $id ) {
			$user = new WP_User( (int) $id );
			$user->remove_role( 'hbc_admin' );
			if ( ! $user->roles ) {
				$user->set_role( 'subscriber' );
			}
		}
		$with_cap = get_users(
			array(
				'capability' => 'hbc_admin',
				'fields'     => 'ID',
				'number'     => 500,
			)
		);
		foreach ( $with_cap as $id ) {
			$user = new WP_User( (int) $id );
			if ( user_can( $user, 'manage_options' ) || in_array( 'hbc_host', (array) $user->roles, true ) ) {
				continue;
			}
			$user->remove_cap( 'hbc_admin' );
			$user->remove_role( 'hbc_admin' );
			if ( ! $user->roles ) {
				$user->set_role( 'subscriber' );
			}
		}
	}

	/**
	 * Door QR destinations hosted on this site (no QRTiger dependency).
	 *
	 * @return array<string, array{label: string, url: string}>
	 */
	public static function door_targets(): array {
		return array(
			'rsvp'       => array(
				'label' => 'Human Gold RSVP postcard fixture',
				'url'   => home_url( '/r' ),
			),
			'trade'      => array(
				'label' => 'Trade entry',
				'url'   => home_url( '/trade' ),
			),
			'gratitude'  => array(
				'label' => 'Gratitude / RSVP entry',
				'url'   => home_url( '/r' ),
			),
			'encounter'  => array(
				'label' => 'Encounter operator entry',
				'url'   => home_url( '/r' ),
			),
		);
	}

	/**
	 * Snapshot for Admin/Super Admin reconciliation review.
	 *
	 * @return array<string, mixed>
	 */
	public static function reconcile_snapshot(): array {
		global $wpdb;
		$month = gmdate( 'Y-m' );
		$snap  = array(
			'period'            => $month,
			'generated_at'      => gmdate( 'c' ),
			'organizations'     => count( self::list_organizations() ),
			'published_events'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}hbc_events WHERE status = 'published'" ),
			'completed_rsvps'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}hbc_rsvps WHERE status = 'completed'" ),
			'volunteer_open'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}hbc_volunteer_requests WHERE state = 'requested'" ),
			'encounters_done'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}hbc_attendance_sessions WHERE state IN ('confirmed','pending_xp')" ),
			'trade_platform_on' => HBC_Prod_Store::trade_is_unlocked(),
			'smallstreet_mirror'=> count( HBC_Prod_Store::smallstreet_mirror_tail( 500 ) ),
			'gold_review'       => array(),
			'red_blockers'      => array(),
		);
		if ( ! class_exists( 'HBC_Laugh_Readiness' ) || ! HBC_Laugh_Readiness::registration_is_open() ) {
			$snap['red_blockers'][] = 'Public registration is closed.';
		}
		if ( ! $snap['published_events'] ) {
			$snap['gold_review'][] = 'No published events — visitors can still register, but RSVP needs an event.';
		}
		$log = get_option( self::RECONCILE_OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$log[ $month ] = $snap;
		update_option( self::RECONCILE_OPTION, $log, false );
		return $snap;
	}
}
