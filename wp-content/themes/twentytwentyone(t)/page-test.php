<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
</head>

<body>
    <!-- fdfdsafa -->

    <?php echo do_shortcode('[fe_widget title="Filters" id="24" show_selected="yes" show_count="yes"] '); ?>
    <?php //echo do_shortcode('[fe_widget  id="24" show_selected="yes" ] '); ?>


    <!-- [fe_widget title="Filters" id="26" show_selected="yes" show_count="yes"] -->

    <!-- <div id="testing"></div> -->

    <div>

        <?php    while ( have_posts() ) : ?>
        <?php the_post(); ?>
        <?php 
        echo 1;
        //get_template_part( 'template-parts/content/content', get_theme_mod( 'display_excerpt_or_full_post', 'excerpt' ) ); ?>
        <?php endwhile; ?>
    </div>
</body>

</html>