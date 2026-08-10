<?php
namespace WPDevAssist;

use PHPMailer;
use WPDevAssist\ActionQuery;
use WPDevAssist\AdminNotice;

defined( 'ABSPATH' ) || exit;

class MailHog {
	public const SEND_TEST_EMAIL_QUERY_KEY = KEY . '_mail_hog_send_test_email';

	protected AdminNotice $admin_notice;
	protected ?bool $is_http_host_exists = null;

	public function __construct( ActionQuery $action_query, AdminNotice $admin_notice ) {
		$this->admin_notice = $admin_notice;

		if ( ! $this->is_enabled() || ! $this->is_http_host_exists() ) {
			return;
		}

		add_action( 'phpmailer_init', $this->change_phpmailer_props() );
		$action_query->add( static::SEND_TEST_EMAIL_QUERY_KEY, $this->handle_send_test_email() );
	}


	protected function change_phpmailer_props(): callable {
		/**
		 * @param $phpmailer PHPMailer
		 */
		return function ( $phpmailer ): void {
			$host                = str_replace( array( 'smtp://', 'http://', 'https://' ), '', $this->get_smtp_host() );
			$host_parts          = explode( ':', $host );
			$phpmailer->Host     = $host_parts[0]; // phpcs:ignore
			$phpmailer->Port     = intval( $host_parts[1] ?? 0 ); // phpcs:ignore
			$phpmailer->SMTPAuth = false; // phpcs:ignore

			$phpmailer->isSMTP();
		};
	}

	public function is_enabled(): bool {
		return 'yes' === get_option( Setting\DevEnv::ENABLE_KEY, Setting\DevEnv::ENABLE_DEFAULT ) &&
			'yes' === get_option( Setting\DevEnv::REDIRECT_TO_MAIL_HOG_KEY, Setting\DevEnv::REDIRECT_TO_MAIL_HOG_DEFAULT );
	}

	public function get_smtp_host(): string {
		$host = get_option( Setting\DevEnv::MAIL_HOG_SMTP_HOST_KEY, Setting\DevEnv::MAIL_HOG_SMTP_HOST_DEFAULT );

		if ( str_contains( $host, 'smtp://' ) || str_contains( $host, 'http://' ) || str_contains( $host, 'https://' ) ) {
			return $host;
		}

		return "smtp://$host";
	}

	public function get_http_host(): string {
		$host = get_option( Setting\DevEnv::MAIL_HOG_HTTP_HOST_KEY, Setting\DevEnv::MAIL_HOG_HTTP_HOST_DEFAULT );

		if ( str_contains( $host, 'http://' ) || str_contains( $host, 'https://' ) ) {
			return $host;
		}

		return "http://$host";
	}

	public function is_http_host_exists(): bool {
		if ( ! $this->is_enabled() ) {
			return false;
		}

		if ( is_bool( $this->is_http_host_exists ) ) {
			return $this->is_http_host_exists;
		}

		$request                   = wp_remote_request( $this->get_http_host() . '/api/v2/outgoing-smtp' );
		$this->is_http_host_exists = ! is_wp_error( $request ) &&
			isset( $request['response']['code'] ) && 200 === $request['response']['code'];

		return $this->is_http_host_exists;
	}

	protected function handle_send_test_email(): callable {
		return function (): void {
			$subject            = sprintf(
				__( 'Testing MailHog for %s', 'development-assistant' ),
				str_replace( array( 'http://', 'https://' ), '', home_url() )
			);
			$content            = esc_html__( 'This is a blank email to test MailHog\'s functionality.', 'development-assistant' );
			$user               = wp_get_current_user();
			$current_user_email = $user->user_email;
			$from               = $current_user_email ?
				'From: ' . $user->display_name . ' <' . $user->user_email . '>' :
				'From: ' . get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>';
			$headers            = array( 'Content-Type: text/html; charset=UTF-8', $from );

			if ( ! wp_mail( $current_user_email, $subject, $content, $headers ) ) {
				$this->admin_notice->add_transient(
					__( 'An error occurred while trying to send the test email.', 'development-assistant' ),
					'error'
				);
			}

			$this->admin_notice->add_transient(
				__( 'Test email sent successfully.', 'development-assistant' ),
				'success'
			);
		};
	}
}
