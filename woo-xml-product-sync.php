<?php
/**
 * Plugin Name: Woo XML Product Sync
 * Description: Sync WooCommerce products with an external XML feed.
 * Version: 0.1.0
 * Author: ChatGPT
 */

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Generate or retrieve the sync token.
 */
function wxps_get_or_create_token(): string
{
    $token = get_option('wxps_sync_token');
    if (! $token) {
        $token = bin2hex(random_bytes(32));
        update_option('wxps_sync_token', $token);
    }
    return $token;
}

/**
 * Setup plugin defaults and cron event.
 */
function wxps_activate_plugin(): void
{
    wxps_get_or_create_token();

    if (! wp_next_scheduled('wxps_cron_sync_event')) {
        wp_schedule_event(time(), 'hourly', 'wxps_cron_sync_event');
    }
}
register_activation_hook(__FILE__, 'wxps_activate_plugin');

/**
 * Remove cron event on deactivation.
 */
function wxps_deactivate_plugin(): void
{
    $timestamp = wp_next_scheduled('wxps_cron_sync_event');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'wxps_cron_sync_event');
    }
}
register_deactivation_hook(__FILE__, 'wxps_deactivate_plugin');

/**
 * Entry point for manual sync triggers.
 */
function wxps_maybe_run_sync(): void
{
    if (! isset($_GET['xml_wc_stock_sync'])) {
        return;
    }

    // Ensure token exists
    $token = wxps_get_or_create_token();

    // Validate token
    if (! isset($_GET['token']) || $_GET['token'] !== $token) {
        echo '[wxps] Error: Invalid or missing token';
        return;
    }

    if (! class_exists('WooCommerce')) {
        echo '[wxps] Error: WooCommerce is not active.';
        return;
    }

    $dry_run = isset($_GET['dryrun']) && '1' === $_GET['dryrun'];

    $report = wxps_sync_products($dry_run, 'manual');
    wxps_log(
        sprintf(
            'Manual sync finished. Created: %d, Updated: %d, Deleted: %d, Skipped: %d, Errors: %d',
            $report['created'],
            $report['updated'],
            $report['deleted'],
            $report['skipped'],
            $report['errors']
        ),
        $dry_run
    );
}
add_action('init', 'wxps_maybe_run_sync');

/**
 * Run sync through WP-Cron.
 */
function wxps_run_cron_sync(): void
{
    if (! class_exists('WooCommerce')) {
        wxps_log('Cron sync skipped because WooCommerce is not active.');
        return;
    }

    wxps_log('Cron sync started.');

    $report = wxps_sync_products(false, 'cron');

    wxps_log(
        sprintf(
            'Cron sync finished. Created: %d, Updated: %d, Deleted: %d, Skipped: %d, Errors: %d',
            $report['created'],
            $report['updated'],
            $report['deleted'],
            $report['skipped'],
            $report['errors']
        )
    );
}
add_action('wxps_cron_sync_event', 'wxps_run_cron_sync');

/**
 * Register tools page for sync logs.
 */
function wxps_register_admin_page(): void
{
    add_management_page(
        'Woo XML Product Sync Logs',
        'Woo XML Sync Logs',
        'manage_options',
        'wxps-logs',
        'wxps_render_logs_page'
    );
}
add_action('admin_menu', 'wxps_register_admin_page');

/**
 * Render plugin logs page.
 */
function wxps_render_logs_page(): void
{
    if (! current_user_can('manage_options')) {
        return;
    }

    if (isset($_POST['wxps_clear_logs']) && check_admin_referer('wxps_clear_logs_action')) {
        update_option('wxps_sync_logs', []);
        echo '<div class="notice notice-success"><p>Logs cleared.</p></div>';
    }

    $logs = get_option('wxps_sync_logs', []);

    echo '<div class="wrap">';
    echo '<h1>Woo XML Product Sync Logs</h1>';
    echo '<p>Recent sync activity from manual and cron runs.</p>';

    echo '<form method="post" style="margin-bottom: 16px;">';
    wp_nonce_field('wxps_clear_logs_action');
    submit_button('Clear Logs', 'delete', 'wxps_clear_logs', false);
    echo '</form>';

    if (empty($logs)) {
        echo '<p>No log entries yet.</p>';
        echo '</div>';
        return;
    }

    echo '<pre style="max-height: 500px; overflow: auto; background: #fff; border: 1px solid #ccd0d4; padding: 12px;">';
    foreach ($logs as $line) {
        echo esc_html($line) . "\n";
    }
    echo '</pre>';
    echo '</div>';
}

/**
 * Perform the product synchronization.
 */
function wxps_sync_products(bool $dry_run = false, string $source = 'manual'): array
{
    $report = [
        'source'  => $source,
        'created' => 0,
        'updated' => 0,
        'deleted' => 0,
        'skipped' => 0,
        'errors'  => 0,
    ];

    $feed_url = 'https://www.recharge.si/export.php?ceID=6';

    $response = wp_remote_get($feed_url);

    if (is_wp_error($response)) {
        echo '[wxps] Error: Failed to fetch XML feed: ' . $response->get_error_message();
        $report['errors']++;
        return $report;
    }

    $status = wp_remote_retrieve_response_code($response);
    if ($status !== 200) {
        echo '[wxps] Error: Unexpected response code: ' . $status;
        $report['errors']++;
        return $report;
    }

    $body = wp_remote_retrieve_body($response);
    $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);

    if ($xml === false) {
        echo '[wxps] Error: Failed to parse XML feed.';
        $report['errors']++;
        return $report;
    }

    if (! isset($xml->izdelki->izdelek)) {
        echo '[wxps] Error: No products found in XML feed.';
        $report['errors']++;
        return $report;
    }

    $feed_skus = [];

    foreach ($xml->izdelki->izdelek as $item) {
        $external_id = trim((string) ($item->izdelekID ?? ''));

        if ($external_id === '') {
            echo '[wxps] Warning: Skipping product with missing external ID.';
            $report['skipped']++;
            continue;
        }

        $sku         = $external_id;
        $name        = trim((string) ($item->izdelekIme ?? ''));
        $description = wxps_clean_description((string) ($item->opis ?? ''));
        $price       = wxps_normalize_price((string) ($item->PPC ?? ''));
        $image_urls  = wxps_collect_images($item);
        $in_stock    = wxps_is_in_stock($item->dobava ?? null);
        $brand       = trim((string) ($item->blagovnaZnamka ?? ''));

        $feed_skus[] = $sku;

        $existing_id = wc_get_product_id_by_sku($sku);

        if (! $existing_id) {
            wxps_log(sprintf('[wxps] Creating product for SKU %s', $sku), $dry_run);

            if ($dry_run) {
                $report['skipped']++;
                continue;
            }

            $product_id = wxps_create_product(
                $name,
                $description,
                $sku,
                $price,
                $in_stock,
                $image_urls,
                $brand
            );

            if ($product_id) {
                $report['created']++;
                update_post_meta($product_id, '_from_xml_feed', 'yes');
                update_post_meta($product_id, '_external_id', $external_id);
                wp_add_object_terms($product_id, 'new', 'product_tag');
            } else {
                $report['errors']++;
            }

            continue;
        }

        wxps_log(sprintf('[wxps] Updating product for SKU %s', $sku), $dry_run);

        $product = wc_get_product($existing_id);
        if (! $product) {
            echo '[wxps] Error: Could not load product with ID ' . $existing_id . ' for SKU ' . $sku;
            $report['errors']++;
            continue;
        }

        if (! $dry_run) {
            // $product->set_name($name);
            // $product->set_description($description);
            // $product->set_regular_price($price);
            // $product->set_price($price);
            $product->set_manage_stock(false);
            $product->set_stock_status($in_stock ? 'instock' : 'outofstock');

            if (! empty($image_urls)) {
                //wxps_attach_images($product, $image_urls);
            }

            $product->save();
            $report['updated']++;

            update_post_meta($existing_id, '_from_xml_feed', 'yes');
            update_post_meta($existing_id, '_external_id', $external_id);
        }
    }

    wxps_handle_missing_feed_products($feed_skus, $dry_run, $report);

    return $report;
}

/**
 * Determine stock status from the <dobava> element.
 */
function wxps_is_in_stock($dobava): bool
{
    if ($dobava === null) {
        return false;
    }

    $id   = isset($dobava['id']) ? trim((string) $dobava['id']) : '';
    $text = trim((string) $dobava);
    $normalized_text = strtolower($text);

    if ($id !== '' && $id !== '0') {
        return true;
    }

    if ($normalized_text === '') {
        return false;
    }

    $in_stock_phrases = ['na zalogi', 'zaloga', 'na voljo'];

    foreach ($in_stock_phrases as $phrase) {
        if (strpos($normalized_text, $phrase) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Create a WooCommerce simple product.
 */
function wxps_create_product(
    string $name,
    string $description,
    string $sku,
    float $price,
    bool $in_stock,
    array $image_urls,
    string $brand = ''
): int {
    $product_args = [
        'post_title'   => $name,
        'post_content' => $description,
        'post_status'  => 'pending',
        'post_type'    => 'product',
    ];

    $product_id = wp_insert_post($product_args);

    if (is_wp_error($product_id) || ! $product_id) {
        echo '[wxps] Error: Failed to create product for SKU ' . $sku;
        return 0;
    }

    wp_set_object_terms($product_id, 'simple', 'product_type');

    $product = wc_get_product($product_id);
    if (! $product) {
        echo '[wxps] Error: Failed to load created product for SKU ' . $sku;
        return 0;
    }

    $product->set_sku($sku);
    $product->set_manage_stock(false);
    $product->set_stock_status($in_stock ? 'instock' : 'outofstock');
    $product->set_regular_price($price);
    $product->set_price($price);

    if (! empty($brand)) {
        $product->set_attributes([
            'pa_brand' => $brand,
        ]);
    }

    if (! empty($image_urls)) {
        wxps_attach_images($product, $image_urls);
    }

    $product->save();

    return $product_id;
}

/**
 * Clean product descriptions for WordPress.
 */
function wxps_clean_description(string $description): string
{
    return wp_kses_post($description);
}

/**
 * Normalize a price value from XML.
 */
function wxps_normalize_price(string $price): float
{
    $normalized = str_replace([' ', ','], ['', '.'], $price);

    return (float) $normalized;
}

/**
 * Collect image URLs from the XML product node.
 */
function wxps_collect_images(SimpleXMLElement $item): array
{
    $images = [];

    $primary = trim((string) ($item->slikaVelika ?? ''));
    if ($primary !== '') {
        $images['primary-0'] = $primary;
    }

    foreach ($item->children() as $child_name => $child_value) {
        if (! preg_match('/^dodatnaSlika(\d*)$/i', (string) $child_name, $matches)) {
            continue;
        }

        $url = trim((string) $child_value);

        if ($url === '') {
            continue;
        }

        $order = $matches[1] !== '' ? (int) $matches[1] : 0;
        $images[sprintf('additional-%04d', $order)] = $url;
    }

    if (empty($images)) {
        return [];
    }

    ksort($images, SORT_NATURAL);

    $unique_images = [];
    foreach ($images as $url) {
        if (! in_array($url, $unique_images, true)) {
            $unique_images[] = $url;
        }
    }

    return $unique_images;
}

/**
 * Attach images to a WooCommerce product.
 */
function wxps_attach_images(WC_Product $product, array $image_urls): void
{
    $new_attachment_ids = [];

    foreach ($image_urls as $url) {
        if ($url === '') {
            continue;
        }

        $attachment_id = wxps_media_sideload_image($url);

        if (! $attachment_id) {
            continue;
        }

        $new_attachment_ids[] = $attachment_id;
    }

    if (empty($new_attachment_ids)) {
        return;
    }

    $primary_id = array_shift($new_attachment_ids);

    $product->set_image_id($primary_id);
    $product->set_gallery_image_ids($new_attachment_ids);
}

/**
 * Download and attach an image to the media library.
 */
function wxps_media_sideload_image(string $url): int
{
    if (! function_exists('media_sideload_image')) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }

    $attachment_id = media_sideload_image($url, 0, null, 'id');

    if (is_wp_error($attachment_id)) {
        echo '[wxps] Error: Failed to sideload image: ' . $attachment_id->get_error_message();
        return 0;
    }

    return (int) $attachment_id;
}

/**
 * Handle products missing from the current feed.
 */
function wxps_handle_missing_feed_products(array $feed_skus, bool $dry_run = false, array &$report = []): void
{
    $query = new WP_Query([
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => [
            [
                'key'   => '_from_xml_feed',
                'value' => 'yes',
            ],
        ],
    ]);

    if (! $query->have_posts()) {
        return;
    }

    foreach ($query->posts as $product_id) {
        $product = wc_get_product($product_id);
        if (! $product) {
            continue;
        }

        $sku = $product->get_sku();

        $in_feed = in_array($sku, $feed_skus, true);

        if (! $in_feed) {
            wxps_log(sprintf('[wxps] Deleting product ID %d (SKU %s) missing from feed', $product_id, $sku), $dry_run);

            if ($dry_run) {
                if (isset($report['skipped'])) {
                    $report['skipped']++;
                }
                continue;
            }

            wp_delete_post($product_id, true);
            if (isset($report['deleted'])) {
                $report['deleted']++;
            }
        } else {
            wxps_log(sprintf('[wxps] Product ID %d (SKU %s) present in feed', $product_id, $sku), $dry_run);
        }
    }
}

/**
 * Simple logging helper that respects dry-run.
 */
function wxps_log(string $message, bool $dry_run = false): void
{
    $prefix = $dry_run ? '[wxps][dry-run] ' : '[wxps] ';
    $line   = sprintf('[%s] %s%s', current_time('mysql'), $prefix, $message);

    error_log($line);

    $logs = get_option('wxps_sync_logs', []);
    if (! is_array($logs)) {
        $logs = [];
    }

    $logs[] = $line;

    if (count($logs) > 200) {
        $logs = array_slice($logs, -200);
    }

    update_option('wxps_sync_logs', $logs);
}
