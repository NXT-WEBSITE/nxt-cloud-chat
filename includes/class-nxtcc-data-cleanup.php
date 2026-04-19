<?php
/**
 * Data cleanup and retention service.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tenant data cleanup helpers.
 */
final class NXTCC_Data_Cleanup {

	/**
	 * Option storing cleanup settings.
	 *
	 * @var string
	 */
	private const OPTION_SETTINGS = 'nxtcc_data_cleanup_settings';

	/**
	 * Option storing the most recent cleanup summary.
	 *
	 * @var string
	 */
	private const OPTION_LAST_RUN = 'nxtcc_data_cleanup_last_run';

	/**
	 * Cleanup lock transient name.
	 *
	 * @var string
	 */
	private const LOCK_TRANSIENT = 'nxtcc_data_cleanup_lock';

	/**
	 * Prefix for cleanup challenge transients.
	 *
	 * @var string
	 */
	private const CHALLENGE_TRANSIENT_PREFIX = 'nxtcc_data_cleanup_math_';

	/**
	 * Daily cron hook.
	 *
	 * @var string
	 */
	private const CRON_HOOK_DAILY = 'nxtcc_data_cleanup_daily';

	/**
	 * Follow-up cron hook.
	 *
	 * @var string
	 */
	private const CRON_HOOK_FOLLOWUP = 'nxtcc_data_cleanup_followup';

	/**
	 * Default cleanup time.
	 *
	 * @var string
	 */
	private const DEFAULT_RUN_TIME = '03:15';

	/**
	 * Automatic cleanup batch size.
	 *
	 * @var int
	 */
	private const AUTO_BATCH_LIMIT = 500;

	/**
	 * Automatic cleanup max loop count per target.
	 *
	 * @var int
	 */
	private const AUTO_MAX_BATCHES = 5;

	/**
	 * Manual cleanup batch size.
	 *
	 * @var int
	 */
	private const MANUAL_BATCH_LIMIT = 500;

	/**
	 * Manual cleanup max loop count per target.
	 *
	 * @var int
	 */
	private const MANUAL_MAX_BATCHES = 20;

	/**
	 * Cleanup challenge lifetime.
	 *
	 * @var int
	 */
	private const CHALLENGE_TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * Prevent double initialization.
	 *
	 * @var bool
	 */
	private static bool $initialized = false;

	/**
	 * Bootstrap hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}

		self::$initialized = true;

		add_filter( 'nxtcc_data_cleanup_targets', array( __CLASS__, 'register_core_targets' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'ensure_schedule' ), 30 );
		add_action( self::CRON_HOOK_DAILY, array( __CLASS__, 'scheduled_cleanup_runner' ) );
		add_action( self::CRON_HOOK_FOLLOWUP, array( __CLASS__, 'followup_cleanup_runner' ) );
		add_action( 'wp_ajax_nxtcc_cleanup_preview', array( __CLASS__, 'ajax_preview' ) );
		add_action( 'wp_ajax_nxtcc_cleanup_run', array( __CLASS__, 'ajax_run' ) );
		add_action( 'wp_ajax_nxtcc_cleanup_everything_challenge', array( __CLASS__, 'ajax_everything_challenge' ) );
		add_action( 'wp_ajax_nxtcc_cleanup_everything', array( __CLASS__, 'ajax_everything' ) );

		if ( defined( 'NXTCC_PLUGIN_FILE' ) ) {
			register_deactivation_hook( NXTCC_PLUGIN_FILE, array( __CLASS__, 'clear_scheduled_events' ) );
		}
	}

	/**
	 * Register the Free cleanup target(s).
	 *
	 * @param array<string,array<string,mixed>> $targets Existing targets.
	 * @return array<string,array<string,mixed>>
	 */
	public static function register_core_targets( array $targets ): array {
		$targets['message_activity'] = array(
			'label'            => did_action( 'init' ) ? __( 'Message activity', 'nxt-cloud-chat' ) : 'Message activity',
			'description'      => did_action( 'init' ) ? __( 'Sent, received, delivered, read, and failed message records.', 'nxt-cloud-chat' ) : 'Sent, received, delivered, read, and failed message records.',
			'default_enabled'  => 0,
			'default_days'     => 180,
			'min_days'         => 1,
			'max_days'         => 3650,
			'order'            => 10,
			'manual_only'      => false,
			'preview_callback' => array( __CLASS__, 'preview_message_activity' ),
			'cleanup_callback' => array( __CLASS__, 'cleanup_message_activity' ),
		);

		return $targets;
	}

	/**
	 * Return the registered cleanup targets.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_targets(): array {
		$targets = apply_filters( 'nxtcc_data_cleanup_targets', array() );
		$targets = is_array( $targets ) ? $targets : array();
		$clean   = array();

		foreach ( $targets as $target_id => $target ) {
			$target_id = sanitize_key( (string) $target_id );

			if ( '' === $target_id || ! is_array( $target ) ) {
				continue;
			}

			$preview_callback = $target['preview_callback'] ?? null;
			$cleanup_callback = $target['cleanup_callback'] ?? null;

			if ( ! is_callable( $preview_callback ) || ! is_callable( $cleanup_callback ) ) {
				continue;
			}

			$clean[ $target_id ] = array(
				'label'            => isset( $target['label'] ) ? sanitize_text_field( (string) $target['label'] ) : $target_id,
				'description'      => isset( $target['description'] ) ? sanitize_text_field( (string) $target['description'] ) : '',
				'default_enabled'  => ! empty( $target['default_enabled'] ) ? 1 : 0,
				'default_days'     => max( 1, (int) ( $target['default_days'] ?? 90 ) ),
				'min_days'         => max( 1, (int) ( $target['min_days'] ?? 7 ) ),
				'max_days'         => max( 1, (int) ( $target['max_days'] ?? 3650 ) ),
				'order'            => (int) ( $target['order'] ?? 100 ),
				'manual_only'      => ! empty( $target['manual_only'] ),
				'preview_callback' => $preview_callback,
				'cleanup_callback' => $cleanup_callback,
			);

			if ( $clean[ $target_id ]['min_days'] > $clean[ $target_id ]['max_days'] ) {
				$clean[ $target_id ]['min_days'] = $clean[ $target_id ]['max_days'];
			}

			$clean[ $target_id ]['default_days'] = max(
				$clean[ $target_id ]['min_days'],
				min( $clean[ $target_id ]['default_days'], $clean[ $target_id ]['max_days'] )
			);
		}

		uasort(
			$clean,
			static function ( array $left, array $right ): int {
				$left_order  = (int) ( $left['order'] ?? 100 );
				$right_order = (int) ( $right['order'] ?? 100 );

				if ( $left_order === $right_order ) {
					return strcmp(
						(string) ( $left['label'] ?? '' ),
						(string) ( $right['label'] ?? '' )
					);
				}

				return ( $left_order < $right_order ) ? -1 : 1;
			}
		);

		return $clean;
	}

	/**
	 * Return normalized cleanup settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_settings(): array {
		$targets  = self::get_targets();
		$raw      = get_option( self::OPTION_SETTINGS, array() );
		$raw      = is_array( $raw ) ? $raw : array();
		$settings = self::default_settings( $targets );
		$run_time = isset( $raw['run_time'] ) ? sanitize_text_field( (string) $raw['run_time'] ) : self::DEFAULT_RUN_TIME;
		$run_time = self::validate_time_value( $run_time ) ? $run_time : self::DEFAULT_RUN_TIME;

		$settings['auto_enabled']       = ! empty( $raw['auto_enabled'] ) ? 1 : 0;
		$settings['run_time']           = $run_time;
		$settings['preserve_favorites'] = ! empty( $raw['preserve_favorites'] ) ? 1 : 0;

		$raw_targets = isset( $raw['targets'] ) && is_array( $raw['targets'] ) ? $raw['targets'] : array();

		foreach ( $targets as $target_id => $target ) {
			$target_raw = isset( $raw_targets[ $target_id ] ) && is_array( $raw_targets[ $target_id ] ) ? $raw_targets[ $target_id ] : array();
			$days       = isset( $target_raw['days'] ) ? (int) $target_raw['days'] : (int) $target['default_days'];

			$settings['targets'][ $target_id ] = array(
				'enabled' => ! empty( $target_raw['enabled'] ) ? 1 : (int) $target['default_enabled'],
				'days'    => max(
					(int) $target['min_days'],
					min( $days, (int) $target['max_days'] )
				),
			);
		}

		return $settings;
	}

	/**
	 * Return target rows prepared for the Tools view.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_tools_view_targets(): array {
		$targets   = self::get_targets();
		$settings  = self::get_settings();
		$view_rows = array();

		foreach ( $targets as $target_id => $target ) {
			$target_settings = isset( $settings['targets'][ $target_id ] ) && is_array( $settings['targets'][ $target_id ] )
				? $settings['targets'][ $target_id ]
				: array();

			$view_rows[] = array(
				'id'              => $target_id,
				'label'           => (string) $target['label'],
				'description'     => (string) $target['description'],
				'enabled'         => ! empty( $target_settings['enabled'] ) ? 1 : 0,
				'days'            => isset( $target_settings['days'] ) ? (int) $target_settings['days'] : (int) $target['default_days'],
				'min_days'        => (int) $target['min_days'],
				'max_days'        => (int) $target['max_days'],
				'manual_only'     => ! empty( $target['manual_only'] ),
				'default_days'    => (int) $target['default_days'],
				'default_enabled' => (int) $target['default_enabled'],
			);
		}

		return $view_rows;
	}

	/**
	 * Return the latest cleanup summary in a view-friendly shape.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_last_run_view_data(): array {
		$raw = get_option( self::OPTION_LAST_RUN, array() );
		$raw = is_array( $raw ) ? $raw : array();

		if ( empty( $raw['started_at'] ) ) {
			return array(
				'has_run'               => false,
				'trigger_label'         => __( 'Not run yet', 'nxt-cloud-chat' ),
				'status_label'          => __( 'Idle', 'nxt-cloud-chat' ),
				'status_class'          => '',
				'started_at_display'    => __( 'Never', 'nxt-cloud-chat' ),
				'finished_at_display'   => '',
				'total_deleted_display' => number_format_i18n( 0 ),
				'items'                 => array(),
				'summary'               => __( 'No cleanup has run yet on this site.', 'nxt-cloud-chat' ),
			);
		}

		$targets     = self::get_targets();
		$raw_items   = isset( $raw['items'] ) && is_array( $raw['items'] ) ? $raw['items'] : array();
		$view_items  = array();
		$total_count = isset( $raw['total_deleted'] ) ? max( 0, (int) $raw['total_deleted'] ) : 0;

		foreach ( $raw_items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$target_id = sanitize_key( (string) ( $item['target'] ?? '' ) );
			$label     = isset( $targets[ $target_id ]['label'] ) ? (string) $targets[ $target_id ]['label'] : $target_id;
			$deleted   = max( 0, (int) ( $item['deleted'] ?? 0 ) );
			$remaining = max( 0, (int) ( $item['remaining'] ?? 0 ) );
			$message   = isset( $item['message'] ) ? sanitize_text_field( (string) $item['message'] ) : '';

			$view_items[] = array(
				'label'             => $label,
				'deleted'           => $deleted,
				'remaining'         => $remaining,
				'deleted_display'   => number_format_i18n( $deleted ),
				'remaining_display' => number_format_i18n( $remaining ),
				'message'           => $message,
			);
		}

		$status       = sanitize_key( (string) ( $raw['status'] ?? 'success' ) );
		$status_label = __( 'Completed', 'nxt-cloud-chat' );
		$status_class = 'is-success';

		if ( 'partial' === $status ) {
			$status_label = __( 'Partly completed', 'nxt-cloud-chat' );
			$status_class = 'is-warning';
		} elseif ( 'failed' === $status ) {
			$status_label = __( 'Needs attention', 'nxt-cloud-chat' );
			$status_class = 'is-fail';
		}

		$trigger       = sanitize_key( (string) ( $raw['trigger'] ?? 'manual' ) );
		$trigger_label = __( 'Manual cleanup', 'nxt-cloud-chat' );

		if ( 'automatic' === $trigger ) {
			$trigger_label = __( 'Automatic cleanup', 'nxt-cloud-chat' );
		} elseif ( 'clean_everything' === $trigger ) {
			$trigger_label = __( 'Clean everything', 'nxt-cloud-chat' );
		}
		$summary = isset( $raw['summary'] ) ? sanitize_text_field( (string) $raw['summary'] ) : '';

		if ( '' === $summary ) {
			if ( $total_count > 0 ) {
				$summary = sprintf(
					/* translators: %s: number of removed rows */
					__( 'Removed %s older items using the saved cleanup rules.', 'nxt-cloud-chat' ),
					number_format_i18n( $total_count )
				);
			} else {
				$summary = __( 'No older items matched the saved cleanup rules.', 'nxt-cloud-chat' );
			}
		}

		return array(
			'has_run'               => true,
			'trigger_label'         => $trigger_label,
			'status_label'          => $status_label,
			'status_class'          => $status_class,
			'started_at_display'    => self::format_local_datetime( (string) ( $raw['started_at'] ?? '' ) ),
			'finished_at_display'   => self::format_local_datetime( (string) ( $raw['finished_at'] ?? '' ) ),
			'total_deleted_display' => number_format_i18n( $total_count ),
			'items'                 => $view_items,
			'summary'               => $summary,
		);
	}

	/**
	 * Return the next automatic cleanup label.
	 *
	 * @return string
	 */
	public static function get_next_run_label(): string {
		$settings = self::get_settings();

		if ( empty( $settings['auto_enabled'] ) ) {
			return __( 'Automatic cleanup is off.', 'nxt-cloud-chat' );
		}

		$timestamp = wp_next_scheduled( self::CRON_HOOK_DAILY );

		if ( ! $timestamp ) {
			return __( 'Automatic cleanup will start after the schedule is saved.', 'nxt-cloud-chat' );
		}

		return sprintf(
			/* translators: %s: local scheduled date/time */
			__( 'Next automatic cleanup: %s', 'nxt-cloud-chat' ),
			wp_date( 'M j, Y g:i A', (int) $timestamp, wp_timezone() )
		);
	}

	/**
	 * Return localized data for the Tools tab script.
	 *
	 * @return array<string,mixed>
	 */
	public static function script_data(): array {
		return array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'nxtcc_admin_ajax' ),
			'canManage' => self::current_user_can_manage_tools(),
			'strings'   => array(
				'previewing'             => __( 'Checking older data...', 'nxt-cloud-chat' ),
				'cleaning'               => __( 'Cleaning older data...', 'nxt-cloud-chat' ),
				'challengeLoading'       => __( 'Preparing the confirmation check...', 'nxt-cloud-chat' ),
				'cleaningEverything'     => __( 'Cleaning everything that can be safely removed...', 'nxt-cloud-chat' ),
				'previewEmpty'           => __( 'Nothing is ready to clear right now based on the saved rules.', 'nxt-cloud-chat' ),
				'confirmRequired'        => __( 'Please confirm that you want to permanently remove older activity first.', 'nxt-cloud-chat' ),
				'previewHeading'         => __( 'What can be cleared now', 'nxt-cloud-chat' ),
				'cleanupHeading'         => __( 'Cleanup result', 'nxt-cloud-chat' ),
				'cleanEverythingHeading' => __( 'Clean everything result', 'nxt-cloud-chat' ),
				'itemsLabel'             => __( 'older items', 'nxt-cloud-chat' ),
				'remainingLabel'         => __( 'still waiting', 'nxt-cloud-chat' ),
				'requestFailed'          => __( 'The cleanup request could not be completed. Please try again.', 'nxt-cloud-chat' ),
				'challengeIntro'         => __( 'To continue, solve this quick check.', 'nxt-cloud-chat' ),
				'challengeEmpty'         => __( 'Enter the answer before continuing.', 'nxt-cloud-chat' ),
				'challengeLabel'         => __( 'Answer', 'nxt-cloud-chat' ),
				'challengePlaceholder'   => __( 'Type the answer', 'nxt-cloud-chat' ),
				'ownerLocked'            => __( 'Cleanup tools can only be managed by the tenant owner.', 'nxt-cloud-chat' ),
				'previewButton'          => __( 'Preview Cleanup', 'nxt-cloud-chat' ),
				'cleanupButton'          => __( 'Clean Up Now', 'nxt-cloud-chat' ),
				'everythingButton'       => __( 'Clean Everything', 'nxt-cloud-chat' ),
			),
		);
	}

	/**
	 * Whether the current user may manage cleanup tools.
	 *
	 * @return bool
	 */
	public static function current_user_can_manage_tools(): bool {
		return self::current_user_can_manage();
	}

	/**
	 * Reset retention settings to their default values.
	 *
	 * @return void
	 */
	public static function reset_retention_settings_to_defaults_from_post(): void {
		if ( ! self::current_user_can_manage() ) {
			add_settings_error(
				'nxtcc_settings',
				'nxtcc_cleanup_retention_reset_forbidden',
				__( 'You do not have permission to reset cleanup rules.', 'nxt-cloud-chat' ),
				'error'
			);
			return;
		}

		$targets   = self::get_targets();
		$settings  = self::get_settings();
		$defaults  = self::default_settings( $targets );
		$new_rules = isset( $defaults['targets'] ) && is_array( $defaults['targets'] ) ? $defaults['targets'] : array();

		$settings['targets']            = $new_rules;
		$settings['preserve_favorites'] = ! empty( $defaults['preserve_favorites'] ) ? 1 : 0;

		update_option( self::OPTION_SETTINGS, $settings, false );

		add_settings_error(
			'nxtcc_settings',
			'nxtcc_cleanup_retention_reset',
			__( 'Cleanup rules were reset to their default values.', 'nxt-cloud-chat' ),
			'updated'
		);
	}

	/**
	 * Save automatic cleanup settings from POST.
	 *
	 * @return void
	 */
	public static function save_schedule_settings_from_post(): void {
		if ( ! self::current_user_can_manage() ) {
			add_settings_error(
				'nxtcc_settings',
				'nxtcc_cleanup_schedule_forbidden',
				__( 'You do not have permission to update cleanup scheduling.', 'nxt-cloud-chat' ),
				'error'
			);
			return;
		}

		$settings                 = self::get_settings();
		$settings['auto_enabled'] = self::post_has( 'nxtcc_cleanup_auto_enabled' ) ? 1 : 0;
		$run_time                 = self::post_text( 'nxtcc_cleanup_run_time' );

		if ( ! self::validate_time_value( $run_time ) ) {
			add_settings_error(
				'nxtcc_settings',
				'nxtcc_cleanup_schedule_invalid_time',
				__( 'Please choose a valid cleanup time.', 'nxt-cloud-chat' ),
				'error'
			);
			return;
		}

		$settings['run_time'] = $run_time;

		update_option( self::OPTION_SETTINGS, $settings, false );
		self::clear_scheduled_events();
		self::ensure_schedule();

		add_settings_error(
			'nxtcc_settings',
			'nxtcc_cleanup_schedule_saved',
			__( 'Cleanup schedule saved.', 'nxt-cloud-chat' ),
			'updated'
		);
	}

	/**
	 * Save retention settings from POST.
	 *
	 * @return void
	 */
	public static function save_retention_settings_from_post(): void {
		if ( ! self::current_user_can_manage() ) {
			add_settings_error(
				'nxtcc_settings',
				'nxtcc_cleanup_retention_forbidden',
				__( 'You do not have permission to update cleanup rules.', 'nxt-cloud-chat' ),
				'error'
			);
			return;
		}

		$targets         = self::get_targets();
		$settings        = self::get_settings();
		$enabled_targets = self::post_array_map( 'nxtcc_cleanup_enabled' );
		$days_map        = self::post_array_map( 'nxtcc_cleanup_days' );
		$errors          = array();
		$new_targets     = array();

		foreach ( $targets as $target_id => $target ) {
			$days_raw = isset( $days_map[ $target_id ] ) ? trim( (string) $days_map[ $target_id ] ) : '';

			if ( '' === $days_raw || ! ctype_digit( $days_raw ) ) {
				$errors[] = sprintf(
					/* translators: %s: data type label */
					__( 'Please enter a whole number of days for %s.', 'nxt-cloud-chat' ),
					(string) $target['label']
				);
				continue;
			}

			$days = (int) $days_raw;

			if ( $days < (int) $target['min_days'] || $days > (int) $target['max_days'] ) {
				$errors[] = sprintf(
					/* translators: 1: data type label, 2: minimum days, 3: maximum days */
					__( '%1$s must be between %2$d and %3$d days.', 'nxt-cloud-chat' ),
					(string) $target['label'],
					(int) $target['min_days'],
					(int) $target['max_days']
				);
				continue;
			}

			$new_targets[ $target_id ] = array(
				'enabled' => isset( $enabled_targets[ $target_id ] ) ? 1 : 0,
				'days'    => $days,
			);
		}

		if ( empty( $errors ) ) {
			$dependency_error = self::validate_target_dependencies( $new_targets, $targets );

			if ( '' !== $dependency_error ) {
				$errors[] = $dependency_error;
			}
		}

		if ( ! empty( $errors ) ) {
			foreach ( $errors as $message ) {
				add_settings_error(
					'nxtcc_settings',
					'nxtcc_cleanup_retention_error_' . md5( (string) $message ),
					$message,
					'error'
				);
			}
			return;
		}

		$settings['targets']            = $new_targets;
		$settings['preserve_favorites'] = self::post_has( 'nxtcc_cleanup_preserve_favorites' ) ? 1 : 0;

		update_option( self::OPTION_SETTINGS, $settings, false );

		add_settings_error(
			'nxtcc_settings',
			'nxtcc_cleanup_retention_saved',
			__( 'Cleanup rules saved.', 'nxt-cloud-chat' ),
			'updated'
		);
	}

	/**
	 * AJAX preview for the current cleanup settings.
	 *
	 * @return void
	 */
	public static function ajax_preview(): void {
		if ( ! self::current_user_can_manage() ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to preview cleanup.', 'nxt-cloud-chat' ),
				),
				403
			);
		}

		check_ajax_referer( 'nxtcc_admin_ajax', 'nonce' );

		$preview = self::build_preview( true );

		if ( isset( $preview['error'] ) && '' !== (string) $preview['error'] ) {
			wp_send_json_error(
				array(
					'message' => (string) $preview['error'],
				),
				400
			);
		}

		wp_send_json_success( $preview );
	}

	/**
	 * AJAX manual cleanup run.
	 *
	 * @return void
	 */
	public static function ajax_run(): void {
		if ( ! self::current_user_can_manage() ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to run cleanup.', 'nxt-cloud-chat' ),
				),
				403
			);
		}

		check_ajax_referer( 'nxtcc_admin_ajax', 'nonce' );

		$result = self::run_cleanup( true );

		if ( isset( $result['error'] ) && '' !== (string) $result['error'] ) {
			wp_send_json_error(
				array(
					'message' => (string) $result['error'],
				),
				409
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: create a math challenge for the clean-everything action.
	 *
	 * @return void
	 */
	public static function ajax_everything_challenge(): void {
		if ( ! self::current_user_can_manage() ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to use cleanup tools.', 'nxt-cloud-chat' ),
				),
				403
			);
		}

		check_ajax_referer( 'nxtcc_admin_ajax', 'nonce' );

		$challenge = self::create_math_challenge();

		wp_send_json_success(
			array(
				'token'    => $challenge['token'],
				'question' => $challenge['question'],
			)
		);
	}

	/**
	 * AJAX: run clean everything after challenge validation.
	 *
	 * @return void
	 */
	public static function ajax_everything(): void {
		if ( ! self::current_user_can_manage() ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to use cleanup tools.', 'nxt-cloud-chat' ),
				),
				403
			);
		}

		check_ajax_referer( 'nxtcc_admin_ajax', 'nonce' );

		$token  = self::post_text( 'challenge_token' );
		$answer = self::post_text( 'challenge_answer' );

		if ( '' === $token || '' === $answer ) {
			wp_send_json_error(
				array(
					'message'           => __( 'Please solve the confirmation check before continuing.', 'nxt-cloud-chat' ),
					'refresh_challenge' => true,
				),
				400
			);
		}

		if ( ! self::validate_math_challenge( $token, $answer ) ) {
			wp_send_json_error(
				array(
					'message'           => __( 'The confirmation answer was incorrect. Please try again.', 'nxt-cloud-chat' ),
					'refresh_challenge' => true,
				),
				400
			);
		}

		$result = self::run_cleanup( true, true );

		if ( isset( $result['error'] ) && '' !== (string) $result['error'] ) {
			wp_send_json_error(
				array(
					'message' => (string) $result['error'],
				),
				409
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * Run the scheduled daily cleanup.
	 *
	 * @return void
	 */
	public static function scheduled_cleanup_runner(): void {
		$settings = self::get_settings();

		if ( empty( $settings['auto_enabled'] ) ) {
			return;
		}

		self::run_cleanup( false );
	}

	/**
	 * Run a follow-up cleanup batch when automatic cleanup still has more work.
	 *
	 * @return void
	 */
	public static function followup_cleanup_runner(): void {
		$settings = self::get_settings();

		if ( empty( $settings['auto_enabled'] ) ) {
			return;
		}

		self::run_cleanup( false );
	}

	/**
	 * Ensure scheduled cleanup events match the current settings.
	 *
	 * @return void
	 */
	public static function ensure_schedule(): void {
		$settings = self::get_settings();

		if ( empty( $settings['auto_enabled'] ) ) {
			self::clear_scheduled_events();
			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK_DAILY ) ) {
			self::schedule_daily_event( (string) $settings['run_time'] );
		}
	}

	/**
	 * Clear scheduled cleanup cron events.
	 *
	 * @return void
	 */
	public static function clear_scheduled_events(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK_DAILY );
		wp_clear_scheduled_hook( self::CRON_HOOK_FOLLOWUP );
	}

	/**
	 * Return the table name for a plugin table suffix.
	 *
	 * @param string $suffix Table suffix without the WordPress prefix.
	 * @return string
	 */
	public static function table_name( string $suffix ): string {
		global $wpdb;

		$suffix = preg_replace( '/[^A-Za-z0-9_]/', '', $suffix );
		$suffix = is_string( $suffix ) ? $suffix : '';

		return $wpdb->prefix . $suffix;
	}

	/**
	 * Quote one table identifier for SQL usage.
	 *
	 * @param string $table Table name.
	 * @return string
	 */
	public static function quote_table_name( string $table ): string {
		$clean = preg_replace( '/[^A-Za-z0-9_]/', '', $table );
		$clean = is_string( $clean ) ? $clean : '';

		if ( '' === $clean ) {
			$clean = 'nxtcc_invalid_table';
		}

		return '`' . $clean . '`';
	}

	/**
	 * Normalize a simple WHERE fragment used by cleanup queries.
	 *
	 * Allows only basic identifier, placeholder, comparison, and whitespace
	 * characters because these clauses are assembled from fixed cleanup rules.
	 *
	 * @param string $where_sql SQL after WHERE.
	 * @return string
	 */
	private static function sanitize_where_sql( string $where_sql ): string {
		$where_sql = preg_replace( '/[^A-Za-z0-9_%\s.=<>()`,]/', '', $where_sql );
		$where_sql = is_string( $where_sql ) ? $where_sql : '';
		$where_sql = trim( preg_replace( '/\s+/', ' ', $where_sql ) ?? '' );

		return $where_sql;
	}

	/**
	 * Check whether a table exists.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	public static function table_exists( string $table ): bool {
		global $wpdb;

		$cache_key = 'nxtcc_cleanup_table_exists_' . md5( $table );
		$cached    = wp_cache_get( $cache_key, 'nxtcc_cleanup' );

		if ( false !== $cached ) {
			return (bool) $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Table existence checks require a direct query.
		$exists = ( $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		) === $table );

		wp_cache_set( $cache_key, $exists ? 1 : 0, 'nxtcc_cleanup', 300 );

		return $exists;
	}

	/**
	 * Convert a retention period into a UTC MySQL cutoff string.
	 *
	 * @param int $days Retention days.
	 * @return string
	 */
	public static function mysql_cutoff_from_days( int $days ): string {
		$days      = max( 1, $days );
		$cutoff_ts = time() - ( $days * DAY_IN_SECONDS );

		return gmdate( 'Y-m-d H:i:s', $cutoff_ts );
	}

	/**
	 * Resolve the cutoff for one target and context.
	 *
	 * @param array<string,mixed> $settings     Cleanup settings.
	 * @param string              $target_id    Target id.
	 * @param int                 $default_days Default days.
	 * @param array<string,mixed> $context      Run context.
	 * @return string
	 */
	public static function mysql_cutoff_for_target( array $settings, string $target_id, int $default_days, array $context = array() ): string {
		if ( ! empty( $context['clean_everything'] ) ) {
			return gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		}

		$days = self::target_days( $settings, $target_id, $default_days );

		return self::mysql_cutoff_from_days( $days );
	}

	/**
	 * Resolve the tenant tuple used for cleanup operations.
	 *
	 * Falls back to the primary tenant and then the latest saved settings row so
	 * cleanup never guesses across tenants.
	 *
	 * @param array<string,mixed> $context Run context.
	 * @return array<string,string>
	 */
	public static function get_cleanup_tenant_context( array $context = array() ): array {
		if ( isset( $context['tenant'] ) && is_array( $context['tenant'] ) ) {
			$tenant = self::sanitize_cleanup_tenant_context( $context['tenant'] );

			if ( self::has_cleanup_tenant_context( $tenant ) ) {
				return $tenant;
			}
		}

		if ( class_exists( 'NXTCC_Access_Control' ) ) {
			$tenant = self::sanitize_cleanup_tenant_context( NXTCC_Access_Control::get_current_tenant_context() );

			if ( self::has_cleanup_tenant_context( $tenant ) ) {
				return $tenant;
			}

			$tenant = self::sanitize_cleanup_tenant_context( NXTCC_Access_Control::get_primary_tenant_context() );

			if ( self::has_cleanup_tenant_context( $tenant ) ) {
				return $tenant;
			}
		}

		if ( class_exists( 'NXTCC_Settings_DAO' ) ) {
			$row = NXTCC_Settings_DAO::get_latest_any();

			if ( is_object( $row ) ) {
				$tenant = self::sanitize_cleanup_tenant_context(
					array(
						'user_mailid'         => isset( $row->user_mailid ) ? (string) $row->user_mailid : '',
						'business_account_id' => isset( $row->business_account_id ) ? (string) $row->business_account_id : '',
						'phone_number_id'     => isset( $row->phone_number_id ) ? (string) $row->phone_number_id : '',
					)
				);

				if ( self::has_cleanup_tenant_context( $tenant ) ) {
					return $tenant;
				}
			}
		}

		return self::sanitize_cleanup_tenant_context( array() );
	}

	/**
	 * Whether a resolved cleanup tenant tuple is complete.
	 *
	 * @param array<string,string> $tenant Tenant tuple.
	 * @return bool
	 */
	public static function has_cleanup_tenant_context( array $tenant ): bool {
		$tenant = self::sanitize_cleanup_tenant_context( $tenant );

		return '' !== $tenant['user_mailid'] && '' !== $tenant['business_account_id'] && '' !== $tenant['phone_number_id'];
	}

	/**
	 * Build a tenant WHERE fragment for cleanup queries.
	 *
	 * @param array<string,mixed> $context Run context.
	 * @param string              $alias   Optional SQL alias.
	 * @return array{0:string,1:array<int,string>}
	 */
	public static function cleanup_tenant_sql( array $context = array(), string $alias = '' ): array {
		$tenant = self::get_cleanup_tenant_context( $context );

		if ( ! self::has_cleanup_tenant_context( $tenant ) ) {
			return array( '1=0', array() );
		}

		$alias  = sanitize_key( $alias );
		$prefix = '' !== $alias ? $alias . '.' : '';

		return array(
			$prefix . 'user_mailid = %s AND ' . $prefix . 'business_account_id = %s AND ' . $prefix . 'phone_number_id = %s',
			array(
				$tenant['user_mailid'],
				$tenant['business_account_id'],
				$tenant['phone_number_id'],
			),
		);
	}

	/**
	 * Normalize one tenant tuple for cleanup usage.
	 *
	 * @param array<string,mixed> $tenant Tenant tuple.
	 * @return array<string,string>
	 */
	private static function sanitize_cleanup_tenant_context( array $tenant ): array {
		return array(
			'user_mailid'         => isset( $tenant['user_mailid'] ) ? sanitize_email( (string) $tenant['user_mailid'] ) : '',
			'business_account_id' => isset( $tenant['business_account_id'] ) ? sanitize_text_field( (string) $tenant['business_account_id'] ) : '',
			'phone_number_id'     => isset( $tenant['phone_number_id'] ) ? sanitize_text_field( (string) $tenant['phone_number_id'] ) : '',
		);
	}

	/**
	 * Prepare SQL containing named table tokens.
	 *
	 * Example token usage: `{history}`.
	 *
	 * @param string                $query     SQL query with table tokens.
	 * @param array<string,string>  $table_map Token => raw table name.
	 * @param array<int,int|string> $args      Query placeholder args.
	 * @return string
	 */
	public static function prepare_with_tables( string $query, array $table_map, array $args = array() ): string {
		global $wpdb;

		$search  = array();
		$replace = array();

		foreach ( $table_map as $token => $table_name ) {
			$marker    = '{' . sanitize_key( (string) $token ) . '}';
			$table_sql = self::quote_table_name( (string) $table_name );
			$sentinel  = '__NXTCC_TABLE_' . strtoupper( sanitize_key( (string) $token ) ) . '__';

			$query     = str_replace( $marker, "'" . $sentinel . "'", $query );
			$search[]  = "'" . $sentinel . "'";
			$replace[] = $table_sql;
			$search[]  = $sentinel;
			$replace[] = $table_sql;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The incoming SQL only contains sanitized table sentinels plus standard wpdb placeholders.
		$prepared = empty( $args ) ? $query : $wpdb->prepare( $query, ...$args );
		$prepared = is_string( $prepared ) ? $prepared : '';

		if ( '' === $prepared ) {
			return '';
		}

		return str_replace( $search, $replace, $prepared );
	}

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders -- Cleanup helper queries sanitize identifiers locally and prepare values inline just before execution.
	/**
	 * Count rows from one table using a prepared WHERE clause.
	 *
	 * @param string                $table     Raw table name.
	 * @param string                $where_sql SQL after WHERE.
	 * @param array<int,int|string> $args      Query args.
	 * @return int
	 */
	public static function count_rows( string $table, string $where_sql, array $args = array() ): int {
		global $wpdb;

		if ( ! self::table_exists( $table ) ) {
			return 0;
		}

		$table_sql = self::quote_table_name( $table );
		$where_sql = self::sanitize_where_sql( $where_sql );

		if ( '' === $where_sql ) {
			return 0;
		}

		$needs_sentinel = empty( $args );
		if ( $needs_sentinel ) {
			$args = array( 1, 1 );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Table names are quoted locally, WHERE fragments are normalized by sanitize_where_sql(), and the query is prepared inline.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $table_sql . ' WHERE ' . $where_sql . ( $needs_sentinel ? ' AND %d = %d' : '' ),
				...$args
			)
		);
	}

	/**
	 * Select row ids from one table.
	 *
	 * @param string                $table     Raw table name.
	 * @param string                $where_sql SQL after WHERE.
	 * @param array<int,int|string> $args      Query args.
	 * @param int                   $limit     Result limit.
	 * @param string                $order_sql ORDER BY fragment.
	 * @return array<int,int>
	 */
	public static function select_ids( string $table, string $where_sql, array $args, int $limit, string $order_sql = 'id ASC' ): array {
		global $wpdb;

		if ( ! self::table_exists( $table ) ) {
			return array();
		}

		$table_sql  = esc_sql( self::quote_table_name( $table ) );
		$where_sql  = self::sanitize_where_sql( $where_sql );
		$order_desc = 'id desc' === strtolower( trim( (string) $order_sql ) );
		$limit      = max( 1, $limit );

		if ( '' === $where_sql ) {
			return array();
		}

		$needs_sentinel = empty( $args );
		if ( $needs_sentinel ) {
			$args = array( 1, 1 );
		}
		$args[] = $limit;

		if ( $order_desc ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Table names are quoted locally, ORDER BY is a fixed literal, WHERE fragments are normalized by sanitize_where_sql(), and the query is prepared inline.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT id FROM ' . $table_sql . ' WHERE ' . $where_sql . ( $needs_sentinel ? ' AND %d = %d' : '' ) . ' ORDER BY id DESC LIMIT %d',
					...$args
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- Table names are quoted locally, ORDER BY is a fixed literal, WHERE fragments are normalized by sanitize_where_sql(), and the query is prepared inline.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT id FROM ' . $table_sql . ' WHERE ' . $where_sql . ( $needs_sentinel ? ' AND %d = %d' : '' ) . ' ORDER BY id ASC LIMIT %d',
					...$args
				)
			);
		}
		$ids = is_array( $ids ) ? $ids : array();

		return array_map( 'intval', $ids );
	}

	/**
	 * Delete rows by primary key ids.
	 *
	 * @param string         $table Raw table name.
	 * @param array<int,int> $ids   Row ids.
	 * @return int
	 */
	public static function delete_rows_by_id( string $table, array $ids ): int {
		global $wpdb;

		if ( ! self::table_exists( $table ) ) {
			return 0;
		}

		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $ids ),
					static function ( int $id ): bool {
						return $id > 0;
					}
				)
			)
		);

		if ( empty( $ids ) ) {
			return 0;
		}

		$table_sql    = esc_sql( self::quote_table_name( $table ) );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Table names are quoted locally, dynamic placeholder lists are generated from sanitized integer IDs, and the query is prepared inline.
		$result = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . $table_sql . ' WHERE id IN (' . $placeholders . ')',
				...$ids
			)
		);

		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Delete rows by a named field.
	 *
	 * @param string                           $table  Raw table name.
	 * @param string                           $field  Field name.
	 * @param array<int,int>|array<int,string> $values Field values.
	 * @return int
	 */
	public static function delete_rows_by_field( string $table, string $field, array $values ): int {
		global $wpdb;

		if ( ! self::table_exists( $table ) ) {
			return 0;
		}

		$field = preg_replace( '/[^A-Za-z0-9_]/', '', $field );
		$field = is_string( $field ) ? $field : '';

		if ( '' === $field || empty( $values ) ) {
			return 0;
		}

		$table_name = preg_replace( '/[^A-Za-z0-9_]/', '', $table );
		$table_name = is_string( $table_name ) ? $table_name : '';

		if ( '' === $table_name ) {
			return 0;
		}

		$deleted = 0;

		foreach ( $values as $value ) {
			if ( is_numeric( $value ) && (string) (int) $value === (string) $value ) {
				$value  = (int) $value;
				$format = '%d';
			} else {
				$value  = sanitize_text_field( (string) $value );
				$format = '%s';
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- wpdb::delete() prepares the statement internally for one sanitized field/value pair.
			$result = $wpdb->delete(
				$table_name,
				array(
					$field => $value,
				),
				array( $format )
			);

			if ( false !== $result ) {
				$deleted += (int) $result;
			}
		}

		return $deleted;
	}
	// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders

	/**
	 * Preview old message activity.
	 *
	 * @param array<string,mixed> $settings Cleanup settings.
	 * @param array<string,mixed> $target   Target config.
	 * @param array<string,mixed> $context  Run context.
	 * @return array<string,mixed>
	 */
	public static function preview_message_activity( array $settings, array $target, array $context ): array {
		$table                              = self::table_name( 'nxtcc_message_history' );
		$days                               = self::target_days( $settings, 'message_activity', (int) $target['default_days'] );
		$cutoff                             = self::mysql_cutoff_from_days( $days );
		list( $tenant_where, $tenant_args ) = self::cleanup_tenant_sql( $context );
		$args                               = array_merge( $tenant_args, array( $cutoff ) );
		$where                              = $tenant_where . ' AND created_at < %s';

		if ( ! empty( $settings['preserve_favorites'] ) ) {
			$where .= ' AND is_favorite = %d';
			$args[] = 0;
		}

		$count   = self::count_rows( $table, $where, $args );
		$message = ! empty( $settings['preserve_favorites'] )
			? __( 'Starred messages stay in place.', 'nxt-cloud-chat' )
			: '';

		return array(
			'count'   => $count,
			'message' => $message,
		);
	}

	/**
	 * Clean up old message activity.
	 *
	 * @param array<string,mixed> $settings Cleanup settings.
	 * @param array<string,mixed> $target   Target config.
	 * @param array<string,mixed> $context  Run context.
	 * @return array<string,mixed>
	 */
	public static function cleanup_message_activity( array $settings, array $target, array $context ): array {
		$table                              = self::table_name( 'nxtcc_message_history' );
		$cutoff                             = self::mysql_cutoff_for_target( $settings, 'message_activity', (int) $target['default_days'], $context );
		$batch_limit                        = isset( $context['batch_limit'] ) ? max( 1, (int) $context['batch_limit'] ) : self::AUTO_BATCH_LIMIT;
		$max_batches                        = isset( $context['max_batches'] ) ? max( 1, (int) $context['max_batches'] ) : self::AUTO_MAX_BATCHES;
		list( $tenant_where, $tenant_args ) = self::cleanup_tenant_sql( $context );
		$args                               = array_merge( $tenant_args, array( $cutoff ) );
		$where                              = $tenant_where . ' AND created_at < %s';
		$deleted                            = 0;

		if ( ! empty( $settings['preserve_favorites'] ) ) {
			$where .= ' AND is_favorite = %d';
			$args[] = 0;
		}

		for ( $batch = 0; $batch < $max_batches; $batch++ ) {
			$ids = self::select_ids( $table, $where, $args, $batch_limit, 'id ASC' );

			if ( empty( $ids ) ) {
				break;
			}

			$deleted += self::delete_rows_by_id( $table, $ids );

			if ( count( $ids ) < $batch_limit ) {
				break;
			}
		}

		$remaining = self::count_rows( $table, $where, $args );
		$message   = ! empty( $settings['preserve_favorites'] )
			? __( 'Starred messages were kept.', 'nxt-cloud-chat' )
			: '';

		return array(
			'deleted'   => $deleted,
			'remaining' => $remaining,
			'message'   => $message,
		);
	}

	/**
	 * Return default settings for the registered targets.
	 *
	 * @param array<string,array<string,mixed>> $targets Cleanup targets.
	 * @return array<string,mixed>
	 */
	private static function default_settings( array $targets ): array {
		$defaults = array(
			'auto_enabled'       => 0,
			'run_time'           => self::DEFAULT_RUN_TIME,
			'preserve_favorites' => 1,
			'targets'            => array(),
		);

		foreach ( $targets as $target_id => $target ) {
			$defaults['targets'][ $target_id ] = array(
				'enabled' => ! empty( $target['default_enabled'] ) ? 1 : 0,
				'days'    => (int) $target['default_days'],
			);
		}

		return $defaults;
	}

	/**
	 * Whether the current user can manage cleanup settings.
	 *
	 * @return bool
	 */
	private static function current_user_can_manage(): bool {
		if ( class_exists( 'NXTCC_Access_Control' ) ) {
			return NXTCC_Access_Control::current_user_is_tenant_owner();
		}

		return current_user_can( 'manage_options' );
	}

	/**
	 * Build the transient key for a cleanup challenge.
	 *
	 * @param int    $user_id Current user ID.
	 * @param string $token   Challenge token.
	 * @return string
	 */
	private static function challenge_transient_key( int $user_id, string $token ): string {
		return self::CHALLENGE_TRANSIENT_PREFIX . md5( $user_id . '|' . $token );
	}

	/**
	 * Create one short math challenge for destructive cleanup.
	 *
	 * @return array<string,string>
	 */
	private static function create_math_challenge(): array {
		$user_id = get_current_user_id();
		$left    = wp_rand( 10, 99 );
		$right   = wp_rand( 10, 99 );
		$token   = wp_generate_password( 20, false, false );
		$key     = self::challenge_transient_key( $user_id, $token );

		set_transient( $key, (string) ( $left + $right ), self::CHALLENGE_TTL );

		return array(
			'token'    => $token,
			'question' => $left . ' + ' . $right,
		);
	}

	/**
	 * Validate and consume one math challenge token.
	 *
	 * @param string $token  Challenge token.
	 * @param string $answer User answer.
	 * @return bool
	 */
	private static function validate_math_challenge( string $token, string $answer ): bool {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 || '' === $token || '' === $answer || ! ctype_digit( $answer ) ) {
			return false;
		}

		$key      = self::challenge_transient_key( $user_id, $token );
		$expected = get_transient( $key );

		delete_transient( $key );

		if ( false === $expected ) {
			return false;
		}

		return (string) (int) $answer === (string) $expected;
	}

	/**
	 * Check whether a POST key exists.
	 *
	 * @param string $key POST key.
	 * @return bool
	 */
	private static function post_has( string $key ): bool {
		$value = filter_input( INPUT_POST, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS );

		return null !== $value;
	}

	/**
	 * Read a sanitized POST text value.
	 *
	 * @param string $key POST key.
	 * @return string
	 */
	private static function post_text( string $key ): string {
		$value = filter_input( INPUT_POST, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
		$value = is_string( $value ) ? $value : '';

		return sanitize_text_field( wp_unslash( $value ) );
	}

	/**
	 * Read one sanitized associative POST array.
	 *
	 * @param string $key POST key.
	 * @return array<string,string>
	 */
	private static function post_array_map( string $key ): array {
		$value = filter_input(
			INPUT_POST,
			$key,
			FILTER_SANITIZE_FULL_SPECIAL_CHARS,
			array(
				'flags' => FILTER_REQUIRE_ARRAY,
			)
		);

		if ( ! is_array( $value ) ) {
			return array();
		}

		$clean = array();

		foreach ( $value as $item_key => $item_value ) {
			$clean[ sanitize_key( (string) $item_key ) ] = sanitize_text_field( wp_unslash( (string) $item_value ) );
		}

		return $clean;
	}

	/**
	 * Validate a HH:MM time string.
	 *
	 * @param string $value Time value.
	 * @return bool
	 */
	private static function validate_time_value( string $value ): bool {
		return 1 === preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value );
	}

	/**
	 * Validate inter-target retention dependencies.
	 *
	 * @param array<string,array<string,int>>   $target_settings Saved target settings.
	 * @param array<string,array<string,mixed>> $targets         Target metadata.
	 * @return string
	 */
	private static function validate_target_dependencies( array $target_settings, array $targets ): string {
		if (
			isset( $targets['campaign_activity'], $target_settings['campaign_activity'], $target_settings['message_activity'] ) &&
			! empty( $target_settings['campaign_activity']['enabled'] )
		) {
			if ( empty( $target_settings['message_activity']['enabled'] ) ) {
				return __( 'Campaign activity can only be cleaned when Message activity cleanup is also turned on.', 'nxt-cloud-chat' );
			}

			if ( (int) $target_settings['campaign_activity']['days'] < (int) $target_settings['message_activity']['days'] ) {
				return __( 'Campaign activity must be kept for at least as long as Message activity so older message records keep their campaign context.', 'nxt-cloud-chat' );
			}
		}

		return '';
	}

	/**
	 * Build a cleanup preview payload.
	 *
	 * @param bool $manual Whether this is a manual preview.
	 * @return array<string,mixed>
	 */
	private static function build_preview( bool $manual ): array {
		$settings = self::get_settings();
		$targets  = self::get_targets();
		$tenant   = self::get_cleanup_tenant_context();
		$items    = array();
		$total    = 0;

		if ( ! self::has_cleanup_tenant_context( $tenant ) ) {
			return array(
				'error' => __( 'Cleanup could not resolve the active tenant for this site.', 'nxt-cloud-chat' ),
			);
		}

		foreach ( $targets as $target_id => $target ) {
			$target_settings = isset( $settings['targets'][ $target_id ] ) ? $settings['targets'][ $target_id ] : array();

			if ( empty( $target_settings['enabled'] ) ) {
				continue;
			}

			if ( ! $manual && ! empty( $target['manual_only'] ) ) {
				continue;
			}

			$callback = $target['preview_callback'];
			$preview  = is_callable( $callback )
				? call_user_func(
					$callback,
					$settings,
					$target,
					array(
						'manual' => $manual,
						'tenant' => $tenant,
					)
				)
				: array();

			$count = is_array( $preview ) ? max( 0, (int) ( $preview['count'] ?? 0 ) ) : 0;

			$items[] = array(
				'id'          => $target_id,
				'label'       => (string) $target['label'],
				'description' => (string) $target['description'],
				'count'       => $count,
				'days'        => (int) $target_settings['days'],
				'manual_only' => ! empty( $target['manual_only'] ),
				'message'     => is_array( $preview ) && ! empty( $preview['message'] ) ? sanitize_text_field( (string) $preview['message'] ) : '',
			);

			$total += $count;
		}

		if ( empty( $items ) ) {
			return array(
				'error' => __( 'Turn on at least one cleanup rule first, then save the Tools tab before running cleanup.', 'nxt-cloud-chat' ),
			);
		}

		return array(
			'items'         => $items,
			'total_count'   => $total,
			'total_display' => number_format_i18n( $total ),
			'has_items'     => $total > 0,
		);
	}

	/**
	 * Run cleanup for enabled targets.
	 *
	 * @param bool $manual           Whether this is a manual cleanup.
	 * @param bool $clean_everything Whether to ignore saved rule toggles and clean all eligible history.
	 * @return array<string,mixed>
	 */
	private static function run_cleanup( bool $manual, bool $clean_everything = false ): array {
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return array(
				'error' => __( 'Cleanup is already running. Please wait a moment and try again.', 'nxt-cloud-chat' ),
			);
		}

		set_transient( self::LOCK_TRANSIENT, 1, 15 * MINUTE_IN_SECONDS );

		try {
			$settings       = self::get_settings();
			$targets        = self::get_targets();
			$tenant         = self::get_cleanup_tenant_context();
			$results        = array();
			$total_deleted  = 0;
			$has_remaining  = false;
			$started_at     = gmdate( 'Y-m-d H:i:s' );
			$max_batches    = $manual ? self::MANUAL_MAX_BATCHES : self::AUTO_MAX_BATCHES;
			$batch_limit    = $manual ? self::MANUAL_BATCH_LIMIT : self::AUTO_BATCH_LIMIT;
			$ran_any_target = false;

			if ( ! self::has_cleanup_tenant_context( $tenant ) ) {
				return array(
					'error' => __( 'Cleanup could not resolve the active tenant for this site.', 'nxt-cloud-chat' ),
				);
			}

			foreach ( $targets as $target_id => $target ) {
				$target_settings = isset( $settings['targets'][ $target_id ] ) && is_array( $settings['targets'][ $target_id ] )
					? $settings['targets'][ $target_id ]
					: array();

				if ( ! $clean_everything && empty( $target_settings['enabled'] ) ) {
					continue;
				}

				if ( ! $clean_everything && ! $manual && ! empty( $target['manual_only'] ) ) {
					continue;
				}

				$ran_any_target = true;
				$callback       = $target['cleanup_callback'];
				$result         = is_callable( $callback )
					? call_user_func(
						$callback,
						$settings,
						$target,
						array(
							'manual'           => $manual,
							'clean_everything' => $clean_everything,
							'tenant'           => $tenant,
							'batch_limit'      => $batch_limit,
							'max_batches'      => $max_batches,
						)
					)
					: array();

				$deleted   = is_array( $result ) ? max( 0, (int) ( $result['deleted'] ?? 0 ) ) : 0;
				$remaining = is_array( $result ) ? max( 0, (int) ( $result['remaining'] ?? 0 ) ) : 0;
				$message   = is_array( $result ) && ! empty( $result['message'] ) ? sanitize_text_field( (string) $result['message'] ) : '';

				$results[] = array(
					'target'    => $target_id,
					'label'     => (string) $target['label'],
					'deleted'   => $deleted,
					'remaining' => $remaining,
					'message'   => $message,
				);

				$total_deleted += $deleted;

				if ( $remaining > 0 ) {
					$has_remaining = true;
				}
			}

			if ( ! $ran_any_target ) {
				return array(
					'error' => $clean_everything
						? __( 'No cleanup targets are available on this site right now.', 'nxt-cloud-chat' )
						: __( 'Turn on at least one cleanup rule first, then save the Tools tab before running cleanup.', 'nxt-cloud-chat' ),
				);
			}

			if ( ! $manual && $has_remaining ) {
				self::schedule_followup_event();
			}

			$finished_at = gmdate( 'Y-m-d H:i:s' );
			$status      = $has_remaining ? 'partial' : 'success';
			$summary     = $total_deleted > 0
				? sprintf(
					/* translators: %s: number of deleted rows */
					__( 'Removed %s older items.', 'nxt-cloud-chat' ),
					number_format_i18n( $total_deleted )
				)
				: __( 'No older items matched the saved cleanup rules.', 'nxt-cloud-chat' );

			if ( $clean_everything ) {
				$summary = $total_deleted > 0
					? sprintf(
						/* translators: %s: number of deleted rows */
						__( 'Removed %s items from cleanup-managed history.', 'nxt-cloud-chat' ),
						number_format_i18n( $total_deleted )
					)
					: __( 'There was nothing eligible to remove from cleanup-managed history.', 'nxt-cloud-chat' );
			}

			if ( $has_remaining ) {
				$summary = $clean_everything
					? __( 'Some items are still waiting because cleanup reached the batch limit for this run.', 'nxt-cloud-chat' )
					: __( 'Some older items are still waiting because cleanup reached the batch limit for this run.', 'nxt-cloud-chat' );
			}

			update_option(
				self::OPTION_LAST_RUN,
				array(
					'trigger'       => $clean_everything ? 'clean_everything' : ( $manual ? 'manual' : 'automatic' ),
					'status'        => $status,
					'started_at'    => $started_at,
					'finished_at'   => $finished_at,
					'total_deleted' => $total_deleted,
					'summary'       => $summary,
					'items'         => $results,
				),
				false
			);

			return array(
				'items'                 => $results,
				'total_deleted'         => $total_deleted,
				'total_deleted_display' => number_format_i18n( $total_deleted ),
				'has_remaining'         => $has_remaining,
				'summary'               => $summary,
				'last_run'              => self::get_last_run_view_data(),
			);
		} finally {
			delete_transient( self::LOCK_TRANSIENT );
		}
	}

	/**
	 * Schedule the next daily cleanup event.
	 *
	 * @param string $run_time Site-local HH:MM time.
	 * @return void
	 */
	private static function schedule_daily_event( string $run_time ): void {
		$run_time = self::validate_time_value( $run_time ) ? $run_time : self::DEFAULT_RUN_TIME;
		$parts    = explode( ':', $run_time );
		$hour     = isset( $parts[0] ) ? (int) $parts[0] : 3;
		$minute   = isset( $parts[1] ) ? (int) $parts[1] : 15;
		$tz       = wp_timezone();
		$now      = new DateTimeImmutable( 'now', $tz );
		$target   = $now->setTime( $hour, $minute, 0 );

		if ( $target <= $now ) {
			$target = $target->modify( '+1 day' );
		}

		wp_schedule_event( $target->getTimestamp(), 'daily', self::CRON_HOOK_DAILY );
	}

	/**
	 * Schedule one follow-up cleanup event when more items remain.
	 *
	 * @return void
	 */
	private static function schedule_followup_event(): void {
		if ( wp_next_scheduled( self::CRON_HOOK_FOLLOWUP ) ) {
			return;
		}

		wp_schedule_single_event( time() + ( 10 * MINUTE_IN_SECONDS ), self::CRON_HOOK_FOLLOWUP );
	}

	/**
	 * Format one UTC datetime into the local site timezone.
	 *
	 * @param string $datetime UTC datetime.
	 * @return string
	 */
	private static function format_local_datetime( string $datetime ): string {
		$datetime = trim( $datetime );

		if ( '' === $datetime || '0000-00-00 00:00:00' === $datetime ) {
			return '';
		}

		$timestamp = strtotime( $datetime . ' UTC' );

		if ( false === $timestamp ) {
			return '';
		}

		return wp_date( 'M j, Y g:i A', $timestamp, wp_timezone() );
	}

	/**
	 * Resolve one target retention period from settings.
	 *
	 * @param array<string,mixed> $settings     Cleanup settings.
	 * @param string              $target_id    Target id.
	 * @param int                 $default_days Default days.
	 * @return int
	 */
	private static function target_days( array $settings, string $target_id, int $default_days ): int {
		$target_id = sanitize_key( $target_id );
		$days      = isset( $settings['targets'][ $target_id ]['days'] ) ? (int) $settings['targets'][ $target_id ]['days'] : $default_days;

		return max( 1, $days );
	}
}
