<?php
/**
 * Plugin Name:       SLNWP Role Extension for Paid Memberships Pro
 * Plugin URI:        https://github.com/soulsong-uk/slnwp-role-extension-pmpro
 * Description:       Manages WordPress roles in sync with Paid Memberships Pro membership levels. Replaces the official PMPro Roles add-on which had critical bugs around role assignment, removal, and administrator role protection.
 * Version:           2.1.9
 * Author:            James Harvey
 * Author URI:        https://soulsong.co.uk
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       slnwp-role-extension-pmpro
 * Requires at least: 6.5
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HOW IT WORKS
 *
 * Each PMPro membership level has a "WordPress Role" and an optional "Interim
 * Role". Multiple levels can share the same role slug (e.g. Gold Monthly,
 * Gold Quarterly → "gold").
 *
 * TWO-STAGE ROLE MODEL (per level, evaluated per member):
 * - Stage 1 — while the member's subscription billing start date is still in
 *   the future (Subscription Delays add-on), the member holds the level's
 *   INTERIM role.
 * - Stage 2 — once that start date has passed, the interim role is dropped and
 *   the level's MAIN role is assigned instead.
 * The start date is resolved per member: a fixed calendar date is read
 *   straight from the level; a relative "N days" delay is added to that
 *   member's own membership start date. A member with several levels (Multiple
 *   Memberships Per User) can be at stage 1 for one level and stage 2 for
 *   another simultaneously — every active level is evaluated on every sync.
 *
 * PROTECTED ROLES — never removed under any circumstances:
 *   administrator, editor, author, contributor, customer, subscriber
 * Protection only blocks removal, not addition — a non-member is still
 * assigned the site default role (usually subscriber) as normal; once held,
 * it (like every protected role) just can't be stripped again afterwards.
 * Extend via: add_filter( 'pmpro_protected_roles', function( $roles ) { ... } )
 *
 * TECHNICAL NOTES
 * - Every sync computes the complete desired role set across all active
 *   levels, then diffs it against the user's current roles and applies only
 *   the difference (add missing, remove stale) — so an interrupted or
 *   re-entrant run can never leave the member with no membership role.
 * - Re-entrancy guarded: role writes can trigger third-party handlers that
 *   call back into pmpro_changeMembershipLevel(); pmpro_rm_apply_roles() is a
 *   no-op while already running for the same user.
 * - Uses new WP_User( $id ) + cache flush on every role operation to bypass
 *   WordPress object caching and ensure DB persistence.
 * - pmpro_after_change_membership_level fires for signup, level change,
 *   cancellation, and expiry. level_id = 0 means cancellation or expiry.
 * - A daily cron sweep (pmpro_rm_daily_role_sweep) performs the stage 1 → 2
 *   transition for members who have not logged in since their start date.
 * - pmpro_save_membership_level passes level ID as integer (not boolean).
 */


// ============================================================================
// TIER
// ============================================================================


/**
 * Build-time tier flag: 'pro', 'free' or 'wporg', set by build.bat.
 */
define( 'PMPRO_RM_TIER', 'wporg' );


/**
 * Whether Pro features are unlocked (never in the free and wporg builds).
 *
 * @return bool
 */
function pmpro_rm_is_pro() {
	if ( 'pro' !== PMPRO_RM_TIER ) {
		return false;
	}
	return true;
}


// ============================================================================
// INIT
// ============================================================================

add_action( 'plugins_loaded', 'pmpro_rm_init' );
register_activation_hook( __FILE__, 'pmpro_rm_activate' );
register_deactivation_hook( __FILE__, 'pmpro_rm_deactivate' );

/**
 * Boot the plugin once PMPro has loaded.
 */
function pmpro_rm_init() {
	if ( ! function_exists( 'pmpro_getMembershipLevelsForUser' ) ) {
		add_action( 'admin_notices', 'pmpro_rm_notice_missing_pmpro' );
		return;
	}

	if ( function_exists( 'pmpro_roles_after_change_membership_level' ) ) {
		add_action( 'admin_notices', 'pmpro_rm_notice_conflict' );
	}
	add_action( 'admin_notices', 'pmpro_rm_notice_protected_level_roles' );


	add_action( 'pmpro_membership_level_after_other_settings', 'pmpro_rm_add_role_slug_field' );
	add_action( 'pmpro_save_membership_level',                  'pmpro_rm_save_role_slug_field' );
	add_action( 'pmpro_after_change_membership_level',          'pmpro_rm_sync_role_on_level_change', 10, 2 );
	add_action( 'admin_menu',                                   'pmpro_rm_add_admin_menu' );
	add_action( 'admin_enqueue_scripts',                        'pmpro_rm_enqueue_admin_assets' );
	add_action( 'admin_init',                                   'pmpro_rm_handle_sync' );
	add_action( 'wp_login',                                     'pmpro_rm_maybe_assign_role_on_login', 10, 2 );
}

/**
 * Schedule the daily role sweep on activation.
 */
function pmpro_rm_activate() {
}

/**
 * Clear the scheduled sweep on deactivation.
 */
function pmpro_rm_deactivate() {
}

/**
 * Whether this plugin's admin notices should show on the current screen:
 * only to admins, and only on the Plugins screen and PMPro's own admin pages
 * (the Memberships menu, which includes Role Sync), plus the Dashboard when
 * $on_dashboard is true. Keeps the warnings out of every other admin screen
 * (WordPress.org Guideline 11).
 *
 * @param bool $on_dashboard Also show on the Dashboard.
 * @return bool
 */
function pmpro_rm_notice_in_scope( $on_dashboard = false ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return false;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( $screen && ( 'plugins' === $screen->id || ( $on_dashboard && 'dashboard' === $screen->id ) ) ) {
		return true;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check, no state change.
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	return 0 === strpos( $page, 'pmpro-' );
}

/** Admin notice: PMPro not active. */
function pmpro_rm_notice_missing_pmpro() {
	if ( ! pmpro_rm_notice_in_scope( true ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>';
	echo '<strong>' . esc_html__( 'SLNWP Role Extension for Paid Memberships Pro', 'slnwp-role-extension-pmpro' ) . '</strong> ';
	esc_html_e( 'requires Paid Memberships Pro to be installed and activated.', 'slnwp-role-extension-pmpro' );
	echo '</p></div>';
}

/** Admin notice: official PMPro Roles add-on also active. */
function pmpro_rm_notice_conflict() {
	if ( ! pmpro_rm_notice_in_scope() ) {
		return;
	}
	echo '<div class="notice notice-warning"><p>';
	echo '<strong>' . esc_html__( 'SLNWP Role Extension for Paid Memberships Pro', 'slnwp-role-extension-pmpro' ) . '</strong>: ';
	esc_html_e( 'The official PMPro Roles add-on is currently active. Please deactivate it to avoid conflicts with this plugin.', 'slnwp-role-extension-pmpro' );
	echo '</p></div>';
}

/**
 * Admin notice: a level uses a protected role as its WordPress Role or Interim
 * Role. This plugin never removes protected roles, so members of that level
 * keep the role after their membership is cancelled or expires. The level
 * editor no longer offers protected roles, so this only flags levels saved
 * before that, or a role a site has since added via pmpro_protected_roles.
 */
function pmpro_rm_notice_protected_level_roles() {
	if ( ! pmpro_rm_notice_in_scope() ) {
		return;
	}
	$names = pmpro_rm_get_protected_role_level_names();
	if ( empty( $names ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p>';
	echo '<strong>' . esc_html__( 'SLNWP Role Extension for Paid Memberships Pro', 'slnwp-role-extension-pmpro' ) . '</strong>: ';
	printf(
		/* translators: %s: comma-separated list of membership level names whose WordPress Role or Interim Role is a protected role. */
		esc_html__( 'These membership levels use a protected role as their WordPress Role or Interim Role: %s. This plugin never removes protected roles, so members keep that role after their membership is cancelled or expires. Edit these levels and choose a different role.', 'slnwp-role-extension-pmpro' ),
		esc_html( implode( ', ', $names ) )
	);
	echo '</p></div>';
}

/**
 * Names of every membership level whose WordPress Role or Interim Role is a
 * protected role.
 *
 * @return string[]
 */
function pmpro_rm_get_protected_role_level_names() {
	$levels    = function_exists( 'pmpro_getAllLevels' ) ? pmpro_getAllLevels( true, true ) : array();
	$levels    = is_array( $levels ) ? $levels : array();
	$protected = pmpro_rm_get_protected_roles();
	$names     = array();
	foreach ( $levels as $level ) {
		$main    = (string) get_option( 'pmpro_level_role_' . (int) $level->id, '' );
		$interim = (string) get_option( 'pmpro_level_interim_role_' . (int) $level->id, '' );
		if ( in_array( $main, $protected, true ) || in_array( $interim, $protected, true ) ) {
			$names[] = $level->name;
		}
	}
	return $names;
}



// ============================================================================
// ADMIN MENU & SYNC PAGE
// ============================================================================

/**
 * The plugin version, read once from the header (used for asset cache-busting
 * so it moves in step with /plugin-bump and no separate constant is needed).
 *
 * @return string
 */
function pmpro_rm_version() {
	static $version = null;
	if ( null === $version ) {
		$data    = get_file_data( __FILE__, array( 'Version' => 'Version' ) );
		$version = empty( $data['Version'] ) ? '1.0.0' : $data['Version'];
	}
	return $version;
}

/**
 * Get / set the Role Sync page's admin hook suffix (so the enqueue callback
 * can match it exactly).
 *
 * @param string|null $set
 * @return string
 */
function pmpro_rm_admin_page_hook( $set = null ) {
	static $hook = '';
	if ( null !== $set ) {
		$hook = (string) $set;
	}
	return $hook;
}

/**
 * Add Role Sync page under Memberships menu.
 */
function pmpro_rm_add_admin_menu() {
	$hook = add_submenu_page(
		'pmpro-dashboard',
		__( 'SLNWP Role Sync', 'slnwp-role-extension-pmpro' ),
		__( 'SLNWP Role Sync', 'slnwp-role-extension-pmpro' ),
		'manage_options',
		'pmpro-role-sync',
		'pmpro_rm_sync_page'
	);
	pmpro_rm_admin_page_hook( $hook );

}


/**
 * Load the admin stylesheet on the Role Sync page and the PMPro level editor
 * (where this plugin injects the role fields), nowhere else.
 *
 * @param string $hook Current admin page hook suffix.
 */
function pmpro_rm_enqueue_admin_assets( $hook ) {
	$on_our_page = ( '' !== pmpro_rm_admin_page_hook() && $hook === pmpro_rm_admin_page_hook() );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check, no state change.
	$page        = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	$on_level_ed = ( 'pmpro-membershiplevels' === $page || 'pmpro-role-sync-licence' === $page );

	if ( ! $on_our_page && ! $on_level_ed ) {
		return;
	}

	wp_enqueue_style(
		'pmpro-rm-admin',
		plugins_url( 'assets/css/admin.css', __FILE__ ),
		array(),
		pmpro_rm_version()
	);
}

/**
 * Render the Role Sync admin page.
 */
function pmpro_rm_sync_page() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- view-state flags only; the batch step behind each one re-verifies its own nonce before doing any work.
	if ( isset( $_GET['pmpro_rm_direct_progress'] ) ) {
		pmpro_rm_render_direct_progress();
		return;
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	// count_users() is expensive on large installs — cache it briefly.
	$total_users = get_transient( 'pmpro_rm_total_users' );
	if ( false === $total_users ) {
		$counts      = count_users();
		$total_users = isset( $counts['total_users'] ) ? (int) $counts['total_users'] : 0;
		set_transient( 'pmpro_rm_total_users', $total_users, 10 * MINUTE_IN_SECONDS );
	}

	$all_levels   = function_exists( 'pmpro_getAllLevels' ) ? pmpro_getAllLevels( true, true ) : array();
	$all_levels   = is_array( $all_levels ) ? $all_levels : array();
	$total_levels = count( $all_levels );

	// Configured levels: rebuilt from the options API (no direct SQL, and it
	// stays consistent with the alloptions cache the rest of the plugin reads).
	$configured = array();
	foreach ( $all_levels as $level ) {
		$main    = get_option( 'pmpro_level_role_' . (int) $level->id, '' );
		$interim = get_option( 'pmpro_level_interim_role_' . (int) $level->id, '' );
		if ( '' === $main && '' === $interim ) {
			continue;
		}
		$row = array(
			'level_id'     => (int) $level->id,
			'level_name'   => $level->name,
			'main_role'    => $main,
			'interim_role' => $interim,
			'start_date'   => '',
		);
		$configured[] = (object) $row;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display of counts from our own post-sync redirect.
	$show_synced = isset( $_GET['synced'] );
	$n_updated   = isset( $_GET['updated'] ) ? absint( wp_unslash( $_GET['updated'] ) ) : 0;
	$n_cleared   = isset( $_GET['cleared'] ) ? absint( wp_unslash( $_GET['cleared'] ) ) : 0;
	$n_skipped   = isset( $_GET['skipped'] ) ? absint( wp_unslash( $_GET['skipped'] ) ) : 0;
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	$tabs = array(
		'about' => __( 'About', 'slnwp-role-extension-pmpro' ),
		'sync'  => __( 'Sync', 'slnwp-role-extension-pmpro' ),
		// Key stays 'misc' so existing ?tab=misc links keep working.
		'misc'  => __( 'Developers', 'slnwp-role-extension-pmpro' ),
	);

	// Read-only tab navigation, not a state-changing action - no nonce needed
	// per WordPress's own security guidance (nonces protect POST-style
	// mutations, not GET page views).
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'about';
	if ( ! array_key_exists( $active_tab, $tabs ) ) {
		$active_tab = 'about';
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'SLNWP Role Sync', 'slnwp-role-extension-pmpro' ); ?></h1>


		<?php if ( $show_synced ) : ?>
			<div class="notice notice-success is-dismissible">
				<p>
					<?php
					printf(
						/* translators: 1: number of members whose membership roles were synced, 2: number of members reset to the default role, 3: number of members skipped because they hold a protected role. */
						esc_html__( 'Sync complete — %1$s members synced, %2$s reset to the default role, %3$s skipped.', 'slnwp-role-extension-pmpro' ),
						esc_html( number_format_i18n( $n_updated ) ),
						esc_html( number_format_i18n( $n_cleared ) ),
						esc_html( number_format_i18n( $n_skipped ) )
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<h2 class="nav-tab-wrapper">
			<?php foreach ( $tabs as $tab_key => $tab_label ) : ?>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'pmpro-role-sync', 'tab' => $tab_key ), admin_url( 'admin.php' ) ) ); ?>" class="nav-tab<?php echo ( $active_tab === $tab_key ) ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( $tab_label ); ?></a>
			<?php endforeach; ?>
		</h2>

		<?php if ( 'about' === $active_tab ) : ?>
			<div class="pmpro-rm-columns">

				<div class="pmpro-rm-col-left">

					<div class="pmpro-rm-card">
						<h2><?php esc_html_e( 'How It Works', 'slnwp-role-extension-pmpro' ); ?></h2>
						<p><?php esc_html_e( 'Roles are kept in sync automatically when:', 'slnwp-role-extension-pmpro' ); ?></p>
						<ul class="pmpro-rm-bullets">
							<li><?php esc_html_e( 'A member signs up, upgrades, downgrades, cancels, or expires', 'slnwp-role-extension-pmpro' ); ?></li>
							<li><?php esc_html_e( 'A member logs in (catches a transition a gateway webhook missed)', 'slnwp-role-extension-pmpro' ); ?></li>
						</ul>
					</div>

				</div>

				<div class="pmpro-rm-col-right">

					<div class="pmpro-rm-card">
						<h2><?php esc_html_e( 'Current Configuration', 'slnwp-role-extension-pmpro' ); ?></h2>
						<p>
							<?php
							/* translators: %s: total number of membership levels. */
							printf( esc_html__( '%s membership levels total', 'slnwp-role-extension-pmpro' ), esc_html( number_format_i18n( $total_levels ) ) );
							?>
						</p>
						<?php if ( ! empty( $configured ) ) : ?>
							<?php
							// One grid column per column this build shows (see .pmpro-rm-cols-N in admin.css).
							$config_cols = 3;
							?>
							<div class="pmpro-rm-list pmpro-rm-config-list pmpro-rm-cols-<?php echo (int) $config_cols; ?>">
								<div class="pmpro-rm-list-header">
									<div class="pmpro-rm-col pmpro-rm-col-id"><?php esc_html_e( 'Level ID', 'slnwp-role-extension-pmpro' ); ?></div>
									<div class="pmpro-rm-col pmpro-rm-col-name"><?php esc_html_e( 'Level Name', 'slnwp-role-extension-pmpro' ); ?></div>
									<div class="pmpro-rm-col pmpro-rm-col-main"><?php esc_html_e( 'Main Role', 'slnwp-role-extension-pmpro' ); ?></div>
								</div>
								<?php
								$alt = false;
								foreach ( $configured as $row ) :
									$alt = ! $alt;
									?>
									<div class="pmpro-rm-list-row<?php echo $alt ? ' pmpro-rm-row-alt' : ''; ?>">
										<div class="pmpro-rm-col pmpro-rm-col-id" data-label="<?php esc_attr_e( 'Level ID', 'slnwp-role-extension-pmpro' ); ?>"><?php echo esc_html( $row->level_id ); ?></div>
										<div class="pmpro-rm-col pmpro-rm-col-name" data-label="<?php esc_attr_e( 'Level Name', 'slnwp-role-extension-pmpro' ); ?>"><?php echo esc_html( $row->level_name ); ?></div>
										<div class="pmpro-rm-col pmpro-rm-col-main" data-label="<?php esc_attr_e( 'Main Role', 'slnwp-role-extension-pmpro' ); ?>"><?php pmpro_rm_render_role_cell( $row->main_role ); ?></div>
									</div>
								<?php endforeach; ?>
							</div>
						<?php else : ?>
							<p class="pmpro-rm-empty">
								<?php esc_html_e( 'No roles configured yet. Edit your membership levels to set a role.', 'slnwp-role-extension-pmpro' ); ?>
							</p>
						<?php endif; ?>
						<p class="pmpro-rm-actions">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=pmpro-membershiplevels' ) ); ?>" class="button">
								<?php esc_html_e( 'Edit Membership Levels', 'slnwp-role-extension-pmpro' ); ?>
							</a>
						</p>
					</div>

				</div>

			</div>
		<?php endif; ?>

		<?php if ( 'sync' === $active_tab ) : ?>
			<div class="pmpro-rm-columns">

				<div class="pmpro-rm-col-wide">

					<div class="pmpro-rm-card">
						<h2><?php esc_html_e( 'Bulk Role Sync', 'slnwp-role-extension-pmpro' ); ?></h2>
						<p><?php esc_html_e( 'Bring all existing users into sync with their current membership levels. Safe to run at any time; large sites are processed in batches.', 'slnwp-role-extension-pmpro' ); ?></p>
						<ul class="pmpro-rm-bullets">
							<li><?php esc_html_e( 'Removes stale membership roles that are still configured on a level (a role removed from every level entirely is left alone)', 'slnwp-role-extension-pmpro' ); ?></li>
							<?php if ( 'pro' !== PMPRO_RM_TIER ) : ?>
								<li><?php esc_html_e( 'Assigns the configured role for each active level', 'slnwp-role-extension-pmpro' ); ?></li>
							<?php endif; ?>
							<li><?php esc_html_e( 'Resets non-members to the default role', 'slnwp-role-extension-pmpro' ); ?></li>
							<li><?php esc_html_e( 'Never removes protected roles (administrator, editor, etc.)', 'slnwp-role-extension-pmpro' ); ?></li>
						</ul>
						<p class="pmpro-rm-count">
							<?php
							/* translators: %s: total number of users on the site. */
							printf( esc_html__( '%s total users', 'slnwp-role-extension-pmpro' ), esc_html( number_format_i18n( $total_users ) ) );
							?>
						</p>
						<?php if ( ! pmpro_rm_is_pro() ) : ?>
						<p class="description">
							<?php esc_html_e( 'Applies immediately, processed in batches so large sites never hit a request time limit.', 'slnwp-role-extension-pmpro' ); ?>
						</p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=pmpro-role-sync' ) ); ?>">
							<?php wp_nonce_field( 'pmpro_rm_sync', 'pmpro_rm_nonce' ); ?>
							<input type="hidden" name="pmpro_rm_do_direct_sync" value="1" />
							<?php
							submit_button(
								__( 'Run Sync Now', 'slnwp-role-extension-pmpro' ),
								'primary large',
								'submit',
								false
							);
							?>
						</form>
						<?php endif; ?>
					</div>

				</div>

				<div class="pmpro-rm-col-side">

					<div class="pmpro-rm-card">
						<h2><?php esc_html_e( 'When to Run a Sync', 'slnwp-role-extension-pmpro' ); ?></h2>
						<p><?php esc_html_e( 'Roles normally stay in sync on their own (see How It Works on the About tab). Run a sync to catch up anyone who fell outside that:', 'slnwp-role-extension-pmpro' ); ?></p>
						<ul class="pmpro-rm-bullets">
							<li><?php esc_html_e( 'Right after installing this plugin on a site that already has members', 'slnwp-role-extension-pmpro' ); ?></li>
							<?php if ( 'pro' !== PMPRO_RM_TIER ) : ?>
								<li><?php esc_html_e( 'After changing a level\'s WordPress Role', 'slnwp-role-extension-pmpro' ); ?></li>
							<?php endif; ?>
							<li><?php esc_html_e( 'After restoring a backup, or moving members between sites', 'slnwp-role-extension-pmpro' ); ?></li>
							<li><?php esc_html_e( 'As an occasional check, if you just want to be sure', 'slnwp-role-extension-pmpro' ); ?></li>
						</ul>
						<p class="description"><?php esc_html_e( 'Only members who are actually out of step are changed; everyone else is left untouched.', 'slnwp-role-extension-pmpro' ); ?></p>
					</div>

					<div class="pmpro-rm-card">
						<h2><?php esc_html_e( 'Protected Roles', 'slnwp-role-extension-pmpro' ); ?></h2>
						<p><?php esc_html_e( 'Never removed by this plugin, under any circumstances:', 'slnwp-role-extension-pmpro' ); ?></p>
						<ul class="pmpro-rm-tags">
							<?php foreach ( pmpro_rm_get_protected_roles() as $role ) : ?>
								<li><code><?php echo esc_html( $role ); ?></code></li>
							<?php endforeach; ?>
						</ul>
						<p class="description">
							<?php esc_html_e( 'Add more with the pmpro_protected_roles filter (see the Developers tab).', 'slnwp-role-extension-pmpro' ); ?>
						</p>
					</div>

				</div>

			</div>
		<?php endif; ?>

		<?php if ( 'misc' === $active_tab ) : ?>
			<div class="pmpro-rm-columns">

				<div class="pmpro-rm-col-wide">

					<div class="pmpro-rm-card">
						<h2><?php esc_html_e( 'Hooks for Developers', 'slnwp-role-extension-pmpro' ); ?></h2>
						<p><?php esc_html_e( 'Extend this plugin from your theme\'s functions.php (or a small site-specific plugin) with these hooks.', 'slnwp-role-extension-pmpro' ); ?></p>

						<div class="pmpro-rm-section">
							<p class="pmpro-rm-field-heading"><code>pmpro_protected_roles</code> <?php esc_html_e( '(filter)', 'slnwp-role-extension-pmpro' ); ?></p>
							<p class="description"><?php esc_html_e( 'Add extra roles this plugin should never remove, on top of the built-in list shown in the Protected Roles card.', 'slnwp-role-extension-pmpro' ); ?></p>
							<pre class="pmpro-rm-code"><code><?php echo esc_html( "add_filter( 'pmpro_protected_roles', function ( \$roles ) {\n\t\$roles[] = 'shop_manager';\n\treturn \$roles;\n} );" ); ?></code></pre>
						</div>

					</div>

				</div>

			</div>
		<?php endif; ?>


	</div>
	<?php
}


/**
 * Echo a configured-role list cell: the slug in <code>, plus a warning when
 * the role is not registered on this site; an em dash when none is set.
 *
 * @param string $slug Role slug (may be empty).
 */
function pmpro_rm_render_role_cell( $slug ) {
	if ( '' === $slug ) {
		echo '&mdash;';
		return;
	}

	echo '<code>' . esc_html( $slug ) . '</code>';

	if ( ! get_role( $slug ) ) {
		echo ' <span class="pmpro-rm-warn">&#9888; '
			. esc_html__( 'Role not found', 'slnwp-role-extension-pmpro' )
			. '</span>';
	}
}



/**
 * Stop a Role Sync request whose nonce failed, with a clear message. Shared by
 * every entry point in pmpro_rm_handle_sync(), which verifies the nonce once,
 * inline, before any of them run.
 *
 * @param bool $nonce_ok Whether the request's pmpro_rm_sync nonce verified.
 */
function pmpro_rm_verify_sync_nonce( $nonce_ok ) {
	if ( ! $nonce_ok ) {
		wp_die( esc_html__( 'Security check failed. Please reload the page and try again.', 'slnwp-role-extension-pmpro' ) );
	}
}




/**
 * Handle every Role Sync bulk-action request, in batches of 100 users per
 * request so a large site can never blow the request time limit.
 *
 * pmpro_rm_do_direct_sync (POST, the Run Sync Now button) starts a sync that
 * writes as it goes; pmpro_rm_continue_direct (GET) continues it, sent by the
 * progress screen's <meta refresh>, until every user has been processed.
 *
 * One pmpro_rm_sync nonce is created when a run starts and carried through
 * every step of it; every step re-verifies it.
 */
function pmpro_rm_handle_sync() {
	// Verified once here for every branch below (each refuses to run unless it
	// passed). $_REQUEST covers both the POST forms and the GET continue links.
	$nonce_ok = isset( $_REQUEST['pmpro_rm_nonce'] )
		&& false !== wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['pmpro_rm_nonce'] ) ), 'pmpro_rm_sync' );

	// Not gated on ! pmpro_rm_is_pro(): a licensed Pro install never shows
	// this button, but the handler itself is just a normal, nonce- and
	// capability-checked sync either way.
	if ( isset( $_POST['pmpro_rm_do_direct_sync'] ) ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to run the role sync.', 'slnwp-role-extension-pmpro' ) );
		}
		pmpro_rm_verify_sync_nonce( $nonce_ok );
		pmpro_rm_run_direct_sync_batch(
			0,
			array(
				'updated' => 0,
				'cleared' => 0,
				'skipped' => 0,
			)
		);
	} elseif ( isset( $_GET['pmpro_rm_continue_direct'] ) ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to run the role sync.', 'slnwp-role-extension-pmpro' ) );
		}
		pmpro_rm_verify_sync_nonce( $nonce_ok );
		pmpro_rm_run_direct_sync_batch(
			isset( $_GET['pmpro_rm_offset'] ) ? absint( wp_unslash( $_GET['pmpro_rm_offset'] ) ) : 0,
			array(
				'updated' => isset( $_GET['updated'] ) ? absint( wp_unslash( $_GET['updated'] ) ) : 0,
				'cleared' => isset( $_GET['cleared'] ) ? absint( wp_unslash( $_GET['cleared'] ) ) : 0,
				'skipped' => isset( $_GET['skipped'] ) ? absint( wp_unslash( $_GET['skipped'] ) ) : 0,
			)
		);
	}
}

/**
 * Batch worker for the free-tier "Run Sync Now" button: 100 users/request,
 * the same redirect-loop shape as Pro's scan/apply, but writes immediately
 * with no preview/review/transient layer.
 *
 * @param int   $offset
 * @param array $counts {updated, cleared, skipped}
 */
function pmpro_rm_run_direct_sync_batch( $offset, $counts ) {
	$batch = 100;

	$user_ids = get_users(
		array(
			'fields'  => 'ID',
			'orderby' => 'ID',
			'order'   => 'ASC',
			'number'  => $batch,
			'offset'  => $offset,
		)
	);

	foreach ( $user_ids as $user_id ) {
		$user_id = (int) $user_id;
		if ( ! get_userdata( $user_id ) ) {
			continue;
		}

		$desired         = pmpro_rm_desired_roles_for_user( $user_id );
		$is_default_case = false;
		$is_protected_np = false; // Protected-role holder with no active membership.

		if ( empty( $desired ) ) {
			if ( pmpro_rm_user_has_protected_role( new WP_User( $user_id ) ) ) {
				$is_protected_np = true;
			} else {
				$desired         = array( pmpro_rm_default_role() );
				$is_default_case = true;
			}
		}

		$diff = pmpro_rm_compute_role_diff( $user_id, $desired );
		if ( empty( $diff['to_add'] ) && empty( $diff['to_remove'] ) ) {
			if ( $is_protected_np ) {
				$counts['skipped']++;
			}
			continue;
		}

		pmpro_rm_apply_roles( $user_id, $desired );

		if ( $is_default_case ) {
			$counts['cleared']++;
		} else {
			$counts['updated']++;
		}
	}

	$processed = count( $user_ids );
	$offset   += $processed;

	if ( $processed < $batch ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'pmpro-role-sync',
					'tab'     => 'sync',
					'synced'  => '1',
					'updated' => $counts['updated'],
					'cleared' => $counts['cleared'],
					'skipped' => $counts['skipped'],
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'                     => 'pmpro-role-sync',
				'pmpro_rm_direct_progress' => '1',
				'pmpro_rm_offset'          => $offset,
				'updated'                  => $counts['updated'],
				'cleared'                  => $counts['cleared'],
				'skipped'                  => $counts['skipped'],
				'pmpro_rm_nonce'           => wp_create_nonce( 'pmpro_rm_sync' ),
			),
			admin_url( 'admin.php' )
		)
	);
	exit;
}

/**
 * Render the free-tier "in progress" screen shown between batches of a
 * direct sync run — the free-tier equivalent of
 * pmpro_rm_render_batch_progress(), with no scan/apply distinction since
 * there is only one pass.
 */
function pmpro_rm_render_direct_progress() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'slnwp-role-extension-pmpro' ) );
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display + continue-link only; the batch step re-verifies the nonce.
	$offset  = isset( $_GET['pmpro_rm_offset'] ) ? absint( wp_unslash( $_GET['pmpro_rm_offset'] ) ) : 0;
	$updated = isset( $_GET['updated'] ) ? absint( wp_unslash( $_GET['updated'] ) ) : 0;
	$cleared = isset( $_GET['cleared'] ) ? absint( wp_unslash( $_GET['cleared'] ) ) : 0;
	$skipped = isset( $_GET['skipped'] ) ? absint( wp_unslash( $_GET['skipped'] ) ) : 0;
	$nonce   = isset( $_GET['pmpro_rm_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['pmpro_rm_nonce'] ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	$continue_url = add_query_arg(
		array(
			'page'                     => 'pmpro-role-sync',
			'pmpro_rm_continue_direct' => '1',
			'pmpro_rm_offset'          => $offset,
			'updated'                  => $updated,
			'cleared'                  => $cleared,
			'skipped'                  => $skipped,
			'pmpro_rm_nonce'           => $nonce,
		),
		admin_url( 'admin.php' )
	);
	$stop_url = admin_url( 'admin.php?page=pmpro-role-sync&tab=sync' );
	$total    = (int) get_transient( 'pmpro_rm_total_users' );
	?>
	<meta http-equiv="refresh" content="1; url=<?php echo esc_url( $continue_url ); ?>">
	<div class="wrap">
		<h1><?php esc_html_e( 'SLNWP Role Sync', 'slnwp-role-extension-pmpro' ); ?></h1>
		<div class="pmpro-rm-grid">
			<div class="pmpro-rm-card">
				<h2><?php esc_html_e( 'Syncing roles…', 'slnwp-role-extension-pmpro' ); ?></h2>
				<p class="pmpro-rm-progress-note">
					<?php
					if ( $total > 0 ) {
						printf(
							/* translators: 1: users processed so far, 2: approximate total to process. */
							esc_html__( 'Processed %1$s of about %2$s users…', 'slnwp-role-extension-pmpro' ),
							esc_html( number_format_i18n( min( $offset, $total ) ) ),
							esc_html( number_format_i18n( $total ) )
						);
					} else {
						/* translators: %s: users processed so far. */
						printf( esc_html__( 'Processed %s users…', 'slnwp-role-extension-pmpro' ), esc_html( number_format_i18n( $offset ) ) );
					}
					?>
				</p>
				<p><?php esc_html_e( 'This page continues automatically until every user is synced. You can leave it running in the background.', 'slnwp-role-extension-pmpro' ); ?></p>
				<p class="pmpro-rm-actions">
					<a class="button button-primary" href="<?php echo esc_url( $continue_url ); ?>"><?php esc_html_e( 'Continue now', 'slnwp-role-extension-pmpro' ); ?></a>
					<a class="button" href="<?php echo esc_url( $stop_url ); ?>"><?php esc_html_e( 'Stop', 'slnwp-role-extension-pmpro' ); ?></a>
				</p>
			</div>
		</div>
	</div>
	<?php
}


// ============================================================================
// MEMBERSHIP LEVEL SETTINGS
// ============================================================================

/**
 * Add Role Slug dropdowns to level settings UI.
 */
function pmpro_rm_add_role_slug_field() {
	// PMPro has already authenticated this level-edit screen; we only read the ID.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$level_id  = isset( $_GET['edit'] ) ? absint( wp_unslash( $_GET['edit'] ) ) : 0;
	$role_slug = $level_id ? get_option( 'pmpro_level_role_' . $level_id, '' ) : '';
	$all_roles = wp_roles()->roles;
	?>
	<h3><?php esc_html_e( 'Role Settings', 'slnwp-role-extension-pmpro' ); ?></h3>
	<div class="pmpro-rm-section">
		<p class="pmpro-rm-field-heading"><?php esc_html_e( 'WordPress Role', 'slnwp-role-extension-pmpro' ); ?></p>
		<?php pmpro_rm_role_select( 'pmpro_level_role_slug', $role_slug, $all_roles ); ?>
		<?php wp_nonce_field( 'pmpro_rm_save_level_roles', 'pmpro_rm_level_nonce', false ); ?>
		<p class="description">
			<?php if ( 'pro' !== PMPRO_RM_TIER ) : ?>
				<?php esc_html_e( 'Assigned to members of this level.', 'slnwp-role-extension-pmpro' ); ?><br>
			<?php endif; ?>
			<?php esc_html_e( 'Multiple levels can share the same role.', 'slnwp-role-extension-pmpro' ); ?><br>
			<?php esc_html_e( 'Protected roles (listed on the Role Sync page) are not offered here, because this plugin never removes them from members.', 'slnwp-role-extension-pmpro' ); ?>
		</p>
	</div>
	<?php
}

/**
 * Echo a <select> of every registered WordPress role, "— None —" first.
 *
 * Protected roles are left out: this plugin never removes them, so a level
 * using one would leave that role with ex-members after cancellation or
 * expiry. A level that already has one saved still lists it (labelled), so
 * re-saving the level doesn't silently clear it; see
 * pmpro_rm_notice_protected_level_roles() for the warning.
 *
 * @param string $name      Field name.
 * @param string $current   Currently selected slug.
 * @param array  $all_roles wp_roles()->roles.
 * @param bool   $disabled  Render disabled="disabled" - a disabled field is
 *                          never submitted, which is how a hidden Interim
 *                          Role section (see pmpro_rm_add_role_slug_field())
 *                          avoids resubmitting/overwriting its own value
 *                          while hidden, with or without JS enabled.
 */
function pmpro_rm_role_select( $name, $current, $all_roles, $disabled = false ) {
	$protected = pmpro_rm_get_protected_roles();
	?>
	<select name="<?php echo esc_attr( $name ); ?>" <?php disabled( $disabled ); ?>>
		<option value=""><?php esc_html_e( '— None —', 'slnwp-role-extension-pmpro' ); ?></option>
		<?php
		foreach ( $all_roles as $slug => $data ) :
			$is_protected = in_array( $slug, $protected, true );
			if ( $is_protected && $slug !== $current ) {
				continue;
			}
			?>
			<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current, $slug ); ?>>
				<?php echo esc_html( translate_user_role( $data['name'] ) ); ?>
				<?php if ( $is_protected ) : ?>
					<?php esc_html_e( '(protected: never removed, choose another role)', 'slnwp-role-extension-pmpro' ); ?>
				<?php endif; ?>
			</option>
		<?php endforeach; ?>
	</select>
	<?php
}

/**
 * Save Role Slug fields when a membership level is saved.
 *
 * Fires from pmpro_save_membership_level. Checks this plugin's own nonce
 * (rendered with the fields by pmpro_rm_add_role_slug_field()) and the
 * capability before writing anything, and validates each submitted slug
 * against the site's registered roles so a crafted POST cannot store an
 * arbitrary key. A level saved without our fields on the form (no nonce) is
 * left exactly as it was.
 *
 * @param int $saveid Level ID passed by pmpro_save_membership_level.
 */
function pmpro_rm_save_role_slug_field( $saveid ) {
	$level_id = (int) $saveid;
	if ( ! $level_id || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! isset( $_POST['pmpro_rm_level_nonce'] )
		|| false === wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pmpro_rm_level_nonce'] ) ), 'pmpro_rm_save_level_roles' ) ) {
		return;
	}

	if ( isset( $_POST['pmpro_level_role_slug'] ) ) {
		$slug = sanitize_key( wp_unslash( $_POST['pmpro_level_role_slug'] ) );
		update_option( 'pmpro_level_role_' . $level_id, pmpro_rm_level_role_to_save( $slug, 'pmpro_level_role_' . $level_id ) );
	}


	// The set of configured role slugs just changed — drop the per-request cache.
	pmpro_rm_get_configured_roles( true );
}

/**
 * Return $slug only if it is a role registered on this site; otherwise ''.
 * The level-editor dropdowns are client-side only, so a crafted POST could
 * otherwise store an arbitrary key.
 *
 * @param string $slug Already run through sanitize_key().
 * @return string
 */
function pmpro_rm_valid_role_or_empty( $slug ) {
	return ( '' !== $slug && get_role( $slug ) ) ? $slug : '';
}

/**
 * The value to store for a level's WordPress Role or Interim Role option.
 *
 * A protected role is refused unless it is already the saved value: the
 * dropdown never offers one (see pmpro_rm_role_select()), so this only stops a
 * crafted POST, while a level that already used one keeps it until an admin
 * changes it on purpose (pmpro_rm_notice_protected_level_roles() flags it).
 * A refused value leaves the saved one unchanged.
 *
 * @param string $slug   Submitted slug, already run through sanitize_key().
 * @param string $option Option name holding the current value.
 * @return string
 */
function pmpro_rm_level_role_to_save( $slug, $option ) {
	$slug  = pmpro_rm_valid_role_or_empty( $slug );
	$saved = (string) get_option( $option, '' );

	if ( '' !== $slug && $slug !== $saved && in_array( $slug, pmpro_rm_get_protected_roles(), true ) ) {
		return $saved;
	}
	return $slug;
}


// ============================================================================
// ROLE HELPERS
// ============================================================================

/**
 * Return roles that are never removed by this plugin.
 *
 * A pmpro_protected_roles callback that returns something other than an array
 * (e.g. one that forgets `return $roles;`) falls back to the built-in list:
 * passing null on would make every role sync throw a TypeError on PHP 8 -
 * membership changes, sync runs and member logins alike.
 *
 * @return string[]
 */
function pmpro_rm_get_protected_roles() {
	$defaults = array(
		'administrator',
		'editor',
		'author',
		'contributor',
		'customer',
		'subscriber',
	);

	$roles = apply_filters( 'pmpro_protected_roles', $defaults );
	if ( ! is_array( $roles ) ) {
		return $defaults;
	}

	$roles = array_map( 'sanitize_key', array_filter( $roles, 'is_string' ) );
	return array_values( array_unique( array_filter( $roles ) ) );
}

/**
 * Check whether a user holds at least one protected role.
 *
 * @param WP_User $user
 * @return bool
 */
function pmpro_rm_user_has_protected_role( $user ) {
	return ! empty( array_intersect( (array) $user->roles, pmpro_rm_get_protected_roles() ) );
}


/**
 * The site's default role slug, guaranteed to be a role that actually exists.
 * Falls back to 'subscriber' if the option is empty or points at a missing role.
 *
 * @return string
 */
function pmpro_rm_default_role() {
	$role = get_option( 'default_role' );
	return ( ! empty( $role ) && get_role( $role ) ) ? $role : 'subscriber';
}

/**
 * Every role slug currently configured on any membership level (main or
 * interim). Read through the options API — no direct SQL, and it stays
 * consistent with the alloptions cache the rest of the plugin uses.
 *
 * Cached for the request; pmpro_rm_save_role_slug_field() passes true to
 * clear it after a change.
 *
 * @param bool $flush Reset the per-request cache and return.
 * @return string[]
 */
function pmpro_rm_get_configured_roles( $flush = false ) {
	static $cache = null;

	if ( $flush ) {
		// Fall through and rebuild rather than returning early: a caller
		// that (unlike today's only caller) actually uses the return value
		// of a flush call should get the fresh list, not an empty one.
		$cache = null;
	}

	if ( null !== $cache ) {
		return $cache;
	}

	$levels = function_exists( 'pmpro_getAllLevels' ) ? pmpro_getAllLevels( true, true ) : array();
	$levels = is_array( $levels ) ? $levels : array();

	$slugs = array();
	foreach ( $levels as $level ) {
		$main    = get_option( 'pmpro_level_role_' . (int) $level->id, '' );
		$interim = get_option( 'pmpro_level_interim_role_' . (int) $level->id, '' );
		if ( '' !== $main ) {
			$slugs[] = $main;
		}
		if ( '' !== $interim ) {
			$slugs[] = $interim;
		}
	}

	$cache = array_values( array_unique( $slugs ) );
	return $cache;
}

/**
 * Return the role slug configured for a level, or null if none / role missing.
 *
 * @param int $level_id
 * @return string|null
 */
function pmpro_rm_get_role_for_level( $level_id ) {
	$slug = get_option( 'pmpro_level_role_' . intval( $level_id ), '' );

	if ( empty( $slug ) ) {
		return null;
	}

	return get_role( $slug ) ? $slug : null;
}


/**
 * The complete set of membership roles a user should hold right now, across
 * every active membership level.
 *
 * Per level: the interim role while that member's subscription start date is
 * still in the future, then the main role once it has passed — the two are
 * mutually exclusive per level (with a fallback to the interim role if a
 * started level has no main role configured, so a member is never left with
 * nothing). With Multiple Memberships Per User a member can legitimately be on
 * the interim role for one level and the main role for another at the same
 * time, so every active level is evaluated and the union is returned.
 *
 * @param int $user_id
 * @return string[] Role slugs, each verified to exist.
 */
function pmpro_rm_desired_roles_for_user( $user_id ) {
	$user_id = (int) $user_id;

	$levels = function_exists( 'pmpro_getMembershipLevelsForUser' )
		? pmpro_getMembershipLevelsForUser( $user_id )
		: array();

	if ( empty( $levels ) ) {
		return array();
	}

	$desired = array();
	foreach ( $levels as $level ) {
		$started = true; // Free tier has no subscription-delay / interim-role concept — always started.

		$slug = null;
		if ( $started ) {
			$slug = pmpro_rm_get_role_for_level( $level->id );
		}

		if ( $slug ) {
			$desired[] = $slug;
		}
	}

	return array_values( array_unique( $desired ) );
}

/**
 * Compute (without writing anything) the role changes needed to bring
 * $user_id's roles into line with $desired. Shared by pmpro_rm_apply_roles()
 * and the bulk-sync test-run scan, so "what a preview shows" and "what
 * applying actually does" can never drift apart.
 *
 * @param int      $user_id
 * @param string[] $desired Desired membership role slugs.
 * @return array{current: string[], to_add: string[], to_remove: string[]}
 */
function pmpro_rm_compute_role_diff( $user_id, $desired ) {
	$desired    = array_values( array_unique( array_filter( (array) $desired ) ) );
	$configured = pmpro_rm_get_configured_roles();
	$protected  = pmpro_rm_get_protected_roles();
	$current    = (array) ( new WP_User( (int) $user_id ) )->roles;

	$to_add = array_diff( $desired, $current );

	// Only ever remove roles this plugin manages, never a protected one, and
	// never one we still want.
	$to_remove = array_diff( array_intersect( $current, $configured ), $desired, $protected );

	return array(
		'current'   => $current,
		'to_add'    => array_values( $to_add ),
		'to_remove' => array_values( $to_remove ),
	);
}

/**
 * Bring a user's roles into line with $desired: add the membership roles they
 * should have, remove the configured membership roles they should not — and
 * nothing else. Protected roles and roles this plugin does not manage are
 * never touched. Only the actual difference is written, so an interrupted run
 * can never strand a member with no membership role.
 *
 * Re-entrant-safe: role writes can trigger third-party handlers that call back
 * into pmpro_changeMembershipLevel(); this is a no-op while already running
 * for the same user.
 *
 * @param int      $user_id
 * @param string[] $desired  Desired membership role slugs.
 */
function pmpro_rm_apply_roles( $user_id, $desired ) {
	static $in_progress = array();

	$user_id = (int) $user_id;
	if ( ! $user_id || ! empty( $in_progress[ $user_id ] ) ) {
		return;
	}
	$in_progress[ $user_id ] = true;

	try {
		$diff = pmpro_rm_compute_role_diff( $user_id, $desired );

		if ( empty( $diff['to_add'] ) && empty( $diff['to_remove'] ) ) {
			return;
		}

		// Re-instantiate WP_User per write: add_role()/remove_role() persist
		// the whole in-memory caps array, so a handler firing between writes
		// could otherwise clobber an earlier change.
		foreach ( $diff['to_remove'] as $role ) {
			$u = new WP_User( $user_id );
			$u->remove_role( $role );
		}
		foreach ( $diff['to_add'] as $role ) {
			$u = new WP_User( $user_id );
			$u->add_role( $role );
		}

		clean_user_cache( $user_id );
		wp_cache_delete( $user_id, 'users' );
	} finally {
		unset( $in_progress[ $user_id ] );
	}
}


// ============================================================================
// MAIN HOOK
// ============================================================================

/**
 * Sync a user's roles to their current membership state.
 *
 * Fires for: new signup, level change, cancellation, expiry. Resolves the
 * full desired role set across *every* active level (so several levels that
 * begin billing on the same day are all handled in one pass), then applies
 * only the difference. On no active membership the user is reset to the site
 * default role — unless they hold a protected role, in which case only their
 * stale membership roles are stripped.
 *
 * $level_id is intentionally unused: PMPro fires this once per changed level,
 * but every call re-derives the whole user, so the per-level id is irrelevant
 * and honouring it would reintroduce "only one membership gets processed".
 *
 * @param int $level_id New level ID, or 0 for cancellation / expiry (unused).
 * @param int $user_id
 */
function pmpro_rm_sync_role_on_level_change( $level_id, $user_id ) {
	unset( $level_id );

	$user_id = (int) $user_id;
	if ( ! $user_id || ! get_userdata( $user_id ) ) {
		return;
	}

	$desired = pmpro_rm_desired_roles_for_user( $user_id );

	if ( empty( $desired ) && ! pmpro_rm_user_has_protected_role( new WP_User( $user_id ) ) ) {
		$desired = array( pmpro_rm_default_role() );
	}

	pmpro_rm_apply_roles( $user_id, $desired );
}

/**
 * Fallback sync on login.
 *
 * If pmpro_after_change_membership_level does not re-fire when a subscription's
 * billing starts (e.g. a Stripe webhook that does not call
 * pmpro_changeMembershipLevel), this brings the member's roles up to date the
 * next time they log in — covering every active level in one pass, not just
 * the first one that needs work.
 *
 * @param string  $user_login
 * @param WP_User $user
 */
function pmpro_rm_maybe_assign_role_on_login( $user_login, $user ) {
	if ( ! $user instanceof WP_User ) {
		return;
	}

	if ( ! empty( pmpro_getMembershipLevelsForUser( $user->ID ) ) ) {
		// pmpro_rm_apply_roles() inside is a no-op when nothing has drifted.
		pmpro_rm_sync_role_on_level_change( 0, $user->ID );
	}
}



// ============================================================================
// CHECKOUT TEXT
// ============================================================================



// ============================================================================
// NATIVE SUBSCRIPTION DELAY (no third-party add-on required)
// ============================================================================

