<?php
/**
 * Plugin Name: Post Type Renamer
 * Description: Migrate posts from one custom post type to another (e.g., ex_service → service) with a safe, batched UI.
 * Version: 1.0.0
 * Author: Execute Rajib
 * Text Domain: ex-ptr
 * Requires at least: 5.6
 * Requires PHP: 8.1
 * License: GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ex_Post_Type_Renamer {
	const OPTION_STATE = 'ex_ptr_state';
	const NONCE_FIELD  = 'ex_ptr_nonce';
	const NONCE_ACTION = 'ex_ptr_action';
	const CAPABILITY   = 'manage_options';
	const MENU_SLUG    = 'ex-post-type-renamer';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'admin_menu' ] );
		add_action( 'admin_post_ex_ptr_start', [ $this, 'handle_start' ] );
		add_action( 'admin_post_ex_ptr_step',  [ $this, 'handle_step' ] );
		add_action( 'admin_post_ex_ptr_reset', [ $this, 'handle_reset' ] );
	}

	public function admin_menu() {
		add_management_page(
			__( 'Post Type Renamer', 'ex-ptr' ),
			__( 'Post Type Renamer', 'ex-ptr' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);
	}

	private function get_state() {
		$state = get_option( self::OPTION_STATE, [] );
		$defaults = [
			'from'       => '',
			'to'         => '',
			'total'      => 0,
			'processed'  => 0,
			'batch'      => 200,
			'started_at' => 0,
			'done'       => false,
			'last_ids'   => [],
		];
		return wp_parse_args( $state, $defaults );
	}

	private function save_state( $state ) {
		update_option( self::OPTION_STATE, $state, false );
	}

	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'ex-ptr' ) );
		}
		$state = $this->get_state();
		$is_running = ! empty( $state['from'] ) && ! $state['done'] && $state['total'] > 0 && $state['processed'] < $state['total'];

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'ex Post Type Renamer', 'ex-ptr' ); ?></h1>
			<p><?php esc_html_e( 'Convert posts from one custom post type key to another in safe batches. Example: ds_service → service. Make sure the target post type is registered by your theme or a plugin. Back up your database before running.', 'ex-ptr' ); ?></p>

			<?php if ( $notice = get_transient( 'ex_ptr_notice' ) ) : ?>
				<div class="notice notice-info"><p><?php echo wp_kses_post( $notice ); ?></p></div>
			<?php delete_transient( 'ex_ptr_notice' ); endif; ?>

			<div class="card" style="max-width:840px;padding:20px;">
				<h2><?php esc_html_e( 'Setup', 'ex-ptr' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
					<input type="hidden" name="action" value="ex_ptr_start" />

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="ex_ptr_from"><?php esc_html_e( 'From post type', 'ex-ptr' ); ?></label></th>
							<td>
								<input required class="regular-text" type="text" id="ex_ptr_from" name="from" placeholder="e.g. ds_service" value="<?php echo esc_attr( $state['from'] ); ?>">
								<p class="description"><?php esc_html_e( 'Existing post type key to migrate from.', 'ex-ptr' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="ex_ptr_to"><?php esc_html_e( 'To post type', 'ex-ptr' ); ?></label></th>
							<td>
								<input required class="regular-text" type="text" id="ex_ptr_to" name="to" placeholder="e.g. service" value="<?php echo esc_attr( $state['to'] ); ?>">
								<p class="description"><?php esc_html_e( 'Target post type key (must be registered).', 'ex-ptr' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="ex_ptr_batch"><?php esc_html_e( 'Batch size', 'ex-ptr' ); ?></label></th>
							<td>
								<input class="small-text" type="number" id="ex_ptr_batch" name="batch" min="10" max="2000" step="10" value="<?php echo esc_attr( (int) $state['batch'] ); ?>">
								<p class="description"><?php esc_html_e( 'How many posts to process per step. Default 200.', 'ex-ptr' ); ?></p>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Start Migration', 'ex-ptr' ), 'primary', 'submit', false ); ?>
				</form>
			</div>

			<div class="card" style="max-width:840px;padding:20px;">
				<h2><?php esc_html_e( 'Progress', 'ex-ptr' ); ?></h2>
				<?php if ( $state['total'] > 0 ) : ?>
					<p>
						<strong><?php esc_html_e( 'From', 'ex-ptr' ); ?>:</strong> <?php echo esc_html( $state['from'] ); ?> &nbsp; | &nbsp;
						<strong><?php esc_html_e( 'To', 'ex-ptr' ); ?>:</strong> <?php echo esc_html( $state['to'] ); ?>
					</p>
					<p>
						<strong><?php esc_html_e( 'Processed', 'ex-ptr' ); ?>:</strong> <?php echo esc_html( (int) $state['processed'] ); ?> / <?php echo esc_html( (int) $state['total'] ); ?>
					</p>
					<?php if ( ! empty( $state['last_ids'] ) ) : ?>
						<p class="description"><?php esc_html_e( 'Last processed IDs', 'ex-ptr' ); ?>: <?php echo esc_html( implode( ', ', array_map( 'intval', (array) $state['last_ids'] ) ) ); ?></p>
					<?php endif; ?>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'No active migration yet.', 'ex-ptr' ); ?></p>
				<?php endif; ?>

				<?php if ( $is_running ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="ex-ptr-step-form">
						<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
						<input type="hidden" name="action" value="ex_ptr_step" />
						<?php submit_button( __( 'Run Next Batch', 'ex-ptr' ), 'secondary', 'submit', false ); ?>
					</form>
					<script>
						// Auto-advance every second until done.
						setTimeout(function(){
							document.getElementById('ex-ptr-step-form').submit();
						}, 1000);
					</script>
				<?php elseif ( $state['done'] && $state['total'] > 0 ) : ?>
					<p><strong><?php esc_html_e( 'Migration complete.', 'ex-ptr' ); ?></strong></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
						<input type="hidden" name="action" value="ex_ptr_reset" />
						<?php submit_button( __( 'Reset State', 'ex-ptr' ), 'secondary', 'submit', false ); ?>
					</form>
				<?php endif; ?>
			</div>

			<div class="card" style="max-width:840px;padding:20px;">
				<h2><?php esc_html_e( 'Notes', 'ex-ptr' ); ?></h2>
				<ul class="ul-disc">
					<li><?php esc_html_e( 'Make sure the target post type is registered (by your theme or another plugin) before running. Otherwise posts will still convert, but you will not see them in the admin until it is registered.', 'ex-ptr' ); ?></li>
					<li><?php esc_html_e( 'After completion, the plugin flushes rewrite rules once.', 'ex-ptr' ); ?></li>
					<li><?php esc_html_e( 'Taxonomies, meta, thumbnails, Elementor content, etc. stay attached to the posts.', 'ex-ptr' ); ?></li>
					<li><?php esc_html_e( 'If you are converting large volumes, increase the batch size gradually if needed.', 'ex-ptr' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}

	public function handle_start() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ex-ptr' ) );
		}
		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		$from  = sanitize_key( $_POST['from'] ?? '' );
		$to    = sanitize_key( $_POST['to'] ?? '' );
		$batch = max( 10, min( 2000, intval( $_POST['batch'] ?? 200 ) ) );

		if ( empty( $from ) || empty( $to ) || $from === $to ) {
			set_transient( 'ex_ptr_notice', __( 'Please provide valid and different "from" and "to" post type slugs.', 'ex-ptr' ), 30 );
			wp_safe_redirect( admin_url( 'tools.php?page=' . self::MENU_SLUG ) );
			exit;
		}

		// Count how many to process
		$total = (int) wp_count_posts( $from, 'readable' )->publish;
		// Include other statuses too:
		$statuses = get_post_stati();
		$q = new WP_Query([
			'post_type'      => $from,
			'post_status'    => $statuses,
			'fields'         => 'ids',
			'posts_per_page' => 1,
		]);
		// The above query is just to ensure the type exists; we'll count properly now.
		global $wpdb;
		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
			$from
		) );

		$state = [
			'from'       => $from,
			'to'         => $to,
			'total'      => $total,
			'processed'  => 0,
			'batch'      => $batch,
			'started_at' => time(),
			'done'       => false,
			'last_ids'   => [],
		];
		$this->save_state( $state );

		if ( 0 === $total ) {
			set_transient( 'ex_ptr_notice', __( 'No posts found for the source post type.', 'ex-ptr' ), 30 );
		}

		wp_safe_redirect( admin_url( 'tools.php?page=' . self::MENU_SLUG ) );
		exit;
	}

	public function handle_step() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ex-ptr' ) );
		}
		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		$state = $this->get_state();
		if ( empty( $state['from'] ) || empty( $state['to'] ) || $state['done'] ) {
			wp_safe_redirect( admin_url( 'tools.php?page=' . self::MENU_SLUG ) );
			exit;
		}

		$batch   = (int) $state['batch'];
		$from    = $state['from'];
		$to      = $state['to'];
		$offset  = (int) $state['processed'];

		// Fetch a batch of IDs starting from the current offset.
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s ORDER BY ID ASC LIMIT %d OFFSET %d",
			$from,
			$batch,
			$offset
		) );

		if ( empty( $ids ) ) {
			// No more posts. Mark done and flush rules.
			$state['done'] = true;
			$this->save_state( $state );
			flush_rewrite_rules();
			set_transient( 'ex_ptr_notice', __( 'Migration complete. Rewrite rules have been flushed.', 'ex-ptr' ), 30 );
			wp_safe_redirect( admin_url( 'tools.php?page=' . self::MENU_SLUG ) );
			exit;
		}

		$updated = 0;
		foreach ( $ids as $id ) {
			$res = wp_update_post( [
				'ID'        => (int) $id,
				'post_type' => $to,
			], true );
			if ( ! is_wp_error( $res ) ) {
				$updated++;
			}
		}

		$state['processed'] += count( $ids );
		$state['last_ids']   = $ids;
		$this->save_state( $state );

		wp_safe_redirect( admin_url( 'tools.php?page=' . self::MENU_SLUG ) );
		exit;
	}

	public function handle_reset() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Permission denied.', 'ex-ptr' ) );
		}
		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );
		delete_option( self::OPTION_STATE );
		delete_transient( 'ex_ptr_notice' );
		wp_safe_redirect( admin_url( 'tools.php?page=' . self::MENU_SLUG ) );
		exit;
	}
}

new ex_Post_Type_Renamer();
