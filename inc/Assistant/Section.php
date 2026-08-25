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

	public function get_title(): string {
		return $this->title;
	}

	public function get_content(): string {
		return $this->content;
	}

	public function get_status_level(): string {
		return $this->status_level;
	}

	public function get_status_description(): string {
		return $this->status_description;
	}

	/**
	 * @return Control[]
	 */
	public function get_controls(): array {
		return $this->controls;
	}

	public function configure_status(): bool {
		return false;
	}
}
