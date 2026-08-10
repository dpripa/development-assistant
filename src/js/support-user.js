const pageUrl = window.wp_dev_assist_support_user.page_url;
const shareNonce = window.wp_dev_assist_support_user.share_nonce;
const shareQueryKeys = window.wp_dev_assist_support_user.share_query_keys;

$( document ).on( 'ready', function() {
	initCopy();
	initShare();
});

function initCopy() {
	let copiedTextTimeout;

	$( '#da-copy-support-user-credentials' ).on( 'click', function() {
		const $this = $( this );
		const credentialsText = $( '#da-support-user-credentials li' )
			.map( function() {
				return $( this ).text().trimStart().trimEnd();
			})
			.get()
			.join( '\n' );
		const $tempInput = $( '<textarea>' );

		$tempInput
			.appendTo( 'body' )
			.addClass( 'da-support-user__hidden-element' )
			.val( credentialsText )
			.select();
		document.execCommand( 'copy' );
		$tempInput.remove();
		$this.addClass( 'da-support-user__copy_copied' );
		clearTimeout( copiedTextTimeout );

		copiedTextTimeout = setTimeout( function() {
			$this.removeClass( 'da-support-user__copy_copied' );
		}, 2500 );
	});
}

function initShare() {
	$( '#da-share-support-user' ).on( 'click', function() {
		const $email = $( '#da-share-support-user-email' );
		const email = $email.val();
		const password = $( '#da-support-user-password' ).text().trim();

		if ( '' === email || ! $email[0].reportValidity() || '' === password ) {
			return;
		}

		const message = $( '#da-share-support-user-message' ).val();
		const $tempForm = $( '<form>' );
		const $tempPasswordInput = $( '<input>' );
		const $tempMessageInput = $( '<textarea>' );
		const actionUrl = pageUrl + '&' +
			shareQueryKeys.email + '=' + email + '&' +
			'_wpnonce=' + shareNonce;

		$tempForm
			.appendTo( 'body' )
			.addClass( 'da-support-user__hidden-element' )
			.attr( 'method', 'post' )
			.attr( 'action', actionUrl )
			.append( $tempPasswordInput )
			.append( $tempMessageInput );

		$tempPasswordInput.attr( 'name', shareQueryKeys.password ).val( password );
		$tempMessageInput.attr( 'name', shareQueryKeys.message ).val( message );

		$tempForm.submit();
	});
}
