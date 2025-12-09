<?php
/*
Plugin Name: Property Importer From XML
Description: Import and sync Houzez properties from an XML feed with automatic mapping, gallery syncing, taxonomy creation, and robust XML parsing. Supports multiple selectors (space/comma/pipe), quoted literals, and saves Houzez gallery as comma-separated IDs. CDATA REMOVED -- Post status added -- logs added
Version: 1.0.6
Author: Aliyan Faisal
Text Domain: property-importer-from-xml
*/

if (!defined('ABSPATH')) { exit; }

define('PIFX_VERSION', '1.0.3');
define('PIFX_PLUGIN_FILE', __FILE__);
define('PIFX_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PIFX_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PIFX_EVENT_HOOK', 'pifx_run_import');

require_once PIFX_PLUGIN_DIR . 'includes/helpers.php';
require_once PIFX_PLUGIN_DIR . 'includes/class-pifx-cron.php';
require_once PIFX_PLUGIN_DIR . 'includes/class-pifx-importer.php';
require_once PIFX_PLUGIN_DIR . 'includes/class-pifx-admin.php';

register_activation_hook(__FILE__, function() {
  $defaults = pifx_default_settings();
  $current = get_option('pifx_settings');
  if (!is_array($current)) {
    update_option('pifx_settings', $defaults);
  } else {
    update_option('pifx_settings', array_merge($defaults, $current));
  }
  PIFX_Cron::schedule_event();
});

register_deactivation_hook(__FILE__, function() {
  PIFX_Cron::clear_scheduled_event();
});

add_action('plugins_loaded', function() {
  PIFX_Cron::init();
  PIFX_Importer::init();
  if (is_admin()) {
    PIFX_Admin::init();
  }
});

add_action('admin_init', function(){
  if (!isset($_GET['run_gallery_update'])) return;
  if (!is_user_logged_in() || !current_user_can('manage_options')) return;
  $paged = 1;
  $updated = 0;
  while (true) {
    $q = new WP_Query(array(
      'post_type' => 'property',
      'post_status' => 'any',
      'fields' => 'ids',
      'posts_per_page' => 200,
      'paged' => $paged
    ));
    if (empty($q->posts)) break;
    foreach ($q->posts as $pid) {
      wp_update_post(array('ID' => $pid));
      $updated++;
    }
    $paged++;
  }
  status_header(200);
  wp_send_json_success(array('message' => 'Gallery update complete', 'updated' => $updated));
  exit;
});



add_action('add_meta_boxes', function(){
  add_meta_box('pifx_meta_table', __('Property Meta','property-importer-from-xml'), function($post){
    $meta = get_post_meta($post->ID);
    echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Meta Key','property-importer-from-xml') . '</th><th>' . esc_html__('Value','property-importer-from-xml') . '</th></tr></thead><tbody>';
    foreach ($meta as $key => $vals) {
      foreach ((array)$vals as $v) {
        $val = is_scalar($v) ? (string)$v : wp_json_encode($v);
        echo '<tr><td>' . esc_html($key) . '</td><td>' . esc_html($val) . '</td></tr>';
      }
    }
    echo '</tbody></table>';
  }, 'property', 'normal', 'default');
});






