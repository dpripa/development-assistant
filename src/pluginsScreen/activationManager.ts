const {
	deactivation_confirm_message: deactivationConfirmMessage,
	deactivation_reset_query_key: resetQueryKey,
	has_deactivated_plugins: hasDeactivatedPlugins,
	plugin_activation_title: activationTitle,
	plugin_activation_url: activationUrl,
	reset,
} = window.wp_dev_assist_plugins_screen;

export default function initActivationManager( $: JQueryStatic ): void {
	if ( hasDeactivatedPlugins === 'yes' ) {
		const $buttonActivate = $( '<a/>' );

		$buttonActivate
			.addClass( 'button' )
			.attr( 'href', activationUrl )
			.text( activationTitle );
		$( '.bulkactions' ).after( $buttonActivate );
	}

	$( '#deactivate-development-assistant' ).on( 'click', function deactivate( event ) {
		event.preventDefault();

		if ( reset === 'yes' ) {
			window.location.href = `${ activationUrl }&${ resetQueryKey }=yes`;

			return;
		}

		if ( confirm( deactivationConfirmMessage ) ) {
			window.location.href = $( this ).attr( 'href' ) ?? '';
		}
	} );
}
