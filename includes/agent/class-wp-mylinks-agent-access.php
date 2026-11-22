<?php
/**
 * AI agent access level and the public agent API.
 *
 * One question asked in one place: how much may an AI agent do on this site
 * right now? It is asked when abilities register and again on every call, so
 * a setting changed mid-request is honored at once. Add-ons ask here too and
 * never read the option themselves.
 *
 * @package    Wp_Mylinks
 * @subpackage Wp_Mylinks/includes/agent
 * @since      1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the agent access setting.
 */
final class Wp_Mylinks_Agent_Access {

	/**
	 * Option holding the level.
	 *
	 * @var string
	 */
	const OPTION = 'wp_mylinks_agent_access';

	/**
	 * Levels, lowest first.
	 *
	 * @var array<string,int>
	 */
	const LEVELS = array(
		'off'   => 0,
		'read'  => 1,
		'write' => 2,
	);

	/**
	 * First WordPress version whose Abilities API these abilities target. The
	 * API also exists as a feature plugin in an older shape, so having the
	 * functions is not enough on its own.
	 *
	 * @var string
	 */
	const MIN_WP = '6.9';

	/**
	 * Does this WordPress have the Abilities API in the shape used here?
	 *
	 * @return bool
	 */
	public static function supported() {
		return function_exists( 'wp_register_ability' )
			&& function_exists( 'wp_register_ability_category' )
			&& version_compare( (string) get_bloginfo( 'version' ), self::MIN_WP, '>=' );
	}

	/**
	 * The level the site owner chose. Anything unrecognized reads as off.
	 *
	 * @return string
	 */
	public static function stored() {
		$stored = get_option( self::OPTION, 'off' );

		return is_string( $stored ) && isset( self::LEVELS[ $stored ] ) ? $stored : 'off';
	}

	/**
	 * The level in force: the stored one, unless code lowers it.
	 *
	 * @return string
	 */
	public static function level() {
		$level = self::stored();

		/**
		 * Filters how much AI agents may do on this site.
		 *
		 * A filter can only lower the level the site owner chose, never raise
		 * it: return 'off' or 'read'. Anything else is ignored.
		 *
		 * @since 1.2.0
		 *
		 * @param string $level 'off', 'read' or 'write'.
		 */
		$filtered = apply_filters( 'wp_mylinks_agent_access_level', $level );

		if ( is_string( $filtered ) && isset( self::LEVELS[ $filtered ] ) && self::LEVELS[ $filtered ] < self::LEVELS[ $level ] ) {
			$level = $filtered;
		}

		return $level;
	}

	/**
	 * May agents work at this level here, now?
	 *
	 * @param string $needed 'read' or 'write'.
	 * @return bool
	 */
	public static function allows( $needed ) {
		if ( ! is_string( $needed ) || 'off' === $needed || ! isset( self::LEVELS[ $needed ] ) || ! self::supported() ) {
			return false;
		}

		return self::LEVELS[ self::level() ] >= self::LEVELS[ $needed ];
	}
}

/**
 * May agents work at this level on this site right now?
 *
 * @since 1.2.0
 *
 * @param string $level 'read' or 'write'.
 * @return bool
 */
function wp_mylinks_agent_allows( $level ) {
	return Wp_Mylinks_Agent_Access::allows( $level );
}

/**
 * The checks every ability shares: a signed-in user and access at this level.
 * Call it first in every permission and execute callback.
 *
 * @since 1.2.0
 *
 * @param string $level 'read' or 'write'.
 * @return true|WP_Error
 */
function wp_mylinks_agent_gate( $level ) {
	if ( ! is_user_logged_in() ) {
		return new WP_Error(
			'wp_mylinks_agent_login_required',
			__( 'Sign in as a WordPress user first. An agent acts as the user whose application password it uses.', 'wp-mylinks' ),
			array( 'status' => 401 )
		);
	}

	if ( ! Wp_Mylinks_Agent_Access::allows( $level ) ) {
		return new WP_Error(
			'wp_mylinks_agent_access_off',
			'write' === $level
				? __( 'Agents may not change MyLinks pages on this site. A site administrator can allow it under MyLinks, Settings, AI agents.', 'wp-mylinks' )
				: __( 'Agent access to MyLinks pages is switched off on this site.', 'wp-mylinks' ),
			array( 'status' => 403 )
		);
	}

	return true;
}

/**
 * The meta array every ability registers with: exposed over MCP, hidden from
 * the core Abilities REST routes.
 *
 * @since 1.2.0
 *
 * @param bool $readonly    The ability changes nothing.
 * @param bool $destructive The ability can overwrite existing content.
 * @param bool $idempotent  Repeating the same call changes nothing further.
 * @return array
 */
function wp_mylinks_agent_ability_meta( $readonly, $destructive, $idempotent ) {
	return array(
		'annotations'  => array(
			'readonly'    => (bool) $readonly,
			'destructive' => (bool) $destructive,
			'idempotent'  => (bool) $idempotent,
		),
		'show_in_rest' => false,
		'public'       => false,
		'mcp'          => array(
			'public' => true,
			'type'   => 'tool',
		),
	);
}
