import './styles.css';

document.querySelectorAll<HTMLFormElement>( '.da-setting-form' ).forEach( ( form ) => {
	const buttons = form.querySelectorAll<HTMLButtonElement | HTMLInputElement>(
		'button[type="submit"], button:not([type]), input[type="submit"]',
	);
	const error = form.querySelector<HTMLElement>( '.da-setting-form__error' );
	const managedButtons = Array.from( buttons ).filter(
		( button ) => ! button.disabled || button.hasAttribute( 'data-da-setting-submit' ),
	);
	const getValues = () => JSON.stringify( Array.from( new FormData( form ).entries() ) );
	const initialValues = getValues();
	const hasChanges = () => getValues() !== initialValues;
	let submitting = false;

	const updateButtons = () => {
		const disabled = submitting || ! hasChanges();
		managedButtons.forEach( ( button ) => {
			button.disabled = disabled;
		} );
	};

	const reset = () => {
		submitting = false;
		form.removeAttribute( 'aria-busy' );
		updateButtons();
	};

	form.addEventListener( 'input', updateButtons );
	form.addEventListener( 'change', updateButtons );
	form.addEventListener( 'reset', () => queueMicrotask( updateButtons ) );
	updateButtons();

	form.addEventListener( 'submit', ( event ) => {
		if ( submitting || ! hasChanges() ) {
			event.preventDefault();
			return;
		}

		if ( event.defaultPrevented ) {
			return;
		}

		if ( ! navigator.onLine ) {
			event.preventDefault();
			if ( error ) {
				error.hidden = false;
			}
			return;
		}

		if ( error ) {
			error.hidden = true;
		}
		submitting = true;
		form.setAttribute( 'aria-busy', 'true' );
		updateButtons();

		// Another submit listener may cancel the navigation after this listener.
		queueMicrotask( () => {
			if ( event.defaultPrevented ) {
				reset();
			}
		} );
	} );

	// Re-evaluate restored form values when navigating Back, including cached pages.
	window.addEventListener( 'pageshow', reset );
} );

// Read-only live status is independent of the shared settings form and unsaved inputs.
document.querySelectorAll<HTMLElement>( '[data-mail-health-status]' ).forEach( ( control ) => {
	const status = control.querySelector<HTMLElement>( '#da-mail-health-status' );
	const statusError = control.querySelector<HTMLElement>( '[data-status-error]' );
	if ( ! status || ! control.dataset.statusUrl ) {
		return;
	}

	let refreshing = false;
	const refresh = async () => {
		if ( refreshing || document.hidden ) {
			return;
		}
		refreshing = true;
		const controller = new AbortController();
		const timeout = window.setTimeout( () => controller.abort(), 15000 );
		try {
			const response = await fetch( control.dataset.statusUrl!, {
				credentials: 'same-origin',
				cache: 'no-store',
				signal: controller.signal,
			} );
			const result: unknown = await response.json();
			if ( ! response.ok || typeof result !== 'object' || result === null || ! ( 'success' in result ) || result.success !== true || ! ( 'data' in result ) || typeof result.data !== 'object' || result.data === null || ! ( 'html' in result.data ) || typeof result.data.html !== 'string' ) {
				throw new Error( 'Invalid Mail Health response' );
			}
			// Escaped markup from the authenticated, same-origin status endpoint.
			if ( status.innerHTML !== result.data.html ) {
				status.innerHTML = result.data.html;
			}
			if ( statusError ) {
				statusError.hidden = true;
			}
		} catch {
			if ( statusError ) {
				statusError.hidden = false;
			}
		} finally {
			window.clearTimeout( timeout );
			refreshing = false;
		}
	};

	window.setInterval( () => void refresh(), 3000 );
	window.addEventListener( 'pageshow', () => void refresh() );
	document.addEventListener( 'visibilitychange', () => void refresh() );
} );
