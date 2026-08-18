<?php
namespace WPDevAssist\Setting;

use Exception;

defined( 'ABSPATH' ) || exit;

class Control {
	protected const TEXT_INPUT_REQUIRED_ARGS = array(
		'name',
		'default',
	);

	protected const STATUS_REQUIRED_ARGS = array(
		'is_success',
		'success_title',
		'failure_title',
	);

	public function render_checkbox( array $args ): void {
		$value       = get_option( $args['name'], $args['default'] );
		$is_disabled = isset( $args['disabled'] ) && $args['disabled'];
		?>
		<div class="da-setting-checkbox <?php echo $is_disabled ? 'da-setting-checkbox_disabled' : ''; ?>">
			<input
				type="hidden"
				name="<?php echo esc_attr( $args['name'] ); ?>"
				value="no"
			>
			<label>
				<span>
					<input
						type="checkbox"
						name="<?php echo esc_attr( $args['name'] ); ?>"
						value="yes"
						<?php checked( 'yes', $value ); ?>
						<?php disabled( true, $is_disabled ); ?>
					>
				</span>
				<?php
				if ( isset( $args['description'] ) && $args['description'] ) {
					?>
					<span class="da-setting-checkbox__description">
						<?php echo wp_kses_post( $args['description'] ); ?>
					</span>
					<?php
				}
				?>
			</label>
		</div>
		<?php
	}

	/**
	 * @throws Exception
	 */
	public function render_text_input( array $args ): void {
		$this->validate_required_args( static::TEXT_INPUT_REQUIRED_ARGS, $args );

		$value       = get_option( $args['name'], $args['default'] );
		$is_disabled = isset( $args['disabled'] ) && $args['disabled'];
		$type        = $args['type'] ?? 'text';
		$min         = $args['min'] ?? false;
		$max         = $args['max'] ?? false;
		$step        = $args['step'] ?? false;
		?>
		<div class="da-setting-text">
			<label>
				<span>
					<input
						type="<?php echo esc_attr( $type ); ?>"
						name="<?php echo esc_attr( $args['name'] ); ?>"
						value="<?php echo esc_attr( $value ); ?>"
						<?php echo is_numeric( $min ) ? 'min="' . esc_attr( $min ) . '"' : ''; ?>
						<?php echo is_numeric( $max ) ? 'max="' . esc_attr( $max ) . '"' : ''; ?>
						<?php echo is_numeric( $step ) ? 'step="' . esc_attr( $step ) . '"' : ''; ?>
						<?php disabled( true, $is_disabled ); ?>
					>
					<?php if ( $is_disabled ) { ?>
						<input
							type="hidden"
							name="<?php echo esc_attr( $args['name'] ); ?>"
							value="<?php echo esc_attr( $value ); ?>"
						>
					<?php } ?>
				</span>
				<?php
				if ( isset( $args['description'] ) && $args['description'] ) {
					?>
					<span class="da-setting-text__description">
						<?php echo wp_kses_post( $args['description'] ); ?>
					</span>
					<?php
				}
				?>
			</label>
		</div>
		<?php
	}

	/**
	 * @throws Exception
	 */
	public function render_status( array $args ): void {
		$this->validate_required_args( static::STATUS_REQUIRED_ARGS, $args );

		if ( isset( $args['disabled'] ) && $args['disabled'] ) {
			$status_classname = 'da-setting-status__label_disabled';
		} elseif ( $args['is_success'] ) {
			$status_classname = 'da-setting-status__label_success';
		} else {
			$status_classname = 'da-setting-status__label_failure';
		}
		?>
		<div class="da-setting-status">
			<span class="da-setting-status__label <?php echo esc_attr( $status_classname ); ?>">
				<?php
				if ( isset( $args['disabled'] ) && $args['disabled'] ) {
					if ( isset( $args['disabled_title'] ) ) {
						echo wp_kses_post( $args['disabled_title'] );
					} else {
						echo esc_html__( 'Disabled', 'development-assistant' );
					}
				} elseif ( $args['is_success'] ) {
					echo wp_kses_post( $args['success_title'] );
				} else {
					echo wp_kses_post( $args['failure_title'] );
				}
				?>
			</span>
			<?php if ( isset( $args['description'] ) && $args['description'] ) { ?>
				<div class="da-setting-status__description">
					<?php echo wp_kses_post( $args['description'] ); ?>
				</div>
			<?php } ?>
		</div>
		<?php
	}

	/**
	 * @throws Exception
	 */
	protected function validate_required_args( array $required_args, array $args ): void {
		foreach ( $required_args as $required_arg ) {
			if ( ! isset( $args[ $required_arg ] ) ) {
				throw new Exception( esc_html( "The \"$required_arg\" argument is required" ) );
			}
		}
	}
}
