# WC Mix and Match - Quickview

### What's This?

Mini-extension for [WooCommerce Mix and Match](https://woocommerce.com/products/woocommerce-mix-and-match-products/) that opens a child item's product details in a pop-up when shoppers click the "Quick view" button on its image, or its title. The pop-up has a button to add the product to the container, a quantity stepper, and previous/next buttons to page through the products.

It works with the regular Mix and Match add to cart form (jQuery), including forms loaded with ajax such as editing an order or subscription. There's no build step and no React.

![A modal window showing some details such as thumbnail, title, and description of a red wine product](https://github.com/kathyisawesome/wc-mnm-quickview/assets/507025/9879c570-c021-48db-8918-eb6205501b4a)

### Customizing the button text

Each Mix and Match product has "Quick view add button text" and "Quick view remove button text" fields in its Mix and Match product data, ex: "Add to bundle".

Or use the filters:

```php
add_filter( 'wc_mnm_quick_view_add_to_box_text', function( $text, $container ) {
	return __( 'Add to bundle', 'your-textdomain' );
}, 10, 2 );

add_filter( 'wc_mnm_quick_view_remove_from_box_text', function( $text, $container ) {
	return __( 'Remove from bundle', 'your-textdomain' );
}, 10, 2 );

// All other strings, ex: "In your box: %d".
add_filter( 'wc_mnm_quick_view_i18n', function( $i18n ) {
	$i18n['inBox'] = __( 'In your bundle: %d', 'your-textdomain' );
	return $i18n;
} );
```

### Other filters and hooks

* `wc_mnm_has_quick_view` - return false to turn the quick view off, gets the container product when known.
* `wc_mnm_quick_view_template_args` - change the data passed to the content template.
* `wc_mnm_quick_view_before_summary` / `wc_mnm_quick_view_after_summary` - add to the product details.
* The product details come from `templates/single-product/mnm/quick-view-content.php`, which can be overridden in your theme at `yourtheme/woocommerce/single-product/mnm/quick-view-content.php`.

### Development

The script and stylesheet are plain files, `assets/js/frontend/quick-view.js` and `assets/css/frontend/quick-view.css`. `npm run dev` makes the minified and RTL copies, which are used when present and `SCRIPT_DEBUG` is off.

### Important

1. This is provided as is and does not receive priority support.
2. Please test thoroughly before using in production.
3. Requires Mix and Match 2.8.0+ and WooCommerce 8.0+.
