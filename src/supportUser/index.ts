import './styles.css';

const {
	page_url: pageUrl,
	share_nonce: shareNonce,
	share_query_keys: shareQueryKeys,
} = window.wp_dev_assist_support_user;

$( document ).on( 'ready', () => {
	initCopy();
	initShare();
} );

function initCopy(): void {
	let copiedTextTimeout: ReturnType<typeof setTimeout> | undefined;

	$( '#da-copy-support-user-credentials' ).on( 'click', function copyCredentials() {
		const $button = $( this );
		const credentialsText = $( '#da-support-user-credentials li' )
			.map( function getCredentialText() {
				return $( this ).text().trim();
			} )
			.get()
			.join( '\n' );
		const $temporaryInput = $( '<textarea>' );

		$temporaryInput
			.appendTo( 'body' )
			.addClass( 'da-support-user__hidden-element' )
			.val( credentialsText )
			.trigger( 'select' );
		document.execCommand( 'copy' );
		$temporaryInput.remove();
		$button.addClass( 'da-support-user__copy_copied' );

		if ( copiedTextTimeout !== undefined ) {
			clearTimeout( copiedTextTimeout );
		}

		copiedTextTimeout = setTimeout( () => {
			$button.removeClass( 'da-support-user__copy_copied' );
		}, 2500 );
	} );
}

function initShare(): void {
	$( '#da-share-support-user' ).on( 'click', () => {
		const $email = $( '#da-share-support-user-email' );
		const email = String( $email.val() ?? '' );
		const emailInput = $email.get( 0 );
		const password = $( '#da-support-user-password' ).text().trim();

		if (
			email === '' ||
			! ( emailInput instanceof HTMLInputElement ) ||
			! emailInput.reportValidity() ||
			password === ''
		) {
			return;
		}

		const message = String( $( '#da-share-support-user-message' ).val() ?? '' );
		const $temporaryForm = $( '<form>' );
		const $temporaryPasswordInput = $( '<input>' );
		const $temporaryMessageInput = $( '<textarea>' );
		const actionUrl = `${ pageUrl }&${ shareQueryKeys.email }=${ email }&_wpnonce=${ shareNonce }`;

		$temporaryForm
			.appendTo( 'body' )
			.addClass( 'da-support-user__hidden-element' )
			.attr( 'method', 'post' )
			.attr( 'action', actionUrl )
			.append( $temporaryPasswordInput )
			.append( $temporaryMessageInput );

		$temporaryPasswordInput.attr( 'name', shareQueryKeys.password ).val( password );
		$temporaryMessageInput.attr( 'name', shareQueryKeys.message ).val( message );
		$temporaryForm.trigger( 'submit' );
	} );
}
