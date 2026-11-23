<?php
/**
 * The record of what AI agents changed, with Undo and Keep.
 *
 * Every applied agent write stores the page as it was before the first
 * unreviewed change. The page's edit screen then offers Undo or Keep. These
 * stay available after agent access is switched off.
 *
 * @package    Wp_Mylinks
 * @subpackage Wp_Mylinks/includes/agent
 * @since      1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Change log, pending review record, and the review screen.
 */
final class Wp_Mylinks_Agent_Changes {

	/**
	 * Meta key of the change log.
	 *
	 * @var string
	 */
	const LOG = '_wp_mylinks_agent_log';

	/**
	 * Meta key of the record awaiting review.
	 *
	 * @var string
	 */
	const PENDING = '_wp_mylinks_agent_pending';

	/**
	 * Log entries kept per page.
	 *
	 * @var int
	 */
	const KEEP = 20;

	/**
	 * Admin-post action, nonce prefix and result query arg.
	 *
	 * @var string
	 */
	const ACTION = 'wp_mylinks_agent_review';

	/**
	 * Record an applied agent write. Call it after the page is saved.
	 *
	 * A new review record starts when none exists, or when a person changed
	 * the page after the last agent write, so Undo never rolls back their work.
	 *
	 * @param WP_Post  $post    Page.
	 * @param array    $before  State before this write.
	 * @param string   $event   Event name.
	 * @param string   $ability Ability name.
	 * @param string[] $changed Agent keys changed.
	 * @param bool     $created This write created the page.
	 * @return array The log entry.
	 */
	public static function record( WP_Post $post, array $before, $event, $ability, array $changed, $created = false ) {
		$pending = self::pending( $post->ID );

		if ( ! $pending || Wp_Mylinks_Agent_State::version( $before ) !== $pending['after_version'] ) {
			$pending = array(
				'page'          => (int) $post->ID,
				'before'        => $before,
				'created'       => (bool) $created,
				'writes'        => 0,
				'first'         => time(),
				'last'          => 0,
				'writers'       => array(),
				'changed'       => array(),
				'after_version' => '',
			);
		}

		// An approval is a person's review. It only reads as reviewed when no
		// unreviewed agent write sits in the same record.
		$pending['approved'] = 'approved' === $event && 0 === (int) $pending['writes'];
		if ( 'approved' !== $event || $pending['approved'] ) {
			$pending['writers'] = array_values( array_unique( array_merge( $pending['writers'], array( get_current_user_id() ) ) ) );
		}

		++$pending['writes'];
		$pending['last']          = time();
		$pending['changed']       = array_values( array_unique( array_merge( $pending['changed'], $changed ) ) );
		$pending['after_version'] = Wp_Mylinks_Agent_State::version( Wp_Mylinks_Agent_State::read( get_post( $post->ID ) ) );

		update_post_meta( $post->ID, self::PENDING, wp_slash( $pending ) );

		return self::log( $post->ID, $event, $ability, $changed );
	}

	/**
	 * Add a log entry, keeping the newest ones.
	 *
	 * @param int      $post_id Page ID.
	 * @param string   $event   Event name.
	 * @param string   $ability Ability name, or '' for a person's action.
	 * @param string[] $changed Agent keys.
	 * @return array The entry.
	 */
	public static function log( $post_id, $event, $ability, array $changed ) {
		$log = get_post_meta( $post_id, self::LOG, true );
		$log = is_array( $log ) ? $log : array();

		$entry = array(
			'time'    => time(),
			'user'    => get_current_user_id(),
			'event'   => (string) $event,
			'ability' => (string) $ability,
			'fields'  => array_values( $changed ),
		);

		$log[] = $entry;
		update_post_meta( $post_id, self::LOG, wp_slash( array_slice( $log, -self::KEEP ) ) );

		/**
		 * Fires after an AI agent event is logged on a MyLinks page.
		 *
		 * @since 1.2.0
		 *
		 * @param int   $post_id Page ID.
		 * @param array $entry   {time, user, event, ability, fields}.
		 */
		do_action( 'wp_mylinks_agent_changed', $post_id, $entry );

		return $entry;
	}

	/**
	 * The record awaiting review. A record copied onto another post is ignored.
	 *
	 * @param int $post_id Page ID.
	 * @return array|null
	 */
	public static function pending( $post_id ) {
		$pending = get_post_meta( $post_id, self::PENDING, true );

		return is_array( $pending )
			&& isset( $pending['page'], $pending['before'], $pending['after_version'] )
			&& (int) $pending['page'] === (int) $post_id
			? $pending
			: null;
	}

	/**
	 * Short hash of a record: the review form sends back the one it showed.
	 *
	 * @param array $record Record.
	 * @return string
	 */
	public static function seen( array $record ) {
		return substr( md5( serialize( $record ) ), 0, 12 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- A fingerprint, never unserialized.
	}

	/**
	 * Undo the agent's unreviewed changes.
	 *
	 * @param int    $post_id Page ID.
	 * @param string $seen    Hash from the review form.
	 * @return string undone, stale, changed, none or error.
	 */
	public static function undo( $post_id, $seen ) {
		$post    = get_post( $post_id );
		$pending = $post instanceof WP_Post ? self::pending( $post->ID ) : null;

		if ( ! $pending ) {
			return 'none';
		}
		if ( ! hash_equals( self::seen( $pending ), (string) $seen ) ) {
			return 'changed';
		}
		if ( Wp_Mylinks_Agent_State::version( Wp_Mylinks_Agent_State::read( $post ) ) !== $pending['after_version'] ) {
			return 'stale';
		}

		if ( ! empty( $pending['created'] ) ) {
			delete_post_meta( $post->ID, self::PENDING );
			self::log( $post->ID, 'undo', '', $pending['changed'] );
			return wp_trash_post( $post->ID ) ? 'undone' : 'error';
		}

		if ( is_wp_error( Wp_Mylinks_Agent_State::write( $post, $pending['before'] ) ) ) {
			return 'error';
		}

		delete_post_meta( $post->ID, self::PENDING );
		self::log( $post->ID, 'undo', '', $pending['changed'] );

		return 'undone';
	}

	/**
	 * Accept the agent's changes and clear the review.
	 *
	 * @param int    $post_id Page ID.
	 * @param string $seen    Hash from the review form.
	 * @return string kept, changed or none.
	 */
	public static function keep( $post_id, $seen ) {
		$pending = self::pending( $post_id );

		if ( ! $pending ) {
			return 'none';
		}
		if ( ! hash_equals( self::seen( $pending ), (string) $seen ) ) {
			return 'changed';
		}

		delete_post_meta( $post_id, self::PENDING );
		self::log( $post_id, 'keep', '', $pending['changed'] );

		return 'kept';
	}

	/**
	 * Hook the review screen (wp-admin only).
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_filter( 'display_post_states', array( __CLASS__, 'post_state' ), 10, 2 );
	}

	/**
	 * Handle the Undo and Keep buttons.
	 *
	 * @return void
	 */
	public static function handle() {
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

		check_admin_referer( self::ACTION . '_' . $post_id, '_wml_agent_nonce' );

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'mylink' !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to review this page.', 'wp-mylinks' ), '', array( 'response' => 403 ) );
		}

		$action  = isset( $_POST['wml_action'] ) ? sanitize_key( wp_unslash( $_POST['wml_action'] ) ) : '';
		$seen    = isset( $_POST['seen'] ) ? sanitize_text_field( wp_unslash( $_POST['seen'] ) ) : '';
		$pending = self::pending( $post_id );

		if ( 'undo' === $action && $pending && ! empty( $pending['created'] ) && ! current_user_can( 'delete_post', $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to move this page to the trash.', 'wp-mylinks' ), '', array( 'response' => 403 ) );
		}

		$result = 'none';
		if ( 'undo' === $action ) {
			$result = self::undo( $post_id, $seen );
		} elseif ( 'keep' === $action ) {
			$result = self::keep( $post_id, $seen );
		}

		$back = 'trash' === get_post_status( $post_id )
			? admin_url( 'edit.php?post_type=mylink' )
			: get_edit_post_link( $post_id, 'raw' );

		wp_safe_redirect( add_query_arg( self::ACTION, $result, $back ) );
		exit;
	}

	/**
	 * Show the review notice and the result of the last review action.
	 *
	 * @return void
	 */
	public static function notice() {
		$screen = get_current_screen();
		if ( ! $screen || 'mylink' !== $screen->post_type || ! in_array( $screen->base, array( 'post', 'edit' ), true ) ) {
			return;
		}

		self::render_result();

		$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen context.
		$pending = 'post' === $screen->base && $post_id ? self::pending( $post_id ) : null;

		if ( ! $pending || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$created  = ! empty( $pending['created'] );
		$can_undo = ! $created || current_user_can( 'delete_post', $post_id );
		$approved = ! empty( $pending['approved'] );
		$message  = $approved
			? sprintf(
				/* translators: 1: user name, 2: list of changed fields */
				__( 'Agent changes approved by %1$s: %2$s. You can still undo them.', 'wp-mylinks' ),
				self::names( $pending['writers'] ),
				self::labels( $pending['changed'] )
			)
			: sprintf(
				/* translators: 1: user name(s), 2: number of changes, 3: list of changed fields */
				_n(
					'An AI agent working as %1$s changed this page %2$d time since your last review: %3$s.',
					'An AI agent working as %1$s changed this page %2$d times since your last review: %3$s.',
					(int) $pending['writes'],
					'wp-mylinks'
				),
				self::names( $pending['writers'] ),
				(int) $pending['writes'],
				self::labels( $pending['changed'] )
			);
		?>
		<div class="notice <?php echo $approved ? 'notice-info' : 'notice-warning'; ?>">
			<p><?php echo esc_html( $message ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="post_id" value="<?php echo esc_attr( (string) $post_id ); ?>">
				<input type="hidden" name="seen" value="<?php echo esc_attr( self::seen( $pending ) ); ?>">
				<?php wp_nonce_field( self::ACTION . '_' . $post_id, '_wml_agent_nonce' ); ?>
				<p>
					<?php if ( $can_undo ) : ?>
						<button type="submit" class="button" name="wml_action" value="undo"><?php echo esc_html( $created ? __( 'Move to trash', 'wp-mylinks' ) : __( 'Undo agent changes', 'wp-mylinks' ) ); ?></button>
					<?php endif; ?>
					<button type="submit" class="button button-primary" name="wml_action" value="keep"><?php esc_html_e( 'Keep changes', 'wp-mylinks' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Mark pages with changes to review in the list table.
	 *
	 * @param array   $states Post states.
	 * @param WP_Post $post   Post.
	 * @return array
	 */
	public static function post_state( $states, $post ) {
		$pending = $post instanceof WP_Post && 'mylink' === $post->post_type ? self::pending( $post->ID ) : null;
		if ( $pending && empty( $pending['approved'] ) ) {
			$states['wp_mylinks_agent_review'] = __( 'Agent changes to review', 'wp-mylinks' );
		}
		return $states;
	}

	/**
	 * User names for a notice.
	 *
	 * @param int[] $user_ids User IDs.
	 * @return string
	 */
	public static function names( array $user_ids ) {
		$names = array();
		foreach ( $user_ids as $user_id ) {
			$user    = get_userdata( (int) $user_id );
			$names[] = $user ? $user->display_name : __( 'a removed user', 'wp-mylinks' );
		}
		return implode( ', ', $names );
	}

	/**
	 * Readable labels of changed agent keys.
	 *
	 * @param string[] $changed Agent keys.
	 * @return string
	 */
	public static function labels( array $changed ) {
		$fields = Wp_Mylinks_Agent_Fields::fields();
		$labels = array();

		foreach ( $changed as $key ) {
			if ( 'title' === $key ) {
				$labels[] = __( 'Title', 'wp-mylinks' );
			} elseif ( 'slug' === $key ) {
				$labels[] = __( 'Slug', 'wp-mylinks' );
			} elseif ( ! empty( $fields[ $key ]['name'] ) ) {
				$labels[] = wp_strip_all_tags( (string) $fields[ $key ]['name'] );
			} elseif ( ! empty( $fields[ $key ]['box_title'] ) ) {
				$labels[] = wp_strip_all_tags( (string) $fields[ $key ]['box_title'] );
			} else {
				$labels[] = (string) $key;
			}
		}

		return implode( ', ', $labels );
	}

	/**
	 * Result of the last Undo or Keep.
	 *
	 * @return void
	 */
	private static function render_result() {
		$result = isset( $_GET[ self::ACTION ] ) ? sanitize_key( wp_unslash( $_GET[ self::ACTION ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only, from a fixed list.

		$messages = array(
			'undone'  => array( 'success', __( 'Agent changes undone.', 'wp-mylinks' ) ),
			'kept'    => array( 'success', __( 'Agent changes kept.', 'wp-mylinks' ) ),
			'stale'   => array( 'error', __( 'Not undone: this page changed after the agent\'s last edit, and undoing would overwrite that work.', 'wp-mylinks' ) ),
			'changed' => array( 'warning', __( 'Nothing was done: the agent made more changes after this screen loaded. Review them below.', 'wp-mylinks' ) ),
			'none'    => array( 'info', __( 'There are no agent changes to review on this page.', 'wp-mylinks' ) ),
			'error'   => array( 'error', __( 'The page could not be restored.', 'wp-mylinks' ) ),
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
