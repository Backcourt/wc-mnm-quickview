/**
 * Mix and Match child item quick view.
 *
 * Clicking a child item's image or title opens its product details in a native <dialog>, with a
 * button to add it to the container. The details are loaded from the server on demand, and
 * prefetched when the pointer rests on, or keyboard focus reaches, a child item's image or title.
 *
 * The jQuery add to cart form (add-to-cart-mnm.js) stays the source of truth: quantities are set by
 * changing the child item's own quantity input and triggering "change", so the form validates and
 * recalculates as if the shopper had changed it.
 *
 * @since   3.0.0
 * @version 3.0.0
 */
( function ( $, window, document ) {
	'use strict';

	var params = window.wc_mnm_quick_view_params || {},
		i18n   = params.i18n || {};

	/**
	 * The elements of a child item that open its quick view.
	 */
	var TRIGGERS = '.mnm_child_product_images, .woocommerce-loop-product__title a, [data-wc-mnm-quick-view]';

	/**
	 * How long the pointer rests on a child item before its details are prefetched, in ms.
	 */
	var HOVER_DELAY = 80;

	/**
	 * Loaded and loading details, keyed by "containerId:itemId". Each is a jQuery promise of the HTML.
	 */
	var cache = {};

	var dialog     = null,
		$dialog    = null,
		openerEl   = null, // The element that opened the modal, focus returns to it on close.
		current    = null, // { containerId, itemId, itemEl }
		messages   = [], // Messages from the last change of quantity.
		hoverTimer = null,
		requestId  = 0;

	/*-----------------------------------------------------------------*/
	/*  Container helpers                                              */
	/*-----------------------------------------------------------------*/

	/**
	 * Get the initialized containers that are on the page.
	 *
	 * @return {Array} WC_MNM_Container objects.
	 */
	function getContainers() {
		var scripts = window.wc_mnm_scripts || {};

		return Object.keys( scripts ).map( function ( key ) {
			return scripts[ key ];
		} ).filter( function ( container ) {
			return container && container.is_initialized && container.$mnm_form && container.$mnm_form.length && document.documentElement.contains( container.$mnm_form.get( 0 ) );
		} );
	}

	/**
	 * Find the container and child item an element belongs to.
	 *
	 * @param {Element} el Element inside a child item.
	 * @return {Object|null} { container, child }
	 */
	function findItem( el ) {
		var containers = getContainers(),
			i, j, container, child;

		for ( i = 0; i < containers.length; i++ ) {
			container = containers[ i ];

			if ( ! container.$mnm_form.get( 0 ).contains( el ) ) {
				continue;
			}

			for ( j = 0; j < container.child_items.length; j++ ) {
				child = container.child_items[ j ];

				if ( child.$self && child.$self.get( 0 ) && child.$self.get( 0 ).contains( el ) ) {
					return { container: container, child: child };
				}
			}
		}

		return null;
	}

	/**
	 * Get the container and child item that are open in the modal.
	 *
	 * Looked up each time, a form loaded with ajax can be re-initialized while the modal is open.
	 *
	 * @return {Object|null} { container, child }
	 */
	function getCurrent() {
		var containers, i, j, child;

		if ( ! current ) {
			return null;
		}

		// The same element, still in its form.
		if ( current.itemEl && document.documentElement.contains( current.itemEl ) ) {
			return findItem( current.itemEl );
		}

		// Otherwise the same item in a re-rendered form.
		containers = getContainers();

		for ( i = 0; i < containers.length; i++ ) {
			if ( parseInt( containers[ i ].container_id, 10 ) !== current.containerId ) {
				continue;
			}

			for ( j = 0; j < containers[ i ].child_items.length; j++ ) {
				child = containers[ i ].child_items[ j ];

				if ( parseInt( child.mnm_item_id, 10 ) === current.itemId ) {
					current.itemEl = child.$self.get( 0 );
					return { container: containers[ i ], child: child };
				}
			}
		}

		return null;
	}

	/**
	 * Get a child item's input type.
	 *
	 * NB: Not child.get_type(), before Mix and Match 3.0 it reports any quantity input hidden with CSS as "hidden".
	 *
	 * @param {Object} child WC_MNM_Child_Item
	 * @return {string} unavailable|checkbox|select|hidden|input
	 */
	function getType( child ) {
		var $qty = child.$mnm_item_qty;

		if ( ! $qty || ! $qty.length ) {
			return 'unavailable'; // Out of stock and unpurchasable items have no quantity input.
		}

		if ( $qty.is( ':checkbox' ) ) {
			return 'checkbox';
		}

		if ( $qty.is( 'select' ) ) {
			return 'select';
		}

		if ( $qty.is( '[type="hidden"]' ) ) {
			return 'hidden'; // Fixed quantity, min = max = value.
		}

		return 'input';
	}

	/**
	 * Read a numeric attribute from a quantity input.
	 *
	 * @param {Object} $input jQuery quantity input.
	 * @param {string} attr   Attribute name.
	 * @return {number} The value, or 0 when missing.
	 */
	function getAttr( $input, attr ) {
		var value = parseFloat( $input.attr( attr ) );
		return isNaN( value ) ? 0 : value;
	}

	/**
	 * Get a child item's quantity.
	 *
	 * @param {Object} child WC_MNM_Child_Item
	 * @return {number}
	 */
	function getQuantity( child ) {
		var $qty = child.$mnm_item_qty,
			qty;

		switch ( getType( child ) ) {
			case 'unavailable':
				return 0;
			case 'checkbox':
				qty = $qty.is( ':checked' ) ? $qty.val() : 0;
				break;
			default:
				qty = $qty.val();
		}

		return parseInt( qty, 10 ) || 0;
	}

	/**
	 * Get the state of the open child item.
	 *
	 * @param {Object} container WC_MNM_Container
	 * @param {Object} child     WC_MNM_Child_Item
	 * @return {Object}
	 */
	function getItemState( container, child ) {
		var $qty     = child.$mnm_item_qty,
			type     = getType( child ),
			quantity = getQuantity( child ),
			size     = parseInt( container.api.get_container_size(), 10 ) || 0,
			maxSize  = parseInt( container.api.get_max_container_size(), 10 ) || 0, // 0 is unlimited.
			isFull   = maxSize > 0 && size >= maxSize,
			data     = {
				type: type,
				quantity: quantity,
				isSelected: quantity > 0,
				isFull: isFull,
				canIncrease: false,
				canDecrease: false
			},
			min, max, step;

		switch ( type ) {
			case 'unavailable':
			case 'hidden':
				break;
			case 'checkbox':
				data.canIncrease = ! data.isSelected && ( ! maxSize || size + ( parseInt( $qty.val(), 10 ) || 1 ) <= maxSize );
				data.canDecrease = data.isSelected;
				break;
			case 'select':
				max  = Math.max.apply( null, $qty.find( 'option' ).map( function () { return parseInt( this.value, 10 ) || 0; } ).get().concat( 0 ) );
				data.canIncrease = ! isFull && quantity < max;
				data.canDecrease = quantity > ( parseInt( $qty.find( 'option' ).first().val(), 10 ) || 0 );
				break;
			default:
				min  = getAttr( $qty, 'min' );
				max  = getAttr( $qty, 'max' ); // 0 is unlimited.
				step = getAttr( $qty, 'step' ) || 1;

				data.canIncrease = ! isFull && ( ! max || quantity + step <= max );
				data.canDecrease = quantity > min;
		}

		return data;
	}

	/**
	 * Set a child item's quantity through its own input, so the form validates and recalculates.
	 *
	 * @param {Object} child    WC_MNM_Child_Item
	 * @param {number} quantity The new quantity.
	 */
	function setQuantity( child, quantity ) {
		var $qty = child.$mnm_item_qty,
			$option;

		switch ( getType( child ) ) {
			case 'unavailable':
			case 'hidden':
				return;
			case 'checkbox':
				$qty.prop( 'checked', quantity > 0 );
				break;
			case 'select':
				// The closest option at or below the quantity.
				$option = $qty.find( 'option' ).filter( function () {
					return ( parseInt( this.value, 10 ) || 0 ) <= quantity;
				} ).last();
				$qty.val( $option.length ? $option.val() : $qty.find( 'option' ).first().val() );
				break;
			default:
				$qty.val( quantity );
		}

		$qty.trigger( 'change' );

		// The child item may have corrected the quantity, ex: when there isn't room. Copy, it clears its messages after a moment.
		messages = 'function' === typeof child.get_messages ? child.get_messages().slice( 0 ) : [];
	}

	/**
	 * Step a child item's quantity up or down.
	 *
	 * @param {number} direction 1 or -1.
	 */
	function step( direction ) {
		var item = getCurrent(),
			type, state, $qty, $options, values, index, stepSize;

		if ( ! item ) {
			return;
		}

		type  = getType( item.child );
		state = getItemState( item.container, item.child );
		$qty  = item.child.$mnm_item_qty;

		if ( ( direction > 0 && ! state.canIncrease ) || ( direction < 0 && ! state.canDecrease ) ) {
			return;
		}

		switch ( type ) {
			case 'checkbox':
				setQuantity( item.child, direction > 0 ? 1 : 0 );
				break;
			case 'select':
				$options = $qty.find( 'option' );
				values   = $options.map( function () { return parseInt( this.value, 10 ) || 0; } ).get();
				index    = values.indexOf( state.quantity );
				index    = Math.min( Math.max( index + direction, 0 ), values.length - 1 );
				setQuantity( item.child, values[ index ] );
				break;
			default:
				stepSize = getAttr( $qty, 'step' ) || 1;
				setQuantity( item.child, Math.max( state.quantity + ( direction * stepSize ), getAttr( $qty, 'min' ) ) );
		}

		render();
	}

	/**
	 * Get the container's button text, set per product on the form.
	 *
	 * @param {Object} container WC_MNM_Container
	 * @return {Object} { addToBox, removeFromBox }
	 */
	function getLabels( container ) {
		var $form  = container.$mnm_form,
			raw    = $form.attr( 'data-quick_view_i18n' ) || $form.find( '.mnm_data' ).attr( 'data-quick_view_i18n' ),
			labels = {};

		if ( raw ) {
			try {
				labels = JSON.parse( raw ) || {};
			} catch ( e ) {
				labels = {};
			}
		}

		return {
			addToBox: labels.addToBox || i18n.addToBox,
			removeFromBox: labels.removeFromBox || i18n.removeFromBox
		};
	}

	/**
	 * Get the child items that can be paged to, the ones shown in the form.
	 *
	 * @param {Object} container WC_MNM_Container
	 * @return {Array} WC_MNM_Child_Item objects.
	 */
	function getVisibleItems( container ) {
		return container.child_items.filter( function ( child ) {
			return child.$self && child.$self.is( ':visible' );
		} );
	}

	/**
	 * Get the item before or after the open one.
	 *
	 * @param {number} direction 1 or -1.
	 * @return {Object|null} WC_MNM_Child_Item
	 */
	function getSibling( direction ) {
		var item = getCurrent(),
			items, index;

		if ( ! item ) {
			return null;
		}

		items = getVisibleItems( item.container );
		index = items.indexOf( item.child );

		return -1 === index ? null : items[ index + direction ] || null;
	}

	/**
	 * Get a child item's title text.
	 *
	 * @param {Object} child WC_MNM_Child_Item
	 * @return {string}
	 */
	function getTitle( child ) {
		return $.trim( child.$self.find( '.woocommerce-loop-product__title' ).first().text() );
	}

	/*-----------------------------------------------------------------*/
	/*  Loading                                                        */
	/*-----------------------------------------------------------------*/

	/**
	 * Preload the main image in some details, so it is ready when the modal opens.
	 *
	 * @param {string} html The details' HTML.
	 */
	function preloadImage( html ) {
		var template = document.createElement( 'template' ),
			img, preload;

		template.innerHTML = html;
		img = template.content.querySelector( '.wc-mnm-quick-view__image img' );

		if ( img ) {
			preload = new window.Image();

			if ( img.sizes ) {
				preload.sizes = img.sizes;
			}

			if ( img.srcset ) {
				preload.srcset = img.srcset;
			}

			preload.src = img.src;
		}
	}

	/**
	 * Fetch a child item's details, once.
	 *
	 * @param {number} containerId Container ID.
	 * @param {number} itemId      Child product ID.
	 * @return {Object} jQuery promise of the details' HTML.
	 */
	function fetchDetails( containerId, itemId ) {
		var key = containerId + ':' + itemId,
			request;

		if ( cache[ key ] ) {
			return cache[ key ];
		}

		request = $.ajax( {
			url: params.ajax_url,
			type: 'GET',
			dataType: 'json',
			data: {
				container_id: containerId,
				child_id: itemId
			}
		} ).then( function ( response ) {
			if ( ! response || ! response.success || ! response.data || ! response.data.html ) {
				return $.Deferred().reject().promise();
			}

			preloadImage( response.data.html );

			return response.data.html;
		} );

		// Let a later hover or click try again.
		request.fail( function () {
			delete cache[ key ];
		} );

		cache[ key ] = request;

		return request;
	}

	/**
	 * Prefetch a child item's details, ex: while the pointer rests on it.
	 *
	 * @param {Element} trigger An element that opens the quick view.
	 */
	function prefetch( trigger ) {
		var item = findItem( trigger );

		if ( item ) {
			fetchDetails( parseInt( item.container.container_id, 10 ), parseInt( item.child.mnm_item_id, 10 ) );
		}
	}

	/**
	 * Load the open child item's details into the modal.
	 */
	function load() {
		var request = ++requestId;

		$dialog.addClass( 'is-loading' ).attr( 'aria-busy', 'true' );
		$dialog.find( '.wc-mnm-quick-view__content' ).empty();
		$dialog.find( '.wc-mnm-quick-view__error' ).prop( 'hidden', true );
		render();

		fetchDetails( current.containerId, current.itemId )
			.done( function ( html ) {
				if ( request === requestId ) {
					$dialog.find( '.wc-mnm-quick-view__content' ).html( html ).scrollTop( 0 );
				}
			} )
			.fail( function () {
				if ( request === requestId ) {
					$dialog.find( '.wc-mnm-quick-view__error' ).prop( 'hidden', false );
				}
			} )
			.always( function () {
				if ( request === requestId ) {
					$dialog.removeClass( 'is-loading' ).removeAttr( 'aria-busy' );
					render();
					$dialog.trigger( 'wc-mnm-quick-view-loaded', [ current.containerId, current.itemId ] );
				}
			} );
	}

	/*-----------------------------------------------------------------*/
	/*  The modal                                                      */
	/*-----------------------------------------------------------------*/

	/**
	 * Escape text for HTML.
	 *
	 * @param {string} text
	 * @return {string}
	 */
	function esc( text ) {
		return $( '<div />' ).text( text || '' ).html();
	}

	/**
	 * Build the modal, once.
	 *
	 * @return {Object} jQuery dialog.
	 */
	function getDialog() {
		if ( $dialog ) {
			return $dialog;
		}

		$dialog = $(
			'<dialog id="wc-mnm-quick-view" class="wc-mnm-quick-view woocommerce" aria-labelledby="wc-mnm-quick-view-title">' +
				'<div class="wc-mnm-quick-view__inner">' +
					'<div class="wc-mnm-quick-view__header">' +
						'<div class="wc-mnm-quick-view__nav">' +
							'<button type="button" class="wc-mnm-quick-view__prev" aria-label="' + esc( i18n.previous ) + '"><span aria-hidden="true">&lsaquo;</span></button>' +
							'<button type="button" class="wc-mnm-quick-view__next" aria-label="' + esc( i18n.next ) + '"><span aria-hidden="true">&rsaquo;</span></button>' +
						'</div>' +
						'<button type="button" class="wc-mnm-quick-view__close" aria-label="' + esc( i18n.close ) + '"><span aria-hidden="true">&times;</span></button>' +
					'</div>' +
					'<div class="wc-mnm-quick-view__content"></div>' +
					'<p class="wc-mnm-quick-view__error woocommerce-error" role="alert" hidden>' + esc( i18n.error ) + '</p>' +
					'<div class="wc-mnm-quick-view__footer" hidden>' +
						'<div class="wc-mnm-quick-view__status" role="status" aria-live="polite">' +
							'<p class="wc-mnm-quick-view__in-box" hidden></p>' +
							'<ul class="wc-mnm-quick-view__messages"></ul>' +
						'</div>' +
						'<div class="wc-mnm-quick-view__actions">' +
							'<div class="wc-mnm-quick-view__stepper" hidden>' +
								'<button type="button" class="wc-mnm-quick-view__decrease button" aria-label="' + esc( i18n.decrease ) + '">&minus;</button>' +
								'<span class="wc-mnm-quick-view__quantity" aria-hidden="true"></span>' +
								'<button type="button" class="wc-mnm-quick-view__increase button" aria-label="' + esc( i18n.increase ) + '">+</button>' +
							'</div>' +
							'<button type="button" class="wc-mnm-quick-view__add button alt wp-element-button" hidden></button>' +
							'<button type="button" class="wc-mnm-quick-view__remove button" hidden></button>' +
						'</div>' +
					'</div>' +
				'</div>' +
			'</dialog>'
		);

		dialog = $dialog.get( 0 );

		$dialog
			.on( 'click', '.wc-mnm-quick-view__close', close )
			.on( 'click', '.wc-mnm-quick-view__prev', function () { navigate( -1 ); } )
			.on( 'click', '.wc-mnm-quick-view__next', function () { navigate( 1 ); } )
			.on( 'click', '.wc-mnm-quick-view__increase', function () { step( 1 ); } )
			.on( 'click', '.wc-mnm-quick-view__decrease, .wc-mnm-quick-view__remove', function () { step( -1 ); } )
			.on( 'click', '.wc-mnm-quick-view__add', add )
			.on( 'click', '.wc-mnm-quick-view__thumbnail', onThumbnailClick )
			.on( 'click', function ( event ) {
				// A click on the dialog itself, not its contents, is a click on the backdrop.
				if ( event.target === dialog ) {
					close();
				}
			} )
			.on( 'keydown', onDialogKeydown )
			.on( 'close', onClose );

		$( document.body ).append( $dialog );

		return $dialog;
	}

	/**
	 * Update the modal from the open child item.
	 */
	function render() {
		var item = getCurrent(),
			isReady, state, labels, prev, next, list, notes;

		if ( ! $dialog || ! current ) {
			return;
		}

		isReady = item && ! $dialog.hasClass( 'is-loading' ) && $dialog.find( '.wc-mnm-quick-view__error' ).prop( 'hidden' );

		// Previous and next.
		prev = getSibling( -1 );
		next = getSibling( 1 );

		$dialog.find( '.wc-mnm-quick-view__prev' )
			.prop( 'disabled', ! prev )
			.attr( 'aria-label', prev ? i18n.previous + ': ' + getTitle( prev ) : i18n.previous );
		$dialog.find( '.wc-mnm-quick-view__next' )
			.prop( 'disabled', ! next )
			.attr( 'aria-label', next ? i18n.next + ': ' + getTitle( next ) : i18n.next );
		$dialog.find( '.wc-mnm-quick-view__nav' ).prop( 'hidden', ! prev && ! next );

		$dialog.find( '.wc-mnm-quick-view__footer' ).prop( 'hidden', ! isReady );

		if ( ! isReady ) {
			return;
		}

		state  = getItemState( item.container, item.child );
		labels = getLabels( item.container );

		$dialog.find( '.wc-mnm-quick-view__in-box' )
			.prop( 'hidden', ! state.isSelected )
			.text( 'hidden' === state.type ? i18n.included : ( i18n.inBox || '' ).replace( '%d', state.quantity ) );

		$dialog.find( '.wc-mnm-quick-view__quantity' ).text( state.quantity );
		$dialog.find( '.wc-mnm-quick-view__stepper' ).prop( 'hidden', ! state.isSelected || 'checkbox' === state.type || 'hidden' === state.type || 'unavailable' === state.type );
		$dialog.find( '.wc-mnm-quick-view__increase' ).prop( 'disabled', ! state.canIncrease );
		$dialog.find( '.wc-mnm-quick-view__decrease' ).prop( 'disabled', ! state.canDecrease );

		$dialog.find( '.wc-mnm-quick-view__add' )
			.text( labels.addToBox )
			.prop( 'hidden', state.isSelected || 'unavailable' === state.type || 'hidden' === state.type )
			.prop( 'disabled', ! state.canIncrease )
			.toggleClass( 'disabled', ! state.canIncrease );

		$dialog.find( '.wc-mnm-quick-view__remove' )
			.text( labels.removeFromBox )
			.prop( 'hidden', ! state.isSelected || 'checkbox' !== state.type );

		// Messages are built as HTML by the container script, same as the form's messages.
		notes = messages.slice( 0 );

		if ( 'unavailable' === state.type ) {
			notes.push( esc( current.unavailableText || i18n.unavailable ) );
		} else if ( state.isFull && ! state.isSelected && ! notes.length ) {
			notes.push( esc( i18n.full ) );
		}

		list = $.map( notes, function ( message ) {
			return '<li>' + message + '</li>';
		} ).join( '' );

		$dialog.find( '.wc-mnm-quick-view__messages' ).html( list );

		keepFocus();
	}

	/**
	 * A button that is disabled or hidden while it has focus drops focus to the page, move it to the nearest control instead.
	 */
	function keepFocus() {
		var active = document.activeElement,
			$target;

		if ( ! dialog || ! dialog.open || ( active && active !== document.body && dialog.contains( active ) && ! active.disabled && $( active ).is( ':visible' ) ) ) {
			return;
		}

		// Prefer the footer controls, then the close button.
		$target = $dialog.find( '.wc-mnm-quick-view__footer button' ).filter( function () {
			return ! this.disabled && $( this ).is( ':visible' );
		} );

		( $target.length ? $target.first() : $dialog.find( '.wc-mnm-quick-view__close' ) ).trigger( 'focus' );
	}

	/**
	 * Open the modal for a child item.
	 *
	 * @param {Object}  item    { container, child }
	 * @param {Element} trigger The element that opened it.
	 */
	function open( item, trigger ) {
		var availability = item.child.$self.find( '.stock' ).first();

		getDialog();

		openerEl = trigger;
		messages = [];
		current  = {
			containerId: parseInt( item.container.container_id, 10 ),
			itemId: parseInt( item.child.mnm_item_id, 10 ),
			itemEl: item.child.$self.get( 0 ),
			// The form prints the reason an item can't be chosen, ex: "Out of stock", where its quantity input would be.
			unavailableText: availability.length ? $.trim( availability.text() ) : ''
		};

		if ( ! dialog.open ) {
			dialog.showModal();
			$dialog.find( '.wc-mnm-quick-view__close' ).trigger( 'focus' );
		}

		load();
	}

	/**
	 * Show the previous or next child item.
	 *
	 * @param {number} direction 1 or -1.
	 */
	function navigate( direction ) {
		var sibling = getSibling( direction ),
			item, $button;

		if ( ! sibling ) {
			return;
		}

		item = findItem( sibling.$self.get( 0 ) );

		if ( item ) {
			// Focus returns to the item that is shown when the modal closes.
			open( item, getTrigger( sibling ) || openerEl );

			// Keep focus on the button that was used, unless it is now disabled.
			$button = $dialog.find( direction > 0 ? '.wc-mnm-quick-view__next' : '.wc-mnm-quick-view__prev' );
			( $button.prop( 'disabled' ) ? $dialog.find( direction > 0 ? '.wc-mnm-quick-view__prev' : '.wc-mnm-quick-view__next' ) : $button ).trigger( 'focus' );
		}
	}

	/**
	 * Add the child item, then move focus to what replaces the add button.
	 */
	function add() {
		var $next;

		step( 1 );

		$next = $dialog.find( '.wc-mnm-quick-view__stepper:not([hidden]) .wc-mnm-quick-view__increase, .wc-mnm-quick-view__remove:not([hidden])' ).first();

		if ( $next.length && $dialog.find( '.wc-mnm-quick-view__add' ).prop( 'hidden' ) ) {
			// The increase button may be disabled once the box is full, then focus the decrease button.
			if ( $next.prop( 'disabled' ) ) {
				$next = $dialog.find( '.wc-mnm-quick-view__decrease' );
			}

			$next.trigger( 'focus' );
		}
	}

	/**
	 * Close the modal.
	 */
	function close() {
		if ( dialog && dialog.open ) {
			dialog.close();
		}
	}

	/**
	 * Clean up and return focus once the modal closes, including on Escape.
	 */
	function onClose() {
		var opener = openerEl;

		requestId++;
		current  = null;
		openerEl = null;
		messages = [];

		if ( opener && document.documentElement.contains( opener ) ) {
			opener.focus();
		}
	}

	/**
	 * Arrow keys page through the items, unless typing in a field.
	 *
	 * @param {KeyboardEvent} event
	 */
	function onDialogKeydown( event ) {
		if ( $( event.target ).is( ':input:not(button)' ) || event.altKey || event.ctrlKey || event.metaKey ) {
			return;
		}

		if ( 'ArrowLeft' === event.key || 'ArrowRight' === event.key ) {
			event.preventDefault();
			navigate( 'ArrowRight' === event.key ? 1 : -1 );
		}
	}

	/**
	 * Swap the main image for a clicked thumbnail.
	 */
	function onThumbnailClick( event ) {
		var thumbnail = event.currentTarget,
			image     = dialog.querySelector( '.wc-mnm-quick-view__image img' );

		if ( ! image ) {
			return;
		}

		image.src = thumbnail.getAttribute( 'data-src' );
		image.alt = thumbnail.getAttribute( 'data-alt' ) || '';

		if ( thumbnail.getAttribute( 'data-srcset' ) ) {
			image.srcset = thumbnail.getAttribute( 'data-srcset' );
		} else {
			image.removeAttribute( 'srcset' );
		}

		$dialog.find( '.wc-mnm-quick-view__thumbnail' ).each( function () {
			$( this ).toggleClass( 'is-active', this === thumbnail ).attr( 'aria-pressed', this === thumbnail ? 'true' : 'false' );
		} );
	}

	/*-----------------------------------------------------------------*/
	/*  Triggers                                                       */
	/*-----------------------------------------------------------------*/

	/**
	 * Get the element that opens a child item's quick view.
	 *
	 * @param {Object} child WC_MNM_Child_Item
	 * @return {Element|null}
	 */
	function getTrigger( child ) {
		var $trigger = child.$self.find( '[data-wc-mnm-quick-view-trigger], .woocommerce-loop-product__title a' ).first();
		return $trigger.length ? $trigger.get( 0 ) : null;
	}

	/**
	 * Find the quick view trigger an event happened on, outside the modal.
	 *
	 * @param {Event} event
	 * @return {Element|null}
	 */
	function getEventTrigger( event ) {
		var trigger = event.target instanceof window.Element ? event.target.closest( TRIGGERS ) : null;
		return trigger && ! ( dialog && dialog.contains( trigger ) ) ? trigger : null;
	}

	/**
	 * Make child item images keyboard accessible, and say what the title link does.
	 *
	 * @param {Object} container WC_MNM_Container
	 */
	function prepareTriggers( container ) {
		$.each( container.child_items || [], function ( index, child ) {
			var title = getTitle( child ),
				label = ( i18n.openLabel || '%s' ).replace( '%s', title );

			// The image becomes a button, the stylesheet adds a magnifier badge to it.
			child.$self.find( '.mnm_child_product_images' ).not( '[data-wc-mnm-quick-view-trigger]' ).attr( {
				'data-wc-mnm-quick-view-trigger': '',
				role: 'button',
				tabindex: '0',
				'aria-haspopup': 'dialog',
				'aria-label': label
			} );

			// The title stays a link, so it can still be opened in a new tab, but says what a click does.
			child.$self.find( '.woocommerce-loop-product__title a' ).not( '[aria-haspopup]' ).attr( {
				'aria-haspopup': 'dialog',
				'aria-label': label
			} );
		} );
	}

	/**
	 * Open a quick view from a click on a child item's image or title.
	 *
	 * Runs in the capture phase so that the image lightbox and product link don't also handle it.
	 *
	 * @param {MouseEvent} event
	 */
	function onClick( event ) {
		var trigger = getEventTrigger( event ),
			item;

		if ( ! trigger ) {
			return;
		}

		// Let shoppers open the product page in a new tab.
		if ( trigger.closest( 'a' ) && ( event.metaKey || event.ctrlKey || event.shiftKey || 0 !== event.button ) ) {
			return;
		}

		item = findItem( trigger );

		if ( ! item ) {
			return;
		}

		event.preventDefault();
		event.stopPropagation();

		open( item, trigger );
	}

	/**
	 * Open a quick view from the keyboard on a child item's image.
	 *
	 * @param {KeyboardEvent} event
	 */
	function onKeydown( event ) {
		var trigger, item;

		if ( 'Enter' !== event.key && ' ' !== event.key ) {
			return;
		}

		trigger = event.target instanceof window.Element && event.target.matches( '[data-wc-mnm-quick-view-trigger]' ) ? event.target : null;
		item    = trigger ? findItem( trigger ) : null;

		if ( item ) {
			event.preventDefault();
			open( item, trigger );
		}
	}

	/**
	 * Prefetch when the pointer rests on a child item's image or title.
	 *
	 * @param {PointerEvent} event
	 */
	function onPointerOver( event ) {
		var trigger = getEventTrigger( event );

		window.clearTimeout( hoverTimer );

		if ( trigger ) {
			hoverTimer = window.setTimeout( function () {
				prefetch( trigger );
			}, HOVER_DELAY );
		}
	}

	/**
	 * Prefetch right away on keyboard focus, and on touch, which has no hover.
	 *
	 * @param {Event} event
	 */
	function onIntent( event ) {
		var trigger = getEventTrigger( event );

		if ( trigger ) {
			prefetch( trigger );
		}
	}

	/*-----------------------------------------------------------------*/
	/*  Init                                                           */
	/*-----------------------------------------------------------------*/

	if ( ! window.HTMLDialogElement || ! params.ajax_url ) {
		return;
	}

	document.addEventListener( 'click', onClick, true );
	document.addEventListener( 'keydown', onKeydown );
	document.addEventListener( 'pointerover', onPointerOver, { passive: true } );
	document.addEventListener( 'pointerdown', onIntent, { passive: true } );
	document.addEventListener( 'focusin', onIntent );

	$( document )
		// Forms initialized later, ex: loaded with ajax to edit an order or subscription.
		.on( 'wc-mnm-initialized', '.mnm_form', function ( event, container ) {
			prepareTriggers( container );
			render();
		} )
		// Keep the modal in step with the form.
		.on( 'wc-mnm-container-quantities-updated wc-mnm-package-changed wc-mnm-form-updated', '.mnm_form', function () {
			if ( dialog && dialog.open ) {
				render();
			}
		} );

	// Forms that were initialized before this script ran.
	$( function () {
		$.each( getContainers(), function ( index, container ) {
			prepareTriggers( container );
		} );
	} );

} )( jQuery, window, document );
