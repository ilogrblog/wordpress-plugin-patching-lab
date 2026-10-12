<?php
/**
 * Plugin Name:       Quick Quote Requests
 * Description:       Adds a [quote_request_form] shortcode, stores submissions in a custom table, lists them under Tools, and exposes them to administrators through a REST API route.
 * Version:           1.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * Text Domain:       quick-quote-requests
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class QQR_Plugin {

	const VERSION        = '1.0.0';
	const DB_VERSION     = '1';
	const DB_OPTION      = 'qqr_db_version';
	const REST_NAMESPACE = 'quick-quote-requests/v1';
	const PAGE_SLUG      = 'quick-quote-requests';
	const MAX_NAME       = 100;
	const MAX_EMAIL      = 100;
	const MAX_MESSAGE    = 5000;
	const RATE_LIMIT     = 5;    // Submissions allowed per IP...
	const RATE_WINDOW    = 3600; // ...per this many seconds.

	/** @var QQR_Plugin|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'qqr_submissions';
	}

	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade_db' ) );
		add_shortcode( 'quote_request_form', array( $this, 'render_shortcode' ) );

		// Front end submission handler (works for logged-in and anonymous visitors).
		add_action( 'admin_post_nopriv_qqr_submit', array( $this, 'handle_submission' ) );
		add_action( 'admin_post_qqr_submit', array( $this, 'handle_submission' ) );

		// Admin.
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_post_qqr_delete', array( $this, 'handle_delete' ) );

		// REST API.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/* ------------------------------------------------------------------
	 * Database
	 * ---------------------------------------------------------------- */

	public static function activate() {
		self::create_table();
	}

	public static function uninstall() {
		global $wpdb;
		// Table name is built from $wpdb->prefix only, not user input.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( self::DB_OPTION );
	}

	private static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(100) NOT NULL,
			email varchar(100) NOT NULL,
			message text NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) {$charset};";

		dbDelta( $sql );
		update_option( self::DB_OPTION, self::DB_VERSION );
	}

	public function maybe_upgrade_db() {
		if ( get_option( self::DB_OPTION ) !== self::DB_VERSION ) {
			self::create_table();
		}
	}

	/* ------------------------------------------------------------------
	 * Shortcode / front-end form
	 * ---------------------------------------------------------------- */

	public function render_shortcode() {
		$status  = isset( $_GET['qqr_status'] ) ? sanitize_key( wp_unslash( $_GET['qqr_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notices = array(
			'success'      => array( 'ok', __( 'Thank you. Your quote request has been received.', 'quick-quote-requests' ) ),
			'invalid'      => array( 'error', __( 'Please fill in your name, a valid email address and a message, within the length limits.', 'quick-quote-requests' ) ),
			'rate_limited' => array( 'error', __( 'Too many requests from your connection. Please try again later.', 'quick-quote-requests' ) ),
			'error'        => array( 'error', __( 'Sorry, your request could not be saved. Please try again.', 'quick-quote-requests' ) ),
		);

		ob_start();
		?>
		<div class="qqr-form-wrap">
			<style>
				.qqr-form-wrap .qqr-field{margin:0 0 1em}
				.qqr-form-wrap label{display:block;font-weight:600;margin-bottom:.25em}
				.qqr-form-wrap input[type=text],.qqr-form-wrap input[type=email],.qqr-form-wrap textarea{width:100%;max-width:40em;box-sizing:border-box}
				.qqr-form-wrap .qqr-notice{padding:.75em 1em;margin:0 0 1em;border-left:4px solid}
				.qqr-form-wrap .qqr-ok{border-color:#1a7f37;background:#edf7ef}
				.qqr-form-wrap .qqr-error{border-color:#b32d2e;background:#fbeaea}
				.qqr-form-wrap .qqr-hp{position:absolute!important;left:-9999px;height:0;width:0;overflow:hidden}
			</style>
			<div role="status" aria-live="polite">
				<?php if ( isset( $notices[ $status ] ) ) : ?>
					<p class="qqr-notice qqr-<?php echo esc_attr( $notices[ $status ][0] ); ?>"><?php echo esc_html( $notices[ $status ][1] ); ?></p>
				<?php endif; ?>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="qqr_submit">
				<?php wp_nonce_field( 'qqr_submit', 'qqr_nonce' ); ?>

				<p class="qqr-field">
					<label for="qqr-name"><?php esc_html_e( 'Name', 'quick-quote-requests' ); ?></label>
					<input type="text" id="qqr-name" name="qqr_name" required maxlength="<?php echo esc_attr( self::MAX_NAME ); ?>" autocomplete="name">
				</p>
				<p class="qqr-field">
					<label for="qqr-email"><?php esc_html_e( 'Email', 'quick-quote-requests' ); ?></label>
					<input type="email" id="qqr-email" name="qqr_email" required maxlength="<?php echo esc_attr( self::MAX_EMAIL ); ?>" autocomplete="email">
				</p>
				<p class="qqr-field">
					<label for="qqr-message"><?php esc_html_e( 'Message', 'quick-quote-requests' ); ?></label>
					<textarea id="qqr-message" name="qqr_message" rows="6" required maxlength="<?php echo esc_attr( self::MAX_MESSAGE ); ?>"></textarea>
				</p>
				<p class="qqr-hp" aria-hidden="true">
					<label for="qqr-website">Leave this field empty</label>
					<input type="text" id="qqr-website" name="qqr_website" tabindex="-1" autocomplete="off">
				</p>
				<p><button type="submit"><?php esc_html_e( 'Request a quote', 'quick-quote-requests' ); ?></button></p>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	private function redirect_back( $status ) {
		$target = wp_get_referer();
		if ( ! $target ) {
			$target = home_url( '/' );
		}
		$target = remove_query_arg( 'qqr_status', $target );
		$target = add_query_arg( 'qqr_status', $status, $target );
		wp_safe_redirect( $target, 303 );
		exit;
	}

	public function handle_submission() {
		if ( ! isset( $_POST['qqr_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['qqr_nonce'] ) ), 'qqr_submit' ) ) {
			wp_die( esc_html__( 'Security check failed. Please go back, reload the page and try again.', 'quick-quote-requests' ), '', array( 'response' => 403 ) );
		}

		// Honeypot: bots fill it in. Pretend success and store nothing.
		if ( ! empty( $_POST['qqr_website'] ) ) {
			$this->redirect_back( 'success' );
		}

		if ( $this->is_rate_limited() ) {
			$this->redirect_back( 'rate_limited' );
		}

		$name    = isset( $_POST['qqr_name'] ) ? sanitize_text_field( wp_unslash( $_POST['qqr_name'] ) ) : '';
		$email   = isset( $_POST['qqr_email'] ) ? sanitize_email( wp_unslash( $_POST['qqr_email'] ) ) : '';
		$message = isset( $_POST['qqr_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['qqr_message'] ) ) : '';

		if (
			'' === $name || mb_strlen( $name ) > self::MAX_NAME ||
			'' === $email || ! is_email( $email ) || mb_strlen( $email ) > self::MAX_EMAIL ||
			'' === $message || mb_strlen( $message ) > self::MAX_MESSAGE
		) {
			$this->redirect_back( 'invalid' );
		}

		global $wpdb;
		$inserted = $wpdb->insert(
			self::table_name(),
			array(
				'name'       => $name,
				'email'      => $email,
				'message'    => $message,
				'created_at' => current_time( 'mysql', true ), // Stored in GMT.
			),
			array( '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			$this->redirect_back( 'error' );
		}

		$this->record_submission_for_rate_limit();
		$this->redirect_back( 'success' );
	}

	/* ------------------------------------------------------------------
	 * Basic per-IP rate limiting (transients, IP is hashed, never stored in the table)
	 * ---------------------------------------------------------------- */

	private function rate_key() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		return 'qqr_rl_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ), 0, 32 );
	}

	private function is_rate_limited() {
		return (int) get_transient( $this->rate_key() ) >= self::RATE_LIMIT;
	}

	private function record_submission_for_rate_limit() {
		$key   = $this->rate_key();
		$count = (int) get_transient( $key );
		if ( 0 === $count ) {
			set_transient( $key, 1, self::RATE_WINDOW );
		} else {
			// Re-setting would extend the window, so keep it simple and bounded.
			set_transient( $key, $count + 1, self::RATE_WINDOW );
		}
	}

	/* ------------------------------------------------------------------
	 * Data access
	 * ---------------------------------------------------------------- */

	private function get_submissions( $page, $per_page ) {
		global $wpdb;
		$table  = self::table_name();
		$offset = max( 0, ( $page - 1 ) * $per_page );

		// $table is from $wpdb->prefix; values are prepared.
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT id, name, email, message, created_at FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ),
			ARRAY_A
		);
	}

	private function count_submissions() {
		global $wpdb;
		$table = self::table_name();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/* ------------------------------------------------------------------
	 * Admin page (Tools > Quote Requests)
	 * ---------------------------------------------------------------- */

	public function register_admin_page() {
		add_management_page(
			__( 'Quote Requests', 'quick-quote-requests' ),
			__( 'Quote Requests', 'quick-quote-requests' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_admin_page' )
		);
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'quick-quote-requests' ), '', array( 'response' => 403 ) );
		}

		$per_page    = 20;
		$page        = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$total       = $this->count_submissions();
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$page        = min( $page, $total_pages );
		$rows        = $this->get_submissions( $page, $per_page );
		$deleted     = isset( $_GET['qqr_deleted'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Quote Requests', 'quick-quote-requests' ); ?></h1>

			<?php if ( $deleted ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Request deleted.', 'quick-quote-requests' ); ?></p></div>
			<?php endif; ?>

			<p>
				<?php
				printf(
					/* translators: %s: number of submissions */
					esc_html( _n( '%s request', '%s requests', $total, 'quick-quote-requests' ) ),
					esc_html( number_format_i18n( $total ) )
				);
				?>
			</p>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col" style="width:5em"><?php esc_html_e( 'ID', 'quick-quote-requests' ); ?></th>
						<th scope="col" style="width:14em"><?php esc_html_e( 'Date', 'quick-quote-requests' ); ?></th>
						<th scope="col" style="width:14em"><?php esc_html_e( 'Name', 'quick-quote-requests' ); ?></th>
						<th scope="col" style="width:18em"><?php esc_html_e( 'Email', 'quick-quote-requests' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Message', 'quick-quote-requests' ); ?></th>
						<th scope="col" style="width:6em"><?php esc_html_e( 'Actions', 'quick-quote-requests' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No quote requests yet.', 'quick-quote-requests' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$id         = (int) $row['id'];
						$delete_url = wp_nonce_url(
							add_query_arg(
								array(
									'action' => 'qqr_delete',
									'id'     => $id,
								),
								admin_url( 'admin-post.php' )
							),
							'qqr_delete_' . $id
						);
						?>
						<tr>
							<td><?php echo esc_html( $id ); ?></td>
							<td><?php echo esc_html( get_date_from_gmt( $row['created_at'], $date_format ) ); ?></td>
							<td><?php echo esc_html( $row['name'] ); ?></td>
							<td><a href="<?php echo esc_url( 'mailto:' . $row['email'] ); ?>"><?php echo esc_html( $row['email'] ); ?></a></td>
							<td style="white-space:pre-wrap;word-break:break-word"><?php echo esc_html( $row['message'] ); ?></td>
							<td>
								<a href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this request permanently?', 'quick-quote-requests' ) ); ?>');"><?php esc_html_e( 'Delete', 'quick-quote-requests' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'      => add_query_arg( 'paged', '%#%' ),
									'format'    => '',
									'current'   => $page,
									'total'     => $total_pages,
									'prev_text' => '&laquo;',
									'next_text' => '&raquo;',
								)
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	public function handle_delete() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'quick-quote-requests' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'qqr_delete_' . $id );

		if ( $id > 0 ) {
			global $wpdb;
			$wpdb->delete( self::table_name(), array( 'id' => $id ), array( '%d' ) );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => self::PAGE_SLUG,
					'qqr_deleted' => 1,
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/* ------------------------------------------------------------------
	 * REST API: GET /wp-json/quick-quote-requests/v1/submissions
	 *
	 * Submissions contain personal data, so the route is restricted to users
	 * who can manage_options. The dashboard app should authenticate with an
	 * Application Password (HTTP Basic auth over HTTPS) for an admin user.
	 * Cookie auth also works from the browser when the X-WP-Nonce header is sent.
	 * ---------------------------------------------------------------- */

	public function register_rest_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/submissions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_get_submissions' ),
				'permission_callback' => array( $this, 'rest_permission' ),
				'args'                => array(
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 50,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);
	}

	public function rest_permission() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error(
			'qqr_forbidden',
			__( 'You are not allowed to read quote requests.', 'quick-quote-requests' ),
			array( 'status' => is_user_logged_in() ? 403 : 401 )
		);
	}

	public function rest_get_submissions( WP_REST_Request $request ) {
		$page     = max( 1, (int) $request['page'] );
		$per_page = min( 100, max( 1, (int) $request['per_page'] ) );
		$total    = $this->count_submissions();
		$rows     = $this->get_submissions( $page, $per_page );

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = array(
				'id'             => (int) $row['id'],
				'name'           => $row['name'],
				'email'          => $row['email'],
				'message'        => $row['message'],
				'created_at_gmt' => str_replace( ' ', 'T', $row['created_at'] ) . 'Z',
			);
		}

		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $total / $per_page ) ) );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}
}

register_activation_hook( __FILE__, array( 'QQR_Plugin', 'activate' ) );
register_uninstall_hook( __FILE__, array( 'QQR_Plugin', 'uninstall' ) );
QQR_Plugin::instance();
