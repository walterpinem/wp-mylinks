<?php
/**
 * Loader for the AI agent feature (WordPress Abilities, exposed over MCP by
 * the separately installed MCP Adapter plugin).
 *
 * @package    Wp_Mylinks
 * @subpackage Wp_Mylinks/includes/agent
 * @since      1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mylinks-agent-access.php';
require_once __DIR__ . '/class-wp-mylinks-agent-fields.php';
require_once __DIR__ . '/class-wp-mylinks-agent-state.php';
