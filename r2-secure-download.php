<?php
/**
 * Plugin Name: R2 Secure Download + Chunk Resume
 * Description: Secure large file download from Cloudflare R2 with license auth, chunked download, resume, and progress bar.
 * Version: 1.2.0
 * Author: Minh
 */

if (!defined('ABSPATH')) {
    exit;
}

class R2_Secure_Download_Chunk {

    // 🔧 CONFIG - CHANGE THESE
    private $api_endpoint; // Your Node.js API - loaded from options
    private $test_file    = 'package-install__woozio-main.zip'; // File key in R2 bucket
    private $chunk_size   = 10 * 1024 * 1024; // 10MB chunks (adjust as needed)
    private $download_dir; // Local save folder - will be set in constructor
    private $license; // License key from WordPress options

    public function __construct() {
        add_shortcode('r2_download', [$this, 'download_shortcode']);
        add_action('admin_menu', [$this, 'settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_ajax_r2_download_progress', [$this, 'ajax_progress']);
        add_action('wp_ajax_nopriv_r2_download_progress', [$this, 'ajax_progress']);
        add_action('wp_ajax_r2_delete_file', [$this, 'ajax_delete_file']);
        add_action('wp_ajax_nopriv_r2_delete_file', [$this, 'ajax_delete_file']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

        // Initialize license key and API endpoint
        $this->license = get_option('r2_license_key');
        $this->api_endpoint = get_option('r2_api_endpoint', 'http://localhost:3000/download');

        // Set download directory to WordPress uploads folder
        $upload_dir = wp_upload_dir();
        $this->download_dir = $upload_dir['basedir'] . '/r2-downloads';

        // Ensure download directory exists
        if (!file_exists($this->download_dir)) {
            mkdir($this->download_dir, 0755, true);
        }

    }

    /* ---------------------------
     * Enqueue Assets
     * --------------------------- */
    public function enqueue_assets() {
        // Only enqueue on pages that have the shortcode
        global $post;
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'r2_download')) {
            // Enqueue CSS
            wp_enqueue_style(
                'r2-download-css',
                plugin_dir_url(__FILE__) . 'assets/css/r2-download.css',
                array(),
                '1.0.0'
            );

            // Enqueue JavaScript
            wp_enqueue_script(
                'r2-download-js',
                plugin_dir_url(__FILE__) . 'assets/js/r2-download.js',
                array('jquery'),
                '1.0.0',
                true
            );

            // Localize script with PHP variables
            wp_localize_script('r2-download-js', 'r2_download_vars', array(
                'nonce' => wp_create_nonce('r2_download_nonce'),
                'ajaxurl' => admin_url('admin-ajax.php')
            ));
        }
    }

    /* ---------------------------
     * Admin Settings Page
     * --------------------------- */
    public function settings_page() {
        add_options_page(
            'R2 Secure Download',
            'R2 Download',
            'manage_options',
            'r2-secure-download',
            [$this, 'settings_html']
        );
    }

    public function register_settings() {
        register_setting('r2_settings_group', 'r2_license_key');
        register_setting('r2_settings_group', 'r2_api_endpoint');
    }

    public function settings_html() {
        // Handle cache clearing
        if (isset($_POST['clear_cache']) && check_admin_referer('clear_r2_cache')) {
            $this->clear_file_size_cache();
            echo '<div class="notice notice-success"><p>File size cache cleared successfully!</p></div>';
        }

        ?>
        <div class="wrap">
            <h1>R2 Secure Chunk Download</h1>
            <form method="post" action="options.php">
                <?php settings_fields('r2_settings_group'); ?>
                <?php do_settings_sections('r2_settings_group'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">License Key</th>
                        <td>
                            <input type="text" name="r2_license_key" value="<?php echo esc_attr(get_option('r2_license_key')); ?>" class="regular-text" />
                            <p class="description">Enter your product license key (e.g., ABC-123-XYZ)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">API Endpoint</th>
                        <td>
                            <input type="url" name="r2_api_endpoint" value="<?php echo esc_attr(get_option('r2_api_endpoint', 'http://localhost:3000/download')); ?>" class="regular-text" />
                            <p class="description">Enter your Node.js API endpoint URL (e.g., http://localhost:3000/download)</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <hr>
            <h2>Cache Management</h2>
            <form method="post">
                <?php wp_nonce_field('clear_r2_cache'); ?>
                <p>Clear the cached file size information. This forces the plugin to re-check file sizes from R2.</p>
                <input type="submit" name="clear_cache" class="button button-secondary" value="Clear File Size Cache" />
            </form>

            <hr>
            <h2>Test Download</h2>
            <p>Use shortcode on any page/post: <code>[r2_download]</code></p>
            <p>Recommended: Place on a private page for logged-in users only.</p>
        </div>
        <?php
    }

    /* ---------------------------
     * Clear File Size Cache
     * --------------------------- */
    public function clear_file_size_cache() {
        $transient_key = 'r2_file_total_size_' . md5($this->test_file);
        delete_transient($transient_key);
    }

    /* ---------------------------
     * Get Signed URL from Node API
     * --------------------------- */
    private function get_signed_url() {
        if (!$this->license) {
            return new WP_Error('no_license', 'License key not configured.');
        }

        $domain = $_SERVER['HTTP_HOST'];

        $response = wp_remote_post($this->api_endpoint, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode([
                'license' => $this->license,
                'domain'  => $domain,
                'file'    => $this->test_file
            ]),
            'timeout' => 20
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($body['success'])) {
            $error = $body['error'] ?? 'Unknown API error';
            return new WP_Error('api_error', "License validation failed: {$error}");
        }

        return $body['data']['url'];
    }

    /* ---------------------------
     * AJAX: Get Download Progress
     * --------------------------- */
    public function ajax_progress() {
        check_ajax_referer('r2_download_nonce', 'nonce');

        $file_path = $this->download_dir . '/' . $this->test_file;
        clearstatcache(); // Clear file status cache
        $downloaded = file_exists($file_path) ? filesize($file_path) : 0;
        $signed_url = $this->get_signed_url();

        if (is_wp_error($signed_url)) {
            wp_send_json_error(['message' => $signed_url->get_error_message()]);
        }

        // Try to get total size from HEAD request (cached if possible)
        $transient_key = 'r2_file_total_size_' . md5($this->test_file);
        $total = get_transient($transient_key);

        // If no cached total or total is 0, try to get it again
        if (false === $total || $total <= 0) {
            $response = wp_remote_get($signed_url, [
                'headers' => [
                    'Range' => 'bytes=0-0',
                ],
                'timeout' => 10, // Shorter timeout for progress checks
            ]);

            if (!is_wp_error($response)) {
                $content_range = wp_remote_retrieve_header($response, 'content-range');

                if ($content_range && preg_match('/\/(\d+)$/', $content_range, $matches)) {
                    $new_total = (int) $matches[1];
                    if ($new_total > 0) {
                        $total = $new_total;
                        set_transient($transient_key, $total, HOUR_IN_SECONDS);
                    }
                }
            }
            // If we can't get total size, continue with 0 but don't mark as complete
        }

        wp_send_json_success([
            'downloaded' => $downloaded,
            'total'      => $total ?? 0,
            'percentage' => $total > 0 ? round(($downloaded / $total) * 100, 1) : 0,
            'complete'   => $total > 0 && $downloaded > 0 && $downloaded >= $total,
            'signed_url' => $signed_url,
            'debug'      => [
                'has_total' => $total > 0,
                'has_downloaded' => $downloaded > 0,
                'total_cached' => $total,
                'downloaded_bytes' => $downloaded
            ]
        ]);
    }

    /* ---------------------------
     * AJAX: Delete Downloaded File
     * --------------------------- */
    public function ajax_delete_file() {
        check_ajax_referer('r2_download_nonce', 'nonce');

        $file_path = $this->download_dir . '/' . $this->test_file;

        if (file_exists($file_path)) {
            if (unlink($file_path)) {
                // Clear the file size cache as well
                $this->clear_file_size_cache();
                wp_send_json_success(['message' => 'File deleted successfully']);
            } else {
                wp_send_json_error(['message' => 'Failed to delete file']);
            }
        } else {
            wp_send_json_error(['message' => 'File does not exist']);
        }
    }

    /* ---------------------------
     * Main Shortcode: [r2_download]
     * --------------------------- */
    public function download_shortcode() {
        if (!is_user_logged_in()) {
            return '<p style="color:red;">⚠️ Please log in to download the demo package.</p>';
        }

        if (!$this->license) {
            return '<p style="color:red;">⚠️ No license key configured in settings.</p>';
        }

        $file_path = $this->download_dir . '/' . $this->test_file;

        // Clear file status cache and check if download is actually complete
        clearstatcache();
        $is_complete = false;

        if (file_exists($file_path)) {
            $file_size = filesize($file_path);

            // Get the expected total size to compare against
            $signed_url = $this->get_signed_url();
            if (!is_wp_error($signed_url)) {
                $transient_key = 'r2_file_total_size_' . md5($this->test_file);
                $expected_total = get_transient($transient_key);

                // If we don't have cached total, try to get it
                if (false === $expected_total || $expected_total <= 0) {
                    $response = wp_remote_get($signed_url, [
                        'headers' => ['Range' => 'bytes=0-0'],
                        'timeout' => 10,
                    ]);

                    if (!is_wp_error($response)) {
                        $content_range = wp_remote_retrieve_header($response, 'content-range');
                        if ($content_range && preg_match('/\/(\d+)$/', $content_range, $matches)) {
                            $expected_total = (int) $matches[1];
                            if ($expected_total > 0) {
                                set_transient($transient_key, $expected_total, HOUR_IN_SECONDS);
                            }
                        }
                    }
                }

                // Only consider complete if file size matches expected total
                $is_complete = $expected_total > 0 && $file_size >= $expected_total;
            }
        }

        ob_start();
        ?>
        <div id="r2-download-container">
            <h3>📦 Click Download Demo Package</h3>
            <p><strong>File:</strong> <?php echo esc_html($this->test_file); ?></p>

            <?php if ($is_complete): ?>
                <div class="complete-message">
                    ✅ <strong>Download complete!</strong><br>
                    File saved: <code><?php echo esc_html($file_path); ?></code>
                </div>
                <button id="r2-delete-btn" class="button button-secondary">
                    🗑️ Delete & Download Again
                </button>
            <?php else: ?>
                <div id="r2-progress-bar">
                    <div id="r2-progress-fill"></div>
                </div>
                <p id="r2-status">Checking license & preparing download...</p>
                <p id="r2-size"></p>
                <button id="r2-download-btn" class="button button-primary">
                    🔄 Start Download
                </button>
            <?php endif; ?>
        </div>

        <?php
        return ob_get_clean();
    }

    /* ---------------------------
     * Core: Perform Chunk Download + Resume
     * --------------------------- */
    public function perform_chunk_download() {
        $file_path = $this->download_dir . '/' . $this->test_file;

        // Get total size (for progress) - cache it
        $total_size = get_transient('r2_file_total_size_' . md5($this->test_file));
        if (false === $total_size) {
            $response = wp_remote_get($signed_url, [
                'headers' => [
                    'Range' => 'bytes=0-0',
                ],
                'timeout' => 15,
            ]);

            if (is_wp_error($response)) {
                return $response;
            }

            $content_range = wp_remote_retrieve_header($response, 'content-range');

            if (!$content_range) {
                return new WP_Error('no_content_range', 'Content-Range header missing');
            }

            // Example header: Content-Range: bytes 0-0/104857600
            if (!preg_match('/\/(\d+)$/', $content_range, $matches)) {
                return new WP_Error('invalid_content_range', 'Invalid Content-Range format');
            }

            $total_size = (int) $matches[1];

            if ($total_size <= 0) {
                return new WP_Error('invalid_size', 'Remote file size invalid');
            }

            set_transient('r2_file_total_size_' . md5($this->test_file), $total_size, HOUR_IN_SECONDS);
        }

        $fp = fopen($file_path, 'c+'); // Create or append
        if (!$fp) {
            return new WP_Error('file_error', 'Cannot open file for writing');
        }

        $downloaded = filesize($file_path);

        // Seek to end of file for appending
        fseek($fp, $downloaded);

        // Debug logging
        error_log("R2 Download: Starting chunk download. Downloaded: {$downloaded}, Total: {$total_size}");

        // Download one chunk at a time to avoid timeouts
        if ($downloaded < $total_size) {
            // Get fresh signed URL for each chunk to prevent expiration
            $signed_url = $this->get_signed_url();
            if (is_wp_error($signed_url)) {
                fclose($fp);
                error_log("R2 Download: Failed to get signed URL: " . $signed_url->get_error_message());
                return $signed_url;
            }

            error_log("R2 Download: Got signed URL for range {$start}-{$end}");

            $start = $downloaded;
            $end = min($start + $this->chunk_size - 1, $total_size - 1);

            $ch = curl_init($signed_url);
            curl_setopt_array($ch, [
                CURLOPT_RANGE         => "$start-$end",
                CURLOPT_FILE          => $fp,
                CURLOPT_TIMEOUT       => 45, // Increased timeout for larger chunks
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT     => 'WordPress/R2-Downloader',
                CURLOPT_HTTPHEADER    => [
                    'Accept: */*',
                    'Accept-Encoding: identity' // Don't compress to ensure accurate byte ranges
                ],
                CURLOPT_NOPROGRESS    => false,
            ]);

            $exec = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            error_log("R2 Download: cURL result - HTTP: {$http_code}, Success: " . ($exec ? 'true' : 'false') . ", Error: {$error}");

            if (!$exec || $http_code !== 206) {
                fclose($fp);
                error_log("R2 Download: Download failed - HTTP {$http_code}: {$error}");
                return new WP_Error('curl_error', "Download failed (HTTP {$http_code}): " . $error);
            }
        }

        fclose($fp);

        // Check if download is complete
        clearstatcache();
        $final_size = filesize($file_path);

        error_log("R2 Download: Chunk completed. Final size: {$final_size}, Complete: " . ($final_size >= $total_size ? 'true' : 'false'));

        return $final_size >= $total_size;
    }
}

// Trigger chunk download via AJAX
add_action('wp_ajax_r2_trigger_download', function() {
    check_ajax_referer('r2_download_nonce', 'nonce');

    $downloader = new R2_Secure_Download_Chunk();
    $result = $downloader->perform_chunk_download();

    if (is_wp_error($result)) {
        wp_send_json_error([
            'message' => $result->get_error_message(),
            'debug' => [
                'error_code' => $result->get_error_code(),
                'error_data' => $result->get_error_data()
            ]
        ]);
    } else {
        wp_send_json_success([
            'message' => 'Chunk downloaded',
            'complete' => $result, // true if download finished
            'debug' => [
                'chunk_completed' => $result,
                'timestamp' => time()
            ]
        ]);
    }
});


new R2_Secure_Download_Chunk();