<?php
/**
 * Plugin Name: Quick Quote Requests
 * Description: Front-end quote request form ([quote_request_form]) that stores submissions in a custom table, lists them under Tools, and exposes them as JSON through the REST API.
 * Version: 1.0.0
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: quick-quote-requests
 */

if (!defined('ABSPATH')) {
    exit;
}

define('QQR_VERSION', '1.0.0');
define('QQR_DB_VERSION', '1.0.0');
define('QQR_RATE_LIMIT', 5);                       // submissions allowed per IP per window
define('QQR_RATE_WINDOW', 10 * MINUTE_IN_SECONDS); // window length in seconds

/* -------------------------------------------------------------------------
 * Database
 * ---------------------------------------------------------------------- */

function qqr_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'qqr_requests';
}

function qqr_create_table() {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table           = qqr_table_name();
    $charset_collate = $wpdb->get_charset_collate();

    // dbDelta is picky: two spaces after PRIMARY KEY, one column per line.
    $sql = "CREATE TABLE $table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        name varchar(100) NOT NULL,
        email varchar(190) NOT NULL,
        message text NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY created_at (created_at)
    ) $charset_collate;";

    dbDelta($sql);
    update_option('qqr_db_version', QQR_DB_VERSION);
}
register_activation_hook(__FILE__, 'qqr_create_table');

// Covers sites where the file was updated without re-activating the plugin.
function qqr_maybe_upgrade_db() {
    if (get_option('qqr_db_version') !== QQR_DB_VERSION) {
        qqr_create_table();
    }
}
add_action('plugins_loaded', 'qqr_maybe_upgrade_db');

/* -------------------------------------------------------------------------
 * Shortcode: front-end form
 * ---------------------------------------------------------------------- */

function qqr_status_messages() {
    return array(
        'sent'      => __('Thanks. Your quote request has been sent.', 'quick-quote-requests'),
        'invalid'   => __('Please check the form. Name, a valid email address and a message of up to 5000 characters are required.', 'quick-quote-requests'),
        'throttled' => __('Too many requests from your connection. Please try again later.', 'quick-quote-requests'),
        'expired'   => __('Your session expired. Please submit the form again.', 'quick-quote-requests'),
        'error'     => __('Your request could not be saved. Please try again.', 'quick-quote-requests'),
    );
}

function qqr_render_form_shortcode($atts) {
    $messages = qqr_status_messages();
    $notice   = '';

    // Status comes back on the query string after the redirect. Only whitelisted keys are shown.
    if (isset($_GET['qqr_status'])) {
        $status = sanitize_key(wp_unslash($_GET['qqr_status']));
        if (isset($messages[$status])) {
            $class  = ($status === 'sent') ? 'qqr-notice qqr-notice-success' : 'qqr-notice qqr-notice-error';
            $notice = sprintf('<p class="%s" role="status">%s</p>', esc_attr($class), esc_html($messages[$status]));
        }
    }

    // Unique element IDs when the shortcode appears more than once on a page.
    static $instance = 0;
    $instance++;
    $id = 'qqr-' . $instance . '-';

    ob_start();
    ?>
    <div class="qqr-quote-form">
        <?php echo $notice; // Built and escaped above. ?>
        <form class="qqr-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="qqr_submit">
            <?php wp_nonce_field('qqr_submit', 'qqr_nonce'); ?>

            <p class="qqr-hp" style="position:absolute;left:-9999px;" aria-hidden="true">
                <label>
                    <?php esc_html_e('Leave this field empty', 'quick-quote-requests'); ?>
                    <input type="text" name="qqr_website" value="" tabindex="-1" autocomplete="off">
                </label>
            </p>

            <p>
                <label for="<?php echo esc_attr($id); ?>name">
                    <?php esc_html_e('Name', 'quick-quote-requests'); ?>
                </label><br>
                <input type="text" id="<?php echo esc_attr($id); ?>name" name="qqr_name"
                       maxlength="100" autocomplete="name" required>
            </p>

            <p>
                <label for="<?php echo esc_attr($id); ?>email">
                    <?php esc_html_e('Email', 'quick-quote-requests'); ?>
                </label><br>
                <input type="email" id="<?php echo esc_attr($id); ?>email" name="qqr_email"
                       maxlength="190" autocomplete="email" required>
            </p>

            <p>
                <label for="<?php echo esc_attr($id); ?>message">
                    <?php esc_html_e('Message', 'quick-quote-requests'); ?>
                </label><br>
                <textarea id="<?php echo esc_attr($id); ?>message" name="qqr_message"
                          rows="6" maxlength="5000" required></textarea>
            </p>

            <p>
                <button type="submit"><?php esc_html_e('Send request', 'quick-quote-requests'); ?></button>
            </p>
        </form>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('quote_request_form', 'qqr_render_form_shortcode');

/* -------------------------------------------------------------------------
 * Form submission (admin-post.php, logged-in and logged-out)
 * ---------------------------------------------------------------------- */

function qqr_redirect_with_status($url, $status) {
    wp_safe_redirect(add_query_arg('qqr_status', $status, $url));
    exit;
}

function qqr_handle_submission() {
    $fallback = home_url('/');
    $redirect = wp_get_referer();
    $redirect = $redirect ? remove_query_arg('qqr_status', $redirect) : $fallback;
    $redirect = wp_validate_redirect($redirect, $fallback);

    // 1. Nonce.
    $nonce = isset($_POST['qqr_nonce']) ? sanitize_text_field(wp_unslash($_POST['qqr_nonce'])) : '';
    if (!wp_verify_nonce($nonce, 'qqr_submit')) {
        qqr_redirect_with_status($redirect, 'expired');
    }

    // 2. Rate limit per IP (hashed key, so no raw IP lands in the transient name).
    $ip  = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
    $key = 'qqr_rl_' . md5($ip);
    $hits = (int) get_transient($key);
    if ($hits >= QQR_RATE_LIMIT) {
        qqr_redirect_with_status($redirect, 'throttled');
    }
    set_transient($key, $hits + 1, QQR_RATE_WINDOW);

    // 3. Honeypot: bots fill the hidden field. Report success without saving.
    if (isset($_POST['qqr_website']) && $_POST['qqr_website'] !== '') {
        qqr_redirect_with_status($redirect, 'sent');
    }

    // 4. Sanitise and validate.
    $name    = isset($_POST['qqr_name']) ? sanitize_text_field(wp_unslash($_POST['qqr_name'])) : '';
    $email   = isset($_POST['qqr_email']) ? sanitize_email(wp_unslash($_POST['qqr_email'])) : '';
    $message = isset($_POST['qqr_message']) ? sanitize_textarea_field(wp_unslash($_POST['qqr_message'])) : '';

    if (
        $name === '' || mb_strlen($name) > 100 ||
        !is_email($email) || mb_strlen($email) > 190 ||
        $message === '' || mb_strlen($message) > 5000
    ) {
        qqr_redirect_with_status($redirect, 'invalid');
    }

    // 5. Save. Prepared by $wpdb->insert; created_at is stored in GMT.
    global $wpdb;
    $saved = $wpdb->insert(
        qqr_table_name(),
        array(
            'name'       => $name,
            'email'      => $email,
            'message'    => $message,
            'created_at' => current_time('mysql', true),
        ),
        array('%s', '%s', '%s', '%s')
    );

    if (false === $saved) {
        qqr_redirect_with_status($redirect, 'error');
    }

    qqr_redirect_with_status($redirect, 'sent');
}
add_action('admin_post_qqr_submit', 'qqr_handle_submission');
add_action('admin_post_nopriv_qqr_submit', 'qqr_handle_submission');

/* -------------------------------------------------------------------------
 * Admin page under Tools
 * ---------------------------------------------------------------------- */

function qqr_register_admin_page() {
    add_management_page(
        __('Quote Requests', 'quick-quote-requests'),
        __('Quote Requests', 'quick-quote-requests'),
        'manage_options',
        'quick-quote-requests',
        'qqr_render_admin_page'
    );
}
add_action('admin_menu', 'qqr_register_admin_page');

function qqr_render_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to view this page.', 'quick-quote-requests'));
    }

    global $wpdb;

    $table    = qqr_table_name();
    $per_page = 20;
    $paged    = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;

    $total       = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
    $total_pages = max(1, (int) ceil($total / $per_page));
    $paged       = min($paged, $total_pages);
    $offset      = ($paged - 1) * $per_page;

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, name, email, message, created_at FROM $table ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
        $per_page,
        $offset
    ));

    $date_format = get_option('date_format') . ' ' . get_option('time_format');

    echo '<div class="wrap">';
    echo '<h1>' . esc_html(get_admin_page_title()) . '</h1>';
    echo '<p>' . esc_html(sprintf(
        /* translators: %s: number of quote requests */
        __('Total requests: %s', 'quick-quote-requests'),
        number_format_i18n($total)
    )) . '</p>';

    if (empty($rows)) {
        echo '<p>' . esc_html__('No quote requests yet.', 'quick-quote-requests') . '</p>';
    } else {
        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th scope="col">' . esc_html__('Received', 'quick-quote-requests') . '</th>';
        echo '<th scope="col">' . esc_html__('Name', 'quick-quote-requests') . '</th>';
        echo '<th scope="col">' . esc_html__('Email', 'quick-quote-requests') . '</th>';
        echo '<th scope="col">' . esc_html__('Message', 'quick-quote-requests') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $received = get_date_from_gmt($row->created_at, $date_format);
            echo '<tr>';
            echo '<td>' . esc_html($received) . '</td>';
            echo '<td>' . esc_html($row->name) . '</td>';
            echo '<td><a href="' . esc_url('mailto:' . $row->email) . '">' . esc_html($row->email) . '</a></td>';
            echo '<td>' . nl2br(esc_html($row->message)) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    if ($total_pages > 1) {
        echo '<div class="tablenav"><div class="tablenav-pages">';
        echo paginate_links(array(
            'base'      => add_query_arg('paged', '%#%'),
            'format'    => '',
            'current'   => $paged,
            'total'     => $total_pages,
            'prev_text' => __('&laquo;', 'quick-quote-requests'),
            'next_text' => __('&raquo;', 'quick-quote-requests'),
        ));
        echo '</div></div>';
    }

    echo '</div>';
}

/* -------------------------------------------------------------------------
 * REST API: GET /wp-json/quick-quote-requests/v1/requests
 * Admin only. Use an Application Password or a logged-in admin session.
 * ---------------------------------------------------------------------- */

function qqr_register_rest_routes() {
    register_rest_route('quick-quote-requests/v1', '/requests', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'qqr_rest_get_requests',
        'permission_callback' => 'qqr_rest_permissions',
        'args'                => array(
            'page'     => array(
                'type'              => 'integer',
                'default'           => 1,
                'minimum'           => 1,
                'sanitize_callback' => 'absint',
            ),
            'per_page' => array(
                'type'              => 'integer',
                'default'           => 20,
                'minimum'           => 1,
                'maximum'           => 100,
                'sanitize_callback' => 'absint',
            ),
        ),
    ));
}
add_action('rest_api_init', 'qqr_register_rest_routes');

function qqr_rest_permissions() {
    return current_user_can('manage_options');
}

function qqr_rest_get_requests(WP_REST_Request $request) {
    global $wpdb;

    $table    = qqr_table_name();
    $page     = max(1, (int) $request->get_param('page'));
    $per_page = min(100, max(1, (int) $request->get_param('per_page')));

    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
    $rows  = $wpdb->get_results($wpdb->prepare(
        "SELECT id, name, email, message, created_at FROM $table ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
        $per_page,
        ($page - 1) * $per_page
    ));

    $items = array_map(function ($row) {
        return array(
            'id'           => (int) $row->id,
            'name'         => $row->name,
            'email'        => $row->email,
            'message'      => $row->message,
            'submitted_at' => gmdate('c', strtotime($row->created_at . ' UTC')), // ISO 8601, GMT
        );
    }, $rows);

    $response = new WP_REST_Response($items, 200);
    $response->header('X-WP-Total', $total);
    $response->header('X-WP-TotalPages', (int) ceil($total / $per_page));
    $response->header('Cache-Control', 'private, no-store');

    return $response;
}
