<?php
    
    /*
     * Plugin Name: Teolaz CPT-Page Relation
     * Plugin URI: teolaz@alice.it
     * Description: This plugin allows to associate a CPT (slug, archive, etc) to a single page, to build the IA correctly
     * Version: 0.1
     * Author: Matteo Lazzarin
     * Author URI: teolaz@alice.it
     * License: Internal use only
     */

// unregister post type function
    if ( !function_exists('unregister_post_type') ) :
        
        function unregister_post_type( $post_type )
        {
            global $wp_post_types;
            if ( isset( $wp_post_types[ $post_type ] ) ) {
                unset( $wp_post_types[ $post_type ] );
                
                return true;
            }
            
            return false;
        }
    
    endif;
    
    foreach ( glob(plugin_dir_path(__FILE__) . "includes/*.php") as $file )
        include_once $file;
    
    define(TEXTDOMAIN, 'Teolaz Plugins');
    
    class TeolazCptPageRelation
    {
        
        private $languages;
        
        private $post_page_rel;
        
        private static $depthRewriteCptRegistration = -1;
        
        //TODO: correct this shit
        function flushIfNeeded()
        {
            $ctps = get_post_types(array(
                'public'   => true,
                '_builtin' => false,
            ), 'objects', 'and');
            
            // get current language
            if ( $this->languages == null )
                $language = 'default';
            else
                $language = ICL_LANGUAGE_CODE;
            
            foreach ( $ctps as $k => $ctp ) {
                if ( $k == 'slide' )
                    continue;
                if ( !isset( $this->post_page_rel[ $k ] ) )
                    continue;
                $page = $this->post_page_rel[ $k ][ $language ];
                $rules = get_option('rewrite_rules', array());
                
                if ( !array_key_exists($this->get_mod_slug($k, $language) . '/?$', $rules) ) {
                    global $wp_rewrite;
                    $wp_rewrite->flush_rules();
                    
                    return;
                }
            }
        }
        
        function __construct()
        {
            // here, before the init hook
            $this->post_page_rel = (array)get_option('teolaz-cpt-page-relation', null);
            $this->languages = null;
            if ( function_exists('icl_get_languages') ) {
                $this->languages = icl_get_languages('skip_missing=0&orderby=name');
            }
            
            // add_action('init', function(){
            // global $wp_rewrite;
            // $wp_rewrite->flush_rules( false );
            // });
            
            // Flush rewrites if needed
            // For example on language change
            add_action('wp_loaded', array(
                $this,
                'flushIfNeeded',
            ));
            
            /**
             * @deprecated from v1.1, uses register_post_type_args instead
             */
//            add_action('registered_post_type', array(
//                $this,
//                'rewriteCptRegistration',
//            ), 10000, 2);
            
            add_filter('register_post_type_args', array(
                $this,
                'rewriteCPTArgs',
            ), 10000, 2);
            
            
            add_action('wp_nav_menu_objects', array(
                $this,
                'sortMenuItems',
            ));
            // this add the correct links to breadcrumbs if wpseo is installed
            add_filter('wpseo_breadcrumb_links', array(
                $this,
                'add_links_to_bc',
            ));
            
            //add title and description from yoast seo metabox in page
            add_filter('wpseo_title', array(
                $this,
                'modify_wpseo_title',
            ));
            
            add_filter('wpseo_metadesc', array(
                $this,
                'modify_wpseo_description',
            ));
            
            //add opengraph, twitter and googleplus metas from social tab
            
            add_filter('wpseo_opengraph_title', array(
                $this,
                'modify_wpseo_opengraph_title',
            ));
            
            add_filter('wpseo_opengraph_desc', array(
                $this,
                'modify_wpseo_opengraph_description',
            ));
            
            add_filter('wpseo_twitter_title', array(
                $this,
                'modify_wpseo_twitter_title',
            ));
            
            add_filter('wpseo_twitter_desc', array(
                $this,
                'modify_wpseo_twitter_description',
            ));
            
            //do all the Googleplus part on custom post archive pages
            add_action('wpseo_googleplus', array(
                $this,
                'redo_wpseo_googleplus',
            ));
            
            //workaround for social images
            add_action('wpseo_opengraph', array(
                $this,
                'enable_wpseo_opengraph_image',
            ), 29);
            
            add_action('wpseo_twitter', array(
                $this,
                'enable_wpseo_twitter_image',
            ), 10);
            
            //disable default image on post type archives (the default image is already printed by enable_wpseo_opengraph_image if present)
            add_filter('wpseo_opengraph_image', array(
                $this,
                'disable_wpseo_opengraph_default_image',
            ));
            
            add_filter('wpseo_twitter_image', array(
                $this,
                'disable_wpseo_twitter_default_image',
            ));
            
            //add new ACF location rule type
            add_filter('acf/location/rule_types', array(
                $this,
                'modify_rule_types',
            ));
            
            //add values to the ACF created above
            add_filter('acf/location/rule_values/cpt_rel', array(
                $this,
                'modify_rule_values',
            ));
            
            //match created rule on edit page view
            add_filter('acf/location/rule_match/cpt_rel', array(
                $this,
                'match_rule_to_show_acf_group',
            ), 10, 3);
            
            // init admin interface
            new TeolazCptPageRelationAdminInterface();
        }
        
        /**
         * thanks to "Current Menu Item for Custom Post Types" plugin for custom
         * post type current
         */
        function sortMenuItems( $sorted_menu_items )
        {
            
            $ctps = get_post_types(array(
                'public'   => true,
                '_builtin' => false,
            ), 'objects', 'and');
            $page_related_to_cpt = array();
            $parent = false;
            
            // get current language
            if ( $this->languages == null )
                $language = 'default';
            else
                $language = ICL_LANGUAGE_CODE;
            
            foreach ( $ctps as $k => $ctp ) {
                if ( $k == 'slide' )
                    continue;
                if ( !isset( $this->post_page_rel[ $k ] ) )
                    continue;
                $page = $this->post_page_rel[ $k ][ $language ];
                if ( !empty( $page ) ) {
                    $page_related_to_cpt[ $k ][ $language ] = $page;
                }
            }
            
            foreach ( $sorted_menu_items as $menuItem ) {
                
                if ( in_array('menu-item-has-children', $menuItem->classes) ) {
                    array_push($menuItem->classes, 'dropdown');
                }
                if ( !is_search() ) {
                    foreach ( $page_related_to_cpt as $k => $v ) {
                        if ( $menuItem->object_id == $v[ $language ] && get_post_type() == $k ) {
                            array_push($menuItem->classes, 'current-menu-item');
                            $parent = $menuItem->menu_item_parent;
                        }
                    }
                }
            }
            if ( !is_search() ) {
                while ( $parent != 0 ) {
                    foreach ( $sorted_menu_items as $menuItem ) {
                        if ( $menuItem->ID == $parent ) {
                            array_push($menuItem->classes, 'current-menu-ancestor');
                            $parent = $menuItem->menu_item_parent;
                        }
                    }
                }
            }
            
            return $sorted_menu_items;
        }
        
        function get_mod_slug( $post_type, $language_code = 'default' )
        {
            $page_post = get_post($this->post_page_rel[ $post_type ][ $language_code ]);
            if ( is_object($page_post) && $page_post->ID ) {
                $slug_arr = array();
                $page_post_ancestors_reverse = array_reverse($page_post->ancestors);
                foreach ( $page_post_ancestors_reverse as $page_post_ancestor ) {
                    $slug_arr[] = get_post($page_post_ancestor)->post_name;
                }
                $slug_arr[] = $page_post->post_name;
                
                return implode('/', $slug_arr);
            }
        }
        
        /**
         * @param $post_type
         * @param $args
         * @deprecated from v1.1, uses rewriteCPTArgs instead
         */
        function rewriteCptRegistration( $post_type, $args )
        {
			die('This action was deprecated from v1.1');
            // ++self::$depthRewriteCptRegistration;
            
            // if ( self::$depthRewriteCptRegistration === 0 ) {
                unregister post_type if it's inside the array of pages for custom posts
                
                // if ( array_key_exists($post_type, $this->post_page_rel) ) {
                    get current language
                    // if ( $this->languages == null )
                        // $language = 'default';
                    // else
                        // $language = ICL_LANGUAGE_CODE;
                    
                    get $args as array
                    // if ( is_object($args) )
                        // $args = get_object_vars($args);
                    // if ( is_object($args[ 'labels' ]) )
                        // $args[ 'labels' ] = get_object_vars($args[ 'labels' ]);
                    // if ( is_object($args[ 'cap' ]) )
                        // $args[ 'cap' ] = get_object_vars($args[ 'cap' ]);
                    
                    get modded slug
                    // $slug = $this->get_mod_slug($post_type, $language);
                    // if ( !empty( $slug ) ) :
                        // if ( $post_type != 'slides' && $args[ 'rewrite' ][ 'slug' ] != $slug ) :
                            // unregister_post_type($post_type);
                            // $args[ 'rewrite' ] = array(
                                // 'slug'       => $slug,
                                // 'feeds'      => false,
                                // 'with_front' => false,
                            // );
                            // register_post_type($post_type, $args);
                        
                        // endif;
                    
                    // endif;
                // }
            // }
            
            // --self::$depthRewriteCptRegistration;
        }
        
        function rewriteCPTArgs( $args, $post_type )
        {
            if ( array_key_exists($post_type, $this->post_page_rel) ) {
                // get current language
                if ( $this->languages == null )
                    $language = 'default';
                else
                    $language = ICL_LANGUAGE_CODE;
                
                // get modded slug
                $slug = $this->get_mod_slug($post_type, $language);
                
                if ( !empty( $slug ) && $post_type != 'slides' && $args[ 'rewrite' ][ 'slug' ] != $slug ) {
                    $args[ 'rewrite' ] = array(
                        'slug'       => $slug,
                        'feeds'      => false,
                        'with_front' => false,
                    );
                }
            }
            
            return $args;
        }
        
        // Breadcrumbs mod on rewritten rules for cpt
        function add_links_to_bc( $links )
        {
            //filter ptarchives to change archive link to page link
            foreach ( $links as &$link ) {
                if ( array_key_exists('ptarchive', $link) ) {
                    $page = getRelatedPageForCPT($link[ 'ptarchive' ]);
                    if ( $page ) {
                        $link[ 'id' ] = $page;
                        unset( $link[ 'ptarchive' ] );
                    }
                }
            }
            
            return $links;
        }
        
        function modify_wpseo_title( $title )
        {
            if ( is_post_type_archive() ) {
                $obj = get_queried_object();
                $page = getRelatedPageForCPT($obj->name);
                if ( $page && get_post_meta($page, '_yoast_wpseo_title', true) != '' )
                    return get_post_meta($page, '_yoast_wpseo_title', true);
            }
            
            return $title;
        }
        
        function modify_wpseo_description( $desc )
        {
            if ( is_post_type_archive() ) {
                $obj = get_queried_object();
                $page = getRelatedPageForCPT($obj->name);
                if ( $page && get_post_meta($page, '_yoast_wpseo_metadesc', true) != '' )
                    return get_post_meta($page, '_yoast_wpseo_metadesc', true);
            }
            
            return $desc;
        }
        
        function modify_wpseo_opengraph_title( $title )
        {
            if ( is_post_type_archive() ) {
                $obj = get_queried_object();
                $page = getRelatedPageForCPT($obj->name);
                if ( $page && get_post_meta($page, '_yoast_wpseo_opengraph-title', true) != '' )
                    return get_post_meta($page, '_yoast_wpseo_opengraph-title', true);
            }
            
            return $title;
        }
        
        function modify_wpseo_opengraph_description( $desc )
        {
            if ( is_post_type_archive() ) {
                $obj = get_queried_object();
                $page = getRelatedPageForCPT($obj->name);
                if ( $page && get_post_meta($page, '_yoast_wpseo_opengraph-description', true) != '' )
                    return get_post_meta($page, '_yoast_wpseo_opengraph-description', true);
            }
            
            return $desc;
        }
        
        function modify_wpseo_twitter_title( $title )
        {
            if ( is_post_type_archive() ) {
                $obj = get_queried_object();
                $page = getRelatedPageForCPT($obj->name);
                if ( $page && get_post_meta($page, '_yoast_wpseo_twitter-title', true) != '' )
                    return get_post_meta($page, '_yoast_wpseo_twitter-title', true);
            }
            
            return $title;
        }
        
        function modify_wpseo_twitter_description( $desc )
        {
            if ( is_post_type_archive() ) {
                $obj = get_queried_object();
                $page = getRelatedPageForCPT($obj->name);
                if ( $page && get_post_meta($page, '_yoast_wpseo_twitter-description', true) != '' )
                    return get_post_meta($page, '_yoast_wpseo_twitter-description', true);
            }
            
            return $desc;
        }
        
        function modify_wpseo_googleplus_title( $title )
        {
            if ( is_post_type_archive() ) {
                $obj = get_queried_object();
                $page = getRelatedPageForCPT($obj->name);
                if ( $page && get_post_meta($page, '_yoast_wpseo_google-plus-title', true) != '' )
                    return get_post_meta($page, '_yoast_wpseo_google-plus-title', true);
            }
            
            return $title;
        }
        
        function modify_wpseo_googleplus_description( $desc )
        {
            if ( is_post_type_archive() ) {
                $obj = get_queried_object();
                $page = getRelatedPageForCPT($obj->name);
                if ( $page && get_post_meta($page, '_yoast_wpseo_google-plus-description', true) != '' )
                    return get_post_meta($page, '_yoast_wpseo_google-plus-description', true);
            }
            
            return $desc;
        }
        
        function enable_wpseo_opengraph_image()
        {
            if ( is_post_type_archive() ) {
                $object = get_queried_object();
                if ( $object && isset( $object->name ) ) {
                    $ID = getRelatedPageForCPT($object->name);
                    //first check if yoast seo metabox contains the image url
                    if ( $ID && get_post_meta($ID, '_yoast_wpseo_opengraph-image', true) ) {
                        $img = get_post_meta($ID, '_yoast_wpseo_opengraph-image', true);
                        $property = 'og:image';
                        $content = $img[ 0 ];
                        echo '<meta property="', esc_attr($property), '" content="', esc_attr($content), '" />', "\n";
                        
                        return;
                    }
                    if ( $ID && get_post_thumbnail_id($ID) != '' ) {
                        $img = wp_get_attachment_image_src(get_post_thumbnail_id($ID), 'full');
                        $property = 'og:image';
                        $content = $img[ 0 ];
                        echo '<meta property="', esc_attr($property), '" content="', esc_attr($content), '" />', "\n";
                        
                        return;
                    }
                }
                $options = get_site_option('wpseo_social', null);
                if ( $options && is_array($options) ) {
                    $property = 'og:image';
                    $content = $options[ 'og_default_image' ];
                    echo '<meta property="', esc_attr($property), '" content="', esc_attr($content), '" />', "\n";
                }
            }
        }
        
        function disable_wpseo_opengraph_default_image( $img )
        {
            if ( is_post_type_archive() ) {
                $img = '';
            }
            
            return $img;
        }
        
        function enable_wpseo_twitter_image()
        {
            if ( is_post_type_archive() ) {
                $object = get_queried_object();
                if ( $object && isset( $object->name ) ) {
                    $ID = getRelatedPageForCPT($object->name);
                    //first check if yoast seo metabox contains the image url
                    if ( $ID && get_post_meta($ID, '_yoast_wpseo_opengraph-image', true) ) {
                        $img = get_post_meta($ID, '_yoast_wpseo_opengraph-image', true);
                        $property = 'twitter:image';
                        $content = $img[ 0 ];
                        echo '<meta name="', esc_attr($property), '" content="', esc_attr($content), '" />', "\n";
                        
                        return;
                    }
                    if ( $ID && get_post_thumbnail_id($ID) != '' ) {
                        $img = wp_get_attachment_image_src(get_post_thumbnail_id($ID), 'full');
                        $property = 'twitter:image';
                        $content = $img[ 0 ];
                        echo '<meta name="', esc_attr($property), '" content="', esc_attr($content), '" />', "\n";
                        
                        return;
                    }
                }
                $options = get_site_option('wpseo_social', null);
                if ( $options && is_array($options) ) {
                    $property = 'twitter:image';
                    $content = $options[ 'og_default_image' ];
                    echo '<meta name="', esc_attr($property), '" content="', esc_attr($content), '" />', "\n";
                }
            }
        }
        
        function disable_wpseo_twitter_default_image( $img )
        {
            if ( is_post_type_archive() ) {
                $img = '';
            }
            
            return $img;
        }
        
        function redo_wpseo_googleplus()
        {
            if ( is_post_type_archive() ) {
                $object = get_queried_object();
                if ( $object && isset( $object->name ) ) {
                    $ID = getRelatedPageForCPT($object->name);
                    if ( $ID && get_post_meta($ID, '_yoast_wpseo_google-plus-title', true) && get_post_meta($ID, '_yoast_wpseo_google-plus-title', true) != '' ) {
                        $title = get_post_meta($ID, '_yoast_wpseo_google-plus-title', true);
                        echo '<meta itemprop="name" content="', esc_attr($title), '">', "\n";
                    }
                    if ( $ID && get_post_meta($ID, '_yoast_wpseo_google-plus-description', true) && get_post_meta($ID, '_yoast_wpseo_google-plus-description', true) != '' ) {
                        $desc = get_post_meta($ID, '_yoast_wpseo_google-plus-description', true);
                        echo '<meta itemprop="description" content="', esc_attr($desc), '">', "\n";
                    }
                    if ( $ID && get_post_meta($ID, '_yoast_wpseo_google-plus-image', true) && get_post_meta($ID, '_yoast_wpseo_google-plus-image', true) != '' ) {
                        $img = get_post_meta($ID, '_yoast_wpseo_google-plus-image', true);
                        echo '<meta itemprop="image" content="', esc_url($img), '">', "\n";
                    }
                }
            }
        }
        
        function modify_rule_types( $choices )
        {
            $choices[ 'Custom' ][ 'cpt_rel' ] = __('Page related to archive type (Teolaz plugin)', TEXTDOMAIN);
            
            return $choices;
        }
        
        function modify_rule_values( $choices )
        {
            $post_types = get_post_types(array( 'public' => true, '_builtin' => false ), 'object');
            foreach ( $post_types as $post_type ) {
                $rel_page = getRelatedPageForCPT($post_type->name);
                if ( $rel_page ) {
                    $choices[ $post_type->name ] = $post_type->label;
                }
            }
            
            return $choices;
        }
        
        function match_rule_to_show_acf_group( $match, $rule, $options )
        {
            global $post;
            
            if ( $post && $post->post_type == 'page' && $this->isCPTArchive($post->ID) ) {
                $cpt = $this->getCPTFromPageID($post->ID);
                
                if ( $cpt && $rule[ 'operator' ] == "==" ) {
                    $match = ( $rule[ 'value' ] == $cpt );
                } elseif ( $cpt && $rule[ 'operator' ] == "!=" ) {
                    $match = ( $rule[ 'value' ] != $cpt );
                }
            }
            
            return $match;
        }
        
        /*
         * This function, given an ID, allows to return the custom post type related to page ID
         *
         */
        protected function getCPTFromPageID( $ID, $language = null )
        {
            if ( is_null($language) ) {
                if ( defined('ICL_LANGUAGE_CODE') )
                    $language = ICL_LANGUAGE_CODE;
                else
                    $language = 'default';
            }
            $cpts = get_option('teolaz-cpt-page-relation', null);
            foreach ( $cpts as $key => $cpt ) {
                if ( array_key_exists($language, $cpt) && $cpt[ $language ] == $ID ) {
                    return $key;
                }
            }
            
            return false;
        }
        
        /*
         * This function, given an ID, return true if and only if the ID is an archive page
         *
         */
        protected function isCPTArchive( $ID, $language = null )
        {
            if ( is_null($language) ) {
                if ( defined('ICL_LANGUAGE_CODE') )
                    $language = ICL_LANGUAGE_CODE;
                else
                    $language = 'default';
            }
            $cpts = get_option('teolaz-cpt-page-relation', null);
            foreach ( $cpts as $key => $cpt ) {
                if ( array_key_exists($language, $cpt) && $cpt[ $language ] == $ID ) {
                    return true;
                }
            }
            
            return false;
        }
    }
    
    $TLCptPageRelation = new TeolazCptPageRelation();
    
    /*
     * HELPERS
     */
    
    /*
     * get page id from a given custom post type name
     */
    function getRelatedPageForCPT( $cpt, $language = null )
    {
        if ( is_null($language) ) {
            if ( defined('ICL_LANGUAGE_CODE') )
                $language = ICL_LANGUAGE_CODE;
            else
                $language = 'default';
        }
        $opt = get_option('teolaz-cpt-page-relation', null);
        if ( isset( $opt[ $cpt ] ) && isset( $opt[ $cpt ][ $language ] ) && $opt[ $cpt ][ $language ] != '' ):
            return $opt[ $cpt ][ $language ];
        else:
            return false;
        endif;
    }
    
    function getCPTPageTitle( $cpt = '' )
    {
        if ( $cpt != '' ) {
            $ID = getRelatedPageForCPT($cpt);
            if ( $ID )
                $title = get_the_title($ID);
        } else {
            if ( is_post_type_archive() ) {
                $obj = get_queried_object();
                $ID = getRelatedPageForCPT($obj->name);
                if ( $ID )
                    $title = get_the_title($ID);
            }
        }
        
        return $title;
    }
    
    function getCPTPageContent( $cpt = '' )
    {
        $content = '';
        if ( $cpt != '' ) {
            $ID = getRelatedPageForCPT($cpt);
            if ( $ID )
                $content = apply_filters('the_content', get_post_field('post_content', $ID));
        } else {
            if ( is_post_type_archive() ) {
                $obj = get_queried_object();
                $ID = getRelatedPageForCPT($obj->name);
                if ( $ID )
                    $content = apply_filters('the_content', get_post_field('post_content', $ID));
            }
        }
        
        return $content;
    }
    
    function getCPTPageLink( $cpt = '' )
    {
        if ( $cpt != '' ) {
            $ID = getRelatedPageForCPT($cpt);
            if ( $ID )
                $link = get_permalink($ID);
        } else {
            if ( is_post_type_archive() ) {
                $obj = get_queried_object();
                $ID = getRelatedPageForCPT($obj->name);
                if ( $ID )
                    $link = get_permalink($ID);
            }
        }
        
        return $link;
    }
    
    function getCPTPageThumb( $size = 'full', $args = '', $cpt = '' )
    {
        $img = '';
        if ( $cpt != '' ) {
            $ID = getRelatedPageForCPT($cpt);
            if ( $ID )
                $img = get_the_post_thumbnail($ID, $size, $args);
        } else {
            if ( is_post_type_archive() ) {
                $obj = get_queried_object();
                $ID = getRelatedPageForCPT($obj->name);
                if ( $ID )
                    $img = get_the_post_thumbnail($ID, $size, $args);
            }
        }
        
        return $img;
    }
    
    function getCPTPageThumbUrl( $size = 'full', $args = '', $cpt = '' )
    {
        $img = '';
        if ( $cpt != '' ) {
            $ID = getRelatedPageForCPT($cpt);
            if ( $ID )
                $img = get_the_post_thumbnail_url($ID, $size, $args);
        } else {
            if ( is_post_type_archive() ) {
                $obj = get_queried_object();
                $ID = getRelatedPageForCPT($obj->name);
                if ( $ID )
                    $img = get_the_post_thumbnail_url($ID, $size, $args);
            }
        }
        
        return $img;
    }
    
    function getCPTPageField( $field, $cpt = '' )
    {
        $mixed = null;
        if ( $cpt != '' ) {
            $ID = getRelatedPageForCPT($cpt);
            if ( $ID )
                $mixed = get_field($field, $ID);
        } else {
            if ( is_post_type_archive() ) {
                $obj = get_queried_object();
                $ID = getRelatedPageForCPT($obj->name);
                if ( $ID )
                    $mixed = get_field($field, $ID);
            }
        }
        
        return $mixed;
    }
