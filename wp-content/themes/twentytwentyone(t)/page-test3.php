<?php
/**
 * The template for displaying all single posts
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/#single-post
 *
 * @package WordPress
 * @subpackage Twenty_Twenty_One
 * @since Twenty Twenty-One 1.0
 */

get_header();

?>


<?php
/* Start the Loop */

$query = new WP_Query(
    array(
        'post_type' => 'student'
        // 'meta_key' => '_wp_page_template',
        // 'meta_value' => 'my_template.php'
    )
);




while ( $query->have_posts() ) :
	$query->the_post();
	get_template_part( 'template-parts/content/content-page' );

	// If comments are open or there is at least one comment, load up the comment template.
	if ( comments_open() || get_comments_number() ) {
		comments_template();
	}
endwhile; // End of the loop.

get_footer();