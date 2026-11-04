/**
 * Mix and Match child item quick view.
 *
 * Clicking a child item's quick view button or title opens its product details in a native <dialog>,
 * with buttons to add it to the container. The details are loaded from the server, all of a
 * container's at once when the page is idle, or when the pointer rests on, or keyboard focus reaches,
 * a child item's quick view button or title.
 *
 * Built on the Mix and Match container store (@wc-mix-and-match/container): the modal reads the child
 * item's state from it and changes quantities with its actions, so it doesn't depend on how the form
 * itself is rendered.
 *
 * The modal markup is printed once in the footer, see Plugin::print_modal(). Clicks and hovers are
 * listened to on the document, so the child items of forms loaded with ajax open it too.
 *
 * @since   3.0.0
 * @version 3.0.0
 */
import * as interactivity from '@wordpress/interactivity';
import '@wc-mix-and-match/container';

const { store, getElement } = interactivity;

// WordPress 6.8+ runs event handlers asynchronously, unless they need the event right away.
const withSyncEvent = interactivity.withSyncEvent || ( ( callback ) => callback );

const NAMESPACE = 'wc-mnm-quickview';

/**
 * The elements of a child item that open its quick view.
 */
const TRIGGERS = '[data-wc-mnm-quick-view], .woocommerce-loop-product__title a';

/**
 * How long the pointer rests on a child item before its details are prefetched, in ms.
 */
const HOVER_DELAY = 80;

/**
 * Item types whose quantity can't be changed from the modal.
 */
const FIXED_TYPES = [ 'unavailable', 'hidden' ];

const { state: mnm, actions: mnmActions } = store( 'wc-mix-and-match/container' );

/*-----------------------------------------------------------------*/
/*  Not reactive on purpose                                        */
/*-----------------------------------------------------------------*/

/**
 * Loaded and loading details, keyed by "containerId:productId". Each is a promise of the HTML.
 *
 * @type {Map<string, Promise<string>>}
 */
const cache = new Map();

/**
 * Requests for all of a container's details, keyed by container ID. Each is a promise of { productId: html }.
 *
 * @type {Map<number, Promise<Object>>}
 */
const batches = new Map();

/**
 * Containers whose details are already scheduled to preload.
 *
 * @type {Set<number>}
 */
const preloaded = new Set();

let dialogEl     = null;
let openerEl     = null; // The element that opened the modal, focus returns to it on close.
let siblings     = { prev: null, next: null }; // Item data, see getItemData().
let pendingFocus = ''; // add|prev|next, where focus goes once the modal re-renders.
let hoverTimer   = null;
let requestId    = 0;

/*-----------------------------------------------------------------*/
/*  Child items in the page                                        */
/*-----------------------------------------------------------------*/

/**
 * IDs can be read from data attributes as strings or numbers.
 *
 * @param {number|string} id
 * @return {number}
 */
const toId = ( id ) => parseInt( id, 10 ) || 0;

/**
 * Whether an element is shown, ex: not hidden by a filter.
 *
 * @param {Element} el
 * @return {boolean}
 */
const isVisible = ( el ) => ( el.checkVisibility ? el.checkVisibility() : el.getClientRects().length > 0 );

/**
 * Read a child item's IDs and quick view data from the page.
 *
 * The quick view button carries the data. Items without one, ex: when the image is turned off, fall
 * back to the child item's own data attributes.
 *
 * @param {Element} itemEl A child item, .mnm_item.
 * @return {Object|null} { containerId, productId, title, addToBox, removeFromBox, trigger, itemEl }
 */
const getItemData = ( itemEl ) => {
	const button  = itemEl.querySelector( '[data-wc-mnm-quick-view]' );
	const titleEl = itemEl.querySelector( '.woocommerce-loop-product__title' );
	let data      = {};

	if ( button ) {
		try {
			data = JSON.parse( button.getAttribute( 'data-wc-mnm-quick-view' ) ) || {};
		} catch ( e ) {
			data = {};
		}
	}

	if ( ! data.containerId ) {
		const form   = itemEl.closest( 'form' );
		const cartEl = form && form.querySelector( '[data-container_id]' );

		data.containerId = cartEl ? cartEl.getAttribute( 'data-container_id' ) : 0;
	}

	const containerId = toId( data.containerId );
	const productId   = toId( data.productId || itemEl.getAttribute( 'data-mnm_item_id' ) );

	if ( ! containerId || ! productId ) {
		return null;
	}

	return {
		...data,
		containerId,
		productId,
		title: data.title || ( titleEl ? titleEl.textContent.trim() : '' ),
		trigger: itemEl.querySelector( TRIGGERS ),
		itemEl,
	};
};

/**
 * Find the child item a quick view trigger belongs to, outside the modal.
 *
 * @param {EventTarget} target
 * @return {Object|null} Item data, see getItemData(), with the trigger that was used.
 */
const getEventItem = ( target ) => {
	const trigger = target instanceof Element ? target.closest( TRIGGERS ) : null;
	const itemEl  = trigger && ! ( dialogEl && dialogEl.contains( trigger ) ) ? trigger.closest( '.mnm_item' ) : null;
	const data    = itemEl ? getItemData( itemEl ) : null;

	return data ? { ...data, trigger } : null;
};

/**
 * Whether the container store knows a child item, so the modal can show its state.
 *
 * @param {Object} data Item data.
 * @return {boolean}
 */
const isKnown = ( data ) => {
	const container = mnm.containers[ data.containerId ];
	return !! ( container && container.items[ data.productId ] );
};

/**
 * Get the shown child items of a container, in the order they appear.
 *
 * @param {number} containerId
 * @return {Object[]} Item data.
 */
const getVisibleItems = ( containerId ) =>
	[ ...document.querySelectorAll( '.mnm_item' ) ]
		.filter( ( itemEl ) => ! ( dialogEl && dialogEl.contains( itemEl ) ) && isVisible( itemEl ) )
		.map( getItemData )
		.filter( ( data ) => data && data.containerId === containerId );

/**
 * Say that the quick view buttons and title links open a dialog.
 *
 * Added here because wp_kses_post() removes aria-haspopup from the button's markup.
 */
const prepareTriggers = () => {
	document.querySelectorAll( '.mnm_item' ).forEach( ( itemEl ) => {
		const data = getItemData( itemEl );

		if ( ! data ) {
			return;
		}

		itemEl.querySelectorAll( '[data-wc-mnm-quick-view]' ).forEach( ( button ) => button.setAttribute( 'aria-haspopup', 'dialog' ) );

		// The title stays a link, so it can still be opened in a new tab, but says what a click does.
		itemEl.querySelectorAll( '.woocommerce-loop-product__title a:not([aria-haspopup])' ).forEach( ( link ) => {
			link.setAttribute( 'aria-haspopup', 'dialog' );
			link.setAttribute( 'aria-label', ( state.i18n.openLabel || '%s' ).replace( '%s', data.title ) );
		} );
	} );
};

/*-----------------------------------------------------------------*/
/*  Loading                                                        */
/*-----------------------------------------------------------------*/

/**
 * Preload the main image in some details, so it is ready when the modal opens.
 *
 * @param {string} html The details' HTML.
 */
const preloadImage = ( html ) => {
	const template = document.createElement( 'template' );
	template.innerHTML = html;

	const img = template.content.querySelector( '.wc-mnm-quick-view__image img' );

	if ( img ) {
		const preload = new window.Image();

		if ( img.sizes ) {
			preload.sizes = img.sizes;
		}

		if ( img.srcset ) {
			preload.srcset = img.srcset;
		}

		preload.src = img.src;
	}
};

/**
 * Request details from the server.
 *
 * @param {Object} args Query args.
 * @return {Promise<Object>} The response data.
 */
const request = async ( args ) => {
	const url = new URL( state.ajaxUrl, window.location.href );

	Object.entries( args ).forEach( ( [ key, value ] ) => url.searchParams.set( key, value ) );

	const response = await window.fetch( url, { credentials: 'same-origin', headers: { Accept: 'application/json' } } );
	const json     = response.ok ? await response.json() : null;

	if ( ! json || ! json.success || ! json.data ) {
		throw new Error( 'wc-mnm-quickview: the details could not be loaded.' );
	}

	return json.data;
};

/**
 * Fetch all of a container's details in one request, once.
 *
 * @param {number} containerId
 * @return {Promise<Object>} The details' HTML, keyed by child product ID.
 */
const fetchAll = ( containerId ) => {
	if ( ! batches.has( containerId ) ) {
		const promise = request( { container_id: containerId } ).then( ( data ) => data.items || {} );

		// Let a later hover or click try again.
		promise.catch( () => batches.delete( containerId ) );

		batches.set( containerId, promise );
	}

	return batches.get( containerId );
};

/**
 * Fetch a child item's details, once.
 *
 * Comes from the request for all of the container's details, or one for just this item if that fails or
 * doesn't have it.
 *
 * @param {number} containerId
 * @param {number} productId
 * @return {Promise<string>} The details' HTML.
 */
const fetchDetails = ( containerId, productId ) => {
	const key = containerId + ':' + productId;

	if ( cache.has( key ) ) {
		return cache.get( key );
	}

	const single = () =>
		request( { container_id: containerId, child_id: productId } ).then( ( data ) => {
			if ( ! data.html ) {
				throw new Error( 'wc-mnm-quickview: no details for this item.' );
			}

			return data.html;
		} );

	const promise = fetchAll( containerId )
		.then( ( items ) => items[ productId ] || single(), single )
		.then( ( html ) => {
			preloadImage( html );
			return html;
		} );

	// Let a later hover or click try again.
	promise.catch( () => cache.delete( key ) );

	cache.set( key, promise );

	return promise;
};

/**
 * Load all of a container's details when the browser is idle.
 *
 * @param {number} containerId
 */
const preloadAll = ( containerId ) => {
	if ( ! state.preloadAll || preloaded.has( containerId ) ) {
		return;
	}

	preloaded.add( containerId );

	const run = () => fetchAll( containerId ).catch( () => {} );

	if ( window.requestIdleCallback ) {
		window.requestIdleCallback( run, { timeout: 3000 } );
	} else {
		window.setTimeout( run, 1000 );
	}
};

/**
 * Load the open child item's details into the modal.
 */
const load = () => {
	const id = ++requestId;

	state.isLoading = true;
	state.hasError  = false;
	state.html      = '';

	fetchDetails( state.containerId, state.productId )
		.then(
			( html ) => {
				if ( id === requestId ) {
					state.html = html;
				}
			},
			() => {
				if ( id === requestId ) {
					state.hasError = true;
				}
			}
		)
		.finally( () => {
			if ( id === requestId ) {
				state.isLoading = false;
			}
		} );
};

/*-----------------------------------------------------------------*/
/*  The modal                                                      */
/*-----------------------------------------------------------------*/

/**
 * Show a child item in the modal, opening it if needed.
 *
 * @param {Object}  data    Item data.
 * @param {Element} trigger The element that opened it, focus returns there on close.
 */
const openItem = ( data, trigger ) => {
	const items = getVisibleItems( data.containerId );
	const index = items.findIndex( ( item ) => item.productId === data.productId );

	siblings = {
		prev: index > 0 ? items[ index - 1 ] : null,
		next: -1 !== index && index < items.length - 1 ? items[ index + 1 ] : null,
	};

	openerEl = trigger;

	state.containerId = data.containerId;
	state.productId   = data.productId;
	state.labels      = {
		addToBox: data.addToBox || state.i18n.addToBox,
		removeFromBox: data.removeFromBox || state.i18n.removeFromBox,
	};
	state.hasPrev     = !! siblings.prev;
	state.hasNext     = !! siblings.next;
	state.prevTitle   = siblings.prev ? siblings.prev.title : '';
	state.nextTitle   = siblings.next ? siblings.next.title : '';
	state.messages    = [];
	state.isOpen      = true;

	// Opened here rather than from a watch on isOpen, because the close event that resets it can come late.
	if ( dialogEl && ! dialogEl.open ) {
		dialogEl.showModal();
		dialogEl.querySelector( '.wc-mnm-quick-view__close' ).focus();
	}

	load();
};

/**
 * Clean up and return focus once the modal closes. Runs again for the close event, so it checks first.
 */
const onClosed = () => {
	const opener = openerEl;

	if ( ! state.isOpen ) {
		return;
	}

	requestId++;
	openerEl       = null;
	pendingFocus   = '';
	state.isOpen   = false;
	state.messages = [];

	if ( opener && opener.isConnected ) {
		opener.focus();
	}
};

/**
 * Show the previous or next child item.
 *
 * @param {string} direction prev|next
 */
const navigate = ( direction ) => {
	const sibling = siblings[ direction ];

	if ( sibling && isKnown( sibling ) ) {
		pendingFocus = direction;
		openItem( sibling, sibling.trigger || openerEl );
	}
};

/**
 * Change the open child item's quantity with a container store action.
 *
 * @param {Function} action increaseItem or decreaseItem.
 */
const change = ( action ) => {
	const result = action( state.containerId, state.productId );

	// The container may have corrected the quantity, ex: when there isn't room.
	state.messages = result && result.messages ? result.messages : [];
};

/**
 * Whether a button can take focus.
 *
 * @param {HTMLButtonElement|null} button
 * @return {boolean}
 */
const canFocus = ( button ) => !! button && ! button.disabled && ! button.closest( '[hidden]' );

/**
 * Move focus where a control was used, or away from one that was disabled or hidden while it had focus.
 */
const placeFocus = () => {
	if ( ! dialogEl || ! dialogEl.open ) {
		return;
	}

	const find   = ( selector ) => dialogEl.querySelector( selector );
	const intent = pendingFocus;
	let target   = null;

	pendingFocus = '';

	if ( 'add' === intent ) {
		// The add button is replaced by the stepper or the remove button.
		target = [ '.wc-mnm-quick-view__increase', '.wc-mnm-quick-view__remove', '.wc-mnm-quick-view__decrease' ].map( find ).find( canFocus );
	} else if ( 'prev' === intent || 'next' === intent ) {
		// Keep focus on the button that was used, unless it is now disabled.
		target = [ intent, 'next' === intent ? 'prev' : 'next' ].map( ( name ) => find( '.wc-mnm-quick-view__' + name ) ).find( canFocus );
	}

	if ( ! target ) {
		const active = document.activeElement;

		if ( active && active !== document.body && dialogEl.contains( active ) && ( 'BUTTON' !== active.tagName || canFocus( active ) ) ) {
			return;
		}

		// Prefer the footer controls, then the close button.
		target = [ ...dialogEl.querySelectorAll( '.wc-mnm-quick-view__footer button' ) ].find( canFocus ) || find( '.wc-mnm-quick-view__close' );
	}

	if ( target ) {
		target.focus();
	}
};

/**
 * Escape text for HTML.
 *
 * @param {string} text
 * @return {string}
 */
const escapeHtml = ( text ) => {
	const el = document.createElement( 'div' );
	el.textContent = text || '';
	return el.innerHTML;
};

/**
 * Keep a reference to the dialog, from a callback on it or inside it. The callbacks can run in any order.
 *
 * Without <dialog> support the quick view stays off.
 *
 * @return {HTMLDialogElement|null}
 */
const setDialog = () => {
	if ( ! dialogEl && window.HTMLDialogElement ) {
		dialogEl = getElement().ref.closest( 'dialog' );
	}

	return dialogEl;
};

/*-----------------------------------------------------------------*/
/*  Store                                                          */
/*-----------------------------------------------------------------*/

const { state } = store( NAMESPACE, {
	state: {
		// From the server: ajaxUrl, preloadAll and i18n.

		isOpen: false,
		isLoading: false,
		hasError: false,
		containerId: 0,
		productId: 0,
		labels: { addToBox: '', removeFromBox: '' },
		html: '',
		messages: [], // HTML, from the last change of quantity.
		hasPrev: false,
		hasNext: false,
		prevTitle: '',
		nextTitle: '',

		/**
		 * The open child item's state in the container store.
		 *
		 * @return {Object|null}
		 */
		get item() {
			const container = mnm.containers[ state.containerId ];
			return ( container && container.items[ state.productId ] ) || null;
		},

		get type() {
			return state.item ? state.item.type : 'unavailable';
		},

		get isSelected() {
			return !! state.item && state.item.isSelected;
		},

		get isReady() {
			return state.isOpen && ! state.isLoading && ! state.hasError && !! state.item;
		},

		get hideError() {
			return ! state.hasError;
		},

		get hideFooter() {
			return ! state.isReady;
		},

		get hideInBox() {
			return ! state.isSelected;
		},

		get inBoxText() {
			const quantity = state.item ? state.item.quantity : 0;
			return 'hidden' === state.type ? state.i18n.included : ( state.i18n.inBox || '' ).replace( '%d', quantity );
		},

		get quantity() {
			return state.item ? String( state.item.quantity ) : '';
		},

		get hideStepper() {
			return ! state.isSelected || 'checkbox' === state.type || FIXED_TYPES.includes( state.type );
		},

		get cannotIncrease() {
			return ! state.item || ! state.item.canIncrease;
		},

		get cannotDecrease() {
			return ! state.item || ! state.item.canDecrease;
		},

		get hideAdd() {
			return state.isSelected || FIXED_TYPES.includes( state.type );
		},

		get hideRemove() {
			return ! state.isSelected || 'checkbox' !== state.type;
		},

		/**
		 * The messages under the item, as HTML. The container's are built by the container script.
		 *
		 * @return {string[]}
		 */
		get notes() {
			const container = mnm.containers[ state.containerId ];
			const notes     = [ ...state.messages ];

			if ( 'unavailable' === state.type ) {
				notes.push( escapeHtml( state.i18n.unavailable ) );
			} else if ( container && container.isFull && ! state.isSelected && ! notes.length ) {
				notes.push( escapeHtml( state.i18n.full ) );
			}

			return notes;
		},

		get hideNav() {
			return ! state.hasPrev && ! state.hasNext;
		},

		get prevLabel() {
			return state.hasPrev ? state.i18n.previous + ': ' + state.prevTitle : state.i18n.previous;
		},

		get nextLabel() {
			return state.hasNext ? state.i18n.next + ': ' + state.nextTitle : state.i18n.next;
		},
	},

	actions: {
		/**
		 * Open a quick view from a click on a child item's quick view button or title.
		 *
		 * @param {MouseEvent} event
		 */
		onDocumentClick: withSyncEvent( ( event ) => {
			const data = dialogEl ? getEventItem( event.target ) : null;

			if ( ! data || ! isKnown( data ) ) {
				return;
			}

			// Let shoppers open the product page in a new tab.
			if ( data.trigger.closest( 'a' ) && ( event.metaKey || event.ctrlKey || event.shiftKey || 0 !== event.button ) ) {
				return;
			}

			event.preventDefault();
			openItem( data, data.trigger );
		} ),

		/**
		 * Prefetch when the pointer rests on a child item's quick view button or title.
		 *
		 * @param {PointerEvent} event
		 */
		onDocumentPointerOver( event ) {
			const data = getEventItem( event.target );

			window.clearTimeout( hoverTimer );

			if ( data ) {
				hoverTimer = window.setTimeout( () => fetchDetails( data.containerId, data.productId ).catch( () => {} ), HOVER_DELAY );
			}
		},

		/**
		 * Prefetch right away on keyboard focus, and on touch, which has no hover.
		 *
		 * @param {Event} event
		 */
		onDocumentIntent( event ) {
			const data = getEventItem( event.target );

			if ( data ) {
				fetchDetails( data.containerId, data.productId ).catch( () => {} );
			}
		},

		close() {
			if ( dialogEl && dialogEl.open ) {
				dialogEl.close();
			}

			onClosed();
		},

		/**
		 * A click on the dialog itself, not its contents, is a click on the backdrop.
		 *
		 * @param {MouseEvent} event
		 */
		onDialogClick( event ) {
			if ( event.target === dialogEl ) {
				dialogEl.close();
				onClosed();
			}
		},

		/**
		 * The dialog closed, ex: on Escape.
		 */
		onClose() {
			onClosed();
		},

		/**
		 * Arrow keys page through the items, unless typing in a field.
		 *
		 * @param {KeyboardEvent} event
		 */
		onKeydown: withSyncEvent( ( event ) => {
			const target = event.target;

			if ( ( target.matches && target.matches( 'input, select, textarea, [contenteditable]' ) ) || event.altKey || event.ctrlKey || event.metaKey ) {
				return;
			}

			if ( 'ArrowLeft' === event.key || 'ArrowRight' === event.key ) {
				event.preventDefault();
				navigate( 'ArrowRight' === event.key ? 'next' : 'prev' );
			}
		} ),

		prev() {
			navigate( 'prev' );
		},

		next() {
			navigate( 'next' );
		},

		increase() {
			change( mnmActions.increaseItem );
		},

		decrease() {
			change( mnmActions.decreaseItem );
		},

		add() {
			pendingFocus = 'add';
			change( mnmActions.increaseItem );
		},

		/**
		 * Swap the main image for a clicked thumbnail. The details are loaded HTML, so the click is delegated.
		 *
		 * @param {MouseEvent} event
		 */
		onContentClick( event ) {
			const thumbnail = event.target instanceof Element ? event.target.closest( '.wc-mnm-quick-view__thumbnail' ) : null;
			const image     = dialogEl && dialogEl.querySelector( '.wc-mnm-quick-view__image img' );

			if ( ! thumbnail || ! image ) {
				return;
			}

			image.src = thumbnail.getAttribute( 'data-src' );
			image.alt = thumbnail.getAttribute( 'data-alt' ) || '';

			if ( thumbnail.getAttribute( 'data-srcset' ) ) {
				image.srcset = thumbnail.getAttribute( 'data-srcset' );
			} else {
				image.removeAttribute( 'srcset' );
			}

			dialogEl.querySelectorAll( '.wc-mnm-quick-view__thumbnail' ).forEach( ( el ) => {
				el.classList.toggle( 'is-active', el === thumbnail );
				el.setAttribute( 'aria-pressed', el === thumbnail ? 'true' : 'false' );
			} );
		},
	},

	callbacks: {
		/**
		 * Keep a reference to the dialog.
		 */
		init() {
			setDialog();
		},

		/**
		 * Get the child items ready as containers appear, including forms loaded with ajax.
		 */
		watchContainers() {
			const ids = [ ...mnm.ids ];

			if ( setDialog() ) {
				prepareTriggers();
				ids.forEach( preloadAll );
			}
		},

		/**
		 * Put the loaded details in the modal.
		 */
		renderContent() {
			const { ref } = getElement();
			const html    = state.html;

			ref.innerHTML = html;
			ref.scrollTop = 0;

			if ( html && dialogEl ) {
				dialogEl.dispatchEvent(
					new window.CustomEvent( 'wc-mnm-quick-view-loaded', {
						bubbles: true,
						detail: { containerId: state.containerId, productId: state.productId },
					} )
				);
			}
		},

		/**
		 * Render the messages. They are HTML, see state.notes.
		 */
		renderMessages() {
			getElement().ref.innerHTML = state.notes.map( ( note ) => '<li>' + note + '</li>' ).join( '' );
		},

		/**
		 * Look after focus when the controls change.
		 */
		watchFocus() {
			// Read what shows, hides, enables or disables a control, so this runs again when they change.
			const controls = [ state.isReady, state.hideAdd, state.hideStepper, state.hideRemove, state.cannotIncrease, state.cannotDecrease, state.hasPrev, state.hasNext ];

			// After the controls re-render.
			if ( controls.length ) {
				window.setTimeout( placeFocus );
			}
		},
	},
} );
