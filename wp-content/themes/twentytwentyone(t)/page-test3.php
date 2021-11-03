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

<?php echo do_shortcode('[fe_widget title="Filters" id="24" show_selected="yes" show_count="yes"] '); ?>

<?php
/* Start the Loop */

$query = new WP_Query(
    array(
        'post_type' => 'student'
        // 'meta_key' => '_wp_page_template',
        // 'meta_value' => 'my_template.php'
    )
);


// [fe_widget title="Filters" id="24" show_selected="yes" show_count="yes"]

while ( $query->have_posts() ) :
	// echo 1;
	$query->the_post();
	// get_template_part( 'template-parts/content/content-page' );
	?>
<br>
<?php

	echo get_the_title();
	// If comments are open or there is at least one comment, load up the comment template.
	// if ( comments_open() || get_comments_number() ) {
	// 	comments_template();
	// }
endwhile; // End of the loop.

get_footer();