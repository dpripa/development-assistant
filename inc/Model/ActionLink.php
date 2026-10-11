<?php
namespace WPDevAssist\Model;

defined( 'ABSPATH' ) || exit;

class ActionLink {
	protected string $title;
	protected string $url;
	protected string $confirm;
	protected bool $target_blank;
	protected string $class_names;
	protected bool $is_disabled;

	public function __construct(
		string $title,
		string $url,
		string $confirm = '',
		bool $target_blank = false,
		string $class_names = '',
		bool $is_disabled = false
	) {
		$this->title        = $title;
		$this->url          = $url;
		$this->confirm      = $confirm;
		$this->target_blank = $target_blank;
		$this->class_names  = $class_names;
		$this->is_disabled  = $is_disabled;
	}

	public function render(): void {
		?>
		<a
			<?php echo ! $this->is_disabled ? 'href="' . esc_url( $this->url ) . '"' : ''; ?>
			<?php echo $this->confirm && ! $this->is_disabled ? 'onclick="return confirm(\'' . esc_js( $this->confirm ) . '\')"' : ''; ?>
			<?php echo $this->target_blank ? 'target="_blank"' : ''; ?>
			<?php echo $this->class_names ? 'class="' . esc_attr( $this->class_names ) . '"' : ''; ?>
		>
			<?php echo wp_kses( $this->title, array( 'code' => array() ) ); ?>
		</a>
		<?php
	}

	public function get_title(): string {
		return $this->title;
	}

	public function get_url(): string {
		return $this->url;
	}

	public function get_confirm(): string {
		return $this->confirm;
	}

	public function is_target_blank(): bool {
		return $this->target_blank;
	}

	public function get_class_names(): string {
		return $this->class_names;
	}

	public function is_disabled(): bool {
		return $this->is_disabled;
	}
}
