<?php
/*
Plugin Name: News Hub Sections
Plugin URI: https://github.com/clas-web/news-hub-sections
Description: 
Version: 1.0.0
Author: Crystal Barton
Author URI: https://www.linkedin.com/in/crystalbarton
*/


if( !defined('NEWS_HUB_SECTIONS') ):

/**
 * The full title of the Connections Hub plugin.
 * @var  string
 */
define( 'NEWS_HUB_SECTIONS', 'News Hub Sections' );

/**
 * True if debug is active, otherwise False.
 * @var  bool
 */
define( 'NHS_DEBUG', false );

/**
 * The path to the plugin.
 * @var  string
 */
define( 'NHS_PLUGIN_PATH', __DIR__ );

/**
 * The url to the plugin.
 * @var  string
 */
define( 'NHS_PLUGIN_URL', plugins_url('', __FILE__) );

/**
 * The version of the plugin.
 * @var  string
 */
define( 'NHS_VERSION', '2.5.0' );

/**
 * The database version of the plugin.
 * @var  string
 */
define( 'NHS_DB_VERSION', '1.0' );

/**
 * The database options key for the Connections Hub version.
 * @var  string
 */
define( 'NHS_VERSION_OPTION', 'nhs-version' );

/**
 * The database options key for the Connections Hub database version.
 * @var  string
 */
define( 'NHS_DB_VERSION_OPTION', 'nhs-db-version' );

/**
 * The database options key for the sections list.
 * @var  string
 */
define( 'NHS_SECTIONS', 'nhs-sections' );

/**
 * The full path to the log file used to log a synch.
 * @var  string
 */
define( 'NHS_LOG_FILE', __DIR__.'log.txt' );

endif;


require_once( NHS_PLUGIN_PATH.'/classes/model.php' );
$nhs_model = NHS_Model::get_instance();
$nhs_sections = $nhs_model->get_sections_objects();


require_once( NHS_PLUGIN_PATH.'/classes/widgets/section-listing.php' );
NHS_WidgetSectionListingControl::register_widget();
NHS_WidgetSectionListingControl::register_shortcode();


if( is_admin() ):
	add_action( 'wp_loaded', 'nhs_load' );
endif;



/**
 * Setup the admin pages.
 */
if( !function_exists('nhs_load') ):
function nhs_load()
{
	require_once( __DIR__.'/admin-pages/require.php' );
	
	$pages = new APL_Handler( false );

	$pages->add_page( new NHS_SectionsAdminPage(NHS_SECTIONS) );
	$pages->setup();
	
	if( $pages->controller )
	{
		add_action( 'admin_enqueue_scripts', 'nhs_enqueue_scripts' );
		add_action( 'admin_menu', 'nhs_update', 5 );
	}
}
endif;


/**
 * Enqueue the admin page's CSS styles.
 */
if( !function_exists('nhs_enqueue_scripts') ):
function nhs_enqueue_scripts()
{
	wp_enqueue_style( 'nhs-style', UNHT_THEME_URL.'/admin-pages/styles/style.css' );
}
endif;


/**
 * Update the database if a version change.
 */
if( !function_exists('nhs_update') ):
function nhs_update()
{
	$version = get_theme_mod( NHS_DB_VERSION_OPTION, '1.0.0' );
	if( $version !== NHS_DB_VERSION )
	{
//		$model = OrgHub_Model::get_instance();
//		$model->create_tables();
	}
	
	set_theme_mod( NHS_DB_VERSION_OPTION, NHS_DB_VERSION );
	set_theme_mod( NHS_VERSION_OPTION, NHS_VERSION );
}
endif;


/**
 * 
 */
if( !function_exists('nhs_get_wpquery_section') ):
function nhs_get_wpquery_section( $wpquery = null )
{
	global $wp_query;

	if( $wpquery === null ) $wpquery = $wp_query;
	if( $wpquery->get('section') ) return $wpquery->get('section');
	
	$qo = $wpquery->get_queried_object();
	
	if( $wpquery->is_archive() )
	{
		if( $wpquery->is_tax() || $wpquery->is_tag() || $wpquery->is_category() )
		{
			$post_types = null;
			
			$taxonomy = get_taxonomy( $qo->taxonomy );
			if( $taxonomy ) $post_types = $taxonomy->object_type;
			
			$section = nhs_get_section( $post_types, array( $qo->taxonomy => $qo->slug ), false );
			$wpquery->set( 'section', $section );
			
			return $section;
		}
		elseif( $wpquery->is_post_type_archive() )
		{
			$section = nhs_get_section( $qo->name, null, false );
			$wpquery->set( 'section', $section );
			return $section;
		}

		return nhs_get_default_section();
	}
	
	if( $wpquery->is_single() )
	{
		if( $qo === null )
		{
			$post_id = $wp_query->get( 'p' );

			if( !$post_id )
			{
				global $wpdb;
				
				$post_type = $wp_query->get( 'post_type', false );
				if( !$post_type ) $post_type = 'post';
				
				$post_slug = $wp_query->get( 'name', false );
				
				if( $post_slug !== false )
					$post_id = $wpdb->get_var( "SELECT ID FROM $wpdb->posts WHERE post_type = '$post_type' AND post_name = '$post_slug'" );
			}
		}
		else
		{
			$post_id = $qo->ID;
		}
		
		if( $post_id )
		{
			$post_type = get_post_type( $post_id );
			$taxonomies = nh_get_taxonomies( $post_id );
			$section = nhs_get_section( $post_type, $taxonomies, false, array('news') );
		}
		else
		{
			$section = nhs_get_default_section();
		}
		
		$wpquery->set( 'section', $section );
		return $section;
	}
	
	return nhs_get_default_section();
}
endif;


/**
 * 
 */
if( !function_exists('nhs_get_section') ):
function nhs_get_section( $post_types, $taxonomies = array(), $return_null = false, $exclude_sections = array() )
{
	global $nhs_sections;

	$type_match = null;
	$partial_match = null;
	$exact_match = null;
	$best_count = 0;
	
	// 
	if( empty($post_types) ) $post_types = array( 'post' );
	if( !is_array($post_types) ) $post_types = array( $post_types );
	if( empty($taxonomies) ) $taxonomies = array();
	
	// cycle through each section looking for exact taxonomy and post type match
	foreach( $nhs_sections as $key => $section )
	{
		if( in_array($key, $exclude_sections) ) continue;
		if( !$section->is_post_type($post_types) ) continue;
		
		if( !$section->has_taxonomies() )
		{
			$type_match = $section;
			continue;
		}
		
		$section_count = $section->get_taxonomy_count();
		$taxonomy_count = 0;
		$match_count = 0;
		foreach( $taxonomies as $taxname => $terms )
		{
			if( is_array($terms) )
			{
				foreach( $terms as $term )
				{
					if( $section->has_term($taxname, $term) )
					{
						$match_count++;
					}
					$taxonomy_count++;
				}
			}
			else
			{
				if( $section->has_term($taxname, $terms) )
				{
					$match_count++;
				}
				$taxonomy_count++;
			}
		}
		
		if( ($taxonomy_count == $match_count) && ($taxonomy_count == $section_count) )
		{
			$exact_match = $section;
			break;
		}
		
		if( $match_count > $best_count )
		{
			$partial_match = $section;
			$best_count = $match_count;
		}
	}
	
	// 
	if( $exact_match !== null ) return $exact_match;
	if( $partial_match !== null ) return $partial_match;
	if( $type_match !== null ) return $type_match;
	
	
	// Done.
	if( $return_null ) return null;
	return nhs_get_default_section();
}
endif;


/**
 * 
 */
if( !function_exists('nhs_get_default_section') ):
function nhs_get_default_section()
{
	return new NHS_Section( array('name' => 'none') );
}
endif;
	

/**
 * 
 */
if( !function_exists('nhs_get_empty_section') ):
function nhs_get_empty_section()
{
	return new NHS_Section( array('name' => '') );
}
endif;
