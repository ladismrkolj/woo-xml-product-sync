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
 * Entry point for manual sync triggers.
 */
function wxps_maybe_run_sync(): void
{
    if (! isset($_GET['xml_wc_stock_sync'])) {
        return;
    }

    if (! class_exists('WooCommerce')) {
        error_log('[wxps] WooCommerce is not active.');
        return;
    }

    $dry_run = isset($_GET['dryrun']) && '1' === $_GET['dryrun'];

    wxps_sync_products($dry_run);
}
add_action('init', 'wxps_maybe_run_sync');

/**
 * Perform the product synchronization.
 */
function wxps_sync_products(bool $dry_run = false): void
{
    $feed_url = 'https://www.recharge.si/export.php?ceID=6';

    $response = wp_remote_get($feed_url);

    if (is_wp_error($response)) {
        error_log('[wxps] Failed to fetch XML feed: ' . $response->get_error_message());
        return;
    }

    $status = wp_remote_retrieve_response_code($response);
    if ($status !== 200) {
        error_log('[wxps] Unexpected response code: ' . $status);
        return;
    }

    $body = wp_remote_retrieve_body($response);
    $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);

    if ($xml === false) {
        error_log('[wxps] Failed to parse XML feed.');
        return;
    }

    if (! isset($xml->izdelki->izdelek)) {
        error_log('[wxps] No products found in XML feed.');
        return;
    }

    $feed_skus = [];

    foreach ($xml->izdelki->izdelek as $item) {
        $external_id = trim((string) ($item->izdelekID ?? ''));

        if ($external_id === '') {
            error_log('[wxps] Skipping product with missing external ID.');
            continue;
        }

        $sku         = $external_id;
        $name        = trim((string) ($item->izdelekIme ?? ''));
        $description = wxps_clean_description((string) ($item->opis ?? ''));
        $price       = wxps_normalize_price((string) ($item->PPC ?? ''));
        $image_urls  = wxps_collect_images($item);
        $in_stock    = wxps_is_in_stock($item->dobava ?? null);

        $feed_skus[] = $sku;

        $existing_id = wc_get_product_id_by_sku($sku);

        if (! $existing_id) {
            wxps_log(sprintf('[wxps] Creating product for SKU %s', $sku), $dry_run);

            if ($dry_run) {
                continue;
            }

            $product_id = wxps_create_product(
                $name,
                $description,
                $sku,
                $price,
                $in_stock,
                $image_urls
            );

            if ($product_id) {
                update_post_meta($product_id, '_from_xml_feed', 'yes');
                update_post_meta($product_id, '_external_id', $external_id);
            }

            continue;
        }

        wxps_log(sprintf('[wxps] Updating product for SKU %s', $sku), $dry_run);

        $product = wc_get_product($existing_id);
        if (! $product) {
            error_log('[wxps] Could not load product with ID ' . $existing_id . ' for SKU ' . $sku);
            continue;
        }

        if (! $dry_run) {
            // $product->set_name($name);
            // $product->set_description($description);
            // $product->set_regular_price($price);
            // $product->set_price($price);
            $product->set_manage_stock(false);
            $product->set_stock_status($in_stock ? 'instock' : 'outofstock');
            $product->save();

            update_post_meta($existing_id, '_from_xml_feed', 'yes');
            update_post_meta($existing_id, '_external_id', $external_id);
        }
    }

    wxps_handle_missing_feed_products($feed_skus, $dry_run);
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
    $text = strtolower(trim((string) $dobava));

    if ($id !== '' && $id !== '0') {
        return true;
    }

    if ($text === '') {
        return false;
    }

    $in_stock_phrases = ['na zalogi', 'zaloga', 'na voljo'];

    foreach ($in_stock_phrases as $phrase) {
        if (strpos($text, $phrase) !== false) {
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
    array $image_urls
): int {
    $product_args = [
        'post_title'   => $name,
        'post_content' => $description,
        'post_status'  => 'draft',
        'post_type'    => 'product',
    ];

    $product_id = wp_insert_post($product_args);

    if (is_wp_error($product_id) || ! $product_id) {
        error_log('[wxps] Failed to create product for SKU ' . $sku);
        return 0;
    }

    wp_set_object_terms($product_id, 'simple', 'product_type');

    $product = wc_get_product($product_id);
    if (! $product) {
        error_log('[wxps] Failed to load created product for SKU ' . $sku);
        return 0;
    }

    $product->set_sku($sku);
    $product->set_manage_stock(false);
    $product->set_stock_status($in_stock ? 'instock' : 'outofstock');
    $product->set_regular_price($price);
    $product->set_price($price);

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
    foreach ($image_urls as $index => $url) {
        if ($url === '') {
            continue;
        }

        $attachment_id = wxps_media_sideload_image($url);

        if (! $attachment_id) {
            continue;
        }

        if ($index === 0) {
            $product->set_image_id($attachment_id);
        } else {
            $gallery_ids   = $product->get_gallery_image_ids();
            $gallery_ids[] = $attachment_id;
            $product->set_gallery_image_ids(array_unique($gallery_ids));
        }
    }
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
        error_log('[wxps] Failed to sideload image: ' . $attachment_id->get_error_message());
        return 0;
    }

    return (int) $attachment_id;
}

/**
 * Handle products missing from the current feed.
 */
function wxps_handle_missing_feed_products(array $feed_skus, bool $dry_run = false): void
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
            wxps_log(sprintf('[wxps] Marking product ID %d (SKU %s) as missing from feed', $product_id, $sku), $dry_run);

            if ($dry_run) {
                continue;
            }

            wp_update_post([
                'ID'          => $product_id,
                'post_status' => 'draft',
            ]);

            wp_add_object_terms($product_id, 'not-in-xml-feed', 'product_tag');
            update_post_meta($product_id, '_not_in_xml_feed', 'yes');
        } else {
            wxps_log(sprintf('[wxps] Product ID %d (SKU %s) present in feed; removing missing markers', $product_id, $sku), $dry_run);

            if ($dry_run) {
                continue;
            }

            delete_post_meta($product_id, '_not_in_xml_feed');
            wp_remove_object_terms($product_id, 'not-in-xml-feed', 'product_tag');
        }
    }
}

/**
 * Simple logging helper that respects dry-run.
 */
function wxps_log(string $message, bool $dry_run = false): void
{
    $prefix = $dry_run ? '[wxps][dry-run] ' : '[wxps] ';
    error_log($prefix . $message);
}
