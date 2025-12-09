<?php
if (!defined('ABSPATH')) { exit; }

function pifx_default_settings() {
  return array(
    'enabled' => false,
    'feed_url' => '',
    'schedule' => 'hourly',
    'run_time' => '',
    'item_path' => '',
    'remove_missing' => true,
    'post_status' => 'publish',
    'per_run' => 50,
    'mappings' => array(
      'external_id' => '',
      'title' => '',
      'description' => '',
      'price' => '',
      'address' => '',
      'bedrooms' => '',
      'bathrooms' => '',
      'area' => '',
      'type' => '',
      'status' => '',
      'images' => '',
      'featured_image' => '',
      'latitude' => '',
      'longitude' => ''
    ),
    'meta_mappings' => array() // each item: ['meta_key' => '', 'xml_path' => '']
  );
}

function pifx_get_settings() {
  $defaults = pifx_default_settings();
  $settings = get_option('pifx_settings', array());
  if (!is_array($settings)) $settings = array();
  // merge deeper
  $settings = array_merge($defaults, $settings);
  $settings['mappings'] = array_merge($defaults['mappings'], isset($settings['mappings']) && is_array($settings['mappings']) ? $settings['mappings'] : array());
  if (!isset($settings['meta_mappings']) || !is_array($settings['meta_mappings'])) {
    $settings['meta_mappings'] = array();
  }
  return $settings;
}

function pifx_update_settings($new) {
  $settings = pifx_get_settings();
  $merged = array_merge($settings, $new);
  if (isset($new['mappings']) && is_array($new['mappings'])) {
    $merged['mappings'] = array_merge($settings['mappings'], $new['mappings']);
  }
  if (isset($new['meta_mappings']) && is_array($new['meta_mappings'])) {
    $merged['meta_mappings'] = $new['meta_mappings'];
  }
  update_option('pifx_settings', $merged);
  return $merged;
}

function pifx_last_status($status = null) {
  if ($status === null) {
    $s = get_option('pifx_last_status', array());
    return is_array($s) ? $s : array();
  }
  update_option('pifx_last_status', $status);
  return $status;
}

// Houzez meta keys mapping for core fields
function pifx_houzez_meta_keys() {
  return array(
    'price' => 'fave_property_price',
    'bedrooms' => 'fave_property_bedrooms',
    'bathrooms' => 'fave_property_bathrooms',
    'area' => 'fave_property_size',
    'address' => 'fave_property_address',
    'latitude' => 'fave_property_latitude',
    'longitude' => 'fave_property_longitude'
    // Removed 'type' and 'status' as they are taxonomies in Houzez
  );
}

// Dot-path getter: path supports segments separated by ".", brackets like [key] or [index]
function pifx_array_get($data, $path) {
  if ($path === '' || $path === null) return null;
  $segments = preg_split('/\./', $path);
  $current = $data;
  foreach ($segments as $seg) {
    if ($seg === '') continue;
    // Parse base and bracket parts: e.g., photo[url][0]
    if (preg_match('/^([^\[]+)((\[[^\]]+\])*)$/', $seg, $m)) {
      $base = $m[1];
      $brackets = $m[2];
      // descend to base
      if (is_array($current) && array_key_exists($base, $current)) {
        $current = $current[$base];
      } elseif (is_object($current) && isset($current->$base)) {
        $current = $current->$base;
      } else {
        return null;
      }
      if ($brackets) {
        // iterate each [...]
        preg_match_all('/\[([^\]]+)\]/', $brackets, $bm);
        foreach ($bm[1] as $token) {
          // numeric index
          if (preg_match('/^\d+$/', $token)) {
            $idx = intval($token);
            if (is_array($current)) {
              $current = isset($current[$idx]) ? $current[$idx] : null;
            } else {
              return null;
            }
          } else {
            // string key selection inside arrays of associative items or nested objects
            $key = $token;
            if (is_array($current)) {
              // If current has direct key (associative array), dive into that key
              if (array_key_exists($key, $current)) {
                $current = $current[$key];
              } else {
                // Else, if current is a list of items, collect values by key
                $collected = array();
                foreach ($current as $row) {
                  if (is_array($row) && array_key_exists($key, $row)) {
                    $collected[] = $row[$key];
                  } elseif (is_object($row) && isset($row->$key)) {
                    $collected[] = $row->$key;
                  }
                }
                $current = $collected;
              }
            } elseif (is_object($current) && isset($current->$key)) {
              $current = $current->$key;
            } else {
              return null;
            }
          }
        }
      }
    } else {
      // simple key
      if (is_array($current) && array_key_exists($seg, $current)) {
        $current = $current[$seg];
      } elseif (is_object($current) && isset($current->$seg)) {
        $current = $current->$seg;
      } else {
        return null;
      }
    }
  }
  // normalize SimpleXML objects and scalars
  if (is_object($current) && method_exists($current, '__toString')) {
    return (string)$current;
  }
  return $current;
}

// Convert SimpleXML to nested arrays
function pifx_xml_to_array(SimpleXMLElement $xml) {
  $json = json_encode($xml);
  $arr = json_decode($json, true);
  return $arr;
}

// Detect item path by finding first repeated child array
function pifx_detect_item_path_from_array($arr, $prefix = '') {
  if (!is_array($arr)) return '';
  foreach ($arr as $key => $val) {
    if (is_array($val)) {
      // list: numeric keys or array of similar elements
      $is_list = array_keys($val) === range(0, count($val) - 1);
      if ($is_list) {
        return ($prefix ? $prefix . '.' : '') . $key;
      } else {
        $child = pifx_detect_item_path_from_array($val, ($prefix ? $prefix . '.' : '') . $key);
        if ($child) return $child;
      }
    }
  }
  return '';
}

// Progress helpers for batch processing
function pifx_get_progress() {
  $p = get_option('pifx_progress', array());
  return is_array($p) ? $p : array();
}
function pifx_set_progress($p) { update_option('pifx_progress', $p); }
function pifx_clear_progress() { delete_option('pifx_progress'); }

// Cache paths in uploads
function pifx_cache_dir() {
  $u = wp_upload_dir();
  $dir = trailingslashit($u['basedir']) . 'pifx';
  if (!is_dir($dir)) { wp_mkdir_p($dir); }
  return $dir;
}
function pifx_cache_file() { return trailingslashit(pifx_cache_dir()) . 'feed.xml'; }

// Get distinct meta keys used by property posts
function pifx_get_property_meta_keys($limit = 200) {
  global $wpdb;
  $sql = "SELECT DISTINCT pm.meta_key FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.post_type = 'property' ORDER BY pm.meta_key LIMIT %d";
  $keys = $wpdb->get_col($wpdb->prepare($sql, $limit));
  return is_array($keys) ? $keys : array();
}

function pifx_strip_cdata($s) {
  $str = is_scalar($s) ? (string)$s : '';
  $str = preg_replace('/^\xEF\xBB\xBF/', '', $str);
  $str = trim($str);
  if ($str === '') return '';
  $str = preg_replace('/<!\[CDATA\[(.*?)\]\]>/', '$1', $str);
  return trim($str);
}

function pifx_collect_scalars($value, &$out) {
  if (is_array($value)) {
    foreach ($value as $v) { pifx_collect_scalars($v, $out); }
    return;
  }
  if (is_object($value) && method_exists($value, '__toString')) {
    $value = (string)$value;
  }
  if (is_scalar($value)) {
    $s = pifx_strip_cdata($value);
    if ($s !== '') { $out[] = $s; }
  }
}

function pifx_get_totals() {
  $t = get_option('pifx_totals', array());
  if (!is_array($t)) { $t = array(); }
  $defaults = array('runs' => 0, 'added' => 0, 'updated' => 0, 'skipped' => 0, 'unpublished' => 0, 'errors' => 0);
  return array_merge($defaults, $t);
}

function pifx_update_totals($status) {
  $t = pifx_get_totals();
  $t['runs'] = isset($t['runs']) ? intval($t['runs']) + 1 : 1;
  $t['added'] = isset($t['added']) ? intval($t['added']) + intval(isset($status['added']) ? $status['added'] : 0) : intval(isset($status['added']) ? $status['added'] : 0);
  $t['updated'] = isset($t['updated']) ? intval($t['updated']) + intval(isset($status['updated']) ? $status['updated'] : 0) : intval(isset($status['updated']) ? $status['updated'] : 0);
  $t['skipped'] = isset($t['skipped']) ? intval($t['skipped']) + intval(isset($status['skipped']) ? $status['skipped'] : 0) : intval(isset($status['skipped']) ? $status['skipped'] : 0);
  $t['unpublished'] = isset($t['unpublished']) ? intval($t['unpublished']) + intval(isset($status['unpublished']) ? $status['unpublished'] : 0) : intval(isset($status['unpublished']) ? $status['unpublished'] : 0);
  $errs = isset($status['errors']) && is_array($status['errors']) ? count($status['errors']) : 0;
  $t['errors'] = isset($t['errors']) ? intval($t['errors']) + $errs : $errs;
  update_option('pifx_totals', $t);
  return $t;
}

function pifx_log($msg) {
  $file = PIFX_PLUGIN_DIR . 'debug_new.log';
  $time = current_time('mysql');
  $entry = "[{$time}] {$msg}\n";
  @file_put_contents($file, $entry, FILE_APPEND);
}