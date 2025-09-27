<?php
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }
global $wpdb;
delete_option('gpt5_sa_settings');
$table = $wpdb->prefix . 'gpt5sa_embeddings';
$wpdb->query("DROP TABLE IF EXISTS $table");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_gpt5sa_%' OR option_name LIKE '_transient_timeout_gpt5sa_%'");
