<?php
/**
 * SEO values for MyLinks pages.
 *
 * MyLinks pages render without wp_head(), so SEO plugins cannot print their
 * tags there. This class reads what those plugins saved for a page and keeps
 * noindex pages out of their sitemaps.
 *
 * Order for text and images: the page's own MyLinks field, the page's value in
 * an active SEO plugin, then the MyLinks global setting.
 *
 * @package Wp_Mylinks
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads SEO values for a MyLinks page.
 */
final class Wp_Mylinks_Seo {

	/**
	 * Values read from SEO plugins, by post ID.
	 *
	 * @var array
	 */
	private static $values = array();

	/**
	 * Noindex page IDs for this request.
	 *
	 * @var int[]|null
	 */
	private static $noindex = null;

	/**
	 * Hook the sitemaps of core and the supported SEO plugins.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'exclude_from_core' ), 10, 2 );
		add_filter( 'wpseo_exclude_from_sitemap_by_post_ids', array( __CLASS__, 'exclude_ids' ) );
		add_filter( 'the_seo_framework_sitemap_exclude_ids', array( __CLASS__, 'exclude_ids' ) );
		add_filter( 'aioseo_sitemap_exclude_posts', array( __CLASS__, 'exclude_ids' ) );
		add_filter( 'seopress_sitemaps_single_query', array( __CLASS__, 'exclude_from_query' ) );
		add_filter( 'rank_math/sitemap/entry', array( __CLASS__, 'exclude_from_rank_math' ), 10, 3 );
	}

	/**
	 * Meta title.
	 *
	 * @param int $post_id Page ID.
	 * @return string
	 */
	public static function title( $post_id ) {
		return self::text( $post_id, 'meta-title', 'title', 'mylinks_meta_title' );
	}

	/**
	 * Meta description.
	 *
	 * @param int $post_id Page ID.
	 * @return string
	 */
	public static function description( $post_id ) {
		return self::text( $post_id, 'meta-description', 'description', 'mylinks_meta_description' );
	}

	/**
	 * Canonical URL: the SEO plugin's page value, the home URL for a MyLink set
	 * as the front page, else the permalink.
	 *
	 * @param int $post_id Page ID.
	 * @return string
	 */
	public static function canonical( $post_id ) {
		$custom = esc_url_raw( self::from_plugins( $post_id, 'canonical' ), array( 'http', 'https' ) );

		if ( '' !== $custom ) {
			return $custom;
		}
		if ( 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === (int) $post_id ) {
			return home_url( '/' );
		}
		return (string) get_permalink( $post_id );
	}

	/**
	 * The page's share image in an active SEO plugin.
	 *
	 * @param int $post_id Page ID.
	 * @return string
	 */
	public static function image( $post_id ) {
		return esc_url_raw( self::from_plugins( $post_id, 'image' ), array( 'http', 'https' ) );
	}

	/**
	 * Robots flags. The page's MyLinks choice decides. On Inherit, an SEO plugin
	 * can add noindex or nofollow but never remove the global setting's.
	 *
	 * @param int $post_id Page ID.
	 * @return array{noindex:bool,nofollow:bool}
	 */
	public static function robots( $post_id ) {
		return array(
			'noindex'  => self::flag( $post_id, 'noindex', 'wp_mylinks_noindex' ),
			'nofollow' => self::flag( $post_id, 'nofollow', 'wp_mylinks_nofollow' ),
		);
	}

	/**
	 * Published MyLinks pages to keep out of sitemaps: noindex or password protected.
	 *
	 * @return int[]
	 */
	public static function noindex_ids() {
		if ( null === self::$noindex ) {
			$posts = get_posts(
				array(
					'post_type'              => 'mylink',
					'post_status'            => 'publish',
					'numberposts'            => -1,
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
				)
			);

			self::$noindex = array();
			foreach ( $posts as $post ) {
				if ( '' !== (string) $post->post_password || self::flag( (int) $post->ID, 'noindex', 'wp_mylinks_noindex' ) ) {
					self::$noindex[] = (int) $post->ID;
				}
			}
		}

		return self::$noindex;
	}

	/**
	 * Add noindex pages to a list of excluded IDs.
	 *
	 * @param mixed $ids Excluded IDs.
	 * @return int[]
	 */
	public static function exclude_ids( $ids ) {
		return array_values( array_unique( array_merge( array_map( 'intval', (array) $ids ), self::noindex_ids() ) ) );
	}

	/**
	 * Core sitemaps.
	 *
	 * @param array  $args      Query args.
	 * @param string $post_type Post type.
	 * @return array
	 */
	public static function exclude_from_core( $args, $post_type ) {
		if ( 'mylink' === $post_type ) {
			$args['post__not_in'] = self::exclude_ids( isset( $args['post__not_in'] ) ? $args['post__not_in'] : array() ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Sitemap query for this post type only, with a short list.
		}
		return $args;
	}

	/**
	 * SEOPress sitemap query.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	public static function exclude_from_query( $args ) {
		if ( is_array( $args ) && isset( $args['post_type'] ) && in_array( 'mylink', (array) $args['post_type'], true ) ) {
			$args['post__not_in'] = self::exclude_ids( isset( $args['post__not_in'] ) ? $args['post__not_in'] : array() ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Sitemap query for this post type only, with a short list.
		}
		return $args;
	}

	/**
	 * Rank Math sitemap entry.
	 *
	 * @param mixed  $url    Entry.
	 * @param string $type   Entry type.
	 * @param mixed  $object Source object.
	 * @return mixed False leaves the entry out.
	 */
	public static function exclude_from_rank_math( $url, $type, $object ) {
		// Rank Math passes a plain database row here, not a WP_Post.
		if ( 'post' === $type && is_object( $object ) && isset( $object->ID ) && 'mylink' === get_post_type( (int) $object->ID ) && in_array( (int) $object->ID, self::noindex_ids(), true ) ) {
			return false;
		}
		return $url;
	}

	/**
	 * One text value: page field, SEO plugin, global setting.
	 *
	 * @param int    $post_id Page ID.
	 * @param string $field   MyLinks field, unprefixed.
	 * @param string $key     Key in the SEO plugin values.
	 * @param string $option  Global option.
	 * @return string
	 */
	private static function text( $post_id, $field, $key, $option ) {
		$value = $post_id ? self::string( get_post_meta( $post_id, mylinks_prefix( $field ), true ) ) : '';
		if ( '' === $value && $post_id ) {
			$value = self::from_plugins( $post_id, $key );
		}
		return '' !== $value ? $value : self::string( get_option( $option, '' ) );
	}

	/**
	 * One robots flag.
	 *
	 * @param int    $post_id Page ID.
	 * @param string $field   noindex or nofollow.
	 * @param string $option  Global option.
	 * @return bool
	 */
	private static function flag( $post_id, $field, $option ) {
		$choice = $post_id ? self::string( get_post_meta( $post_id, mylinks_prefix( $field ), true ) ) : '';
		if ( 'yes' === $choice || 'no' === $choice ) {
			return 'yes' === $choice;
		}
		if ( $post_id ) {
			foreach ( self::plugin_values( $post_id, false ) as $values ) {
				if ( ! empty( $values[ $field ] ) ) {
					return true;
				}
			}
		}
		return 'yes' === get_option( $option );
	}

	/**
	 * First non-empty value among the active SEO plugins.
	 *
	 * @param int    $post_id Page ID.
	 * @param string $key     Value key.
	 * @return string
	 */
	private static function from_plugins( $post_id, $key ) {
		foreach ( self::plugin_values( $post_id ) as $values ) {
			$value = isset( $values[ $key ] ) ? self::string( wp_strip_all_tags( self::string( $values[ $key ] ) ) ) : '';
			if ( '' !== $value ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * What each active SEO plugin saved for this page. A failure in another
	 * plugin must never take the page down, so it only skips that plugin.
	 *
	 * @param int  $post_id   Page ID.
	 * @param bool $with_text False skips variable replacement, for robots-only reads.
	 * @return array[]
	 */
	private static function plugin_values( $post_id, $with_text = true ) {
		$post_id = (int) $post_id;
		if ( isset( self::$values[ $post_id ]['full'] ) ) {
			return self::$values[ $post_id ]['full'];
		}
		if ( ! $with_text && isset( self::$values[ $post_id ]['robots'] ) ) {
			return self::$values[ $post_id ]['robots'];
		}

		$post    = get_post( $post_id );
		$results = array();
		$readers = array(
			'yoast'     => function_exists( 'wp_mylinks_isYoastActive' ) && wp_mylinks_isYoastActive(),
			'rank_math' => function_exists( 'rank_math' ) && isset( rank_math()->variables ),
			'aioseo'    => function_exists( 'aioseo' ) && class_exists( '\AIOSEO\Plugin\Common\Models\Post' ),
			'seopress'  => function_exists( 'seopress_get_service' ),
			'tsf'       => function_exists( 'tsf' ),
		);

		if ( $post instanceof WP_Post ) {
			foreach ( array_keys( array_filter( $readers ) ) as $reader ) {
				try {
					$results[] = self::$reader( $post, $with_text );
				} catch ( \Throwable $e ) {
					continue;
				}
			}
		}

		self::$values[ $post_id ][ $with_text ? 'full' : 'robots' ] = $results;
		return $results;
	}

	/**
	 * Yoast SEO.
	 *
	 * @param WP_Post $post      Page.
	 * @param bool    $with_text Replace variables in title and description.
	 * @return array
	 */
	private static function yoast( WP_Post $post, $with_text ) {
		$vars = static function ( $text ) use ( $post, $with_text ) {
			if ( ! $with_text ) {
				return '';
			}
			return '' !== $text && function_exists( 'wpseo_replace_vars' ) ? wpseo_replace_vars( $text, $post ) : $text;
		};

		return array(
			'title'       => $vars( self::meta( $post, '_yoast_wpseo_title' ) ),
			'description' => $vars( self::meta( $post, '_yoast_wpseo_metadesc' ) ),
			'canonical'   => self::meta( $post, '_yoast_wpseo_canonical' ),
			'image'       => self::meta( $post, '_yoast_wpseo_opengraph-image' ),
			'noindex'     => '1' === self::meta( $post, '_yoast_wpseo_meta-robots-noindex' ),
			'nofollow'    => '1' === self::meta( $post, '_yoast_wpseo_meta-robots-nofollow' ),
		);
	}

	/**
	 * Rank Math.
	 *
	 * @param WP_Post $post      Page.
	 * @param bool    $with_text Replace variables in title and description.
	 * @return array
	 */
	private static function rank_math( WP_Post $post, $with_text ) {
		$vars   = static function ( $text ) use ( $post, $with_text ) {
			if ( ! $with_text ) {
				return '';
			}
			return '' !== $text ? \RankMath\Helper::replace_vars( $text, $post ) : $text;
		};
		$robots = (array) get_post_meta( $post->ID, 'rank_math_robots', true );

		return array(
			'title'       => $vars( self::meta( $post, 'rank_math_title' ) ),
			'description' => $vars( self::meta( $post, 'rank_math_description' ) ),
			'canonical'   => self::meta( $post, 'rank_math_canonical_url' ),
			'image'       => self::meta( $post, 'rank_math_facebook_image' ),
			'noindex'     => in_array( 'noindex', $robots, true ),
			'nofollow'    => in_array( 'nofollow', $robots, true ),
		);
	}

	/**
	 * All in One SEO, which keeps page values in its own table.
	 *
	 * @param WP_Post $post      Page.
	 * @param bool    $with_text Replace variables in title and description.
	 * @return array
	 */
	private static function aioseo( WP_Post $post, $with_text ) {
		$row  = \AIOSEO\Plugin\Common\Models\Post::getPost( $post->ID );
		$vars = static function ( $text ) use ( $post, $with_text ) {
			if ( ! $with_text ) {
				return '';
			}
			$text = self::string( $text );
			return '' !== $text ? aioseo()->tags->replaceTags( $text, $post->ID ) : $text;
		};
		$custom = empty( $row->robots_default );

		return array(
			'title'       => $vars( $row->title ),
			'description' => $vars( $row->description ),
			'canonical'   => self::string( $row->canonical_url ),
			'image'       => self::string( $row->og_image_custom_url ),
			'noindex'     => $custom && ! empty( $row->robots_noindex ),
			'nofollow'    => $custom && ! empty( $row->robots_nofollow ),
		);
	}

	/**
	 * SEOPress.
	 *
	 * @param WP_Post $post      Page.
	 * @param bool    $with_text Replace variables in title and description.
	 * @return array
	 */
	private static function seopress( WP_Post $post, $with_text ) {
		$vars = static function ( $text ) use ( $post, $with_text ) {
			if ( ! $with_text || '' === $text ) {
				return $text;
			}
			$context = seopress_get_service( 'ContextPage' )->buildContextWithCurrentId( $post->ID )->getContext();
			return seopress_get_service( 'TagsToString' )->replace( $text, $context );
		};

		return array(
			'title'       => $vars( self::meta( $post, '_seopress_titles_title' ) ),
			'description' => $vars( self::meta( $post, '_seopress_titles_desc' ) ),
			'canonical'   => self::meta( $post, '_seopress_robots_canonical' ),
			'image'       => self::meta( $post, '_seopress_social_fb_img' ),
			'noindex'     => 'yes' === self::meta( $post, '_seopress_robots_index' ),
			'nofollow'    => 'yes' === self::meta( $post, '_seopress_robots_follow' ),
		);
	}

	/**
	 * The SEO Framework. Its fields hold plain text.
	 *
	 * @param WP_Post $post Page.
	 * @return array
	 */
	private static function tsf( WP_Post $post ) {
		return array(
			'title'       => self::meta( $post, '_genesis_title' ),
			'description' => self::meta( $post, '_genesis_description' ),
			'canonical'   => self::meta( $post, '_genesis_canonical_uri' ),
			'image'       => self::meta( $post, '_social_image_url' ),
			'noindex'     => '1' === self::meta( $post, '_genesis_noindex' ),
			'nofollow'    => '1' === self::meta( $post, '_genesis_nofollow' ),
		);
	}

	/**
	 * A post meta value as a trimmed string.
	 *
	 * @param WP_Post $post Page.
	 * @param string  $key  Meta key.
	 * @return string
	 */
	private static function meta( WP_Post $post, $key ) {
		return self::string( get_post_meta( $post->ID, $key, true ) );
	}

	/**
	 * A stored value as a trimmed string.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function string( $value ) {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
