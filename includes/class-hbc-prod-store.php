<?php
defined( 'ABSPATH' ) || exit;

/**
 * Seed data, identity helpers, RSVP/encounter persistence, expiry sweep.
 */
final class HBC_Prod_Store {
	public const XP_AMOUNT          = '1000000000000000000000';
	public const HOST_OPTION        = 'hbc_prod_host_uuid';
	public const EVENT_OPTION       = 'hbc_prod_community_event_uuid';
	public const FAITH_OPTION       = 'hbc_prod_faith_event_uuid';
	public const TRADE_OPTION       = 'hbc_trade_unlocked';
	public const TRADE_EMERGENCY_LOCK = 'hbc_trade_emergency_lock_until';
	public const TRADE_AUDIT_OPTION = 'hbc_trade_audit_log';
	public const TRADE_SELLERS_OPTION = 'hbc_trade_seller_authorizations';
	public const TRADE_LIFECYCLE_OPTION = 'hbc_trade_lifecycle_rows';
	public const SMALLSTREET_MIRROR_OPTION = 'hbc_smallstreet_xp_mirror';
	public const RETURN_CTX_PREFIX = 'hbc_return_ctx_';
	public const RETURN_CTX_TTL = 43200; // 12 hours
	public const TOUCHSTONES        = array( 'Acceptance', 'Compassion', 'Gratitude', 'Humility' );
	public const BRANCHES           = array( 'Planning', 'Budget', 'Media', 'Distribution', 'Membership' );

	/**
	 * Calendar labels only. None of these create a charge.
	 *
	 * @return array<string, string>
	 */
	public static function event_categories(): array {
		return array(
			'worship'   => 'Worship or spiritual',
			'social'    => 'Social',
			'service'   => 'Volunteer service',
			'fundraiser'=> 'Fundraiser',
			'youth'     => 'Youth or family',
			'community' => 'Community gathering',
		);
	}

	public static function sanitize_event_category( string $raw ): string {
		$key = sanitize_key( $raw );
		return array_key_exists( $key, self::event_categories() ) ? $key : 'community';
	}

	public static function is_local(): bool {
		$host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		if ( function_exists( 'wp_get_environment_type' ) && 'local' === wp_get_environment_type() ) {
			return true;
		}
		return (bool) preg_match( '/\.local$|localhost$/', $host );
	}

	public static function activate(): void {
		self::ensure_admin_caps();
		if ( ! wp_next_scheduled( 'hbc_prod_orphan_sweep' ) ) {
			wp_schedule_event( time() + 60, 'hourly', 'hbc_prod_orphan_sweep' );
		}
		if ( class_exists( 'HBC_Laugh_Schema' ) ) {
			HBC_Laugh_Schema::install();
		}
		self::maybe_seed();
		self::bind_first_admin();
	}

	/** Keep Administrator role able to open /super-admin and Human Gold ops. */
	public static function ensure_admin_caps(): void {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( 'hbc_admin' );
			$admin->add_cap( 'hbc_super_admin' );
		}
	}

	public static function maybe_open_local_registration(): void {
		if ( ! self::is_local() ) {
			return;
		}
		if ( ! class_exists( 'HBC_Laugh_Readiness' ) ) {
			return;
		}
		if ( HBC_Laugh_Readiness::registration_is_open() ) {
			return;
		}
		// Official readiness also requires HTTPS. Local Flywheel is often HTTP.
		update_option( 'hbc_laugh_public_registration', 'open', false );
	}

	public static function uuid(): string {
		return wp_generate_uuid4();
	}

	public static function now(): string {
		return current_time( 'mysql', true );
	}

	public static function hash_token( string $token ): string {
		return hash( 'sha256', $token );
	}

	public static function lookup_hash( string $value ): string {
		return hash( 'sha256', strtolower( trim( $value ) ) );
	}

	public static function seal( string $value ): string {
		return base64_encode( $value );
	}

	public static function unseal( string $value ): string {
		$out = base64_decode( $value, true );
		return is_string( $out ) ? $out : '';
	}

	public static function host_uuid(): string {
		$uuid = (string) get_option( self::HOST_OPTION, '' );
		return self::is_uuid( $uuid ) ? $uuid : '';
	}

	public static function community_event_uuid(): string {
		$uuid = (string) get_option( self::EVENT_OPTION, '' );
		return self::is_uuid( $uuid ) ? $uuid : '';
	}

	public static function faith_event_uuid(): string {
		$uuid = (string) get_option( self::FAITH_OPTION, '' );
		return self::is_uuid( $uuid ) ? $uuid : '';
	}

	public static function is_uuid( string $value ): bool {
		return (bool) preg_match( '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', $value );
	}

	public static function trade_is_unlocked(): bool {
		$lock_until = (int) get_option( self::TRADE_EMERGENCY_LOCK, 0 );
		if ( $lock_until > time() ) {
			return false;
		}
		return get_option( self::TRADE_OPTION, 'locked' ) === 'open';
	}

	/**
	 * Platform Trade gate status for Super Admin UI/API.
	 *
	 * @return array<string, mixed>
	 */
	public static function trade_status(): array {
		$lock_until = (int) get_option( self::TRADE_EMERGENCY_LOCK, 0 );
		$option     = (string) get_option( self::TRADE_OPTION, 'locked' );
		return array(
			'platform_on'           => self::trade_is_unlocked(),
			'option_value'          => $option,
			'emergency_locked'      => $lock_until > time(),
			'emergency_lock_until'  => $lock_until > 0 ? gmdate( 'c', $lock_until ) : null,
			'audit'                 => self::trade_audit_tail( 12 ),
			'seller_authorizations' => self::list_seller_authorizations(),
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function trade_audit_tail( int $limit = 20 ): array {
		$log = get_option( self::TRADE_AUDIT_OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$limit = max( 1, min( 50, $limit ) );
		return array_slice( array_reverse( $log ), 0, $limit );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function set_trade_unlocked( bool $on, string $reason, string $actor ): array {
		$reason = sanitize_text_field( $reason );
		$actor  = sanitize_text_field( $actor );
		if ( $reason === '' ) {
			return array(
				'error' => array(
					'code'    => 'validation_error',
					'message' => 'A reason is required for every Trade gate change.',
				),
			);
		}
		$lock_until = (int) get_option( self::TRADE_EMERGENCY_LOCK, 0 );
		if ( $on && $lock_until > time() ) {
			return array(
				'error' => array(
					'code'    => 'trade_emergency_locked',
					'message' => 'Trade is locked OFF after an emergency shutdown until ' . gmdate( 'c', $lock_until ) . '.',
				),
			);
		}
		$prev = self::trade_is_unlocked() ? 'ON' : 'OFF';
		update_option( self::TRADE_OPTION, $on ? 'open' : 'locked', false );
		if ( ! $on ) {
			// Clearing emergency only when intentionally turning off without emergency is fine;
			// turning ON while lock active is already blocked above.
		}
		self::append_trade_audit(
			$actor,
			'Platform Trade toggle changed',
			$prev,
			$on ? 'ON' : 'OFF',
			$reason
		);
		return self::trade_status();
	}

	/**
	 * Force Trade OFF for 24 hours.
	 *
	 * @return array<string, mixed>
	 */
	public static function trade_emergency_shutdown( string $reason, string $actor ): array {
		$reason = sanitize_text_field( $reason );
		$actor  = sanitize_text_field( $actor );
		if ( $reason === '' ) {
			return array(
				'error' => array(
					'code'    => 'validation_error',
					'message' => 'A reason is required for emergency shutdown.',
				),
			);
		}
		$prev = self::trade_is_unlocked() ? 'ON' : 'OFF';
		update_option( self::TRADE_OPTION, 'locked', false );
		$until = time() + DAY_IN_SECONDS;
		update_option( self::TRADE_EMERGENCY_LOCK, $until, false );
		self::append_trade_audit(
			$actor,
			'Emergency shutdown Trade',
			$prev,
			'OFF',
			'EMERGENCY — ' . $reason
		);
		return self::trade_status();
	}

	private static function append_trade_audit( string $actor, string $action, string $prev, string $next, string $reason ): void {
		$log = get_option( self::TRADE_AUDIT_OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$log[] = array(
			'actor'  => $actor,
			'action' => $action,
			'prev'   => $prev,
			'next'   => $next,
			'reason' => $reason,
			'when'   => gmdate( 'c' ),
		);
		if ( count( $log ) > 200 ) {
			$log = array_slice( $log, -200 );
		}
		update_option( self::TRADE_AUDIT_OPTION, $log, false );

		global $wpdb;
		$table = $wpdb->prefix . 'hbc_audit_events';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			$wpdb->insert(
				$table,
				array(
					'event_uuid'          => self::uuid(),
					'actor_identity_uuid' => null,
					'host_uuid'           => self::host_uuid() ?: null,
					'action'              => substr( $action, 0, 100 ),
					'target_type'         => 'trade_gate',
					'target_uuid'         => null,
					'reason'              => $reason,
					'request_id'          => null,
					'metadata_json'       => wp_json_encode(
						array(
							'actor' => $actor,
							'prev'  => $prev,
							'next'  => $next,
						)
					),
					'created_at'          => self::now(),
				)
			);
		}
	}

	/**
	 * @return array<string, bool>
	 */
	public static function list_seller_authorizations(): array {
		$raw = get_option( self::TRADE_SELLERS_OPTION, array() );
		return is_array( $raw ) ? array_map( 'boolval', $raw ) : array();
	}

	public static function seller_is_trade_authorized( string $identity_uuid ): bool {
		$auth = self::list_seller_authorizations();
		return ! empty( $auth[ $identity_uuid ] );
	}

	/**
	 * Detailed Trade seller gate for staging proof / UI (no state change).
	 *
	 * @return array{ok: bool, reasons: list<string>, platform_on: bool, seller_authorized: bool, miner_active: bool}
	 */
	public static function trade_offer_gate_detail( string $identity_uuid ): array {
		$platform = self::trade_is_unlocked();
		$seller   = self::seller_is_trade_authorized( $identity_uuid );
		$miner    = self::is_megavoter_identity( $identity_uuid );
		$reasons  = array();
		if ( ! $platform ) {
			$lock_until = (int) get_option( self::TRADE_EMERGENCY_LOCK, 0 );
			if ( $lock_until > time() ) {
				$reasons[] = 'Emergency OFF is active until ' . gmdate( 'c', $lock_until ) . '.';
			} else {
				$reasons[] = 'Platform Trade toggle is OFF.';
			}
		}
		if ( ! $seller ) {
			$reasons[] = 'This seller identity is not Trade-authorized by Super Admin.';
		}
		if ( ! $miner ) {
			$reasons[] = 'Active Miner membership is required.';
		}
		return array(
			'ok'                => $platform && $seller && $miner,
			'reasons'           => $reasons,
			'platform_on'       => $platform,
			'seller_authorized' => $seller,
			'miner_active'      => $miner,
		);
	}

	/**
	 * Both platform gate and per-seller authorization required for Trade offers.
	 */
	public static function can_offer_trade( string $identity_uuid ): bool {
		$gate = self::trade_offer_gate_detail( $identity_uuid );
		return ! empty( $gate['ok'] );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function set_seller_trade_authorization( string $identity_uuid, bool $authorized, string $reason, string $actor ): array {
		if ( ! self::is_uuid( $identity_uuid ) ) {
			return array(
				'error' => array(
					'code'    => 'validation_error',
					'message' => 'A valid seller identity UUID is required.',
				),
			);
		}
		$reason = sanitize_text_field( $reason );
		if ( $reason === '' ) {
			return array(
				'error' => array(
					'code'    => 'validation_error',
					'message' => 'A reason is required.',
				),
			);
		}
		$auth = self::list_seller_authorizations();
		$prev = ! empty( $auth[ $identity_uuid ] ) ? 'Authorized' : 'Not authorized';
		if ( $authorized ) {
			$auth[ $identity_uuid ] = true;
		} else {
			unset( $auth[ $identity_uuid ] );
		}
		update_option( self::TRADE_SELLERS_OPTION, $auth, false );
		self::append_trade_audit(
			sanitize_text_field( $actor ),
			( $authorized ? 'Granted' : 'Revoked' ) . ' Trade authorization',
			$prev,
			$authorized ? 'Authorized' : 'Not authorized',
			$reason . ' — ' . $identity_uuid
		);
		return self::trade_status();
	}

	/**
	 * Start a Trade encounter session when platform + seller gates are open.
	 *
	 * @return array<string, mixed>
	 */
	public static function begin_trade_offer( string $event_uuid, string $seller_identity_uuid ): array {
		$gate = self::trade_offer_gate_detail( $seller_identity_uuid );
		if ( empty( $gate['ok'] ) ) {
			return array(
				'error' => array(
					'code'    => 'trade_not_live',
					'message' => implode( ' ', $gate['reasons'] ) ?: 'Trade is not live for this seller.',
					'gate'    => $gate,
				),
			);
		}
		$assignment = self::assignment( $seller_identity_uuid, $event_uuid );
		if ( ! is_array( $assignment ) ) {
			return array(
				'error' => array(
					'code'    => 'not_operator',
					'message' => 'Seller must be an assigned operator for this event to offer Trade.',
				),
			);
		}
		$session = self::issue_encounter(
			$event_uuid,
			$seller_identity_uuid,
			(string) ( $assignment['assignment_uuid'] ?? '' ),
			(string) ( $assignment['role'] ?? 'host' ),
			'trade'
		);
		if ( ! is_array( $session ) ) {
			return array(
				'error' => array(
					'code'    => 'create_failed',
					'message' => 'Could not create the Trade encounter.',
				),
			);
		}
		$allocation = array(
			'cogs'          => 10.00,
			'buyer_rebate'  => 5.00,
			'social_impact' => 4.00,
			'patronage'     => 1.00,
			'proof'         => 0.30,
			'seller_margin' => 9.70,
		);
		$session['trade_amount_usd'] = 30;
		$session['allocation']       = $allocation;
		$session['lifecycle']        = 'issued';
		$session['gate']             = $gate;
		self::persist_trade_lifecycle(
			(string) $session['session_uuid'],
			array(
				'session_uuid'         => (string) $session['session_uuid'],
				'event_uuid'           => $event_uuid,
				'seller_identity_uuid' => $seller_identity_uuid,
				'lifecycle'            => 'issued',
				'trade_amount_usd'     => 30,
				'allocation'           => $allocation,
				'issued_at'            => gmdate( 'c' ),
			)
		);
		return $session;
	}

	/**
	 * Persist Trade allocation lifecycle (issued → …). Idempotent by session_uuid.
	 *
	 * @param array<string, mixed> $row
	 */
	public static function persist_trade_lifecycle( string $session_uuid, array $row ): void {
		$all = get_option( self::TRADE_LIFECYCLE_OPTION, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		if ( isset( $all[ $session_uuid ] ) ) {
			return;
		}
		$all[ $session_uuid ] = $row;
		if ( count( $all ) > 400 ) {
			$all = array_slice( $all, -400, null, true );
		}
		update_option( self::TRADE_LIFECYCLE_OPTION, $all, false );
	}

	/**
	 * Advance Trade lifecycle after dual confirm. Never double-issues XP (issue_xp is idempotent).
	 */
	public static function mark_trade_lifecycle( string $session_uuid, string $lifecycle ): void {
		$all = get_option( self::TRADE_LIFECYCLE_OPTION, array() );
		if ( ! is_array( $all ) || empty( $all[ $session_uuid ] ) || ! is_array( $all[ $session_uuid ] ) ) {
			return;
		}
		$all[ $session_uuid ]['lifecycle']   = sanitize_key( $lifecycle );
		$all[ $session_uuid ]['updated_at'] = gmdate( 'c' );
		update_option( self::TRADE_LIFECYCLE_OPTION, $all, false );
	}

	/**
	 * Mirror an authorized XP recognition for SmallStreet (read-only display store).
	 *
	 * @param array<string, mixed> $payload
	 */
	public static function mirror_xp_to_smallstreet( array $payload ): void {
		$mirror = get_option( self::SMALLSTREET_MIRROR_OPTION, array() );
		if ( ! is_array( $mirror ) ) {
			$mirror = array();
		}
		$id = sanitize_key( (string) ( $payload['confirmation_id'] ?? self::uuid() ) );
		if ( isset( $mirror[ $id ] ) ) {
			return; // idempotent
		}
		$mirror[ $id ] = array(
			'confirmation_id' => $id,
			'identity_uuid'   => (string) ( $payload['identity_uuid'] ?? '' ),
			'amount'          => (string) ( $payload['amount'] ?? self::XP_AMOUNT ),
			'lifecycle'       => 'pending',
			'source'          => (string) ( $payload['source'] ?? 'human_gold_encounter' ),
			'mirrored_at'     => gmdate( 'c' ),
		);
		if ( count( $mirror ) > 500 ) {
			$mirror = array_slice( $mirror, -500, null, true );
		}
		update_option( self::SMALLSTREET_MIRROR_OPTION, $mirror, false );
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function smallstreet_mirror_tail( int $limit = 20 ): array {
		$mirror = get_option( self::SMALLSTREET_MIRROR_OPTION, array() );
		if ( ! is_array( $mirror ) ) {
			return array();
		}
		return array_slice( array_reverse( array_values( $mirror ) ), 0, max( 1, min( 50, $limit ) ) );
	}

	public static function maybe_seed(): void {
		global $wpdb;
		if ( ! class_exists( 'HBC_Laugh_Schema' ) ) {
			return;
		}
		if ( HBC_Laugh_Schema::installed_version() < HBC_Laugh_Schema::CURRENT_VERSION ) {
			HBC_Laugh_Schema::install();
		}
		$hosts = $wpdb->prefix . 'hbc_hosts';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hosts ) ) !== $hosts ) {
			return;
		}
		if ( self::host_uuid() && $wpdb->get_var( $wpdb->prepare( "SELECT host_uuid FROM {$hosts} WHERE host_uuid = %s", self::host_uuid() ) ) ) {
			self::ensure_open_published_events( self::host_uuid() );
			return;
		}

		$host_uuid   = self::uuid();
		$event_uuid  = self::uuid();
		$faith_uuid  = self::uuid();
		$assign_uuid = self::uuid();
		$now         = self::now();
		$start       = gmdate( 'Y-m-d H:i:s', strtotime( '+7 days 18:00' ) );
		$end         = gmdate( 'Y-m-d H:i:s', strtotime( '+7 days 21:00' ) );
		$open        = gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) );
		$close       = gmdate( 'Y-m-d H:i:s', strtotime( '+30 days' ) );

		$wpdb->insert(
			$hosts,
			array(
				'host_uuid'               => $host_uuid,
				'name'                    => 'Human Gold Pilot',
				'type'                    => 'community',
				'status'                  => 'active',
				'timezone'                => 'America/New_York',
				'created_by_identity_uuid'=> '00000000-0000-4000-8000-000000000001',
				'created_at'              => $now,
			)
		);

		$events = $wpdb->prefix . 'hbc_events';
		$wpdb->insert(
			$events,
			array(
				'event_uuid'         => $event_uuid,
				'host_uuid'          => $host_uuid,
				'name'               => 'Saturday gathering at Stone Mountain',
				'event_type'         => 'community',
				'description'        => 'Community Checkers / LAUGH gathering. Observe or Participate. A scan is not presence.',
				'starts_at'          => $start,
				'ends_at'            => $end,
				'location'           => 'Stone Mountain, Georgia',
				'capacity'           => 40,
				'allow_observe'      => 1,
				'allow_participate'  => 1,
				'volunteer_message'  => 'Participate also records a volunteer request.',
				'route_mode'         => 'universal',
				'status'             => 'published',
				'rsvp_opens_at'      => $open,
				'rsvp_closes_at'     => $close,
				'created_at'         => $now,
				'updated_at'         => $now,
			)
		);
		$wpdb->insert(
			$events,
			array(
				'event_uuid'         => $faith_uuid,
				'host_uuid'          => $host_uuid,
				'name'               => 'Practice FAITH Touchstone Fulfillment',
				'event_type'         => 'practice_faith_touchstone',
				'description'        => 'Optional $0.00 Touchstone request. Not membership. Not Trade.',
				'starts_at'          => $start,
				'ends_at'            => $end,
				'location'           => 'Stone Mountain, Georgia',
				'allow_observe'      => 1,
				'allow_participate'  => 1,
				'route_mode'         => 'universal',
				'status'             => 'published',
				'rsvp_opens_at'      => $open,
				'rsvp_closes_at'     => $close,
				'created_at'         => $now,
				'updated_at'         => $now,
			)
		);

		$codes = $wpdb->prefix . 'hbc_event_codes';
		$wpdb->insert(
			$codes,
			array(
				'event_code' => 'STONE',
				'event_uuid' => $event_uuid,
				'status'     => 'active',
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		$assigns = $wpdb->prefix . 'hbc_host_assignments';
		$wpdb->insert(
			$assigns,
			array(
				'assignment_uuid' => $assign_uuid,
				'host_uuid'       => $host_uuid,
				'identity_uuid'   => '00000000-0000-4000-8000-000000000001',
				'role_key'        => 'host',
				'scope_type'      => 'organization',
				'scope_uuid'      => $host_uuid,
				'starts_at'       => $open,
				'ends_at'         => null,
				'revoked_at'      => null,
			)
		);

		update_option( self::HOST_OPTION, $host_uuid, false );
		update_option( self::EVENT_OPTION, $event_uuid, false );
		update_option( self::FAITH_OPTION, $faith_uuid, false );
		update_option( 'hbc_prod_host_assignment_uuid', $assign_uuid, false );
		update_option( self::TRADE_OPTION, 'locked', false );
		self::bind_first_admin();
	}

	/**
	 * /r only lists published events whose RSVP is still open. The pilot seed
	 * closes RSVP after 30 days, which empties the scan list. When nothing is
	 * open, extend those published RSVP windows. Create one event only when
	 * this host has no published event at all.
	 */
	public static function ensure_open_published_events( string $host_uuid ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'hbc_events';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}
		$open = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'published' AND (rsvp_closes_at IS NULL OR rsvp_closes_at >= UTC_TIMESTAMP())" );
		if ( $open > 0 ) {
			return;
		}
		$closed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'published' AND rsvp_closes_at IS NOT NULL AND rsvp_closes_at < UTC_TIMESTAMP()" );
		if ( $closed > 0 ) {
			$close = gmdate( 'Y-m-d H:i:s', strtotime( '+90 days' ) );
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET rsvp_closes_at = %s, updated_at = %s WHERE status = 'published' AND rsvp_closes_at IS NOT NULL AND rsvp_closes_at < UTC_TIMESTAMP()",
					$close,
					self::now()
				)
			);
			return;
		}
		if ( self::is_uuid( $host_uuid ) && self::get_host( $host_uuid ) ) {
			self::insert_open_pilot_event( $host_uuid );
		}
	}

	/**
	 * One published gathering so /r has an event to select.
	 */
	private static function insert_open_pilot_event( string $host_uuid ): void {
		global $wpdb;
		$now   = self::now();
		$start = gmdate( 'Y-m-d H:i:s', strtotime( '+7 days 18:00' ) );
		$end   = gmdate( 'Y-m-d H:i:s', strtotime( '+7 days 21:00' ) );
		$open  = gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) );
		$close = gmdate( 'Y-m-d H:i:s', strtotime( '+90 days' ) );
		$wpdb->insert(
			$wpdb->prefix . 'hbc_events',
			array(
				'event_uuid'        => self::uuid(),
				'host_uuid'         => $host_uuid,
				'name'              => 'Human Gold Pilot gathering',
				'event_type'        => 'community',
				'description'       => 'Observe or Participate. A scan is not presence.',
				'starts_at'         => $start,
				'ends_at'           => $end,
				'location'          => 'Stone Mountain, Georgia',
				'capacity'          => 40,
				'allow_observe'     => 1,
				'allow_participate' => 1,
				'volunteer_message' => 'Participate also records a volunteer request.',
				'route_mode'        => 'universal',
				'status'            => 'published',
				'rsvp_opens_at'     => $open,
				'rsvp_closes_at'    => $close,
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);
	}

	public static function bind_first_admin(): void {
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => array( 'ID', 'user_email' ),
			)
		);
		if ( empty( $admins[0] ) ) {
			return;
		}
		$admin = $admins[0];
		$identity = self::ensure_identity( (string) $admin->user_email, '15555550100', (int) $admin->ID );
		self::bind_admin_host_if_needed( $identity['identity_uuid'] );
	}

	public static function get_event( string $event_uuid ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}hbc_events WHERE event_uuid = %s", $event_uuid ) );
		return $row instanceof stdClass ? $row : null;
	}

	public static function get_host( string $host_uuid ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}hbc_hosts WHERE host_uuid = %s", $host_uuid ) );
		return $row instanceof stdClass ? $row : null;
	}

	public static function list_public_events( int $limit, ?string $cursor, string $query, string $host_uuid = '' ): array {
		global $wpdb;
		$limit  = max( 1, min( 20, $limit ) );
		$offset = 0;
		if ( $cursor && ctype_digit( $cursor ) ) {
			$offset = (int) $cursor;
		}
		$where = "e.status = 'published' AND (e.rsvp_closes_at IS NULL OR e.rsvp_closes_at >= UTC_TIMESTAMP())";
		$args  = array();
		if ( $host_uuid !== '' && self::is_uuid( $host_uuid ) ) {
			$where .= ' AND e.host_uuid = %s';
			$args[] = $host_uuid;
		}
		if ( $query !== '' ) {
			$like   = '%' . $wpdb->esc_like( $query ) . '%';
			$where .= ' AND (e.name LIKE %s OR h.name LIKE %s)';
			$args[] = $like;
			$args[] = $like;
		}
		$sql = "SELECT e.event_uuid, e.name, e.starts_at, e.location, h.name AS organization_name, h.host_uuid
			FROM {$wpdb->prefix}hbc_events e
			INNER JOIN {$wpdb->prefix}hbc_hosts h ON h.host_uuid = e.host_uuid
			WHERE {$where}
			ORDER BY e.starts_at ASC
			LIMIT %d OFFSET %d";
		$args[] = $limit + 1;
		$args[] = $offset;
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) );
		$rows   = is_array( $rows ) ? $rows : array();
		$has_more = count( $rows ) > $limit;
		$rows     = array_slice( $rows, 0, $limit );
		$events   = array();
		foreach ( $rows as $row ) {
			$events[] = array(
				'event_uuid'        => $row->event_uuid,
				'name'              => $row->name,
				'organization_name' => $row->organization_name,
				'host_uuid'         => $row->host_uuid,
				'starts_at'         => self::iso( $row->starts_at ),
				'location'          => $row->location,
			);
		}
		return array(
			'events'      => $events,
			'has_more'    => $has_more,
			'next_cursor' => $has_more ? (string) ( $offset + $limit ) : null,
			'host_uuid'   => $host_uuid !== '' ? $host_uuid : null,
		);
	}

	/**
	 * All events for one host (admin dashboard), including drafts.
	 *
	 * @return array{events: list<array<string, mixed>>, host_uuid: string}
	 */
	public static function list_host_events( string $host_uuid ): array {
		global $wpdb;
		$host = self::get_host( $host_uuid );
		if ( ! $host ) {
			return array(
				'host_uuid' => $host_uuid,
				'events'    => array(),
			);
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.*, c.event_code
				FROM {$wpdb->prefix}hbc_events e
				LEFT JOIN {$wpdb->prefix}hbc_event_codes c ON c.event_uuid = e.event_uuid AND c.status = 'active'
				WHERE e.host_uuid = %s
				ORDER BY (e.starts_at IS NULL) ASC, e.starts_at DESC, e.created_at DESC",
				$host_uuid
			)
		);
		$events = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$stats = self::event_rsvp_counts( (string) $row->event_uuid );
			$events[] = array(
				'event_uuid'        => $row->event_uuid,
				'name'              => $row->name,
				'description'       => (string) ( $row->description ?? '' ),
				'event_type'        => $row->event_type,
				'status'            => $row->status,
				'location'          => $row->location,
				'starts_at'         => self::iso( $row->starts_at ),
				'ends_at'           => self::iso( $row->ends_at ),
				'event_code'        => $row->event_code ? (string) $row->event_code : null,
				'allow_observe'     => (int) $row->allow_observe === 1,
				'allow_participate' => (int) $row->allow_participate === 1,
				'rsvp_observe'      => $stats['observe'],
				'rsvp_participate'  => $stats['participate'],
				'rsvp_walk_away'    => $stats['walk_away'],
				'rsvp_completed'    => $stats['completed'],
				'encounters_done'   => $stats['encounters_done'],
			);
		}
		return array(
			'host_uuid' => $host_uuid,
			'host_name' => $host->name,
			'events'    => $events,
		);
	}

	/**
	 * Per-event RSVP / encounter counts for host dashboard.
	 *
	 * @return array{observe: int, participate: int, walk_away: int, completed: int, encounters_done: int}
	 */
	public static function event_rsvp_counts( string $event_uuid ): array {
		global $wpdb;
		$out = array(
			'observe'         => 0,
			'participate'     => 0,
			'walk_away'       => 0,
			'completed'       => 0,
			'encounters_done' => 0,
		);
		if ( ! self::is_uuid( $event_uuid ) ) {
			return $out;
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT rsvp_choice, status, COUNT(*) AS c
				FROM {$wpdb->prefix}hbc_rsvps
				WHERE event_uuid = %s
				GROUP BY rsvp_choice, status",
				$event_uuid
			)
		);
		foreach ( (array) $rows as $row ) {
			$choice = sanitize_key( (string) $row->rsvp_choice );
			$count  = (int) $row->c;
			if ( $row->status === 'completed' ) {
				$out['completed'] += $count;
				if ( isset( $out[ $choice ] ) ) {
					$out[ $choice ] += $count;
				}
			} elseif ( $row->status === 'withdrawn' && $choice === 'walk_away' ) {
				$out['walk_away'] += $count;
			}
		}
		$out['encounters_done'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}hbc_attendance_sessions
				WHERE event_uuid = %s AND state IN ('confirmed','pending_xp')",
				$event_uuid
			)
		);
		return $out;
	}

	/**
	 * Host dashboard snapshot (Overview parity for /organize).
	 *
	 * @return array<string, mixed>
	 */
	public static function host_dashboard_snapshot( string $host_uuid ): array {
		$events_payload = self::list_host_events( $host_uuid );
		$events         = $events_payload['events'] ?? array();
		$totals         = array(
			'events'            => count( $events ),
			'published'         => 0,
			'rsvp_observe'      => 0,
			'rsvp_participate'  => 0,
			'rsvp_walk_away'    => 0,
			'rsvp_completed'    => 0,
			'encounters_done'   => 0,
			'volunteers_open'   => 0,
		);
		foreach ( $events as $ev ) {
			if ( ( $ev['status'] ?? '' ) === 'published' ) {
				++$totals['published'];
			}
			$totals['rsvp_observe']     += (int) ( $ev['rsvp_observe'] ?? 0 );
			$totals['rsvp_participate'] += (int) ( $ev['rsvp_participate'] ?? 0 );
			$totals['rsvp_walk_away']   += (int) ( $ev['rsvp_walk_away'] ?? 0 );
			$totals['rsvp_completed']   += (int) ( $ev['rsvp_completed'] ?? 0 );
			$totals['encounters_done']  += (int) ( $ev['encounters_done'] ?? 0 );
		}
		$vols = self::list_volunteers( $host_uuid );
		foreach ( ( $vols['volunteers'] ?? array() ) as $v ) {
			if ( ( $v['state'] ?? '' ) === 'requested' ) {
				++$totals['volunteers_open'];
			}
		}
		$org_code = class_exists( 'HBC_Prod_Orgs' ) ? HBC_Prod_Orgs::public_code_for_host( $host_uuid ) : '';
		$scan_url = $org_code !== ''
			? home_url( '/r?org=' . rawurlencode( $org_code ) )
			: home_url( '/r' );
		$doors = class_exists( 'HBC_Prod_Orgs' ) ? HBC_Prod_Orgs::door_targets() : array();
		if ( $org_code !== '' && isset( $doors['rsvp'] ) ) {
			$doors['rsvp']['url'] = $scan_url;
		}
		return array(
			'host_uuid'    => $host_uuid,
			'host_name'    => (string) ( $events_payload['host_name'] ?? '' ),
			'org_code'     => $org_code,
			'scan_url'     => $scan_url,
			'print_studio' => home_url( '/print-studio' ),
			'totals'       => $totals,
			'events'       => $events,
			'doors'        => $doors,
			'trade'        => self::trade_status(),
		);
	}

	/**
	 * Opaque short-lived return context for Megavoters / LLB closed-loop (file 33).
	 * Payload contains only org/event location — never PII.
	 *
	 * @return array<string, mixed>
	 */
	public static function issue_return_context( string $event_uuid, string $org_code = '' ): array {
		$event = self::get_event( $event_uuid );
		if ( ! $event ) {
			return array(
				'error' => array(
					'code'    => 'validation_error',
					'message' => 'A published event is required to issue return context.',
				),
			);
		}
		$host_uuid = (string) $event->host_uuid;
		if ( $org_code === '' && class_exists( 'HBC_Prod_Orgs' ) ) {
			$org_code = HBC_Prod_Orgs::public_code_for_host( $host_uuid );
		}
		$org_code = sanitize_text_field( $org_code );
		$token    = 'hbc' . wp_generate_password( 40, false, false );
		$hash     = hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
		$payload  = array(
			'event_uuid' => $event_uuid,
			'host_uuid'  => $host_uuid,
			'org_code'   => $org_code,
			'issued_at'  => time(),
			'exp'        => time() + self::RETURN_CTX_TTL,
		);
		set_transient( self::RETURN_CTX_PREFIX . $hash, $payload, self::RETURN_CTX_TTL );
		$ctx_query  = 'hbc_ctx=' . rawurlencode( $token );
		$hgo_base   = self::is_local() ? 'http://humangold.local' : 'https://humangold.org';
		$llb_base   = self::is_local() ? 'http://legacytoliveby.local' : 'https://legacytoliveby.org';
		$hgo_start  = $hgo_base . '/start/?' . $ctx_query;
		$hgo_disc   = $hgo_base . '/discover/?' . $ctx_query;
		$llb_welcome = $llb_base . '/llb-welcome/?' . $ctx_query;
		return array(
			'hbc_ctx'           => $token,
			'expires_in'        => self::RETURN_CTX_TTL,
			'event_uuid'        => $event_uuid,
			'org_code'          => $org_code,
			'hgo_start_url'     => $hgo_start,
			'hgo_discover_url'  => $hgo_disc,
			'llb_welcome_url'   => $llb_welcome,
			'mega_start_url'    => $hgo_start,
			'mega_discover_url' => $hgo_disc,
			'hbc_return_url'    => home_url( '/return?hbc_ctx=' . rawurlencode( $token ) ),
		);
	}

	/**
	 * Redeem hbc_ctx → locally constructed /r?org=&event= (never trusts return_url).
	 *
	 * @return array{ok?: bool, redirect?: string, error?: array{code: string, message: string}}
	 */
	public static function redeem_return_context( string $token ): array {
		$token = sanitize_text_field( $token );
		if ( $token === '' || ! preg_match( '/^[A-Za-z0-9_-]{16,128}$/', $token ) ) {
			return array(
				'error' => array(
					'code'    => 'invalid_context',
					'message' => 'This return link is missing or malformed.',
				),
			);
		}
		$hash = hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
		$data = get_transient( self::RETURN_CTX_PREFIX . $hash );
		if ( ! is_array( $data ) ) {
			return array(
				'error' => array(
					'code'    => 'expired_context',
					'message' => 'This return link expired or was already used. Open /r to find your event.',
				),
			);
		}
		if ( ! empty( $data['exp'] ) && (int) $data['exp'] < time() ) {
			delete_transient( self::RETURN_CTX_PREFIX . $hash );
			return array(
				'error' => array(
					'code'    => 'expired_context',
					'message' => 'This return link expired. Open /r to find your event.',
				),
			);
		}
		$event_uuid = (string) ( $data['event_uuid'] ?? '' );
		$org_code   = sanitize_text_field( (string) ( $data['org_code'] ?? '' ) );
		$host_uuid  = (string) ( $data['host_uuid'] ?? '' );
		$event      = self::is_uuid( $event_uuid ) ? self::get_event( $event_uuid ) : null;
		if ( ! $event || ( $host_uuid !== '' && (string) $event->host_uuid !== $host_uuid ) ) {
			return array(
				'error' => array(
					'code'    => 'invalid_context',
					'message' => 'This return context does not match a known event.',
				),
			);
		}
		if ( $org_code !== '' && class_exists( 'HBC_Prod_Orgs' ) ) {
			$resolved = HBC_Prod_Orgs::host_uuid_for_code( $org_code );
			if ( $resolved !== '' && $resolved !== (string) $event->host_uuid ) {
				return array(
					'error' => array(
						'code'    => 'cross_org',
						'message' => 'This return context does not match the organization.',
					),
				);
			}
		}
		$args = array( 'event' => $event_uuid );
		if ( $org_code !== '' ) {
			$args['org'] = $org_code;
		}
		return array(
			'ok'       => true,
			'redirect' => add_query_arg( $args, home_url( '/r' ) ),
		);
	}

	/**
	 * Create a published or draft event under a host. Optional short code for /r discovery.
	 *
	 * @param array<string, mixed> $payload Form / REST body.
	 * @return array<string, mixed>
	 */
	public static function create_event( string $host_uuid, array $payload ): array {
		global $wpdb;
		$host = self::get_host( $host_uuid );
		if ( ! $host ) {
			return array(
				'error' => array(
					'code'    => 'validation_error',
					'message' => 'That organization was not found.',
				),
			);
		}

		$name = sanitize_text_field( (string) ( $payload['name'] ?? '' ) );
		if ( $name === '' || strlen( $name ) > 180 ) {
			return array(
				'error' => array(
					'code'    => 'validation_error',
					'message' => 'Enter an event name (max 180 characters).',
				),
			);
		}

		$event_category = self::sanitize_event_category( (string) ( $payload['event_category'] ?? 'community' ) );
		$event_type = sanitize_key( (string) ( $payload['event_type'] ?? 'community' ) );
		if ( ! in_array( $event_type, array( 'community', 'practice_faith_touchstone' ), true ) ) {
			return array(
				'error' => array(
					'code'    => 'validation_error',
					'message' => 'Event type must be community or practice_faith_touchstone.',
				),
			);
		}

		$status = sanitize_key( (string) ( $payload['status'] ?? 'published' ) );
		if ( ! in_array( $status, array( 'draft', 'published' ), true ) ) {
			$status = 'published';
		}

		$description = sanitize_textarea_field( (string) ( $payload['description'] ?? '' ) );
		$location    = sanitize_text_field( (string) ( $payload['location'] ?? '' ) );
		$capacity    = isset( $payload['capacity'] ) && $payload['capacity'] !== '' ? absint( $payload['capacity'] ) : null;
		$allow_obs   = ! isset( $payload['allow_observe'] ) || ! empty( $payload['allow_observe'] );
		$allow_part  = ! isset( $payload['allow_participate'] ) || ! empty( $payload['allow_participate'] );

		$starts_at = self::parse_admin_datetime( (string) ( $payload['starts_at'] ?? '' ) );
		$ends_at   = self::parse_admin_datetime( (string) ( $payload['ends_at'] ?? '' ) );
		$opens_at  = self::parse_admin_datetime( (string) ( $payload['rsvp_opens_at'] ?? '' ) );
		$closes_at = self::parse_admin_datetime( (string) ( $payload['rsvp_closes_at'] ?? '' ) );

		if ( ! $opens_at ) {
			$opens_at = self::now();
		}
		if ( ! $closes_at && $ends_at ) {
			$closes_at = $ends_at;
		}
		if ( ! $closes_at ) {
			$closes_at = gmdate( 'Y-m-d H:i:s', strtotime( '+30 days' ) );
		}

		$event_code = strtoupper( preg_replace( '/[^A-Z0-9-]/', '', (string) ( $payload['event_code'] ?? '' ) ) );
		if ( $event_code !== '' && ( strlen( $event_code ) < 4 || strlen( $event_code ) > 16 ) ) {
			return array(
				'error' => array(
					'code'    => 'invalid_event_code',
					'message' => 'Short event code must be 4–16 letters, numbers, or hyphens.',
				),
			);
		}
		if ( $event_code !== '' ) {
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT event_code FROM {$wpdb->prefix}hbc_event_codes WHERE event_code = %s",
					$event_code
				)
			);
			if ( $exists ) {
				return array(
					'error' => array(
						'code'    => 'duplicate_event_code',
						'message' => 'That short event code is already in use.',
					),
				);
			}
		}

		$event_uuid = self::uuid();
		$now        = self::now();
		$inserted   = $wpdb->insert(
			$wpdb->prefix . 'hbc_events',
			array(
				'event_uuid'        => $event_uuid,
				'host_uuid'         => $host_uuid,
				'name'              => $name,
				'event_type'        => $event_type,
				'event_category'    => $event_category,
				'description'       => $description !== '' ? $description : null,
				'starts_at'         => $starts_at,
				'ends_at'           => $ends_at,
				'location'          => $location !== '' ? $location : null,
				'capacity'          => $capacity,
				'allow_observe'     => $allow_obs ? 1 : 0,
				'allow_participate' => $allow_part ? 1 : 0,
				'volunteer_message' => $allow_part ? 'Participate also records a volunteer request.' : null,
				'route_mode'        => 'universal',
				'status'            => $status,
				'rsvp_opens_at'     => $opens_at,
				'rsvp_closes_at'    => $closes_at,
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);
		if ( ! $inserted ) {
			return array(
				'error' => array(
					'code'    => 'create_failed',
					'message' => 'Database could not save the event.',
				),
			);
		}

		if ( $event_code !== '' ) {
			$wpdb->insert(
				$wpdb->prefix . 'hbc_event_codes',
				array(
					'event_code' => $event_code,
					'event_uuid' => $event_uuid,
					'status'     => 'active',
					'created_at' => $now,
					'updated_at' => $now,
				)
			);
		}

		return array(
			'event_uuid' => $event_uuid,
			'host_uuid'  => $host_uuid,
			'name'            => $name,
			'event_type'      => $event_type,
			'event_category'  => $event_category,
			'collects_payment'=> false,
			'status'          => $status,
			'event_code' => $event_code !== '' ? $event_code : null,
			'message'    => $status === 'published'
				? 'Event published. It will appear on /r search' . ( $event_code !== '' ? ' and via code ' . $event_code : '' ) . '.'
				: 'Draft event saved. Publish it later to show on /r.',
		);
	}

	/**
	 * Update an existing event (organizer calendar/edit).
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public static function update_event( string $host_uuid, string $event_uuid, array $payload ): array {
		global $wpdb;
		$event = self::get_event( $event_uuid );
		if ( ! $event || $event->host_uuid !== $host_uuid ) {
			return array(
				'error' => array(
					'code'    => 'event_unavailable',
					'message' => 'That event was not found for this organization.',
				),
			);
		}
		$data = array( 'updated_at' => self::now() );
		if ( isset( $payload['name'] ) ) {
			$name = sanitize_text_field( (string) $payload['name'] );
			if ( $name === '' || strlen( $name ) > 180 ) {
				return array(
					'error' => array(
						'code'    => 'validation_error',
						'message' => 'Enter an event name (max 180 characters).',
					),
				);
			}
			$data['name'] = $name;
		}
		if ( isset( $payload['description'] ) ) {
			$data['description'] = sanitize_textarea_field( (string) $payload['description'] );
		}
		if ( isset( $payload['location'] ) ) {
			$data['location'] = sanitize_text_field( (string) $payload['location'] );
		}
		if ( array_key_exists( 'starts_at', $payload ) ) {
			$data['starts_at'] = self::parse_admin_datetime( (string) $payload['starts_at'] );
		}
		if ( array_key_exists( 'ends_at', $payload ) ) {
			$data['ends_at'] = self::parse_admin_datetime( (string) $payload['ends_at'] );
		}
		if ( array_key_exists( 'rsvp_opens_at', $payload ) ) {
			$data['rsvp_opens_at'] = self::parse_admin_datetime( (string) $payload['rsvp_opens_at'] );
		}
		if ( array_key_exists( 'rsvp_closes_at', $payload ) ) {
			$data['rsvp_closes_at'] = self::parse_admin_datetime( (string) $payload['rsvp_closes_at'] );
		}
		if ( isset( $payload['status'] ) ) {
			$status = sanitize_key( (string) $payload['status'] );
			if ( in_array( $status, array( 'draft', 'published', 'cancelled', 'archived' ), true ) ) {
				$data['status'] = $status;
			}
		}
		if ( isset( $payload['capacity'] ) && $payload['capacity'] !== '' ) {
			$data['capacity'] = absint( $payload['capacity'] );
		}
		if ( isset( $payload['allow_observe'] ) ) {
			$data['allow_observe'] = ! empty( $payload['allow_observe'] ) ? 1 : 0;
		}
		if ( isset( $payload['allow_participate'] ) ) {
			$data['allow_participate'] = ! empty( $payload['allow_participate'] ) ? 1 : 0;
		}
		if ( isset( $payload['event_category'] ) ) {
			$data['event_category'] = self::sanitize_event_category( (string) $payload['event_category'] );
		}
		$wpdb->update( $wpdb->prefix . 'hbc_events', $data, array( 'event_uuid' => $event_uuid ) );

		if ( ! empty( $payload['event_code'] ) ) {
			$code = strtoupper( preg_replace( '/[^A-Z0-9-]/', '', (string) $payload['event_code'] ) );
			if ( strlen( $code ) >= 4 && strlen( $code ) <= 16 ) {
				$existing = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT event_uuid FROM {$wpdb->prefix}hbc_event_codes WHERE event_code = %s AND event_uuid <> %s",
						$code,
						$event_uuid
					)
				);
				if ( $existing ) {
					return array(
						'error' => array(
							'code'    => 'duplicate_event_code',
							'message' => 'That short event code is already in use.',
						),
					);
				}
				$has = $wpdb->get_var( $wpdb->prepare( "SELECT event_code FROM {$wpdb->prefix}hbc_event_codes WHERE event_uuid = %s", $event_uuid ) );
				if ( $has ) {
					$wpdb->update(
						$wpdb->prefix . 'hbc_event_codes',
						array(
							'event_code' => $code,
							'status'     => 'active',
							'updated_at' => self::now(),
						),
						array( 'event_uuid' => $event_uuid )
					);
				} else {
					$wpdb->insert(
						$wpdb->prefix . 'hbc_event_codes',
						array(
							'event_code' => $code,
							'event_uuid' => $event_uuid,
							'status'     => 'active',
							'created_at' => self::now(),
							'updated_at' => self::now(),
						)
					);
				}
			}
		}

		$list = self::list_host_events( $host_uuid );
		foreach ( $list['events'] as $row ) {
			if ( ( $row['event_uuid'] ?? '' ) === $event_uuid ) {
				$row['message'] = 'Event updated.';
				return $row;
			}
		}
		return array(
			'event_uuid' => $event_uuid,
			'message'    => 'Event updated.',
		);
	}

	/**
	 * Volunteer queue for a host.
	 *
	 * @return array{volunteers: list<array<string, mixed>>}
	 */
	public static function list_volunteers( string $host_uuid ): array {
		return self::list_volunteers_query( $host_uuid );
	}

	/**
	 * Platform-wide volunteer queue (Super Admin).
	 *
	 * @return array{volunteers: list<array<string, mixed>>}
	 */
	public static function list_all_volunteers(): array {
		return self::list_volunteers_query( '' );
	}

	/**
	 * @return array{volunteers: list<array<string, mixed>>}
	 */
	private static function list_volunteers_query( string $host_uuid ): array {
		global $wpdb;
		$sql = "SELECT v.*, r.rsvp_choice, r.identity_uuid, r.event_uuid, e.name AS event_name, h.name AS host_name
			FROM {$wpdb->prefix}hbc_volunteer_requests v
			INNER JOIN {$wpdb->prefix}hbc_rsvps r ON r.rsvp_uuid = v.rsvp_uuid
			LEFT JOIN {$wpdb->prefix}hbc_events e ON e.event_uuid = r.event_uuid
			LEFT JOIN {$wpdb->prefix}hbc_hosts h ON h.host_uuid = v.host_uuid";
		if ( $host_uuid !== '' ) {
			$sql .= $wpdb->prepare( ' WHERE v.host_uuid = %s', $host_uuid );
		}
		$sql .= ' ORDER BY v.created_at DESC LIMIT 100';
		$rows = $wpdb->get_results( $sql );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'volunteer_request_uuid' => $row->volunteer_request_uuid,
				'rsvp_uuid'              => $row->rsvp_uuid,
				'host_uuid'              => $row->host_uuid,
				'host_name'              => $row->host_name,
				'event_uuid'             => $row->event_uuid,
				'event_name'             => $row->event_name,
				'identity_uuid'          => $row->identity_uuid,
				'state'                  => $row->state,
				'created_at'             => self::iso( $row->created_at ),
			);
		}
		return array( 'volunteers' => $out );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function review_volunteer( string $volunteer_uuid, string $decision, string $reviewer_identity = '' ): array {
		global $wpdb;
		$decision = sanitize_key( $decision );
		if ( ! in_array( $decision, array( 'accepted', 'declined' ), true ) ) {
			return array(
				'error' => array(
					'code'    => 'validation_error',
					'message' => 'Decision must be accepted or declined.',
				),
			);
		}
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}hbc_volunteer_requests WHERE volunteer_request_uuid = %s",
				$volunteer_uuid
			)
		);
		if ( ! $row ) {
			return array(
				'error' => array(
					'code'    => 'not_found',
					'message' => 'Volunteer request not found.',
				),
			);
		}
		$wpdb->update(
			$wpdb->prefix . 'hbc_volunteer_requests',
			array(
				'state'                     => $decision,
				'reviewed_by_identity_uuid' => $reviewer_identity !== '' ? $reviewer_identity : null,
				'reviewed_at'               => self::now(),
			),
			array( 'volunteer_request_uuid' => $volunteer_uuid )
		);
		return array(
			'volunteer_request_uuid' => $volunteer_uuid,
			'state'                  => $decision,
			'message'                => 'Volunteer request ' . $decision . '.',
		);
	}

	/**
	 * Accept admin datetime-local (site timezone) or full ISO; store as UTC MySQL datetime.
	 */
	private static function parse_admin_datetime( string $value ): ?string {
		$value = trim( $value );
		if ( $value === '' ) {
			return null;
		}
		try {
			if ( preg_match( '/(Z|[+-]\d{2}:?\d{2})$/', $value ) ) {
				$dt = new DateTimeImmutable( $value );
				return $dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			}
			$tz          = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
			$normalized  = str_replace( 'T', ' ', $value );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $normalized ) ) {
				$normalized .= ':00';
			}
			$dt = new DateTimeImmutable( $normalized, $tz );
			return $dt->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			return null;
		}
	}

	public static function resolve_event_code( string $code ): string {
		global $wpdb;
		$code = strtoupper( preg_replace( '/[^A-Z0-9-]/', '', $code ) );
		if ( strlen( $code ) < 4 ) {
			return '';
		}
		$key = 'hbc_code_fail_' . md5( $code . ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$fails = (int) get_transient( $key );
		$uuid = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT event_uuid FROM {$wpdb->prefix}hbc_event_codes WHERE event_code = %s AND status = 'active'",
				$code
			)
		);
		if ( ! self::is_uuid( $uuid ) ) {
			set_transient( $key, $fails + 1, 15 * MINUTE_IN_SECONDS );
			if ( $fails >= 8 ) {
				return '';
			}
			return '';
		}
		return $uuid;
	}

	public static function iso( ?string $mysql ): string {
		if ( ! $mysql ) {
			return '';
		}
		return gmdate( 'c', strtotime( $mysql . ' UTC' ) );
	}

	public static function event_payload( object $event ): array {
		$host = self::get_host( (string) $event->host_uuid );
		$type = ( $event->event_type === 'practice_faith_touchstone' ) ? 'practice_faith_touchstone' : 'ordinary';
		$category = isset( $event->event_category ) ? self::sanitize_event_category( (string) $event->event_category ) : 'community';
		$out  = array(
			'event_uuid'       => $event->event_uuid,
			'name'             => $event->name,
			'event_type'       => $type,
			'event_category'   => $category,
			'collects_payment' => false,
			'starts_at'        => self::iso( $event->starts_at ),
			'location'         => $event->location,
		);
		if ( $type === 'practice_faith_touchstone' ) {
			$out['available_touchstones']     = self::TOUCHSTONES;
			$out['peace_pentagon_branches']   = self::BRANCHES;
		}
		if ( $host ) {
			$out['organization_name'] = $host->name;
		}
		return $out;
	}

	public static function find_identity_by_phone( string $phone ): ?object {
		global $wpdb;
		$hash = self::lookup_hash( preg_replace( '/\D/', '', $phone ) );
		$row  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}hbc_identities WHERE phone_lookup_hash = %s", $hash ) );
		return $row instanceof stdClass ? $row : null;
	}

	public static function resolve_token( string $token ) {
		global $wpdb;
		if ( $token === '' ) {
			return false;
		}
		$hash = self::hash_token( $token );
		$uuid = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT identity_uuid FROM {$wpdb->prefix}hbc_devices WHERE recognition_token_hash = %s AND status = 'active'",
				$hash
			)
		);
		return self::is_uuid( (string) $uuid ) ? (string) $uuid : false;
	}

	/**
	 * Registered phones and browsers for the Human Gold admin list.
	 * The recognition token itself is not included.
	 *
	 * @return list<array<string, string>>
	 */
	public static function list_saved_devices(): array {
		global $wpdb;
		$devices = $wpdb->prefix . 'hbc_devices';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $devices ) ) !== $devices ) {
			return array();
		}
		$rows = $wpdb->get_results(
			"SELECT d.device_uuid, d.identity_uuid, d.status, d.verified_at, d.last_seen_at,
				i.email_ciphertext, i.phone_ciphertext
			FROM {$devices} d
			LEFT JOIN {$wpdb->prefix}hbc_identities i ON i.identity_uuid = d.identity_uuid
			ORDER BY d.verified_at DESC
			LIMIT 200"
		);
		$host_ids = $wpdb->get_col(
			"SELECT identity_uuid FROM {$wpdb->prefix}hbc_host_assignments
			WHERE role_key = 'host' AND revoked_at IS NULL"
		);
		$host_ids = is_array( $host_ids ) ? $host_ids : array();
		$out      = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$email = self::unseal( (string) ( $row->email_ciphertext ?? '' ) );
			$phone = self::unseal( (string) ( $row->phone_ciphertext ?? '' ) );
			$user  = self::wp_user_for_identity( (string) $row->identity_uuid );
			$account = $email;
			if ( $user > 0 ) {
				$wp_user = get_userdata( $user );
				if ( $wp_user && is_email( $wp_user->user_email ) ) {
					$account = (string) $wp_user->user_email;
				}
			}
			if ( $account === '' ) {
				$account = 'Unknown';
			}
			$digits = preg_replace( '/\D/', '', $phone );
			$masked = '—';
			if ( is_string( $digits ) && strlen( $digits ) >= 4 ) {
				$masked = '••••' . substr( $digits, -4 );
			}
			$role = in_array( (string) $row->identity_uuid, $host_ids, true ) ? 'Host' : 'Guest';
			$out[] = array(
				'account'      => $account,
				'phone'        => $masked,
				'role'         => $role,
				'status'       => (string) $row->status,
				'verified_at'  => (string) ( $row->verified_at ?: '—' ),
				'last_seen_at' => (string) ( $row->last_seen_at ?: '—' ),
			);
		}
		return $out;
	}

	public static function device_for_identity( string $identity_uuid ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}hbc_devices WHERE identity_uuid = %s AND status = 'active' ORDER BY last_seen_at DESC LIMIT 1", $identity_uuid ) );
		return $row instanceof stdClass ? $row : null;
	}

	public static function ensure_identity( string $email, string $phone, int $wp_user_id = 0, bool $phone_verified = false ): array {
		global $wpdb;
		$phone_digits = preg_replace( '/\D/', '', $phone );
		$existing     = self::find_identity_by_phone( $phone_digits );
		if ( $existing ) {
			if ( $phone_verified && (string) ( $existing->phone_status ?? '' ) !== 'verified' ) {
				$wpdb->update(
					$wpdb->prefix . 'hbc_identities',
					array(
						'phone_status'      => 'verified',
						'phone_verified_at' => self::now(),
					),
					array( 'identity_uuid' => $existing->identity_uuid )
				);
			}
			return array(
				'identity_uuid' => $existing->identity_uuid,
				'created'       => false,
				'phone_status'  => $phone_verified ? 'verified' : (string) ( $existing->phone_status ?? 'pending' ),
				'email_status'  => (string) ( $existing->email_status ?? 'pending' ),
			);
		}
		$identity_uuid = self::uuid();
		$now           = self::now();
		$wpdb->insert(
			$wpdb->prefix . 'hbc_identities',
			array(
				'identity_uuid'     => $identity_uuid,
				'email_ciphertext'  => self::seal( $email ),
				'email_lookup_hash' => self::lookup_hash( $email ),
				'email_status'      => 'pending',
				'phone_ciphertext'  => self::seal( $phone_digits ),
				'phone_lookup_hash' => self::lookup_hash( $phone_digits ),
				'phone_status'      => $phone_verified ? 'verified' : 'pending',
				'phone_verified_at' => $phone_verified ? $now : null,
				'status'            => 'active',
				'created_at'        => $now,
			)
		);
		if ( $wp_user_id > 0 ) {
			update_user_meta( $wp_user_id, 'hbc_identity_uuid', $identity_uuid );
		}
		return array(
			'identity_uuid' => $identity_uuid,
			'created'       => true,
		);
	}

	public static function issue_device_token( string $identity_uuid ): string {
		global $wpdb;
		$token = 'dvt_' . bin2hex( random_bytes( 24 ) );
		$wpdb->insert(
			$wpdb->prefix . 'hbc_devices',
			array(
				'device_uuid'             => self::uuid(),
				'identity_uuid'           => $identity_uuid,
				'recognition_token_hash'  => self::hash_token( $token ),
				'verified_at'             => self::now(),
				'last_seen_at'            => self::now(),
				'status'                  => 'active',
			)
		);
		return $token;
	}

	public static function bind_admin_host_if_needed( string $identity_uuid ): void {
		global $wpdb;
		$host = self::host_uuid();
		if ( $host === '' || ! self::is_uuid( $identity_uuid ) ) {
			return;
		}
		$table = $wpdb->prefix . 'hbc_host_assignments';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT assignment_uuid, identity_uuid FROM {$table}
				WHERE host_uuid = %s AND role_key = 'host' AND revoked_at IS NULL
				ORDER BY starts_at DESC LIMIT 1",
				$host
			)
		);
		if ( ! $row || (string) $row->identity_uuid === $identity_uuid ) {
			return;
		}
		$placeholder = '00000000-0000-4000-8000-000000000001';
		// A host phone that already registered keeps the role. A placeholder or
		// an admin identity with no device can be replaced by this registration.
		if ( (string) $row->identity_uuid !== $placeholder && self::device_for_identity( (string) $row->identity_uuid ) ) {
			return;
		}
		$wpdb->update(
			$table,
			array( 'identity_uuid' => $identity_uuid ),
			array( 'assignment_uuid' => $row->assignment_uuid )
		);
	}

	public static function assignment( string $identity_uuid, string $event_uuid, string $assignment_uuid = '', string $role = '' ) {
		global $wpdb;
		$event = self::get_event( $event_uuid );
		if ( ! $event ) {
			return false;
		}
		$sql = "SELECT * FROM {$wpdb->prefix}hbc_host_assignments
			WHERE identity_uuid = %s AND host_uuid = %s AND revoked_at IS NULL
			AND starts_at <= UTC_TIMESTAMP() AND (ends_at IS NULL OR ends_at >= UTC_TIMESTAMP())";
		$args = array( $identity_uuid, $event->host_uuid );
		if ( $assignment_uuid !== '' ) {
			$sql   .= ' AND assignment_uuid = %s';
			$args[] = $assignment_uuid;
		}
		if ( $role !== '' ) {
			$sql   .= ' AND role_key = %s';
			$args[] = $role;
		}
		$sql .= ' ORDER BY FIELD(role_key, "host", "greeter") LIMIT 1';
		$row  = $wpdb->get_row( $wpdb->prepare( $sql, ...$args ) );
		if ( ! $row ) {
			return false;
		}
		return array(
			'assignment_uuid' => $row->assignment_uuid,
			'role'            => $row->role_key,
			'host_uuid'       => $row->host_uuid,
			'identity_uuid'   => $row->identity_uuid,
			'event_uuid'      => $event_uuid,
		);
	}

	/**
	 * Active $12 Miner membership. A WordPress administrator is exempt.
	 * A lapsed membership is not active. The shop-access role flag is not a membership.
	 */
	public static function user_is_active_miner( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'hbc_super_admin' ) ) {
			return true;
		}
		if ( function_exists( 'pmpro_hasMembershipLevel' ) ) {
			$level_id = 2;
			if ( class_exists( 'Cpm_Humanblockchain_Membership' ) ) {
				$mapped = (int) Cpm_Humanblockchain_Membership::get_pmpro_level_id_for_tier( 'megavoter' );
				if ( $mapped > 0 ) {
					$level_id = $mapped;
				}
			}
			return (bool) pmpro_hasMembershipLevel( $level_id, $user_id );
		}
		$raw  = get_user_meta( $user_id, '_membership_level', true );
		$meta = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $meta ) ) {
			return false;
		}
		$name = (string) ( $meta['level_name'] ?? '' );
		if ( false === stripos( $name, 'miner' ) && false === stripos( $name, 'megavoter' ) ) {
			return false;
		}
		$expires = (string) ( $meta['expires_at'] ?? $meta['enddate'] ?? '' );
		if ( $expires !== '' ) {
			$ends = strtotime( $expires );
			if ( false !== $ends && $ends < time() ) {
				return false;
			}
		}
		return true;
	}

	public static function identity_is_active_miner( string $identity_uuid ): bool {
		return self::user_is_active_miner( self::wp_user_for_identity( $identity_uuid ) );
	}

	/**
	 * The person currently assigned as host for this event's organization.
	 * An unresolved or placeholder host does not count.
	 */
	public static function event_host_identity( string $event_uuid ): string {
		global $wpdb;
		$event = self::get_event( $event_uuid );
		if ( ! $event ) {
			return '';
		}
		$identity = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT identity_uuid FROM {$wpdb->prefix}hbc_host_assignments
				WHERE host_uuid = %s AND role_key = 'host' AND revoked_at IS NULL
				AND identity_uuid <> %s
				AND starts_at <= UTC_TIMESTAMP() AND (ends_at IS NULL OR ends_at >= UTC_TIMESTAMP())
				ORDER BY starts_at DESC LIMIT 1",
				$event->host_uuid,
				'00000000-0000-4000-8000-000000000001'
			)
		);
		return is_string( $identity ) && self::is_uuid( $identity ) ? $identity : '';
	}

	/**
	 * @return array{code:string,message:string}|null
	 */
	public static function encounter_start_block( string $operator_identity, string $event_uuid ): ?array {
		if ( ! self::identity_is_active_miner( $operator_identity ) ) {
			return array(
				'code'    => 'miner_required',
				'message' => 'An active Miner membership is required to start an Encounter.',
			);
		}
		$host_identity = self::event_host_identity( $event_uuid );
		if ( $host_identity === '' ) {
			return array(
				'code'    => 'host_unresolved',
				'message' => 'This event has no host on record, so an Encounter cannot be started.',
			);
		}
		if ( ! self::identity_is_active_miner( $host_identity ) ) {
			return array(
				'code'    => 'host_miner_required',
				'message' => 'The event host needs an active Miner membership before an Encounter can start.',
			);
		}
		return null;
	}

	/**
	 * Accepted Volunteer for this event only. Pending, declined, and other events do not qualify.
	 *
	 * @return array<string, string>|null
	 */
	public static function accepted_volunteer_for_event( string $identity_uuid, string $event_uuid ): ?array {
		global $wpdb;
		if ( ! self::is_uuid( $identity_uuid ) || ! self::is_uuid( $event_uuid ) ) {
			return null;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT v.volunteer_request_uuid
				FROM {$wpdb->prefix}hbc_volunteer_requests v
				INNER JOIN {$wpdb->prefix}hbc_rsvps r ON r.rsvp_uuid = v.rsvp_uuid
				WHERE r.identity_uuid = %s AND r.event_uuid = %s AND v.state = 'accepted'
				LIMIT 1",
				$identity_uuid,
				$event_uuid
			)
		);
		if ( ! $row || empty( $row->volunteer_request_uuid ) ) {
			return null;
		}
		return array(
			'assignment_uuid' => (string) $row->volunteer_request_uuid,
			'role'            => 'volunteer',
			'identity_uuid'   => $identity_uuid,
			'event_uuid'      => $event_uuid,
		);
	}

	public static function identity_assigned_to_host( string $identity_uuid, string $host_uuid ): bool {
		global $wpdb;
		if ( ! self::is_uuid( $identity_uuid ) || ! self::is_uuid( $host_uuid ) ) {
			return false;
		}
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT assignment_uuid FROM {$wpdb->prefix}hbc_host_assignments
				WHERE identity_uuid = %s AND host_uuid = %s AND revoked_at IS NULL
				AND starts_at <= UTC_TIMESTAMP() AND (ends_at IS NULL OR ends_at >= UTC_TIMESTAMP())
				LIMIT 1",
				$identity_uuid,
				$host_uuid
			)
		);
		return is_string( $found ) && $found !== '';
	}

	public static function is_megavoter_identity( string $identity_uuid ): bool {
		$user_id = self::wp_user_for_identity( $identity_uuid );
		if ( $user_id > 0 && function_exists( 'hb_user_can_access_shop' ) ) {
			return hb_user_can_access_shop( $user_id );
		}
		return false;
	}

	public static function wp_user_for_identity( string $identity_uuid ): int {
		$users = get_users(
			array(
				'meta_key'   => 'hbc_identity_uuid',
				'meta_value' => $identity_uuid,
				'number'     => 1,
				'fields'     => 'ID',
			)
		);
		return ! empty( $users[0] ) ? (int) $users[0] : 0;
	}

	public static function save_rsvp( string $event_uuid, string $identity_uuid, string $response, array $practice = array() ) {
		global $wpdb;
		$event = self::get_event( $event_uuid );
		if ( ! $event || $event->status !== 'published' ) {
			return false;
		}
		$response = sanitize_key( $response );
		if ( ! in_array( $response, array( 'observe', 'participate', 'walk_away' ), true ) ) {
			return false;
		}

		$is_faith = $event->event_type === 'practice_faith_touchstone';
		$word     = sanitize_text_field( (string) ( $practice['touchstone_word'] ?? '' ) );
		$branch   = sanitize_text_field( (string) ( $practice['peace_pentagon_branch'] ?? '' ) );
		if ( $response === 'walk_away' ) {
			$word   = '';
			$branch = '';
		} elseif ( $is_faith ) {
			if ( ! in_array( $word, self::TOUCHSTONES, true ) || ! in_array( $branch, self::BRANCHES, true ) ) {
				return false;
			}
		} else {
			$word   = '';
			$branch = '';
		}

		$device = self::device_for_identity( $identity_uuid );
		$now    = self::now();
		$table  = $wpdb->prefix . 'hbc_rsvps';
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE event_uuid = %s AND identity_uuid = %s",
				$event_uuid,
				$identity_uuid
			)
		);

		// First-time Walk Away (no prior RSVP): no row, no negative record.
		if ( $response === 'walk_away' && ! $existing ) {
			return array(
				'rsvp_uuid'      => '',
				'event_uuid'     => $event_uuid,
				'response'       => 'walk_away',
				'event_type'     => $is_faith ? 'practice_faith_touchstone' : 'ordinary',
				'message'        => 'No RSVP was recorded.',
				'terminal_state' => 'WALKED_AWAY',
				'withdrawn'      => false,
			);
		}

		$status = $response === 'walk_away' ? 'withdrawn' : 'completed';
		$data   = array(
			'event_uuid'         => $event_uuid,
			'host_uuid'          => $event->host_uuid,
			'identity_uuid'      => $identity_uuid,
			'device_uuid'        => $device ? $device->device_uuid : ( $existing->device_uuid ?? self::uuid() ),
			'rsvp_choice'        => $response,
			'touchstone_word'    => $word ?: null,
			'peace_branch'       => $branch ?: null,
			'status'             => $status,
			'volunteer_interest' => $response === 'participate' ? 1 : 0,
			'otp_verified_at'    => $existing->otp_verified_at ?? $now,
			'completed_at'       => $existing->completed_at ?? $now,
			'updated_at'         => $now,
		);

		if ( $existing ) {
			$wpdb->update( $table, $data, array( 'rsvp_uuid' => $existing->rsvp_uuid ) );
			$rsvp_uuid = $existing->rsvp_uuid;
		} else {
			$rsvp_uuid          = self::uuid();
			$data['rsvp_uuid']  = $rsvp_uuid;
			$data['otp_verified_at'] = $now;
			$data['completed_at']    = $now;
			$wpdb->insert( $table, $data );
		}

		if ( $response === 'participate' ) {
			$existing_vol = $wpdb->get_var( $wpdb->prepare( "SELECT volunteer_request_uuid FROM {$wpdb->prefix}hbc_volunteer_requests WHERE rsvp_uuid = %s", $rsvp_uuid ) );
			if ( ! $existing_vol ) {
				$wpdb->insert(
					$wpdb->prefix . 'hbc_volunteer_requests',
					array(
						'volunteer_request_uuid' => self::uuid(),
						'rsvp_uuid'              => $rsvp_uuid,
						'host_uuid'              => $event->host_uuid,
						'state'                  => 'requested',
						'created_at'             => $now,
					)
				);
			}
		}

		if ( $response !== 'walk_away' ) {
			self::queue_message( $event->host_uuid, $identity_uuid, $event_uuid, $response === 'participate' ? 'participant_rsvp_welcome' : 'observer_rsvp_welcome', 'email' );
		}

		$out = array(
			'rsvp_uuid'                 => $rsvp_uuid,
			'event_uuid'                => $event_uuid,
			'response'                  => $response,
			'event_type'                => $is_faith ? 'practice_faith_touchstone' : 'ordinary',
			'message'                   => $response === 'walk_away'
				? 'Your previous RSVP for this event was withdrawn. You may close this page.'
				: 'Your RSVP is complete. A scan is not presence.',
			'welcome_status'            => $response === 'walk_away' ? 'not_sent' : 'queued',
			'email_confirmation_status' => $response === 'walk_away' ? 'not_applicable' : 'pending',
			'terminal_state'            => $response === 'walk_away' ? 'WALKED_AWAY' : 'RSVP_COMPLETED',
			'withdrawn'                 => $response === 'walk_away',
		);
		if ( $response === 'walk_away' ) {
			$out['pending_xp_extinguished'] = self::extinguish_pending_xp_for_event( $event_uuid, $identity_uuid );
			return $out;
		}
		if ( $is_faith ) {
			$out['touchstone_word']       = $word;
			$out['peace_pentagon_branch'] = $branch;
		} else {
			$org_code = class_exists( 'HBC_Prod_Orgs' ) ? HBC_Prod_Orgs::public_code_for_host( (string) $event->host_uuid ) : '';
			$ctx      = self::issue_return_context( $event_uuid, $org_code );
			$faith    = self::get_event( self::faith_event_uuid() );
			$offer    = array(
				'explore_url' => ! empty( $ctx['hgo_start_url'] )
					? (string) $ctx['hgo_start_url']
					: ( self::is_local() ? 'http://humangold.local/start/' : 'https://humangold.org/start/' ),
			);
			if ( empty( $ctx['error'] ) ) {
				$offer['hbc_ctx']         = (string) ( $ctx['hbc_ctx'] ?? '' );
				$offer['hbc_return_url']  = (string) ( $ctx['hbc_return_url'] ?? '' );
				$offer['llb_welcome_url'] = (string) ( $ctx['llb_welcome_url'] ?? '' );
				$offer['expires_in']      = (int) ( $ctx['expires_in'] ?? 0 );
			}
			if ( $faith ) {
				$offer['event_uuid'] = $faith->event_uuid;
				$offer['name']       = $faith->name;
			}
			$out['practice_faith_offer'] = $offer;
		}
		return $out;
	}

	public static function queue_message( string $host_uuid, string $identity_uuid, string $event_uuid, string $template, string $channel ): void {
		global $wpdb;
		$identity = $wpdb->get_row( $wpdb->prepare( "SELECT email_ciphertext, email_status, phone_status FROM {$wpdb->prefix}hbc_identities WHERE identity_uuid = %s", $identity_uuid ) );
		$welcome  = in_array( $template, array( 'otp', 'observer_rsvp_welcome', 'participant_rsvp_welcome', 'nugget_rsvp' ), true );
		$email_status = ( $identity && isset( $identity->email_status ) ) ? (string) $identity->email_status : 'pending';
		$phone_status = ( $identity && isset( $identity->phone_status ) ) ? (string) $identity->phone_status : 'pending';
		$status       = 'email' === $channel ? $email_status : $phone_status;
		$state    = ( ! $welcome && 'verified' !== $status ) ? 'suppressed' : 'queued';
		$wpdb->insert(
			$wpdb->prefix . 'hbc_message_deliveries',
			array(
				'delivery_uuid'  => self::uuid(),
				'host_uuid'      => $host_uuid,
				'identity_uuid'  => $identity_uuid,
				'event_uuid'     => $event_uuid,
				'template_key'   => $template,
				'channel'        => $channel,
				'state'          => $state,
				'failure_code'   => 'suppressed' === $state ? 'contact_unconfirmed' : null,
				'created_at'     => self::now(),
				'updated_at'     => self::now(),
			)
		);
		if ( 'suppressed' === $state || 'email' !== $channel ) {
			return;
		}
		$email    = $identity ? self::unseal( (string) $identity->email_ciphertext ) : '';
		if ( $email && is_email( $email ) ) {
			wp_mail(
				$email,
				'Human Gold',
				'Your Human Gold update (' . $template . ') is recorded. XP is not money. You may close this page.'
			);
		}
	}

	public static function issue_encounter( string $event_uuid, string $identity_uuid, string $assignment_uuid, string $role, string $encounter_type = 'gratitude' ) {
		global $wpdb;
		$event = self::get_event( $event_uuid );
		$device = self::device_for_identity( $identity_uuid );
		if ( ! $event || ! $device ) {
			return null;
		}
		$encounter_type = in_array( $encounter_type, array( 'presence', 'gratitude', 'trade' ), true ) ? $encounter_type : 'gratitude';
		if ( $encounter_type === 'trade' && ! self::can_offer_trade( $identity_uuid ) ) {
			return null;
		}
		$token         = bin2hex( random_bytes( 16 ) );
		$session_uuid  = self::uuid();
		$expires       = gmdate( 'Y-m-d H:i:s', time() + 300 );
		$wpdb->insert(
			$wpdb->prefix . 'hbc_attendance_sessions',
			array(
				'session_uuid'             => $session_uuid,
				'event_uuid'               => $event_uuid,
				'host_uuid'                => $event->host_uuid,
				'operator_identity_uuid'   => $identity_uuid,
				'operator_device_uuid'     => $device->device_uuid,
				'operator_assignment_uuid' => $assignment_uuid,
				'operator_role_at_time'    => $role,
				'encounter_type'           => $encounter_type,
				'token_hash'               => self::hash_token( $token ),
				'issued_at'                => self::now(),
				'expires_at'               => $expires,
				'state'                    => 'pending',
			)
		);
		return array(
			'encounter_token'       => $token,
			'session_uuid'          => $session_uuid,
			'encounter_type'        => $encounter_type,
			'identity_qr_image_url' => home_url( '/hbc-qr/' . rawurlencode( $token ) ),
			'expires_at'            => self::iso( $expires ),
			'encounter_url'         => home_url( '/e/' . $token ),
		);
	}

	public static function session_by_token( string $token ): ?object {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}hbc_attendance_sessions WHERE token_hash = %s",
				self::hash_token( $token )
			)
		);
		return $row instanceof stdClass ? $row : null;
	}

	public static function session_by_uuid( string $session_uuid ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}hbc_attendance_sessions WHERE session_uuid = %s", $session_uuid ) );
		return $row instanceof stdClass ? $row : null;
	}

	public static function encounter_view( object $session, array $extra = array() ): array {
		$event = self::get_event( (string) $session->event_uuid );
		$rsvp  = null;
		if ( ! empty( $session->attendee_identity_uuid ) ) {
			global $wpdb;
			$rsvp = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}hbc_rsvps WHERE event_uuid = %s AND identity_uuid = %s",
					$session->event_uuid,
					$session->attendee_identity_uuid
				)
			);
		}
		$out = array_merge(
			array(
				'state'            => $session->state,
				'event_name'       => $event ? $event->name : '',
				'session_uuid'     => $session->session_uuid,
				'encounter_type'   => (string) ( $session->encounter_type ?? 'gratitude' ),
				'expires_at'       => self::iso( $session->expires_at ),
				'presence_status'  => in_array( $session->state, array( 'confirmed', 'pending_xp', 'proximity_verified' ), true ) ? 'confirmed' : 'not_confirmed',
			),
			$extra
		);
		if ( $rsvp ) {
			$out['rsvp_response']         = $rsvp->rsvp_choice;
			$out['touchstone_word']       = $rsvp->touchstone_word;
			$out['peace_pentagon_branch'] = $rsvp->peace_branch;
		}
		return $out;
	}

	/**
	 * On RSVP Walk Away: void pending XP for this identity+event only.
	 * Matured / already-issued XP options marked mature are left untouched.
	 *
	 * @return int Number of pending rows extinguished.
	 */
	public static function extinguish_pending_xp_for_event( string $event_uuid, string $identity_uuid ): int {
		global $wpdb;
		$sessions_table = $wpdb->prefix . 'hbc_attendance_sessions';
		$now            = self::now();
		$count          = 0;

		// In-flight encounter sessions for this attendee at this event — close without XP.
		$open = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT session_uuid, state FROM {$sessions_table}
				WHERE event_uuid = %s AND attendee_identity_uuid = %s
				AND state IN ('pending','attendee_confirmed','operator_confirmed','proximity_verified','pending_xp')",
				$event_uuid,
				$identity_uuid
			)
		);
		foreach ( (array) $open as $row ) {
			$new_state = ( $row->state === 'pending_xp' ) ? 'extinguished' : 'walked_away';
			$wpdb->update(
				$sessions_table,
				array( 'state' => $new_state ),
				array( 'session_uuid' => $row->session_uuid )
			);
			if ( $row->state === 'pending_xp' ) {
				self::mark_xp_option_extinguished( (string) $row->session_uuid );
			}
			++$count;
		}

		// Also extinguish pending_xp rows where this identity is the operator and XP is still pending for the attendee? No —
		// Walk Away of RSVP is the attendee's withdrawal; only attendee-linked pending XP.

		// Option-backed XP rows still marked pending (never matured).
		$like = $wpdb->esc_like( 'hbc_xp_' ) . '%';
		$opts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like
			)
		);
		foreach ( (array) $opts as $opt ) {
			$data = maybe_unserialize( $opt->option_value );
			if ( ! is_array( $data ) ) {
				continue;
			}
			if ( (string) ( $data['identity_uuid'] ?? '' ) !== $identity_uuid ) {
				continue;
			}
			$status = sanitize_key( (string) ( $data['status'] ?? 'issued' ) );
			if ( ! in_array( $status, array( 'pending', 'pending_xp' ), true ) ) {
				continue;
			}
			$session_uuid = (string) ( $data['session_uuid'] ?? '' );
			if ( $session_uuid === '' ) {
				continue;
			}
			$session = self::session_by_uuid( $session_uuid );
			if ( ! $session || (string) $session->event_uuid !== $event_uuid ) {
				continue;
			}
			$data['status']         = 'extinguished';
			$data['extinguished_at'] = $now;
			$data['extinguish_reason'] = 'rsvp_walk_away';
			update_option( $opt->option_name, $data, false );
			if ( $session->state === 'pending_xp' ) {
				$wpdb->update(
					$sessions_table,
					array( 'state' => 'extinguished' ),
					array( 'session_uuid' => $session_uuid )
				);
			}
			++$count;
		}

		return $count;
	}

	/**
	 * @param string $session_uuid Session / confirmation id used as option key suffix.
	 */
	private static function mark_xp_option_extinguished( string $session_uuid ): void {
		$key  = 'hbc_xp_' . $session_uuid;
		$data = get_option( $key );
		if ( ! is_array( $data ) ) {
			return;
		}
		$status = sanitize_key( (string) ( $data['status'] ?? 'issued' ) );
		if ( ! in_array( $status, array( 'pending', 'pending_xp' ), true ) ) {
			return;
		}
		$data['status']            = 'extinguished';
		$data['extinguished_at']   = self::now();
		$data['extinguish_reason'] = 'rsvp_walk_away';
		update_option( $key, $data, false );
	}

	public static function issue_xp( string $confirmation_id, string $identity_uuid, string $session_uuid ): string {
		$key = 'hbc_xp_' . $confirmation_id;
		if ( get_option( $key ) ) {
			$existing = get_option( $key );
			if ( is_array( $existing ) && sanitize_key( (string) ( $existing['status'] ?? '' ) ) === 'extinguished' ) {
				return 'extinguished';
			}
			return 'issued';
		}
		update_option(
			$key,
			array(
				'amount'        => self::XP_AMOUNT,
				'identity_uuid' => $identity_uuid,
				'session_uuid'  => $session_uuid,
				'status'        => 'issued',
				'issued_at'     => self::now(),
			),
			false
		);
		$user_id = self::wp_user_for_identity( $identity_uuid );
		if ( $user_id > 0 && class_exists( 'Cpm_Humanblockchain_Xp_Ledger' ) ) {
			do_action(
				'cpm_hb_xp_ledger_row_saved',
				array(
					'wp_user_id'     => $user_id,
					'scan_type'      => 'human_gold_encounter',
					'transaction_id' => $confirmation_id,
					'xp_units'       => self::XP_AMOUNT,
					'scan_status'    => 'confirmed',
					'event_kind'     => 'insert',
				)
			);
		}
		self::mirror_xp_to_smallstreet(
			array(
				'confirmation_id' => $confirmation_id,
				'identity_uuid'   => $identity_uuid,
				'amount'          => self::XP_AMOUNT,
				'source'          => 'human_gold_encounter',
			)
		);
		return 'issued';
	}

	public static function sweep_orphans(): void {
		global $wpdb;
		$now = self::now();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}hbc_attendance_sessions
				SET state = 'expired'
				WHERE state IN ('pending','attendee_confirmed','operator_confirmed')
				AND expires_at < %s",
				$now
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}hbc_rsvps
				SET status = 'expired', updated_at = %s
				WHERE status IN ('pending','started') AND completed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)",
				$now
			)
		);
		update_option( 'hbc_prod_last_sweep', $now, false );
	}

	public static function last_sweep_ok(): bool {
		$last = (string) get_option( 'hbc_prod_last_sweep', '' );
		if ( $last === '' ) {
			return false;
		}
		return ( time() - strtotime( $last . ' UTC' ) ) < 10 * MINUTE_IN_SECONDS;
	}
}
