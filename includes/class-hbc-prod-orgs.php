<?php
defined( 'ABSPATH' ) || exit;

/**
 * Multi-organization registry, Super Admin cap, door QRs, and reconciliation snapshots.
 * Extends the live LAUGH stack — does not replace pod-ledger.
 */
final class HBC_Prod_Orgs {
	public const ORG_CODES_OPTION     = 'hbc_org_public_codes';
	public const SUPER_ADMIN_MAX      = 2;
	public const SUPER_ADMIN_ALLOWLIST = 'hbc_super_admin_user_ids';
	public const RECONCILE_OPTION     = 'hbc_reconcile_snapshots';

	public static function register(): void {
		add_action( 'user_register', array( __CLASS__, 'guard_super_admin_cap' ), 20 );
		add_action( 'profile_update', array( __CLASS__, 'guard_super_admin_cap' ), 20 );
		add_action( 'set_user_role', array( __CLASS__, 'on_set_role' ), 20, 3 );
		add_filter( 'hbc_list_public_events', array( __CLASS__, 'filter_public_events_by_org' ), 5, 2 );
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
		$code = strtoupper( preg_replace( '/[^A-Z0-9-]/', '', $code ) );
		$map  = self::code_map();
		return isset( $map[ $code ] ) ? (string) $map[ $code ] : '';
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
		$code = strtoupper( preg_replace( '/[^A-Z0-9-]/', '', (string) ( $payload['public_code'] ?? '' ) ) );
		if ( $name === '' || strlen( $name ) > 180 ) {
			return array(
				'error' => array(
					'code'    => 'validation_error',
					'message' => 'Enter an organization name (max 180 characters).',
				),
			);
		}
		if ( $code === '' ) {
			$code = self::suggest_code( $name );
		}
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
					'message' => 'That public organization code is already in use. Choose another.',
				),
			);
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
			return array(
				'error' => array(
					'code'    => 'create_failed',
					'message' => 'Could not create the organization.',
				),
			);
		}

		$map          = self::code_map();
		$map[ $code ] = $host_uuid;
		update_option( self::ORG_CODES_OPTION, $map, false );

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

		// Grant WP admin access hint for actor.
		if ( $actor_user_id > 0 && user_can( $actor_user_id, 'read' ) ) {
			$user = new WP_User( $actor_user_id );
			if ( ! $user->has_cap( 'hbc_admin' ) ) {
				$user->add_cap( 'hbc_admin' );
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
		$base = strtoupper( preg_replace( '/[^A-Z0-9]/', '', $name ) );
		$base = substr( $base !== '' ? $base : 'ORG', 0, 8 );
		$code = $base;
		$n    = 2;
		while ( self::host_uuid_for_code( $code ) !== '' && $n < 99 ) {
			$code = substr( $base, 0, 6 ) . $n;
			$n++;
		}
		return $code;
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
		if ( ! is_array( $ids ) || ! $ids ) {
			// Default: first two users who already have the cap, else empty (Super Admin must set).
			$users = get_users(
				array(
					'capability' => 'hbc_super_admin',
					'fields'     => 'ID',
					'number'     => self::SUPER_ADMIN_MAX,
				)
			);
			$ids = array_map( 'intval', $users );
			if ( $ids ) {
				update_option( self::SUPER_ADMIN_ALLOWLIST, $ids, false );
			}
		}
		return array_values( array_unique( array_map( 'intval', is_array( $ids ) ? $ids : array() ) ) );
	}

	public static function set_super_admin_allowlist( array $user_ids ): array {
		$user_ids = array_values( array_unique( array_map( 'intval', $user_ids ) ) );
		$user_ids = array_filter( $user_ids );
		if ( count( $user_ids ) > self::SUPER_ADMIN_MAX ) {
			return array(
				'error' => array(
					'code'    => 'super_admin_limit',
					'message' => 'Only ' . self::SUPER_ADMIN_MAX . ' Super Admin accounts are allowed (Coach Tom + implementer).',
				),
			);
		}
		update_option( self::SUPER_ADMIN_ALLOWLIST, $user_ids, false );
		// Sync caps.
		$all = get_users( array( 'fields' => 'ID', 'number' => 500 ) );
		foreach ( $all as $uid ) {
			$user = new WP_User( (int) $uid );
			if ( in_array( (int) $uid, $user_ids, true ) ) {
				$user->add_cap( 'hbc_super_admin' );
			} elseif ( user_can( $user, 'manage_options' ) ) {
				// Keep WP Administrators able to open /super-admin for ops.
				$user->add_cap( 'hbc_super_admin' );
			} elseif ( $user->has_cap( 'hbc_super_admin' ) ) {
				$user->remove_cap( 'hbc_super_admin' );
			}
		}
		foreach ( $user_ids as $uid ) {
			$user = new WP_User( $uid );
			$user->add_cap( 'hbc_super_admin' );
		}
		return array(
			'user_ids' => $user_ids,
			'message'  => 'Super Admin allowlist updated (max ' . self::SUPER_ADMIN_MAX . ').',
		);
	}

	public static function guard_super_admin_cap( int $user_id ): void {
		$user = new WP_User( $user_id );
		if ( ! $user->has_cap( 'hbc_super_admin' ) ) {
			return;
		}
		// Never strip Administrators / manage_options — they are platform owners.
		if ( user_can( $user, 'manage_options' ) ) {
			return;
		}
		$allowed = self::allowed_super_admin_ids();
		if ( in_array( $user_id, $allowed, true ) ) {
			return;
		}
		if ( count( $allowed ) < self::SUPER_ADMIN_MAX ) {
			$allowed[] = $user_id;
			update_option( self::SUPER_ADMIN_ALLOWLIST, $allowed, false );
			return;
		}
		$user->remove_cap( 'hbc_super_admin' );
	}

	public static function on_set_role( int $user_id, string $role, array $old ): void {
		unset( $role, $old );
		self::guard_super_admin_cap( $user_id );
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
