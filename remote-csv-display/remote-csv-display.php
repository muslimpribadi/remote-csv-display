<?php
/**
 * Plugin Name:       Remote CSV Data Display for SIMPANG.ID
 * Plugin URI:        https://github.com/muslimpribadi/remote-csv-display
 * Description:       Fetches, caches, and displays data from a remote CSV file using a shortcode with sortable and paginated table.
 * Version:           1.1.0
 * Author:            Mpribadi, Gemini
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

register_deactivation_hook(__FILE__, 'rcd_cleanup_on_deactivation');

/**
 * Handles the logic for the [remote_csv_display] shortcode.
 *
 * @param array $atts Shortcode attributes.
 * @return string The HTML output for the table or an error comment.
 */
function rcd_shortcode_handler(array $atts): string {
    $atts = shortcode_atts(
        [
            'url'  => 'https://raw.githubusercontent.com/digitalpunchid/harga_komoditas_pangan/refs/heads/main/harga_komoditas_konsumen_kota_bandung.csv',
            'hide' => '',
        ],
        $atts,
        'remote_csv_display'
    );

    $csv_url = esc_url_raw($atts['url']);

    if (!rcd_is_safe_remote_url($csv_url)) {
        return '<!-- Invalid or disallowed URL. Requests to local or private networks are blocked. -->';
    }

    $cache_key = 'rcd_cache_' . md5($csv_url);
    $cached_data = get_transient($cache_key);

    $should_fetch_new_data = false;
    try {
        $timezone = new DateTimeZone('Asia/Jakarta'); // GMT+7
        $now = new DateTime('now', $timezone);
        $update_time_today = (new DateTime('today 13:30', $timezone));
        $last_fetch_timestamp = (int) get_option('rcd_last_fetch_timestamp_' . md5($csv_url), 0);
        $last_fetch_datetime = (new DateTime())->setTimestamp($last_fetch_timestamp)->setTimezone($timezone);

        if ($now > $update_time_today && $last_fetch_datetime < $update_time_today) {
            $should_fetch_new_data = true;
        }
    } catch (Exception $e) {
        error_log('Remote CSV Display Plugin Error: ' . $e->getMessage());
        return '<!-- Timezone or Date calculation error. -->';
    }

    if ($cached_data !== false && !$should_fetch_new_data) {
        $data = $cached_data;
    } else {
        $response = wp_remote_get($csv_url, ['timeout' => 20]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            if (is_wp_error($response)) {
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

    return rcd_render_html_table($data, (string) $atts['hide']);
}

/**
 * Security Helper: Validates a URL to prevent SSRF and other request vulnerabilities.
 */
function rcd_is_safe_remote_url(string $url): bool {
    if (empty($url)) {
        return false;
    }
    $parsed_url = wp_parse_url($url);
    if (!isset($parsed_url['scheme']) || !in_array($parsed_url['scheme'], ['http', 'https'], true)) {
        return false;
    }
    if (!isset($parsed_url['host'])) {
        return false;
    }
    return wp_http_validate_url($url);
}

/**
 * Renders the parsed CSV data into an interactive HTML table with sorting and pagination.
 */
function rcd_render_html_table(array $data, string $hide_columns): string {
    if (empty($data['header']) || !isset($data['rows'])) {
        return '';
    }

    $table_id = 'rcd-table-' . uniqid();
    $header = $data['header'];
    $rows = $data['rows'];

    $hidden_columns_names = array_map('trim', explode(',', strtolower($hide_columns)));
    $hidden_indices = [];
    $visible_header = [];
    foreach ($header as $index => $title) {
        if (in_array(strtolower($title), $hidden_columns_names)) {
            $hidden_indices[] = $index;
        } else {
            $visible_header[] = $title;
        }
    }

    // Filter rows to only include visible columns
    $visible_rows = [];
    foreach($rows as $row) {
        $visible_row = [];
        foreach($row as $index => $cell) {
            if (!in_array($index, $hidden_indices, true)) {
                $visible_row[] = $cell;
            }
        }
        $visible_rows[] = $visible_row;
    }

    ob_start();
    ?>
    <style>
        .rcd-container { font-family: sans-serif; }
        .rcd-controls { display: flex; justify-content: space-between; align-items: center; margin: 1em 0; flex-wrap: wrap; gap: 10px; }
        .rcd-controls label, .rcd-controls .rcd-pagination-nav { font-size: 0.9em; }
        .rcd-controls select, .rcd-controls button { padding: 5px 8px; border-radius: 4px; border: 1px solid #ccc; background-color: #f9f9f9; cursor: pointer; }
        .rcd-controls button:disabled { cursor: not-allowed; opacity: 0.5; }
        .rcd-pagination-nav span { margin: 0 5px; }
        .rcd-table-wrapper { overflow-x: auto; margin: 1.5em 0; }
        .rcd-table { width: 100%; border-collapse: collapse; border: 1px solid #ddd; }
        .rcd-table th, .rcd-table td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #ddd; }
        .rcd-table thead th { background-color: #f2f2f2; color: #333; font-weight: bold; cursor: pointer; user-select: none; position: relative; }
        .rcd-table thead th.sortable::after { content: ''; position: absolute; right: 8px; top: 50%; transform: translateY(-50%); border: 4px solid transparent; }
        .rcd-table thead th.sort-asc::after { border-bottom-color: #333; }
        .rcd-table thead th.sort-desc::after { border-top-color: #333; }
        .rcd-table tbody tr:nth-of-type(even) { background-color: #f9f9f9; }
        .rcd-table tbody tr:hover { background-color: #f1f1f1; }
    </style>

    <div class="rcd-container" id="rcd-container-<?php echo esc_attr($table_id); ?>">
        <!-- Pagination controls will be injected here -->
        <div class="rcd-table-wrapper">
            <table class="rcd-table" id="<?php echo esc_attr($table_id); ?>">
                <thead>
                    <tr>
                        <?php foreach ($visible_header as $title) : ?>
                            <th class="sortable"><?php echo esc_html($title); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php /* Rows will be loaded by JavaScript for efficiency */ ?>
                </tbody>
            </table>
        </div>
         <!-- And here -->
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const tableId = '<?php echo esc_js($table_id); ?>';
        const container = document.getElementById('rcd-container-' + tableId);
        const table = document.getElementById(tableId);
        const tbody = table.querySelector('tbody');

        // This is a large dataset passed from PHP.
        // For very large files, this can be slow initially, but makes UI interaction fast.
        const allRowsData = <?php echo json_encode($visible_rows); ?>;

        let currentPage = 1;
        let rowsPerPage = 15;
        let sortColumn = -1;
        let sortDirection = 'asc';

        function createControls() {
            return `
            <div class="rcd-controls">
                <div>
                    <label for="rows-per-page-${tableId}">Show </label>
                    <select id="rows-per-page-${tableId}" class="rcd-rows-per-page">
                        <option value="15" ${rowsPerPage === 15 ? 'selected' : ''}>15</option>
                        <option value="25" ${rowsPerPage === 25 ? 'selected' : ''}>25</option>
                        <option value="50" ${rowsPerPage === 50 ? 'selected' : ''}>50</option>
                        <option value="100" ${rowsPerPage === 100 ? 'selected' : ''}>100</option>
                    </select>
                    <span> entries</span>
                </div>
                <div class="rcd-pagination-nav">
                    <button class="rcd-prev-page">&laquo; Previous</button>
                    <span class="rcd-page-info"></span>
                    <button class="rcd-next-page">Next &raquo;</button>
                </div>
            </div>
            `;
        }

        function renderTable() {
            tbody.innerHTML = '';
            const start = (currentPage - 1) * rowsPerPage;
            const end = start + rowsPerPage;
            const paginatedItems = allRowsData.slice(start, end);

            paginatedItems.forEach(rowData => {
                const tr = document.createElement('tr');
                rowData.forEach(cellData => {
                    const td = document.createElement('td');
                    td.textContent = cellData;
                    tr.appendChild(td);
                });
                tbody.appendChild(tr);
            });
            updatePaginationInfo();
        }

        function updatePaginationInfo() {
            const pageInfoElements = container.querySelectorAll('.rcd-page-info');
            const totalPages = Math.ceil(allRowsData.length / rowsPerPage);
            pageInfoElements.forEach(el => el.textContent = `Page ${currentPage} of ${totalPages}`);

            const prevButtons = container.querySelectorAll('.rcd-prev-page');
            const nextButtons = container.querySelectorAll('.rcd-next-page');
            prevButtons.forEach(btn => btn.disabled = currentPage === 1);
            nextButtons.forEach(btn => btn.disabled = currentPage === totalPages || totalPages === 0);
        }

        function sortData(columnIndex) {
            const direction = sortColumn === columnIndex && sortDirection === 'asc' ? 'desc' : 'asc';

            allRowsData.sort((a, b) => {
                let valA = a[columnIndex];
                let valB = b[columnIndex];

                // Basic numeric check
                const isNumeric = !isNaN(parseFloat(valA)) && isFinite(valA) && !isNaN(parseFloat(valB)) && isFinite(valB);

                if (isNumeric) {
                    return direction === 'asc' ? valA - valB : valB - valA;
                } else {
                     return direction === 'asc'
                        ? valA.toString().localeCompare(valB.toString())
                        : valB.toString().localeCompare(valA.toString());
                }
            });

            sortColumn = columnIndex;
            sortDirection = direction;

            // Update header classes
            table.querySelectorAll('thead th').forEach((th, i) => {
                th.classList.remove('sort-asc', 'sort-desc');
                if (i === columnIndex) {
                    th.classList.add(`sort-${direction}`);
                }
            });

            currentPage = 1;
            renderTable();
        }

        // Initial setup
        const controlsHTML = createControls();
        container.insertAdjacentHTML('afterbegin', controlsHTML);
        container.insertAdjacentHTML('beforeend', controlsHTML);

        renderTable();

        // Event Listeners
        container.querySelectorAll('.rcd-rows-per-page').forEach(select => {
            select.addEventListener('change', (e) => {
                rowsPerPage = parseInt(e.target.value, 10);
                currentPage = 1;
                // Sync other select
                container.querySelectorAll('.rcd-rows-per-page').forEach(s => s.value = rowsPerPage);
                renderTable();
            });
        });

        container.querySelectorAll('.rcd-prev-page').forEach(btn => {
            btn.addEventListener('click', () => {
                if (currentPage > 1) {
                    currentPage--;
                    renderTable();
                }
            });
        });

        container.querySelectorAll('.rcd-next-page').forEach(btn => {
            btn.addEventListener('click', () => {
                const totalPages = Math.ceil(allRowsData.length / rowsPerPage);
                if (currentPage < totalPages) {
                    currentPage++;
                    renderTable();
                }
            });
        });

        table.querySelectorAll('thead th').forEach((th, i) => {
            th.addEventListener('click', () => sortData(i));
        });
    });
    </script>
    <?php
    return ob_get_clean();
}

/**
 * Cleans up plugin data upon deactivation.
 */
function rcd_cleanup_on_deactivation(): void {
    global $wpdb;
    $transient_prefix = '_transient_rcd_cache_';
    $timeout_prefix = '_transient_timeout_rcd_cache_';
    $option_prefix = 'rcd_last_fetch_timestamp_';

    $sql = "
        DELETE FROM {$wpdb->options}
        WHERE option_name LIKE %s
           OR option_name LIKE %s
           OR option_name LIKE %s
    ";

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
define('RCD_UPDATE_URL', 'https://raw.githubusercontent.com/muslimpribadi/remote-csv-display/refs/heads/main/update.json');

function rcd_check_for_updates(object $transient): object {
    if (empty($transient->checked)) return $transient;
    if (strpos(RCD_UPDATE_URL, 'https://') !== 0) return $transient;

    $plugin_data = get_plugin_data(RCD_PLUGIN_FILE);
    $current_version = $plugin_data['Version'];

    $response = wp_remote_get(RCD_UPDATE_URL, ['timeout' => 10, 'headers' => ['Accept' => 'application/json']]);
    if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) return $transient;

    $remote_info = json_decode(wp_remote_retrieve_body($response));
    if (!$remote_info || !isset($remote_info->version) || !isset($remote_info->download_url)) return $transient;
    if (!rcd_is_safe_remote_url($remote_info->download_url) || strpos($remote_info->download_url, 'https://') !== 0) return $transient;

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

function rcd_plugins_api_filter(false|object|array $result, string $action = '', ?object $args = null): false|object|array {
    if ($action !== 'plugin_information' || !isset($args->slug) || $args->slug !== RCD_PLUGIN_SLUG) return $result;

    $response = wp_remote_get(RCD_UPDATE_URL, ['timeout' => 10, 'headers' => ['Accept' => 'application/json']]);
    if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) return $result;

    $remote_info = json_decode(wp_remote_retrieve_body($response));
    if (!$remote_info) return $result;

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

