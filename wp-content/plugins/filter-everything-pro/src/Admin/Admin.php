<?php


namespace FilterEverything\Filter;

if ( ! defined('WPINC') ) {
    wp_die();
}

class Admin
{
    public $tabRenderer;

    public function __construct()
    {
        add_action( 'admin_menu', array($this, 'adminMenu'), 9);
        $this->tabRenderer = Container::instance()->getTabRenderer();
        $filterSet = Container::instance()->getFilterSetService();

        add_action( 'pre_post_update', [$filterSet, 'preSaveSet'], 10, 2 );
        add_action( 'save_post', array( $filterSet, 'saveSet' ), 10, 2 );

        add_action( 'init', array( $this, 'initTabs' ), 11 );

        add_action( 'admin_init', array( $this, 'init' ) );
    }

    public function init()
    {
        $filterFields = Container::instance()->getFilterFieldsService();
        $filterFields->registerHooks();

        // Check permissions before to show these screens
        add_action( 'load-post.php', [ $this, 'checkPermissions' ] );
        add_action( 'load-edit.php', [ $this, 'checkPermissions' ] );
        add_action( 'load-post-new.php', [ $this, 'checkPermissions' ] );

    }

    public function adminMenu()
    {
        $page = 'edit.php?post_type=' . FLRT_FILTERS_SET_POST_TYPE;

        add_menu_page( esc_html__('Filters', 'filter-everything'), esc_html__('Filters', 'filter-everything'), 'manage_options', $page, false,  $this->get_icon_svg(), '85');

        add_submenu_page( $page, esc_html__('Filter Sets', 'filter-everything'), esc_html__('Filter Sets', 'filter-everything'), 'manage_options', $page);
        add_submenu_page( $page, esc_html__('Add New', 'filter-everything'), esc_html__('Add New', 'filter-everything'), 'manage_options', 'post-new.php?post_type=' . FLRT_FILTERS_SET_POST_TYPE);

        do_action('wpc_add_submenu_pages');

        add_submenu_page( $page, esc_html__('Settings', 'filter-everything'), esc_html__('Settings', 'filter-everything'), 'manage_options', 'filters-settings', array($this, 'filterSettingsPage'));
    }

    public function filterSettingsPage()
    {
        $this->tabRenderer->render();
    }

    public function initTabs()
    {
        $this->tabRenderer->register(new SettingsTab());
        $this->tabRenderer->register(new PermalinksTab());

        do_action( 'wpc_setttings_tabs_register', $this->tabRenderer );

        $this->tabRenderer->register(new ExperimentalTab());

        if( ! defined('FLRT_FILTERS_PRO') ) {
            $this->tabRenderer->register(new AboutProTab());
        }

        $this->tabRenderer->init();
    }

    public function get_icon_svg()
    {
        return flrt_get_icon_svg();
    }

    public function checkPermissions(){
        $screen     = get_current_screen();
        $post_types = [ FLRT_FILTERS_SET_POST_TYPE, FLRT_FILTERS_POST_TYPE ];

        if( defined('FLRT_FILTERS_PRO') && FLRT_FILTERS_PRO ){
            $post_types[] = FLRT_SEO_RULES_POST_TYPE;
        }

        if( isset( $screen->post_type ) && in_array( $screen->post_type, $post_types, true ) ){
            if( ! current_user_can( 'manage_options' ) ) {
                wp_die( esc_html__( 'Sorry, you are not allowed to access this page.' ) );
            }
        }
    }

}