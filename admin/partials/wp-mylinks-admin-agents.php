<?php
/**
 * The "AI agents" settings tab.
 *
 * Two choices the site owner makes (how much agents may do, and whether their
 * changes to published pages wait for approval), each with its risk and its
 * advantage spelled out, and a checklist of what an agent needs to connect.
 *
 * @package    Wp_Mylinks
 * @subpackage Wp_Mylinks/admin/partials
 * @since      1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the two settings in their own group.
 *
 * @return void
 */
function wp_mylinks_agents_register_settings() {
	register_setting(
		'mylinks-agents',
		Wp_Mylinks_Agent_Access::OPTION,
		array(
			'type'              => 'string',
			'default'           => 'off',
			'sanitize_callback' => 'wp_mylinks_agents_sanitize_access',
		)
	);
	register_setting(
		'mylinks-agents',
		Wp_Mylinks_Agent_Approval::OPTION,
		array(
			'type'              => 'string',
			'default'           => 'approve',
			'sanitize_callback' => 'wp_mylinks_agents_sanitize_publish',
		)
	);
}
add_action( 'admin_init', 'wp_mylinks_agents_register_settings' );

/**
 * Only administrators may change what agents can do.
 *
 * @return string
 */
function wp_mylinks_agents_settings_capability() {
	return 'manage_options';
}
add_filter( 'option_page_capability_mylinks-agents', 'wp_mylinks_agents_settings_capability' );

/**
 * Sanitize the access level. Anything unknown is off, and a user who cannot
 * manage options cannot change it.
 *
 * @param mixed $value Submitted value.
 * @return string
 */
function wp_mylinks_agents_sanitize_access( $value ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return Wp_Mylinks_Agent_Access::stored();
	}
	return is_string( $value ) && isset( Wp_Mylinks_Agent_Access::LEVELS[ $value ] ) ? $value : 'off';
}

/**
 * Sanitize the publish mode. Anything unknown is approve, and a user who
 * cannot manage options cannot change it.
 *
 * @param mixed $value Submitted value.
 * @return string
 */
function wp_mylinks_agents_sanitize_publish( $value ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return Wp_Mylinks_Agent_Approval::mode();
	}
	return 'live' === $value ? 'live' : 'approve';
}

/**
 * Has some copy of the MCP Adapter been started on this request? Calling its
 * instance() would start it, so its hooks are looked at instead.
 *
 * @return bool
 */
function wp_mylinks_agents_adapter_started() {
	global $wp_filter;

	foreach ( array( 'wp_abilities_api_init', 'rest_api_init', 'init' ) as $hook ) {
		if ( ! isset( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof WP_Hook ) {
			continue;
		}
		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof \WP\MCP\Core\McpAdapter ) {
					return true;
				}
			}
		}
	}

	return false;
}

/**
 * Facts the checklist is built from.
 *
 * @return array
 */
function wp_mylinks_agents_environment() {
	$plugin  = did_action( 'wp_mcp_init' ) > 0;
	$present = $plugin || class_exists( 'WP\MCP\Core\McpAdapter' );

	return array(
		'wp_version'    => (string) get_bloginfo( 'version' ),
		'supported'     => Wp_Mylinks_Agent_Access::supported(),
		'stored'        => Wp_Mylinks_Agent_Access::stored(),
		'access'        => Wp_Mylinks_Agent_Access::level(),
		'publish'       => Wp_Mylinks_Agent_Approval::mode(),
		'adapter'       => array(
			'present'        => $present,
			'plugin'         => $plugin,
			'running'        => $present && wp_mylinks_agents_adapter_started(),
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The MCP Adapter's own filter, read here and not defined.
			'default_server' => (bool) apply_filters( 'mcp_adapter_create_default_server', true ),
		),
		'app_passwords' => wp_is_application_passwords_available(),
		'multisite'     => is_multisite(),
	);
}

/**
 * The connect checklist.
 *
 * @param array $env Facts from wp_mylinks_agents_environment().
 * @return array[] Items of {done, title, detail[], link?}.
 */
function wp_mylinks_agents_checklist( array $env ) {
	$items = array();

	$items[] = $env['supported']
		? array(
			'done'   => true,
			'title'  => __( 'WordPress supports agents', 'wp-mylinks' ),
			/* translators: %s: WordPress version number */
			'detail' => array( sprintf( __( 'WordPress %s includes abilities, the way plugins offer their tools to AI agents.', 'wp-mylinks' ), $env['wp_version'] ) ),
		)
		: array(
			'done'   => false,
			'title'  => __( 'Update WordPress', 'wp-mylinks' ),
			/* translators: %s: WordPress version number */
			'detail' => array( sprintf( __( 'Agents need WordPress 6.9 or newer. This site runs WordPress %s.', 'wp-mylinks' ), $env['wp_version'] ) ),
		);

	$names   = array(
		'off'   => __( 'Off', 'wp-mylinks' ),
		'read'  => __( 'Read only', 'wp-mylinks' ),
		'write' => __( 'Read and edit', 'wp-mylinks' ),
	);
	$lowered = $env['stored'] !== $env['access']
		? array(
			sprintf(
				/* translators: 1: level chosen in the setting, 2: lower level agents get */
				__( 'Code on this site lowers agent access: the setting says %1$s, but agents get %2$s. It comes from the wp_mylinks_agent_access_level filter, so changing the setting cannot raise it.', 'wp-mylinks' ),
				$names[ $env['stored'] ],
				$names[ $env['access'] ]
			),
		)
		: array();

	if ( 'write' === $env['access'] ) {
		$items[] = array(
			'done'   => true,
			'title'  => __( 'Agent access is on: read and edit', 'wp-mylinks' ),
			'detail' => array(
				'live' === $env['publish']
					? __( 'Agents can read, create and change pages. Their changes to published pages are live at once.', 'wp-mylinks' )
					: __( 'Agents can read, create and change pages. Their changes to published pages wait for your approval.', 'wp-mylinks' ),
			),
		);
	} elseif ( 'read' === $env['access'] ) {
		$items[] = array(
			'done'   => true,
			'title'  => __( 'Agent access is on: read only', 'wp-mylinks' ),
			'detail' => array_merge( array( __( 'Agents can read your pages but cannot change them.', 'wp-mylinks' ) ), $lowered ),
		);
	} elseif ( $lowered ) {
		$items[] = array(
			'done'   => false,
			'title'  => __( 'Agent access is held off by code', 'wp-mylinks' ),
			'detail' => $lowered,
		);
	} else {
		$items[] = array(
			'done'   => false,
			'title'  => __( 'Turn on agent access', 'wp-mylinks' ),
			'detail' => array( __( 'Choose Read only or Read and edit under Agent access, then save.', 'wp-mylinks' ) ),
		);
	}

	$adapter    = $env['adapter'];
	$server_off = __( 'Another plugin has switched off the adapter\'s default server, which is the one agents use to reach MyLinks. The setup guide shows how to turn it back on.', 'wp-mylinks' );

	if ( ! $adapter['present'] ) {
		$items[] = array(
			'done'   => false,
			'title'  => __( 'Install the MCP Adapter plugin', 'wp-mylinks' ),
			'detail' => array( __( 'This free plugin from the WordPress AI team is what connects AI agents to WordPress. The setup guide shows where to get it.', 'wp-mylinks' ) ),
		);
	} elseif ( ! $adapter['running'] ) {
		$items[] = array(
			'done'   => false,
			'title'  => __( 'Install the MCP Adapter plugin', 'wp-mylinks' ),
			'detail' => array_merge(
				array( __( 'Another plugin on this site includes its own copy of the MCP Adapter but does not start it, so agents cannot connect through it.', 'wp-mylinks' ) ),
				$adapter['default_server'] ? array() : array( $server_off )
			),
		);
	} elseif ( ! $adapter['default_server'] ) {
		$items[] = array(
			'done'   => false,
			'title'  => __( 'Turn the adapter\'s default server back on', 'wp-mylinks' ),
			'detail' => array( $server_off ),
		);
	} elseif ( $adapter['plugin'] ) {
		$items[] = array(
			'done'   => true,
			'title'  => __( 'The MCP Adapter is active', 'wp-mylinks' ),
			'detail' => array( __( 'Agents connect to WordPress through it.', 'wp-mylinks' ) ),
		);
	} else {
		$items[] = array(
			'done'   => true,
			'title'  => __( 'The MCP Adapter is running inside another plugin', 'wp-mylinks' ),
			'detail' => array( __( 'Agents can reach MyLinks now. Installing the MCP Adapter plugin itself keeps that working if the other plugin changes or is removed.', 'wp-mylinks' ) ),
		);
	}

	if ( $env['app_passwords'] ) {
		$detail = array(
			__( 'Agents sign in with an application password.', 'wp-mylinks' ),
			__( 'Give agents their own user with the lowest role that does the job, rather than your administrator account, and create the password on that user\'s profile. MyLinks pages need a role that can edit pages, such as Editor. You can then see what an agent changed, and revoke its password without touching your own login.', 'wp-mylinks' ),
		);
		if ( $env['multisite'] ) {
			$detail[] = __( 'On a multisite network, an application password works on every site its user belongs to, so add the agent\'s user to this site only.', 'wp-mylinks' );
		}
		$items[] = array(
			'done'   => true,
			'title'  => __( 'Application passwords are available', 'wp-mylinks' ),
			'detail' => $detail,
			'link'   => array( admin_url( 'profile.php' ) . '#application-passwords-section', __( 'Application passwords on your profile', 'wp-mylinks' ) ),
		);
	} else {
		$items[] = array(
			'done'   => false,
			'title'  => __( 'Turn on application passwords', 'wp-mylinks' ),
			'detail' => array( __( 'Agents sign in with an application password, and this site does not offer them. WordPress only offers them on sites served over HTTPS, and some security plugins and hosts switch them off.', 'wp-mylinks' ) ),
		);
	}

	return $items;
}

/**
 * One radio choice with its explanation.
 *
 * @param string $option  Option name.
 * @param string $value   Choice value.
 * @param string $current Current value.
 * @param string $title   Choice title.
 * @param string $desc    What it means.
 * @return void
 */
function wp_mylinks_agents_choice( $option, $value, $current, $title, $desc ) {
	?>
	<label class="wml-choice">
		<input type="radio" name="<?php echo esc_attr( $option ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $current, $value ); ?>>
		<span class="wml-choice__text">
			<strong><?php echo esc_html( $title ); ?></strong>
			<small><?php echo esc_html( $desc ); ?></small>
		</span>
	</label>
	<?php
}

/**
 * Render the tab.
 *
 * @return void
 */
function wp_mylinks_render_agents_tab() {
	$stored  = Wp_Mylinks_Agent_Access::stored();
	$publish = Wp_Mylinks_Agent_Approval::mode();
	?>
	<form method="post" action="options.php">
		<?php
		settings_errors();
		settings_fields( 'mylinks-agents' );

		echo '<div class="wml-settings-grid wml-settings-grid--2col">';

		wp_mylinks_card_open(
			'rest-api',
			__( 'Agent access', 'wp-mylinks' ),
			__( 'How much AI agents may do on this site. An agent acts as the WordPress user whose application password it holds, and never gets more than that user can do.', 'wp-mylinks' )
		);
		wp_mylinks_agents_choice(
			Wp_Mylinks_Agent_Access::OPTION,
			'off',
			$stored,
			__( 'Off', 'wp-mylinks' ),
			__( 'Agents cannot reach MyLinks. This is the default.', 'wp-mylinks' )
		);
		wp_mylinks_agents_choice(
			Wp_Mylinks_Agent_Access::OPTION,
			'read',
			$stored,
			__( 'Read only', 'wp-mylinks' ),
			__( 'Agents can list your pages and read their content, view and click counts, and site defaults. They cannot change anything.', 'wp-mylinks' )
		);
		wp_mylinks_agents_choice(
			Wp_Mylinks_Agent_Access::OPTION,
			'write',
			$stored,
			__( 'Read and edit', 'wp-mylinks' ),
			__( 'Agents can also create pages and change their profile, links, social icons, appearance and SEO. HTML is always cleaned and custom scripts stay out of reach. MyLinks tools for agents never delete or upload, but an agent whose account may publish can still change a page\'s status through WordPress itself, so give it an account with the lowest role that does the job.', 'wp-mylinks' )
		);
		echo '<p class="wml-field__help">' . esc_html__( 'Site-wide settings stay read only: an agent can recommend changes, and you apply them on the General tab.', 'wp-mylinks' ) . '</p>';
		wp_mylinks_card_close();

		wp_mylinks_card_open(
			'visibility',
			__( 'Agent changes to published pages', 'wp-mylinks' ),
			__( 'What happens when an agent edits a page visitors can see. Applies only when access is Read and edit.', 'wp-mylinks' )
		);
		wp_mylinks_agents_choice(
			Wp_Mylinks_Agent_Approval::OPTION,
			'approve',
			$publish,
			__( 'Wait for my approval (default)', 'wp-mylinks' ),
			__( 'Visitors keep seeing the published page. Its edit screen shows what the agent proposes, with a preview, to approve or discard. New pages stay drafts until you publish them. Safer, but nothing changes until you look.', 'wp-mylinks' )
		);
		wp_mylinks_agents_choice(
			Wp_Mylinks_Agent_Approval::OPTION,
			'live',
			$publish,
			__( 'Publish directly, with undo', 'wp-mylinks' ),
			__( 'An agent\'s change reaches visitors as soon as it is made, and an agent may publish new pages. Each page\'s edit screen then offers Undo. Faster, but a wrong or manipulated change is public until you undo it.', 'wp-mylinks' )
		);
		echo '<p class="wml-field__help">' . esc_html__( 'Text an agent reads can try to steer it. Whichever you choose, keep the agent\'s account limited and review what it changes.', 'wp-mylinks' ) . '</p>';
		wp_mylinks_card_close();

		echo '</div>';

		wp_mylinks_save_bar();
		?>
	</form>

	<div class="wml-settings-grid">
		<?php
		wp_mylinks_card_open(
			'admin-links',
			__( 'Connect an AI agent', 'wp-mylinks' ),
			__( 'What an agent needs before it can work on your pages, and whether this site has it.', 'wp-mylinks' ),
			true
		);
		?>
		<ul class="wml-agent-checklist">
			<?php foreach ( wp_mylinks_agents_checklist( wp_mylinks_agents_environment() ) as $item ) : ?>
				<li class="<?php echo $item['done'] ? 'wml-agent-checklist__done' : 'wml-agent-checklist__todo'; ?>">
					<span class="dashicons <?php echo $item['done'] ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>" aria-hidden="true"></span>
					<div>
						<strong>
							<span class="screen-reader-text"><?php echo $item['done'] ? esc_html__( 'Ready:', 'wp-mylinks' ) : esc_html__( 'To do:', 'wp-mylinks' ); ?></span>
							<?php echo esc_html( $item['title'] ); ?>
						</strong>
						<?php foreach ( $item['detail'] as $line ) : ?>
							<p><?php echo esc_html( $line ); ?></p>
						<?php endforeach; ?>
						<?php if ( ! empty( $item['link'] ) ) : ?>
							<p><a href="<?php echo esc_url( $item['link'][0] ); ?>"><?php echo esc_html( $item['link'][1] ); ?></a></p>
						<?php endif; ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
		<p>
			<a class="wml-button-secondary" href="<?php echo esc_url( wp_mylinks_url( 'https://www.onlinestorekit.com/docs/wml/agents/connect/' ) ); ?>" target="_blank" rel="noopener noreferrer">
				<span class="dashicons dashicons-external" aria-hidden="true"></span>
				<?php esc_html_e( 'Read the setup guide', 'wp-mylinks' ); ?>
				<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'wp-mylinks' ); ?></span>
			</a>
		</p>
		<?php wp_mylinks_card_close(); ?>
	</div>
	<?php
}
