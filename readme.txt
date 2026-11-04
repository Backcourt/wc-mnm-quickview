=== WC Mix and Match - Quickview ===
Contributors: helgatheviking
Tags: woocommerce, mix and match, quick view
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 3.0.0
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Open a Mix and Match child item's product details in a pop-up, with a button to add it to the container.

== Description ==

Mini-extension for WooCommerce Mix and Match. Shoppers open a child item's details from the magnifier button in the corner of its image, or from its title. The pop-up has a button to add the product to the container, a quantity stepper, and previous/next buttons to page through the products.

Requires WooCommerce 8.0 and Mix and Match 2.8.0 or later.

== Installation ==

1. Download the zip from the latest GitHub release.
2. Upload it in Plugins > Add New > Upload Plugin, then activate it.

Updates come from the Backcourt update server.

== Changelog ==

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
