<?php
namespace WPDevAssist\Assistant;

defined( 'ABSPATH' ) || exit;

abstract class Section {
	protected string $title              = '';
	protected string $content            = '';
	protected string $status_level       = 'success';
	protected string $status_description = '';

	/**
	 * @var Control[]
	 */
	protected array $controls = array();

	public function __construct() {
		$this->set_title();
		$this->set_content();
		$this->set_controls();
	}

	abstract protected function set_title(): void;

	abstract protected function set_content(): void;

	abstract protected function set_controls(): void;

	public function render(): void {
		?>
		<div class="da-assistant__section da-assistant__section_<?php echo esc_attr( $this->status_level ); ?>">
			<div class="da-assistant__section-title"><?php echo esc_html( $this->title ); ?></div>
			<div class="da-assistant__section-content"><?php echo wp_kses_post( $this->content ); ?></div>
			<?php if ( $this->controls ) { ?>
				<ul class="da-assistant__controls">
					<?php foreach ( $this->controls as $control ) { ?>
						<li><?php $control->render(); ?></li>
					<?php } ?>
				</ul>
			<?php } ?>
		</div>
		<?php
	}

	public function configure_status(): bool {
		return false;
	}

	public function render_status(): void {
		?>
		<span class="da-assistant__status da-assistant__status_<?php echo esc_attr( $this->status_level ); ?>">
			<?php echo esc_html( $this->title ); ?>
			<?php if ( $this->status_description ) { ?>
				<span class="da-assistant__status-description"><?php echo esc_html( $this->status_description ); ?></span>
			<?php } ?>
		</span>
		<?php
	}

	public function is_forces_panel_be_open(): bool {
		return false;
	}
}
