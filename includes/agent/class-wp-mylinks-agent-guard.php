<?php
/**
 * Keeps an agent's application password inside the agent controls.
 *
 * An application password is not limited to MCP: it also signs in to the core
 * REST API and XML-RPC. So MyLinks fields are protected meta on every site,
 * and while agents are read only or need approval, an application password
 * cannot write MyLinks pages through those two doors. A browser session, and
 * any integration on a site with agent access off, is left alone.
 *
 * @package    Wp_Mylinks
 * @subpackage Wp_Mylinks/includes/agent
 * @since      1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guards the non-MCP ways in.
 */
final class Wp_Mylinks_Agent_Guard {

	/**
	 * Did this request sign in with an application password?
	 *
	 * @var bool
	 */
	private static $app_password = false;

	/**
	 * Hook in, on every request.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'is_protected_meta', array( __CLASS__, 'protect_meta' ), 10, 3 );
		add_filter( 'wp_import_post_meta', array( __CLASS__, 'clean_import' ) );
		add_action( 'application_password_did_authenticate', array( __CLASS__, 'note_app_password' ) );
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'guard_rest' ), 10, 3 );
		add_filter( 'wp_insert_post_empty_content', array( __CLASS__, 'guard_xmlrpc_write' ), 10, 2 );
		add_filter( 'pre_trash_post', array( __CLASS__, 'guard_xmlrpc_delete' ), 10, 2 );
		add_filter( 'pre_delete_post', array( __CLASS__, 'guard_xmlrpc_delete' ), 10, 2 );
	}

	/**
	 * MyLinks fields are written only by the editor and the agent path, never
	 * as generic custom fields. Those fields include page scripts.
	 *
	 * @param bool   $protected Whether the key is protected.
	 * @param string $key       Meta key.
	 * @param string $type      Object type.
	 * @return bool
	 */
	public static function protect_meta( $protected, $key, $type = '' ) {
		if ( in_array( $type, array( '', 'post' ), true ) && ( 0 === strpos( (string) $key, 'mylinks_' ) || 0 === strpos( (string) $key, 'wp_mylinks_' ) ) ) {
			return true;
		}
		return $protected;
	}

	/**
	 * Remember that this request used an application password.
	 *
	 * @return void
	 */
	public static function note_app_password() {
		self::$app_password = true;
	}

	/**
	 * The WordPress importer writes meta directly, around every other check. An
	 * importer without unfiltered_html must not bring raw markup into MyLinks fields.
	 *
	 * @param mixed $postmeta Importer meta rows.
	 * @return mixed
	 */
	public static function clean_import( $postmeta ) {
		if ( ! is_array( $postmeta ) || current_user_can( 'unfiltered_html' ) ) {
			return $postmeta;
		}
		foreach ( $postmeta as $index => $row ) {
			if ( is_array( $row ) && isset( $row['key'], $row['value'] ) && self::protect_meta( false, $row['key'], 'post' ) ) {
				$value = is_string( $row['value'] ) && is_serialized( $row['value'] ) ? @unserialize( trim( $row['value'] ), array( 'allowed_classes' => false ) ) : $row['value']; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- No objects allowed, same data the importer reads.

				$postmeta[ $index ]['value'] = wp_kses_post_deep( $value );
			}
		}
		return $postmeta;
	}

	/**
	 * Must writes outside the abilities be refused right now? True at Read
	 * only, and at Read and edit while changes wait for approval.
	 *
	 * @return bool
	 */
	public static function restricted() {
		if ( ! Wp_Mylinks_Agent_Access::supported() ) {
			return false;
		}
		$level = Wp_Mylinks_Agent_Access::level();

		return 'read' === $level || ( 'write' === $level && 'approve' === Wp_Mylinks_Agent_Approval::mode() );
	}

	/**
	 * Refuse application-password writes to the core MyLinks REST routes,
	 * batch requests included. The abilities do not use these routes.
	 *
	 * @param mixed           $response Response so far.
	 * @param array           $handler  Route handler.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function guard_rest( $response, $handler, $request ) {
		unset( $handler );

		if ( is_wp_error( $response )
			|| ! $request instanceof WP_REST_Request
			|| in_array( $request->get_method(), array( 'GET', 'HEAD', 'OPTIONS' ), true )
			|| ! preg_match( '#^/wp/v2/mylink(/|$)#', $request->get_route() ) ) {
			return $response;
		}

		get_current_user_id(); // Makes sure authentication has run.

		if ( ! self::$app_password || ! self::restricted() ) {
			return $response;
		}

		return new WP_Error(
			'wp_mylinks_agent_use_abilities',
			__( 'This site does not let application passwords change MyLinks pages through the REST API. Use the wp-mylinks abilities over MCP, which follow the site\'s approval setting.', 'wp-mylinks' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Refuse application-password writes to MyLinks pages over XML-RPC. Every
	 * XML-RPC write method ends in wp_insert_post(), which stops here. The
	 * stored type is checked too, so a request cannot rename the type to pass.
	 *
	 * @param bool  $maybe_empty Whether core treats the post as empty.
	 * @param array $postarr     Post data.
	 * @return bool
	 */
	public static function guard_xmlrpc_write( $maybe_empty, $postarr ) {
		if ( ! self::xmlrpc_restricted() ) {
			return $maybe_empty;
		}

		$is_mylink = ( isset( $postarr['post_type'] ) && 'mylink' === $postarr['post_type'] )
			|| ( ! empty( $postarr['ID'] ) && 'mylink' === get_post_type( (int) $postarr['ID'] ) );

		return $is_mylink ? true : $maybe_empty;
	}

	/**
	 * Refuse application-password trashing and deleting over XML-RPC.
	 *
	 * @param mixed   $check Short-circuit value.
	 * @param WP_Post $post  Post.
	 * @return mixed
	 */
	public static function guard_xmlrpc_delete( $check, $post ) {
		return self::xmlrpc_restricted() && $post instanceof WP_Post && 'mylink' === $post->post_type ? false : $check;
	}

	/**
	 * Is this an application-password XML-RPC request while restricted?
	 *
	 * @return bool
	 */
	private static function xmlrpc_restricted() {
		return defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST && self::$app_password && self::restricted();
	}
}
