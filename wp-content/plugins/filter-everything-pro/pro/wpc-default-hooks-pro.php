<?php

if ( ! defined('WPINC') ) {
    wp_die();
}

add_filter('wpc_seo_vars_list', 'flrt_add_seo_vars');
function flrt_add_seo_vars( $seo_vars )
{
    $seo_vars['site_title']  = '{site_title}';
    $seo_vars['page_number'] = '{page_number}';
    return $seo_vars;
}

function flrt_remove_wpseo()
{
    $all_wp_hooks   = flrt_print_filters_for('wp_head' );
    $hooksToDisable = [];

    // Disable Squirrly SEO
    remove_all_filters( 'sq_buffer' );

    // Disable Rank Math SEO, All in One SEO, Yoast SEO, The SEO Framework, SEOPress on filtering pages
    if( ! empty( $all_wp_hooks->callbacks ) && $all_wp_hooks->callbacks ){
        foreach ( $all_wp_hooks->callbacks as $priority => $hooks ){
            if( is_array( $hooks ) ){
                foreach ($hooks as $function => $args ){

                    if( strpos( $function, 'call_wpseo_head' ) !== false
                        && isset( $args['function'][0] )
                        && ( $args['function'][0] instanceof Yoast\WP\SEO\Integrations\Front_End_Integration )
                    ){
                        $hooksToDisable[$function] = $priority;
                    }

                    if( strpos( $function, 'head' ) !== false
                        && isset( $args['function'][0] )
                        && ( $args['function'][0] instanceof RankMath\Frontend\Head )
                    ){
                        $hooksToDisable[$function] = $priority;
                    }

                    if( strpos( $function, 'init' ) !== false
                        && isset( $args['function'][0] )
                        && ( $args['function'][0] instanceof AIOSEO\Plugin\Common\Main\Head )
                    ){
                        $hooksToDisable[$function] = $priority;
                    }

                    if( strpos( $function, 'html_output' ) !== false
                        && isset( $args['function'][0] )
                        && ( $args['function'][0] instanceof The_SEO_Framework\Load )
                    ){
                        $hooksToDisable[$function] = $priority;
                    }

                    if( strpos( $function, 'seopress' ) !== false ){
                        $hooksToDisable[$function] = $priority;
                    }

                    if( strpos( $function, 'render' ) !== false
                        && isset( $args['function'][0] )
                        && ( $args['function'][0] instanceof SEOPress\Actions\Front\Metas\DescriptionMeta
                            ||
                            $args['function'][0] instanceof SEOPress\Actions\Front\Schemas\PrintHeadJsonSchema
                        )
                    ){
                        $hooksToDisable[$function] = $priority;
                    }

                }
            }
        }
    }

    if( ! empty( $hooksToDisable ) ){
        foreach ( $hooksToDisable as $hookName => $priority ){
            remove_action('wp_head', $hookName, $priority );
        }
    }

}

if( ! function_exists('flrt_lowercase_seo_vars') ){
    add_filter('wpc_seo_var_term_name', 'flrt_lowercase_seo_vars', 10, 2);
    function flrt_lowercase_seo_vars( $termName, $e_name )
    {
        $do_not_strtolower = flrt_get_option( 'terms_with_capital_letter' );
        $do_not_strtolower = array_map( 'trim', explode(',', $do_not_strtolower ) );

        if( ! empty( $do_not_strtolower ) ){

            foreach ( $do_not_strtolower as $e_name_as_is ){
                if( $e_name_as_is ){
                    if( mb_strpos( $e_name, $e_name_as_is ) !== false ){
                        return $termName;
                    }
                }
            }

        }

        return strtolower( $termName );
    }
}

if( ! function_exists('flrt_term_rating_stars') ){
    add_filter( 'wpc_filters_checkbox_term_html', 'flrt_term_rating_stars', 10, 4 );
    add_filter( 'wpc_filters_radio_term_html', 'flrt_term_rating_stars', 10, 4 );

    function flrt_term_rating_stars($html, $link_attributes, $term, $filter)
    {
        $rating_slugs = array(
            'rated-1',
            'rated-2',
            'rated-3',
            'rated-4',
            'rated-5'
        );

        if( $filter['e_name'] !== 'product_visibility' ){
            return $html;
        }

        if( ! isset( $term->slug ) ){
            return $html;
        }

        if( ! in_array( $term->slug, $rating_slugs, true ) ){
            return $html;
        }

        $rating = 0;
        if( mb_strpos( $term->slug, 'rated-' ) !== false){
            $pieces = explode("-", $term->slug);
            $rating = isset( $pieces[1] ) ? $pieces[1] : 0;
        }

        $rating_html = '<div class="star-rating"><span style="width:' . esc_attr( $rating * 20 ) . '%">' . sprintf( esc_html__( '%s out of 5', 'woocommerce' ), esc_html( $rating ) ) . '</span></div>';

        $html = '<a '.$link_attributes.'>'.$rating_html.'</a>';

        return $html;
    }
}

add_filter( 'wpc_settings_field_checkbox', 'flrt_collapse_widget_checkbox_handler', 10, 2 );
function flrt_collapse_widget_checkbox_handler($checkbox, $args )
{
    if( isset($args['id']) && $args['id'] === 'show_open_close_button' ){
        if(flrt_get_option('show_bottom_widget') === 'on' ){
            $checkbox = '<label class="wpc-inactive-settings-field"><input type="checkbox" name="%s[%s]" %s id="%s">%s</label>';
        }
    }
    return $checkbox;
}

add_filter( 'wpc_filters_checkbox_term_html', 'wpc_term_brand_logo', 10, 4 );
add_filter( 'wpc_filters_radio_term_html', 'wpc_term_brand_logo', 10, 4 );
if( ! function_exists('wpc_term_brand_logo') ) {
    function wpc_term_brand_logo($html, $link_attributes, $term, $filter)
    {
        $img = '';
        if ($filter['e_name'] !== 'pa_brand') {
            return $html;
        }
        if (!isset($term->slug)) {
            return $html;
        }
        $src = get_term_meta($term->term_id, 'image', true);
        if ($src) {
            $img = '<span class="wpc-term-image-wrapper"><span class="wpc-term-image-container" style="background-image: url(' . $src . ')"></span></span>';
            $html = '<a ' . $link_attributes . '>' . $img . ' ' . $term->name . '</a>';
        }
        return $html;
    }
}