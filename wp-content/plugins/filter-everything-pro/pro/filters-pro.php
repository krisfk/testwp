<?php

if ( ! defined('WPINC') ) {
    wp_die();
}

use FilterEverything\Filter\Pro\Admin\Admin;
use FilterEverything\Filter\Container;

if( ! class_exists('FiltersPro') ):

class FiltersPro{

    function __construct()
    {
        $wpcFilter = flrt_filter();
        $wpcFilter->define( 'FLRT_SEO_RULES_POST_TYPE', 'filter-seo-rule' );
        $wpcFilter->define( 'FLRT_FILTERS_PRO', true );

        flrt_include('pro/Entities/PostMetaExistsEntity.php');
        flrt_include('pro/wpc-default-hooks-pro.php');
        flrt_include('pro/wpc-utility-functions.php');
        flrt_include('pro/PluginPro.php');
        flrt_include('pro/PostTypes.php');


        flrt_include('pro/SeoFrontend.php');
        flrt_include('pro/Settings/Tabs/SeoRulesTab.php');
        flrt_include('pro/Settings/Tabs/IndexingDepth.php');
        flrt_include('pro/Admin/SeoRules.php');

        flrt_include('pro/Admin/Admin.php');
        flrt_include('pro/Admin/MetaBoxes.php');
        flrt_include('pro/Admin/ShortcodesPro.php');

        if( is_admin() ){
            new Admin();
        }

        add_action( 'wp', [ $this, 'wpInit'], -1 );
    }

    public function wpInit()
    {
        $seoFrontend = Container::instance()->getSeoFrontendService();
        $seoFrontend->processPageSeo();
    }

}

new FiltersPro();

endif;