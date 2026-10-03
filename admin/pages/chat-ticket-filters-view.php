<?php
/**
 * Compact inbox ticket filter controls.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;
$nxtcc_filter_groups = array(
	'assignment' => array(
		'label'   => __( 'Assignment', 'nxt-cloud-chat' ),
		'all'     => __( 'All tickets', 'nxt-cloud-chat' ),
		'options' => array(
			'mine'       => __( 'My tickets', 'nxt-cloud-chat' ),
			'team'       => __( 'Team queue', 'nxt-cloud-chat' ),
			'following'  => __( 'Following', 'nxt-cloud-chat' ),
			'unassigned' => __( 'Unassigned', 'nxt-cloud-chat' ),
		),
	),
	'status'     => array(
		'label'   => __( 'Status', 'nxt-cloud-chat' ),
		'all'     => __( 'All statuses', 'nxt-cloud-chat' ),
		'options' => array(
			'open'     => __( 'Open', 'nxt-cloud-chat' ),
			'pending'  => __( 'Pending', 'nxt-cloud-chat' ),
			'snoozed'  => __( 'Snoozed', 'nxt-cloud-chat' ),
			'resolved' => __( 'Resolved', 'nxt-cloud-chat' ),
			'closed'   => __( 'Closed', 'nxt-cloud-chat' ),
		),
	),
	'priority'   => array(
		'label'   => __( 'Priority', 'nxt-cloud-chat' ),
		'all'     => __( 'All priorities', 'nxt-cloud-chat' ),
		'options' => array(
			'low'    => __( 'Low', 'nxt-cloud-chat' ),
			'normal' => __( 'Normal', 'nxt-cloud-chat' ),
			'high'   => __( 'High', 'nxt-cloud-chat' ),
			'urgent' => __( 'Urgent', 'nxt-cloud-chat' ),
		),
	),
);
?>
<div class="nxtcc-ticket-filters">
	<?php foreach ( $nxtcc_filter_groups as $nxtcc_filter_key => $nxtcc_filter_group ) : ?>
		<div class="nxtcc-ticket-filter" data-filter="<?php echo esc_attr( $nxtcc_filter_key ); ?>" data-label="<?php echo esc_attr( $nxtcc_filter_group['label'] ); ?>" data-all="<?php echo esc_attr( $nxtcc_filter_group['all'] ); ?>">
			<button type="button" class="nxtcc-ticket-filter-toggle" aria-expanded="false" aria-label="<?php echo esc_attr( $nxtcc_filter_group['label'] ); ?>" aria-controls="<?php echo esc_attr( $nxtcc_instance_id . '-filter-' . $nxtcc_filter_key ); ?>">
				<span class="nxtcc-ticket-filter-value"><?php echo esc_html( $nxtcc_filter_group['all'] ); ?></span>
				<i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
			</button>
			<div class="nxtcc-ticket-filter-menu" id="<?php echo esc_attr( $nxtcc_instance_id . '-filter-' . $nxtcc_filter_key ); ?>" role="group" aria-label="<?php echo esc_attr( $nxtcc_filter_group['label'] ); ?>" hidden>
				<div class="nxtcc-ticket-filter-tools">
					<label><input type="checkbox" class="nxtcc-ticket-filter-all"> <?php esc_html_e( 'Select all', 'nxt-cloud-chat' ); ?></label>
					<button type="button" class="nxtcc-ticket-filter-clear"><?php esc_html_e( 'Clear', 'nxt-cloud-chat' ); ?></button>
				</div>
				<?php if ( 'assignment' === $nxtcc_filter_key ) : ?>
					<label><input type="checkbox" class="nxtcc-ticket-filter-any" checked> <?php esc_html_e( 'All tickets', 'nxt-cloud-chat' ); ?></label>
				<?php endif; ?>
				<?php foreach ( $nxtcc_filter_group['options'] as $nxtcc_option_key => $nxtcc_option_label ) : ?>
					<label><input type="checkbox" class="nxtcc-ticket-filter-option" value="<?php echo esc_attr( $nxtcc_option_key ); ?>"> <span><?php echo esc_html( $nxtcc_option_label ); ?></span></label>
				<?php endforeach; ?>
				<?php if ( 'status' === $nxtcc_filter_key ) : ?>
					<label class="nxtcc-ticket-filter-overdue"><input type="checkbox" class="nxtcc-ticket-overdue"> <span><?php esc_html_e( 'Overdue only', 'nxt-cloud-chat' ); ?></span></label>
					<span class="screen-reader-text nxtcc-ticket-filter-disabled-hint"><?php esc_html_e( 'Completed tickets cannot be overdue.', 'nxt-cloud-chat' ); ?></span>
				<?php endif; ?>
			</div>
		</div>
	<?php endforeach; ?>
</div>
