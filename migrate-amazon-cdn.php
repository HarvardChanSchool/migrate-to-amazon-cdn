<?php
/*
Plugin Name: Migrate Amazon CDN
Plugin URI: http://www.hsph.harvard.edu/
Description: A plugin to migrate Multisite files from blogs.dir to the uploads directory. 
Version: 0.1
Network: true
Author: David Marshall/HSPH WebTeam
Author URI: http://www.hsph.harvard.edu/
*/

// if we are not a multisite then kill the plugin
if ( ! is_multisite() ) {
	wp_die( __( 'Multisite support is not enabled.' ) );
}

// this is not for ordpress MU networks
if ( ! defined( 'MULTISITE' ) ) {
	wp_die( __( 'This plugin is not for WordPress MU networks.' ) );
}

// well er have gotten here and we are in an admin so allow for activation
if ( is_admin() ) {
	register_activation_hook( __FILE__, 'migrate_amazon_cdn_activation' );
}

// turn off the MS files rewriting filter
// this will be redundant post activation but it is here
//remove_filter( 'default_site_option_ms_files_rewriting', '__return_true' );

/**
 * Run on activation. Check if we are or are not able to even use this plugin
 *
 * @since Migrate Amazon CDN 1.0
 */
function migrate_amazon_cdn_activation() {
	// we need to do a version check
	global $wp_version;
	
	// version compare
	if (version_compare($wp_version,"3.5","<")) {
		exit ( 'This requires WordPress 3.5 or newer. <a href="http://codex.wordpress.org/Upgrading_WordPress">Please update</a>' );
	}

	// is ms files rewriting on?
	if ( get_site_option( 'ms_files_rewriting' ) != 0) {
		exit ( 'This plugin requires that ms-files.php be turned off. <a href="http://core.trac.wordpress.org/ticket/19235" target="_blank">Please diable ms-files.php and try again.</a>' );
	}
		
}

/**
 * add a page to each site's admin menu	
 *
 * @since Migrate Amazon CDN 1.0
 */
		
function migrate_amazon_cdn_admin_setup() {
	add_submenu_page( 'settings.php', 'Migrate AWS', 'Migrate AWS', 'manage_network', 'migrate_amazon_cdn_page', 'migrate_amazon_cdn_page');
}

add_action( 'network_admin_menu', 'migrate_amazon_cdn_admin_setup' );

/**
 * Callback for the menu page from above
 * If we are to process an action do it
 * Otherwise display the appropriate activate otr deactivate page. 
 *
 * @since Migrate Amazon CDN 1.0
 */
function migrate_amazon_cdn_page() {  		
	echo '<div class="wrap">';
	echo '<h2>' . __( 'Migrate Amazon CDN' ) . '</h2>';
	
	// get the URL to update as the CDN
	if ( get_site_option( 'tantan_wordpress_s3' ) != false ) {
		// get the tantan options
		$cdn_options = get_site_option( 'tantan_wordpress_s3' );
		
		// get the CDN URL
		$cdn_url = 'http://' . $cdn_options['cloudfront'];
	} else {
		$cdn_url = false;
	}
		
	// do we have a URL set and an action to switch the CDN
	if ( empty( $_POST[ 'action' ] ) || $cdn_url == false ) {
		// check our ms files is enabled. If so, then show the ability to undo it
		// otherwise allow for us to change it
		if ( get_site_option( 'migrate_amazon_cdn' ) != false ) {
			show_migrate_amazon_cdn_undo_info();
		} else {
			show_migrate_amazon_cdn_update_info();
		}
	} else {
		// check and then go
		check_admin_referer( 'migrate_amazon_cdn' );
		
		if ( $_POST[ 'action' ] == 'update' ) {
			migrate_amazon_cdn_update( 'update', $cdn_url );
		}
		
		if ( $_POST[ 'action' ] == 'undo' ) {
			migrate_amazon_cdn_update( 'undo', $cdn_url );
		}
	}
	
	echo '</div>';
}

/**
 * Display the text for the reactivation of ms files (undo)
 *
 * @since Migrate Amazon CDN 1.0
 */
function show_migrate_amazon_cdn_undo_info() {
	?>
	<p>You are running on a CDN</p>
	<p>Please verify all the links on the site are intact and working. You can use the <strong>Broken Link Checker</strong> to help identify links that need to be changed</p>
	<h2>Need to undo?</h2>
	<p>If you would like to re-enable serving files from your local server, click the undo button below. This will set the URLs back to the site url.</p>
	<?php
	echo "<form method='POST'><input type='hidden' name='action' value='undo' />";
	wp_nonce_field( 'migrate_amazon_cdn' );
	echo "<p><input type='submit' class='button-secondary' value='" . __( 'Undo' ) . "' /></p>";
	echo "</form>";
}

/**
 * Display text for the deactivation of ms files 
 *
 * @since Migrate Amazon CDN 1.0
 */
function show_migrate_amazon_cdn_update_info() {
	?>
	<h3>What can this plugin do?</h3>
	<p>This plugin is designed to work with the Amazon S3 and CloudFront plugin to migrate a site to using Amazon S3 and CloudFront to serve all files. </p>
	<p>This plugin will first standardize all of the urls on the site to follow the direct URL to the page minus the <code>/sitename/</code> portion so all URLs follow the pattern <code>http://www.example.com/wp-content/...</code>. </p>
	<p>It does not copy the files to the CDN. Please make sure the files are all copied over to the CDN before running the plugin.</p>
	<h3>Options</h3>
	<?php
	echo "<form method='POST'><input type='hidden' name='action' value='update' />";
	wp_nonce_field( 'migrate_amazon_cdn' );
	echo "<p><input type='submit' class='button button-primary button-large' value='" .__( ' Migrate to CDN ' ). "' /></p>";
	echo "</form>";
}

/**
 * deactivate MS files
 *
 * @since Migrate Amazon CDN 1.0
 */
function migrate_amazon_cdn_update( $direction = 'update', $cdn_url ) {
	// set these as globals - we need them later
	global $files_copied, $urls_changed, $wpdb;
	
	$urls_changed = 0;
	$migrate_sites_current = 0;
	$attach_parsed = 0;
		
	// copy files for each blog
	echo "<h4>Updating</h4>";
	echo "<p><em>Note that this step can take several minutes depending on the number of sites to be updated.</em></p>";

	// get all our blogs
	$blogs = $wpdb->get_results( 'SELECT * FROM `' . $wpdb->prefix . 'blogs`', ARRAY_A );
		
	if ( $direction == 'undo' ) {
		// finally upgade the network site option
		if ( false === get_site_option( 'migrate_amazon_cdn', false, false ) ) {
			// there is not site option to delete
		} else {
			delete_site_option( 'migrate_amazon_cdn' );
		}
	} else {
		// finally upgade the network site option
		if ( false === get_site_option( 'migrate_amazon_cdn', false, false ) ) {
			add_site_option( 'migrate_amazon_cdn', $cdn_url );
		} else {
			update_site_option( 'migrate_amazon_cdn', $cdn_url );
		}
	}

	// for each blog in our sstem look for things to upgrade		
	foreach ( $blogs as $blog ) {		
		// copy files from blog directory to uploads
		// only do this if the option is checked
		if ( $direction == 'undo' ) {
			// update URL links in posts and pages
			// get all our blogs
			if ( $blog['blog_id'] == 1 ) {
				$deleted = $wpdb->delete( $wpdb->prefix . 'postmeta', array( 'meta_key' => 'amazonS3_info' ), array( '%s' ) );
			} else {
				$deleted = $wpdb->delete( $wpdb->prefix . $blog['blog_id'] . '_postmeta', array( 'meta_key' => 'amazonS3_info' ), array( '%s' ) );
			}
			
			// increment the counter
			$attach_parsed += $deleted;
			
			// update URL links in posts and pages
			$content_source = $cdn_url . '/wp-content/';
			$content_dest = untrailingslashit( network_home_url() ) . '/wp-content/';
		} else {
			// get posts
			if ( $blog['blog_id'] == 1 ) {
				$attachments = $wpdb->get_results( 'SELECT * FROM `' . $wpdb->prefix . 'posts` WHERE `post_type` = "attachment" ORDER BY `ID` DESC', ARRAY_A );
			} else {
				$attachments = $wpdb->get_results( 'SELECT * FROM `' . $wpdb->prefix . $blog['blog_id'] . '_posts` WHERE `post_type` = "attachment" ORDER BY `ID` DESC', ARRAY_A );
			}
			
			if ( $attachments ) {
				foreach ( $attachments as $post ) {
					// network home 
					$urlpart = str_replace( network_home_url( '/' ), '', $post['guid'] );
					
					// if it exists in the guid trim off the bloginfo path
					$urlpart = str_replace( ltrim( $blog['path'], '/' ), '', $urlpart );
					
					// amazon array data
					$amazon_array = array( 'bucket' => 'bucket_name', 'key' => $urlpart );
		
					// add the postmeta
					if ( $blog['blog_id'] == 1 ) {
						$table = $wpdb->prefix . 'postmeta';
					} else {
						$table = $wpdb->prefix . $blog['blog_id'] . '_postmeta';
					}
					
					// insert into the database
					$wpdb->insert( 
					$table, 
					array(
						'post_id' => $post['ID'],
						'meta_key' => 'amazonS3_info',
						'meta_value' => maybe_serialize( $amazon_array )
						), 
					array( 
						'%d',
						'%s',
						'%s'
						)
					);
				}
				// count the attachments for this iste
				$attach_parsed += count( $attachments );
			}

			// the forward needs to be a two step process
			// we need to correct any URls that may have the sitename incorrectly in them
			$content_source = untrailingslashit( network_home_url() ) . $blog['path'] . 'wp-content/';
			$content_dest = untrailingslashit( network_home_url() ) . '/wp-content/';
						
			// and run the migration on the blogs databases
			migrate_amazon_cdn_update_url( $content_source, $content_dest, $blog['blog_id'] );
						
			// the forward needs to be a two step process
			// we need to correct any URls that may have the sitename incorrectly in them
			$content_source = untrailingslashit( network_home_url() ) . '/wp-content/';
			$content_dest = $cdn_url . '/wp-content/';

		}
				
		// run the converter				
		migrate_amazon_cdn_update_url( $content_source, $content_dest, $blog['blog_id'] );

		// update progress
		$migrate_sites_current++;
		show_migrate_amazon_cdn_progress($migrate_sites_current, count($blogs));
	}
		

	echo "<br/>";	
	echo "<br/>Parsed $attach_parsed attachments";
	echo "<br/>Changed $urls_changed URLs";
	echo "<br/>";
	echo "<br/>";
	echo "<p>All done.";
}

/**
 * Display a Span Progress bar
 *
 * @since Migrate Amazon CDN 1.0
 */
function show_migrate_amazon_cdn_progress( $current, $total ) {
    echo "<span style='position: absolute;z-index:$current;background:#F1F1F1;'>Parsing Blog " . $current . ' - ' . round($current / $total * 100) . "% Complete</span>";
    echo(str_repeat(' ', 256));
    if (@ob_get_contents()) {
        @ob_end_flush();
    }
    flush();
}

/**
 * Replace the post content, excerpt annd meta with the old and new URL
 *
 * @since Migrate Amazon CDN 1.0
 */
function migrate_amazon_cdn_update_url( $oldurl, $newurl, $blog_id ){	
	global $wpdb, $urls_changed;

	$oldurl = esc_attr($oldurl);
	$newurl = esc_attr($newurl);
		
	$queries = array(
		'content' => 		'UPDATE `' . $wpdb->prefix . $blog_id . '_posts` SET post_content = replace(post_content, %s, %s)',
		'excerpts' =>		'UPDATE `' . $wpdb->prefix . $blog_id . '_posts` SET post_excerpt = replace(post_excerpt, %s, %s)',
		'postmeta' =>		'UPDATE `' . $wpdb->prefix . $blog_id . '_postmeta` SET meta_value = replace(meta_value, %s, %s)',
		'options' =>		'UPDATE `' . $wpdb->prefix . $blog_id . '_postmeta` SET option_value = replace(option_value, %s, %s)'
	);
	
	foreach( $queries as $option => $query ){
		switch( $option ){
			case 'postmeta':
				$postmeta = $wpdb->get_results( 'SELECT * FROM `' . $wpdb->prefix . $blog_id . '_postmeta` WHERE meta_value != ""', ARRAY_A );
			
				foreach( $postmeta as $key => $item ) {
					// if the string is empty then dont bother and continue
					if( trim( $item['meta_value'] ) == '' ) {
						continue;
					}
					
					// we have a possibel suspect lets check if it is serialized
					if ( is_serialized( $item['meta_value'] ) ) { 
						$edited = migrate_amazon_cdn_unserialize_replace( $oldurl, $newurl, $item['meta_value'] );
					} else {
						// we are not serialized so we can replace directly
						$edited = str_ireplace( $oldurl, $newurl, $item['meta_value'], $count );
						$urls_changed += $count;
					}
			
					if( $edited != $item['meta_value'] ){
						$fix = $wpdb->query( $wpdb->prepare( 'UPDATE `' . $wpdb->prefix . $blog_id . '_postmeta` SET meta_value = "%s" WHERE meta_id = %s', $edited, $item['meta_id'] ) );
					}
				}
			break;
			case 'options':
				$postmeta = $wpdb->get_results( 'SELECT * FROM `' . $wpdb->prefix . $blog_id . '_options` WHERE option_value != ""', ARRAY_A );
			
				foreach( $postmeta as $key => $item ) {
					// if the string is empty then dont bother and continue
					if( trim( $item['option_value'] ) == '' ) {
						continue;
					}
					
					// we have a possibel suspect lets check if it is serialized
					if ( is_serialized( $item['option_value'] ) ) { 
						$edited = migrate_amazon_cdn_unserialize_replace( $oldurl, $newurl, $item['option_value'] );
					} else {
						// we are not serialized so we can replace directly
						$edited = str_ireplace( $oldurl, $newurl, $item['option_value'], $count );
						$urls_changed += $count;
					}
			
					if( $edited != $item['option_value'] ){
						$fix = $wpdb->query( $wpdb->prepare( 'UPDATE `' . $wpdb->prefix . $blog_id . '_options` SET option_value = "%s" WHERE option_id = %s', $edited, $item['option_id'] ) );
					}
				}
			break;
			default:
				$result = $wpdb->query( $wpdb->prepare( $query, $oldurl, $newurl) );
			
				if ( FALSE !== $result && 0 < $result ) {
					$urls_changed += $result;
				}
			break;
		}
	}
	//return $results;			
}

/**
 * Serialized data cannot have a direct swap. We need to handle the URLs ourside of serialization
 *
 * @since Migrate Amazon CDN 1.0
 */

function migrate_amazon_cdn_unserialize_replace( $from = '', $to = '', $data = '', $serialised = false ) {
	global $urls_changed;
	
	try {
		if ( is_string( $data ) && ( $unserialized = @unserialize( $data ) ) !== false ) {
			$data = migrate_amazon_cdn_unserialize_replace( $from, $to, $unserialized, true );
		}
		elseif ( is_array( $data ) ) {
			$_tmp = array( );
			foreach ( $data as $key => $value ) {
				$_tmp[ $key ] = migrate_amazon_cdn_unserialize_replace( $from, $to, $value, false );
			}
			$data = $_tmp;
			unset( $_tmp );
		}
		else {
			if ( is_string( $data ) ) {
				$data = str_replace( $from, $to, $data, $count );
				$urls_changed += $count;
			}
		}
		if ( $serialised )
			return serialize( $data );
	} catch( Exception $error ) {
	}
	return $data;
}