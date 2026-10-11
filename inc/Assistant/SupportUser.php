<?php
namespace WPDevAssist\Assistant;

use WPDevAssist\ActionQuery;
use WPDevAssist\Setting;

defined( 'ABSPATH' ) || exit;

class SupportUser extends Section {
	protected ActionQuery $action_query;
	protected Setting\SupportUser $support_user;
	protected bool $is_created;
	protected bool $is_current_user_support;
	protected string $action_base_url;

	public function __construct( ActionQuery $action_query, Setting\SupportUser $support_user, string $action_base_url ) {
		$this->action_query            = $action_query;
		$this->support_user            = $support_user;
		$this->is_created              = 0 < get_option( Setting\SupportUser::ID_KEY, Setting\SupportUser::ID_DEFAULT );
		$this->is_current_user_support = $support_user->is_current_user();
		$this->action_base_url         = $action_base_url;

		parent::__construct();
	}

	protected function set_title(): void {
		$this->title = __( 'Support User', 'development-assistant' );
	}

	protected function set_content(): void {
		if ( $this->is_created ) {
			if ( $this->is_current_user_support ) {
				$this->content .= __( 'You are logged in as a support user.', 'development-assistant' );
			} else {
				$this->content .= __( 'The support user exists.', 'development-assistant' );
			}

			$this->content .= $this->support_user->get_details(
				'da-assistant__simple-list',
				! $this->is_current_user_support
			);
		} else {
			$this->content .= __( 'Support user not created. You don\'t share administrative access with third-parties.', 'development-assistant' );
		}
	}

	protected function set_controls(): void {
		if ( $this->is_created ) {
			if ( $this->support_user->is_allowed_continue_existence() ) {
				$this->controls[] = new Control(
					__( 'Continue existence', 'development-assistant' ),
					$this->action_query->get_url( Setting\SupportUser::UPDATE_CREATE_AT_QUERY_KEY, $this->action_base_url ),
				);
			}

			if ( ! $this->is_current_user_support ) {
				$this->controls[] = new Control(
					__( 'Recreate user', 'development-assistant' ),
					$this->action_query->get_url( Setting\SupportUser::RECREATE_QUERY_KEY, $this->support_user->get_page_url() ),
					$this->support_user->get_recreation_confirmation_massage()
				);
				$this->controls[] = new Control(
					__( 'Delete user', 'development-assistant' ),
					$this->action_query->get_url( Setting\SupportUser::DELETE_QUERY_KEY, $this->action_base_url ),
					$this->support_user->get_deletion_confirmation_massage()
				);
			}
		} else {
			$this->controls[] = new Control(
				__( 'Create user in one click', 'development-assistant' ),
				$this->action_query->get_url( Setting\SupportUser::CREATE_QUERY_KEY, $this->support_user->get_page_url() )
			);
		}
	}

	public function configure_status(): bool {
		if ( $this->is_created ) {
			if ( $this->support_user->is_allowed_continue_existence() ) {
				if ( 1 < intval( get_option( Setting\SupportUser::DELETE_AFTER_DAYS_KEY, Setting\SupportUser::DELETE_AFTER_DAYS_DEFAULT ) ) ) {
					$this->status_level = 'error';
				} else {
					$this->status_level = 'warning';
				}

				$this->status_description = __( 'Will lose access soon', 'development-assistant' );
			} else {
				$this->status_level       = 'warning';
				$this->status_description = __( 'Enabled', 'development-assistant' );
			}
		} else {
			$this->status_level       = 'success';
			$this->status_description = __( 'Disabled', 'development-assistant' );
		}

		return true;
	}
}
