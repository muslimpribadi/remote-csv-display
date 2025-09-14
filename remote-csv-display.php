<?php
/**
 * Plugin Name:       Remote CSV Data Display for SIMPANG.ID
 * Plugin URI:        https://github.com/muslimpribadi/remote-csv-display
 * Description:       Fetches, caches, and displays data from a remote CSV file using a shortcode.
 * Version:           1.0.2
 * Author:            Gemini, Mpribadi
 * Author URI:        https://www.simpang.id/penulis/muslim-pribadi/
 * Requires PHP:      8.2
 * Text Domain:       remote-csv-display
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Registers the shortcode [remote_csv_display] for use in posts and pages.
 */
function rcd_register_shortcode(): void {
    add_shortcode('remote_csv_display', 'rcd_shortcode_handler');
}
add_action('init', 'rcd_register_shortcode');

// --- NEW: Register plugin deactivation hook for cleanup ---
register_deactivation_hook(__FILE__, 'rcd_cleanup_on_deactivation');


/**
 * Handles the logic for the [remote_csv_display] shortcode.
 *
 * @param array $atts Shortcode attributes.
 * @return string The HTML output for the table or an error comment.
 */
function rcd_shortcode_handler(array $atts): string {
    // 1. Process Shortcode Attributes
    $atts = shortcode_atts(
        [
            'url'  => 'https://raw.githubusercontent.com/digitalpunchid/harga_komoditas_pangan/refs/heads/main/harga_komoditas_konsumen_kota_bandung.csv', // Default public CSV
            'hide' => '',
        ],
        $atts,
        'remote_csv_display'
    );

    $csv_url = esc_url_raw($atts['url']);

    // Security: Validate the URL to prevent SSRF attacks.
    if (!rcd_is_safe_remote_url($csv_url)) {
        return '<!-- Invalid or disallowed URL. Requests to local or private networks are blocked. -->';
    }

    // Prepare a unique key for caching based on the URL
    $cache_key = 'rcd_cache_' . md5($csv_url);
    $cached_data = get_transient($cache_key);

    // 2. Conditional Fetching & Freshness Logic
    $should_fetch_new_data = false;
    try {
        $timezone = new DateTimeZone('Asia/Jakarta'); // GMT+7
        $now = new DateTime('now', $timezone);
        $update_time_today = (new DateTime('today 13:40', $timezone));
        $last_fetch_timestamp = (int) get_option('rcd_last_fetch_timestamp_' . md5($csv_url), 0);
        $last_fetch_datetime = (new DateTime())->setTimestamp($last_fetch_timestamp)->setTimezone($timezone);

        if ($now > $update_time_today && $last_fetch_datetime < $update_time_today) {
            $should_fetch_new_data = true;
        }
    } catch (Exception $e) {
        // Log the error for admin, but show a generic message to the user.
        error_log('Remote CSV Display Plugin Error: ' . $e->getMessage());
        return '<!-- Timezone or Date calculation error. -->';
    }


    // 3. Caching and Data Retrieval Flow
    if ($cached_data !== false && !$should_fetch_new_data) {
        $data = $cached_data;
    } else {
        $response = wp_remote_get($csv_url, ['timeout' => 20]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            // Log the detailed error for debugging.
            if(is_wp_error($response)) {
                 error_log('Remote CSV Fetch Error: ' . $response->get_error_message());
            }
            return '<!-- CSV data could not be retrieved at this time. -->';
        }

        $csv_body = wp_remote_retrieve_body($response);
        if (empty($csv_body)) {
            return '<!-- Fetched CSV file is empty. -->';
        }

        $lines = explode("\n", trim($csv_body));
        $header = str_getcsv(array_shift($lines));
        $rows = [];
        foreach ($lines as $line) {
             if (!empty(trim($line))) {
                $rows[] = str_getcsv($line);
             }
        }

        if (empty($header) || empty($rows)) {
            return '<!-- CSV data is malformed or has no content. -->';
        }

        $data = ['header' => $header, 'rows' => $rows];

        set_transient($cache_key, $data, DAY_IN_SECONDS);
        update_option('rcd_last_fetch_timestamp_' . md5($csv_url), time());
    }

    // 4. Generate HTML Output
    return rcd_render_html_table($data, (string) $atts['hide']);
}

/**
 * Security Helper: Validates a URL to prevent SSRF and other request vulnerabilities.
 *
 * @param string $url The URL to check.
 * @return bool True if the URL is safe, false otherwise.
 */
function rcd_is_safe_remote_url(string $url): bool {
    if (empty($url)) {
        return false;
    }

    $parsed_url = wp_parse_url($url);

    // We only want to allow http and https protocols.
    if (!isset($parsed_url['scheme']) || !in_array($parsed_url['scheme'], ['http', 'https'], true)) {
        return false;
    }

    // Ensure there is a host.
    if (!isset($parsed_url['host'])) {
        return false;
    }

    // Use WordPress's built-in function to check for local/private IPs.
    return wp_http_validate_url($url);
}

/**
 * Renders the parsed CSV data into an HTML table.
 *
 * @param array $data Contains 'header' and 'rows' keys.
 * @param string $hide_columns A comma-separated string of column headers to hide.
 * @return string The complete HTML table as a string.
 */
function rcd_render_html_table(array $data, string $hide_columns): string {
    if (empty($data['header']) || !isset($data['rows'])) {
        return '';
    }

    $header = $data['header'];
    $rows = $data['rows'];

    $hidden_columns_names = array_map('trim', explode(',', strtolower($hide_columns)));
    $hidden_indices = [];
    foreach ($header as $index => $title) {
        if (in_array(strtolower($title), $hidden_columns_names)) {
            $hidden_indices[] = $index;
        }
    }

    ob_start();
    ?>
    <style>
        .rcd-table-wrapper { overflow-x: auto; margin: 1.5em 0; }
        .rcd-table { width: 100%; border-collapse: collapse; font-family: sans-serif; border: 1px solid #ddd; }
        .rcd-table th, .rcd-table td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #ddd; }
        .rcd-table thead th { background-color: #f2f2f2; color: #333; font-weight: bold; }
        .rcd-table tbody tr:nth-of-type(even) { background-color: #f9f9f9; }
        .rcd-table tbody tr:hover { background-color: #f1f1f1; }
    </style>
    <div class="rcd-table-wrapper">
        <table class="rcd-table">
            <thead>
                <tr>
                    <?php foreach ($header as $index => $title) : ?>
                        <?php if (!in_array($index, $hidden_indices, true)) : ?>
                            <th><?php echo esc_html($title); ?></th>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row) : ?>
                    <tr>
                        <?php foreach ($row as $index => $cell) : ?>
                            <?php if (!in_array($index, $hidden_indices, true)) : ?>
                                <td><?php echo esc_html($cell); ?></td>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * --- NEW: Cleans up plugin data upon deactivation. ---
 * Deletes all transients and options created by the plugin to keep the database clean.
 */
function rcd_cleanup_on_deactivation(): void {
    global $wpdb;

    // Define the prefixes for the data we need to clean up.
    // Note: WordPress prefixes transients with '_transient_' and '_transient_timeout_'.
    $transient_prefix = '_transient_rcd_cache_';
    $timeout_prefix = '_transient_timeout_rcd_cache_';
    $option_prefix = 'rcd_last_fetch_timestamp_';

    // Since options and transients can be created for any URL, we must use a LIKE
    // query to find and delete them all. This is the most efficient way to clean up.
    $sql = "
        DELETE FROM {$wpdb->options}
        WHERE option_name LIKE %s
           OR option_name LIKE %s
           OR option_name LIKE %s
    ";

    // Prepare and execute the query safely.
    $wpdb->query(
        $wpdb->prepare(
            $sql,
            $wpdb->esc_like($transient_prefix) . '%',
            $wpdb->esc_like($timeout_prefix) . '%',
            $wpdb->esc_like($option_prefix) . '%'
        )
    );
}


// --- START: SELF-HOSTED PLUGIN UPDATER (HARDENED) ---

define('RCD_PLUGIN_FILE', __FILE__);
define('RCD_PLUGIN_SLUG', 'remote-csv-display');
define('RCD_UPDATE_URL', 'https://github.com/muslimpribadi/remote-csv-display/dist//update.json');

/**
 * Checks for plugin updates.
 *
 * @param object $transient The update transient.
 * @return object The modified transient.
 */
function rcd_check_for_updates(object $transient): object {
    if (empty($transient->checked)) {
        return $transient;
    }

    // Security: Ensure the update URL is using HTTPS.
    if (strpos(RCD_UPDATE_URL, 'https://') !== 0) {
        return $transient;
    }

    $plugin_data = get_plugin_data(RCD_PLUGIN_FILE);
    $current_version = $plugin_data['Version'];

    $response = wp_remote_get(RCD_UPDATE_URL, ['timeout' => 10, 'headers' => ['Accept' => 'application/json']]);

    if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
        return $transient;
    }

    $remote_info = json_decode(wp_remote_retrieve_body($response));

    if (!$remote_info || !isset($remote_info->version) || !isset($remote_info->download_url)) {
        return $transient; // Bail if JSON is invalid or missing required fields.
    }

    // Security: Validate the download URL from the manifest.
    if (!rcd_is_safe_remote_url($remote_info->download_url) || strpos($remote_info->download_url, 'https://') !== 0) {
        return $transient; // Only allow secure download links.
    }

    if (version_compare($current_version, $remote_info->version, '<')) {
        $plugin_info = new stdClass();
        $plugin_info->slug = RCD_PLUGIN_SLUG;
        $plugin_info->new_version = $remote_info->version;
        $plugin_info->url = $remote_info->sections->description ?? '';
        $plugin_info->package = $remote_info->download_url;
        $transient->response[plugin_basename(RCD_PLUGIN_FILE)] = $plugin_info;
    }

    return $transient;
}
add_filter('pre_set_site_transient_update_plugins', 'rcd_check_for_updates');

/**
 * Provides plugin information for the "View details" popup.
 *
 * @param false|object|array $result The result object.
 * @param string             $action The API action.
 * @param object             $args   Arguments for the API call.
 * @return false|object The plugin info object or false.
 */
function rcd_plugins_api_filter(false|object|array $result, string $action = '', ?object $args = null): false|object|array {
    if ($action !== 'plugin_information' || !isset($args->slug) || $args->slug !== RCD_PLUGIN_SLUG) {
        return $result;
    }

    $response = wp_remote_get(RCD_UPDATE_URL, ['timeout' => 10, 'headers' => ['Accept' => 'application/json']]);

    if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
        return $result;
    }

    $remote_info = json_decode(wp_remote_retrieve_body($response));

    if (!$remote_info) {
        return $result;
    }

    $plugin_info = new stdClass();
    $plugin_info->name = $remote_info->name ?? '';
    $plugin_info->slug = $remote_info->slug ?? '';
    $plugin_info->version = $remote_info->version ?? '';
    $plugin_info->author = $remote_info->author ?? '';
    $plugin_info->requires = $remote_info->requires ?? '';
    $plugin_info->tested = $remote_info->tested ?? '';
    $plugin_info->download_link = $remote_info->download_url ?? '';
    $plugin_info->trunk = $remote_info->download_url ?? '';
    $plugin_info->sections = isset($remote_info->sections) ? (array) $remote_info->sections : [];
    $plugin_info->banners = isset($remote_info->banners) ? (array) $remote_info->banners : [];

    return $plugin_info;
}
add_filter('plugins_api', 'rcd_plugins_api_filter', 10, 3);

// --- END: SELF-HOSTED PLUGIN UPDATER (HARDENED) ---
