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

    <div id="primary">

        <?php
       
       $args = array(  
        'post_type' => 'student',
        'post_status' => 'publish',
        'posts_per_page' => 8, 
        'orderby' => 'title', 
        'order' => 'ASC', 
    );

    $loop = new WP_Query( $args ); 
        
    while ( $loop->have_posts() ) : $loop->the_post(); 
    echo 1;
        // print the_title(); 
        // the_excerpt(); 
    endwhile;
    
    ?>
    </div>
</body>

</html>