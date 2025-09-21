<?php
/**
 * Plugin Name:       Remote CSV Data Display for SIMPANG.ID
 * Plugin URI:        https://github.com/muslimpribadi/remote-csv-display
 * Description:       Fetches, caches, and displays data from a remote CSV file using a shortcode [remote_csv_display] with sortable and paginated table.
 * Version:           1.2.0
 * Author:            Mpribadi, Gemini
 * Author URI:        https://www.simpang.id/penulis/muslim-pribadi/
 * Requires PHP:      8.2
 * Text Domain:       remote-csv-display
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

add_action('init', 'rcd_register_shortcode');
register_deactivation_hook(__FILE__, 'rcd_cleanup_on_deactivation');

/**
 * Registers the shortcode and assets.
 */
function rcd_register_shortcode(): void {
    add_shortcode('remote_csv_display', 'rcd_shortcode_handler');
}

/**
 * Enqueues scripts and styles needed for the shortcode output.
 */
function rcd_enqueue_assets(bool $is_timeline_view = false): void {
    static $styles_enqueued = false;
    if (!$styles_enqueued) {
        wp_enqueue_style(
            'rcd-styles',
            plugin_dir_url(__FILE__) . 'style.min.css',
            [],
            '1.2.0' // Cache busting
        );
        $styles_enqueued = true;
    }

    if ($is_timeline_view) {
        static $chartjs_enqueued = false;
        if (!$chartjs_enqueued) {
            // Note: The prompt requested 4.5.0, but as of development, 4.4.3 is the latest stable version on the CDN.
            // Using 4.4.3 to ensure functionality. This can be updated when 4.5.0 is released.
            wp_enqueue_script(
                'chartjs',
                'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js',
                [],
                '4.4.3',
                true // Load in footer
            );
            $chartjs_enqueued = true;
        }
    }
}

/**
 * Handles the logic for the [remote_csv_display] shortcode.
 */
function rcd_shortcode_handler(array $atts): string {
    $atts = shortcode_atts(
        [
            'url'               => 'https://raw.githubusercontent.com/digitalpunchid/harga_komoditas_pangan/refs/heads/main/harga_komoditas_konsumen_kota_bandung.csv',
            'hide'              => '',
            'grouped-timeline'  => '',
        ],
        $atts,
        'remote_csv_display'
    );

    $csv_url = esc_url_raw($atts['url']);

    if (!rcd_is_safe_remote_url($csv_url)) {
        return '<!-- Invalid or disallowed URL. -->';
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
            return '<!-- CSV data could not be retrieved. -->';
        }

        $csv_body = wp_remote_retrieve_body($response);
        if (empty($csv_body)) return '<!-- Fetched CSV file is empty. -->';

        $lines = explode("\n", trim($csv_body));
        $header = str_getcsv(array_shift($lines));

        // --- NEW: Limit to last 1500 rows for performance ---
        if (count($lines) > 1500) {
            $lines = array_slice($lines, -1500);
        }

        $rows = [];
        foreach ($lines as $line) {
            if (!empty(trim($line))) {
                $row_data = str_getcsv($line);
                if(count($row_data) === count($header)){
                    $rows[] = $row_data;
                }
            }
        }

        if (empty($header) || empty($rows)) return '<!-- CSV data is malformed. -->';

        $data = ['header' => $header, 'rows' => $rows];

        set_transient($cache_key, $data, DAY_IN_SECONDS);
        update_option('rcd_last_fetch_timestamp_' . md5($csv_url), time());
    }

    // --- NEW: Conditional rendering based on shortcode attribute ---
    if (!empty($atts['grouped-timeline'])) {
        rcd_enqueue_assets(true); // Enqueue assets for timeline view
        return rcd_render_timeline_view($data, (string) $atts['grouped-timeline']);
    } else {
        rcd_enqueue_assets(false); // Enqueue assets for table view
        return rcd_render_html_table($data, (string) $atts['hide']);
    }
}

/**
 * NEW: Renders the "Grouped Timeline" view with vertical tabs and lazy-loaded charts.
 */
function rcd_render_timeline_view(array $data, string $timeline_params): string {
    $params = array_map('trim', explode(',', $timeline_params));
    if (count($params) !== 4) return '<!-- Invalid grouped-timeline parameters. Expecting 4 comma-separated values. -->';
    [$id_col, $name_col, $price_col, $unit_col] = $params;

    $header = $data['header'];
    $name_idx = array_search($name_col, $header);
    $price_idx = array_search($price_col, $header);
    $unit_idx = array_search($unit_col, $header);

    if ($name_idx === false || $price_idx === false || $unit_idx === false) {
        return '<!-- One or more column names in grouped-timeline not found in CSV header. -->';
    }

    $grouped_data = [];
    foreach ($data['rows'] as $row) {
        $item_name = $row[$name_idx];
        if (!isset($grouped_data[$item_name])) {
            $grouped_data[$item_name] = [
                'unit' => $row[$unit_idx],
                'records' => []
            ];
        }
        $grouped_data[$item_name]['records'][] = [
            'date' => $row[0], // Assumes date is the first column
            'price' => (float) $row[$price_idx]
        ];
    }

    // Sort records by date descending for each group
    foreach ($grouped_data as $item_name => &$details) {
        usort($details['records'], fn($a, $b) => strcmp($b['date'], $a['date']));
    }
    unset($details);

    $container_id = 'rcd-timeline-' . uniqid();
    ob_start();
    ?>
    <div class="rcd-timeline-container" id="<?php echo esc_attr($container_id); ?>">
        <div class="rcd-timeline-tabs">
            <?php $first = true; foreach (array_keys($grouped_data) as $item_name): ?>
                <button class="rcd-timeline-tab <?php echo $first ? 'active' : ''; ?>" data-target="#tab-<?php echo esc_attr(sanitize_title($item_name)); ?>">
                    <?php echo esc_html($item_name); ?>
                </button>
            <?php $first = false; endforeach; ?>
        </div>
        <div class="rcd-timeline-content-wrapper">
            <?php $first = true; foreach ($grouped_data as $item_name => $details):
                $latest_record = $details['records'][0] ?? null;
                $last_7_days_records = array_slice($details['records'], 0, 7);
                $chart_data = [
                    'labels' => array_reverse(array_column($last_7_days_records, 'date')),
                    'prices' => array_reverse(array_column($last_7_days_records, 'price')),
                    'unit' => $details['unit']
                ];
            ?>
                <div class="rcd-timeline-content <?php echo $first ? 'active' : ''; ?>" id="tab-<?php echo esc_attr(sanitize_title($item_name)); ?>">
                    <?php if ($latest_record): ?>
                        <div class="rcd-latest-price"><?php echo esc_html(number_format($latest_record['price'])); ?></div>
                        <div class="rcd-latest-meta">Latest price on <?php echo esc_html($latest_record['date']); ?></div>
                    <?php endif; ?>
                    <div class="rcd-chart-container">
                        <canvas
                            class="rcd-chart-canvas"
                            data-chart-data="<?php echo esc_attr(json_encode($chart_data)); ?>"
                        ></canvas>
                    </div>
                </div>
            <?php $first = false; endforeach; ?>
        </div>
    </div>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const container = document.getElementById('<?php echo esc_js($container_id); ?>');
        if (!container) return;

        const tabs = container.querySelectorAll('.rcd-timeline-tab');
        const contents = container.querySelectorAll('.rcd-timeline-content');

        const initChart = (canvas) => {
            if (canvas.dataset.chartInitialized === 'true' || typeof Chart === 'undefined') return;

            const chartData = JSON.parse(canvas.dataset.chartData);
            new Chart(canvas.getContext('2d'), {
                type: 'line',
                data: {
                    labels: chartData.labels,
                    datasets: [{
                        label: 'Price',
                        data: chartData.prices,
                        borderColor: '#0073aa',
                        backgroundColor: 'rgba(0, 115, 170, 0.1)',
                        fill: true,
                        tension: 0.1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: { y: { title: { display: true, text: chartData.unit } } }
                }
            });
            canvas.dataset.chartInitialized = 'true';
        };

        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                tabs.forEach(t => t.classList.remove('active'));
                contents.forEach(c => c.classList.remove('active'));

                tab.classList.add('active');
                const targetContent = container.querySelector(tab.dataset.target);
                if (targetContent) {
                    targetContent.classList.add('active');
                    const canvas = targetContent.querySelector('.rcd-chart-canvas');
                    if (canvas) initChart(canvas);
                }
            });
        });

        // Initialize the chart for the initially active tab
        const activeCanvas = container.querySelector('.rcd-timeline-content.active .rcd-chart-canvas');
        if (activeCanvas) {
            // Use a small timeout to ensure Chart.js library has loaded from the footer
            setTimeout(() => initChart(activeCanvas), 100);
        }
    });
    </script>
    <?php
    return ob_get_clean();
}

/**
 * Renders the parsed CSV data into an interactive HTML table with sorting and pagination.
 */
function rcd_render_html_table(array $data, string $hide_columns): string {
    if (empty($data['header']) || !isset($data['rows'])) return '';

    $table_id = 'rcd-table-' . uniqid();
    // Logic from previous version, just without the <style> block
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
    <div class="rcd-container" id="rcd-container-<?php echo esc_attr($table_id); ?>">
        <div class="rcd-table-wrapper">
            <table class="rcd-table" id="<?php echo esc_attr($table_id); ?>">
                <thead>
                    <tr>
                        <?php foreach ($visible_header as $title) : ?>
                            <th class="sortable"><?php echo esc_html($title); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
    <script>
    // JS for table remains unchanged from previous version
    document.addEventListener('DOMContentLoaded', function() {
        const tableId = '<?php echo esc_js($table_id); ?>';
        const container = document.getElementById('rcd-container-' + tableId);
        const table = document.getElementById(tableId);
        const tbody = table.querySelector('tbody');
        const allRowsData = <?php echo json_encode($visible_rows); ?>;
        let currentPage = 1, rowsPerPage = 15, sortColumn = -1, sortDirection = 'asc';

        function createControls() {
            return `<div class="rcd-controls"><div><label for="rows-per-page-${tableId}">Show </label><select id="rows-per-page-${tableId}" class="rcd-rows-per-page"><option value="15">15</option><option value="25">25</option><option value="50">50</option><option value="100">100</option></select><span> entries</span></div><div class="rcd-pagination-nav"><button class="rcd-prev-page">&laquo; Previous</button><span class="rcd-page-info"></span><button class="rcd-next-page">Next &raquo;</button></div></div>`;
        }
        function renderTable() {
            tbody.innerHTML = '';
            const start = (currentPage - 1) * rowsPerPage;
            const end = start + rowsPerPage;
            const paginatedItems = allRowsData.slice(start, end);
            paginatedItems.forEach(rowData => {
                const tr = document.createElement('tr');
                rowData.forEach(cellData => { const td = document.createElement('td'); td.textContent = cellData; tr.appendChild(td); });
                tbody.appendChild(tr);
            });
            updatePaginationInfo();
        }
        function updatePaginationInfo() {
            const totalPages = Math.ceil(allRowsData.length / rowsPerPage);
            container.querySelectorAll('.rcd-page-info').forEach(el => el.textContent = `Page ${currentPage} of ${totalPages}`);
            container.querySelectorAll('.rcd-prev-page').forEach(btn => btn.disabled = currentPage === 1);
            container.querySelectorAll('.rcd-next-page').forEach(btn => btn.disabled = currentPage === totalPages || totalPages === 0);
        }
        function sortData(columnIndex) {
            const direction = sortColumn === columnIndex && sortDirection === 'asc' ? 'desc' : 'asc';
            allRowsData.sort((a, b) => {
                let valA = a[columnIndex], valB = b[columnIndex];
                const isNumeric = !isNaN(parseFloat(valA)) && isFinite(valA) && !isNaN(parseFloat(valB)) && isFinite(valB);
                if (isNumeric) return direction === 'asc' ? valA - valB : valB - valA;
                return direction === 'asc' ? valA.toString().localeCompare(valB.toString()) : valB.toString().localeCompare(valA.toString());
            });
            sortColumn = columnIndex; sortDirection = direction;
            table.querySelectorAll('thead th').forEach((th, i) => {
                th.classList.remove('sort-asc', 'sort-desc');
                if (i === columnIndex) th.classList.add(`sort-${direction}`);
            });
            currentPage = 1; renderTable();
        }
        const controlsHTML = createControls();
        container.insertAdjacentHTML('afterbegin', controlsHTML); container.insertAdjacentHTML('beforeend', controlsHTML);
        renderTable();
        container.querySelectorAll('.rcd-rows-per-page').forEach(select => select.addEventListener('change', (e) => {
            rowsPerPage = parseInt(e.target.value, 10); currentPage = 1;
            container.querySelectorAll('.rcd-rows-per-page').forEach(s => s.value = rowsPerPage); renderTable();
        }));
        container.querySelectorAll('.rcd-prev-page').forEach(btn => btn.addEventListener('click', () => { if (currentPage > 1) { currentPage--; renderTable(); } }));
        container.querySelectorAll('.rcd-next-page').forEach(btn => btn.addEventListener('click', () => { if (currentPage < Math.ceil(allRowsData.length / rowsPerPage)) { currentPage++; renderTable(); } }));
        table.querySelectorAll('thead th').forEach((th, i) => th.addEventListener('click', () => sortData(i)));
    });
    </script>
    <?php
    return ob_get_clean();
}


/**
 * Security Helper: Validates a URL to prevent SSRF and other request vulnerabilities.
 */
function rcd_is_safe_remote_url(string $url): bool {
    if (empty($url)) return false;
    $parsed_url = wp_parse_url($url);
    if (!isset($parsed_url['scheme']) || !in_array($parsed_url['scheme'], ['http', 'https'], true)) return false;
    if (!isset($parsed_url['host'])) return false;
    return wp_http_validate_url($url);
}

/**
 * Cleans up plugin data upon deactivation.
 */
function rcd_cleanup_on_deactivation(): void {
    global $wpdb;
    $sql = "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s";
    $wpdb->query($wpdb->prepare($sql, $wpdb->esc_like('_transient_rcd_cache_') . '%', $wpdb->esc_like('_transient_timeout_rcd_cache_') . '%', $wpdb->esc_like('rcd_last_fetch_timestamp_') . '%'));
}

// --- START: SELF-HOSTED PLUGIN UPDATER (HARDENED) ---
define('RCD_PLUGIN_FILE', __FILE__);
define('RCD_PLUGIN_SLUG', 'remote-csv-display');
define('RCD_UPDATE_URL', 'https://raw.githubusercontent.com/muslimpribadi/remote-csv-display/refs/heads/main/update.json');

add_filter('pre_set_site_transient_update_plugins', 'rcd_check_for_updates');
function rcd_check_for_updates(object $transient): object {
    if (empty($transient->checked)) return $transient;
    $current_version = get_plugin_data(RCD_PLUGIN_FILE)['Version'];
    $response = wp_remote_get(RCD_UPDATE_URL, ['timeout' => 10]);
    if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) return $transient;
    $remote_info = json_decode(wp_remote_retrieve_body($response));
    if (!$remote_info || !isset($remote_info->version) || !isset($remote_info->download_url)) return $transient;
    if (version_compare($current_version, $remote_info->version, '<')) {
        $plugin_info = new stdClass();
        $plugin_info->slug = RCD_PLUGIN_SLUG;
        $plugin_info->new_version = $remote_info->version;
        $plugin_info->package = $remote_info->download_url;
        $transient->response[plugin_basename(RCD_PLUGIN_FILE)] = $plugin_info;
    }
    return $transient;
}

add_filter('plugins_api', 'rcd_plugins_api_filter', 10, 3);
function rcd_plugins_api_filter(false|object|array $result, string $action = '', ?object $args = null): false|object|array {
    if ($action !== 'plugin_information' || !isset($args->slug) || $args->slug !== RCD_PLUGIN_SLUG) return $result;
    $response = wp_remote_get(RCD_UPDATE_URL, ['timeout' => 10]);
    if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) return $result;
    $remote_info = json_decode(wp_remote_retrieve_body($response));
    return $remote_info ? (object) array_merge((array)$result, (array)$remote_info) : $result;
}
// --- END: SELF-HOSTED PLUGIN UPDATER (HARDENED) ---
