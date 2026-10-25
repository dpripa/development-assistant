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
