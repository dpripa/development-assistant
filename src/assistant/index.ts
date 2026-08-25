import './styles.css';

const menuId = 'wp-admin-bar-wp_dev_assist_assistant';
const triggerSelector = `#${ menuId } > .ab-item`;

function getMenu(): HTMLElement | null {
	return document.getElementById( menuId );
}

function getTrigger( target: EventTarget | null ): HTMLElement | null {
	return target instanceof Element
		? target.closest< HTMLElement >( triggerSelector )
		: null;
}

function setExpanded( menu: HTMLElement, trigger: HTMLElement, expanded: boolean ): void {
	menu.classList.toggle( 'hover', expanded );
	trigger.setAttribute( 'aria-expanded', String( expanded ) );
}

document.addEventListener( 'click', ( event ) => {
	const menu = getMenu();
	const trigger = getTrigger( event.target );

	if ( ! menu ) {
		return;
	}

	if ( trigger ) {
		event.preventDefault();
		event.stopImmediatePropagation();
		setExpanded( menu, trigger, ! menu.classList.contains( 'hover' ) );
	} else if ( event.target instanceof Node && ! menu.contains( event.target ) ) {
		const menuTrigger = menu.querySelector< HTMLElement >( ':scope > .ab-item' );

		if ( menuTrigger ) {
			setExpanded( menu, menuTrigger, false );
		}
	}
}, true );

document.addEventListener( 'keydown', ( event ) => {
	const menu = getMenu();
	const trigger = getTrigger( event.target );

	if ( ! menu || ! trigger ) {
		return;
	}

	if ( event.key === 'Enter' || event.key === ' ' ) {
		event.preventDefault();
		event.stopImmediatePropagation();
		setExpanded( menu, trigger, ! menu.classList.contains( 'hover' ) );
	} else if ( event.key === 'Escape' ) {
		event.preventDefault();
		event.stopImmediatePropagation();
		setExpanded( menu, trigger, false );
	}
}, true );
