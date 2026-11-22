<?php
/**
 * Approval mode: an agent's change to a published page waits for a person.
 *
 * The change is stored as a proposal and visitors keep seeing the live page.
 * A hold mark stops an agent from getting around this by unpublishing a page,
 * changing it and publishing it again.
 *
 * @package    Wp_Mylinks
 * @subpackage Wp_Mylinks/includes/agent
 * @since      1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Proposals, the hold mark, the preview and the approval screen.
 */
final class Wp_Mylinks_Agent_Approval {

	/**
	 * Option holding the publish mode.
	 *
	 * @var string
	 */
	const OPTION = 'wp_mylinks_agent_publish';

	/**
	 * Meta key of the proposal.
	 *
	 * @var string
	 */
	const PROPOSAL = '_wp_mylinks_agent_proposal';

	/**
	 * Meta key of the hold mark.
	 *
	 * @var string
	 */
	const HOLD = '_wp_mylinks_agent_hold';

	/**
	 * Statuses visitors (or some of them) can see.
	 *
	 * @var string[]
	 */
	const HELD = array( 'publish', 'future', 'private' );

	/**
	 * Admin-post action, nonce prefix and result query arg.
	 *
	 * @var string
	 */
	const ACTION = 'wp_mylinks_agent_proposal';

	/**
	 * Preview query arg and nonce prefix.
	 *
	 * @var string
	 */
	const PREVIEW = 'wp_mylinks_agent_preview';

	/**
	 * Page being previewed in this request.
	 *
	 * @var int
	 */
	private static $previewing = 0;

	/**
	 * The proposal state served during a preview.
	 *
	 * @var array
	 */
	private static $preview_state = array();

	/**
	 * Meta keys the preview overrides.
	 *
	 * @var string[]
	 */
	private static $preview_keys = array();

	/**
	 * The publish mode. Anything unrecognized reads as approve.
	 *
	 * @return string approve or live.
	 */
	public static function mode() {
		return 'live' === get_option( self::OPTION, 'approve' ) ? 'live' : 'approve';
	}

	/**
	 * Must an agent's change to this page wait for approval?
	 *
	 * @param WP_Post $post Page.
	 * @return bool
	 */
	public static function holds( WP_Post $post ) {
		if ( self::proposal( $post ) ) {
			return true;
		}
		if ( 'approve' !== self::mode() ) {
			return false;
		}
		if ( in_array( $post->post_status, self::HELD, true ) ) {
			return true;
		}

		$hold = get_post_meta( $post->ID, self::HOLD, true );

		return is_array( $hold ) && isset( $hold['page'] ) && (int) $hold['page'] === (int) $post->ID;
	}

	/**
	 * The waiting proposal. A record copied onto another post is ignored.
	 *
	 * @param WP_Post $post Page.
	 * @return array|null
	 */
	public static function proposal( WP_Post $post ) {
		$proposal = get_post_meta( $post->ID, self::PROPOSAL, true );

		return is_array( $proposal )
			&& isset( $proposal['page'], $proposal['state'], $proposal['based_on'] )
			&& (int) $proposal['page'] === (int) $post->ID
			? $proposal
			: null;
	}

	/**
	 * Store a proposal. Nothing live is touched.
	 *
	 * @param WP_Post  $post     Page.
	 * @param array    $live     Live state.
	 * @param array    $proposed Proposed state.
	 * @param string   $ability  Ability name.
	 * @param string[] $changed  Agent keys changed by this call.
	 * @return array The stored record.
	 */
	public static function propose( WP_Post $post, array $live, array $proposed, $ability, array $changed ) {
		$record = self::proposal( $post );

		if ( ! $record ) {
			$record = array(
				'page'     => (int) $post->ID,
				'based_on' => Wp_Mylinks_Agent_State::version( $live ),
				'first'    => time(),
				'writes'   => 0,
				'writers'  => array(),
				'changed'  => array(),
			);
		}

		$record['state']   = $proposed;
		$record['last']    = time();
		$record['writes']  = (int) $record['writes'] + 1;
		$record['writers'] = array_values( array_unique( array_merge( $record['writers'], array( get_current_user_id() ) ) ) );
		$record['changed'] = array_values( array_unique( array_merge( $record['changed'], $changed ) ) );

		update_post_meta( $post->ID, self::PROPOSAL, wp_slash( $record ) );
		Wp_Mylinks_Agent_Changes::log( $post->ID, 'propose', $ability, $changed );

		return $record;
	}

	/**
	 * Apply a proposal to the live page.
	 *
	 * @param int    $post_id Page ID.
	 * @param string $seen    Hash from the review form.
	 * @return string approved, none, changed, stale, locked or error.
	 */
	public static function approve( $post_id, $seen ) {
		$post     = get_post( $post_id );
		$proposal = $post instanceof WP_Post ? self::proposal( $post ) : null;

		if ( ! $proposal ) {
			return 'none';
		}
		if ( ! hash_equals( Wp_Mylinks_Agent_Changes::seen( $proposal ), (string) $seen ) ) {
			return 'changed';
		}

		require_once ABSPATH . 'wp-admin/includes/post.php';
		if ( wp_check_post_lock( $post->ID ) ) {
			return 'locked';
		}

		$live = Wp_Mylinks_Agent_State::read( $post );
		if ( Wp_Mylinks_Agent_State::version( $live ) !== $proposal['based_on'] ) {
			return 'stale';
		}

		if ( is_wp_error( Wp_Mylinks_Agent_State::write( $post, $proposal['state'] ) ) ) {
			return 'error';
		}

		delete_post_meta( $post->ID, self::PROPOSAL );
		Wp_Mylinks_Agent_Changes::record( get_post( $post->ID ), $live, 'approved', '', $proposal['changed'] );

		return 'approved';
	}

	/**
	 * Throw a proposal away.
	 *
	 * @param int    $post_id Page ID.
	 * @param string $seen    Hash from the review form.
	 * @return string discarded, none or changed.
	 */
	public static function discard( $post_id, $seen ) {
		$post     = get_post( $post_id );
		$proposal = $post instanceof WP_Post ? self::proposal( $post ) : null;

		if ( ! $proposal ) {
			return 'none';
		}
		if ( ! hash_equals( Wp_Mylinks_Agent_Changes::seen( $proposal ), (string) $seen ) ) {
			return 'changed';
		}

		delete_post_meta( $post->ID, self::PROPOSAL );
		Wp_Mylinks_Agent_Changes::log( $post->ID, 'discard', '', $proposal['changed'] );

		return 'discarded';
	}

	/**
	 * Hooks needed on every request: a status can change over REST or WP-CLI,
	 * an export can run from WP-CLI, and the preview runs on the front end.
	 *
	 * @return void
	 */
	public static function register_everywhere() {
		add_action( 'transition_post_status', array( __CLASS__, 'mark_hold' ), 10, 3 );
		add_filter( 'wxr_export_skip_postmeta', array( __CLASS__, 'skip_export' ), 10, 2 );
		add_action( 'save_post', array( __CLASS__, 'clear_hold' ), 20, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_preview' ), 0 );
		add_filter( 'wp_mylinks_is_bot_request', array( __CLASS__, 'mute_preview' ) );
	}

	/**
	 * Hook the approval screen (wp-admin only).
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_filter( 'display_post_states', array( __CLASS__, 'post_state' ), 10, 2 );
	}

	/**
	 * Mark a page leaving a visible status.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 * @return void
	 */
	public static function mark_hold( $new_status, $old_status, $post ) {
		if ( ! $post instanceof WP_Post
			|| 'mylink' !== $post->post_type
			|| ! in_array( $old_status, self::HELD, true )
			|| in_array( $new_status, self::HELD, true ) ) {
			return;
		}

		update_post_meta(
			$post->ID,
			self::HOLD,
			array(
				'page'  => (int) $post->ID,
				'since' => time(),
				'from'  => (string) $old_status,
				'user'  => get_current_user_id(),
			)
		);
	}

	/**
	 * Clear the hold when a person saves the unpublished page in the editor.
	 * Only a verified metabox nonce counts: agents have no way to produce one.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @return void
	 */
	public static function clear_hold( $post_id, $post ) {
		if ( 'mylink' !== $post->post_type
			|| wp_is_post_revision( $post_id )
			|| in_array( $post->post_status, self::HELD, true )
			|| ! metadata_exists( 'post', $post_id, self::HOLD )
			|| ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		foreach ( array_keys( Wp_Mylinks_Metabox::boxes() ) as $box_id ) {
			$key = 'wp_mylinks_' . $box_id . '_nonce';
			if ( isset( $_POST[ $key ] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ $key ] ) ), 'wp_mylinks_save_' . $box_id ) ) {
				delete_post_meta( $post_id, self::HOLD );
				return;
			}
		}
	}

	/**
	 * Leave agent bookkeeping out of WordPress exports.
	 *
	 * @param bool   $skip     Whether to skip the key.
	 * @param string $meta_key Meta key.
	 * @return bool
	 */
	public static function skip_export( $skip, $meta_key ) {
		return in_array( $meta_key, array( self::PROPOSAL, self::HOLD, Wp_Mylinks_Agent_Changes::PENDING, Wp_Mylinks_Agent_Changes::LOG ), true ) ? true : $skip;
	}

	/**
	 * Serve the proposal instead of the live page for an authorized preview.
	 *
	 * @return void
	 */
	public static function maybe_preview() {
		if ( ! isset( $_GET[ self::PREVIEW ] ) ) {
			return;
		}

		$post  = get_queried_object();
		$nonce = sanitize_text_field( wp_unslash( $_GET[ self::PREVIEW ] ) );

		if ( ! $post instanceof WP_Post
			|| 'mylink' !== $post->post_type
			|| ! wp_verify_nonce( $nonce, self::PREVIEW . '_' . $post->ID )
			|| ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		$proposal = self::proposal( $post );
		if ( ! $proposal ) {
			return;
		}

		self::$previewing    = (int) $post->ID;
		self::$preview_state = $proposal['state'];
		self::$preview_keys  = Wp_Mylinks_Agent_Fields::state_keys();

		add_filter( 'get_post_metadata', array( __CLASS__, 'preview_meta' ), 10, 4 );
		add_filter( 'the_title', array( __CLASS__, 'preview_title' ), 10, 2 );

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The convention page-cache plugins read.
		}
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow' );
		}
	}

	/**
	 * Proposal values during a preview.
	 *
	 * Core takes element [0] of a non-null array when $single is true, and
	 * metadata_exists() casts the result to bool, so a missing key is '' for a
	 * single read.
	 *
	 * @param mixed  $value     Short-circuit value.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 * @param bool   $single    Single value requested.
	 * @return mixed
	 */
	public static function preview_meta( $value, $object_id, $meta_key, $single ) {
		if ( (int) $object_id !== self::$previewing || ! in_array( $meta_key, self::$preview_keys, true ) ) {
			return $value;
		}
		if ( array_key_exists( $meta_key, self::$preview_state['meta'] ) ) {
			return array( self::$preview_state['meta'][ $meta_key ] );
		}
		return $single ? '' : array();
	}

	/**
	 * Proposed title during a preview.
	 *
	 * @param string $title Title.
	 * @param int    $id    Post ID.
	 * @return string
	 */
	public static function preview_title( $title, $id = 0 ) {
		return (int) $id === self::$previewing ? self::$preview_state['title'] : $title;
	}

	/**
	 * Keep previews out of the counts: the page view itself, and clicks made
	 * from it, which arrive as beacons with the preview URL as referer.
	 *
	 * @param bool $is_bot Current answer.
	 * @return bool
	 */
	public static function mute_preview( $is_bot ) {
		if ( self::$previewing ) {
			return true;
		}
		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';

		return false !== strpos( $referer, self::PREVIEW . '=' ) ? true : $is_bot;
	}

	/**
	 * Handle the Approve and Discard buttons.
	 *
	 * @return void
	 */
	public static function handle() {
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

		check_admin_referer( self::ACTION . '_' . $post_id, '_wml_proposal_nonce' );

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'mylink' !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to review this page.', 'wp-mylinks' ), '', array( 'response' => 403 ) );
		}

		$action = isset( $_POST['wml_action'] ) ? sanitize_key( wp_unslash( $_POST['wml_action'] ) ) : '';
		$seen   = isset( $_POST['seen'] ) ? sanitize_text_field( wp_unslash( $_POST['seen'] ) ) : '';

		$result = 'none';
		if ( 'approve' === $action ) {
			$result = self::approve( $post_id, $seen );
		} elseif ( 'discard' === $action ) {
			$result = self::discard( $post_id, $seen );
		}

		wp_safe_redirect( add_query_arg( self::ACTION, $result, get_edit_post_link( $post_id, 'raw' ) ) );
		exit;
	}

	/**
	 * Show the proposal notice and the result of the last action.
	 *
	 * @return void
	 */
	public static function notice() {
		$screen = get_current_screen();
		if ( ! $screen || 'mylink' !== $screen->post_type || 'post' !== $screen->base ) {
			return;
		}

		self::render_result();

		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen context.
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$proposal = self::proposal( $post );
		if ( ! $proposal ) {
			return;
		}

		$preview = get_preview_post_link( $post, array( self::PREVIEW => wp_create_nonce( self::PREVIEW . '_' . $post_id ) ) );
		$message = sprintf(
			/* translators: 1: user name(s), 2: list of changed fields */
			__( 'An AI agent working as %1$s proposes changes to this page: %2$s. Visitors still see the current page.', 'wp-mylinks' ),
			Wp_Mylinks_Agent_Changes::names( $proposal['writers'] ),
			Wp_Mylinks_Agent_Changes::labels( $proposal['changed'] )
		);
		?>
		<div class="notice notice-info">
			<p><?php echo esc_html( $message ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post_id ); ?>">
				<input type="hidden" name="seen" value="<?php echo esc_attr( Wp_Mylinks_Agent_Changes::seen( $proposal ) ); ?>">
				<?php wp_nonce_field( self::ACTION . '_' . $post_id, '_wml_proposal_nonce' ); ?>
				<p>
					<a class="button" href="<?php echo esc_url( $preview ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Preview proposed page', 'wp-mylinks' ); ?></a>
					<button type="submit" class="button button-primary" name="wml_action" value="approve"><?php esc_html_e( 'Approve and publish', 'wp-mylinks' ); ?></button>
					<button type="submit" class="button" name="wml_action" value="discard"><?php esc_html_e( 'Discard', 'wp-mylinks' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Mark pages with a waiting proposal in the list table.
	 *
	 * @param array   $states Post states.
	 * @param WP_Post $post   Post.
	 * @return array
	 */
	public static function post_state( $states, $post ) {
		if ( $post instanceof WP_Post && 'mylink' === $post->post_type && self::proposal( $post ) ) {
			$states['wp_mylinks_agent_proposal'] = __( 'Waiting for approval', 'wp-mylinks' );
		}
		return $states;
	}

	/**
	 * Result of the last Approve or Discard.
	 *
	 * @return void
	 */
	private static function render_result() {
		$result = isset( $_GET[ self::ACTION ] ) ? sanitize_key( wp_unslash( $_GET[ self::ACTION ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, from a fixed list.

		$messages = array(
			'approved'  => array( 'success', __( 'Proposal approved. Visitors now see the agent\'s changes.', 'wp-mylinks' ) ),
			'discarded' => array( 'success', __( 'Proposal discarded. The page was not changed.', 'wp-mylinks' ) ),
			'stale'     => array( 'error', __( 'Not applied: this page changed after the agent made its proposal. Discard it, then ask the agent to work from the current page.', 'wp-mylinks' ) ),
			'changed'   => array( 'warning', __( 'Nothing was done: the proposal changed after this screen loaded. Review it below.', 'wp-mylinks' ) ),
			'locked'    => array( 'error', __( 'Not applied: someone else is editing this page.', 'wp-mylinks' ) ),
			'none'      => array( 'info', __( 'There is no proposal on this page.', 'wp-mylinks' ) ),
			'error'     => array( 'error', __( 'The proposal could not be applied.', 'wp-mylinks' ) ),
		);

		if ( isset( $messages[ $result ] ) ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $messages[ $result ][0] ),
				esc_html( $messages[ $result ][1] )
			);
		}
	}
}
