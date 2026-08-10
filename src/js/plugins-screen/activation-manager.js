const hasDeactivatedPlugins = window.wp_dev_assist_plugins_screen.has_deactivated_plugins;
const activationUrl = window.wp_dev_assist_plugins_screen.plugin_activation_url;
const activationTitle = window.wp_dev_assist_plugins_screen.plugin_activation_title;
const reset = window.wp_dev_assist_plugins_screen.reset;
const resetQueryKey = window.wp_dev_assist_plugins_screen.deactivation_reset_query_key;
const deactivationConfirmMessage = window.wp_dev_assist_plugins_screen.deactivation_confirm_message;

export default function initActivationManager() {
	if ( 'yes' === hasDeactivatedPlugins ) {
		const $btnActivate = $( '<a/>' );

		$btnActivate
			.addClass( 'button' )
			.attr( 'href', activationUrl )
			.text( activationTitle );
		$( '.bulkactions' ).after( $btnActivate );
	}

	$( '#deactivate-development-assistant' ).on( 'click', function( event ) {
		event.preventDefault();

		if ( 'yes' === reset ) {
			window.location.href = activationUrl + '&' +
				resetQueryKey + '=yes';

			return;
		}

		if ( confirm( deactivationConfirmMessage ) ) {
			window.location.href = $( this ).attr( 'href' );
		}
	});
}
