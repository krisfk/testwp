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
// $paged = ( get_query_var( 'paged' ) ) ? get_query_var( 'paged' ) : 1;

$query = new WP_Query(
    array(
        'post_type' => 'student', 
		// 'posts_per_page' => 2,
		// 'paged' => $paged

        // 'meta_key' => '_wp_page_template',
        // 'meta_value' => 'my_template.php'
    )
);


// [fe_widget title="Filters" id="24" show_selected="yes" show_count="yes"]
?>
<div id="students-div">

    <?php
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
?>
    <!-- <div class="pagination">
        <?php 
        echo paginate_links( array(
            'base'         => str_replace( 999999999, '%#%', esc_url( get_pagenum_link( 999999999 ) ) ),
            'total'        => $query->max_num_pages,
            'current'      => max( 1, get_query_var( 'paged' ) ),
            'format'       => '?paged=%#%',
            'show_all'     => false,
            'type'         => 'plain',
            'end_size'     => 2,
            'mid_size'     => 1,
            'prev_next'    => true,
            'prev_text'    => sprintf( '<i></i> %1$s', __( 'Newer Posts', 'text-domain' ) ),
            'next_text'    => sprintf( '%1$s <i></i>', __( 'Older Posts', 'text-domain' ) ),
            'add_args'     => false,
            'add_fragment' => '',
        ) );
    ?>
    </div> -->
</div>
<?php
get_footer();