<?php
if (!defined('ABSPATH')) { exit; }

class PIFX_Importer {

  public static function init() {
    add_action('admin_post_pifx_manual_import', array(__CLASS__, 'manual_import'));
  }

  public static function manual_import() {
    if (!current_user_can('manage_options')) wp_die('Insufficient permissions');
    check_admin_referer('pifx_manual_import');
    $result = self::import_once();
    $redirect = add_query_arg(array('pifx_import' => $result ? 'ok' : 'fail'), wp_get_referer());
    wp_safe_redirect($redirect ? $redirect : admin_url('options-general.php?page=pifx'));
    exit;
  }

  public static function import_once() {
    set_time_limit(0);
    @ini_set('memory_limit', '1024M'); 
    $settings = pifx_get_settings();
    $status = array('started_at' => current_time('mysql'), 'added' => 0, 'updated' => 0, 'skipped' => 0, 'unpublished' => 0, 'errors' => array(), 'trigger' => (defined('DOING_CRON') && DOING_CRON) ? 'cron' : 'request');
    // pifx_log("Starting import_once. Trigger: " . $status['trigger']);
    if (empty($settings['enabled']) || empty($settings['feed_url'])) {
      $status['errors'][] = 'Importer disabled or feed URL missing.';
      pifx_last_status($status);
      return false;
    }

    $progress = pifx_get_progress();
    $cache_file = isset($progress['cache_file']) ? $progress['cache_file'] : '';
    $item_path = isset($progress['item_path']) ? $progress['item_path'] : ($settings['item_path'] ?: '');
    $offset = isset($progress['offset']) ? intval($progress['offset']) : 0;

    $body = '';
    $total = 0;

    if (!$cache_file || !file_exists($cache_file)) {
      // Start a new cycle: fetch and cache the feed
      // pifx_log("Fetching feed from URL: " . $settings['feed_url']);
      $response = wp_remote_get($settings['feed_url'], array('timeout' => 60));
      if (is_wp_error($response)) {
        // pifx_log("Feed fetch error: " . $response->get_error_message());
        $status['errors'][] = $response->get_error_message();
        pifx_last_status($status);
        return false;
      }
      $body = wp_remote_retrieve_body($response);
      if (!$body) {
        $status['errors'][] = 'Empty feed body.';
        pifx_last_status($status);
        return false;
      }

      
      // Write feed to cache file
      $cache_file = pifx_cache_file();
      $dir = dirname($cache_file);
      if (!is_dir($dir)) { wp_mkdir_p($dir); }
      // Use file_put_contents for simplicity; WordPress FS API is optional here
      @file_put_contents($cache_file, $body);
      // pifx_log("Feed cached to: " . $cache_file);
    } else {
      // Continue a cycle using cached feed
      $body = @file_get_contents($cache_file);
      if (!$body) {
        // If cache is empty, restart the cycle by refetching
        $response = wp_remote_get($settings['feed_url'], array('timeout' => 60));
        if (is_wp_error($response)) {
          $status['errors'][] = $response->get_error_message();
          pifx_last_status($status);
          return false;
        }
        $body = wp_remote_retrieve_body($response);
        @file_put_contents($cache_file, $body);
      }
    }

    libxml_use_internal_errors(true);
    // Trim BOM and whitespace which can break XML parsing
    $body = preg_replace('/^\xEF\xBB\xBF/', '', trim($body));
    // Parse with flags to handle CDATA and ignore blanks
    $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOBLANKS);
    if (!$xml) {
      // Capture libxml errors for diagnostics
      $libxml_errors = libxml_get_errors();
      libxml_clear_errors();
      // Attempt to sanitize common invalid ampersands
      $sanitized = preg_replace('/&(?!#?[a-zA-Z0-9]+;)/', '&amp;', $body);
      if ($sanitized !== $body) {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($sanitized, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOBLANKS);
        if ($xml) {
          $libxml_errors = array();
        }
        libxml_clear_errors();
      }
    }
    if (!$xml) {
      // Distinguish HTML responses from malformed XML
      if (stripos($body, '<html') !== false) {
        $status['errors'][] = 'Feed URL returned HTML, not XML.';
      } else {
        $msg = 'Invalid XML.';
        if (!empty($libxml_errors)) {
          $msgs = array();
          foreach ($libxml_errors as $e) { $msgs[] = trim($e->message); if (count($msgs) >= 3) break; }
          if (!empty($msgs)) { $msg .= ' ' . implode(' | ', $msgs); }
        }
        $status['errors'][] = $msg;
      }
      pifx_last_status($status);
      // Clear progress if we can't parse the XML
      pifx_clear_progress();
      return false;
    }
    $arr = pifx_xml_to_array($xml);
    // pifx_log("XML parsed successfully.");
    if (!$item_path) {
      // pifx_log("Detecting item path...");
      $item_path = pifx_detect_item_path_from_array($arr);
      // pifx_log("Detected item path: " . $item_path);
    } else {
      // pifx_log("Using existing item path: " . $item_path);
    }
    $items = pifx_array_get($arr, $item_path);
    if (!is_array($items)) {
      // sometimes items is array under numeric keys
      $items = is_array($arr) ? $arr : array();
    }
    $total = count($items);
    // pifx_log("Total items found: " . $total);

    // Prepare or update progress
    $progress['feed_url'] = $settings['feed_url'];
    $progress['cache_file'] = $cache_file;
    $progress['item_path'] = $item_path;
    if (!isset($progress['total'])) { $progress['total'] = $total; }
    if (!isset($progress['offset'])) { $progress['offset'] = 0; }

    $batch_size = max(1, intval($settings['per_run']));
    $start = intval($progress['offset']);
    $end = min($start + $batch_size, $total);
    // pifx_log("Batch info - Start: $start, End: $end, Batch Size: $batch_size");


    $imported_ids = array();

    for ($i = $start; $i < $end; $i++) {
      // pifx_log("Processing item index: $i");
      $item = $items[$i];
      if (!is_array($item)) { /* pifx_log("Item $i is not an array. Skipped."); */ $status['skipped']++; continue; }

      $ext_id = self::get_mapped_value($item, $settings['mappings']['external_id']);
      if (!$ext_id) { /* pifx_log("Item $i has no external ID. Skipped."); */ $status['skipped']++; continue; }
      // pifx_log("Item $i External ID: $ext_id");
      $imported_ids[] = $ext_id;

      $post_id = self::find_existing_property($ext_id);

      $title = self::get_mapped_value($item, $settings['mappings']['title']);
      $desc  = self::get_mapped_value($item, $settings['mappings']['description']);

      if ($post_id) {
        $post_data = array(
          'ID' => $post_id,
          'post_title' => $title ? sanitize_text_field($title) : ('Property ' . $ext_id),
          'post_content' => $desc ? wp_kses_post($desc) : ''
        );
        $post_id = wp_update_post($post_data, true);
        $status['updated']++;
      } else {
        $post_data = array(
          'post_type' => 'property',
          'post_status' => isset($settings['post_status']) && $settings['post_status'] ? $settings['post_status'] : 'publish',
          'post_title' => $title ? sanitize_text_field($title) : ('Property ' . $ext_id),
          'post_content' => $desc ? wp_kses_post($desc) : ''
        );
        $post_id = wp_insert_post($post_data, true);
        $status['added']++;
      }
      if (is_wp_error($post_id) || !$post_id) {
        // pifx_log("Failed to save post for ID $ext_id. Error: " . (is_wp_error($post_id) ? $post_id->get_error_message() : 'Unknown'));
        $status['errors'][] = 'Failed to save post for ID ' . $ext_id;
        continue;
      }
      // pifx_log("Post saved. ID: $post_id");

      update_post_meta($post_id, '__pifx_external_id', $ext_id);

      // Core meta fields for Houzez
      $meta_map = pifx_houzez_meta_keys();
      foreach ($meta_map as $field => $meta_key) {
        $path = isset($settings['mappings'][$field]) ? $settings['mappings'][$field] : '';
        $value = self::get_mapped_value($item, $path);
        if ($value !== null && $value !== '') {
          update_post_meta($post_id, $meta_key, sanitize_text_field(is_array($value) ? implode(',', $value) : $value));
        }
      }

      // Custom meta mappings
      if (!empty($settings['meta_mappings'])) {
        foreach ($settings['meta_mappings'] as $mm) {
          if (empty($mm['meta_key']) || empty($mm['xml_path'])) continue;
          $value = self::get_mapped_value($item, $mm['xml_path']);
          if ($value !== null && $value !== '') {
            update_post_meta($post_id, $mm['meta_key'], sanitize_text_field(is_array($value) ? implode(',', $value) : $value));
          }
        }
      }
      // Taxonomy mappings (Houzez taxonomies)
      $tax_map = array(
        'property_type' => 'property_type',
        'property_status' => 'property_status',
        'property_feature' => 'property_feature',
        'property_country' => 'property_country',
        'property_city' => 'property_city',
        'property_area' => 'property_area',
        'property_state' => 'property_state'
      );
      foreach ($tax_map as $field => $taxonomy) {
        $path = isset($settings['mappings'][$field]) ? $settings['mappings'][$field] : '';
        if (!$path) continue;
        $value = self::get_mapped_value($item, $path);
        if ($value === null || $value === '') continue;
        // Normalize to list of term names
        $terms = array();
        if (is_array($value)) {
          foreach ($value as $vv) { if (is_scalar($vv)) { $t = trim((string)$vv); if ($t !== '') $terms[] = $t; } }
        } elseif (is_string($value)) {
          $str = trim($value);
          if ($str !== '') {
            // allow comma/semicolon/pipe separated values
            $terms = preg_split('/\s*[,;|]\s*/', $str);
          }
        }
        if (!$terms) continue;
        // Ensure terms exist and assign
        $assign_terms = array();
        foreach ($terms as $t) {
          $t = trim($t);
          if ($t === '') continue;
          $exists = term_exists($t, $taxonomy);
          if (!$exists) {
            $ins = wp_insert_term($t, $taxonomy);
            if (is_wp_error($ins)) { continue; }
          }
          $assign_terms[] = $t;
        }
        if ($assign_terms) {
          wp_set_object_terms($post_id, $assign_terms, $taxonomy, false);
        }
      }

      // Images handling
      $images_val = self::get_mapped_value($item, $settings['mappings']['images']);
      $featured_val = self::get_mapped_value($item, isset($settings['mappings']['featured_image']) ? $settings['mappings']['featured_image'] : '');
      // pifx_log("Attaching images for Post $post_id...");
      self::attach_images($post_id, $images_val, $featured_val);
      // pifx_log("Images attached for Post $post_id.");
      
      wp_update_post(array('ID' => $post_id));
    }

    // Update progress and scheduling
    $progress['offset'] = $end;
    pifx_set_progress($progress);

    // Only unpublish missing after the final batch
    if ($end >= $total) {
      if (!empty($settings['remove_missing'])) {
        $all_ext = array();
        foreach ($items as $it) {
          $ext = self::get_mapped_value($it, $settings['mappings']['external_id']);
          if ($ext) { $all_ext[] = $ext; }
        }
        $status['unpublished'] = self::unpublish_missing($all_ext);
      }
      // Cleanup cache and progress; next run will happen on normal schedule
      if (file_exists($cache_file)) { @unlink($cache_file); }
      pifx_clear_progress();
    } else {
      // Schedule next minute until feed is fully processed
      // pifx_log("Rescheduling continuation event.");
      wp_schedule_single_event(time() + 60, PIFX_EVENT_HOOK);
    }

    // pifx_log("Batch finished. Added: {$status['added']}, Updated: {$status['updated']}");

    $status['finished_at'] = current_time('mysql');
    $status['imported_ids_count'] = count($imported_ids);
    $status['batch'] = array('start' => $start, 'end' => $end, 'total' => $total);
    pifx_last_status($status);
    pifx_update_totals($status);
    return true;
  }


  private static function get_mapped_value($item, $path) {

    if (!$path) return null;
    // Support multiple selectors separated by comma, pipe, or whitespace (outside quotes)
    $selectors = array();
    $s = trim($path);
    $len = strlen($s);
    $buf = '';
    $in_single = false; $in_double = false;
    for ($i = 0; $i < $len; $i++) {
      $ch = $s[$i];
      if ($ch === "'" && !$in_double) { $in_single = !$in_single; $buf .= $ch; continue; }
      if ($ch === '"' && !$in_single) { $in_double = !$in_double; $buf .= $ch; continue; }
      $is_sep = (!$in_single && !$in_double) && ( $ch === ',' || $ch === '|' || preg_match('/\s/', $ch) );
      if ($is_sep) {
        if ($buf !== '') { $selectors[] = $buf; $buf = ''; }
        continue;
      }
      $buf .= $ch;
    }
    if ($buf !== '') { $selectors[] = $buf; }
    $paths = array_map('trim', $selectors);
    $values = array();
    foreach ($paths as $p) {
      if ($p === '') continue;
      $p_norm = trim(stripslashes($p));
      // If selector is quoted ("literal" or 'literal'), treat as hardcoded value
      if (preg_match('/^([\'\"])\s*(.*?)\s*\1$/', $p_norm, $m)) {
        $values[] = pifx_strip_cdata($m[2]);
        continue;
      }
      $v = pifx_array_get($item, $p_norm);
      $collected = array();
      pifx_collect_scalars($v, $collected);
      foreach ($collected as $c) { $values[] = $c; }
    }
    // Decide return type: URLs -> array, otherwise join as space-separated string
    $values = array_filter($values, function($x){ return $x !== ''; });
    if (!$values) return null;
    $all_urls = true;
    foreach ($values as $x) {
      if (!filter_var($x, FILTER_VALIDATE_URL)) { $all_urls = false; break; }
    }
    if ($all_urls) {
      return array_values(array_unique($values));
    }
    return implode(',', $values);
  }

  private static function find_existing_property($external_id) {
    $q = new WP_Query(array(
      'post_type' => 'property',
      'post_status' => 'any',
      'meta_query' => array(
        array('key' => '__pifx_external_id', 'value' => $external_id)
      ),
      'fields' => 'ids',
      'posts_per_page' => 1
    ));
    if (!empty($q->posts)) return $q->posts[0];
    return 0;
  }

  private static function attach_images($post_id, $images, $featured = null) {
    if (!$images) return;

    // Normalize to either IDs or URLs
    $ids = array();
    $urls = array();

    if (is_string($images)) {
      $str = trim($images);
      if ($str === '') return;
      // If purely comma-separated integers, treat as gallery IDs
      if (preg_match('/^\d+(?:\s*,\s*\d+)*$/', $str)) {
        $ids = array_filter(array_map('intval', array_map('trim', explode(',', $str))));
      } else {
        // split by comma/semicolon/pipe into potential URLs
        $urls = preg_split('/\s*[,;|]\s*/', $str);
      }
    } elseif (is_array($images)) {
      $scalars = array();
      foreach ($images as $u) { if (is_scalar($u)) { $scalars[] = trim((string)$u); } }
      // If all scalars are integers, treat as IDs
      $all_int = true;
      foreach ($scalars as $s) { if (!preg_match('/^\d+$/', $s)) { $all_int = false; break; } }
      if ($all_int) {
        $ids = array_filter(array_map('intval', $scalars));
      } else {
        $urls = $scalars;
      }
    }

    // If we have IDs, save Houzez gallery meta using one meta row per ID
    if (!empty($ids)) {
      // Move featured ID to front if provided as numeric
      $featured_id = null;
      if (is_scalar($featured) && preg_match('/^\d+$/', (string)$featured)) { $featured_id = intval($featured); }
      if ($featured_id) {
        $ids = array_values(array_unique($ids));
        $ids = array_filter($ids, function($x) use ($featured_id){ return $x !== $featured_id; });
        array_unshift($ids, $featured_id);
      }
      // Clear existing gallery meta and add one meta row per attachment ID
      delete_post_meta($post_id, 'fave_property_images');
      foreach ($ids as $aid) {
        if (get_post_type($aid) === 'attachment') {
          add_post_meta($post_id, 'fave_property_images', $aid);
          // Important for some parts of the theme: set attachment parent
          wp_update_post(array('ID' => $aid, 'post_parent' => $post_id));
        }
      }
      // Set featured image to the first gallery image
      if (!empty($ids)) {
        update_post_meta($post_id, '_thumbnail_id', $ids[0]);
        set_post_thumbnail($post_id, $ids[0]);
      }
      return;
    }

    // Otherwise, treat as URLs: sideload and attach
    // Ensure featured URL is first if provided
    $featured_url = '';
    if ($featured) {
      if (is_array($featured)) {
        foreach ($featured as $fv) { if (is_string($fv) && $fv) { $featured_url = trim($fv); break; } }
      } elseif (is_string($featured)) {
        $featured_url = trim($featured);
      }
      if ($featured_url) {
        // move featured to front if present in urls or prepend otherwise
        $urls = array_values(array_unique($urls));
        $urls = array_filter($urls, function($u) use ($featured_url){ return $u !== $featured_url; });
        array_unshift($urls, $featured_url);
      }
    }

    $attached = array();
    foreach ($urls as $url) {
      if (!filter_var($url, FILTER_VALIDATE_URL)) continue;
      // Avoid duplicates: check if attachment exists for URL
      $attachment_id = attachment_url_to_postid($url);
      if (!$attachment_id) {
        // download and sideload, get attachment ID directly
        if (!function_exists('media_sideload_image')) {
          require_once(ABSPATH . 'wp-admin/includes/media.php');
          require_once(ABSPATH . 'wp-admin/includes/file.php');
          require_once(ABSPATH . 'wp-admin/includes/image.php');
        }
        $attachment_id = media_sideload_image($url, $post_id, null, 'id');
        if (is_wp_error($attachment_id)) continue;
      }
      if ($attachment_id) {
        wp_update_post(array('ID' => (int)$attachment_id, 'post_parent' => $post_id));
        $attached[] = (int)$attachment_id;
      }
    }
    if (!empty($attached)) {
      // Clear and save gallery IDs as individual meta rows per Houzez convention
      delete_post_meta($post_id, 'fave_property_images');
      foreach ($attached as $aid) {
        add_post_meta($post_id, 'fave_property_images', $aid);
      }
      // set featured image to first
      update_post_meta($post_id, '_thumbnail_id', $attached[0]);
      set_post_thumbnail($post_id, $attached[0]);
    }
  }

  private static function unpublish_missing($current_ids) {
    $q = new WP_Query(array(
      'post_type' => 'property',
      'post_status' => 'publish',
      'meta_key' => '__pifx_external_id',
      'fields' => 'ids',
      'posts_per_page' => -1
    ));
    $keep = is_array($current_ids) ? $current_ids : array();
    $cnt = 0;
    foreach ($q->posts as $pid) {
      $ext = get_post_meta($pid, '__pifx_external_id', true);
      if ($ext && !in_array($ext, $keep, true)) {
        $res = wp_update_post(array('ID' => $pid, 'post_status' => 'draft'));
        if (!is_wp_error($res) && $res) { $cnt++; }
      }
    }
    return $cnt;
  }
}