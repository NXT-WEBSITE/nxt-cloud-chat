<?php
/**
 * WhatsApp Login widget.
 *
 * Registers a WordPress widget that renders the NXTCC WhatsApp login UI.
 *
 * @package NXTCC
 */

defined( 'ABSPATH' ) || exit;

/**
 * NXTCC WhatsApp Login Widget.
 */
class NXTCC_Login_WhatsApp_Widget extends WP_Widget {

	/**
	 * Set up widget name and description.
	 *
	 * @return void
	 */
	public function __construct() {
		parent::__construct(
			'nxtcc_login_whatsapp_widget',
			__( 'NXTCC: WhatsApp Login', 'nxt-cloud-chat' ),
			array(
				'description' => __( 'Displays the NXTCC WhatsApp login widget.', 'nxt-cloud-chat' ),
				'classname'   => 'widget_nxtcc_login_whatsapp',
			)
		);
	}

	/**
	 * Output the widget content on the front end.
	 *
	 * @param array $args     Widget arguments.
	 * @param array $instance Widget settings.
	 * @return void
	 */
	public function widget( $args, $instance ) {
		$args = wp_parse_args(
			is_array( $args ) ? $args : array(),
			array(
				'before_widget' => '',
				'after_widget'  => '',
				'before_title'  => '',
				'after_title'   => '',
			)
		);

		$title_raw = isset( $instance['title'] ) ? (string) $instance['title'] : '';
		$title     = apply_filters( 'widget_title', $title_raw, $instance, $this->id_base );

		// Ensure any required assets for the login UI are available.
		if ( function_exists( 'nxtcc_auth_enqueue_login_widget_assets' ) ) {
			nxtcc_auth_enqueue_login_widget_assets();
		}

		echo wp_kses_post( $args['before_widget'] );

		if ( '' !== $title ) {
			echo wp_kses_post( $args['before_title'] );
			echo esc_html( $title );
			echo wp_kses_post( $args['after_title'] );
		}

		/*
		 * Render the login widget markup.
		 * The renderer is expected to return HTML; output is restricted to safe tags.
		 */
		if ( function_exists( 'nxtcc_render_login_whatsapp' ) ) {
			$html = nxtcc_render_login_whatsapp( array() );
			if ( is_string( $html ) && '' !== $html ) {
				echo wp_kses_post( $html );
			}
		}

		echo wp_kses_post( $args['after_widget'] );
	}

	/**
	 * Output the widget settings form in wp-admin.
	 *
	 * @param array $instance Current widget settings.
	 * @return void
	 */
	public function form( $instance ) {
		$title      = isset( $instance['title'] ) ? (string) $instance['title'] : '';
		$field_id   = $this->get_field_id( 'title' );
		$field_name = $this->get_field_name( 'title' );
		?>
		<p>
			<label for="<?php echo esc_attr( $field_id ); ?>">
				<?php esc_html_e( 'Title (optional):', 'nxt-cloud-chat' ); ?>
			</label>
			<input
				class="widefat"
				id="<?php echo esc_attr( $field_id ); ?>"
				name="<?php echo esc_attr( $field_name ); ?>"
				type="text"
				value="<?php echo esc_attr( $title ); ?>"
			/>
		</p>
		<?php
	}

	/**
	 * Sanitize widget settings before saving.
	 *
	 * @param array $new_instance New widget settings.
	 * @param array $old_instance Previous widget settings.
	 * @return array Sanitized settings to save.
	 */
	public function update( $new_instance, $old_instance ) {
		$instance = is_array( $old_instance ) ? $old_instance : array();

		$title = isset( $new_instance['title'] ) ? (string) $new_instance['title'] : '';
		$title = sanitize_text_field( $title );

		if ( function_exists( 'mb_substr' ) ) {
			$title = mb_substr( $title, 0, 200 );
		} else {
			$title = substr( $title, 0, 200 );
		}

		$instance['title'] = $title;

		return $instance;
	}
}
