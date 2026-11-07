=== WC Mix and Match - Quickview ===
Contributors: helgatheviking
Tags: woocommerce, mix and match, quick view
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 3.0.0-beta.1
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Open a Mix and Match child item's product details in a pop-up, with a button to add it to the container.

== Description ==

Mini-extension for WooCommerce Mix and Match. Shoppers open a child item's details from the magnifier button in the corner of its image, or from its title. The pop-up has a button to add the product to the container, a quantity stepper, and previous/next buttons to page through the products.

Requires WordPress 6.5, WooCommerce 8.0 and Mix and Match 3.0 or later.

== Installation ==

1. Download the zip from the latest GitHub release.
2. Upload it in Plugins > Add New > Upload Plugin, then activate it.

Updates come from the Backcourt update server.

== Changelog ==

= 3.0.0-beta.2 XXXX.XX.XX =
* Breaking: The quick view is built on the Interactivity API and the Mix and Match container store, instead of the jQuery add to cart form. Requires Mix and Match 3.0 and WordPress 6.5.
* Update: Requires Mix and Match 3.0.0-beta.1. With an older Mix and Match, WooCommerce or WordPress, an admin notice says what to update, and the quick view doesn't load.
* Breaking: The script is now the @wc-mnm-quickview/quick-view script module. The wc_mnm_quick_view_params global and the wc-mnm-quick-view-loaded event are removed. Watch state.loaded in the wc-mnm-quickview store instead, see the readme.
* New: The modal is printed once in the footer, and its buttons and text render from the store.
* New: The item order, names and stock text come from the container store, so unavailable items say why, ex: "Out of stock". The quick view button only carries the container's add and remove button text.
* Update: The quick view button uses the pointer cursor instead of the zoom cursor.
* Update: Shorter default text that suits any container: "Add", "Remove", "Selected: 2" and "Included", instead of "Add to box", "Remove from box", "In your box: 2" and "Included in your box". The per-container button text and filters still work.
* New: The add, remove and stepper buttons name the product for screen readers, ex: "Add Capitulo Vinho Regional". Translate or reorder it with the buttonLabel string.
* Update: The quick view isn't loaded in the admin, ex: when editing an order item, because the container store isn't available there.

= 3.0.0-beta.1 XXXX.XX.XX =
* Breaking: Rebuilt without React. The quick view is a native dialog that works with the jQuery add to cart form.
* New: Open the quick view with a magnifier button in the corner of the child item's image, or by clicking its title. The old button template is removed, and the image lightbox is turned off.
* New: "Add to box" button and quantity stepper in the quick view. Quantities are set through the child item's own input, so the form validates and recalculates.
* New: The add button is disabled when the container is full, including after choosing another package in Mix and Match 3.0.
* New: Previous and next buttons, and arrow keys, to page through the child items.
* New: Keyboard support. Escape closes, and focus returns to the item.
* New: Product details for all of a container's items load in one request from the mnm_get_child_item_quick_view wc-ajax endpoint, once the page is idle or on the first hover or focus, so the quick view opens instantly. Filter wc_mnm_quick_view_preload_all to only load on hover or focus.
* New: Customize the add and remove button text per product, or with the wc_mnm_quick_view_add_to_box_text and wc_mnm_quick_view_remove_from_box_text filters.
* New: Works in forms loaded with ajax, ex: editing an order item or a subscription.
* Update: Requires Mix and Match 2.8.0 and WooCommerce 8.0.
* Update: The main class is now WC\MNM\Quickview\Plugin.
