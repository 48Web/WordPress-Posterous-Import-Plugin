<?php
/*
Plugin Name: Posterous Importer 2
Plugin URI: http://48web.com/posterous-importer
Description: Import posts, comments, tags, and attachments from a Posterous.com blog. Forked from plugin originally built by Automattic http://wordpress.org/extend/plugins/posterous-importer/. Link fix from https://gist.github.com/bradgessler/3185320 via Brad Gessler.
Author: Andy Brudtkuhl
Author URI: http://48web.com
Version: 0.20
License: GPL v2 - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/
 
if ( !defined('WP_LOAD_IMPORTERS') )
  return;
 
// Load Importer API
require_once ABSPATH . 'wp-admin/includes/import.php';
 
if ( !class_exists( 'WP_Importer' ) ) {
  $class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
  if ( file_exists( $class_wp_importer ) )
    require_once $class_wp_importer;
}
/**
 * Posterous Importer
 *
 * @package WordPress 
 * @subpackage Importer 
 */
if ( class_exists( 'WP_Importer' ) ) {
class Posterous_Import extends WP_Importer {
  var $blog_id = 0;
  var $user_id = 0;
  var $hostname;
  var $sites;
  var $sites_id;
  var $auth = false;
  var $username = '';
  var $password = '';
  var $bid = '';
  var $permalinks = array();
  var $comments = array();
  var $attachments = array();
  var $url_remap = array();
  var $have_posts = true;
 
 
  /**
   * Constructor
   *
 * @return void 
   */
  function __construct() {
    parent::__construct();
    add_action( 'process_attachment', array( &$this, 'process_attachment' ), 10, 3 );
    add_action( 'posterous_handle_bad_response', array( &$this, 'handle_bad_response' ), 10, 2 );
 
    if ( isset( $_GET['import'] ) && 'posterous' == $_GET['import'] ) {
      add_action( 'admin_enqueue_scripts', array( &$this, 'admin_enqueue_scripts' ) );
      add_action( 'admin_head', array ( &$this, 'admin_head' ) );
    }
  }
 
  /**
   * PHP 4 Constructor
   *
 * @return void 
   */
  function Posterous_Import(){
    $this->__construct();
  }
  
  /**
   * Strip out protocol, check for proper domain.
   *
 * @param string $hostname 
 * @return string 
   */
  function sanitize_hostname( $hostname ) {
    $hostname = str_replace( array( 'http://', 'https://' ), '', trim( stripslashes( strtolower( $hostname ) ) ) );
    if ( !strstr( $hostname, '.posterous.com' ) )
      $hostname = $hostname . '.posterous.com';
    return $hostname;
  }
 
  /**
   * Import calls each step of the process
   *
 * @return void 
   */
  function import() {
    if ( !defined( 'WP_IMPORTING' ) )
      define( 'WP_IMPORTING', true );
    do_action( 'import_start' );
    // Set time limit after import_start to avoid the 900 second limit
    set_time_limit( 0 );
    $this->sites = $this->get_sites();
    // Sleep after fetching sites because Posterous doesn't like if you do more than one request in a second.
    usleep( 3100000 );
    $this->permalinks = $this->get_imported_posts( 'posterous', $this->bid );
    $this->comments = $this->get_imported_comments();
    $this->attachments = $this->get_imported_attachments( 'posterous', $this->bid );
    $this->do_posts();
    $this->process_attachments();
    $this->cleanup();
  }
 
  function get_sites() {
    $url = 'http://posterous.com/api/getsites';
    $data = $this->get_page( $url, $this->username, $this->password );
    if ( is_wp_error( $data ) ) {
      echo "Error:\n" . $data->get_error_message() . "\n";
      return;
    }
 
    $code = (int) $data['response']['code'];
    if ( 200 !== $code ) {
      printf( "<em>%s</em><br />\n", __( 'Got HTTP code' ) . ' ' . $code . ' ' . __( 'from' ) . ' ' . $url );
      exit();
    }
 
    $xml = simplexml_load_string( $data['body'] );
    if ( !$xml ) {
      printf( __METHOD__ . ": <em>%s</em><br />\n", __( 'Error loading XML' ) );
      exit();
    }
 
    $subdomain = substr( $this->hostname, 0, strpos( $this->hostname, '.posterous.com' ) );
    foreach ( $xml->site as $site ) {
      // Compare the subdomain to the hostname from Posterous.
      if ( $subdomain == (string) $site->hostname ) {
        $this->site_id = (int) $site->id;
        break;
      }
    }
  }
 
  /**
   * Loop over each feed and process posts
   *
 * @return void 
   */
  function do_posts() {
    $page = 1;
    do {
      $url = 'http://posterous.com/api/readposts';
      $url = add_query_arg( 'site_id', $this->site_id, $url );
      $url = add_query_arg( 'num_posts', 10, $url );
      $url = add_query_arg( 'page', $page, $url );
      $this->process_posts( $url );
      $page++;
      // Sleep after fetching sites because Posterous doesn't like if you do more than one request in a second.
      usleep( 1100000 );
    } while ( true === $this->have_posts );
  }
 
  /**
   * Print an error if we didn't get a 200 response from the Posterous API.
 * @param array $data The data our API request fetched, in case we want to do more with it. 
 * @return void 
   */
  function handle_bad_response( $data, $code ) {
    printf( "<em>%s</em><br />\n", __( 'Got HTTP code' ) . ' ' . $code . ' ' . __( 'from' ) . ' ' . $url );
    exit();
  }
  
  /**
   * Extract XML from URL and import as posts
   *
 * @param string $url 
 * @return void 
   */
  function process_posts( $url ) {
    $data = $this->get_page( $url, $this->username, $this->password );
    if ( is_wp_error( $data ) ) {
      echo "Error:\n" . $data->get_error_message() . "\n";
      return;
    }
 
    $code = (int) $data['response']['code'];
    if ( 200 !== $code ) {
      do_action( 'posterous_handle_bad_response', $data, $code );
    }
 
    $body = $data['body'];
    $xml = @simplexml_load_string( $body );
    if ( !$xml ) {
      printf( __METHOD__ . ": <em>%s</em><br />\n", __( 'Error loading XML' ) );
      $this->have_posts = false; 
      return;
    }
 
    // If posts is empty, we're done here, move along
    if ( empty( $xml->post ) ) {
      $this->have_posts = false;
      return;
    }
 
    foreach ( $xml->post as $entry ) {
      $entry->title = (string) $entry->title;
      $permalink = (string) $entry->link;
      $commentscount = (int) $entry->commentsCount;     
      $parsed_url = parse_url($permalink);
      $slug = trim($parsed_url["path"], "/"); // Remove leading / from the path.
 
      if ( isset( $this->permalinks[$permalink] ) ) {
        printf( "<em>%s</em><br />\n", __( 'Skipping' ) . ' ' . $entry->title );
        // process_comments here to check for any new comments or ones that we missed
        printf( "\t<em>%s</em><br />\n", __( 'Found Comments:' ) . ' ' . $commentscount );
        if ( $commentscount > 0 )
          $this->process_comments( $this->permalinks[$permalink], $entry->comment );
        continue;
      }         
 
      $post = array();
      $post['post_title'] = (string) $entry->title;
      $post['post_date'] = gmdate( "Y-m-d H:i:s", strtotime( (string) $entry->date ) );
      $post['post_content'] = (string) $entry->body;
      $post['post_name'] = (string) $slug;
 
      printf( "\t<em>%s</em><br />\n", __( 'Slug:' ) . ' ' . $post['post_name'] );
 
      $post['post_status'] = 'publish';
      if ( 'true' == (string) $entry->private )
        $post['post_status'] = 'private';
 
      $post_id = wp_insert_post( $post );
 
      if ( is_wp_error( $post_id ) ) {
        printf( __('Error: %s') . "\n", htmlspecialchars( $post_id->get_error_message() ) );
        continue;
      }
 
      add_post_meta( $post_id, 'posterous_' . $this->bid . '_post_id', (int) $entry->id, true );
      add_post_meta( $post_id, 'posterous_' . $this->bid . '_permalink', $permalink, true );
 
      // Check to see if we have audio or video files and add relevant data to postmeta for later use if so.
      if ( isset( $entry->media ) ) {
        $media = array();
          
        foreach ( $entry->media as $m ) {
          if ( in_array( (string) $m->type, array( 'audio', 'video' ) ) ) {           
            $file = new stdClass();
            $file->type = (string) $m->type;
            $file->url = (string) $m->url;
            $file->filesize = (string) $m->filesize;
            $file->thumb = (string) $m->thumb;
            $media[] = $file;
          }
        }
        if ( 0 < count( $media ) )
          add_post_meta( $post_id, 'posterous_' . $this->bid . '_media', $media, true );
      }
 
      printf( "<em>%s</em><br />\n", __( 'Importing' ) . ' ' . $entry->title );
      $this->permalinks[$permalink] = $post_id;
 
      $tags = $this->get_tags( $entry );
      if ( !empty( $tags ) )
        printf( "\t<em>%s</em><br />\n", __( 'Found tags:' ) . ' ' . implode( ', ', $tags ) );
      $this->add_post_tags( $post_id, $tags );
 
      printf( "\t<em>%s</em><br />\n", __( 'Found Comments:' ) . ' ' . $commentscount );
      if ( $commentscount > 0 )
        $this->process_comments( $post_id, $entry->comment );
    }
  }
 
  /**
   * Extract XML from URL, find and import comments
   *
 * @param string $url 
 * @return array 
   */
  function process_comments( $post_id, $comments ) {
    foreach ( $comments as $comment ) {
      $author = (string) $comment->author;
      $date = gmdate( "Y-m-d H:i:s", strtotime( (string) $comment->date ) );
      $import_comment_hash = md5( $author . $date );
      // Skip existing comments
      if ( isset( $this->comments[$import_comment_hash] ) ) 
        continue;
 
      $body = (string) $comment->body;
 
      $comment_post_ID = $post_id;
      $comment_author = $author;
      $comment_date = $date;
      $comment_content = addslashes( trim( strip_tags( $body ) ) );
      $comment = compact( 'comment_post_ID', 'comment_author', 'comment_author_url', 'comment_date', 'comment_content' );
      $comment = wp_filter_comment( $comment );
      $comment_id = (int) wp_insert_comment( $comment );
 
      $meta_key = 'posterous_' . $this->bid . '_comment_id';
      add_comment_meta( $comment_id, $meta_key, $import_comment_hash, true );
    }
 
    return $comments;
  }
 
  /**
   * Add tags to post
   *
 * @param int $post_id 
 * @param array $tags 
 * @return void 
   */
  function add_post_tags( $post_id, $tags ) {
    if ( empty( $tags ) )
      return;
    global $wpdb;
    $post_tags = array();
    foreach ( $tags as $tag ) {
      $slug = sanitize_term_field( 'slug', $tag, 0, 'post_tag', 'db' );
      $tag_obj = get_term_by( 'slug', $slug, 'post_tag' );
      $tag_id = 0;
      if ( !empty( $tag_obj ) )
        $tag_id = $tag_obj->term_id;
      if ( $tag_id == 0 ) {
        $tag = $wpdb->escape( $tag );
        $tag_id = wp_insert_term( $tag, 'post_tag' );
        if ( is_wp_error( $tag_id ) )
          continue;
        $tag_id = $tag_id['term_id'];
      }
      $post_tags[] = (int) $tag_id;
    }
    if ( empty( $post_tags ) )
      return;
    wp_set_post_tags( $post_id, $post_tags );
  }
 
  /**
   * Get tags from post
   *
 * @param object $entry 
 * @return array 
   */
  function get_tags( $entry ) {
    $tags = array();
    if ( isset( $entry->tag ) ) {
      foreach ( $entry->tag as $tag ) {
        $tags[] = (string) $tag;
      }
    }
 
    return array_unique( $tags );
  }
 
  /**
   * Scan all posts for attachments
   *
 * @return void 
   */
  function process_attachments() {
    if ( empty( $this->permalinks ) )
      return;
 
    // Loop over each post ID
    foreach ( $this->permalinks as $permalink => $post_id ) {
      // Get post data
      $post = get_post( $post_id );
      $media = get_post_meta( $post_id, 'posterous_' . $this->bid . '_media' );
 
      printf( "<em>%s</em>", __( 'Checking' ) . " '$post->post_title' " . __( 'for images...' ) );
      $attachments = $this->extract_post_media( $post->post_content, $media );
      printf( "<em>%s</em><br />\n", ' ' . sizeof( $attachments['fullsize'] ) + sizeof( $attachments['single'] ) . ' ' . __( 'images found' ) );
 
      // Process attachments
      if ( !empty( $attachments['fullsize'] ) ) {
        do_action( 'process_attachment', $post, $attachments['fullsize'], $attachments['thumb'] );
      }
 
      if ( !empty( $attachments['single'] ) ) {
        do_action( 'process_attachment', $post, $attachments['single'], $attachments['single'] );
      }
 
      unset( $post, $attachments );
 
      $this->stop_the_insanity();
    }
  }
 
  /**
   * Import and processes each attachment
   *
 * @param object $post 
 * @param array $fullsizes 
 * @param array $thumbs 
 * @return void 
   */
  function process_attachment( $post, $fullsizes, $thumbs ) {     
 
    // Add media files from postmeta to the $fullsizes array for fetching.
    $media_types = array();
    $media = get_post_meta( $post->ID, 'posterous_' . $this->bid . '_media', true );
    if ( is_array( $media ) ) {
      foreach ( $media as $m ) {
        $fullsizes[] = $m->url;
        $media_types[$m->url] = $m->type;
      }
    }
    
    if ( empty( $fullsizes ) )
      return;
 
    foreach ( $fullsizes as $id => $fullsize ) {
      if( $this->is_user_over_quota() )
        return false;
 
      $thumb = $thumbs[$id];
 
      // Skip duplicates
      if ( isset( $this->attachments[$fullsize] ) ) {
        $post_id = $this->attachments[$fullsize];
        printf( "<em>%s</em><br />\n", __( 'Skipping duplicate' ) . ' ' . $fullsize );
        // Get new attachment URL
        $attachment_url = wp_get_attachment_url( $post_id );
 
        // Update url_remap array
        $this->url_remap[$fullsize] = $attachment_url;
        $sized = image_downsize( $post_id, 'medium' );
        if ( isset( $sized[0] ) ) {
          $this->url_remap[$thumb] = $sized[0];
        }
 
        continue;
      }
 
      echo '<em>Importing attachment ' . htmlspecialchars( $fullsize ) . "...</em>";
      $upload = $this->fetch_remote_file( $post, $fullsize );
      
      if ( is_wp_error( $upload ) ) {
        printf( "<em>%s</em><br />\n", __( 'Remote file error:' ) . ' ' . htmlspecialchars( $upload->get_error_message() ) );
        continue;
      } else {
        printf( "<em> (%s)</em><br />\n", size_format( filesize( $upload['file'] ) ) );
      }
 
      if ( 0 == filesize( $upload['file'] ) ) {
        print __( "Zero length file, deleting..." ) . "<br />\n";
        @unlink( $upload['file'] );
        continue;
      }
 
      $info = wp_check_filetype( $upload['file'] );
      if ( false === $info['ext'] ) {
        printf( "<em>%s</em><br />\n", $upload['file'] . __( 'has an invalid file type') );
        @unlink( $upload['file'] );
        continue;
      }
 
      // as per wp-admin/includes/upload.php
      $attachment = array ( 
        'post_title' => $post->post_title, 
        'post_content' => '', 
        'post_status' => 'inherit', 
        'guid' => $upload['url'], 
        'post_mime_type' => $info['type'] 
        );
  
      $post_id = (int) wp_insert_attachment( $attachment, $upload['file'], $post->ID );
      $attachment_meta = @wp_generate_attachment_metadata( $post_id, $upload['file'] );
      wp_update_attachment_metadata( $post_id, $attachment_meta );            
 
      // Fire an action to do anything we might like to do to the post after adding an attachment (e.g. inserting shortcodes).
      // This is not implemented within the plugin; it's just here so that it can be extended.
      do_action( 'posterous_process_attachment_post_update', $post, $post_id, $fullsize, $media_types );
 
      // Add remote_url to post_meta
      add_post_meta( $post_id, 'posterous_' . $this->bid . '_attachment', $fullsize, true );
      // Add remote_url to hash table
      $this->attachments[$fullsize] = $post_id;
      
      // Get new attachment URL
      $attachment_url = wp_get_attachment_url( $post_id );
      // Update url_remap array
      $this->url_remap[$fullsize] = $attachment_url;
      $sized = image_downsize( $post_id, 'medium' );
      if ( isset( $sized[0] ) ) {
        $this->url_remap[$thumb] = $sized[0];
      }     
    }
    
    $this->backfill_attachment_urls( $post );
  }
 
  /**
   * Update url references in post bodies to point to the new local files
   *
 * @return void 
   */
  function backfill_attachment_urls( $post = false ) {
    if ( false === $post )
      return;
 
    // make sure we do the longest urls first, in case one is a substring of another
    uksort( $this->url_remap, array( &$this, 'cmpr_strlen') );
 
    $from_urls = array_keys( $this->url_remap );
    $to_urls = array_values( $this->url_remap );
 
    $hash_1 = md5( $post->post_content );
    $post->post_content = str_replace( $from_urls, $to_urls, $post->post_content );
    $hash_2 = md5( $post->post_content );
 
    if ( $hash_1 !== $hash_2 )
      wp_update_post( $post );
  }
 
  /**
   * Download remote file, keep track of URL map
   *
 * @param object $post 
 * @param string $url 
 * @return array 
   */
  function fetch_remote_file( $post, $url ) {
    // Increase the timeout
    add_filter( 'http_request_timeout', array( &$this, 'bump_request_timeout' ) );
 
    $parts = parse_url( $url );
    $filename = basename( $parts['path'] );
    
    // get placeholder file in the upload dir with a unique sanitized filename
    $upload = wp_upload_bits( $filename, 0, '', $post->post_date );
    if ( is_wp_error( $upload ) ) {
      return $upload;
    }
 
    if ( $upload['error'] ) {
      echo $upload['error'];
      return false;
    }
 
    // fetch the remote url and write it to the placeholder file
    $headers = wp_get_http( $url, $upload['file'] );
 
    // make sure the fetch was successful
    if ( $headers['response'] != '200' ) {
      @unlink( $upload['file'] );
      return new WP_Error( 'import_file_error', sprintf( __( 'Remote file returned error response %d' ), intval( $headers['response'] ) ) );
    }
 
    // keep track of the old and new urls so we can substitute them later
    $this->url_remap[$url] = $upload['url'];
    // if the remote url is redirected somewhere else, keep track of the destination too
    if ( isset( $headers['x-final-location'] ) && $headers['x-final-location'] != $url )
      $this->url_remap[$headers['x-final-location']] = $upload['url'];
 
    return apply_filters( 'wp_handle_upload', $upload );
  }
 
  /**
   * Return array of images from the post
   *
 * @param string $post_content 
 * @return array 
   */
  function extract_post_media( $post_content, $media ) {
    $post_content = stripslashes( $post_content );
    $post_content = str_replace( "\n", '', $post_content );
    $post_content = $this->min_whitespace( $post_content );
    $attachments = array();
    $attachments['thumb'] = array();
    $attachments['fullsize'] = array();
    $attachments['single'] = array();
    $attachments['audiovideo'] = array();
 
    // Find all linked images
    $matches = array();
    preg_match_all( '|<a.*?href=[\'"](.*?)[\'"].*?><img.*?src=[\'"](.*?)[\'"].*?>|i', $post_content, $matches );
    foreach ( $matches[1] as $i => $url ) {
      if ( strstr( $url, 'posterous.com' ) ) {
        $attachments['thumb'][$i] = $matches[2][$i];
        $attachments['fullsize'][$i] = $url;
      }
    }
 
    // Find all not linked images
    $matches = array();
    preg_match_all( '|<img.*?src=[\'"](.*?)[\'"].*?>|i', $post_content, $matches );
    foreach ( $matches[1] as $i => $url ) {
      if ( strstr( $url, 'posterous.com' ) && !in_array( $url, $attachments['thumb'] ) && !in_array( $url, $attachments['fullsize'] ) ) {
        $attachments['single'][$i] = $url;
      }
    }
    $attachments['single'] = array_unique( $attachments['single'] );
 
    // Find all linked mp3s and videos
    $matches = array();
    preg_match_all( '!href=(\'|")(http:\/\/[a-zA-Z0-9\-+%\&\?#\/\.]+\/files\.posterous\.com\/[a-zA-Z0-9\-+%_&\?#\/\.]+\.(mp3|m4v|mp4|mov|avi|wmv|3gv|3g2))(\'|")/!i', $post_content, $matches );
    if ( !empty( $matches[2] ) ) {
      foreach ( $matches[2] as $i => $url ) {
        $attachments['audiovideo'][$i] = $url;
      }
    }
    
    unset( $post_content, $matches );
 
    return $attachments;
  }
 
  /**
   * Set array with imported attachments from WordPress database
   *
 * @param string $importer_name 
 * @param string $bid 
 * @return array 
   */
  function get_imported_attachments( $importer_name, $bid ) {
    global $wpdb;
 
    $hashtable = array ();
 
    // Get all attachments
    $sql = $wpdb->prepare( "SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = '%s'", $importer_name . '_' . $bid . '_attachment' );
    $results = $wpdb->get_results( $sql );
 
    if (! empty( $results )) {
      foreach ( $results as $r ) {
        // Set permalinks into array
        $hashtable[$r->meta_value] = (int) $r->post_id;
      }
    }
 
    // unset to save memory
    unset( $results, $r );
 
    return $hashtable;
  }
 
  /**
   * Return hash table of imported comment_id's
   *
 * @return array 
   */
  function get_imported_comments() {
    global $wpdb;
 
    $hashtable = array ();
    $limit = 100;
    $offset = 0;
    $meta_key = 'posterous_' . $this->bid . '_comment_id';
 
    // Grab all comments in chunks
    do {
      $sql = $wpdb->prepare ( "SELECT comment_id, meta_value FROM $wpdb->commentmeta WHERE meta_key LIKE '%s' LIMIT %d,%d", $meta_key, $offset, $limit );
      $results = $wpdb->get_results ( $sql );
 
      // Increment offset
      $offset = ($limit + $offset);
 
      if (! empty ( $results )) {
        foreach ( $results as $r ) {
          $hashtable [$r->meta_value] = (int) $r->comment_id;
        }
      }
    } while ( count ( $results ) == $limit );
 
    // unset to save memory
    unset ( $results, $r );
    return $hashtable;
  }
 
  /**
   * Extract page number from URL
   *
 * @param string $url 
 * @return int 
   */
  function get_page_number( $url ) {
    $path = parse_url( $url, PHP_URL_PATH );
    $parts = explode( '/', $path );
    $pos = ( sizeof( $parts ) - 2 );
    $num = $parts[$pos];
    if ( ! is_numeric( $num ) ) {
      return false;
    }
 
    return (int) $num;
  }
 
  /**
   * Check links object for rel= , return url or false
   *
 * @param object $links 
 * @param string $rel 
 * @return mixed 
   */
  function get_link_by_rel( $links, $rel ) {
    foreach ( $links as $link ) {
      $attr = $link->attributes();
      $_rel = (string) $attr['rel'];
      $href = (string) $attr['href'];
 
      if ( $rel == $_rel ) {
        return $href;
      }
    }
 
    return false;
  }
 
  /**
   * Perform cleanup operations
   *
 * @return void 
   */
  function cleanup() {
    delete_option( 'posterous_import' );
    do_action( 'import_done', 'posterous' );
    printf ( "<strong>%s</strong><br />\n", __( 'All Done!' ) );
  }
 
  // Enqueue the core js our js depends on
  function admin_enqueue_scripts() {
    wp_enqueue_script( 'jquery' );
  }
 
  function admin_head() {
    ?>
<style type="text/css">
 #posterous_info label { 
  display:inline;
  float:left;
  font-weight:bold;
  width:85px;
}
 #auth_message { 
  margin:10px;
  color:red;
}
 #spinner { 
  display:none;
}
</style>
 
<script type="text/javascript">
var $ = jQuery.noConflict();
$( function() {
  var code = 0;
 $('#import_submit').click( function() { 
    email_regex = /[\w_\.\+]+@[\w\.][\w\.]{2,}/;
 if ( !email_regex.test( $( '#username' ).val() ) ) { 
      alert( '<?php echo esc_js( __( 'Please specify your Posterous account email address as the username.' ) ); ?>' );
      return false;
    }
 $('#import_submit').attr('value', 'Please Wait...'); 
 $('#import_submit').attr('disabled', 'disabled'); 
 $('#spinner').show(); 
 var dataString = $('#posterous_info').serialize(); 
    $.ajax({
      type: 'POST',
      url: 'admin.php?import=posterous&noheader=true&test_user_pass=true',
      data: dataString,
      success: function( data, status ) {
        code = data;
        //alert(code);          
      if ( '401' == code ) {
 $('#spinner').hide(); 
 $('#auth_message').html("Please check your user name and password, Posterous says it's incorrect."); 
 $('#import_submit').attr('value', 'Submit'); 
 $('#import_submit').removeAttr('disabled'); 
 
        return false;
      }
    
      if ( '200' == code ) {
        $.ajax({
          type: 'POST',
          url: 'admin.php?import=posterous&noheader=true&step=2',
          data: dataString,
          success: function( data, status ) {
            if ( 'ready' == data ) {
              window.location = 'admin.php?import=posterous&step=3';
            }
          }
          });
 
          return true;
      }
 
      }
      });
  });
});
</script>
 
<script type="text/javascript">
/*
 * jQuery doTimeout: Like setTimeout, but better! - v0.4 - 7/15/2009
 * http://benalman.com/projects/jquery-dotimeout-plugin/
 * 
 * Copyright (c) 2009 "Cowboy" Ben Alman
 * Dual licensed under the MIT and GPL licenses.
 * http://benalman.com/about/license/
 */
(function($){var a={},c="doTimeout",d=Array.prototype.slice;$[c]=function(){return b.apply(window,[0].concat(d.call(arguments)))};$.fn[c]=function(){var e=d.call(arguments),f=b.apply(this,[c+e[0]].concat(e));return typeof e[0]==="number"||typeof e[1]==="number"?this:f};function b(l){var m=this,h,k={},n=arguments,i=4,g=n[1],j=n[2],o=n[3];if(typeof g!=="string"){i--;g=l=0;j=n[1];o=n[2]}if(l){h=m.eq(0);h.data(l,k=h.data(l)||{})}else{if(g){k=a[g]||(a[g]={})}}k.id&&clearTimeout(k.id);delete k.id;function f(){if(l){h.removeData(l)}else{if(g){delete a[g]}}}function e(){k.id=setTimeout(function(){k.fn()},j)}if(o){k.fn=function(p){o.apply(m,d.call(n,i))&&!p?e():f()};e()}else{if(k.fn){j===undefined?f():k.fn(j===false);return true}else{f()}}}})(jQuery);
</script>
 
<script type="text/javascript">
var $ = jQuery.noConflict();
$( function() {
 $('#start_poll').hide(); 
 $('#stop_poll').hide(); 
 var elem = $('#polling_loop'); 
 $('#start_poll').click( function() { 
 //$('#start_poll').hide(); 
 //$('#stop_poll').show(); 
    // Start a polling loop with an id of 'loop' and a counter.
    var i = 0;
 
    elem.doTimeout( 'loop', 3000, function() {
 $('#loop_count').html( ++i ); 
 var hostname = $('#hostname').val(); 
    if( $.trim( hostname ) == '') {
      return false;
    }
 
 var ajaxurl = 'admin.php?import=posterous&noheader=true&status=true&hostname=' + $('#hostname').val(); 
    $.getJSON( ajaxurl, function( data ) { 
      var jo = eval( data );
 $('#posts_count').html( jo.posts ); 
 $('#comments_count').html( jo.comments ); 
 $('#attachments_count').html( jo.attachments ); 
    });
 
    return true;
    });
  });
  
 $('#stop_poll').click( function() { 
    // Cancel the polling loop with id of 'loop'.
    elem.doTimeout( 'loop' );
 $('#start_poll').show(); 
 $('#stop_poll').hide(); 
  });
 
 $('#start_poll').click(); 
});
</script>
 
<?php
  }
 
  function print_header() {
    echo "<div class='wrap'>\n";
    screen_icon();
    echo "<h2>" . __( 'Import Posterous' ) . "</h2>\n";
  }
 
  function print_footer() {
    echo "</div>\n";
  }
 
  function test_user_pass( $hostname, $username, $password ) {
    $hostname = $this->sanitize_hostname( $hostname );
    $username = strtolower( $username );
 
    $this->username = $username;
    $this->password = $password;
    $this->auth = true;
    $url = 'http://posterous.com/api/getsites';
    $data = $this->get_page( $url, $this->username, $this->password );
    if ( is_wp_error( $data ) ) {
      echo "Error:\n" . $data->get_error_message() . "\n";
      return;
    }
 
    $code = (int) $data['response']['code'];
    unset( $data );
    echo $code;
  }
 
  function step_1() {
    $action = add_query_arg( 'step', 2, $_SERVER['REQUEST_URI'] );
    $hostname = $this->sanitize_hostname( get_option( 'posterous_hostname' ) );
    $hostname = str_replace( '.posterous.com', '', $hostname );
    $username = get_option( 'posterous_username' );
?>
 
<p><?php _e( 'Howdy! So, you want to import your Posterous blog? No problem, we just need a little information.' ); ?></p>
<p><?php _e( 'Please enter your Posterous user name and password.' ); ?></p>
<p><?php _e( 'WordPress will not permanently store your Posterous password. After the import is finished, your password will be deleted immediately.' ); ?></p>
 
<form id="posterous_info" name="posterous_info" action="<?php echo esc_url( $action ); ?>" method="POST">
  <label><?php _e( 'Host name' ); ?></label> <input id="hostname" name="hostname" type="text" value="<?php echo esc_attr( $hostname ); ?>" /> .posterous.com<br />
  <label><?php _e( 'Email Address' ); ?></label> <input id="username" name="username" type="text" value="<?php echo esc_attr( $username ); ?>" /><br />
  <label><?php _e( 'Password' ); ?></label> <input id="password" name="password" type="password" value="" /><br />
  <input class="button" id="import_submit" name="import_submit" type="button" value="Submit" /> <img id="spinner" src="images/loading.gif" />
  <div id="auth_message"></div>
</form>
<hr />
<?php
 
  }
 
  function step_2() {
    $hostname = $this->sanitize_hostname( $_POST['hostname'] );
    $hostname = str_replace( '.posterous.com', '', $hostname );
    update_option( 'posterous_hostname', $hostname );
    $username = trim( stripslashes( strtolower( $_POST['username'] ) ) );
    update_option( 'posterous_username', $username );
    $password = trim( stripslashes( $_POST['password'] ) );
    update_option( 'posterous_password', $password );
 
    $data = new stdClass();
    $data->hostname = $hostname;
    $data->username = $username;
    $data->password = $password;
    
    add_option( 'posterous_import', $data );
    echo 'ready';
  }
 
  function step_3() {
    global $blog_id, $current_user, $current_blog;
 
    $data = get_option( 'posterous_import' );
    if ( !is_object( $data ) )
      die();
  
    $this->hostname = $this->sanitize_hostname( $data->hostname );
    $this->bid = md5( $this->hostname );
    $this->username = $data->username;
    $this->password = $data->password;
    $this->auth = false;
    if ( !empty( $this->username ) && !empty( $this->password ) )
      $this->auth = true;
  
    $this->blog_id = $this->set_blog( $blog_id );
    $this->user_id = $this->set_user( $current_user->ID );
 
    $this->import();
  }
 
  function importer_status() {
    $hostname = $this->sanitize_hostname( get_option( 'posterous_hostname' ) );
    $status = $this->get_importer_status( $hostname, 'array' );
?>
  <div id="polling_loop">
    <div id="importer_status">
      <h2><?php _e( 'Importer Status' ); ?></h2>
      <input type="hidden" id="hostname" name="hostname" value="<?php echo $hostname; ?>" />
      <strong><?php _e( 'Posts:' ); ?></strong> <span id="posts_count"><?php echo $status['posts']; ?></span><br />
      <strong><?php _e( 'Comments:' ); ?></strong> <span id="comments_count"><?php echo $status['comments']; ?></span><br />
      <strong><?php _e( 'Attachments:' ); ?></strong> <span id="attachments_count"><?php echo $status['attachments']; ?></span><br />
      <p><input type="button" class="button" id="start_poll" name="start_poll" value="<?php _e( 'Check Status' ); ?>" /><input type="button" class="button" id="stop_poll" name="stop_poll" value="<?php _e( 'Stop Checking' ); ?>" /></p>
    </div>
  </div>
<hr />
<?php
  }
 
  function get_importer_status( $hostname, $return = 'json' ) {
    $this->hostname = $this->sanitize_hostname( $hostname );
    $this->bid = md5( $this->hostname );
    $this->permalinks = $this->get_imported_posts( 'posterous', $this->bid );
    $this->comments = $this->get_imported_comments();
    $this->attachments = $this->get_imported_attachments( 'posterous', $this->bid );
 
    $status = array();
    $status['posts'] = count( $this->permalinks );
    $status['comments'] = count( $this->comments );
    $status['attachments'] = count( $this->attachments );
 
    if ( 'json' == $return )
      return json_encode( $status );
    if ( 'array' == $return )
      return $status;
  }
 
  function dispatch() {
    // A hook to do anything we might like to do before proceeding. Not implemented but potentially useful for extending the plugin.
    do_action( 'posterous_pre_dispatch' );
    
    // Set step
    $step = isset( $_GET['step'] ) ? (int) $_GET['step'] : 1;
 
    if ( isset( $_GET['hostname'] ) && isset( $_GET['status'] ) )
      die( $this->get_importer_status( $_GET['hostname'] ) );
    
    if ( isset( $_GET['test_user_pass'] ) )
      die( $this->test_user_pass( $_POST['hostname'], $_POST['username'], $_POST['password'] ) );
 
    if ( 2 === $step )
      die( $this->step_2() );
 
    $this->print_header();
 
    if ( 1 === $step )
      $this->step_1();
    if ( 3 === $step )
      $this->step_3();
 
    $this->importer_status();
 
    // A hook to do anything we might like to do before printing the status. Not implemented but potentially useful for extending the plugin.
    do_action( 'posterous_after_status' );
 
    $this->print_footer();
  }
}
}
 
$posterous = new Posterous_Import();
register_importer( 'posterous', __( 'Posterous' ), __( 'Import posts, comments, tags, and attachments from a Posterous.com blog.' ), array( $posterous, 'dispatch' ) );