interface Window {
	wp_dev_assist_plugins_screen: {
		deactivation_confirm_message: string;
		deactivation_reset_query_key: string;
		has_deactivated_plugins: 'yes' | 'no';
		plugin_activation_title: string;
		plugin_activation_url: string;
		reset: 'yes' | 'no';
	};
	wp_dev_assist_support_user: {
		page_url: string;
		share_nonce: string;
		share_query_keys: {
			email: string;
			message: string;
			password: string;
		};
	};
}
