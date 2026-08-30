<?php

defined( 'ABSPATH' ) or exit;

/**
 * Fallback live link and domain rewriter inside the theme.
 */
if ( ! class_exists( 'Live_Link_Asset_Rewriter' ) ) {
	class Live_Link_Asset_Rewriter {

		private static $instance = null;
		public $home_host = 'marclesterbagal.local';
		public $tunnel_host = '';
		public $tunnel_proto = 'http';
		public $is_tunnel = false;
		private $buffering_started = false;

		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		public function __construct() {
			$this->detect_environment();
			if ( $this->is_tunnel ) {
				$this->register_hooks();
			}
		}

		public function detect_environment() {
			$home_option = get_option( 'home' );
			if ( ! empty( $home_option ) ) {
				$parsed = parse_url( $home_option, PHP_URL_HOST );
				if ( ! empty( $parsed ) ) {
					$this->home_host = $parsed;
				}
			}

			if ( ! empty( $_SERVER['HTTP_X_ORIGINAL_HOST'] ) ) {
				$this->tunnel_host = trim( $_SERVER['HTTP_X_ORIGINAL_HOST'] );
			} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_HOST'] ) ) {
				$hosts = explode( ',', $_SERVER['HTTP_X_FORWARDED_HOST'] );
				$this->tunnel_host = trim( $hosts[0] );
			} elseif ( ! empty( $_SERVER['HTTP_HOST'] ) && strpos( $_SERVER['HTTP_HOST'], 'localsite.io' ) !== false ) {
				$this->tunnel_host = trim( $_SERVER['HTTP_HOST'] );
			}

			if (
				( ! empty( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && strtolower( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) === 'https' ) ||
				( ! empty( $_SERVER['HTTP_X_FORWARDED_SSL'] ) && strtolower( $_SERVER['HTTP_X_FORWARDED_SSL'] ) === 'on' ) ||
				( ! empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== 'off' ) ||
				( isset( $_SERVER['SERVER_PORT'] ) && $_SERVER['SERVER_PORT'] == 443 ) ||
				( ! empty( $this->tunnel_host ) && strpos( $this->tunnel_host, 'localsite.io' ) !== false )
			) {
				$this->tunnel_proto = 'https';
			} else {
				$this->tunnel_proto = 'http';
			}

			if ( ! empty( $this->tunnel_host ) && strcasecmp( $this->tunnel_host, $this->home_host ) !== 0 ) {
				$this->is_tunnel = true;
			}
		}

		private function register_hooks() {
			remove_action( 'template_redirect', 'redirect_canonical' );
			add_filter( 'redirect_canonical', '__return_false' );

			$this->start_output_buffering();
			add_action( 'init', array( $this, 'start_output_buffering' ), -99999 );
			add_action( 'template_redirect', array( $this, 'start_output_buffering' ), -99999 );
			add_action( 'wp_head', array( $this, 'start_output_buffering' ), -99999 );

			add_filter( 'the_content', array( $this, 'rewrite_content' ), 9999 );
			add_filter( 'the_excerpt', array( $this, 'rewrite_content' ), 9999 );
			add_filter( 'widget_text', array( $this, 'rewrite_content' ), 9999 );
			add_filter( 'widget_custom_html_content', array( $this, 'rewrite_content' ), 9999 );
			add_filter( 'elementor/frontend/the_content', array( $this, 'rewrite_content' ), 9999 );
			add_filter( 'elementor/widget/render_content', array( $this, 'rewrite_content' ), 9999 );

			add_filter( 'wp_get_attachment_url', array( $this, 'rewrite_url' ), 9999 );
			add_filter( 'wp_get_attachment_image_src', array( $this, 'rewrite_array_urls' ), 9999 );
			add_filter( 'wp_calculate_image_srcset', array( $this, 'rewrite_srcset' ), 9999 );
			add_filter( 'wp_get_attachment_image_attributes', array( $this, 'rewrite_image_attributes' ), 9999 );

			add_filter( 'style_loader_src', array( $this, 'rewrite_url' ), 9999 );
			add_filter( 'script_loader_src', array( $this, 'rewrite_url' ), 9999 );
			add_filter( 'theme_file_uri', array( $this, 'rewrite_url' ), 9999 );
			add_filter( 'parent_theme_file_uri', array( $this, 'rewrite_url' ), 9999 );
			add_filter( 'rest_url', array( $this, 'rewrite_url' ), 9999 );
		}

		public function start_output_buffering() {
			if ( ! $this->buffering_started ) {
				ob_start( array( $this, 'rewrite_output_buffer' ) );
				$this->buffering_started = true;
			}
		}

		public function rewrite_output_buffer( $buffer ) {
			if ( empty( $buffer ) || ! is_string( $buffer ) ) {
				return $buffer;
			}
			return $this->replace_domain_strings( $buffer );
		}

		public function rewrite_content( $content ) {
			if ( empty( $content ) || ! is_string( $content ) ) {
				return $content;
			}
			return $this->replace_domain_strings( $content );
		}

		public function rewrite_url( $url ) {
			if ( empty( $url ) || ! is_string( $url ) ) {
				return $url;
			}
			return $this->replace_domain_strings( $url );
		}

		public function rewrite_srcset( $sources ) {
			if ( ! is_array( $sources ) ) {
				return $sources;
			}
			foreach ( $sources as &$source ) {
				if ( isset( $source['url'] ) ) {
					$source['url'] = $this->replace_domain_strings( $source['url'] );
				}
			}
			return $sources;
		}

		public function rewrite_image_attributes( $attr ) {
			if ( ! is_array( $attr ) ) {
				return $attr;
			}
			if ( isset( $attr['src'] ) ) {
				$attr['src'] = $this->replace_domain_strings( $attr['src'] );
			}
			if ( isset( $attr['srcset'] ) ) {
				$attr['srcset'] = $this->replace_domain_strings( $attr['srcset'] );
			}
			return $attr;
		}

		public function rewrite_array_urls( $item ) {
			if ( is_string( $item ) ) {
				return $this->replace_domain_strings( $item );
			}
			if ( is_array( $item ) ) {
				foreach ( $item as $key => $val ) {
					$item[ $key ] = $this->rewrite_array_urls( $val );
				}
			}
			return $item;
		}

		private function replace_domain_strings( $str ) {
			if ( ! is_string( $str ) || empty( $str ) ) {
				return $str;
			}

			$local_host = $this->home_host;
			$target_host = $this->tunnel_host;
			$target_proto = $this->tunnel_proto;

			$replacements = array(
				'http://' . $local_host   => $target_proto . '://' . $target_host,
				'https://' . $local_host  => $target_proto . '://' . $target_host,
				'//' . $local_host        => '//' . $target_host,
				'http:\/\/' . $local_host  => $target_proto . ':\/\/' . $target_host,
				'https:\/\/' . $local_host => $target_proto . ':\/\/' . $target_host,
				'\/\/' . $local_host       => '\/\/' . $target_host,
				'http%3A%2F%2F' . $local_host  => $target_proto . '%3A%2F%2F' . $target_host,
				'https%3A%2F%2F' . $local_host => $target_proto . '%3A%2F%2F' . $target_host,
			);

			if ( $local_host !== 'marclesterbagal.local' ) {
				$replacements['http://marclesterbagal.local'] = $target_proto . '://' . $target_host;
				$replacements['https://marclesterbagal.local'] = $target_proto . '://' . $target_host;
				$replacements['//marclesterbagal.local'] = '//' . $target_host;
				$replacements['http:\/\/marclesterbagal.local'] = $target_proto . ':\/\/' . $target_host;
				$replacements['https:\/\/marclesterbagal.local'] = $target_proto . ':\/\/' . $target_host;
			}

			return strtr( $str, $replacements );
		}
	}

	Live_Link_Asset_Rewriter::get_instance();
}
