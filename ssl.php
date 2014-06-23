<?php
/*
Plugin Name:  SSL Helper
Description:  SSL Helper
Plugin URI:   https://lud.icro.us/wordpress-plugin-ssl-helper/
Version:      1.2.1
Author:       John Blackbourn
Author URI:   https://johnblackbourn.com/
Network:      true

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

*/

class Amsterdam_SSL {

	# Notable SSL tickets:
	# http://core.trac.wordpress.org/ticket/15928
	# http://core.trac.wordpress.org/ticket/18017
	# http://core.trac.wordpress.org/ticket/20253
	# http://core.trac.wordpress.org/ticket/20750

	public function __construct() {

		if ( !is_admin() ) {

			#@todo correct multisite login screen logo url when admin is ssl but site isn't
			#@TODO allow ssl url to be on different domain/port like the WordPress HTTPS plugin does

			# Front-end Filters:
			add_filter( 'admin_url',                 array( $this, 'enforce_admin_scheme' ), 10, 3 );
			add_filter( 'home_url',                  array( $this, 'enforce_home_scheme' ), 10, 3 );
			add_filter( 'the_content',               array( $this, 'filter_embeds_in_text' ), 99 );
			add_filter( 'comment_text',              array( $this, 'filter_embeds_in_text' ), 99 );
			add_filter( 'wp_get_attachment_url',     'set_url_scheme', 1 );

			# Front-end Actions:
			add_action( 'template_redirect',         array( $this, 'ssl_request' ) );

		} else {

			# Admin Filters:
			add_filter( 'get_sample_permalink_html', array( $this, 'sample_permalink' ), 10, 2 );

			# Admin Actions:
			add_action( 'save_post',                 array( $this, 'save_meta' ), 10, 2 );
			add_action( 'admin_head-post.php',       array( $this, 'action_admin_head_post' ) );
			add_action( 'admin_head-post-new.php',   array( $this, 'action_admin_head_post' ) );

		}

		# General Filters:
		add_filter( 'post_link',                 array( $this, 'ssl_link' ), 10, 2 );
		add_filter( 'page_link',                 array( $this, 'ssl_link' ), 10, 2 );
		add_filter( 'post_type_link',            array( $this, 'ssl_link' ), 10, 2 );

	}

	/**
	 * Sets the scheme of admin links with empty schemes to 'http', so links on SSL pages link to
	 * 'http' rather than 'https'.
	 * 
	 * What this does: If you're on an SSL page but your admin URL is not SSL, it stops admin links
	 * (eg in the toolbar) from showing as SSL links.
	 */
	public function enforce_admin_scheme( $url, $path, $orig_scheme ) {
		if ( empty( $orig_scheme ) and !FORCE_SSL_ADMIN and ( false === strpos( get_option( 'siteurl' ), 'https://' ) ) )
			$url = set_url_scheme( $url, 'http' );
		return $url;
	}

	/**
	 * Sets the scheme of site links with empty schemes to 'http', so links on SSL pages link to
	 * 'http' rather than 'https'.
	 * 
	 * What this does: If you're on an SSL page but your site URL is not SSL, it stops links from
	 * showing as SSL links.
	 */
	public function enforce_home_scheme( $url, $path, $orig_scheme ) {
		if ( empty( $orig_scheme ) and ( false === strpos( get_option( 'home' ), 'https://' ) ) )
			$url = set_url_scheme( $url, 'http' );
		return $url;
	}

	/**
	 * [ssl_link description]
	 * @param  string $link         [description]
	 * @param  int|WP_Post $post_id [description]
	 * @return string [description]
	 */
	public function ssl_link( $link, $post_id ) {

		if ( get_post_meta( get_post( $post_id )->ID, 'force_ssl', true ) )
			$link = set_url_scheme( $link, 'https' );

		return $link;

	}

	public function ssl_request() {

		if ( is_ssl() )
			return;
		if ( !is_singular() )
			return;

		# There's not a lot we can do if something POSTs to a page over HTTP that
		# should have been HTTPS. @TODO we could trigger a notice/warning/something
		if ( 'POST' == $_SERVER['REQUEST_METHOD'] )
			return;

		if ( get_post_meta( get_the_ID(), 'force_ssl', true ) ) {
			wp_redirect( set_url_scheme( self::current_url(), 'https' ) );
			exit;
		}

	}

	/**
	 * Helper function. Returns the current URL.
	 *
	 * @return string The current URL
	 */
	public static function current_url() {
		return ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
	}

	public function save_meta( $post_id, WP_Post $post ) {

		if ( self::verify_meta_handler_nonce( $post->ID, 'force_ssl' ) ) {
			if ( !isset( $_POST['force_ssl'] ) )
				delete_post_meta( $post->ID, 'force_ssl' );
			else
				update_post_meta( $post->ID, 'force_ssl', 1 );
		}

	}

	/**
	 * Filter text to replace non-SSL embedded media with SSL versions.
	 */
	public function filter_embeds_in_text( $text ) {

		if ( !is_ssl() )
			return $text;

		$http  = home_url( null, 'http' );
		$https = home_url( null, 'https' );

		$text = str_replace( ' src="' . $http, ' src="' . $https, $text );
		$text = str_replace( " src='" . $http, " src='" . $https, $text );

		return $text;

	}

	public function sample_permalink( $return, $post_id ) {

		if ( 0 === strpos( home_url(), 'https://' ) )
			return $return;

		# Wrap the protocol in a span:
		$search  = '|id="sample-permalink"([^>]*)>http(s)?|';
		$replace = 'id="sample-permalink"$1><span id="permalink_protocol">http$2</span>';
		$return  = preg_replace( $search, $replace, $return );

		# Insert the checkbox and nonce field after the Edit button:
		$search  = '|<span id="edit-slug-buttons">(.*?)</a></span>|';
		$replace = '$0 <label id="ssl_label"><input type="checkbox" id="force_ssl" name="force_ssl"' . checked( get_post_meta( $post_id, 'force_ssl', true ), true, false ) . ' />&nbsp;SSL</label>' . self::meta_handler_nonce_field( $post_id, 'force_ssl' );
		$return  = preg_replace( $search, $replace, $return );

		return $return;

	}

	public function action_admin_head_post() {
		?>
		<script type="text/javascript">
			jQuery(function($){
				$(document).on('click','#force_ssl',function(){
					if ( "http" == $("#permalink_protocol").text() )
						$("#permalink_protocol").text("https");
					else
						$("#permalink_protocol").text("http");
				});
			});
		</script>
		<style type="text/css">
			#ssl_label {
				cursor: pointer !important;
				margin: 0 8px;
			}
			#permalink_protocol {
				width: 2.5em;
				text-align: right;
				display: inline-block;
			}
		</style>
		<?php
	}

	protected static function meta_handler_nonce_value( $post_id, $meta_name ) {
		return wp_create_nonce( self::meta_handler_nonce_name( $post_id, $meta_name ) );
	}

	protected static function meta_handler_nonce_name( $post_id, $meta_name ) {
		$meta_name = sanitize_title( $meta_name );
		return "handle_meta_{$post_id}_{$meta_name}_nonce";
	}

	protected static function meta_handler_nonce_field( $post_id, $meta_name ) {
		$name  = self::meta_handler_nonce_name( $post_id, $meta_name );
		$value = self::meta_handler_nonce_value( $post_id, $meta_name );
		return '<input type="hidden" name="' . $name . '" value="' . $value . '" />';
	}

	protected static function verify_meta_handler_nonce( $post_id, $meta_name ) {
		$name = self::meta_handler_nonce_name( $post_id, $meta_name );
		if ( isset( $_REQUEST[$name] ) )
			return wp_verify_nonce( $_REQUEST[$name], $name );
		return false;
	}

}

global $amsterdam_ssl;

$amsterdam_ssl = new Amsterdam_SSL;
