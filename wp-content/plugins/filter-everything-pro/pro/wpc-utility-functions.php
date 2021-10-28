<?php

if ( ! defined('WPINC') ) {
    wp_die();
}

use FilterEverything\Filter\Container;
use FilterEverything\Filter\Pro\Admin\SeoRules;

function flrt_get_seo_rules_fields( $post_id )
{
    $seoRules = new SeoRules();
    return $seoRules->getRuleInputs( $post_id );
}

function flrt_create_seo_rules_nonce()
{
    return SeoRules::createNonce();
}

//function flrt_get_allowed_variation_meta_keys(){
//    return apply_filters( 'wpc_allowed_variation_meta_keys', array(
//        '_thumbnail_id',
//        '_regular_price',
//        'total_sales',
//        '_tax_status',
//        '_tax_class',
//        '_manage_stock',
//        '_backorders',
//        '_sold_individually',
//        '_virtual',
//        '_downloadable',
//        '_download_limit',
//        '_download_expiry',
//        '_stock',
//        '_stock_status',
//        '_wc_average_rating',
//        '_wc_review_count',
//        '_product_version',
//        '_price',
//        '_product_attributes',
//        '_wp_old_slug',
//        '_default_attributes',
//        '_wp_old_date',
//        '_sale_price',
//        '_length',
//        '_width',
//        '_height',
//        '_weight'
//        )
//    );
//}

function flrt_is_first_order_clause( $query ) {
    return isset( $query['key'] ) || isset( $query['value'] );
}

function flrt_build_variations_meta_query( $parent_ids, $meta_query = [] ) {
    global $wpdb;
    $variations_sql = [];

    if( empty( $parent_ids ) ){
        return $variations_sql;
    }

    $parent_ids = array_unique( $parent_ids );

    $variations_sql[]       = " OR ("; //$all_not_exists ? " AND (" : " OR (";
    $variations_sql[]       = "{$wpdb->posts}.ID IN( ". implode( ",", $parent_ids ) ." )";

    if( ! empty( $meta_query ) ){
        $side_meta_query = new \WP_Meta_Query( $meta_query );
        $clauses = $side_meta_query->get_sql( 'post', $wpdb->posts, 'ID' );
        if( $clauses['where'] ){
            $variations_sql[] = $clauses['where'];
        }
    }

    $variations_sql[]       = ")";

    return $variations_sql;
}


/**
 * Extracts from meta_queries only those which can be related with variations
 * @param $queries array meta_queries
 * @return array
 */
function flrt_sanitize_variations_meta_query( $queries, $queried_filters ) {
    $clean_queries           = [];
    $separated_queries       = [ 'for_variations' => [], 'for_products' => [] ];
    $filter_keys             = [ 'keys_variations' => [], 'keys_products' => [] ];
//    $variations_allowed_keys = flrt_get_allowed_variation_meta_keys();

    if( ! $queried_filters ){
        return $separated_queries;
    }

    // Collect only post meta filter keys.
    foreach ( $queried_filters as $slug => $filter ){
        if( isset( $filter['e_name'] ) && in_array( $filter['entity'], array( 'post_meta', 'post_meta_num', 'post_meta_exists' ) ) ){

            if( $filter['used_for_variations'] === 'yes' ){
                $filter_keys['keys_variations'][] = $filter['e_name'];
            }else{
                $filter_keys['keys_products'][] = $filter['e_name'];
            }

        }
    }

    if ( ! is_array( $queries ) ) {
        return $separated_queries;
    }

    foreach ( $queries as $key => $query ) {
        if ( 'relation' === $key ) {
            $relation = $query;

        } elseif ( ! is_array( $query ) ) {
            continue;

            // First-order clause.
        } elseif ( flrt_is_first_order_clause( $query ) ) {
            if ( isset( $query['value'] ) && array() === $query['value'] ) {
                unset( $query['value'] );
            }

            if( isset( $query['key'] ) ){
                if( in_array( $query['key'], $filter_keys['keys_variations'] ) ){
                    $separated_queries['for_variations'][ $key ] = $query;
                }else{
                    $separated_queries['for_products'][ $key ] = $query;
                }
            }

            // Otherwise, it's a nested query, so we recurse.
        } else {
            $sub_queries = flrt_sanitize_variations_meta_query( $query, $queried_filters );

            if ( ! empty( $sub_queries['for_variations'] ) ) {
                $separated_queries['for_variations'][ $key ] = $sub_queries['for_variations'];
            }

            if( ! empty( $sub_queries['for_products'] ) ){
                $separated_queries['for_products'][ $key ] = $sub_queries['for_products'];
            }
        }
    }

    if ( empty( $separated_queries['for_variations'] ) ) {
        return $separated_queries;
    }

    // Sanitize the 'relation' key provided in the query.
    if ( isset( $relation ) && 'OR' === strtoupper( $relation ) ) {
        $separated_queries['for_variations']['relation'] = 'OR';
//        $this->has_or_relation     = true;

        /*
        * If there is only a single clause, call the relation 'OR'.
        * This value will not actually be used to join clauses, but it
        * simplifies the logic around combining key-only queries.
        */
    } elseif ( 1 === count( $clean_queries ) ) {
        $separated_queries['for_variations']['relation'] = 'OR';

        // Default to AND.
    } else {
        $separated_queries['for_variations']['relation'] = 'AND';
    }

    return $separated_queries;
}

function flrt_is_all_not_exists( $queries ) {
    $all_not_exists = true;

    if ( ! is_array( $queries ) ) {
        return false;
    }

    foreach ( $queries as $key => $query ) {
        if ( 'relation' === $key ) {
            continue;

        } elseif ( ! is_array( $query ) ) {
            continue;

            // First-order clause.
        } elseif ( flrt_is_first_order_clause( $query ) ) {
            if( isset( $query['compare'] ) ){
                if( ! in_array( $query['compare'], array( 'NOT EXISTS' /*, 'NOT IN'*/ ) ) ){
                    $all_not_exists = false;
                    break;
                }
            }

            // Otherwise, it's a nested query, so we recurse.
        } else {
            $all_not_exists = flrt_is_all_not_exists( $query );
        }
    }

    return $all_not_exists;
}

function flrt_get_terms_ids_by_tax_query( $query ){
    if( ! isset( $query['terms'] ) || empty( $query['terms'] ) ){
        return false;
    }

    $args       = [ 'slug' => $query['terms'] ];
    $term_query = new WP_Term_Query();
    $term_list  = $term_query->query( $args );

    $term_list = wp_list_pluck( $term_list, 'term_id' );
    return '(' . implode( ",", $term_list ) . ')';
}