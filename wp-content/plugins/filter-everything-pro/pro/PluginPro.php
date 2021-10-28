<?php


namespace FilterEverything\Filter\Pro;

if ( ! defined('WPINC') ) {
    wp_die();
}

use Elementor\Plugin;
use FilterEverything\Filter\AuthorEntity;
use FilterEverything\Filter\Container;
use FilterEverything\Filter\FilterSet;
use FilterEverything\Filter\PostMetaEntity;
use FilterEverything\Filter\PostMetaNumEntity;
use FilterEverything\Filter\Pro\Entities\PostMetaExistsEntity;
use FilterEverything\Filter\TaxonomyEntity;
use FilterEverything\Filter\UrlManager;
use FilterEverything\Filter\Pro\ShortcodesPro;

class PluginPro
{
    public function __construct()
    {
        add_action( 'pre_get_posts', [$this, 'burpOutAllWpQueries'], 9999 );

        add_action('wp_ajax_wpc-get-set-location-terms', [$this, 'sendSetLocationTerms']);

        add_filter('wpc_relevant_set_ids', [$this, 'findRelevantSetsPro'], 10, 2);
        add_filter('wpc_is_filtered_query', [$this, 'isFilteredQueryPro'], 10, 2);

        add_action('body_class', array($this, 'bodyClass'));

        add_filter('wpc_filter_set_default_fields', [$this, 'filterSetDefaultFields'], 10, 2);
        add_filter('wpc_filter_default_fields', [$this, 'filterDefaultFields'], 10, 2);

        add_filter('wpc_prepare_filter_set_parameters', [$this, 'prepareSetParameters'], 10, 2);
        add_filter('wpc_filter_before_make_default_set_values', [$this, 'legacyPrepareWpPageTypeValue'] );

        // Validation entities
        add_filter('wpc_validation_wp_page_type_entities', [$this, 'validationWpPageTypeEntities'] );
        add_filter('wpc_validation_location_entities', [$this, 'validationLocationEntities'], 10, 2);

        add_action( 'wpc_before_filter_set_settings_fields', [$this, 'showLocationFields'] );

        add_filter('manage_edit-' . FLRT_FILTERS_SET_POST_TYPE . '_columns', array($this, 'filterSetPostTypeCol'));
        add_action('manage_' . FLRT_FILTERS_SET_POST_TYPE . '_posts_custom_column', array($this, 'filterSetPostTypeColContent'), 10, 2);

        add_filter('wpc_possible_entities', [$this, 'postMetaExistsEntity']);
        add_action('template_redirect', [$this, 'wpInit']);

        add_filter( 'paginate_links', [ $this, 'filtersPaginationLink' ] );

        // Get variations map
        if( flrt_is_woocommerce() ){
            add_filter( 'get_meta_sql', [$this, 'postMetaExistsSql'], 10, 6 );
            add_filter( 'posts_where', [$this, 'taxQueryVariationsSql'], 10, 2 );

            add_action( 'wpc_related_set_ids', [ $this, 'setVariationsMap' ] );
            add_filter( 'wpc_min_and_max_values_post_meta_num', [ $this, 'replaceProductIdsWithVariationIdsForPostMetaNum' ], 10, 2 );
            // Replace product IDs with their variation IDs
            add_filter( 'wpc_items_before_calc_term_count', [$this, 'replaceProductIdsWithVariationIdsForTerms'], 10, 3 );
            add_filter( 'wpc_items_after_calc_term_count', [$this, 'replaceBackProductIdsWithVariationIdsForTerms'] );

            add_filter( 'wpc_from_products_to_variations', [$this, 'replaceProdIdsVarIds'] );

            // Replace back variation IDs with Product IDs
            add_filter( 'wpc_from_variations_to_products', [ $this, 'replaceBackProdIdsVarIds' ] );

        }

        $woo_shortcodes = array(
            'products',
            'featured_products',
            'sale_products',
            'best_selling_products',
            'recent_products',
            'product_attribute',
            'top_rated_products'
        );

        // Fix caching problem for products queried by shortcode
        foreach ( $woo_shortcodes as $woo_shortcode ){
            add_filter( "shortcode_atts_{$woo_shortcode}", [$this, 'disableCacheProductsShortcode'] );
        }

        new ShortcodesPro();
    }

    public function replaceBackProdIdsVarIds( $post_ids )
    {
        if( ! empty( $post_ids ) ){
            $variations_map  = $this->getVariationsMap();
            if( empty( $variations_map ) ){
                return $post_ids;
            }

            $post_ids = array_flip( $post_ids );
            $replaced_post_ids = [];
            foreach ( $post_ids as $post_id ){
                if( in_array( $post_id, array_keys($variations_map) ) ){
                    $replaced_post_ids[] = (int) $variations_map[$post_id];
                }else{
                    $replaced_post_ids[] = (int) $post_id;
                }
            }

            $post_ids = array_unique( $replaced_post_ids );
        }

        return $post_ids;
    }

    public function replaceProdIdsVarIds( $allPostsIds )
    {
        if( ! empty( $allPostsIds ) ){
            $variations_map  = $this->getVariationsMap();
            if( empty( $variations_map ) ){
                return $allPostsIds;
            }

            $variations_reverse_map = [];
            foreach ( $variations_map as $variation_id => $parent_id ){
                $variations_reverse_map[$parent_id][] = (int) $variation_id;
            }

            $replaced_posts = [];
            $reverse_posts = array_keys($allPostsIds);

            foreach ( $reverse_posts as $post_id ){
                if( in_array( $post_id, array_keys($variations_reverse_map) ) ){
                    foreach ( $variations_reverse_map[$post_id] as $var_id ){
                        $replaced_posts[] = (int) $var_id;
                    }
                }else{
                    $replaced_posts[] = (int) $post_id;
                }
            }

            $allPostsIds = array_flip($replaced_posts);

        }
        return $allPostsIds;
    }

    /**
     * Replace product IDs with their variation IDs to correctly compare variations
     * @param $entity_items
     * @param $entity
     * @param $used_for_variations
     * @return array
     */
    public function replaceProductIdsWithVariationIdsForTerms( $entity_items, $entity, $used_for_variations )
    {
        $variations_map  = $this->getVariationsMap();

        if( empty( $variations_map ) ){
            return $entity_items;
        }

        $variations_reverse_map = [];
        foreach ( $variations_map as $variation_id => $parent_id ){
            $variations_reverse_map[$parent_id][] = (int) $variation_id;
        }

        if( $entity instanceof TaxonomyEntity){

            $is_pa_attribute = ( strpos( $entity->getName(), 'pa_' ) === 0 );

            if( $is_pa_attribute ){
                $attribute_meta_key = 'attribute_'.$entity->getName();
                // variation_id=>meta_value pairs
                $varitaions_meta_terms = $this->getVariationsByMetaKey($attribute_meta_key);
            }

            $new_entity_items = [];

            foreach ( $entity_items as $in => $term_object ){
                $new_posts = [];
                foreach ( $term_object->posts as $inn => $post_id ){
                    // Variable product
                    if( in_array( $post_id, array_keys( $variations_reverse_map ) ) ){
                        foreach ( $variations_reverse_map[$post_id] as $var_id ){

                            if( $is_pa_attribute && $used_for_variations === 'yes' ){
                                // Add it to term_ids list only if variation has attribute_pa_...
                                if( isset( $varitaions_meta_terms[$var_id] ) ){
                                    if( $term_object->slug == $varitaions_meta_terms[$var_id] ){
                                        $new_posts[] = (int) $var_id;
                                    }
                                }
                            }else{
                                $new_posts[] = (int) $var_id;
                            }

                        }
                    //  Non-variable product or variable product without variations
                    }else{
                        $new_posts[] = (int) $post_id;
                    }
                }
                $term_object->posts     = $new_posts;
                $new_entity_items[$in]  = $term_object;

            }

            $entity_items = $new_entity_items;


        }else if( $entity instanceof PostMetaEntity || $entity instanceof PostMetaExistsEntity || $entity instanceof PostMetaNumEntity) {
            // Post meta terms contain parent product IDs and variation IDs
//            $variations_allowed_keys = flrt_get_allowed_variation_meta_keys();
            // If not allowed keys, we do not have to do anything
            if( $used_for_variations === 'yes' ){

                $new_entity_items = [];
                foreach ( $entity_items as $in => $term_object ){
                    $new_posts = [];
                    foreach ( $term_object->posts as $inn => $post_id ){
                        $to_leave = true;
                        // Variable product
                        if( in_array( $post_id, array_keys( $variations_reverse_map ) ) ){
                            foreach ( $variations_reverse_map[$post_id] as $var_id ){
                                if( in_array( $var_id, $term_object->posts ) ){
                                    // Do not leave parent product ID if its variation already exists
                                    // in posts list
                                    $to_leave = false;
                                    break;
                                }
                            }

                            if( $to_leave ){
                                $new_posts[] = (int) $post_id;
                            }
                        // Non-variable product or variable product without variations
                        }else{
                            $new_posts[] = (int) $post_id;
                        }

                    }
                    $term_object->posts     = $new_posts;
                    $new_entity_items[$in]  = $term_object;
                }

                $entity_items = $new_entity_items;

            }else{
                // In case if it is meta key, that is not Use for Variations
                // we have to remove all variation IDs from the term posts list
                $new_entity_items = [];
                $variations_reverse_map_keys = array_keys( $variations_reverse_map );
                //@todo PostMetaExists Entity requires also post_types
                foreach ( $entity_items as $in => $term_object ){
                    $new_posts = [];
                    foreach ( $term_object->posts as $inn => $post_id ){

                        // Variable product
                        if( in_array( $post_id, $variations_reverse_map_keys ) ){
                            foreach ( $variations_reverse_map[$post_id] as $var_id ){
                                $new_posts[] = (int) $var_id;
                            }

                        // Non-variable product or variable product without variations or variation id
                        }else{
                            if( isset( $variations_map[$post_id] ) ){
                                $parent_id = $variations_map[$post_id];
                                if( ! isset( $term_object->post_types[$parent_id] ) ){
                                    continue;
                                }
                            }
                            $new_posts[] = (int) $post_id;
                        }
                    }

                    $term_object->posts     = $new_posts;
                    $new_entity_items[$in]  = $term_object;
                }

                $entity_items = $new_entity_items;
            }
        }else if( $entity instanceof AuthorEntity ){

            $new_entity_items = [];
            $variations_reverse_map_keys = array_keys( $variations_reverse_map );
            foreach ( $entity_items as $in => $term_object ){
                $new_posts = [];
                foreach ( $term_object->posts as $inn => $post_id ){
                    // Variable product
                    if( in_array( $post_id, $variations_reverse_map_keys ) ){
                        foreach ( $variations_reverse_map[$post_id] as $var_id ){
                            $new_posts[] = (int) $var_id;
                        }

                    // Non-variable product or variable product without variations
                    }else{
                        $new_posts[] = (int) $post_id;
                    }

                }
                $term_object->posts     = $new_posts;
                $new_entity_items[$in]  = $term_object;
            }

            $entity_items = $new_entity_items;

        }

        return $entity_items;
    }

    public function replaceProductIdsWithVariationIdsForPostMetaNum( $meta_num_posts, $entity )
    {
        $em = Container::instance()->getEntityManager();
        $filter = $em->getFilterBy('e_name', $entity->getName(), array('used_for_variations'));

        if( isset($filter['used_for_variations']) && $filter['used_for_variations'] === 'yes' ){
            $meta_num_posts = apply_filters( 'wpc_from_products_to_variations', array_flip( $meta_num_posts ) );
            $meta_num_posts = array_flip($meta_num_posts);
        }

        return $meta_num_posts;
    }

    public function replaceBackProductIdsWithVariationIdsForTerms( $entity_items )
    {
        $variations_map  = $this->getVariationsMap();
        if( empty( $variations_map ) ){
            return $entity_items;
        }

        $new_entity_items = [];
        foreach ( $entity_items as $in => $term_object ){
            $new_posts = [];
            foreach ( $term_object->posts as $inn => $post_id ){
                // Variable product
                if( in_array( $post_id, array_keys( $variations_map ) ) ){
                    $new_posts[] = (int) $variations_map[$post_id];
                }else{
                    $new_posts[] = (int) $post_id;
                }
            }
            $term_object->posts     = array_unique( $new_posts );
            $new_entity_items[$in]  = $term_object;

        }

        return $entity_items;
    }

    public function setVariationsMap( $sets )
    {
        global $wpdb;
        $is_products = false;
        $variations_map = [];
        $container = Container::instance();

        foreach ( $sets as $set ){
            if( $set['filtered_post_type'] === 'product' ){
                $is_products = true;
                break;
            }
        }

        if( ! $is_products ){
            return false;
        }

        $key = 'product_variations_map';
        if( ! $variations_map = $container->getParam( $key ) ) {

            $sql[] = "SELECT {$wpdb->posts}.ID, {$wpdb->posts}.post_parent";
            $sql[] = "FROM {$wpdb->posts}";
            $sql[] = "WHERE {$wpdb->posts}.post_type = 'product_variation'";

            $sql = implode(" ", $sql);
            $results = $wpdb->get_results($sql);

            if (!empty($results)) {
                foreach ($results as $single_result) {
                    $variations_map[$single_result->ID] = $single_result->post_parent;
                }
            }

            $container->storeParam($key, $variations_map);
        }
    }

    /**
     * @return array|mixed pairs variation_id=>parent_id
     */
    public function getVariationsMap()
    {
        $container = Container::instance();
        $key = 'product_variations_map';
        $variations_map = $container->getParam( $key );

        if( ! $variations_map ){
            return [];
        }

        return $variations_map;
    }

    public function getVariationsByMetaKey( $meta_key ){
        global $wpdb;
        $list = [];

        $sql[] = "SELECT /*{$wpdb->posts}.post_parent,*/ {$wpdb->posts}.ID, {$wpdb->postmeta}.meta_value";
        $sql[] = "FROM {$wpdb->posts}";
        $sql[] = "LEFT JOIN {$wpdb->postmeta}";
        $sql[] = $wpdb->prepare( "ON ( {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id AND {$wpdb->postmeta}.meta_key = %s )", $meta_key);
        $sql[] = "WHERE {$wpdb->posts}.post_type = 'product_variation'";
        $sql[] = "AND {$wpdb->postmeta}.meta_value IS NOT NULL";

        $sql = implode(" ", $sql);

        $variations =  $wpdb->get_results( $sql, ARRAY_A );

        if( ! empty( $variations ) ){
            foreach ( $variations as $pair ){
                $list[ $pair['ID'] ] = $pair['meta_value'];
            }
        }

        return $list;
    }

    public function getVariationProductsMap(){
        global $wpdb;
        $list = [];

        $sql[] = "SELECT {$wpdb->posts}.post_parent, {$wpdb->posts}.ID";
        $sql[] = "FROM {$wpdb->posts}";
        $sql[] = "WHERE {$wpdb->posts}.post_type = 'product_variation'";

        $sql = implode(" ", $sql);

        $variations =  $wpdb->get_results( $sql, ARRAY_A );

        if( ! empty( $variations ) ){
            foreach ( $variations as $pair ){
                $list[ $pair['ID'] ] = $pair['post_parent'];
            }
        }

        return $list;
    }

    /**
     * Modifies the WHERE clause for variations tax query
     * @param $where
     * @param $wp_query
     * @return string
     */
    public function taxQueryVariationsSql( $where, $wp_query )
    {
        // Make sure, that it is appropriate query
        if( $wp_query->get('flrt_query_hash')
            &&
            $wp_query->get('wc_query') === 'product_query'
            &&
            ! $wp_query->get('flrt_query_clone')
        ){

            if( is_admin() ){
                return $where;
            }

            global $wpdb;
            $modify         = false;
            $terms_IN       = false;
            $shipping_query = [];
            //@todo add used_for_variations to Shipping class
            foreach( $wp_query->tax_query->queries as $k => $query ){
                if( isset( $query['taxonomy'] ) &&  $query['taxonomy'] === 'product_shipping_class' ){
                    $modify             = true;
                    // Get (225) or (225,226) IN string
                    $terms_IN           = flrt_get_terms_ids_by_tax_query( $query );
                    $operator           = $query['operator'];
                    $shipping_query[]   = $query;
                    break;
                }
            }
            // @todo test with shipping class
            if( $modify && $terms_IN ){
                $shipping_tax_query = new \WP_Tax_Query($shipping_query);

                $primary_table      = $wpdb->posts;
                $primary_id_column  = 'ID';

                $variations_shipping_sql = $shipping_tax_query->get_sql($primary_table, $primary_id_column);

                // Find index of the query part, that should be modified
                $pieces = explode( "AND", $where);
                foreach ( $pieces as $and_index => $piece ){
                    if( strpos( $piece, $terms_IN ) !== false ){
                            break;
                    }
                }

                // Extract SQL part, that should be modified
                switch ($operator){
                    case 'IN':
                        $to_replace = $pieces[$and_index];
                        break;

                    case 'AND':
                        $to_replace = $pieces[$and_index] .' AND '. $pieces[ ($and_index + 1) ];
                        break;
                }

                // Add variations SQL part
                $modified_query[] = "( ".$to_replace;
                    $modified_query[] = "OR {$wpdb->posts}.ID IN(";
                    $modified_query[] = "SELECT DISTINCT {$wpdb->posts}.post_parent";
                    $modified_query[] = "FROM {$wpdb->posts}";
                    $modified_query[] = $variations_shipping_sql['join'];
                    $modified_query[] = "WHERE 1=1";
                    $modified_query[] = $variations_shipping_sql['where'];
                    $modified_query[] = "AND {$wpdb->posts}.post_type = 'product_variation' )";
                $modified_query[] = ")";

                $modified_query_str = implode("\n", $modified_query);

                // Insert modified part to the original WHERE
                switch ($operator){
                    case 'IN':
                        $pieces[$and_index] = $modified_query_str;
                        break;

                    case 'AND':
                        unset( $pieces[($and_index + 1)] );
                        $pieces[$and_index] = $modified_query_str;
                        break;
                }
                // Combine modified pieces into result query
                $where = implode( "AND", $pieces );
            }

            // Add attribute_pa_... where clause for appropriate tax query without meta query
            if( empty( $wp_query->get('meta_query') ) ){

                $wpManager               = Container::instance()->getWpManager();
                $queried_filters         = $wpManager->getQueryVar('queried_values', []);

                if( empty( $queried_filters ) ){
                    return $where;
                }
                global $wpdb;
                $flrt_meta_query = [];
                $variations_sql = [];

                foreach ($queried_filters as $queried_filter) {
                    if( $queried_filter['entity'] === 'taxonomy' ) {
                        if ($queried_filter['used_for_variations'] === 'yes') {
                            if (strpos($queried_filter['e_name'], 'pa_') === 0) {
                                // It is unnecessary to detect filter logic and it is enough
                                // Always to use compare IN for variations.
                                $flrt_meta_query[] = array(
                                    'key' => 'attribute_' . $queried_filter['e_name'],
                                    'value' => $queried_filter['values'],
                                    'compare' => 'IN'
                                );
                            }
                        }
                    }
                }

                if( count( $flrt_meta_query ) > 1 ){
                    $flrt_meta_query['relation'] = 'AND';
                }

                if( empty( $flrt_meta_query ) ){
                    return $where;
                }

                $meta_query = new \WP_Meta_Query( $flrt_meta_query );

                $variations_query = new \WP_Query();
                $test_args = array(
                    'post_type' => 'product_variation',
                    'posts_per_page' => -1,
                    'meta_query' => $meta_query->queries,
                    'fields' => 'id=>parent'
                );

                $variations_query->parse_query( $test_args );
                $variation_posts = $variations_query->get_posts();

                // Do not take into account products that have no variations
                $variations_sql[] = "\n {$wpdb->posts}.ID NOT IN(";
                $variations_sql[] = "SELECT DISTINCT {$wpdb->posts}.post_parent";
                $variations_sql[] = "FROM {$wpdb->posts}";
                $variations_sql[] = "WHERE {$wpdb->posts}.post_type = 'product_variation'";
                $variations_sql[] = ")";

                // Add variations query
                $variations_sql = array_merge( $variations_sql, flrt_build_variations_meta_query( $variation_posts ) );
                $inserted_sql   = " ( " . implode("\n", $variations_sql) . " ) AND ";

                if( $where ){
                    $pos = strpos( $where, '(' );
                    $where = substr_replace( $where, '('.$inserted_sql, $pos, 1 );
                }
            }
        }

        return $where;
    }

    /**
     * Adds correction for PostMetaExists filter SQL and variable WooCommerce products.
     *
     * @param $sql
     * @param $queries
     * @param $type
     * @param $primary_table
     * @param $primary_id_column
     * @param $context
     * @return mixed
     */
    public function postMetaExistsSql( $sql, $queries, $type, $primary_table, $primary_id_column, $context )
    {
        if( $context instanceof \WP_Query ){
            if(
                $context->get('flrt_query_hash')
                &&
                $context->get('wc_query') === 'product_query'
                &&
                $context->get('meta_query')
            ){
                /**
                 * @todo add correct calculation of meta_keys for variable product terms "_downloadable" example
                 * @tod add correct query for query type IN "_downloadable"
                  */
                global $wpdb;
                $variations_sql = [];
                remove_filter( 'get_meta_sql', [$this, 'postMetaExistsSql'], 10 );
                $wpManager               = Container::instance()->getWpManager();
                $queried_filters         = $wpManager->getQueryVar('queried_values', []);

                $flrt_meta_query = $context->get('meta_query');

                // 1. First native part of Meta query remains and affects only non-variable products
                // 2. Second part we should generate and SQL scheme should be another, without IS NULL
                // Because of it doesn't work for cases, when two variations have different values of the
                // same meta key. There should be POSTS.ID IN | POSTS.ID NOT IN SQL scheme.
                // 3. We need to extract from meta query only filtered keys and add them to the main meta_query

                // Separate queries to product and variations
                $separated_queries = flrt_sanitize_variations_meta_query( $flrt_meta_query, $queried_filters );

                foreach ($queried_filters as $queried_filter) {
                    if( $queried_filter['entity'] === 'taxonomy' ) {
                        if ($queried_filter['used_for_variations'] === 'yes') {
                            if (strpos($queried_filter['e_name'], 'pa_') === 0) {
                                // It is unnecessary to detect filter logic and it is enough
                                // Always to use compare IN for variations.
                                $separated_queries['for_variations'][] = array(
                                    'key' => 'attribute_' . $queried_filter['e_name'],
                                    'value' => $queried_filter['values'],
                                    'compare' => 'IN'
                                );

                                $separated_queries['for_variations']['relation'] = 'AND';
                            }
                        }
                    }
                }

                if( empty( $separated_queries['for_variations'] ) ){
                    return $sql;
                }

                $meta_query = new \WP_Meta_Query( $separated_queries['for_variations'] );

                $variations_query = new \WP_Query();
                $test_args = array(
                        'post_type' => 'product_variation',
                        'posts_per_page' => -1,
                        'meta_query' => $meta_query->queries,
                        'fields' => 'id=>parent'
                );

                $variations_query->parse_query( $test_args );
                $variation_posts = $variations_query->get_posts();

                // @todo test if all not exists
                $all_not_exists = flrt_is_all_not_exists($meta_query->queries);

                // Do not take into account products that have no variations
                $variations_sql[] = "\n AND {$wpdb->posts}.ID NOT IN(";
                $variations_sql[] = "SELECT DISTINCT {$wpdb->posts}.post_parent";
                $variations_sql[] = "FROM {$wpdb->posts}";
                $variations_sql[] = "WHERE {$wpdb->posts}.post_type = 'product_variation'";
                $variations_sql[] = ")";


                // Add variations query
                $variations_sql = array_merge( $variations_sql, flrt_build_variations_meta_query( $variation_posts, $separated_queries['for_products'] ) );
                $inserted_sql   = " " . implode("\n", $variations_sql) . " ";
                $parent_where = isset( $sql['where'] ) ? $sql['where'] : false;

                if( $parent_where ){
                    $pos = strrpos( $parent_where, ')' );
                    $parent_where = substr_replace( $parent_where, $inserted_sql. ' )', $pos );
                    $sql['where'] = $parent_where;
                }

            }
        }

        return $sql;
    }

    public function disableCacheProductsShortcode( $out )
    {
        $wpManager = Container::instance()->getWpManager();
        $is_filter_request = $wpManager->getQueryVar('wpc_is_filter_request');

        if( isset( $out['cache'] ) && $is_filter_request ){
            $out['cache'] = false;
        }

        return $out;
    }

    public function filtersPaginationLink( $link ){

        if ( is_singular() && ! is_front_page() ) {
            $default_link   = trailingslashit( get_permalink() );
            $urlManager     = new UrlManager();
            $correct_link   = trailingslashit( $urlManager->getFormActionUrl() );

            if( strpos( $link, $correct_link ) === false ){
                $link = str_replace( $default_link, $correct_link, $link );
            }
        }

        return $link;
    }

    public function legacyPrepareWpPageTypeValue( $prepared )
    {
        if( isset($prepared['post_name']['value']) && $prepared['post_name']['value'] ){
            if( ! isset( $prepared['wp_page_type']['value'] ) ){
                $prepared['wp_page_type']['value'] = $this->detectWpPageTypeByLocation( $prepared['post_name']['value']);
            }
        }
        return $prepared;
    }

    public function showLocationFields( &$set_settings_fields ){
        $location_fields = flrt_extract_vars( $set_settings_fields, array('wp_page_type', 'post_name') );
    ?>
        <tr class="wpc-filter-tr <?php echo esc_attr( $location_fields['wp_page_type']['class'] ); ?>-tr"<?php flrt_maybe_hide_row( $location_fields['wp_page_type'] ); ?>><?php

            flrt_include_admin_view('filter-field-label', array(
                    'field_key'  => 'wp_page_type',
                    'attributes' =>  $location_fields['wp_page_type']
                )
            );
            ?>
            <td class="wpc-filter-field-td wpc-filter-field-location-td">
                <div class="wpc-field-wrap <?php echo esc_attr( $location_fields['wp_page_type']['id'] ); ?>-wrap">
                    <?php echo flrt_render_input( $location_fields['wp_page_type'] ); // Already escaped in function ?>
                </div>
                <div class="wpc-field-wrap <?php echo esc_attr( $location_fields['post_name']['id'] ); ?>-wrap">
                    <?php echo flrt_render_input( $location_fields['post_name'] ); // Already escaped in function ?>
                </div>
            </td>
        </tr>
<?php
    }

    public function wpInit()
    {
        add_filter( 'wpc_posts_containers', [$this, 'setIndividualPostsContainer'], 10 );
    }

    public function setIndividualPostsContainer( $defaultContainer )
    {
        // For multiple Sets we can use its post_id to specify correct container
        // for JavaScript handler.
        $wpManager  = Container::instance()->getWpManager();
        $sets       = $wpManager->getQueryVar('wpc_page_related_set_ids');
        $filterSet  = Container::instance()->getFilterSetService();

        $containers = $defaultContainer;
        if( ! isset( $containers['default'] ) && is_string( $defaultContainer ) ){
            $containers = [
                'default' => trim($defaultContainer)
            ];
        }

        if( ! empty( $sets ) ) {
            foreach ( $sets as $set ){
                $theSet = $filterSet->getSet( $set['ID'] );
                if ( isset( $theSet['custom_posts_container']['value'] ) && ! empty( $theSet['custom_posts_container']['value'] ) ){
                    $containers[ $set['ID'] ] = esc_attr( trim($theSet['custom_posts_container']['value']) );
                }
            }
        }

        unset($filterSet, $wpManager);

        return $containers;
    }

    public function bodyClass( $classes )
    {
        if( flrt_get_option('show_bottom_widget') === 'on' ){
            $classes[] = 'wpc_show_bottom_widget';
        }

        return $classes;
    }

    /**
     * @param $filterSet array
     * @param $queriedObject array
     * @return array
     */
    public function findRelevantSetsPro( $filterSet, $queriedObject )
    {
        // Singular page

        if( isset( $queriedObject[ 'post_id' ] ) ){
            $sets = $this->getSetIdForSingular( $queriedObject[ 'post_types' ], $queriedObject[ 'post_id' ] );
            if( $sets !== false ){
                return $sets;
            }

            //@todo Try to find common set for all pages this post type
//            $sets = $this->getSetIdForSingular( $queriedObject[ 'post_types' ], '-1' );
//            if( $sets !== false ){
//                return $sets;
//            }
        }

        // We need to process common pages first as more prioritized
        // Than archive pages
        if( isset( $queriedObject[ 'common' ] ) ){
            $sets = $this->getSetIdForCommon( $queriedObject[ 'common' ] );
            if( ! empty( $sets )){
                return $sets;
            }
        }

        // Get filter set specified for term (if exists)
        if( isset( $queriedObject[ 'taxonomy' ] ) ){
            // get term related filters Set
            $sets = $this->getSetIdForTerm( $queriedObject[ 'taxonomy' ], $queriedObject[ 'term_id' ] );

            if( $sets !== false ){
                return $sets;
            }
            // Try to find common set for taxonomy archive
            $sets = $this->getSetIdForTerm( $queriedObject[ 'taxonomy' ], '-1' );

            if( $sets !== false ){
                return $sets;
            }
        }

        if( isset( $queriedObject[ 'author' ] ) ){
            $sets = $this->getSetIdForAuthor( $queriedObject[ 'author' ] );
            if( $sets !== false ){
                return $sets;
            }
        }


        return $filterSet;
    }

    /**
     * @param $common array
     * @return false|mixed
     */
    private function getSetIdForCommon( $common )
    {
        $storeKey   = 'set_common';
        $searchKey  = [];

        foreach( $common as $value ){
            if( ! in_array( $value, array(
                'page_on_front',
                'page_for_posts',
                'search_results',
                'shop_page' ) ) ){
                return false;
            }

            $storeKey .= '_'.$value;
            $searchKey[] = "common___".$value;
        }

        return $this->querySets( $storeKey, $searchKey );

    }

    private function getSetIdForSingular( $postTypes, $postId )
    {
        if( ! $postTypes || ! $postId ){
            return false;
        }

        $postType = reset($postTypes);

        $storeKey   = 'set_' . $postType . '_' .$postId;
        $searchKey  = $postType.'___'.$postId;

        return $this->querySets( $storeKey, $searchKey );

    }

    /**
     * @return int|false
     */
    private function getSetIdForTerm( $taxonomy, $termId )
    {
        if( ! $taxonomy || ! $termId ){
            return false;
        }

        $storeKey   = 'set_' . $taxonomy . '_' .$termId;
        $searchKey  = $taxonomy.'___'.$termId;

        $sets = $this->querySets( $storeKey, $searchKey );

        if( ! $sets ){
            $parentTermId = wp_get_term_taxonomy_parent_id( $termId, $taxonomy );

            if($parentTermId){
                return $this->getSetIdForTerm( $taxonomy, $parentTermId );
            }
        }

        return $sets;
    }

    /**
     * @param $author user_id|user slug
     * @return int|false
     */
    private function getSetIdForAuthor( $authorSlug )
    {
        $user_id = false;

        if( ! $authorSlug ){
            return false;
        }

        if( $user = get_user_by( 'slug', $authorSlug ) ){
            $user_id = $user->ID;
        }

        $storeKey   = 'set_author_' . $user_id;
        $searchKey  = 'author___'.$user_id;

        $sets = $this->querySets( $storeKey, $searchKey );

        // Try to find common author page sets
        if( ! $sets ){
            $user_id    = '-1';
            $storeKey   = 'set_author_' . $user_id;
            $searchKey  = 'author___'.$user_id;

            $sets = $this->querySets( $storeKey, $searchKey );
        }

        return $sets;
    }

    private function querySets( $storeKey, $searchKey )
    {
        $container = Container::instance();

        if( ! $sets = $container->getParam( $storeKey ) ){

            if( ! is_array( $searchKey ) ){
                $searchKey = array( $searchKey );
            }

            $args = array(
                'post_type'      => FLRT_FILTERS_SET_POST_TYPE,
                'post_status'    => 'publish',
                'post_name__in'  => $searchKey,
                'orderby'        => array( 'menu_order' => 'DESC', 'ID' => 'ASC' ),
                'flrt_set_query' => true,
                'suppress_filters'=> true
            );

            $setQuery = new \WP_Query();
            $setQuery->parse_query($args);
            $setPosts = $setQuery->get_posts();

            if( ! empty( $setPosts ) ){
                foreach ( $setPosts as $set ){

                    $content = maybe_unserialize( $set->post_content );
                    $query  = isset( $content['wp_filter_query'] ) ? $content['wp_filter_query']: '-1';

                    $sets[] = array(
                        'ID'                 => (string) $set->ID,
                        'filtered_post_type' => $set->post_excerpt,
                        'query'              => $query
                    );

                }
            }else{
                return false;
            }

            $container->storeParam( $storeKey, $sets );
        }

        unset( $container );

        return $sets;
    }

    public function filterSetDefaultFields( $defaultFields, $filterSet )
    {
        /**
         * The order of some fields in array doesn't matter because
         * they replace already existing fields
         */
        $defaultFields['wp_page_type'] = array(
            'type'          => 'Select',
            'label'         => esc_html__('Location', 'filter-everything'),
            'class'         => 'wpc-field-wp-page-type',
            'id'            => $filterSet->generateFieldId('wp_page_type'),
            'name'          => $filterSet->generateFieldName('wp_page_type'),
            'options'       => $this->getSetLocationGroups(),
            'default'       => 'common___common',
            'instructions'  => esc_html__('Specify page(s) where to show this Filter Set', 'filter-everything'),
            'settings'      => true
        );

        $defaultFields['post_name'] = array(
            'type'          => 'Select',
            'label'         => '',
            'class'         => 'wpc-field-location',
            'id'            => $filterSet->generateFieldId('post_name'),
            'name'          => $filterSet->generateFieldName('post_name'),
            'options'       => $this->getSetLocationTerms(),
            'default'       => '1',
            'instructions'  => '',
            'particular'    => 'post_name', // Determine that this is specific field should be stored in wp_post column
            'settings'      => true
        );

        $defaultFields['wp_filter_query'] = array(
            'type'          => 'Select',
            'label'         => esc_html__('Query', 'filter-everything'),
            'class'         => 'wpc-field-wp-filter-query',
            'id'            => $filterSet->generateFieldId('wp_filter_query'),
            'name'          => $filterSet->generateFieldName('wp_filter_query'),
            'options'       => array( '-1' => esc_html__('— Select Query —', 'filter-everything') ),
            'default'       => '-1',
            'instructions'  => esc_html__('Select WP Query, that should be filtered', 'filter-everything'),
            'tooltip'       => esc_html__( 'The page selected in the Location field can contain multiple WP Queries associated with the desired Post type. They can be responsible for the work of widgets, posts and even nav menus. Please, try experimentally determining which WP Query is responsible for displaying the posts you want to filter.', 'filter-everything' ),
            'settings'      => true
        );

        $defaultFields['hide_empty_filter'] = array(
            'type'          => 'Checkbox',
            'label'         => esc_html__('Hide empty Filters', 'filter-everything'),
            'name'          => $filterSet->generateFieldName('hide_empty_filter'),
            'id'            => $filterSet->generateFieldId('hide_empty_filter'),
            'class'         => 'wpc-field-hide-empty-filter',
            'default'       => 'no',
            'instructions'  => esc_html__('Hide entire Filter if no one term contains posts', 'filter-everything'),
            'settings'      => true
        );

        $defaultFields['custom_posts_container'] = array(
            'type'          => 'Text',
            'label'         => esc_html__('CSS ID or Class of Posts Container', 'filter-everything'),
            'name'          => $filterSet->generateFieldName('custom_posts_container'),
            'id'            => $filterSet->generateFieldId('custom_posts_container'),
            'class'         => 'wpc-field-custom-posts-container',
            'placeholder'   => esc_html__( 'e.g. #primary or .main-content', 'filter-everything' ),
            'default'       => '',
            'instructions'  => esc_html__('Specify individual CSS selector of Posts Container for AJAX', 'filter-everything'),
            'settings'      => true
        );

        $defaultFields['menu_order'] = array(
            'type'          => 'Text',
            'label'         => esc_html__('Order №', 'filter-everything'),
            'name'          => $filterSet->generateFieldName('menu_order'),
            'id'            => $filterSet->generateFieldId('menu_order'),
            'class'         => 'wpc-field-menu-order',
            'default'       => 0,
            'instructions'  => esc_html__('Filter Set with a higher value will be shown first on a page with several Filter Sets', 'filter-everything'),
            'particular'    => 'menu_order',
            'settings'      => true // Determine to display this in Settings meta box
        );

        return $defaultFields;
    }

    public function filterDefaultFields( $defaultFields, $filterFields )
    {
        $defaultFields['slug'] = array(
            'type'          => 'Text',
            'label'         => esc_html__( 'Prefix for URL', 'filter-everything' ),
            'class'         => 'wpc-field-slug',
            'instructions'  => esc_html__( 'A part of URL with which the filter section begins', 'filter-everything'),
            'tooltip'       => wp_kses(
                __( 'Filter Prefix is something like Wordpress slug.<br />For example in URL path: <br />/color-red-or-blue/size-large/<br /> «color» and «size» are filter prefixes.<br />You can not edit already defined filter prefix here, but you can edit it globally in the Plugin Settings.', 'filter-everything'),
                array( 'br' => array() )
            ),
            'required'      => true
        );

        if( ! flrt_is_woocommerce() ){
            return $defaultFields;
        }

        $updatedFields = [];
        foreach ( $defaultFields as $key => $field ){
            $updatedFields[$key] = $field;

            if( $key === 'hierarchy' ){
                $updatedFields['used_for_variations'] = array(
                    'type' => 'Checkbox',
                    'label' => esc_html__('Use for Variations', 'filter-everything'),
                    'class' => 'wpc-field-for-variations',
                    'default' => 'no',
                    'instructions' => esc_html__('If checked, filtering will take into account variations with this attribute or meta key', 'filter-everything'),
                    'tooltip' => wp_kses(
                        __( 'Check this box if products have variations with this meta key or this attribute.<br />And don\'t check the box if they don\'t have.<br />Relevant for variable products only.', 'filter-everything'),
                        array( 'br' => array() )
                    )
                );
            }
        }

        return $updatedFields;
    }

    private function getSetLocationGroups(){
        if(! is_admin()){
            return array();
        }
        // Common WP pages
        $fields = array(
            'common' => array(
                'group_label' => esc_html__('Common', 'filter-everything'),
                'entities' => array(
                    // This should be renamed as it looks like WP Page post type
                    'common___common' => esc_html__('Common pages', 'filter-everything'),
                )
            )
        );

        // Get Taxonomies
        $excludedTaxes  = flrt_excluded_taxonomies();
        $args           = array( 'public' => true, 'rewrite' => true );
        $taxonomies     = get_taxonomies( $args, 'objects' );
        $tax_entitites  = [];

        foreach ( $taxonomies as $t => $taxonomy ) {
            if ( ! in_array( $taxonomy->name, $excludedTaxes ) ) {
                $label = ucwords( flrt_ucfirst( mb_strtolower( $taxonomy->label ) ) );
                $tax_entitites[ 'taxonomy___' .$taxonomy->name] = $label;
            }
        }

        if( ! empty( $tax_entitites ) ){
            $fields['taxonomies'] = array(
                'group_label' => esc_html__('Taxonomies', 'filter-everything'),
                'entities' => $tax_entitites
            );
        }

        // Get Post types
        $filterSet  = Container::instance()->getFilterSetService();

        $post_types = $filterSet->getPostTypes();

        if( ! empty( $post_types ) ){
            $new_post_types = [];
            foreach ($post_types as $post_type_key => $post_type_label ){
                $new_post_types[ 'post_type___' .$post_type_key ] = $post_type_label;
            }

            $fields['post_types'] = array(
                'group_label' => esc_html__('Post types', 'filter-everything'),
                'entities' => $new_post_types
            );
        }

        $fields['author'] = array(
            'group_label' => esc_html__( 'Author', 'filter-everything' ),
            'entities'    => array(
                'author___author' => esc_html__( 'Author', 'filter-everything' )
            )
        );

        unset( $filterSet );

        return $fields;
    }

    private function getSetLocationTerms( $wpPageType = 'common___common', $postType = 'post' )
    {
        $fields = [];
        if( ! is_admin() ){
            return $fields;
        }
        $wpPageType = $wpPageType ? $wpPageType : 'common___common';

        $pageTypeVars = explode('___', $wpPageType);
        $typeKey      = $pageTypeVars[0];
        $typeValue    = isset( $pageTypeVars[1] ) ? $pageTypeVars[1] : false;

        // @todo No posts, No tags what to show in Dropdown?
        switch ( $typeKey ){
            case 'common':
                $fields = $this->getCommonLocationTerms( $postType );
                break;
            case 'post_type':
                $fields = $this->getPostTypeLocationTerms( $typeValue );
                break;
            case 'taxonomy':
                $fields = $this->getTaxonomyLocationTerms( $typeValue );
                break;
            case 'author':
                $fields = $this->getAuthorLocationTerms();
                break;
        }

        return $fields;
    }

    private function getAuthorLocationTerms()
    {
        $fields  = [];
        $em      = Container::instance()->getEntityManager();
        $authors = $em->getAuthorTermsForDropdown( true );

        if (! empty( $authors )){
            $label = esc_html__('Author');

            $firstAuthorKey  = array_key_first($authors);
            $keyParts        = explode( ":", $firstAuthorKey );
            $firstAuthorId   = intval( $keyParts[1] );
            $firstAuthorLink = get_author_posts_url( $firstAuthorId );

            $fields['author___-1'] = array(
                'label'     => sprintf(esc_html__('Any %s (for common query across all %s pages)', 'filter-everything'), $label, $label ),
                'data-link' => $firstAuthorLink
            );

            unset( $firstAuthorKey, $keyParts, $firstAuthorId, $firstAuthorLink );

            foreach ( $authors as $authorKey => $authorLabel ){
                $keyParts   = explode( ":", $authorKey );
                $authorId   = intval( $keyParts[1] );
                $authorLink = get_author_posts_url( $authorId );

                $fields['author___'.$authorId] = array(
                    'label'     => $authorLabel,
                    'data-link' => $authorLink
                );
            }

        }

        unset( $em );

        return $fields;
    }

    private function getTaxonomyLocationTerms( $taxonomy )
    {
        $fields = [];

        if( ! $taxonomy ){
            return $fields;
        }

        $args = array(
            'taxonomy'      => $taxonomy,
            'hide_empty'    => false,
            'fields'        => 'id=>name'
        );

        $terms          = get_terms( $args );
        $taxonomyObject = get_taxonomy( $taxonomy );

        $label          = isset( $taxonomyObject->labels->singular_name ) ? $taxonomyObject->labels->singular_name : flrt_ucfirst( $taxonomy );

        if( ! is_wp_error( $terms ) && ! empty( $terms ) ){

            $firstTermId    = array_key_first($terms);
            $firstTermlink  = get_term_link( $firstTermId, $taxonomy );
            $firstTermlink  = ( is_wp_error( $firstTermlink ) ) ? '' : $firstTermlink;

            $fields[$taxonomy.'___-1'] = array(
                'label'     => sprintf(esc_html__('Any %s (for common query across all %s pages)', 'filter-everything'), $label, $label ),
                'data-link' => $firstTermlink
            );
            unset( $firstTermId, $firstTermlink);

            foreach ( $terms as $termId => $termName ){
                $link = get_term_link( $termId, $taxonomy );
                $link = ( is_wp_error( $link ) ) ? '' : $link;

                $fields[$taxonomy.'___'.$termId] = array(
                    'label'     => $termName,
                    'data-link' => $link
                );
            }
        }else{
            $fields[$taxonomy.'___0'] = array(
                'label'     => sprintf(esc_html__('— There is no any %s yet —', 'filter-everything'), $label ),
                'data-link' => ''
            );
        }

        return $fields;
    }

    private function getPostTypeLocationTerms( $postType = 'post' )
    {
        $postType   = $postType ? $postType : 'post';
        $fields     = [];

        $args = array(
            'post_type'      => $postType,
            'posts_per_page' => -1,
            'post_status'    => array( 'publish', 'private' ),
            'orderby'        => 'title',
            'order'          => 'ASC',
            'fields'         => 'ids'
        );

        $allPosts = new \WP_Query();
        $allPosts->parse_query($args);
        $ids      = $allPosts->get_posts();

        $postTypeObject = get_post_type_object($postType);
        $label = isset( $postTypeObject->labels->singular_name ) ? $postTypeObject->labels->singular_name : flrt_ucfirst( $postType );

        if( ! empty( $ids ) ){
            $firstPostId    = reset($ids);
            $firstPostlink  = get_permalink($firstPostId);

            //@todo add support of all post type pages. Maybe.
//            if( $postType !== 'page'){
//                $fields[$postType.'___-1'] = array(
//                    'label'     => sprintf(esc_html__('Any %s', 'filter-everything'), $label ),
//                    'data-link' => $firstPostlink
//                );
//            }

            unset( $firstPostId, $firstPostlink );

            foreach ( $ids as $postId ){
                $fields[$postType.'___'.$postId] = array(
                    'label'     => get_the_title( $postId ),
                    'data-link' => get_permalink( $postId )
                );
            }
        }else{
            $fields[$postType.'___0'] = array(
                'label'     => sprintf(esc_html__('— There is no any %s yet —', 'filter-everything'), $label ),
                'data-link' => ''
            );
        }

        return $fields;
    }

    private function getCommonLocationTerms( $postType = 'post' )
    {
        $fields = [];
        // To avoid bug with 301 redirect if URL is not match permastructure
//        $link = user_trailingslashit( get_post_type_archive_link($postType) );
//        $link = trim( $link, '/' );
        $link = get_post_type_archive_link($postType);

        if( $link ){
            $fields = array( '1' => array(
                    'label' => esc_html__('All archive pages for this Post Type', 'filter-everything'),
                    'data-link' => $link
                ),
            );
        }

        $page_for_posts = get_option( 'page_for_posts' );

        if( $page_for_posts ){
            $fields['common___page_for_posts'] = array(
                'label' => esc_html__('Blog page'),
                'data-link' => get_permalink( $page_for_posts )
            );
        }

        $page_on_front = get_option( 'page_on_front' );

        if( $page_on_front ){
            $fields['common___page_on_front'] = array(
                'label' => esc_html__('Homepage' ),
                'data-link' => get_permalink( $page_on_front )
            );
        }

        $fields['common___search_results'] = array(
            'label' => esc_html__('Search results page for selected Post Type', 'filter-everything'),
            'data-link' => add_query_arg( array('s' => 'a', 'post_type' => $postType ), trailingslashit( get_bloginfo('url') ) )
        );

        if( function_exists('is_woocommerce') ){
            $fields['common___shop_page'] = array(
                'label' => esc_html__('Shop page', 'filter-everything' ),
                'data-link' => get_permalink( wc_get_page_id( 'shop' ) )
            );
        }

        return $fields;
    }

    public function sendSetLocationTerms()
    {
        $postData   = Container::instance()->getThePost();
        $filterSet  = Container::instance()->getFilterSetService();

        $post_type  = isset( $postData['postType'] ) ? $postData['postType'] : 'post';
        $wpPageType = isset( $postData['wpPageType'] ) ? $postData['wpPageType'] : false;
        $post_id    = isset( $postData['postId'] ) ? $postData['postId'] : '';
        $nonce      = isset( $postData['_wpnonce'] ) ? $postData['_wpnonce'] : false;

        $errorResponse  = array(
            'postId' => $post_id,
            'message' => esc_html__('An error occured. Please, refresh the page and try again.', 'filter-everything')
        );

        if( ! wp_verify_nonce( $nonce, FilterSet::NONCE_ACTION ) ){
            wp_send_json_error($errorResponse);
        }

        $set = $filterSet->getSet( $post_id );

        // Get prepared field with populated saved values
        if( ! empty( $set ) && $set['post_type']['value'] == $post_type ){
            $location = $set['post_name'];
        }else{
            // Or create new one, if it is new set
            $fields = $filterSet->getFieldsMapping();
            $location = $fields['post_name'];
        }

        $location['options'] = $this->getSetLocationTerms( $wpPageType, $post_type );

        $response = [];

        ob_start();

        echo flrt_render_input($location);

        $response['html'] = ob_get_clean();

        wp_send_json_success($response);
        die();
    }

    public function prepareSetParameters( $defaults, $set_post  )
    {
        // Set location dropdown fields related to saved post_type and wp_page_type
        $postType = $set_post->post_excerpt ? $set_post->post_excerpt : 'post';
        $unserialized = maybe_unserialize( $set_post->post_content );

        // For backward compatibility. From v.1.1.24
        if( isset( $unserialized['wp_page_type'] ) ){
            $unserialized['wp_page_type'] = str_replace(":", "___", $unserialized['wp_page_type']);
        }

        $wpPageType = isset( $unserialized['wp_page_type'] ) ? $unserialized['wp_page_type'] : $this->detectWpPageTypeByLocation( $set_post->post_name );

        $defaults['post_name']['options'] = $this->getSetLocationTerms( $wpPageType, $postType );

        return $defaults;
    }

    public function detectWpPageTypeByLocation( $locationValue )
    {
        $wpPostType = 'common___common';


        if( $locationValue == '1' ){
            $wpPostType = 'common___common';
        }else if( mb_strpos( $locationValue, 'author' ) !== false ){
            $wpPostType = 'author___author';

        }else if( mb_strpos( $locationValue, 'post_type' ) !== false ){
            $postTypeParts = explode("___", $locationValue);
            $postTypeName  = $postTypeParts[0];
            $wpPostType    = 'post_type___'.$postTypeName;
        }else if( $locationValue ){
            $taxonomyParts = explode("___", $locationValue);
            $taxName       = $taxonomyParts[0];
            $wpPostType = 'taxonomy___'.$taxName;
        }
        ;
        return $wpPostType;
    }

    public function validationLocationEntities( $possibleEntities, $setFields )
    {
        $possibleEntities = $this->getSetLocationTerms( $setFields['wp_page_type'], $setFields['post_type'] );
        return array_keys( $possibleEntities );
    }

    public function validationWpPageTypeEntities( $possibleWpPageTypes )
    {
        $possibleWpPageTypes = $this->flattenValues( $this->getSetLocationGroups() );
        return array_keys( $possibleWpPageTypes );
    }

    public function filterSetPostTypeCol( $columns )
    {
        $newColumns = [];

        foreach ( $columns as $columnId => $columnName ) {

            $newColumns[$columnId] = $columnName;
            if( $columnId === 'title' ){
                $newColumns['location'] = esc_html__( 'Available on', 'filter-everything' );
            }
        }

        return $newColumns;
    }

    private function getSetLocationLabel( $options, $value, $post_type = 'post' )
    {
        $entityLabel = $entityGroup = $entity = '';

        if( ! isset($options['common']['entities']) ){
            return false;
        }

        $parts          = explode("___", $value);
        $selectedEntity = $parts[0];
        $selectedValue  = isset($parts[1]) ? $parts[1] : $parts[0];

        unset($parts);

        if( $selectedValue == '1' && $selectedEntity == '1' ){
            $entityGroup = 'common';
            $entity      = 'common';
            $entityLabel = esc_html__('All archive pages for this Post Type', 'filter-everything');
        }else{
            foreach( $options as $section ){
                foreach( $section['entities'] as $groupAndEntity => $label ){
                    $parts = explode("___", $groupAndEntity);
                    $entityGroup = $parts[0];
                    $entity = $parts[1];

                    unset($parts);

                    if( $entity === $selectedEntity ){
                        $entityLabel = $label;
                        break;
                    }

                    $entityGroup = $entity = '';

                }

                if( $entityGroup && $entity ){
                    break;
                }
            }
        }

        if(  $entityGroup && $entity && $entityLabel ) {

            switch ( $entityGroup ){
                case 'common':

                    $commonPages = $this->getCommonLocationTerms( $post_type );

                    if( isset( $commonPages[ $selectedEntity .'___'. $selectedValue ]['label'] ) ){
                        $toShow = $commonPages[ $selectedEntity .'___'. $selectedValue ]['label'];
                    }else{
                        $toShow = $entityLabel;
                    }

                    break;
                case 'taxonomy':
                    // could be -1
                    if( $selectedValue == '-1' ){
                        $toShow = sprintf(esc_html__('Any %s', 'filter-everything'), $entityLabel );
                    }else{
                        $term   = get_term( $selectedValue, $selectedEntity );
                        $name   = is_wp_error( $term ) ? '' : $term->name;
                        $toShow = sprintf(esc_html__('%s: %s', 'filter-everything'), $entityLabel, $name);
                    }

                    break;

                case 'post_type':
                    // could be -1
                    if( $selectedValue == '-1' ){
                        $toShow = sprintf(esc_html__('Any %s', 'filter-everything'), $entityLabel );
                    }else{
                        $name = get_the_title($selectedValue);
                        $toShow = sprintf(esc_html__('%s: %s', 'filter-everything'), $entityLabel, $name);
                    }
                    break;
                case 'author':
                    // could be -1
                    if( $selectedValue == '-1' ){
                        $toShow = sprintf(esc_html__('Any %s', 'filter-everything'), $entityLabel );
                    }else{
                        $author = get_userdata($selectedValue);
                        $name   = ( $author ) ? $author->data->display_name : '';
                        $toShow = sprintf(esc_html__('%s: %s', 'filter-everything'), $entityLabel, $name);
                    }
                    break;

            }

            return $toShow;

        }

        return false;
    }

    public function burpOutAllWpQueries( $wp_query )
    {
        $postData = Container::instance()->getThePost();
        if( isset( $postData['action'] ) && $postData['action'] === 'wpc_get_wp_queries' ){

            if( ! isset( $postData['_wpnonce'] ) || ! wp_verify_nonce( $postData['_wpnonce'], FilterSet::NONCE_ACTION ) ){
                return $wp_query;
            }

            if( ! current_user_can( 'manage_options' ) ) {
                return $wp_query;
            }

            add_action( 'wp_footer', [$this, 'showCollectedWpQueries'] );
        }

        return $wp_query;
    }

    public function isFilteredQueryPro( $result, $query )
    {
        $wpManager = Container::instance()->getWpManager();
        $sets = $wpManager->getQueryVar('wpc_page_related_set_ids');

        if( empty( $sets ) ){
            return false;
        }

        $filterSet = Container::instance()->getFilterSetService();
        remove_filter('wpc_prepare_filter_set_parameters', [$this, 'prepareSetParameters'], 10, 2);

        foreach ( $sets as $set ){

            $theSet = $filterSet->getSet( $set['ID'] );

            if( isset( $theSet['wp_filter_query']['value'] ) && $theSet['wp_filter_query']['value'] ){
                $savedValue = $theSet['wp_filter_query']['value'];

                if( $savedValue === $query->get('flrt_query_hash') ){
                    $result[] = $set['ID'];
                }
            }
        }

        if( empty( $result ) ){
            // Let's do it again.
            foreach ( $sets as $set ) {

                $theSet = $filterSet->getSet($set['ID']);

                if( isset( $theSet['wp_filter_query']['value'] ) && $theSet['wp_filter_query']['value'] ) {
                    $savedValue = $theSet['wp_filter_query']['value'];

                    // For backward compatibility, when savedValue isn't specified and is default -1
                    if ($query->is_main_query() && $savedValue === '-1') {
                        $result[] = $set['ID'];
                        break;
                    }

                    // For All Post type archive pages
                    if (isset($theSet['post_name']['value']) && $theSet['post_name']['value'] === '1' && $query->is_main_query()) {
                        $result[] = $set['ID'];
                        break;
                    }
                }
            }
        }

        add_filter('wpc_prepare_filter_set_parameters', [$this, 'prepareSetParameters'], 10, 2);

        unset($filterSet, $wpManager);

        return $result;
    }

    public function showCollectedWpQueries()
    {
        global $flrt_queries;

        $filterSet       = Container::instance()->getFilterSetService();
        $postData        = Container::instance()->getThePost();
        $postType        = isset( $postData['postType'] ) ? $postData['postType'] : false;
        $postId          = isset( $postData['postId'] ) ? $postData['postId'] : false;
        $flatten_queries = $this->flatAllWpQueriesList( $flrt_queries, $postType );
        $fieldName       = 'wp_filter_query';

        $theSet          = $filterSet->getSet( $postId );
        // Set includes field configuration arrays together with saved values
        $select_atts     = isset( $theSet[$fieldName] ) ? $theSet[$fieldName] : false;
        if( $select_atts ){
            $select_atts['options'] = $flatten_queries['options'];
        }

        // Remove all additional HTML from the 'wp_filter_query' Select field
        remove_all_filters('wpc_input_type_select');

        $selectField = flrt_render_input($select_atts);

        if( ! $selectField ) {
            // For Any case if the 'flrt_render_input()' return false;
            $postTypeObject     = get_post_type_object( $postType );
            $postNameLabel      = isset( $postTypeObject->labels->singular_name ) ? $postTypeObject->labels->singular_name : flrt_ucfirst( $postType );

            $selectField  = '<div><select class="wpc-field-wp-filter-query" id="wpc_set_fields-wp_filter_query" name="wpc_set_fields[wp_filter_query]">'."\n";
            $selectField .= '<option value="-1" >'.sprintf( esc_html__('No WP Queries matched to the post type "%s" found on the page' ), $postNameLabel ).'</option>'."\n";
            $selectField .= '</select></div>'."\n";
        }

        echo '<div>'.$selectField.'</div>';

        echo '<div><div id="wpc_query_vars">';
        if( isset( $flatten_queries['query_vars'] ) && ! empty( $flatten_queries['query_vars'] ) ){
                foreach ( $flatten_queries['query_vars'] as $hash => $vars ){
                    $hiddenFieldName = esc_attr( $filterSet::FIELD_NAME_PREFIX . '[wp_filter_query_vars]['.$hash.']' );
                    echo '<input type="hidden" name="'.$hiddenFieldName.'" value="'.esc_attr( $vars ).'" />'."\n";
                }
        }
        echo '</div></div>';

    }

    /**
     * Converts queries array from multidimensional to simple
     * Optionally removes queries with unnecessary post type
     * @param array $queries
     * @param false|string $postType
     */
    public function flatAllWpQueriesList( $queries, $postType = false )
    {
        $flatten = [];

        $postTypeObject = get_post_type_object( $postType );
        $postNameLabel = isset( $postTypeObject->labels->singular_name ) ? $postTypeObject->labels->singular_name : flrt_ucfirst( $postType );

        if( empty( $queries ) ){
            $flatten['options']['-1'] = sprintf( esc_html__('No WP Queries matched to the post type "%s" found on the page' ), $postNameLabel );
            return $flatten;
        }

        foreach ( $queries as $hash => $single_query ){
            foreach ($single_query as $index => $values ) {

                if( $postType ){
                    if( ! in_array( $postType, $values['post_types'], true ) ){
                        continue;
                    }
                }

                // We should use another label numeration logic
                $new_hash = md5( $hash . $index );
                $flatten['options'][ $new_hash ]    = $values['label'];
                $flatten['query_vars'][ $new_hash ] = $values['query_vars'];
            }
        }

        // Add numeration for equal labels
        if( ! empty( $flatten['options'] ) ){
            $copy_flatten = $flatten['options'];
            $count_labels = array_count_values($copy_flatten);
            $i = [];

            foreach ( $copy_flatten as $hash => $label ){
                if( $count_labels[$label] > 1 ){
                    $i[$label]++;
                    $new_label = sprintf( esc_html__('%s #%s', 'filter-everything'), $label, $i[$label] );
                    $flatten['options'][ $hash ] = $new_label;
                }
            }
        }else{
            $flatten['options']['-1'] = sprintf( esc_html__('No WP Queries matched to the post type "%s" found on the page' ), $postNameLabel );
            return $flatten;
        }

        return $flatten;
    }

    // Show selected location in the Available on column of admin Filter Sets list
    public function filterSetPostTypeColContent( $column_name, $post_id )
    {
        if ( 'location' == $column_name ){
            $fss        = Container::instance()->getFilterSetService();
            $theSet     = $fss->getSet( $post_id );

            $wpPageType = isset( $theSet['wp_page_type'] ) ? $theSet['wp_page_type'] : '';
            $location   = isset( $theSet['post_name'] ) ? $theSet['post_name'] : '';
            $post_type  = isset( $theSet['post_type']['value'] ) ? $theSet['post_type']['value'] : 'post';

            if( $label = $this->getSetLocationLabel( $wpPageType['options'], $location['value'] , $post_type) ){
                echo esc_html( $label );
            }

            unset($fss);
        }
    }

    public function postMetaExistsEntity( $entities )
    {
        // Add Post Meta Exists entity
        $entities['post_meta']['entities']['post_meta_exists'] = esc_html__( 'Custom Field Exists', 'filter-everything' );

        return $entities;
    }

    public function flattenValues( $entities )
    {
        if( empty( $entities ) ){
            return $entities;
        }
        $flat_entities = [];

        array_walk_recursive( $entities, function ( $value, $key ) use ( &$flat_entities ) {
            if( $key !== 'group_label' /*&& isset( $value['label'] ) && $value['label'] */){
                $flat_entities[ $key ] = $value;
            }
        }, $flat_entities );

        return $flat_entities;
    }
}