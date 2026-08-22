<?php
namespace WPDevAssist\Setting;

use Exception;
use WP_Error;
use WPDevAssist\Model\ActionLink;
use WPDevAssist\ActionQuery;
use WPDevAssist\AdminNotice;
use WPDevAssist\Asset;
use const WPDevAssist\KEY;

class SupportUser extends Page {
	public const KEY                        = KEY . '_support_user';
	public const ENABLE_KEY                 = KEY . '_enable_support_user';
	public const ENABLE_DEFAULT             = 'yes';
	public const DELETE_AFTER_DAYS_KEY      = KEY . '_delete_support_user_after_days';
	public const DELETE_AFTER_DAYS_DEFAULT  = 3;
	public const ID_KEY                     = KEY . '_support_user_id';
	public const ID_DEFAULT                 = 0;
	public const LOGIN_KEY                  = KEY . '_support_user_login';
	public const LOGIN_DEFAULT              = '';
	public const PASSWORD_KEY               = KEY . '_support_user_password';
	public const PASSWORD_DEFAULT           = '';
	public const PASSWORD_SHOWN_VALUE       = '************';
	public const CREATED_AT_KEY             = KEY . '_support_user_created_at';
	public const CREATED_AT_DEFAULT         = 0;
	public const EMAIL_KEY                  = KEY . '_support_user_email';
	public const EMAIL_DEFAULT              = '';
	public const CREATE_QUERY_KEY           = KEY . '_create_support_user';
	public const DELETE_QUERY_KEY           = KEY . '_delete_support_user';
	public const RECREATE_QUERY_KEY         = KEY . '_recreate_support_user';
	public const SHARE_EMAIL_QUERY_KEY      = KEY . '_share_support_user_email';
	public const UPDATE_CREATE_AT_QUERY_KEY = KEY . '_update_create_support_user';
	public const ENABLE_HOOK                = KEY . '_enable_support_user';
	public const EMAIL_HOOK                 = self::KEY . '_email';

	protected const SETTING_KEYS = array(
		self::ENABLE_KEY,
		self::DELETE_AFTER_DAYS_KEY,
	);

	protected const SHARE_PASSWORD_QUERY_KEY = KEY . '_share_support_user_password';
	protected const SHARE_MESSAGE_QUERY_KEY  = KEY . '_share_support_user_message';

	protected ActionQuery $action_query;
	protected Control $control;

	public function __construct(
		ActionQuery $action_query,
		Asset $asset,
		AdminNotice $admin_notice,
		Control $control
	) {
		$this->action_query = $action_query;
		$this->control      = $control;

		add_action( 'deleted_user', $this->delete_data_when_user_deleted() );

		if ( ! apply_filters( static::ENABLE_HOOK, true ) ) {
			return;
		}

		parent::__construct( $asset, $admin_notice );
		$action_query->add( static::CREATE_QUERY_KEY, $this->handle_create_user() );
		$action_query->add( static::DELETE_QUERY_KEY, $this->handle_delete_user() );
		$action_query->add( static::RECREATE_QUERY_KEY, $this->handle_recreate_user() );
		$action_query->add( static::SHARE_EMAIL_QUERY_KEY, $this->handle_share_to_email(), false );
		$action_query->add( static::UPDATE_CREATE_AT_QUERY_KEY, $this->handle_update_create_at() );
		add_action( 'update_option_' . static::ENABLE_KEY, $this->delete_user_if_disabled(), 10, 2 );
		add_action( 'admin_init', $this->delete_user_after_days() );
	}

	protected function add_page(): callable {
		return function (): void {
			$page_title = __( 'Support User', 'development-assistant' );

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
		return function (): void {
			$this->add_general_section( static::KEY . '_general' );
			$this->add_user_data_section( static::KEY . '_user_data' );
		};
	}

	protected function add_general_section( string $section_key ): void {
		$this->add_section(
			$section_key,
			'',
			function (): void {
				?>
				<div class="da-setting-section__description">
					<?php echo wp_kses( __( 'This section allows you to quickly create a user with administrator role that can be used by the support team to access the website for debugging purposes.', 'development-assistant' ), array( 'b' => array() ) ); ?>
				</div>
				<?php
			}
		);
		$this->add_setting(
			$section_key,
			static::ENABLE_KEY,
			__( 'Enable support user', 'development-assistant' ),
			array( $this->control, 'render_checkbox' ),
			static::ENABLE_DEFAULT
		);
		$this->add_setting(
			$section_key,
			static::DELETE_AFTER_DAYS_KEY,
			__( 'Delete user after days', 'development-assistant' ),
			array( $this->control, 'render_text_input' ),
			static::DELETE_AFTER_DAYS_DEFAULT,
			array(
				'type'        => 'number',
				'min'         => 0,
				'step'        => 1,
				'description' => __( 'Set to 0 to disable automatic deletion.', 'development-assistant' ),
			)
		);
	}

	protected function add_user_data_section( string $section_key ): void {
		if ( 'yes' !== get_option( static::ENABLE_KEY, self::ENABLE_DEFAULT ) ) {
			return;
		}

		$this->add_section(
			$section_key,
			__( 'User Credentials', 'development-assistant' ),
			$this->render_user_data_section()
		);
	}

	protected function render_user_data_section(): callable {
		return function (): void {
			?>
			<div class="da-support-user">
				<?php
				if ( 0 === get_option( static::ID_KEY, self::ID_DEFAULT ) ) {
					$this->render_user_data_section_empty();

					return;
				}

				$password          = $this->get_password_once_and_mask_it();
				$is_password_shown = static::PASSWORD_SHOWN_VALUE === $password;
				?>
				<ul class="da-support-user__credentials" id="da-support-user-credentials">
					<li style="display: none;"><?php echo esc_html( wp_login_url() ); ?></li>
					<li>
						<b><?php echo esc_html__( 'Username', 'development-assistant' ); ?>:</b> <span><?php echo esc_html( get_option( static::LOGIN_KEY, static::LOGIN_DEFAULT ) ); ?></span>
					</li>
					<li>
						<b><?php echo esc_html__( 'Password', 'development-assistant' ); ?>:</b> <span id="da-support-user-password"><?php echo esc_html( $password ); ?></span>
					</li>
				</ul>
				<?php
				echo wp_kses_post( $this->get_details( 'da-support-user__details' ) );

				if ( ! $is_password_shown ) {
					?>
					<div class="da-support-user__description">
						<b><?php echo esc_html__( 'Note!', 'development-assistant' ); ?></b> <?php echo esc_html__( 'The password is displayed only once, then it will be encrypted and you will not be able to see or share it.', 'development-assistant' ); ?>
						<br>
						<b><?php echo esc_html__( 'Please, be careful who you share access with, this user will have access to all administrative capabilities.', 'development-assistant' ); ?></b>
					</div>
				<?php } else { ?>
					<div class="da-support-user__description">
						<b><?php echo esc_html__( 'Note!', 'development-assistant' ); ?></b> <?php echo esc_html__( 'You cannot share credentials that have already been displayed. Recreate the user if necessary.', 'development-assistant' ); ?>
					</div>
				<?php } ?>
				<ul class="da-support-user__controls">
					<?php if ( ! $is_password_shown ) { ?>
						<li>
							<button  type="button" class="button da-support-user__copy" id="da-copy-support-user-credentials">
							<span class="da-support-user__copy-text">
								<?php echo esc_html__( 'Copy to Clipboard', 'development-assistant' ); ?>
							</span>
								<span class="da-support-user__copied-text">
								<span class="dashicons dashicons-saved"></span>
								<span><?php echo esc_html__( 'Copied!', 'development-assistant' ); ?></span>
							</span>
							</button>
						</li>
					<?php } elseif ( $this->is_allowed_continue_existence() ) { ?>
						<li>
							<a
								href="<?php echo esc_url( $this->action_query->get_url( static::UPDATE_CREATE_AT_QUERY_KEY ) ); ?>"
							>
								<?php echo esc_html__( 'Continue existence', 'development-assistant' ); ?>
							</a>
						</li>
					<?php } ?>
					<li>
						<?php
						( new ActionLink(
							__( 'Recreate user', 'development-assistant' ),
							$this->action_query->get_url( static::RECREATE_QUERY_KEY ),
							$this->get_recreation_confirmation_massage()
						) )->render();
						?>
					</li>
					<li>
						<?php
						( new ActionLink(
							__( 'Delete user', 'development-assistant' ),
							$this->action_query->get_url( static::DELETE_QUERY_KEY ),
							$this->get_deletion_confirmation_massage(),
							false,
							'da-support-user__link-danger'
						) )->render();
						?>
					</li>
				</ul>
				<?php if ( ! $is_password_shown ) { ?>
					<div class="da-support-user__share-form" id="da-share-support-user-form">
						<div class="da-support-user__share-header">
							<span><?php echo esc_html__( 'Or you can', 'development-assistant' ); ?></span>
							<h2><?php echo esc_html__( 'Share to Email', 'development-assistant' ); ?></h2>
						</div>
						<div class="da-support-user__share-field">
							<input
								id="da-share-support-user-email"
								type="email"
								placeholder="<?php echo esc_attr__( 'Email you want to share with', 'development-assistant' ); ?>"
								aria-label="<?php echo esc_attr__( 'Email', 'development-assistant' ); ?>"
								value="<?php echo esc_attr( apply_filters( static::EMAIL_HOOK, '' ) ); ?>"
							>
						</div>
						<div class="da-support-user__share-field">
						<textarea
							id="da-share-support-user-message"
							placeholder="<?php echo esc_attr__( 'Your message (optional)', 'development-assistant' ); ?>"
							aria-label="<?php echo esc_attr__( 'Optional message', 'development-assistant' ); ?>"
						></textarea>
						</div>
						<button type="button" class="button" id="da-share-support-user">
							<?php echo esc_html__( 'Share Credentials', 'development-assistant' ); ?>
						</button>
					</div>
				<?php } ?>
			</div>
			<?php
		};
	}

	public function get_details( string $classname, bool $display_email = true ): string {
		ob_start();

		$email          = get_option( static::EMAIL_KEY, static::EMAIL_DEFAULT );
		$is_auto_delete = 0 < intval( get_option( static::DELETE_AFTER_DAYS_KEY, static::DELETE_AFTER_DAYS_DEFAULT ) );

		if ( $email || $is_auto_delete ) {
			?>
			<ul class="<?php echo esc_attr( $classname ); ?>">
				<?php if ( $email && $display_email ) { ?>
					<li>
						<b><?php echo esc_html__( 'Shared with email', 'development-assistant' ); ?>:</b> <a href="mailto:<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $email ); ?></a>
					</li>
					<?php
				}

				if ( $is_auto_delete ) {
					?>
					<li>
						<b><?php echo esc_html__( 'Will be auto-deleted', 'development-assistant' ); ?>:</b>
						<?php
						echo sprintf( // phpcs:ignore
							esc_html__( 'after less than %s days', 'development-assistant' ),
							esc_html( $this->get_days_for_auto_delete() )
						);
						?>
					</li>
				<?php } ?>
			</ul>
			<?php
		}

		return ob_get_clean();
	}

	public function get_recreation_confirmation_massage(): string {
		return __( 'Are you sure to recreate the support user? This will cause loss of access for those providing support, you will need to reshare this to keep their access.', 'development-assistant' );
	}

	public function get_deletion_confirmation_massage(): string {
		return __( 'Are you sure to delete the support user? This will cause loss of access for those providing support.', 'development-assistant' );
	}

	protected function render_user_data_section_empty(): void {
		?>
		<div class="da-support-user__description">
			<?php echo esc_html__( 'Support user not yet created.', 'development-assistant' ); ?>
		</div>
		<ul class="da-support-user__controls">
			<li>
				<a href="<?php echo esc_url( $this->action_query->get_url( static::CREATE_QUERY_KEY ) ); ?>">
					<?php echo esc_html__( 'Create user in one click', 'development-assistant' ); ?>
				</a>
			</li>
		</ul>
		<?php
	}

	protected function get_password_once_and_mask_it(): string {
		$password = get_option( static::PASSWORD_KEY, static::PASSWORD_DEFAULT );

		update_option( static::PASSWORD_KEY, static::PASSWORD_SHOWN_VALUE );

		return $password;
	}

	public function is_allowed_continue_existence(): bool {
		return 1 <= intval( get_option( static::DELETE_AFTER_DAYS_KEY, static::DELETE_AFTER_DAYS_DEFAULT ) ) &&
			1 === $this->get_days_for_auto_delete();
	}

	protected function get_days_for_auto_delete(): int {
		$created_at   = intval( get_option( static::CREATED_AT_KEY, static::CREATED_AT_DEFAULT ) );
		$delete_after = intval( get_option( static::DELETE_AFTER_DAYS_KEY, static::DELETE_AFTER_DAYS_DEFAULT ) );
		$result       = ceil( ( $created_at + $delete_after * DAY_IN_SECONDS - time() ) / DAY_IN_SECONDS );

		return 0 < $result ? $result : 1;
	}

	protected function handle_create_user(): callable {
		return function (): void {
			$error = $this->create_user();

			if ( $error instanceof WP_Error ) {
				$this->admin_notice->add_transient( $error->get_error_message(), 'error' );

				return;
			}

			$this->admin_notice->add_transient( __( 'Support user created.', 'development-assistant' ), 'success' );
		};
	}

	protected function handle_delete_user(): callable {
		return function (): void {
			$error = $this->delete_user();

			if ( $error instanceof WP_Error ) {
				$this->admin_notice->add_transient( $error->get_error_message(), 'error' );

				return;
			}

			$this->admin_notice->add_transient( __( 'Support user deleted.', 'development-assistant' ), 'success' );
		};
	}

	protected function handle_recreate_user(): callable {
		return function (): void {
			$old_user_id = intval( get_option( static::ID_KEY, static::ID_DEFAULT ) );

			if ( 0 >= $old_user_id || ! get_userdata( $old_user_id ) ) {
				$this->delete_user_data();
				$this->admin_notice->add_transient( __( 'Support user does not exist. Stale stored credentials were removed; create a new support user.', 'development-assistant' ), 'error' );

				return;
			}

			$user_data = $this->insert_user();

			if ( $user_data instanceof WP_Error ) {
				$this->admin_notice->add_transient(
					sprintf(
						__( 'Support user could not be recreated: %s The existing support user remains active.', 'development-assistant' ),
						$user_data->get_error_message()
					),
					'error'
				);

				return;
			}

			$delete_error = $this->delete_user();

			if ( $delete_error instanceof WP_Error ) {
				if ( wp_delete_user( $user_data['id'] ) ) {
					$this->admin_notice->add_transient( __( 'Support user could not be recreated because the existing account could not be deleted. The replacement account was removed; verify the existing account and try again.', 'development-assistant' ), 'error' );
				} else {
					$this->admin_notice->add_transient(
						sprintf(
							__( 'Support user could not be recreated, and the replacement account "%s" could not be removed. Delete that account manually before trying again.', 'development-assistant' ),
							$user_data['login']
						),
						'error'
					);
				}

				return;
			}

			$this->store_user_data( $user_data );
			$this->admin_notice->add_transient( __( 'Support user recreated.', 'development-assistant' ), 'success' );
		};
	}

	protected function handle_share_to_email(): callable {
		return function ( array $data, array $post_data ): void {
			$email       = filter_var( wp_unslash( $data[ static::SHARE_EMAIL_QUERY_KEY ] ), FILTER_VALIDATE_EMAIL );
			$password    = sanitize_text_field( wp_unslash( $post_data[ static::SHARE_PASSWORD_QUERY_KEY ] ) );
			$redirect_to = remove_query_arg( array( static::SHARE_EMAIL_QUERY_KEY, '_wpnonce' ) );

			if ( ! $email ) {
				$this->admin_notice->add_transient( __( 'Invalid email. Please recreate the user and try sharing again.', 'development-assistant' ), 'error' );
				wp_safe_redirect( $redirect_to );

				exit;
			}

			if ( empty( $password ) || static::PASSWORD_SHOWN_VALUE === $password ) {
				$this->admin_notice->add_transient( __( 'An error occurred while trying to send the email.', 'development-assistant' ), 'error' );
				wp_safe_redirect( $redirect_to );

				exit;
			}

			$user_id      = intval( get_option( static::ID_KEY, static::ID_DEFAULT ) );
			$support_user = get_userdata( $user_id );

			if ( 0 === $user_id || ! $support_user ) {
				$this->admin_notice->add_transient( __( 'Support user does not exist. Recreate the user before sharing credentials.', 'development-assistant' ), 'error' );
				wp_safe_redirect( $redirect_to );

				exit;
			}

			$update_error = $this->update_user_email( $user_id, $email );

			if ( $update_error instanceof WP_Error ) {
				$this->admin_notice->add_transient(
					sprintf(
						__( 'Credentials were not sent because the support user account email could not be updated: %s', 'development-assistant' ),
						$update_error->get_error_message()
					),
					'error'
				);
				wp_safe_redirect( $redirect_to );

				exit;
			}

			$subject = sprintf(
				__( 'Support access to %s', 'development-assistant' ),
				str_replace( array( 'http://', 'https://' ), '', home_url() )
			);
			$message = sanitize_text_field( wp_unslash( $post_data[ static::SHARE_MESSAGE_QUERY_KEY ] ) );
			$content = $this->get_share_to_email_content( $password, $message );
			$user    = wp_get_current_user();
			$from    = $user->user_email ?
				'From: ' . $user->display_name . ' <' . $user->user_email . '>' :
				'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>';
			$headers = array( 'Content-Type: text/html; charset=UTF-8', $from );

			if ( ! wp_mail( $email, $subject, $content, $headers ) ) {
				$rollback_error = $this->update_user_email( $user_id, $support_user->user_email );

				if ( $rollback_error instanceof WP_Error ) {
					$this->admin_notice->add_transient(
						sprintf(
							__( 'Credentials could not be sent, and the support user account email could not be restored: %s', 'development-assistant' ),
							$rollback_error->get_error_message()
						),
						'error'
					);
				} else {
					$this->admin_notice->add_transient( __( 'An error occurred while trying to send the email.', 'development-assistant' ), 'error' );
				}

				wp_safe_redirect( $redirect_to );

				exit;
			}

			update_option( static::EMAIL_KEY, $email );
			$this->admin_notice->add_transient(
				sprintf(
					__( 'Credentials successfully shared with %s.', 'development-assistant' ),
					$email
				),
				'success'
			);
			wp_safe_redirect( $redirect_to );

			exit;
		};
	}

	/**
	 * @return WP_Error|null
	 */
	protected function update_user_email( int $user_id, string $email ): ?WP_Error {
		$result = wp_update_user(
			array(
				'ID'         => $user_id,
				'user_email' => $email,
			)
		);

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		return null;
	}

	protected function get_share_to_email_content( string $password, string $message ): string {
		$login     = get_option( static::LOGIN_KEY, static::LOGIN_DEFAULT );
		$home_url  = home_url();
		$login_url = wp_login_url();

		ob_start();

		echo sprintf( // phpcs:ignore
			esc_html__( 'You have been requested for support and granted administrative access to %s.', 'development-assistant' ),
			'<a href="' . esc_url( $home_url ) . '">' . esc_html( $home_url ) . '</a>'
		);

		if ( 0 < intval( get_option( static::DELETE_AFTER_DAYS_KEY, static::DELETE_AFTER_DAYS_DEFAULT ) ) ) {
			?>
			<br><br>
			<b><?php echo esc_html__( 'Note!', 'development-assistant' ); ?></b> <?php echo sprintf( esc_html__( 'User will be auto-deleted after less than %s days.', 'development-assistant' ), esc_html( $this->get_days_for_auto_delete() ) ); // phpcs:ignore ?>
		<?php } ?>
		<br><br>
		<a href="<?php echo esc_url( $login_url ); ?>"><?php echo esc_html( $login_url ); ?></a>
		<br>
		<b><?php echo esc_html__( 'Username', 'development-assistant' ); ?>:</b> <?php echo esc_html( $login ); ?>
		<br>
		<b><?php echo esc_html__( 'Password', 'development-assistant' ); ?>:</b> <?php echo esc_html( $password ); ?>
		<?php if ( $message ) { ?>
			<br><br>
			<b><?php echo esc_html__( 'Message from customer', 'development-assistant' ); ?>:</b>
			<br>
			<?php
			echo esc_html( $message );
		}
		return ob_get_clean();
	}

	/**
	 * @return WP_Error|null
	 */
	protected function create_user(): ?WP_Error {
		$user_data = $this->insert_user();

		if ( $user_data instanceof WP_Error ) {
			return $user_data;
		}

		$this->store_user_data( $user_data );

		return null;
	}

	/**
	 * @return array<string, int|string>|WP_Error
	 */
	protected function insert_user() {
		$time     = time();
		$login    = $this->get_available_login( 'support_' . $time );
		$password = wp_generate_password();

		$user_id = wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => $password,
				'role'       => 'administrator',
			)
		);

		if ( $user_id instanceof WP_Error ) {
			return $user_id;
		}

		return array(
			'id'         => $user_id,
			'login'      => $login,
			'password'   => $password,
			'created_at' => $time,
		);
	}

	protected function get_available_login( string $base_login ): string {
		$login  = $base_login;
		$suffix = 2;

		while ( username_exists( $login ) ) {
			$login = $base_login . '_' . $suffix;
			++$suffix;
		}

		return $login;
	}

	/**
	 * @param array<string, int|string> $user_data Support-user account data.
	 */
	protected function store_user_data( array $user_data ): void {
		update_option( static::ID_KEY, $user_data['id'] );
		update_option( static::LOGIN_KEY, $user_data['login'] );
		update_option( static::PASSWORD_KEY, $user_data['password'] );
		update_option( static::CREATED_AT_KEY, $user_data['created_at'] );
		delete_option( static::EMAIL_KEY );
	}

	protected function handle_update_create_at(): callable {
		return function (): void {
			update_option( static::CREATED_AT_KEY, time() );
			$this->admin_notice->add_transient( __( 'Support user existence extended.', 'development-assistant' ), 'success' );
		};
	}

	/**
	 * @return WP_Error|null
	 */
	protected function delete_user(): ?WP_Error {
		$user_id = intval( get_option( static::ID_KEY, static::ID_DEFAULT ) );

		if ( 0 >= $user_id || ! get_userdata( $user_id ) ) {
			$this->delete_user_data();

			return new WP_Error(
				'support_user_missing',
				__( 'Support user does not exist. Stale stored credentials were removed.', 'development-assistant' )
			);
		}

		if ( ! wp_delete_user( $user_id ) ) {
			return new WP_Error(
				'support_user_delete_failed',
				__( 'Support user could not be deleted. The account and stored credentials were preserved; verify the account and try again.', 'development-assistant' )
			);
		}

		$this->delete_user_data();

		return null;
	}

	protected function delete_user_if_disabled(): callable {
		return function ( string $old_value, string $value ): void {
			if ( 'yes' === $value ) {
				return;
			}

			$this->delete_user();
		};
	}

	protected function delete_user_after_days(): callable {
		return function (): void {
			$created_at   = intval( get_option( static::CREATED_AT_KEY, static::CREATED_AT_DEFAULT ) );
			$delete_after = intval( get_option( static::DELETE_AFTER_DAYS_KEY, static::DELETE_AFTER_DAYS_DEFAULT ) );

			if ( 0 === $created_at || 0 === $delete_after ) {
				return;
			}

			$delete_time = $created_at + $delete_after * DAY_IN_SECONDS;

			if ( $delete_time < time() ) {
				$this->delete_user();
			}
		};
	}

	protected function delete_data_when_user_deleted(): callable {
		return function ( int $user_id ): void {
			if ( intval( get_option( static::ID_KEY, static::ID_DEFAULT ) ) !== $user_id ) {
				return;
			}

			$this->delete_user_data();
		};
	}

	protected function delete_user_data(): void {
		delete_option( static::ID_KEY );
		delete_option( static::LOGIN_KEY );
		delete_option( static::PASSWORD_KEY );
		delete_option( static::CREATED_AT_KEY );
		delete_option( static::EMAIL_KEY );
	}

	public function is_current_user(): bool {
		return get_current_user_id() === intval( get_option( static::ID_KEY, static::ID_DEFAULT ) );
	}

	/**
	 * @throws Exception
	 */
	protected function enqueue_assets(): callable {
		return function (): void {
			if ( ! $this->is_current() ) {
				return;
			}

			parent::enqueue_assets()();
			$this->asset
				->enqueue_style( 'support-user' )
				->enqueue_script(
					'support-user',
					array( 'jquery' ),
					array(
						'page_url'         => $this->get_page_url(),
						'share_nonce'      => wp_create_nonce( static::SHARE_EMAIL_QUERY_KEY ),
						'share_query_keys' => array(
							'email'    => static::SHARE_EMAIL_QUERY_KEY,
							'password' => static::SHARE_PASSWORD_QUERY_KEY,
							'message'  => static::SHARE_MESSAGE_QUERY_KEY,
						),
					)
				);
		};
	}

	public function add_default_options(): void {
		if ( ! apply_filters( static::ENABLE_HOOK, true ) ) {
			return;
		}

		if ( ! in_array( get_option( static::ENABLE_KEY ), array( 'yes', 'no' ), true ) ) {
			update_option( static::ENABLE_KEY, static::ENABLE_DEFAULT );
		}
	}

	public function reset(): void {
		$this->delete_user();
		parent::reset();
	}
}
