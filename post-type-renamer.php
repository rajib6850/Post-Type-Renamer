<?php
/**
 * Plugin Name: Post Type Renamer
 * Description: Rename post types and/or custom field keys (meta) in safe batches with an admin UI.
 * Version: 1.0.1
 * Author: Execute Rajib
 * Text Domain: ex-ptrx
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * License: GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class PTR_Extended {
	const OPTION_STATE      = 'ex_ptrx_state';
	const NONCE_FIELD       = 'ex_ptrx_nonce';
	const NONCE_ACTION      = 'ex_ptrx_action';
	const CAPABILITY        = 'manage_options';
	const MENU_SLUG         = 'ex-post-type-renamer-extended';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'admin_menu' ] );
		add_action( 'admin_post_ex_ptrx_start', [ $this, 'handle_start' ] );
		add_action( 'admin_post_ex_ptrx_step',  [ $this, 'handle_step' ] );
		add_action( 'admin_post_ex_ptrx_reset', [ $this, 'handle_reset' ] );
	}

	public function admin_menu() {
		add_management_page(
			__( 'Post Type Renamer + Meta', 'ex-ptrx' ),
			__( 'Post Type Renamer + Meta', 'ex-ptrx' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);
	}

	private function get_state() {
		$state = get_option( self::OPTION_STATE, [] );
		$defaults = [
			'from'         => '',
			'to'           => '',
			'total'        => 0,
			'processed'    => 0,
			'batch'        => 200,
			'started_at'   => 0,
			'done'         => false,
			'last_ids'     => [],
			'meta_map'     => [],
			'delete_old'   => true,
			'meta_only'    => false,
		];
		return wp_parse_args( $state, $defaults );
	}

	private function save_state( $state ) { update_option( self::OPTION_STATE, $state, false ); }

	private function parse_meta_map( $raw ) {
		$map = [];
		$lines = preg_split( '/\r\n|\r|\n/', (string) $raw );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) continue;
			if ( strpos( $line, '=>' ) !== false ) {
				list( $old, $new ) = array_map( 'trim', explode( '=>', $line, 2 ) );
			} elseif ( strpos( $line, ':' ) !== false ) {
				list( $old, $new ) = array_map( 'trim', explode( ':', $line, 2 ) );
			} elseif ( strpos( $line, '=' ) !== false ) {
				list( $old, $new ) = array_map( 'trim', explode( '=', $line, 2 ) );
			} else {
				continue;
			}
			$old = sanitize_key( $old ); $new = sanitize_key( $new );
			if ( $old && $new && $old !== $new ) { $map[] = [ 'from' => $old, 'to' => $new ]; }
		}
		return $map;
	}

	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) { wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'ex-ptrx' ) ); }
		$state = $this->get_state();
		$is_running = ( ! $state['done'] ) && ( ! empty( $state['from'] ) ) && ( $state['total'] > 0 ) && ( $state['processed'] < $state['total'] );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Post Type Renamer + Meta Key Renamer', 'ex-ptrx' ); ?></h1>
			<p><?php esc_html_e( 'Rename post type and/or custom field keys (meta) in batches.', 'ex-ptrx' ); ?></p>
			<?php if ( $notice = get_transient( 'ex_ptrx_notice' ) ) : ?>
				<div class="notice notice-info"><p><?php echo wp_kses_post( $notice ); ?></p></div>
			<?php delete_transient( 'ex_ptrx_notice' ); endif; ?>

			<div class="card" style="max-width:920px;padding:20px;">
				<h2><?php esc_html_e( 'Setup', 'ex-ptrx' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
					<input type="hidden" name="action" value="ex_ptrx_start" />
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="from"><?php esc_html_e( 'From post type', 'ex-ptrx' ); ?></label></th>
							<td><input required class="regular-text" type="text" id="from" name="from" placeholder="e.g. ds_service" value="<?php echo esc_attr( $state['from'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="to"><?php esc_html_e( 'To post type', 'ex-ptrx' ); ?></label></th>
							<td>
								<input class="regular-text" type="text" id="to" name="to" placeholder="e.g. service" value="<?php echo esc_attr( $state['to'] ); ?>">
								<p class="description"><?php esc_html_e( 'Leave blank for meta-only mode.', 'ex-ptrx' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="batch"><?php esc_html_e( 'Batch size', 'ex-ptrx' ); ?></label></th>
							<td><input class="small-text" type="number" id="batch" name="batch" min="10" max="2000" step="10" value="<?php echo esc_attr( (int) $state['batch'] ); ?>"></td>
						</tr>
						<tr>
							<th scope="row" style="vertical-align:top;"><label for="meta_map"><?php esc_html_e( 'Meta key rename map', 'ex-ptrx' ); ?></label></th>
							<td>
								<textarea class="large-text code" id="meta_map" name="meta_map" rows="6" placeholder="ds_service_subtitle => service_subtitle"></textarea>
								<label><input type="checkbox" name="delete_old" value="1" checked> <?php esc_html_e( 'Delete old meta keys after copying', 'ex-ptrx' ); ?></label><br>
								<label><input type="checkbox" name="meta_only" value="1"> <?php esc_html_e( 'Meta-only mode (do not change post type)', 'ex-ptrx' ); ?></label>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Start', 'ex-ptrx' ), 'primary', 'submit', false ); ?>
				</form>
			</div>

			<div class="card" style="max-width:920px;padding:20px;">
				<h2><?php esc_html_e( 'Progress', 'ex-ptrx' ); ?></h2>
				<?php if ( $state['total'] > 0 ) : ?>
					<p><strong><?php esc_html_e( 'From', 'ex-ptrx' ); ?>:</strong> <?php echo esc_html( $state['from'] ); ?> |
					   <strong><?php esc_html_e( 'To', 'ex-ptrx' ); ?>:</strong> <?php echo esc_html( $state['to'] ?: '—' ); ?></p>
					<p><strong><?php esc_html_e( 'Processed', 'ex-ptrx' ); ?>:</strong> <?php echo esc_html( (int) $state['processed'] ); ?> / <?php echo esc_html( (int) $state['total'] ); ?></p>
					<?php if ( ! empty( $state['last_ids'] ) ) : ?>
						<p class="description"><?php esc_html_e( 'Last IDs', 'ex-ptrx' ); ?>: <?php echo esc_html( implode( ', ', array_map( 'intval', (array) $state['last_ids'] ) ) ); ?></p>
					<?php endif; ?>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'No active run yet.', 'ex-ptrx' ); ?></p>
				<?php endif; ?>

				<?php if ( ( ! $state['done'] ) && $state['total'] > 0 && $state['processed'] < $state['total'] ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="step-form">
						<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
						<input type="hidden" name="action" value="ex_ptrx_step" />
						<?php submit_button( __( 'Run Next Batch', 'ex-ptrx' ), 'secondary', 'submit', false ); ?>
					</form>
					<script>setTimeout(function(){ document.getElementById('step-form').submit(); }, 1000);</script>
				<?php elseif ( $state['done'] && $state['total'] > 0 ) : ?>
					<p><strong><?php esc_html_e( 'All done.', 'ex-ptrx' ); ?></strong></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
						<input type="hidden" name="action" value="ex_ptrx_reset" />
						<?php submit_button( __( 'Reset State', 'ex-ptrx' ), 'secondary', 'submit', false ); ?>
					</form>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public function handle_start() {
		if ( ! current_user_can( self::CAPABILITY ) ) { wp_die( esc_html__( 'Permission denied.', 'ex-ptrx' ) ); }
		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		$from   = sanitize_key( $_POST['from'] ?? '' );
		$to     = sanitize_key( $_POST['to'] ?? '' );
		$batch  = max( 10, min( 2000, intval( $_POST['batch'] ?? 200 ) ) );
		$mapraw = (string) ( $_POST['meta_map'] ?? '' );
		$map    = $this->parse_meta_map( $mapraw );
		$delete = ! empty( $_POST['delete_old'] );
		$meta_only = ! empty( $_POST['meta_only'] );

		if ( empty( $from ) ) { set_transient( 'ex_ptrx_notice', __( '"From post type" is required.', 'ex-ptrx' ), 30 ); wp_safe_redirect( admin_url( 'tools.php?page=' . self::MENU_SLUG ) ); exit; }
		if ( ! $meta_only && empty( $to ) ) { set_transient( 'ex_ptrx_notice', __( 'Provide a "To post type" or enable Meta-only mode.', 'ex-ptrx' ), 30 ); wp_safe_redirect( admin_url( 'tools.php?page=' . self::MENU_SLUG ) ); exit; }

		global $wpdb;
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", $from ) );

		$state = [
			'from'         => $from, 'to' => $to, 'total' => $total, 'processed' => 0, 'batch' => $batch,
			'started_at'   => time(), 'done' => false, 'last_ids' => [],
			'meta_map'     => $map, 'delete_old' => $delete ? 1 : 0, 'meta_only' => $meta_only ? 1 : 0,
		];
		$this->save_state( $state );

		if ( 0 === $total ) { set_transient( 'ex_ptrx_notice', __( 'No posts found for the source post type.', 'ex-ptrx' ), 30 ); }

		wp_safe_redirect( admin_url( 'tools.php?page=' . self::MENU_SLUG ) ); exit;
	}

	public function handle_step() {
		if ( ! current_user_can( self::CAPABILITY ) ) { wp_die( esc_html__( 'Permission denied.', 'ex-ptrx' ) ); }
		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		$state = $this->get_state();
		if ( empty( $state['from'] ) || $state['done'] ) { wp_safe_redirect( admin_url( 'tools.php?page=' . self::MENU_SLUG ) ); exit; }

		$batch   = (int) $state['batch']; $from = $state['from']; $to = $state['to']; $offset = (int) $state['processed'];

		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s ORDER BY ID ASC LIMIT %d OFFSET %d", $from, $batch, $offset ) );

		if ( empty( $ids ) ) {
			$state['done'] = true; $this->save_state( $state );
			flush_rewrite_rules();
			set_transient( 'ex_ptrx_notice', __( 'Run complete. Rewrite rules flushed.', 'ex-ptrx' ), 30 );
			wp_safe_redirect( admin_url( 'tools.php?page=' . self::MENU_SLUG ) ); exit;
		}

		$meta_map   = (array) $state['meta_map'];
		$delete_old = ! empty( $state['delete_old'] );
		$meta_only  = ! empty( $state['meta_only'] );

		foreach ( $ids as $id ) {
			if ( ! $meta_only && $to && $to != $from ) { wp_update_post( [ 'ID' => (int) $id, 'post_type' => $to ], true ); }

			if ( ! empty( $meta_map ) ) {
				foreach ( $meta_map as $pair ) {
					$old = sanitize_key( $pair['from'] ?? '' );
					$new = sanitize_key( $pair['to'] ?? '' );
					if ( ! $old || ! $new || $old === $new ) continue;

					$values = get_post_meta( $id, $old, false );
					if ( empty( $values ) ) continue;

					foreach ( $values as $val ) {
						if ( function_exists( 'metadata_exists' ) && metadata_exists( 'post', $id, $new ) ) {
							$existing = get_post_meta( $id, $new, false );
							$has_same = false;
							foreach ( (array) $existing as $ex ) { if ( maybe_serialize( $ex ) == maybe_serialize( $val ) ) { $has_same = True; break; } }
							if ( ! $has_same ) { add_post_meta( $id, $new, $val ); }
						} else {
							add_post_meta( $id, $new, $val );
						}
					}
					if ( $delete_old ) { delete_post_meta( $id, $old ); }
				}
			}
		}

		$state['processed'] += len(ids) if False else len(ids)  # placeholder for readability
		$state['processed'] += 0  # will be overwritten below
		$state['processed'] = (int) ($offset + len(ids))

		$state['last_ids']   = $ids;
		$this->save_state( $state );

		wp_safe_redirect( admin_url( 'tools.php?page=' . self::MENU_SLUG ) ); exit;
	}

	public function handle_reset() {
		if ( ! current_user_can( self::CAPABILITY ) ) { wp_die( esc_html__( 'Permission denied.', 'ex-ptrx' ) ); }
		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );
		delete_option( self::OPTION_STATE ); delete_transient( 'ex_ptrx_notice' );
		wp_safe_redirect( admin_url( 'tools.php?page=' . self::MENU_SLUG ) ); exit;
	}
}

new PTR_Extended();
