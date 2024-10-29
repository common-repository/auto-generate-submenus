<?php

/**
 * Plugin name: Auto Generate Submenus
 * Author: Willy Bahuaud
 * Description: With this plugin, you can add an automatically generated submenu for each menu item.
 * Version:     1.1
 * Stable tag: 1.1
 * Requires at least: 4.6
 * Text Domain: auto-generate-submenus
 * Author URI:  https://wabeo.fr/
 * License:     GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

/**
 * Switch walker for `wp_edit_nav_menu_walker`
 */
add_filter( 'wp_edit_nav_menu_walker', 'willy_wp_edit_nav_menu_walker' );
function willy_wp_edit_nav_menu_walker( $walker ) {
	$walker = 'Willy_Walker_Nav_Menu_Edit';
	return $walker;
}

/**
 * Load Willy_Walker_Nav_Menu_Edit
 *
 * This walker add new input fields on nav menus items fieldsets
 */
add_action( 'admin_init', 'willy_menu_pirouette' );
function willy_menu_pirouette() {
	require_once ABSPATH . 'wp-admin/includes/nav-menu.php';
	class Willy_Walker_Nav_Menu_Edit extends Walker_Nav_Menu_Edit {
		public function start_el( &$output, $item, $depth = 0, $args = array(), $id = 0 ) {
			// Get item start_el from parent class
			$temp = '';
			Walker_Nav_Menu_Edit::start_el( $temp, $item, $depth, $args, $id );

			if ( in_array( $item->type, array( 'post_type', 'taxonomy', 'post_type_archive' ) ) ) {
				// Add my new fields
				// -> build input for sub elements types
				$type = esc_html__( 'sub-items', 'auto-generate-submenus' );
				if ( 'taxonomy' === $item->type && is_taxonomy_hierarchical( $item->object ) ) {
					$type = vsprintf( '<select name="menu-item-autolist-type[%1$s]">
					<option value="sub-elems">%2$s</option>
					<option value="sub-terms" %4$s>%3$s</option>
					</select>', array(
						$item->ID,
						esc_html__( 'sub-items', 'auto-generate-submenus' ),
						esc_html__( 'sub-terms', 'auto-generate-submenus' ),
						selected( 'sub-terms', $item->autolist_type, false ),
					) );
				}
				// -> build input for sub elements number
				$input = sprintf( '<input type="number" style="width:3em;" name="menu-item-autolist-number[%1$s]" step="1" min="-1" value="%2$s">',
					$item->ID,
					is_int( $item->autolist_number ) && true === $item->autolist ? $item->autolist_number : get_option( 'posts_per_page' )
				);
				// -> insert fields
				$re = '/(<p class="field-description[\S\s]*?<\/p>)/';
				$subst = vsprintf( '$1<p class="field-autolist description"><label for="edit-menu-item-autolist-%1$s">
					<input type="checkbox" id="edit-menu-item-autolist-%1$s" value="oui" name="menu-item-autolist[%1$s]"%2$s /> %3$s
					</label></p>', array(
						$item->ID,
						checked( true, $item->autolist, false ),
						sprintf( __( 'Automatically list %1$s %2$s', 'auto-generate-submenus' ),
							$input,
							$type
						),
					)
				);
				$output .= preg_replace( $re, $subst, $temp );
			} else {
				$output .= $temp;
			}
		}
	}
}

/**
 * Add a checkbox field to conditionnaly hide/show the new fields
 */
add_filter( 'manage_nav-menus_columns', 'willy_manage_nav_menus_columns', 11 );
function willy_manage_nav_menus_columns( $columns ) {
	$columns['autolist'] = esc_html__( 'Display auto generated submenus lists properties', 'auto-generate-submenus' );
	return $columns;
}

/**
 * Update nav_menu_items with auto generated submenus properties
 */
add_action( 'wp_update_nav_menu_item', 'willy_wp_update_nav_menu_item', 11, 3 );
function willy_wp_update_nav_menu_item( $menu_id, $menu_item_db_id, $args ) {
	$value = isset( $_REQUEST['menu-item-autolist'][ $menu_item_db_id ] );
	update_post_meta( $menu_item_db_id, 'menu-item-autolist', $value );
	$value_num = $value ? intval( $_REQUEST['menu-item-autolist-number'][ $menu_item_db_id ] ) : false;
	update_post_meta( $menu_item_db_id, 'menu-item-autolist-number', $value_num );
	$value_type = $value ? esc_attr( $_REQUEST['menu-item-autolist-type'][ $menu_item_db_id ] ) : false;
	update_post_meta( $menu_item_db_id, 'menu-item-autolist-type', $value_type );
}

/**
 * Push auto generate submenus properties to each nav_menu_item
 */
add_filter( 'wp_setup_nav_menu_item', 'willy_wp_setup_nav_menu_item' );
function willy_wp_setup_nav_menu_item( $menu_item ) {
	$menu_item->autolist = boolval( get_post_meta( $menu_item->ID, 'menu-item-autolist', true ) );
	$menu_item->autolist_number = intval( get_post_meta( $menu_item->ID, 'menu-item-autolist-number', true ) );
	$menu_item->autolist_type = get_post_meta( $menu_item->ID, 'menu-item-autolist-type', true );
	return $menu_item;
}

/**
 * Depending a menu item property, load submenu lists
 */
add_filter( 'wp_get_nav_menu_items', 'willy_autolist_wp_nav_menu_objects' );
function willy_autolist_wp_nav_menu_objects( $items ) {
	// Dont load autolist items on admin-edit view
	if ( is_admin() || is_customize_preview() ) {
		return $items;
	}
	// Parse each items, and load autolist if it have the required meta
	foreach ( $items as $item ) {
		if ( $item->autolist && is_int( $item->autolist_number ) && 0 !== $item->autolist_number ) {
			switch ( $item->type ) {
				case 'post_type_archive':
					$autolist = willy_get_autolist_for_archive( array(
						'post_type' => $item->object,
						'number'    => $item->autolist_number,
						'parent'    => $item,
						'count'     => count( $items ),
					) );
					break;
				case 'post_type':
					if ( get_option( 'page_for_posts' ) === $item->object_id ) {
						$autolist = willy_get_autolist_for_archive( array(
							'post_type' => 'post',
							'number'    => $item->autolist_number,
							'parent'    => $item,
							'count'     => count( $items ),
						) );
					} else {
						$autolist = willy_get_autolist_for_post_parent( array(
							'post_type'   => $item->object,
							'post_parent' => $item->object_id,
							'number'      => $item->autolist_number,
							'parent'      => $item,
							'count'       => count( $items ),
						) );
					}
					break;
				case 'taxonomy':
					if ( 'sub-terms' === $item->autolist_type ) {
						$autolist = willy_get_autolist_for_taxonomy_terms( array(
							'taxonomy' => $item->object,
							'term_id'  => $item->object_id,
							'number'   => $item->autolist_number,
							'parent'   => $item,
							'count'    => count( $items ),
							'type-req' => 'terms',
						) );
					} else {
						$autolist = willy_get_autolist_for_taxonomy( array(
							'taxonomy' => $item->object,
							'term_id'  => $item->object_id,
							'number'   => $item->autolist_number,
							'parent'   => $item,
							'count'    => count( $items ),
						) );
					}
					break;
			}
			if ( ! empty( $autolist ) ) {
				$items = array_merge( $items, $autolist );
			}
		}
	}
	return $items;
}

/**
 * Callback for generate submenu for taxonomy items (return posts)
 * @param  [array] $args submenus properties
 * @return [array]       an array of menu items
 */
function willy_get_autolist_for_taxonomy( $args ) {
	$query = apply_filters( 'autolist_query', array(
		'suppress_filters' => false,
		'post_type'        => 'any',
		'posts_per_page'   => $args['number'],
		'no_found_rows'    => true,
		'tax_query'        => array(
			array(
				'taxonomy' => $args['taxonomy'],
				'terms'    => array( $args['term_id'] ),
			),
		),
	), 'taxonomy', $args );
	return willy_get_autolist( $query, $args );
}

/**
 * Callback for generate submenu for taxonomy terms (return terms)
 * @param  [array] $args submenus properties
 * @return [array]       an array of menu items
 */
function willy_get_autolist_for_taxonomy_terms( $args ) {
	$query = apply_filters( 'autolist_query', array(
		'taxonomy' => $args['taxonomy'],
		'number'   => $args['number'],
		'child_of' => $args['term_id'],
	), 'taxonomy_terms', $args );
	return willy_get_autolist( $query, $args );
}

/**
 * Callback for generate submenu for posts parents
 * @param  [array] $args submenus properties
 * @return [array]       an array of menu items
 */
function willy_get_autolist_for_post_parent( $args ) {
	$query = apply_filters( 'autolist_query', array(
		'suppress_filters' => false,
		'post_type'        => $args['post_type'],
		'posts_per_page'   => $args['number'],
		'no_found_rows'    => true,
		'post_parent'      => $args['post_parent'],
	), 'post_parent', $args );
	return willy_get_autolist( $query, $args );
}

/**
 * Callback for generate submenu for post type archives
 * @param  [array] $args submenus properties
 * @return [array]       an array of menu items
 */
function willy_get_autolist_for_archive( $args ) {
	$query = apply_filters( 'autolist_query', array(
		'suppress_filters' => false,
		'post_type'        => $args['post_type'],
		'posts_per_page'   => $args['number'],
		'no_found_rows'    => true,
	), 'archive', $args );
	return willy_get_autolist( $query, $args );
}

/**
 * Parse autolists queries then return a formated array of menu items
 * @param  [array] $args an array of posts/terms objects
 * @param  [array] $args submenus properties
 * @return [array]       an array of menu items objects
 */
function willy_get_autolist( $query, $args ) {
	$autolist = isset( $args['type-req'] ) && 'terms' === $args['type-req'] ? get_terms( $query ) : get_posts( $query );
	$vars = array( 'parent' => $args['parent']->ID, 'count' => $args['count'] + 1, 'term_q' => isset( $args['type-req'] ) && 'terms' === $args['type-req'] );
	array_walk( $autolist, 'willy_format_as_menu_item', $vars );
	return $autolist;
}

/**
 * Parse posts or terms to add menu items required properties
 * @param  [object] $p    post/term object
 * @param  [int]    $k    key
 * @param  [array]  $vars submenus properties
 * @return [object]       a single menu item
 */
function willy_format_as_menu_item( $p, $k, $vars ) {
	$p->post_content     = '';
	$p->post_excerpt     = '';
	$p->menu_item_parent = $vars['parent'];
	if ( $vars['term_q'] ) {
		$p->type         = 'taxonomy';
		$p->object       = $p->taxonomy;
		$p->object_id    = $p->term_ID;
		$p->url          = get_term_link( $p );
		$p->title        = $p->name;
	} else {
		$p->type         = 'post_type';
		$p->object_id    = $p->ID;
		$p->url          = get_permalink( $p );
		$p->title        = get_the_title( $p );
	}
	$p->classes          = array( 'sub-elem-auto' );
	$p->menu_order       = $vars['count'] + $k;
	return $p;
}
