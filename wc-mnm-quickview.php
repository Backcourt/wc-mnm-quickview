<?php
/**
 * Plugin Name: WC Mix and Match - Quickview
 * Plugin URI: https://github.com/backcourt/wc-mnm-quickview
 * Update URI: https://www.backcourt.io
 * Version: 3.0.0-beta.1
 * Description: Open a child item's product details in a pop-up, with a button to add it to the container.
 * Author: Kathy Darling
 * Author URI: http://kathyisawesome.com/
 * Text Domain: wc-mnm-quickview
 * Domain Path: /languages
 *
 * Requires at least: 6.4
 * Tested up to: 6.9
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce, woocommerce-mix-and-match-products
 *
 * WC requires at least: 8.0.0
 * WC tested up to: 10.3.0
 *
 * MNM requires at least: 2.8.0
 *
 * Copyright: © 2020 Kathy Darling
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace WC\MNM\Quickview;

use Backcourt\MNMQuickView\Vendor\Fragen;

defined( 'ABSPATH' ) || exit;

/**
 * Add Git Updater Lite. Updates come from the server in the Update URI header.
 */
if ( file_exists( __DIR__ . '/vendor-prefixed/autoload.php' ) ) {
	require_once __DIR__ . '/vendor-prefixed/autoload.php';

	if ( class_exists( Fragen\Git_Updater\Lite::class ) ) {
		( new Fragen\Git_Updater\Lite( __FILE__ ) )->run();
	}
}

/**
 * The main plugin class.
 */
class Plugin {

	/**
	 * Plugin version.
	 */
	const VERSION = '3.0.0-beta.1';

	/**
	 * Minimum versions.
	 */
	const REQ_WC_VERSION  = '8.0.0';
	const REQ_MNM_VERSION = '2.8.0';

	/**
	 * The wc-ajax action that returns a child item's details.
	 */
	const AJAX_ACTION = 'mnm_get_child_item_quick_view';

	/**
	 * Product meta keys for the per-product button text.
	 */
	const META_ADD_TEXT    = '_wc_mnm_quick_view_add_text';
	const META_REMOVE_TEXT = '_wc_mnm_quick_view_remove_text';

	/**
	 * Singleton instance of the class.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * NB: Mix and Match core unhooks `array( Plugin::instance(), 'init' )` when it ships its own quick view.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Hook in.
	 */
	public function init() {

		// Quietly quit if WooCommerce or Mix and Match are missing or too old.
		if ( ! function_exists( 'WC' ) || version_compare( WC()->version, self::REQ_WC_VERSION ) < 0 ) {
			return false;
		}

		if ( ! function_exists( 'wc_mix_and_match' ) || version_compare( wc_mix_and_match()->version, self::REQ_MNM_VERSION ) < 0 ) {
			return false;
		}

		// Translations.
		add_action( 'init', array( $this, 'load_plugin_textdomain' ) );

		// Declare HPOS compatibility.
		add_action( 'before_woocommerce_init', array( $this, 'declare_features_compatibility' ) );

		// Register scripts on init, so they can be enqueued on the frontend and in the admin (edit order).
		add_action( 'init', array( $this, 'register_scripts' ), 20 );

		// Load the scripts with any Mix and Match form.
		add_action( 'woocommerce_mix-and-match_add_to_cart', array( $this, 'load_scripts' ), 0 );

		// Forms loaded with ajax: editing an order item in the admin, or a subscription in My Account.
		add_action( 'wc_mnm_container_editing_enqueue_scripts', array( $this, 'load_scripts' ) );
		add_action( 'wc_mnm_subscription_editing_enqueue_scripts', array( $this, 'load_scripts' ) );

		// Pass each container's button text to the script.
		add_filter( 'wc_mnm_container_data_attributes', array( $this, 'add_data_attributes' ), 10, 2 );

		// The quick view replaces the child item image lightbox.
		add_filter( 'wc_mnm_add_to_cart_script_parameters', array( $this, 'disable_child_item_lightbox' ) );
		add_filter( 'wc_mnm_child_item_image_html', array( $this, 'child_item_image_html' ), 10, 3 );

		// Product details.
		add_action( 'wc_ajax_' . self::AJAX_ACTION, array( $this, 'get_quick_view' ) );

		// Per-product button text.
		add_action( 'wc_mnm_admin_product_options', array( $this, 'product_options' ), 60, 2 );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product_options' ) );
	}

	/*-----------------------------------------------------------------------------------*/
	/* Core Compat */
	/*-----------------------------------------------------------------------------------*/

	/**
	 * Load translations.
	 */
	public function load_plugin_textdomain() {
		load_plugin_textdomain( 'wc-mnm-quickview', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	/**
	 * Declare HPOS (custom order tables) and cart/checkout blocks compatibility.
	 */
	public function declare_features_compatibility() {

		if ( ! class_exists( 'Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', plugin_basename( __FILE__ ), true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', plugin_basename( __FILE__ ), true );
	}

	/*-----------------------------------------------------------------------------------*/
	/* Scripts and Styles */
	/*-----------------------------------------------------------------------------------*/

	/**
	 * Register the script and stylesheet.
	 */
	public function register_scripts() {

		$style_path = $this->get_asset_path( 'assets/css/frontend/quick-view.css' );

		wp_register_style( 'wc-mnm-quickview', $this->plugin_url( $style_path ), array(), $this->get_file_version( $style_path ) );

		if ( file_exists( $this->plugin_path( str_replace( '.css', '-rtl.css', $style_path ) ) ) ) {
			wp_style_add_data( 'wc-mnm-quickview', 'rtl', 'replace' );

			if ( false !== strpos( $style_path, '.min.css' ) ) {
				wp_style_add_data( 'wc-mnm-quickview', 'suffix', '.min' );
			}
		}

		$script_path = $this->get_asset_path( 'assets/js/frontend/quick-view.js' );

		wp_register_script(
			'wc-mnm-quickview',
			$this->plugin_url( $script_path ),
			array( 'jquery', 'wc-add-to-cart-mnm' ),
			$this->get_file_version( $script_path ),
			true
		);
	}

	/**
	 * Load the scripts anywhere a Mix and Match form is displayed.
	 */
	public function load_scripts() {

		global $product;

		$container = doing_action( 'woocommerce_mix-and-match_add_to_cart' ) && $product instanceof \WC_Product ? $product : null;

		if ( ! self::is_enabled( $container ) ) {
			return;
		}

		// Registered on init, but make sure in case this runs early.
		if ( ! wp_script_is( 'wc-mnm-quickview', 'registered' ) ) {
			$this->register_scripts();
		}

		wp_enqueue_style( 'wc-mnm-quickview' );
		wp_enqueue_script( 'wc-mnm-quickview' );

		if ( ! wp_scripts()->get_data( 'wc-mnm-quickview', 'data' ) ) {
			wp_localize_script(
				'wc-mnm-quickview',
				'wc_mnm_quick_view_params',
				array(
					'ajax_url'    => \WC_AJAX::get_endpoint( self::AJAX_ACTION ),
					/**
					 * Whether to load every child item's details once the page is idle, so the quick view opens
					 * instantly. When false, they load on the first hover or focus of a child item.
					 *
					 * @since 3.0.0
					 *
					 * @param bool $preload
					 */
					'preload_all' => (bool) apply_filters( 'wc_mnm_quick_view_preload_all', true ),
					'i18n'        => self::get_i18n(),
				)
			);
		}
	}

	/*-----------------------------------------------------------------------------------*/
	/* Display */
	/*-----------------------------------------------------------------------------------*/

	/**
	 * Add the container's button text to the form, so forms loaded with ajax get them too.
	 *
	 * @param array                     $attributes
	 * @param \WC_Product_Mix_and_Match $container
	 * @return array
	 */
	public function add_data_attributes( $attributes, $container ) {

		if ( self::is_enabled( $container ) ) {
			$attributes['quick_view_i18n'] = wp_json_encode(
				array(
					'addToBox'      => self::get_add_to_box_text( $container ),
					'removeFromBox' => self::get_remove_from_box_text( $container ),
				)
			);
		}

		return $attributes;
	}

	/**
	 * Don't open child item images in the PhotoSwipe lightbox.
	 *
	 * @param array $params The add to cart script parameters.
	 * @return array
	 */
	public function disable_child_item_lightbox( $params ) {

		$container = is_singular( 'product' ) ? wc_get_product( get_queried_object_id() ) : null;

		if ( self::is_enabled( $container ? $container : null ) ) {
			$params['photoswipe_enabled'] = false;
		}

		return $params;
	}

	/**
	 * Swap the image's lightbox link for a quick view button.
	 *
	 * @param string                    $html       The child item image HTML.
	 * @param \WC_MNM_Child_Item        $child_item
	 * @param \WC_Product_Mix_and_Match $container
	 * @return string
	 */
	public function child_item_image_html( $html, $child_item, $container ) {

		if ( ! self::is_enabled( $container ) ) {
			return $html;
		}

		// The lightbox link, whatever classes a theme gave it.
		$html = preg_replace( '#<a\s[^>]*class="[^"]*\bzoom\b[^"]*"[^>]*>(.*?)</a>#s', '$1', $html );

		$i18n    = self::get_i18n();
		$product = $child_item->get_product();
		$title   = $product ? $product->get_name() : '';

		// The script adds aria-haspopup, wp_kses_post() would remove it here.
		$button = sprintf(
			'<button type="button" class="wc-mnm-quick-view-button" data-wc-mnm-quick-view aria-label="%1$s"><span class="wc-mnm-quick-view-button__text">%2$s</span></button>',
			esc_attr( str_replace( '%s', $title, $i18n['openLabel'] ) ),
			esc_html( $i18n['quickView'] )
		);

		$position = strrpos( $html, '</figure>' );

		return false === $position ? $html . $button : substr_replace( $html, $button, $position, 0 );
	}

	/**
	 * Return a child item's details for the modal.
	 */
	public function get_quick_view() {

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read only, public product data.
		$container_id = isset( $_GET['container_id'] ) ? absint( $_GET['container_id'] ) : 0;
		$child_id     = isset( $_GET['child_id'] ) ? absint( $_GET['child_id'] ) : 0;
		// phpcs:enable

		$container = $container_id ? wc_get_product( $container_id ) : false;

		if ( ! $container || ! $container->is_type( 'mix-and-match' ) || ! $this->can_view( $container ) || ! self::is_enabled( $container ) ) {
			wp_send_json_error( null, 404 );
		}

		// One item, or all of them in one go, so each opening doesn't pay for loading WordPress.
		if ( $child_id ) {
			$child_item = $container->get_child_item_by_product_id( $child_id );

			if ( ! $child_item || ! $child_item->get_product() ) {
				wp_send_json_error( null, 404 );
			}

			wp_send_json_success(
				array(
					'html' => $this->get_content_html( $child_item ),
				)
			);
		}

		$items = array();

		foreach ( $container->get_child_items() as $child_item ) {
			if ( $child_item->get_product() && $child_item->is_visible() ) {
				$items[ $child_item->get_product_id() ] = $this->get_content_html( $child_item );
			}
		}

		wp_send_json_success(
			array(
				'items' => (object) $items,
			)
		);
	}

	/**
	 * Get a child item's details HTML.
	 *
	 * @param \WC_MNM_Child_Item $child_item
	 * @return string
	 */
	protected function get_content_html( $child_item ) {

		ob_start();

		wc_get_template(
			'single-product/mnm/quick-view-content.php',
			$this->get_content_args( $child_item ),
			'',
			$this->plugin_path( 'templates/' )
		);

		return ob_get_clean();
	}

	/*-----------------------------------------------------------------------------------*/
	/* Admin */
	/*-----------------------------------------------------------------------------------*/

	/**
	 * Add the button text fields to the Mix and Match product options.
	 *
	 * @param int                       $post_id
	 * @param \WC_Product_Mix_and_Match $container
	 */
	public function product_options( $post_id, $container ) {

		woocommerce_wp_text_input(
			array(
				'id'          => self::META_ADD_TEXT,
				'label'       => esc_html__( 'Quick view add button text', 'wc-mnm-quickview' ),
				'placeholder' => esc_attr_x( 'Add to box', '[Frontend]', 'wc-mnm-quickview' ),
				'value'       => $container->get_meta( self::META_ADD_TEXT ),
				'desc_tip'    => true,
				'description' => esc_html__( 'Text of the button that adds a product to this container from its quick view, ex: Add to bundle. Leave blank for the default.', 'wc-mnm-quickview' ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'          => self::META_REMOVE_TEXT,
				'label'       => esc_html__( 'Quick view remove button text', 'wc-mnm-quickview' ),
				'placeholder' => esc_attr_x( 'Remove from box', '[Frontend]', 'wc-mnm-quickview' ),
				'value'       => $container->get_meta( self::META_REMOVE_TEXT ),
				'desc_tip'    => true,
				'description' => esc_html__( 'Text of the button that removes a product from this container in its quick view, shown for checkbox selections. Leave blank for the default.', 'wc-mnm-quickview' ),
			)
		);
	}

	/**
	 * Save the button text.
	 *
	 * @param \WC_Product $product
	 */
	public function save_product_options( $product ) {

		if ( ! $product->is_type( 'mix-and-match' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by WooCommerce.
		foreach ( array( self::META_ADD_TEXT, self::META_REMOVE_TEXT ) as $key ) {
			$value = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';

			if ( '' === $value ) {
				$product->delete_meta_data( $key );
			} else {
				$product->update_meta_data( $key, $value );
			}
		}
		// phpcs:enable
	}

	/*-----------------------------------------------------------------------------------*/
	/* Helpers */
	/*-----------------------------------------------------------------------------------*/

	/**
	 * Whether child items open in a quick view.
	 *
	 * @param \WC_Product|null $container The container, null when not known, ex: editing a subscription.
	 * @return bool
	 */
	public static function is_enabled( $container = null ) {
		/**
		 * Filter whether child items open in a quick view.
		 *
		 * @since 3.0.0
		 *
		 * @param bool             $is_enabled
		 * @param \WC_Product|null $container
		 */
		return (bool) apply_filters( 'wc_mnm_has_quick_view', true, $container );
	}

	/**
	 * Get the text of the button that adds the child item to the container.
	 *
	 * @param \WC_Product|null $container
	 * @return string
	 */
	public static function get_add_to_box_text( $container = null ) {

		$text = $container ? (string) $container->get_meta( self::META_ADD_TEXT ) : '';

		/**
		 * Filter the quick view's add button text, ex: "Add to bundle".
		 *
		 * @since 3.0.0
		 *
		 * @param string           $text
		 * @param \WC_Product|null $container The container, if known.
		 */
		return (string) apply_filters( 'wc_mnm_quick_view_add_to_box_text', '' !== $text ? $text : _x( 'Add to box', '[Frontend]', 'wc-mnm-quickview' ), $container );
	}

	/**
	 * Get the text of the button that removes a selected checkbox child item from the container.
	 *
	 * @param \WC_Product|null $container
	 * @return string
	 */
	public static function get_remove_from_box_text( $container = null ) {

		$text = $container ? (string) $container->get_meta( self::META_REMOVE_TEXT ) : '';

		/**
		 * Filter the quick view's remove button text, ex: "Remove from bundle".
		 *
		 * @since 3.0.0
		 *
		 * @param string           $text
		 * @param \WC_Product|null $container The container, if known.
		 */
		return (string) apply_filters( 'wc_mnm_quick_view_remove_from_box_text', '' !== $text ? $text : _x( 'Remove from box', '[Frontend]', 'wc-mnm-quickview' ), $container );
	}

	/**
	 * Get the script's strings.
	 *
	 * @return array
	 */
	public static function get_i18n() {

		$i18n = array(
			'addToBox'      => self::get_add_to_box_text(),
			'removeFromBox' => self::get_remove_from_box_text(),
			/* translators: %d: Quantity of the child item in the container. */
			'inBox'         => _x( 'In your box: %d', '[Frontend]', 'wc-mnm-quickview' ),
			'included'      => _x( 'Included in your box', '[Frontend]', 'wc-mnm-quickview' ),
			'unavailable'   => _x( 'This product can\'t be added to your box.', '[Frontend]', 'wc-mnm-quickview' ),
			'full'          => _x( 'Your box is full. Remove an item to make room.', '[Frontend]', 'wc-mnm-quickview' ),
			'error'         => _x( 'Sorry, this product could not be loaded.', '[Frontend]', 'wc-mnm-quickview' ),
			'close'         => _x( 'Close', '[Frontend]', 'wc-mnm-quickview' ),
			'decrease'      => _x( 'Remove one', '[Frontend]', 'wc-mnm-quickview' ),
			'increase'      => _x( 'Add one more', '[Frontend]', 'wc-mnm-quickview' ),
			'previous'      => _x( 'Previous product', '[Frontend]', 'wc-mnm-quickview' ),
			'next'          => _x( 'Next product', '[Frontend]', 'wc-mnm-quickview' ),
			'quickView'     => _x( 'Quick view', '[Frontend]', 'wc-mnm-quickview' ),
			/* translators: %s: Child product title. */
			'openLabel'     => _x( 'Quick view: %s', '[Frontend]', 'wc-mnm-quickview' ),
		);

		/**
		 * Filter the quick view's strings.
		 *
		 * NB: Use wc_mnm_quick_view_add_to_box_text and wc_mnm_quick_view_remove_from_box_text for the button
		 * text, they can be changed per container.
		 *
		 * @since 3.0.0
		 *
		 * @param array $i18n
		 */
		return (array) apply_filters( 'wc_mnm_quick_view_i18n', $i18n );
	}

	/**
	 * Whether the current visitor may see a container's details.
	 *
	 * @param \WC_Product_Mix_and_Match $container
	 * @return bool
	 */
	protected function can_view( $container ) {

		if ( post_password_required( $container->get_id() ) ) {
			return false;
		}

		return 'publish' === $container->get_status() || current_user_can( 'read_post', $container->get_id() );
	}

	/**
	 * Get the template args for a child item's details.
	 *
	 * @param \WC_MNM_Child_Item $child_item
	 * @return array
	 */
	protected function get_content_args( $child_item ) {

		$product = $child_item->get_product();
		$parent  = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
		$parent  = $parent ? $parent : $product;

		$image_ids = array_merge(
			array( $product->get_image_id() ? $product->get_image_id() : $parent->get_image_id() ),
			$parent->get_gallery_image_ids()
		);

		$args = array(
			'child_item'        => $child_item,
			'container'         => $child_item->get_container(),
			'product'           => $product,
			'parent_product'    => $parent,
			'title'             => $child_item->get_title(),
			'permalink'         => $child_item->get_permalink(),
			'image_ids'         => array_values( array_unique( array_filter( array_map( 'absint', $image_ids ) ) ) ),
			'short_description' => $parent->get_short_description(),
			'description'       => $product->get_description() ? $product->get_description() : $parent->get_description(),
		);

		/**
		 * Filter the quick view template args.
		 *
		 * @since 3.0.0
		 *
		 * @param array              $args
		 * @param \WC_MNM_Child_Item $child_item
		 */
		return (array) apply_filters( 'wc_mnm_quick_view_template_args', $args, $child_item );
	}

	/**
	 * Use the minified asset when it exists and SCRIPT_DEBUG is off.
	 *
	 * @param string $path Relative path to the unminified file.
	 * @return string
	 */
	protected function get_asset_path( $path ) {

		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
			return $path;
		}

		$min = preg_replace( '/\.(js|css)$/', '.min.$1', $path );

		return file_exists( $this->plugin_path( $min ) ) ? $min : $path;
	}

	/**
	 * Get a file's version, its modified time while debugging.
	 *
	 * @param string $path Relative path.
	 * @return string
	 */
	protected function get_file_version( $path ) {

		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG && file_exists( $this->plugin_path( $path ) ) ) {
			return (string) filemtime( $this->plugin_path( $path ) );
		}

		return self::VERSION;
	}

	/**
	 * Get the plugin URL.
	 *
	 * @param string $path Optional path to append.
	 * @return string
	 */
	public function plugin_url( $path = '' ) {
		return plugins_url( $path, __FILE__ );
	}

	/**
	 * Get the plugin path.
	 *
	 * @param string $path Optional path to append.
	 * @return string
	 */
	public function plugin_path( $path = '' ) {
		return trailingslashit( plugin_dir_path( __FILE__ ) ) . $path;
	}
}

add_action( 'plugins_loaded', array( Plugin::instance(), 'init' ), 20 );
