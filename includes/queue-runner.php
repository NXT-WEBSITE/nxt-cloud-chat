<?php
/**
 * Queue runner bootstrap.
 *
 * Schedules and runs a recurring cron event to process the broadcast queue.
 * - Promotes 'scheduled' → 'queued'.
 * - Sends queued jobs via the broadcast worker when available.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cron hook name for processing the queue.
 */
const NXTCC_QUEUE_CRON_HOOK = 'nxtcc_process_queue_cron';

/**
 * Cron schedule slug used by this plugin.
 *
 * VIP guidance discourages very frequent crons; use 15 minutes by default.
 */
const NXTCC_QUEUE_CRON_SCHEDULE = 'nxtcc_every_15_minutes';

/**
 * Register the cron schedule and ensure the cron event is scheduled.
 *
 * @return void
 */
function nxtcc_queue_runner_boot(): void {
	if ( ! wp_next_scheduled( NXTCC_QUEUE_CRON_HOOK ) ) {
		// Run first tick soon, then on the configured interval.
		wp_schedule_event( time() + 60, NXTCC_QUEUE_CRON_SCHEDULE, NXTCC_QUEUE_CRON_HOOK );
	}
}
add_action( 'init', 'nxtcc_queue_runner_boot' );

/**
 * Register a custom schedule for the queue runner.
 *
 * @param array $schedules Existing cron schedules.
 * @return array
 */
function nxtcc_queue_runner_add_schedule( array $schedules ): array {
	if ( ! isset( $schedules[ NXTCC_QUEUE_CRON_SCHEDULE ] ) ) {
		$display = 'Every 15 Minutes (NXTCC)';
		if ( did_action( 'init' ) ) {
			$display = __( 'Every 15 Minutes (NXTCC)', 'nxt-cloud-chat' );
		}

		$schedules[ NXTCC_QUEUE_CRON_SCHEDULE ] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => $display,
		);
	}

	return $schedules;
}
add_filter( 'cron_schedules', 'nxtcc_queue_runner_add_schedule' );

/**
 * Cron callback: run the broadcast job processor if present.
 *
 * @return void
 */
function nxtcc_queue_runner_process(): void {
	if ( ! function_exists( 'nxtcc_process_broadcast_jobs' ) ) {
		return;
	}

	try {
		nxtcc_process_broadcast_jobs();
	} catch ( \Throwable $e ) {
		/*
		 * The worker is optional and may throw due to transient network/API errors.
		 * We intentionally swallow exceptions here so cron does not fatally break.
		 * When debugging is enabled, expose the error through core-style hooks.
		 */
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$message = '[NXTCC] Queue runner error: ' . (string) $e->getMessage();

			if ( function_exists( 'wp_trigger_error' ) ) {
				wp_trigger_error( 'nxtcc_queue_runner_process', $message );
			}

			/**
			 * Fires when the queue runner catches an exception.
			 *
			 * @param string    $message Error message.
			 * @param \Throwable $e      Caught exception.
			 */
			do_action( 'nxtcc_queue_runner_error', $message, $e );
		}
	}
}
add_action( NXTCC_QUEUE_CRON_HOOK, 'nxtcc_queue_runner_process' );

/**
 * Clean up scheduled cron on plugin deactivation.
 *
 * @return void
 */
function nxtcc_queue_runner_deactivate(): void {
	$timestamp = wp_next_scheduled( NXTCC_QUEUE_CRON_HOOK );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, NXTCC_QUEUE_CRON_HOOK );
	}
}
register_deactivation_hook( NXTCC_PLUGIN_FILE, 'nxtcc_queue_runner_deactivate' );
