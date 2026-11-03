<?php
/**
 * Mix and Match child item quick view content
 *
 * The product details shown in the quick view modal.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/single-product/mnm/quick-view-content.php.
 *
 * HOWEVER, on occasion WooCommerce Mix and Match will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * NB: Keep the #wc-mnm-quick-view-title ID on the title, the modal is labelled by it. Thumbnails
 * with the wc-mnm-quick-view__thumbnail class swap the main image.
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package WC Mix and Match Quickview/Templates
 * @since   3.0.0
 * @version 3.0.0
 *
 * @var WC_MNM_Child_Item        $child_item
 * @var WC_Product_Mix_and_Match $container
 * @var WC_Product               $product           The child product, can be a variation.
 * @var WC_Product               $parent_product    The variation's parent, or the child product.
 * @var string                   $title
 * @var string                   $permalink         Empty when the product isn't visible on its own. Not shown by default.
 * @var int[]                    $image_ids         The main image first.
 * @var string                   $short_description
 * @var string                   $description
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$main_image_id = $image_ids ? $image_ids[0] : 0;
?>
<div class="wc-mnm-quick-view__product product">

	<div class="wc-mnm-quick-view__gallery">

		<figure class="wc-mnm-quick-view__image">
			<?php
			echo $main_image_id
				? wp_get_attachment_image( $main_image_id, 'woocommerce_single' )
				: wc_placeholder_img( 'woocommerce_single' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
		</figure>

		<?php if ( count( $image_ids ) > 1 ) : ?>
			<ul class="wc-mnm-quick-view__thumbnails">
				<?php foreach ( $image_ids as $index => $image_id ) : ?>
					<?php $image_src = wp_get_attachment_image_src( $image_id, 'woocommerce_single' ); ?>
					<li>
						<button
							type="button"
							class="wc-mnm-quick-view__thumbnail<?php echo 0 === $index ? ' is-active' : ''; ?>"
							aria-pressed="<?php echo 0 === $index ? 'true' : 'false'; ?>"
							data-src="<?php echo esc_url( $image_src ? $image_src[0] : '' ); ?>"
							data-srcset="<?php echo esc_attr( (string) wp_get_attachment_image_srcset( $image_id, 'woocommerce_single' ) ); ?>"
							data-alt="<?php echo esc_attr( (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) ); ?>"
						>
							<?php
							// translators: %d: Image number.
							echo '<span class="screen-reader-text">' . esc_html( sprintf( _x( 'Show image %d', '[Frontend]', 'wc-mnm-quickview' ), $index + 1 ) ) . '</span>';
							echo wp_get_attachment_image( $image_id, 'woocommerce_gallery_thumbnail', false, array( 'alt' => '' ) );
							?>
						</button>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

	</div>

	<div class="wc-mnm-quick-view__summary">

		<?php
		/**
		 * Before the quick view summary.
		 *
		 * @since 3.0.0
		 *
		 * @param WC_MNM_Child_Item        $child_item
		 * @param WC_Product_Mix_and_Match $container
		 */
		do_action( 'wc_mnm_quick_view_before_summary', $child_item, $container );
		?>

		<h2 id="wc-mnm-quick-view-title" class="product_title entry-title"><?php echo wp_kses_post( $title ); ?></h2>

		<?php
		wc_mnm_template_child_item_price( $child_item, $container );

		echo wp_kses_post( wc_get_stock_html( $product ) );
		?>

		<?php if ( $short_description ) : ?>
			<div class="woocommerce-product-details__short-description">
				<?php echo apply_filters( 'woocommerce_short_description', $short_description ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		<?php endif; ?>

		<?php if ( $description ) : ?>
			<div class="wc-mnm-quick-view__description">
				<h3><?php echo esc_html_x( 'Description', '[Frontend]', 'wc-mnm-quickview' ); ?></h3>
				<?php echo wc_format_content( wp_kses_post( $description ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		<?php endif; ?>

		<?php
		ob_start();
		wc_display_product_attributes( $parent_product );
		$attributes = ob_get_clean();
		?>

		<?php if ( false !== strpos( $attributes, '<tr' ) ) : ?>
			<div class="wc-mnm-quick-view__attributes">
				<h3><?php echo esc_html_x( 'Additional information', '[Frontend]', 'wc-mnm-quickview' ); ?></h3>
				<?php echo $attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		<?php endif; ?>

		<?php
		/**
		 * After the quick view summary.
		 *
		 * @since 3.0.0
		 *
		 * @param WC_MNM_Child_Item        $child_item
		 * @param WC_Product_Mix_and_Match $container
		 */
		do_action( 'wc_mnm_quick_view_after_summary', $child_item, $container );
		?>

	</div>
</div>
