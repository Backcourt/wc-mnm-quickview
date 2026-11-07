<?php
/**
 * Plugin Name: WC Mix and Match - Quickview
 * Plugin URI: https://github.com/backcourt/wc-mnm-quickview
 * Version: 3.0.0-beta.1
 * Description: Open a child item's product details in a pop-up, with a button to add it to the container.
 * Author: Backcourt Development
 * Author URI: https://www.backcourt.io/
 *
 * Text Domain: wc-mnm-quickview
 * Domain Path: /languages
 *
 * Requires at least: 6.5
 * Tested up to: 6.9
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce, woocommerce-mix-and-match-products
 *
 * WC requires at least: 8.2.0
 * WC tested up to: 11.2.0
 * 
 * Update URI: https://www.backcourt.io/
 *
 * Copyright: © 2020 Backcourt Development
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace WC\MNM\Quickview;

use Backcourt\MNMQuickView\Vendor\Fragen;
use Backcourt\MixAndMatch\Interactivity\ContainerStore;

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
	const REQ_MNM_VERSION = '3.0.0-beta.1';
	const REQ_WP_VERSION  = '6.5';

	/**
	 * The wc-ajax action that returns a child item's details.
	 */
	const AJAX_ACTION = 'mnm_get_child_item_quick_view';

	/**
	 * Script module ID.
	 */
	const MODULE = '@wc-mnm-quickview/quick-view';

	/**
	 * Interactivity API store namespace.
	 */
	const STORE = 'wc-mnm-quickview';

	/**
	 * Why the plugin isn't running, shown as an admin notice.
	 *
	 * @var string
	 */
	private $notice = '';

	/**
	 * Whether the modal should be printed in the footer.
	 *
	 * @var bool
	 */
	private $needs_modal = false;

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

		// Stop, with an admin notice, if WooCommerce, Mix and Match or WordPress are missing or too old.
		if ( ! $this->meets_requirements() ) {
			add_action( 'admin_notices', array( $this, 'admin_notice' ) );
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

		// Forms loaded with ajax, ex: editing a subscription in My Account.
		add_action( 'wc_mnm_subscription_editing_enqueue_scripts', array( $this, 'load_scripts' ) );

		// The modal.
		add_action( 'wp_footer', array( $this, 'print_modal' ) );

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
	/* Requirements */
	/*-----------------------------------------------------------------------------------*/

	/**
	 * Check the required versions, and keep the reason when one isn't met.
	 *
	 * The quick view is built on the Mix and Match container store, which needs Mix and Match 3.0 and the
	 * Interactivity API from WordPress 6.5.
	 *
	 * @return bool
	 */
	protected function meets_requirements() {

		if ( ! function_exists( 'WC' ) || version_compare( WC()->version, self::REQ_WC_VERSION ) < 0 ) {
			/* translators: %s: Required WooCommerce version. */
			$this->notice = sprintf( __( 'WC Mix and Match - Quickview requires WooCommerce %s or newer.', 'wc-mnm-quickview' ), self::REQ_WC_VERSION );
			return false;
		}

		if ( ! function_exists( 'wc_mix_and_match' ) || version_compare( wc_mix_and_match()->version, self::REQ_MNM_VERSION ) < 0 ) {
			/* translators: %s: Required Mix and Match version. */
			$this->notice = sprintf( __( 'WC Mix and Match - Quickview requires Mix and Match %s or newer.', 'wc-mnm-quickview' ), self::REQ_MNM_VERSION );
			return false;
		}

		if ( ! self::is_supported() ) {
			$this->notice = class_exists( ContainerStore::class )
				/* translators: %s: Required WordPress version. */
				? sprintf( __( 'WC Mix and Match - Quickview requires WordPress %s or newer.', 'wc-mnm-quickview' ), self::REQ_WP_VERSION )
				/* translators: %s: Required Mix and Match version. */
				: sprintf( __( 'WC Mix and Match - Quickview requires the Mix and Match container store, from Mix and Match %s.', 'wc-mnm-quickview' ), self::REQ_MNM_VERSION );
			return false;
		}

		return true;
	}

	/**
	 * Say why the plugin isn't running.
	 */
	public function admin_notice() {

		if ( ! $this->notice || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $this->notice ) );
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
	 * Register the stylesheet and script module.
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

		if ( ! self::is_supported() ) {
			return;
		}

		$script_path = $this->get_asset_path( 'assets/js/frontend/quick-view.js' );

		// Native ES module, the imports resolve through the import map so there is no build step.
		wp_register_script_module(
			self::MODULE,
			$this->plugin_url( $script_path ),
			array( '@wordpress/interactivity', ContainerStore::MODULE ),
			$this->get_file_version( $script_path )
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
		if ( ! wp_style_is( 'wc-mnm-quickview', 'registered' ) ) {
			$this->register_scripts();
		}

		wp_enqueue_style( 'wc-mnm-quickview' );
		wp_enqueue_script_module( self::MODULE );

		if ( ! $this->needs_modal ) {
			wp_interactivity_state(
				self::STORE,
				array(
					'ajaxUrl'    => \WC_AJAX::get_endpoint( self::AJAX_ACTION ),
					/**
					 * Whether to load every child item's details once the page is idle, so the quick view opens
					 * instantly. When false, they load on the first hover or focus of a child item.
					 *
					 * @since 3.0.0
					 *
					 * @param bool $preload
					 */
					'preloadAll' => (bool) apply_filters( 'wc_mnm_quick_view_preload_all', true ),
					'i18n'       => self::get_i18n(),
				)
			);
		}

		$this->needs_modal = true;
	}

	/*-----------------------------------------------------------------------------------*/
	/* Display */
	/*-----------------------------------------------------------------------------------*/

	/**
	 * Print the modal once, on pages with a Mix and Match form.
	 *
	 * The buttons and text are rendered from the store with Interactivity API directives. The child item's
	 * details are loaded into .wc-mnm-quick-view__content.
	 *
	 * NB: One data-wp-watch per element, WordPress 6.9+ ignores data-wp-watch--suffix.
	 */
	public function print_modal() {

		if ( ! $this->needs_modal ) {
			return;
		}

		$i18n = self::get_i18n();

		ob_start();
		?>
		<dialog
			id="wc-mnm-quick-view"
			class="wc-mnm-quick-view woocommerce"
			aria-labelledby="wc-mnm-quick-view-title"
			data-wp-interactive="<?php echo esc_attr( self::STORE ); ?>"
			data-wp-init="callbacks.init"
			data-wp-on-document--click="actions.onDocumentClick"
			data-wp-on-document--pointerover="actions.onDocumentPointerOver"
			data-wp-on-document--pointerdown="actions.onDocumentIntent"
			data-wp-on-document--focusin="actions.onDocumentIntent"
			data-wp-on--click="actions.onDialogClick"
			data-wp-on--keydown="actions.onKeydown"
			data-wp-on--close="actions.onClose"
			data-wp-class--is-loading="state.isLoading"
			data-wp-bind--aria-busy="state.isLoading"
		>
			<div class="wc-mnm-quick-view__inner" data-wp-watch="callbacks.watchContainers">
				<div class="wc-mnm-quick-view__header">
					<div class="wc-mnm-quick-view__nav" data-wp-bind--hidden="state.hideNav">
						<button type="button" class="wc-mnm-quick-view__prev" aria-label="<?php echo esc_attr( $i18n['previous'] ); ?>" data-wp-bind--aria-label="state.prevLabel" data-wp-bind--disabled="!state.hasPrev" data-wp-on--click="actions.prev"><span aria-hidden="true">&lsaquo;</span></button>
						<button type="button" class="wc-mnm-quick-view__next" aria-label="<?php echo esc_attr( $i18n['next'] ); ?>" data-wp-bind--aria-label="state.nextLabel" data-wp-bind--disabled="!state.hasNext" data-wp-on--click="actions.next"><span aria-hidden="true">&rsaquo;</span></button>
					</div>
					<button type="button" class="wc-mnm-quick-view__close" aria-label="<?php echo esc_attr( $i18n['close'] ); ?>" data-wp-on--click="actions.close"><span aria-hidden="true">&times;</span></button>
				</div>
				<div class="wc-mnm-quick-view__content" data-wp-watch="callbacks.renderContent" data-wp-on--click="actions.onContentClick"></div>
				<p class="wc-mnm-quick-view__error woocommerce-error" role="alert" data-wp-bind--hidden="state.hideError"><?php echo esc_html( $i18n['error'] ); ?></p>
				<div class="wc-mnm-quick-view__footer" data-wp-bind--hidden="state.hideFooter" data-wp-watch="callbacks.watchFocus">
					<div class="wc-mnm-quick-view__status" role="status" aria-live="polite">
						<p class="wc-mnm-quick-view__in-box" data-wp-bind--hidden="state.hideInBox" data-wp-text="state.inBoxText"></p>
						<ul class="wc-mnm-quick-view__messages" data-wp-watch="callbacks.renderMessages"></ul>
					</div>
					<div class="wc-mnm-quick-view__actions">
						<div class="wc-mnm-quick-view__stepper" data-wp-bind--hidden="state.hideStepper">
							<button type="button" class="wc-mnm-quick-view__decrease button wp-element-button" aria-label="<?php echo esc_attr( $i18n['decrease'] ); ?>" data-wp-bind--aria-label="state.decreaseLabel" data-wp-bind--disabled="state.cannotDecrease" data-wp-on--click="actions.decrease">&minus;</button>
							<span class="wc-mnm-quick-view__quantity" aria-hidden="true" data-wp-text="state.quantity"></span>
							<button type="button" class="wc-mnm-quick-view__increase button wp-element-button" aria-label="<?php echo esc_attr( $i18n['increase'] ); ?>" data-wp-bind--aria-label="state.increaseLabel" data-wp-bind--disabled="state.cannotIncrease" data-wp-on--click="actions.increase">+</button>
						</div>
						<button type="button" class="wc-mnm-quick-view__add button alt wp-element-button" data-wp-bind--hidden="state.hideAdd" data-wp-bind--disabled="state.cannotIncrease" data-wp-class--disabled="state.cannotIncrease" data-wp-bind--aria-label="state.addLabel" data-wp-text="state.labels.addToBox" data-wp-on--click="actions.add"></button>
						<button type="button" class="wc-mnm-quick-view__remove button wp-element-button" data-wp-bind--hidden="state.hideRemove" data-wp-bind--aria-label="state.removeLabel" data-wp-text="state.labels.removeFromBox" data-wp-on--click="actions.decrease"></button>
					</div>
				</div>
			</div>
		</dialog>
		<?php
		// Render the directives on the server too, so nothing that should be hidden shows before the module loads.
		echo wp_interactivity_process_directives( ob_get_clean() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
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

		// The container's button text. The item's IDs, name and stock come from the container store.
		// The script adds aria-haspopup, wp_kses_post() would remove it here.
		$data = array(
			'addToBox'      => self::get_add_to_box_text( $container ),
			'removeFromBox' => self::get_remove_from_box_text( $container ),
		);

		$button = sprintf(
			'<button type="button" class="wc-mnm-quick-view-button" data-wc-mnm-quick-view="%1$s" aria-label="%2$s"><span class="wc-mnm-quick-view-button__text">%3$s</span></button>',
			esc_attr( wp_json_encode( $data ) ),
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
				'placeholder' => esc_attr_x( 'Add', '[Frontend]', 'wc-mnm-quickview' ),
				'value'       => $container->get_meta( self::META_ADD_TEXT ),
				'desc_tip'    => true,
				'description' => esc_html__( 'Text of the button that adds a product to this container from its quick view, ex: Add to bundle. Leave blank for the default.', 'wc-mnm-quickview' ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'          => self::META_REMOVE_TEXT,
				'label'       => esc_html__( 'Quick view remove button text', 'wc-mnm-quickview' ),
				'placeholder' => esc_attr_x( 'Remove', '[Frontend]', 'wc-mnm-quickview' ),
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
		return self::is_supported() && ! self::is_admin_request() && (bool) apply_filters( 'wc_mnm_has_quick_view', true, $container );
	}

	/**
	 * Whether the Mix and Match container store and the Interactivity API are available.
	 *
	 * @return bool
	 */
	public static function is_supported() {
		return class_exists( ContainerStore::class ) && ContainerStore::is_supported();
	}

	/**
	 * Whether this is an admin page, or an ajax request from one, ex: editing an order item.
	 *
	 * The container store isn't loaded in the admin.
	 *
	 * @return bool
	 */
	protected static function is_admin_request() {

		if ( ! is_admin() ) {
			return false;
		}

		return ! wp_doing_ajax() || 0 === strpos( (string) wp_get_referer(), admin_url() );
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
		return (string) apply_filters( 'wc_mnm_quick_view_add_to_box_text', '' !== $text ? $text : _x( 'Add', '[Frontend]', 'wc-mnm-quickview' ), $container );
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
		return (string) apply_filters( 'wc_mnm_quick_view_remove_from_box_text', '' !== $text ? $text : _x( 'Remove', '[Frontend]', 'wc-mnm-quickview' ), $container );
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
			'inBox'         => _x( 'Selected: %d', '[Frontend]', 'wc-mnm-quickview' ),
			'included'      => _x( 'Included', '[Frontend]', 'wc-mnm-quickview' ),
			'unavailable'   => _x( 'This product can\'t be added to your box.', '[Frontend]', 'wc-mnm-quickview' ),
			'full'          => _x( 'Your box is full. Remove an item to make room.', '[Frontend]', 'wc-mnm-quickview' ),
			'error'         => _x( 'Sorry, this product could not be loaded.', '[Frontend]', 'wc-mnm-quickview' ),
			'close'         => _x( 'Close', '[Frontend]', 'wc-mnm-quickview' ),
			'decrease'      => _x( 'Remove one', '[Frontend]', 'wc-mnm-quickview' ),
			'increase'      => _x( 'Add one more', '[Frontend]', 'wc-mnm-quickview' ),
			'previous'      => _x( 'Previous product', '[Frontend]', 'wc-mnm-quickview' ),
			'next'          => _x( 'Next product', '[Frontend]', 'wc-mnm-quickview' ),
			/* translators: %1$s: Button text, ex: Add. %2$s: Child product title. */
			'buttonLabel'   => _x( '%1$s %2$s', '[Frontend]', 'wc-mnm-quickview' ),
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
