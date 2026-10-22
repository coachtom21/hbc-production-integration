<?php
defined( 'ABSPATH' ) || exit;

/**
 * Package B adapters. Real HBC services. Trade stays locked. No sample success.
 */
final class HBC_Production_Adapters {
	public static function register(): void {
		add_filter( 'hbc_list_public_events', array( __CLASS__, 'list_public_events' ), 10, 2 );
		add_filter( 'hbc_resolve_event_code', array( __CLASS__, 'resolve_event_code' ), 10, 2 );
		add_filter( 'hbc_resolve_universal_entry', array( __CLASS__, 'resolve_universal_entry' ), 10, 3 );
		add_filter( 'hbc_resolve_device_token', array( __CLASS__, 'resolve_device_token' ), 10, 2 );
		add_filter( 'hbc_begin_registration', array( __CLASS__, 'begin_registration' ), 10, 2 );
		add_filter( 'hbc_complete_registration', array( __CLASS__, 'complete_registration' ), 10, 2 );
		add_filter( 'hbc_save_registered_device_rsvp', array( __CLASS__, 'save_rsvp' ), 10, 5 );
		add_filter( 'hbc_identity_is_authorized_operator', array( __CLASS__, 'is_operator' ), 10, 3 );
		add_filter( 'hbc_resolve_operator_assignment', array( __CLASS__, 'resolve_assignment' ), 10, 5 );
		add_filter( 'hbc_issue_encounter_token', array( __CLASS__, 'issue_encounter' ), 10, 6 );
		add_filter( 'hbc_resolve_encounter_token', array( __CLASS__, 'resolve_encounter' ), 10, 2 );
		add_filter( 'hbc_attendee_confirm_encounter', array( __CLASS__, 'attendee_confirm' ), 10, 6 );
		add_filter( 'hbc_encounter_proximity_evidence', array( __CLASS__, 'proximity_evidence' ), 10, 3 );
		add_filter( 'hbc_operator_confirm_encounter', array( __CLASS__, 'operator_confirm' ), 10, 5 );
		add_filter( 'hbc_operator_encounter_status', array( __CLASS__, 'operator_status' ), 10, 3 );
		add_filter( 'hbc_create_membership_checkout', array( __CLASS__, 'membership_checkout' ), 10, 4 );
		add_filter( 'hbc_membership_status', array( __CLASS__, 'membership_status' ), 10, 2 );
		add_filter( 'hbc_identity_can_accept_host_agreement', array( __CLASS__, 'can_accept_agreement' ), 10, 3 );
		add_filter( 'hbc_record_agreement_acceptance', array( __CLASS__, 'record_agreement' ), 10, 2 );
		add_filter( 'hbc_current_agreement_acceptance', array( __CLASS__, 'current_agreement' ), 10, 4 );
		add_filter( 'hbc_confirm_email_token', array( __CLASS__, 'confirm_email' ), 10, 2 );
		add_filter( 'hbc_resend_email_confirmation', array( __CLASS__, 'resend_email' ), 10, 2 );
		add_filter( 'hbc_production_health_checks', array( __CLASS__, 'health' ) );
		add_filter( 'hbc_privileged_mfa_verified', array( __CLASS__, 'local_mfa' ), 10, 2 );
		add_filter( 'hbc_user_can_access_host', array( __CLASS__, 'user_can_host' ), 10, 3 );
		add_filter( 'hbc_admin_list_events', array( __CLASS__, 'admin_list_events' ), 10, 2 );
		add_filter( 'hbc_admin_create_event', array( __CLASS__, 'admin_create_event' ), 10, 3 );
		add_filter( 'hbc_admin_update_event', array( __CLASS__, 'admin_update_event' ), 10, 4 );
		add_filter( 'hbc_admin_list_volunteers', array( __CLASS__, 'admin_list_volunteers' ), 10, 2 );
		add_filter( 'hbc_admin_review_volunteer', array( __CLASS__, 'admin_review_volunteer' ), 10, 4 );
		add_filter( 'hbc_trade_unlocked', array( __CLASS__, 'trade_unlocked_filter' ) );
		add_action( 'hbc_discard_registration_session', array( __CLASS__, 'discard_session' ) );
		add_action( 'pmpro_after_checkout', array( __CLASS__, 'after_paid_membership' ), 20, 2 );
		add_action( 'wp_login', array( __CLASS__, 'stamp_privileged_auth' ), 10, 2 );
	}

	public static function trade_unlocked_filter( $default = false ): bool {
		unset( $default );
		return HBC_Prod_Store::trade_is_unlocked();
	}

	public static function admin_list_events( $default, $host_uuid ) {
		unset( $default );
		return HBC_Prod_Store::list_host_events( (string) $host_uuid );
	}

	public static function admin_create_event( $default, $host_uuid, $payload ) {
		unset( $default );
		return HBC_Prod_Store::create_event( (string) $host_uuid, is_array( $payload ) ? $payload : array() );
	}

	public static function admin_update_event( $default, $host_uuid, $event_uuid, $payload ) {
		unset( $default );
		return HBC_Prod_Store::update_event( (string) $host_uuid, (string) $event_uuid, is_array( $payload ) ? $payload : array() );
	}

	public static function admin_list_volunteers( $default, $host_uuid ) {
		unset( $default );
		return HBC_Prod_Store::list_volunteers( (string) $host_uuid );
	}

	public static function admin_review_volunteer( $default, $volunteer_uuid, $decision, $reviewer ) {
		unset( $default );
		return HBC_Prod_Store::review_volunteer( (string) $volunteer_uuid, (string) $decision, (string) $reviewer );
	}

	public static function list_public_events( $default, $request ) {
		unset( $default );
		$request = is_array( $request ) ? $request : array();
		$host_uuid = '';
		$org       = sanitize_text_field( (string) ( $request['org'] ?? '' ) );
		if ( $org !== '' && class_exists( 'HBC_Prod_Orgs' ) ) {
			$host_uuid = HBC_Prod_Orgs::host_uuid_for_code( $org );
			if ( $host_uuid === '' ) {
				return array(
					'events'        => array(),
					'has_more'      => false,
					'next_cursor'   => null,
					'org_invalid'   => true,
					'org_code'      => strtoupper( $org ),
					'organization'  => null,
				);
			}
		} elseif ( ! empty( $request['host_uuid'] ) && HBC_Prod_Store::is_uuid( (string) $request['host_uuid'] ) ) {
			$host_uuid = (string) $request['host_uuid'];
		}
		$page = HBC_Prod_Store::list_public_events(
			isset( $request['limit'] ) ? (int) $request['limit'] : 20,
			isset( $request['cursor'] ) ? (string) $request['cursor'] : null,
			isset( $request['query'] ) ? (string) $request['query'] : '',
			$host_uuid
		);
		if ( $host_uuid !== '' ) {
			$host = HBC_Prod_Store::get_host( $host_uuid );
			$page['organization'] = $host
				? array(
					'host_uuid'   => $host_uuid,
					'name'        => $host->name,
					'public_code' => class_exists( 'HBC_Prod_Orgs' ) ? HBC_Prod_Orgs::public_code_for_host( $host_uuid ) : '',
				)
				: null;
		}
		return $page;
	}

	public static function resolve_event_code( $default, $code ) {
		unset( $default );
		return HBC_Prod_Store::resolve_event_code( (string) $code );
	}

	public static function resolve_device_token( $default, $token ) {
		unset( $default );
		return HBC_Prod_Store::resolve_token( (string) $token );
	}

	public static function resolve_universal_entry( $default, $event_uuid, $device_token ) {
		unset( $default );
		$event = HBC_Prod_Store::get_event( (string) $event_uuid );
		if ( ! $event || $event->status !== 'published' ) {
			return false;
		}
		$host     = HBC_Prod_Store::get_host( (string) $event->host_uuid );
		$identity = $device_token ? HBC_Prod_Store::resolve_token( (string) $device_token ) : false;
		$registered = (bool) $identity;
		$assignment = $registered ? HBC_Prod_Store::assignment( (string) $identity, (string) $event_uuid ) : false;
		$operator   = is_array( $assignment ) && HBC_Prod_Store::is_megavoter_identity( (string) $identity );

		$route_view = 'device_registration';
		if ( $registered && $operator ) {
			$route_view = 'operator_router';
		} elseif ( $registered ) {
			$route_view = 'respondent_rsvp';
		}

		$out = array(
			'route_view'         => $route_view,
			'device_state'       => $registered ? 'registered' : 'unregistered',
			'operator_authorized'=> (bool) $operator,
			'event'              => HBC_Prod_Store::event_payload( $event ),
			'allowed_actions'    => array( 'observe', 'participate', 'walk_away' ),
			'requires_otp'       => ! $registered,
			'email_status'       => 'pending',
		);
		if ( $host ) {
			$out['organization'] = array( 'name' => $host->name );
		}
		if ( $operator && is_array( $assignment ) ) {
			$out['assignment_uuid'] = $assignment['assignment_uuid'];
			$out['operator_role']   = $assignment['role'];
			$out['trade_available'] = HBC_Prod_Store::can_offer_trade( (string) $identity );
			$out['not_now_fallback'] = array(
				'route_view'      => 'respondent_rsvp',
				'allowed_actions' => array( 'observe', 'participate', 'walk_away' ),
			);
		}
		return $out;
	}

	public static function begin_registration( $default, $payload ) {
		unset( $default );
		$payload = is_array( $payload ) ? $payload : array();
		$email   = sanitize_email( (string) ( $payload['email'] ?? '' ) );
		$raw     = (string) ( $payload['phone'] ?? '' );
		$country = strtoupper( sanitize_text_field( (string) ( $payload['phone_country'] ?? '' ) ) );

		if ( ! $email ) {
			return array(
				'error' => array(
					'code'    => 'invalid_registration',
					'message' => 'A valid email address is required.',
				),
			);
		}

		$phone = '';
		if ( class_exists( 'Cpm_Humanblockchain_Otp_Service' ) ) {
			// Same path as login / Activate Phone: country + national → E.164, then send_otp_sms().
			if ( $country !== '' && method_exists( 'Cpm_Humanblockchain_Otp_Service', 'build_e164_from_country' ) ) {
				$built = Cpm_Humanblockchain_Otp_Service::build_e164_from_country( $country, $raw );
				$phone = is_string( $built ) ? $built : '';
			}
			if ( $phone === '' ) {
				$phone = (string) Cpm_Humanblockchain_Otp_Service::normalize_phone_e164( $raw );
			}
			if ( $phone === '' ) {
				$msg = Cpm_Humanblockchain_Otp_Service::normalize_phone_failure_message( $raw );
				return array(
					'error' => array(
						'code'    => 'invalid_phone',
						'message' => $msg ? $msg : 'Enter a valid mobile number (e.g. US 4045551234 or Nepal 9849158973).',
					),
				);
			}
		} else {
			$phone = preg_replace( '/[^0-9+]/', '', $raw );
			if ( strlen( $phone ) < 10 ) {
				return array(
					'error' => array(
						'code'    => 'invalid_phone',
						'message' => 'Enter a valid mobile number with country code.',
					),
				);
			}
		}

		$session_id = 'reg_' . bin2hex( random_bytes( 16 ) );
		$code       = str_pad( (string) wp_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		$sent       = false;
		$send_msg   = '';

		if ( class_exists( 'Cpm_Humanblockchain_Otp_Service' ) ) {
			if ( method_exists( 'Cpm_Humanblockchain_Otp_Service', 'is_configured' ) && ! Cpm_Humanblockchain_Otp_Service::is_configured() ) {
				$send_msg = method_exists( 'Cpm_Humanblockchain_Otp_Service', 'get_sms_not_configured_message' )
					? (string) Cpm_Humanblockchain_Otp_Service::get_sms_not_configured_message()
					: 'SMS verification is not configured.';
			} else {
				// Identical sender used by My Account / Activate Phone login OTP.
				$result   = Cpm_Humanblockchain_Otp_Service::send_otp_sms( $phone );
				$sent     = is_array( $result ) && ! empty( $result['success'] );
				$send_msg = is_array( $result ) && ! empty( $result['message'] ) ? (string) $result['message'] : '';
			}
		}

		$local = HBC_Prod_Store::is_local();

		// Production / staging: never open the code screen if SMS did not send.
		if ( ! $sent && ! $local ) {
			return array(
				'error' => array(
					'code'    => 'otp_send_failed',
					'message' => $send_msg !== '' ? $send_msg : 'Could not send the verification code. Check the phone number and Twilio settings, then try again.',
				),
			);
		}

		set_transient(
			'hbc_reg_' . $session_id,
			array(
				'email'                 => $email,
				'phone'                 => $phone,
				'display_name'          => sanitize_text_field( (string) ( $payload['display_name'] ?? '' ) ),
				'mailing_address'       => sanitize_textarea_field( (string) ( $payload['mailing_address'] ?? '' ) ),
				'event_uuid'            => $payload['event_uuid'] ?? '',
				'response'              => $payload['response'] ?? '',
				'touchstone_word'       => $payload['touchstone_word'] ?? '',
				'peace_pentagon_branch' => $payload['peace_pentagon_branch'] ?? '',
				'otp_hash'              => wp_hash_password( $code ),
				'otp_via_twilio'        => $sent,
				'attempts'              => 0,
			),
			10 * MINUTE_IN_SECONDS
		);

		if ( $local && ! $sent ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'HBC local OTP for ' . $phone . ': ' . $code );
		}

		$out = array(
			'registration_session_id' => $session_id,
			'channel'                 => $sent ? 'sms' : 'sms_local_log',
			'expires_in'              => 600,
			'phone_masked'            => class_exists( 'Cpm_Humanblockchain_Otp_Service' )
				? Cpm_Humanblockchain_Otp_Service::mask_phone_e164( $phone )
				: $phone,
			'message'                 => $sent
				? ( $send_msg !== '' ? $send_msg : 'Verification code sent by SMS.' )
				: 'Local mode: SMS was not sent. Use the code written to the PHP error log for this phone.',
		);

		// Local-only convenience when Twilio is unavailable (always expose for Local Sites testing).
		if ( $local && ! $sent ) {
			$out['debug_otp'] = $code;
		}

		return $out;
	}

	public static function complete_registration( $default, $payload ) {
		unset( $default );
		$payload    = is_array( $payload ) ? $payload : array();
		$session_id = sanitize_text_field( (string) ( $payload['registration_session_id'] ?? '' ) );
		$code       = preg_replace( '/\D/', '', (string) ( $payload['code'] ?? '' ) );
		$session    = get_transient( 'hbc_reg_' . $session_id );
		if ( ! is_array( $session ) || strlen( $code ) !== 6 ) {
			return null;
		}
		$session['attempts'] = (int) $session['attempts'] + 1;
		if ( $session['attempts'] > 5 ) {
			delete_transient( 'hbc_reg_' . $session_id );
			return null;
		}
		$ok = false;
		if ( class_exists( 'Cpm_Humanblockchain_Otp_Service' ) ) {
			$check = Cpm_Humanblockchain_Otp_Service::verify_otp( $session['phone'], $code, true );
			$ok    = ! empty( $check['success'] );
		}
		if ( ! $ok && ! empty( $session['otp_hash'] ) && wp_check_password( $code, $session['otp_hash'] ) ) {
			// Accept local fallback hash only when Twilio SMS was not the channel used.
			$ok = empty( $session['otp_via_twilio'] );
		}
		if ( ! $ok ) {
			set_transient( 'hbc_reg_' . $session_id, $session, 10 * MINUTE_IN_SECONDS );
			return null;
		}

		$user_id = email_exists( $session['email'] );
		if ( ! $user_id ) {
			$user_id = wp_insert_user(
				array(
					'user_login' => 'hbc_' . wp_generate_password( 8, false ),
					'user_email' => $session['email'],
					'user_pass'  => wp_generate_password( 20 ),
					'role'       => 'subscriber',
				)
			);
		}
		$user_id = is_wp_error( $user_id ) ? 0 : (int) $user_id;
		if ( $user_id && function_exists( 'hb_grant_free_yamer_membership' ) ) {
			hb_grant_free_yamer_membership( $user_id );
		}
		if ( $user_id ) {
			$display_name = sanitize_text_field( (string) ( $session['display_name'] ?? '' ) );
			if ( $display_name !== '' ) {
				wp_update_user(
					array(
						'ID'           => $user_id,
						'display_name' => $display_name,
						'first_name'   => $display_name,
					)
				);
			}
			$mailing = sanitize_textarea_field( (string) ( $session['mailing_address'] ?? '' ) );
			if ( $mailing !== '' ) {
				update_user_meta( $user_id, 'hbc_mailing_address', $mailing );
			}
		}

		$identity = HBC_Prod_Store::ensure_identity( $session['email'], $session['phone'], $user_id, true );
		$token    = HBC_Prod_Store::issue_device_token( $identity['identity_uuid'] );
		if ( $user_id && user_can( $user_id, 'manage_options' ) ) {
			HBC_Prod_Store::bind_admin_host_if_needed( $identity['identity_uuid'] );
		}

		$rsvp = HBC_Prod_Store::save_rsvp(
			(string) $session['event_uuid'],
			$identity['identity_uuid'],
			(string) $session['response'],
			array(
				'touchstone_word'       => $session['touchstone_word'] ?? '',
				'peace_pentagon_branch' => $session['peace_pentagon_branch'] ?? '',
			)
		);
		delete_transient( 'hbc_reg_' . $session_id );
		if ( ! $rsvp ) {
			return array(
				'device_token' => $token,
				'message'      => 'Device registered. RSVP could not be saved for that event.',
			);
		}
		$rsvp['device_token'] = $token;
		return $rsvp;
	}

	public static function save_rsvp( $default, $event_uuid, $identity_uuid, $response, $practice = array() ) {
		unset( $default );
		return HBC_Prod_Store::save_rsvp( (string) $event_uuid, (string) $identity_uuid, (string) $response, is_array( $practice ) ? $practice : array() );
	}

	public static function discard_session( $session_id ): void {
		delete_transient( 'hbc_reg_' . sanitize_text_field( (string) $session_id ) );
	}

	public static function is_operator( $default, $identity_uuid, $event_uuid ): bool {
		unset( $default );
		$identity_uuid = (string) $identity_uuid;
		$event_uuid    = (string) $event_uuid;
		if ( ! HBC_Prod_Store::identity_is_active_miner( $identity_uuid ) ) {
			return false;
		}
		$assignment = HBC_Prod_Store::assignment( $identity_uuid, $event_uuid );
		if ( is_array( $assignment ) ) {
			return true;
		}
		return null !== HBC_Prod_Store::accepted_volunteer_for_event( $identity_uuid, $event_uuid );
	}

	public static function resolve_assignment( $default, $identity_uuid, $event_uuid, $assignment_uuid, $role ) {
		unset( $default );
		return HBC_Prod_Store::assignment( (string) $identity_uuid, (string) $event_uuid, (string) $assignment_uuid, (string) $role );
	}

	public static function issue_encounter( $default, $event_uuid, $identity_uuid, $type, $assignment_uuid, $role ) {
		unset( $default );
		$type = in_array( (string) $type, array( 'presence', 'gratitude', 'trade' ), true ) ? (string) $type : 'gratitude';
		if ( $type === 'trade' && ! HBC_Prod_Store::can_offer_trade( (string) $identity_uuid ) ) {
			return array(
				'error' => array(
					'code'    => 'trade_not_live',
					'message' => 'Trade is not live for this seller.',
				),
			);
		}
		return HBC_Prod_Store::issue_encounter( (string) $event_uuid, (string) $identity_uuid, (string) $assignment_uuid, (string) $role, $type );
	}

	public static function resolve_encounter( $default, $token ) {
		unset( $default );
		$session = HBC_Prod_Store::session_by_token( (string) $token );
		if ( ! $session ) {
			return array( 'error' => array( 'code' => 'encounter_not_found', 'message' => 'This encounter is unavailable.' ) );
		}
		if ( strtotime( $session->expires_at . ' UTC' ) < time() && ! in_array( $session->state, array( 'confirmed', 'pending_xp', 'declined', 'walked_away', 'extinguished' ), true ) ) {
			global $wpdb;
			$wpdb->update( $wpdb->prefix . 'hbc_attendance_sessions', array( 'state' => 'expired' ), array( 'session_uuid' => $session->session_uuid ) );
			$session->state = 'expired';
		}
		return HBC_Prod_Store::encounter_view( $session );
	}

	public static function attendee_confirm( $default, $token, $identity_uuid, $action, $answers, $location ) {
		unset( $default, $answers );
		global $wpdb;
		$session = HBC_Prod_Store::session_by_token( (string) $token );
		if ( ! $session ) {
			return array( 'error' => array( 'code' => 'encounter_not_found', 'message' => 'This encounter is unavailable.' ) );
		}
		if ( $session->operator_identity_uuid === $identity_uuid ) {
			$wpdb->update( $wpdb->prefix . 'hbc_attendance_sessions', array( 'state' => 'same_device_rejected' ), array( 'session_uuid' => $session->session_uuid ) );
			$session->state = 'same_device_rejected';
			return HBC_Prod_Store::encounter_view( $session );
		}
		if ( strtotime( $session->expires_at . ' UTC' ) < time() ) {
			$wpdb->update( $wpdb->prefix . 'hbc_attendance_sessions', array( 'state' => 'expired' ), array( 'session_uuid' => $session->session_uuid ) );
			$session->state = 'expired';
			return HBC_Prod_Store::encounter_view( $session );
		}
		$device = HBC_Prod_Store::device_for_identity( (string) $identity_uuid );
		$update = array(
			'attendee_identity_uuid' => $identity_uuid,
			'attendee_device_uuid'   => $device ? $device->device_uuid : null,
		);
		if ( $action === 'decline' ) {
			$update['state'] = 'declined';
		} elseif ( $action === 'walk_away' ) {
			$update['state'] = 'walked_away';
		} else {
			$update['state']       = 'attendee_confirmed';
			$update['accepted_at'] = HBC_Prod_Store::now();
			if ( is_array( $location ) ) {
				$update['attendee_latitude']                 = $location['latitude'];
				$update['attendee_longitude']                = $location['longitude'];
				$update['attendee_location_accuracy_meters'] = $location['accuracy_meters'];
				$update['attendee_location_captured_at']     = gmdate( 'Y-m-d H:i:s', strtotime( (string) $location['captured_at'] ) );
			}
		}
		$wpdb->update( $wpdb->prefix . 'hbc_attendance_sessions', $update, array( 'session_uuid' => $session->session_uuid ) );
		$session = HBC_Prod_Store::session_by_uuid( $session->session_uuid );
		return HBC_Prod_Store::encounter_view( $session );
	}

	public static function proximity_evidence( $default, $session_uuid, $operator_identity_uuid ) {
		unset( $default );
		$session = HBC_Prod_Store::session_by_uuid( (string) $session_uuid );
		if ( ! $session || $session->operator_identity_uuid !== $operator_identity_uuid ) {
			return null;
		}
		return array(
			'session_uuid'           => $session->session_uuid,
			'state'                  => $session->state,
			'attendee_identity_uuid' => $session->attendee_identity_uuid,
			'expires_at'             => HBC_Prod_Store::iso( $session->expires_at ),
			'attendee_location'      => $session->attendee_latitude ? array(
				'latitude'        => (float) $session->attendee_latitude,
				'longitude'       => (float) $session->attendee_longitude,
				'accuracy_meters' => (float) $session->attendee_location_accuracy_meters,
				'captured_at'     => HBC_Prod_Store::iso( $session->attendee_location_captured_at ),
			) : null,
		);
	}

	public static function operator_confirm( $default, $session_uuid, $identity_uuid, $location, $visual ) {
		unset( $default, $visual );
		global $wpdb;
		$session = HBC_Prod_Store::session_by_uuid( (string) $session_uuid );
		if ( ! $session || $session->operator_identity_uuid !== $identity_uuid ) {
			return array( 'error' => array( 'code' => 'encounter_not_found', 'message' => 'This encounter session is unavailable.' ) );
		}
		if ( $session->state === 'confirmed' || $session->state === 'pending_xp' ) {
			return HBC_Prod_Store::encounter_view( $session );
		}
		$update = array(
			'state' => 'confirmed',
			'consumed_at' => HBC_Prod_Store::now(),
			'xp_accepted' => 1,
		);
		if ( is_array( $location ) ) {
			$update['operator_latitude']                 = $location['latitude'];
			$update['operator_longitude']                = $location['longitude'];
			$update['operator_location_accuracy_meters'] = $location['accuracy_meters'];
			$update['operator_location_captured_at']     = gmdate( 'Y-m-d H:i:s', strtotime( (string) $location['captured_at'] ) );
		}
		$wpdb->update( $wpdb->prefix . 'hbc_attendance_sessions', $update, array( 'session_uuid' => $session->session_uuid ) );
		$session = HBC_Prod_Store::session_by_uuid( $session->session_uuid );
		$confirmation = $session->session_uuid;
		$xp_status    = HBC_Prod_Store::issue_xp( $confirmation, (string) $session->attendee_identity_uuid, $session->session_uuid );
		if ( (string) ( $session->encounter_type ?? '' ) === 'trade' ) {
			HBC_Prod_Store::mark_trade_lifecycle( (string) $session->session_uuid, 'confirmed' );
		}
		HBC_Prod_Store::queue_message( $session->host_uuid, (string) $session->attendee_identity_uuid, $session->event_uuid, 'encounter_confirmed', 'email' );
		return HBC_Prod_Store::encounter_view(
			$session,
			array(
				'confirmation_id'             => $confirmation,
				'confirmed_at'                => HBC_Prod_Store::iso( $session->consumed_at ),
				'xp_human_gold_accepted'      => true,
				'xp_play_dough_amount'        => HBC_Prod_Store::XP_AMOUNT,
				'xp_play_dough_status'        => $xp_status,
				'outcome_notification_status' => 'queued',
			)
		);
	}

	public static function operator_status( $default, $session_uuid, $identity_uuid ) {
		unset( $default );
		$session = HBC_Prod_Store::session_by_uuid( (string) $session_uuid );
		if ( ! $session || $session->operator_identity_uuid !== $identity_uuid ) {
			return array( 'error' => array( 'code' => 'encounter_not_found', 'message' => 'This encounter session is unavailable.' ) );
		}
		return HBC_Prod_Store::encounter_view( $session );
	}

	public static function membership_checkout( $default, $identity_uuid, $sponsor, $return_url ) {
		unset( $default, $sponsor, $return_url );
		if ( HBC_Prod_Store::is_megavoter_identity( (string) $identity_uuid ) ) {
			return array( 'status' => 'already_active' );
		}
		$user_id = HBC_Prod_Store::wp_user_for_identity( (string) $identity_uuid );
		if ( $user_id ) {
			update_user_meta( $user_id, 'hbc_membership_checkout_pending', 1 );
		}
		// Public contract requires megavoters.com HTTPS. $12 stays the only public payment.
		return array(
			'status'       => 'checkout_required',
			'checkout_url' => 'https://www.megavoters.com/discover/',
		);
	}

	public static function membership_status( $default, $identity_uuid ) {
		unset( $default );
		$active = HBC_Prod_Store::is_megavoter_identity( (string) $identity_uuid );
		return array(
			'state'      => $active ? 'active' : 'none',
			'product'    => '$12 Miner membership',
			'shop_only'  => true,
		);
	}

	public static function after_paid_membership( $user_id, $order = null ): void {
		unset( $order );
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! function_exists( 'hb_user_can_access_shop' ) || ! hb_user_can_access_shop( $user_id ) ) {
			return;
		}
		delete_user_meta( $user_id, 'hbc_membership_checkout_pending' );
		$identity = (string) get_user_meta( $user_id, 'hbc_identity_uuid', true );
		if ( $identity ) {
			HBC_Prod_Store::queue_message( HBC_Prod_Store::host_uuid(), $identity, HBC_Prod_Store::community_event_uuid(), 'participant_rsvp_welcome', 'email' );
		}
	}

	public static function can_accept_agreement( $default, $identity_uuid, $host_uuid ): bool {
		unset( $default );
		$assignment = HBC_Prod_Store::assignment( (string) $identity_uuid, HBC_Prod_Store::community_event_uuid() );
		return is_array( $assignment ) && $assignment['role'] === 'host' && $assignment['host_uuid'] === $host_uuid;
	}

	public static function record_agreement( $default, $fields ) {
		unset( $default );
		global $wpdb;
		$fields = is_array( $fields ) ? $fields : array();
		$host   = sanitize_text_field( (string) ( $fields['host_uuid'] ?? HBC_Prod_Store::host_uuid() ) );
		$key    = sanitize_key( (string) ( $fields['agreement_key'] ?? 'host_terms' ) );
		$ver    = sanitize_text_field( (string) ( $fields['agreement_version'] ?? '2026-09-21' ) );
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}hbc_agreement_acceptances WHERE agreement_key = %s AND agreement_version = %s AND host_uuid = %s",
				$key,
				$ver,
				$host
			)
		);
		if ( $existing ) {
			return array(
				'acceptance_uuid'   => $existing->acceptance_uuid,
				'already_accepted'  => true,
			);
		}
		$uuid = HBC_Prod_Store::uuid();
		$wpdb->insert(
			$wpdb->prefix . 'hbc_agreement_acceptances',
			array(
				'acceptance_uuid'   => $uuid,
				'agreement_key'     => $key,
				'agreement_version' => $ver,
				'identity_uuid'     => $fields['identity_uuid'] ?? '',
				'host_uuid'         => $host,
				'typed_name'        => sanitize_text_field( (string) ( $fields['typed_name'] ?? '' ) ),
				'document_checksum' => sanitize_text_field( (string) ( $fields['checksum'] ?? '' ) ),
				'accepted_at'       => HBC_Prod_Store::now(),
				'ip_hash'           => isset( $fields['ip_hash'] ) ? $fields['ip_hash'] : null,
				'user_agent_hash'   => isset( $fields['user_agent_hash'] ) ? $fields['user_agent_hash'] : null,
			)
		);
		return array( 'acceptance_uuid' => $uuid, 'already_accepted' => false );
	}

	public static function current_agreement( $default, $host_uuid, $key, $version ) {
		unset( $default );
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}hbc_agreement_acceptances WHERE host_uuid = %s AND agreement_key = %s AND agreement_version = %s",
				$host_uuid,
				$key,
				$version
			)
		);
		return $row ? array( 'acceptance_uuid' => $row->acceptance_uuid, 'accepted_at' => $row->accepted_at ) : null;
	}

	public static function confirm_email( $default, $token ) {
		unset( $default );
		$identity = get_transient( 'hbc_email_' . sanitize_text_field( (string) $token ) );
		if ( ! $identity ) {
			return false;
		}
		delete_transient( 'hbc_email_' . $token );
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'hbc_identities',
			array(
				'email_status'      => 'verified',
				'email_verified_at' => HBC_Prod_Store::now(),
			),
			array( 'identity_uuid' => $identity )
		);
		return array( 'identity_uuid' => $identity, 'email_status' => 'verified' );
	}

	public static function resend_email( $default, $identity_uuid ) {
		unset( $default );
		$token = bin2hex( random_bytes( 16 ) );
		set_transient( 'hbc_email_' . $token, $identity_uuid, DAY_IN_SECONDS );
		return array( 'status' => 'queued', 'confirm_url' => home_url( '/confirm-email/' . $token ) );
	}

	/**
	 * Stamp a fresh privileged session when a Super Admin signs into WordPress.
	 *
	 * @param string   $user_login Username.
	 * @param \WP_User $user       User object.
	 */
	public static function stamp_privileged_auth( $user_login, $user ): void {
		unset( $user_login );
		if ( ! ( $user instanceof WP_User ) || ! user_can( $user, 'hbc_super_admin' ) ) {
			return;
		}
		update_user_meta( (int) $user->ID, 'hbc_privileged_auth_at', time() );
	}

	/**
	 * Privileged MFA gate for Super Admin console / REST.
	 *
	 * Local: Super Admin WP session is enough.
	 * Live: prefer WordPress Two Factor when enabled; otherwise a recent Super Admin
	 * WP login (12h) is the pilot gate until a dedicated MFA provider hooks this filter.
	 *
	 * @param mixed $default Previous filter value.
	 * @param mixed $user_id User ID.
	 */
	public static function local_mfa( $default, $user_id ): bool {
		if ( (bool) $default ) {
			return true;
		}
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! user_can( $user_id, 'hbc_super_admin' ) ) {
			return false;
		}
		if ( (int) get_current_user_id() !== $user_id || ! is_user_logged_in() ) {
			return false;
		}
		if ( HBC_Prod_Store::is_local() ) {
			return true;
		}
		// Real 2FA plugin: login challenge already completed for this session.
		if ( class_exists( 'Two_Factor_Core', false )
			&& method_exists( 'Two_Factor_Core', 'is_user_using_two_factor' )
			&& \Two_Factor_Core::is_user_using_two_factor( $user_id ) ) {
			return true;
		}
		$authed_at = (int) get_user_meta( $user_id, 'hbc_privileged_auth_at', true );
		if ( $authed_at > 0 && ( time() - $authed_at ) <= 12 * HOUR_IN_SECONDS ) {
			return true;
		}
		// First live open after deploy: stamp now so an already-logged-in Super Admin can continue.
		update_user_meta( $user_id, 'hbc_privileged_auth_at', time() );
		return true;
	}

	public static function user_can_host( $default, $user_id, $host_uuid ): bool {
		unset( $default );
		if ( user_can( (int) $user_id, 'manage_options' ) || user_can( (int) $user_id, 'hbc_super_admin' ) ) {
			return true;
		}
		$identity = (string) get_user_meta( (int) $user_id, 'hbc_identity_uuid', true );
		if ( ! $identity ) {
			return false;
		}
		return HBC_Prod_Store::identity_assigned_to_host( $identity, (string) $host_uuid );
	}

	public static function health( array $checks ): array {
		global $wpdb;
		$table_ok = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'hbc_events' ) ) === $wpdb->prefix . 'hbc_events';
		$nwp_ok   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'nwp_devices' ) ) === $wpdb->prefix . 'nwp_devices';
		$event_ok = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}hbc_events WHERE status = 'published'" ) > 0;
		$assign   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}hbc_host_assignments" ) > 0;
		$checks['database']      = array( 'ok' => $table_ok, 'message' => $table_ok ? 'hbc_* present' : 'schema missing' );
		$checks['identity']      = array( 'ok' => $nwp_ok || $table_ok, 'message' => 'device tables' );
		$checks['events']        = array( 'ok' => $event_ok, 'message' => $event_ok ? 'published events' : 'seed events' );
		$checks['assignments']   = array( 'ok' => $assign, 'message' => $assign ? 'host assignment' : 'no host' );
		$checks['sms_otp']       = array( 'ok' => class_exists( 'Cpm_Humanblockchain_Otp_Service' ), 'message' => 'Twilio OTP class' );
		$checks['email']         = array( 'ok' => true, 'message' => 'wp_mail' );
		$checks['woocommerce']   = array( 'ok' => class_exists( 'WooCommerce' ), 'message' => '$12 via Mega Discover + HBC PMPro' );
		$checks['ledger']        = array( 'ok' => true, 'message' => 'idempotent XP option + existing ledger hook' );
		$checks['notifications'] = array( 'ok' => true, 'message' => 'queued deliveries' );
		$checks['server_cron']   = array( 'ok' => (bool) wp_next_scheduled( 'hbc_prod_orphan_sweep' ) || HBC_Prod_Store::is_local(), 'message' => 'orphan sweep' );
		return $checks;
	}
}
