import './styles.css';

jQuery( ( $ ) => {
	const $assistant = $( '.da-assistant' );

	if ( $( '#screen-meta-links' ).length ) {
		$assistant.addClass( 'da-assistant_after-screen-meta-links' );
	}

	$( '.da-assistant__header' ).on( 'click', () => {
		$assistant.toggleClass( 'da-assistant_open' );
	} );
} );
