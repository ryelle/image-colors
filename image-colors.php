<?php
/**
 * Plugin Name:       Image Colors
 * Description:       A proof of concept for 2017
 * Version:           1.0.0
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Get the luminosity of a color, rep'd by an array with 'r', 'g', 'b'.
 *
 * @param array $rgb RGB values (0-255) for this color.
 * @return float The luminosity of this color, between 0 and 1.
 */
function kdic_get_luminosity( $rgb ) {
	$lum = array();
	foreach( $rgb as $slot => $value ) {
		$chan = $value / 255;
		$lum[ $slot ] = ( $chan <= 0.03928 ) ? $chan / 12.92 : pow( ( ( $chan + 0.055 ) / 1.055 ), 2.4 );
	}
	return 0.2126 * $lum['r'] + 0.7152 * $lum['g'] + 0.0722 * $lum['b'];
}

/**
 * Use ImageMagick to resize then fetch the average image color.
 * Intial code from https://manu.ninja/dominant-colors-for-lazy-loading-images
 *
 * @param string $file The path to the image file
 * @return array An array with the selected color and a contrast (white or black).
 */
function kdic_generate_image_colors( $file ) {
	if ( class_exists( 'Imagick' ) ) {
		$image = new Imagick( $file );
		$image->resizeImage( 250, 250, Imagick::FILTER_GAUSSIAN, 1 );
		$image->quantizeImage( 1, Imagick::COLORSPACE_RGB, 0, false, false );
		$image->setFormat( 'RGB' );
		$color = substr( bin2hex( $image ), 0, 6);

		// The image has been reduced to only 1 color, so the pixel at 0,0
		// is representative of the whole image. Use the `ImagickPixel` code
		// to get the luminosity of this image.
		$pixel = $image->getImagePixelColor( 0, 0 )->getColor();
		$contrast = 'white';
		if ( $pixel && isset( $pixel['r'] ) ) {
			$luminosity = kdic_get_luminosity( $pixel );
			$lumdiff_white = 1.05 / ( $luminosity + 0.05 );
			$lumdiff_black = ( $luminosity + 0.05 ) / 0.05;
			$contrast = ( $lumdiff_white <= $lumdiff_black ) ? 'black' : 'white';
		}

		return array(
			'average'  => '#' . $color,
			'contrast' => $contrast,
		);
	}
}

/**
 * Get the color info based on the featured image of a post. For 2017, this could be swapped out
 * with a function to get the color/contrast of the site's header image - this would also reduce
 * the number of calls to `kdic_generate_image_colors`.
 *
 * @param int $post_id The current post.
 * @return array An array with the selected color and a contrast (white or black) for this post.
 */
function kdic_get_image_colors( $post_id ) {
	$attachment = get_post_thumbnail_id( $post_id );
	if ( ! $attachment ) {
		return false;
	}
	$color = get_post_meta( $attachment, 'kdic_color_info', true );
	if ( ! $color ) {
		$color = kdic_save_colors( $attachment );
	}
	return $color;
}

/**
 * Hook into the image upload process to generate the color info on upload. Saves the info
 * in post_meta called `kdic_color_info`
 *
 * @param int $post_id The current post.
 * @return array An array with the selected color and a contrast (white or black) for this post.
 */
function kdic_save_colors( $post_id ) {
	$attachment = get_post( $post_id );
	if ( 'attachment' !== $attachment->post_type || false === strpos( $attachment->post_mime_type, 'image' ) ) {
		return;
	}

	$attachment_src = wp_get_attachment_image_src( $post_id, 'full' );
	if ( ! $attachment_src || ! ( $file = $attachment_src[0] ) ) {
		return;
	}

	$color = kdic_generate_image_colors( $file );
	update_post_meta( $post_id, 'kdic_color_info', $color );
	return $color;
}
add_action( 'add_attachment', 'kdic_save_colors' );

// =================================================
// Proof of concept, color the background on 2015.
// =================================================
$kdic_styles = '';

function kdic_the_content() {
	global $kdic_styles;
	$id = get_the_ID();
	$color = kdic_get_image_colors( $id );
	if ( $color ) {
		$kdic_styles .= '.post-' . $id . ' .post-thumbnail { background-color: ' . $color['average'] . '; border-style: solid; border-width: 0 20px; border-color: ' . $color['average'] . '; }' . "\n";
	}
}
add_action( 'the_post', 'kdic_the_content' );

function kdic_print_styles() {
	global $kdic_styles;
	echo '<style type="text/css">' . $kdic_styles . '</style>';
}
add_action( 'wp_footer', 'kdic_print_styles' );
