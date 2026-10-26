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

// Mail health has one autosaved switch; all checks and status updates are automatic.
document.querySelectorAll<HTMLFormElement>( '.da-mail-health-toggle' ).forEach( ( form ) => {
	const checkbox = form.querySelector<HTMLInputElement>( 'input[name="enabled"]' );
	const status = document.getElementById( 'da-mail-health-status' );
	const saveError = form.querySelector<HTMLElement>( '[data-save-error]' );
	const statusError = form.querySelector<HTMLElement>( '[data-status-error]' );
	if ( ! checkbox || ! status ) {
		return;
	}

	let saving = false;
	let refreshing = false;
	let changeVersion = 0;
	let saved = checkbox.checked;

	const update = ( data: unknown ) => {
		if ( typeof data !== 'object' || data === null || ! ( 'enabled' in data ) || ! ( 'html' in data ) || typeof data.enabled !== 'boolean' || typeof data.html !== 'string' ) {
			throw new Error( 'Invalid mail health response' );
		}
		saved = data.enabled;
		checkbox.checked = saved;
		// This markup comes from the authenticated, same-origin status endpoint.
		if ( status.innerHTML !== data.html ) {
			status.innerHTML = data.html;
		}
		if ( statusError ) {
			statusError.hidden = true;
		}
	};

	const request = async ( url: string, body?: FormData ) => {
		const controller = new AbortController();
		const timeout = window.setTimeout( () => controller.abort(), 15000 );
		try {
			const response = await fetch( url, {
				method: body ? 'POST' : 'GET',
				body,
				credentials: 'same-origin',
				cache: 'no-store',
				signal: controller.signal,
			} );
			const result: unknown = await response.json();
			if ( ! response.ok || typeof result !== 'object' || result === null || ! ( 'success' in result ) || result.success !== true || ! ( 'data' in result ) ) {
				throw new Error( 'Mail health request failed' );
			}
			return result.data;
		} finally {
			window.clearTimeout( timeout );
		}
	};

	const refresh = async () => {
		if ( saving || refreshing || document.hidden ) {
			return;
		}
		refreshing = true;
		const version = changeVersion;
		try {
			const data = await request( form.dataset.statusUrl! );
			// A toggle may have started while this older status request was in flight.
			if ( ! saving && version === changeVersion ) {
				update( data );
			}
		} catch {
			if ( statusError ) {
				statusError.hidden = false;
			}
		} finally {
			refreshing = false;
		}
	};

	const save = async () => {
		if ( saving ) {
			return;
		}
		++changeVersion;
		const body = new FormData( form );
		body.set( 'action', form.dataset.toggleAction! );
		body.set( 'enabled', checkbox.checked ? 'yes' : 'no' );
		saving = true;
		checkbox.disabled = true;
		form.setAttribute( 'aria-busy', 'true' );
		if ( saveError ) {
			saveError.hidden = true;
		}
		try {
			update( await request( form.dataset.saveUrl!, body ) );
		} catch {
			checkbox.checked = saved;
			if ( saveError ) {
				saveError.hidden = false;
			}
		} finally {
			saving = false;
			checkbox.disabled = false;
			form.removeAttribute( 'aria-busy' );
		}
		// Reconcile an ambiguous save response, and advance the initial background check.
		void refresh();
	};
	checkbox.addEventListener( 'change', () => void save() );

	window.setInterval( () => void refresh(), 3000 );
	window.addEventListener( 'pageshow', () => void refresh() );
	document.addEventListener( 'visibilitychange', () => void refresh() );
} );
