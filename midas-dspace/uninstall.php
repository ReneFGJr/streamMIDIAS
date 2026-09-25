<?php
defined('WP_UNINSTALL_PLUGIN') || exit;
if (!get_option('midas_delete_data')) { return; }
global $wpdb;
$ids = $wpdb->get_col("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_midas_owned' AND meta_value='1'");
foreach ($ids as $id) { wp_delete_attachment((int)$id, true); }
foreach (['repositories', 'collections', 'items', 'item_collections', 'files', 'checkpoints', 'logs'] as $table) {
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'midas_' . $table);
}
foreach (['midas_schema', 'midas_delete_data', 'midas_github'] as $option) { delete_option($option); }
delete_transient('midas_release');
wp_clear_scheduled_hook('midas_tick');
if (function_exists('as_unschedule_all_actions')) { as_unschedule_all_actions('midas_tick', [], 'midas'); }
