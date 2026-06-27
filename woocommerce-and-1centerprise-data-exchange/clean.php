<?php
if ( ! defined( 'ABSPATH' ) ) exit;

if (!defined('WP_CLI')) {
  if (!current_user_can('shop_manager') && !current_user_can('administrator')) exit("No permissions\n");

  if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] == 'GET') { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
    ?>
    <form method="post">
      <input type="submit" value="Clean">
    </form>
    <?php
  }

  if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] != 'POST') exit; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
}

global $wpdb;

if (!isset($wpdb->termmeta)) exit("WooCommerce plugin is not active");

wc1c_disable_time_limit();

// if (is_dir(WC1C_DATA_DIR)) {
//   $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(WC1C_DATA_DIR, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
//   foreach ($iterator as $path => $item) {
//     if ($item->isDir()) {
//       rmdir($path) or wc1c_error(sprintf("Failed to remove directory %s", $path));
//     }
//     else {
//       unlink($path) or wc1c_error(sprintf("Failed to unlink file %s", $path));
//     }
//   }
// }
// else {
//   mkdir(WC1C_DATA_DIR) or wc1c_error(sprintf("Failed to make directory %s", WC1C_DATA_DIR));
// }

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$wc1c_rows = $wpdb->get_results("SELECT tm.term_id, taxonomy FROM $wpdb->termmeta tm JOIN $wpdb->term_taxonomy tt ON tm.term_id = tt.term_id WHERE meta_key = 'wc1c_guid'"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
foreach ($wc1c_rows as $wc1c_row) {
  wp_delete_term($wc1c_row->term_id, $wc1c_row->taxonomy);
}

$wc1c_attribute_ids = get_option('wc1c_guid_attributes', array());
foreach ($wc1c_attribute_ids as $wc1c_attribute_id) {
  wc1c_delete_woocommerce_attribute($wc1c_attribute_id);
}
delete_transient('wc_attribute_taxonomies');

$wc1c_option_names = $wpdb->get_col("SELECT option_name FROM $wpdb->options WHERE option_name LIKE 'wc1c_%'"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
foreach ($wc1c_option_names as $wc1c_option_name) {
  delete_option($wc1c_option_name);
}

$wc1c_post_ids = $wpdb->get_col("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wc1c_guid'"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
foreach ($wc1c_post_ids as $wc1c_post_id) {
  $wc1c_post_attachments = get_attached_media('image', $wc1c_post_id);
  foreach ($wc1c_post_attachments as $wc1c_post_attachment) {
    wp_delete_attachment($wc1c_post_attachment->ID, true);
  }

  wp_delete_post($wc1c_post_id, true);
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

echo defined('WP_CLI') ? "\x07" : "Done";
