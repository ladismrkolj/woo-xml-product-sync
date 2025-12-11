# woo-xml-product-sync

## Placement
Place `woo-xml-product-sync.php` inside its own plugin folder on your WordPress site:

```
wp-content/plugins/woo-xml-product-sync/woo-xml-product-sync.php
```

You can either upload the file into that folder via FTP/File Manager or copy the entire folder from this repository.

## Activation
1. In wp-admin, go to **Plugins → Installed Plugins**.
2. Activate **Woo XML Product Sync**.

## Manual sync trigger
- Real run: visit `https://your-domain.com/?xml_wc_stock_sync=1`
- Dry-run (logs only, no DB changes): `https://your-domain.com/?xml_wc_stock_sync=1&dryrun=1`

The plugin reads the XML feed, creates missing products as drafts, updates managed products, and marks missing items with the `not-in-xml-feed` tag.

## Testing
For a quick syntax check before deployment, run:

```
php -l woo-xml-product-sync.php
```

On shared hosting, you can also place the file and run the manual sync URLs to verify behavior in staging/production.
