<?php
namespace WPDevAssist\Setting;

use WP_Error;
use WPDevAssist\Model\ActionLink;
use WPDevAssist\ActionQuery;
use WPDevAssist\Asset;
use WPDevAssist\AdminNotice;
use WPDevAssist\ExternalFileMutationManager;
use WPDevAssist\Fs;
use WPDevAssist\Setting;
use const WPDevAssist\KEY;

defined( 'ABSPATH' ) || exit;

class DebugLog extends Page {
	public const KEY                    = KEY . '_debug_log';
	public const DELETE_LOG_QUERY_KEY   = KEY . '_delete_debug_log';
	public const DOWNLOAD_LOG_QUERY_KEY = KEY . '_download_debug_log';

	protected const NORMAL_SIZE                = MB_IN_BYTES * 10;
	protected const LOG_FILE_PATH              = WP_CONTENT_DIR . '/debug.log';
	protected const ORIGINAL_EXISTENCE_KEY     = KEY . '_original_debug_log_existence';
	protected const ORIGINAL_EXISTENCE_DEFAULT = 'yes';

	protected ActionQuery $action_query;
	protected ExternalFileMutationManager $file_mutations;
	protected Fs $fs;

	public function __construct( ActionQuery $action_query, Asset $asset, AdminNotice $admin_notice, Fs $fs, ExternalFileMutationManager $file_mutations ) {
		$this->action_query   = $action_query;
		$this->file_mutations = $file_mutations;
		$this->fs             = $fs;

		parent::__construct( $asset, $admin_notice );
		$action_query->add( static::DELETE_LOG_QUERY_KEY, $this->handle_delete_file() );
		$action_query->add( static::DOWNLOAD_LOG_QUERY_KEY, $this->handle_download_file() );
		add_action( 'admin_head', $this->render_notice_disabled_logs() );
	}

	protected function add_page(): callable {
		return function (): void {
			$page_title = __( 'debug.log', 'development-assistant' );

			add_submenu_page(
				KEY,
				$page_title,
				$page_title,
				'administrator', // phpcs:ignore
				static::KEY,
				$this->render_page()
			);
		};
	}

	protected function add_sections(): callable {
		return function (): void {};
	}

	protected function render_content(): void {
		?>
		<div class="da-debug-log">
			<?php $this->render_actions(); ?>
			<div class="da-debug-log__container">
				<?php
				if ( $this->is_file_exists() ) {
					$log = $this->fs->read_text_file_tail( static::LOG_FILE_PATH );

					if ( $log ) {
						?>
						<div class="da-debug-log__content">
							<?php echo wp_kses( nl2br( $log ), array( 'br' => array() ) ); ?>
						</div>
						<?php
					} else {
						?>
						<div class="da-debug-log__content da-debug-log__content_error">
							<?php echo esc_html__( 'Can\'t read the log file.', 'development-assistant' ); ?>
						</div>
						<?php
					}
					?>
				<?php } else { ?>
					<div class="da-debug-log__content da-debug-log__content_empty">
						<?php echo esc_html__( 'Log is empty.', 'development-assistant' ); ?>
					</div>
				<?php } ?>
			</div>
		</div>
		<?php
	}

	protected function render_actions(): void {
		$link_delete_log   = $this->action_query->get_url( static::DELETE_LOG_QUERY_KEY, $this->get_page_url() );
		$link_download_log = $this->action_query->get_url( static::DOWNLOAD_LOG_QUERY_KEY, $this->get_page_url() );
		$is_file_exists    = $this->is_file_exists();
		?>
		<ul class="da-debug-log__actions">
			<li>
					<a
						class="button button-primary <?php echo $is_file_exists ? '' : 'button-disabled'; ?>"
						<?php echo $is_file_exists ? 'href="' . esc_url( $link_download_log ) . '"' : ''; ?>
					>
						<?php echo esc_html__( 'Download', 'development-assistant' ); ?>
					</a>
				</li>
				<li>
					<?php
					( new ActionLink(
						__( 'Delete file', 'development-assistant' ),
						$link_delete_log,
						$this->get_deletion_confirmation_massage(),
						false,
						'button button-secondary' . ( $is_file_exists ? '' : ' button-disabled' ),
						! $is_file_exists
					) )->render();
					?>
				</li>
				<li>
					<?php $this->render_file_size( $is_file_exists ); ?>
				</li>
				<li>
					<?php $this->render_direct_access_status(); ?>
				</li>
		</ul>
		<?php
	}

	protected function render_file_size( bool $is_file_exists ): void {
		$file_size = $is_file_exists ? filesize( static::LOG_FILE_PATH ) : 0;
		$is_large  = static::NORMAL_SIZE <= $file_size;
		?>
		<div class="da-debug-log__status <?php echo $is_large ? 'da-debug-log__status_error' : ''; ?>">
			<span class="dashicons dashicons-cloud"></span>
			<span>
				<?php
				echo esc_html( size_format( $file_size ) );

				if ( $is_large ) {
					echo ' (' . esc_html__( 'size is large', 'development-assistant' ) . ')';
				}
				?>
			</span>
		</div>
		<?php
	}

	protected function render_direct_access_status(): void {
		$is_direct_access_disabled = 'yes' === get_option( Setting::DISABLE_DIRECT_ACCESS_TO_LOG_KEY, Setting::DISABLE_DIRECT_ACCESS_TO_LOG_DEFAULT );

		if ( $is_direct_access_disabled ) {
			$icon_class = 'dashicons-lock';
			$message    = esc_html__( 'Direct access to the file via %s is disabled.', 'development-assistant' );
		} else {
			$icon_class = 'dashicons-unlock';
			$message    = esc_html__( 'Direct access to the file via %s is enabled.', 'development-assistant' );
			$url        = $this->action_query->get_url( Setting::DISABLE_DIRECT_ACCESS_TO_LOG_QUERY_KEY );
			$message   .= ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Disable', 'development-assistant' ) . '</a>';
		}
		?>
		<div class="da-debug-log__status <?php echo $is_direct_access_disabled ? 'da-debug-log__status_success' : 'da-debug-log__status_error'; ?>">
			<span class="dashicons <?php echo esc_attr( $icon_class ); ?>"></span>
			<span>
				<?php
				echo wp_kses_post(
					sprintf(
						$message,
						'<a href="' . $this->get_public_url() . '" target="_blank">' . esc_html__( 'the link', 'development-assistant' ) . '</a>'
					)
				);
				?>
			</span>
		</div>
		<?php
	}

	public function get_deletion_confirmation_massage(): string {
		return __( 'Are you sure to delete the debug.log file? This action is irreversible.', 'development-assistant' );
	}

	protected function handle_delete_file(): callable {
		return function (): void {
			$error = $this->delete_file();

			if ( $error instanceof WP_Error ) {
				$this->admin_notice->add_transient( $error->get_error_message(), 'error' );

				return;
			}

			$this->admin_notice->add_transient( __( 'Log file deleted.', 'development-assistant' ), 'success' );
		};
	}

	protected function handle_download_file(): callable {
		return function (): void {
			$file = @fopen( $this->get_log_file_path(), 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen

			if ( false === $file ) {
				$this->admin_notice->add_transient( __( 'The debug log could not be downloaded because it is missing or unreadable.', 'development-assistant' ), 'error' );

				return;
			}

			$file_data = fstat( $file );

			if ( false === $file_data ) {
				fclose( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				$this->admin_notice->add_transient( __( 'The debug log could not be downloaded because it is missing or unreadable.', 'development-assistant' ), 'error' );

				return;
			}

			$filename = str_replace(
				'.',
				'_',
				str_replace( array( 'http://', 'https://' ), '', home_url() )
			) . '_debug.log';

			header( 'Content-Type: text/plain' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			header( 'Content-Length: ' . $file_data['size'] );
			flush();
			fpassthru( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fpassthru
			fclose( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

			exit;
		};
	}

	public function store_original_file_existence(): void {
		update_option(
			static::ORIGINAL_EXISTENCE_KEY,
			$this->is_file_exists() ? 'yes' : 'no'
		);
	}

	public function delete_file_if_originally_not_exists(): void {
		if (
			'no' === get_option( static::ORIGINAL_EXISTENCE_KEY, static::ORIGINAL_EXISTENCE_DEFAULT ) &&
			$this->is_file_exists()
		) {
			$error = $this->delete_file();

			if ( $error instanceof WP_Error ) {
				$this->admin_notice->add_transient( $error->get_error_message(), 'error' );

				return;
			}
		}

		delete_option( static::ORIGINAL_EXISTENCE_KEY );
	}

	public function is_file_exists(): bool {
		return file_exists( $this->get_log_file_path() );
	}

	/**
	 * @return WP_Error|null
	 */
	protected function delete_file(): ?WP_Error {
		if ( ! current_user_can( 'administrator' ) ) { // phpcs:ignore
			return new WP_Error(
				'debug_log_delete_forbidden',
				__( 'You are not allowed to delete the debug log.', 'development-assistant' )
			);
		}

		return $this->file_mutations->delete_debug_log();
	}

	protected function get_log_file_path(): string {
		return static::LOG_FILE_PATH;
	}

	public function get_public_url(): string {
		return WP_CONTENT_URL . '/debug.log';
	}

	protected function render_notice_disabled_logs(): callable {
		return function (): void {
			if (
				! $this->is_current() ||
				(
					'yes' === get_option( Setting::ENABLE_WP_DEBUG_KEY, Setting::ENABLE_WP_DEBUG_DEFAULT ) &&
					'yes' === get_option( Setting::ENABLE_WP_DEBUG_LOG_KEY, Setting::ENABLE_WP_DEBUG_LOG_DEFAULT )
				)
			) {
				return;
			}

			$message  = __( 'Logging is disabled.', 'development-assistant' );
			$url      = $this->action_query->get_url( Setting::ENABLE_DEBUG_LOG_QUERY_KEY );
			$message .= ' <a href="' . $url . '">' . __( 'Enable', 'development-assistant' ) . '</a>';

			$this->admin_notice->render( $message, 'warning', false );
		};
	}

	protected function enqueue_assets(): callable {
		return function (): void {
			if ( ! $this->is_current() ) {
				return;
			}

			$this->asset->enqueue_style( 'debug-log' );
		};
	}
}
