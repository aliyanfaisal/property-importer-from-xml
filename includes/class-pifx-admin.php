<?php
if (!defined('ABSPATH')) { exit; }

class PIFX_Admin {
  public static function init() {
    add_action('admin_menu', array(__CLASS__, 'menu'));
    add_action('admin_post_pifx_save_settings', array(__CLASS__, 'save_settings'));
    add_action('wp_ajax_pifx_autodetect_mapping', array(__CLASS__, 'ajax_autodetect_mapping'));
    add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
  }

  public static function menu() {
    add_options_page(
      __('Property Importer From XML', 'property-importer-from-xml'),
      __('Property Importer From XML', 'property-importer-from-xml'),
      'manage_options',
      'pifx',
      array(__CLASS__, 'render')
    );
  }

  public static function enqueue_assets($hook) {
    if ($hook !== 'settings_page_pifx') return;
    wp_enqueue_script('pifx-admin', PIFX_PLUGIN_URL . 'assets/admin.js', array('jquery'), PIFX_VERSION, true);
    wp_localize_script('pifx-admin', 'PIFX', array(
      'ajax' => admin_url('admin-ajax.php'),
      'nonce' => wp_create_nonce('pifx_autodetect'),
    ));
  }

  public static function render() {
    if (!current_user_can('manage_options')) return;
    $settings = pifx_get_settings();
    $status = pifx_last_status();
    $totals = pifx_get_totals();
    $meta_keys = pifx_get_property_meta_keys();
    $houzez_meta = pifx_houzez_meta_keys();
    // Exclude Houzez meta keys from custom mapping to avoid duplication
    $houzez_meta_values = array_values($houzez_meta);
    $filtered_meta_keys = array_diff($meta_keys, $houzez_meta_values);
    ?>
    <div class="wrap">
      <h1>Property Importer From XML</h1>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('pifx_save_settings'); ?>
        <input type="hidden" name="action" value="pifx_save_settings" />

        <h2>General</h2>
        <table class="form-table">
          <tr>
            <th scope="row">Enable Import</th>
            <td><label><input type="checkbox" name="enabled" value="1" <?php checked(!empty($settings['enabled'])); ?>/> Enabled</label></td>
          </tr>
          <tr>
            <th scope="row">XML Feed URL</th>
            <td><input type="url" name="feed_url" class="regular-text" value="<?php echo esc_attr($settings['feed_url']); ?>" /></td>
          </tr>
          <tr>
            <th scope="row">Root Items Path</th>
            <td><input type="text" name="item_path" class="regular-text" placeholder="e.g. listings.property" value="<?php echo esc_attr($settings['item_path']); ?>" />
            <p class="description">Dot path to the repeating item node in XML. Supports brackets like photo[url] and photo[url][0].</p></td>
          </tr>
          <tr>
            <th scope="row">Schedule</th>
            <td>
              <select name="schedule">
                <?php
                  $schedules = array('pifx_15min' => 'Every 15 Minutes', 'pifx_30min' => 'Every 30 Minutes', 'hourly' => 'Hourly', 'twicedaily' => 'Twice Daily', 'daily' => 'Daily');
                  foreach ($schedules as $k => $label) {
                    echo '<option value="'.esc_attr($k).'" '.selected($settings['schedule'], $k, false).'>'.esc_html($label).'</option>';
                  }
                ?>
              </select>
            </td>
          </tr>
          <tr>
            <th scope="row">Run Time (Site Time)</th>
            <td>
              <input type="time" name="run_time" value="<?php echo esc_attr(isset($settings['run_time']) ? $settings['run_time'] : ''); ?>" />
              <p class="description">Used when schedule is set to Daily. Based on the site's timezone.</p>
            </td>
          </tr>
          <tr>
            <th scope="row">Imported Post Status</th>
            <td>
              <select name="post_status">
                <?php
                  $statuses = array(
                    'publish' => 'Publish',
                    'draft' => 'Draft',
                    'pending' => 'Pending Review',
                    'private' => 'Private'
                  );
                  $cur = isset($settings['post_status']) ? $settings['post_status'] : 'publish';
                  foreach ($statuses as $sk => $label) {
                    echo '<option value="'.esc_attr($sk).'" '.selected($cur, $sk, false).'>'.esc_html($label).'</option>';
                  }
                ?>
              </select>
            </td>
          </tr>
          <tr>
            <th scope="row">Properties per single run</th>
            <td><input type="number" name="per_run" min="1" step="1" value="<?php echo esc_attr(intval($settings['per_run'])); ?>" />
            <p class="description">Processes this many properties per minute until the feed is complete.</p></td>
          </tr>
          <tr>
            <th scope="row">Remove Missing</th>
            <td><label><input type="checkbox" name="remove_missing" value="1" <?php checked(!empty($settings['remove_missing'])); ?>/> Unpublish properties not in the current feed</label></td>
          </tr>
        </table>

        <h2>Main Fields</h2>
        <p>Map XML keys (dot/bracket notation) to core fields.</p>
        <table class="widefat striped">
          <thead><tr><th>Field</th><th>XML Path</th></tr></thead>
          <tbody>
            <?php
              // Core non-meta fields
              $main_fields = array(
              'external_id','title','description','images','featured_image',
              'property_type','property_status','property_feature',
              'property_country','property_city','property_area','property_state'
              );
              foreach ($main_fields as $f) {
                echo '<tr><td>'.esc_html(ucwords(str_replace('_',' ', $f))).'</td><td><input type="text" name="mappings['.$f.']" value="'.esc_attr(isset($settings['mappings'][$f]) ? $settings['mappings'][$f] : '').'" class="regular-text" /></td></tr>';
              }
            ?>
          </tbody>
        </table>
        <p><button type="button" class="button" id="pifx-autodetect">Auto-detect from XML</button></p>

        <h2>Houzez Meta Fields</h2>
        <p>These map directly to Houzez meta keys.</p>
        <table class="widefat striped">
          <thead><tr><th>Field</th><th>XML Path</th></tr></thead>
          <tbody>
            <?php
              foreach ($houzez_meta as $field => $meta_key) {
                $label = ucwords(str_replace('_',' ', $field)) . ' (' . esc_html($meta_key) . ')';
                echo '<tr><td>'.esc_html($label).'</td><td><input type="text" name="mappings['.$field.']" value="'.esc_attr(isset($settings['mappings'][$field]) ? $settings['mappings'][$field] : '').'" class="regular-text" /></td></tr>';
              }
            ?>
          </tbody>
        </table>

        <h2>Custom Meta Mapping</h2>
        <p>Set additional meta keys to fill from XML.</p>
        <datalist id="pifx-meta-keys">
          <?php foreach ($filtered_meta_keys as $mk) { echo '<option value="'.esc_attr($mk).'">'; } ?>
        </datalist>
        <table class="widefat striped">
          <thead><tr><th>Field</th><th>XML Path</th></tr></thead>
          <tbody id="pifx-meta-rows">
            <?php
              $metaRows = !empty($settings['meta_mappings']) ? $settings['meta_mappings'] : array();
              $existingMap = array();
              foreach ($metaRows as $row) {
                if (!empty($row['meta_key'])) {
                  $existingMap[$row['meta_key']] = isset($row['xml_path']) ? $row['xml_path'] : '';
                }
              }
              $index = 0;
              foreach ($filtered_meta_keys as $mk) {
                $xp = isset($existingMap[$mk]) ? $existingMap[$mk] : '';
                echo '<tr><td><input list="pifx-meta-keys" type="text" class="regular-text" name="meta_mappings['.$index.'][meta_key]" value="'.esc_attr($mk).'" /></td><td><input type="text" name="meta_mappings['.$index.'][xml_path]" value="'.esc_attr($xp).'" class="regular-text" /></td></tr>';
                $index++;
              }
              // Additional rows from settings not present in filtered_meta_keys or Houzez meta keys
              foreach ($metaRows as $row) {
                if (empty($row['meta_key']) || in_array($row['meta_key'], $filtered_meta_keys, true) || in_array($row['meta_key'], $houzez_meta_values, true)) continue;
                $mk = $row['meta_key'];
                $xp = isset($row['xml_path']) ? $row['xml_path'] : '';
                echo '<tr><td><input list="pifx-meta-keys" type="text" class="regular-text" name="meta_mappings['.$index.'][meta_key]" value="'.esc_attr($mk).'" /></td><td><input type="text" name="meta_mappings['.$index.'][xml_path]" value="'.esc_attr($xp).'" class="regular-text" /></td></tr>';
                $index++;
              }
              // One blank row to allow adding new mapping in-place
              echo '<tr><td><input list="pifx-meta-keys" type="text" class="regular-text" name="meta_mappings['.$index.'][meta_key]" value="" /></td><td><input type="text" name="meta_mappings['.$index.'][xml_path]" value="" class="regular-text" /></td></tr>';
            ?>
          </tbody>
        </table>
        <p><button type="button" class="button" id="pifx-add-meta-row">Add Custom Field</button></p>

        <p class="submit">
          <button type="submit" class="button button-primary">Save Settings</button>
          <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=pifx_manual_import'), 'pifx_manual_import')); ?>" class="button">Run Import Now</a>
        </p>
      </form>

      <h2>Import Status</h2>
      <?php if (!empty($status)) : ?>
        <table class="form-table">
          <tr><th>Started</th><td><?php echo esc_html(isset($status['started_at']) ? $status['started_at'] : '-'); ?></td></tr>
          <tr><th>Finished</th><td><?php echo esc_html(isset($status['finished_at']) ? $status['finished_at'] : '-'); ?></td></tr>
          <tr><th>Added</th><td><?php echo esc_html(isset($status['added']) ? $status['added'] : 0); ?></td></tr>
          <tr><th>Updated</th><td><?php echo esc_html(isset($status['updated']) ? $status['updated'] : 0); ?></td></tr>
          <tr><th>Skipped</th><td><?php echo esc_html(isset($status['skipped']) ? $status['skipped'] : 0); ?></td></tr>
          <tr><th>Unpublished Missing</th><td><?php echo esc_html(isset($status['unpublished']) ? $status['unpublished'] : 0); ?></td></tr>
          <tr><th>Errors</th><td><code><?php echo esc_html(implode(' | ', isset($status['errors']) ? $status['errors'] : array())); ?></code></td></tr>
        </table>
      <?php endif; ?>

      <h2>All Runs Totals</h2>
      <table class="form-table">
        <tr><th>Runs</th><td><?php echo esc_html(isset($totals['runs']) ? $totals['runs'] : 0); ?></td></tr>
        <tr><th>Added</th><td><?php echo esc_html(isset($totals['added']) ? $totals['added'] : 0); ?></td></tr>
        <tr><th>Updated</th><td><?php echo esc_html(isset($totals['updated']) ? $totals['updated'] : 0); ?></td></tr>
        <tr><th>Skipped</th><td><?php echo esc_html(isset($totals['skipped']) ? $totals['skipped'] : 0); ?></td></tr>
        <tr><th>Unpublished</th><td><?php echo esc_html(isset($totals['unpublished']) ? $totals['unpublished'] : 0); ?></td></tr>
        <tr><th>Error Count</th><td><?php echo esc_html(isset($totals['errors']) ? $totals['errors'] : 0); ?></td></tr>
      </table>
    </div>
    <?php
  }

  public static function save_settings() {
    if (!current_user_can('manage_options')) wp_die('Insufficient permissions');
    check_admin_referer('pifx_save_settings');
    // Unslash incoming data to prevent accumulating backslashes
    $post = wp_unslash($_POST);
    $new = array(
      'enabled' => !empty($post['enabled']),
      'feed_url' => isset($post['feed_url']) ? esc_url_raw($post['feed_url']) : '',
      'item_path' => isset($post['item_path']) ? sanitize_text_field($post['item_path']) : '',
      'schedule' => isset($post['schedule']) ? sanitize_text_field($post['schedule']) : 'hourly',
      'run_time' => isset($post['run_time']) ? sanitize_text_field($post['run_time']) : '',
      'remove_missing' => !empty($post['remove_missing']),
      'post_status' => 'publish',
      'per_run' => isset($post['per_run']) ? max(1, intval($post['per_run'])) : 50,
      'mappings' => array(),
      'meta_mappings' => array()
    );
    if (isset($post['post_status'])) {
      $ps = sanitize_text_field($post['post_status']);
      $allowed = array('publish','draft','pending','private');
      $new['post_status'] = in_array($ps, $allowed, true) ? $ps : 'publish';
    }
    if (!empty($post['mappings']) && is_array($post['mappings'])) {
      foreach ($post['mappings'] as $k => $v) {
        $new['mappings'][$k] = sanitize_text_field($v);
      }
    }
    if (!empty($post['meta_mappings']) && is_array($post['meta_mappings'])) {
      foreach ($post['meta_mappings'] as $row) {
        $mk = isset($row['meta_key']) ? sanitize_text_field($row['meta_key']) : '';
        $xp = isset($row['xml_path']) ? sanitize_text_field($row['xml_path']) : '';
        if ($mk && $xp) $new['meta_mappings'][] = array('meta_key' => $mk, 'xml_path' => $xp);
      }
    }
    pifx_update_settings($new);
    // Reschedule if schedule changed
    PIFX_Cron::reschedule_now();
    wp_safe_redirect(add_query_arg('updated', '1', wp_get_referer()));
    exit;
  }

  public static function ajax_autodetect_mapping() {
    check_ajax_referer('pifx_autodetect', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('No permission', 403);
    $feed = isset($_POST['feed_url']) ? esc_url_raw($_POST['feed_url']) : '';
    if (!$feed) {
      $settings = pifx_get_settings();
      $feed = $settings['feed_url'];
    }
    if (!$feed) wp_send_json_error('Feed URL missing', 400);

    $response = wp_remote_get($feed, array('timeout' => 30));
    if (is_wp_error($response)) wp_send_json_error($response->get_error_message(), 500);
    $body = wp_remote_retrieve_body($response);
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body);
    if (!$xml) wp_send_json_error('Invalid XML', 400);
    $arr = pifx_xml_to_array($xml);
    $root_items = pifx_detect_item_path_from_array($arr);

    // naive autodetection: search keys
    $map = array();
    $candidates = array(
      'external_id' => array('id','listing_id','property_id','ref','reference'),
      'title' => array('title','name'),
      'description' => array('description','details','detail'),
      'price' => array('price','amount'),
      'area' => array('area','size','sqft','sqm'),
      'type' => array('type','category'),
      'status' => array('status','availability'),
      'images' => array('images','image','photos','photo','picture','pictures'),
      'featured_image' => array('featured','thumb','thumbnail','primary','main','image')
    );

    // Inspect first item
    $items = pifx_array_get($arr, $root_items);
    $meta_map = array();
    if (is_array($items) && !empty($items)) {
      $sample = $items[0];
      $paths = self::collect_paths($sample);
      foreach ($candidates as $field => $keys) {
        $found = self::find_path_by_keys($paths, $keys);
        if ($found) $map[$field] = $found;
      }
      // Detect Houzez meta keys into main mappings
      $houzez_meta = pifx_houzez_meta_keys();
      foreach ($houzez_meta as $field => $mk) {
        $found = self::find_path_by_meta_key($paths, $mk);
        if ($found) { $map[$field] = $found; }
      }
      // Detect taxonomy-like fields by name tokens
      $tax_fields = array('property_type','property_status','property_feature','property_country','property_city','property_area','property_state');
      foreach ($tax_fields as $tf) {
        $found = self::find_path_by_meta_key($paths, $tf);
        if ($found) { $map[$tf] = $found; }
      }
      // Detect for known property meta keys too (custom) - exclude Houzez meta keys
      $meta_keys = pifx_get_property_meta_keys();
      $houzez_meta_values = array_values($houzez_meta);
      $filtered_meta_keys = array_diff($meta_keys, $houzez_meta_values);
      foreach ($filtered_meta_keys as $mk) {
        $found = self::find_path_by_meta_key($paths, $mk);
        if ($found) { $meta_map[$mk] = $found; }
      }
      // If images path like photo[url] detected, propose featured_image as [0]
      if (!empty($map['images']) && preg_match('/\[[^\]]+\]$/', $map['images'])) {
        $map['featured_image'] = $map['images'] . '[0]';
      }
    }

    wp_send_json_success(array('item_path' => $root_items, 'mappings' => $map, 'meta_mappings' => $meta_map));
  }

  private static function collect_paths($node, $prefix = '') {
    $paths = array();
    if (is_array($node)) {
      foreach ($node as $k => $v) {
        $p = $prefix ? ($prefix.'.'.$k) : $k;
        if (is_array($v)) {
          $is_list = array_keys($v) === range(0, count($v)-1);
          if ($is_list) {
            // assume list of values
            $paths[] = $p;
            if (!empty($v[0]) && is_array($v[0])) {
              foreach (self::collect_paths($v[0], $p.'[0]') as $cp) { $paths[] = $cp; }
            }
          } else {
            foreach (self::collect_paths($v, $p) as $cp) { $paths[] = $cp; }
          }
        } else {
          $paths[] = $p;
        }
      }
    }
    return $paths;
  }

  private static function find_path_by_keys($paths, $keys) {
    foreach ($paths as $p) {
      $leaf = substr($p, strrpos($p, '.') !== false ? strrpos($p, '.')+1 : 0);
      $leaf = preg_replace('/\[\d+\]$/', '', $leaf);
      if (in_array(strtolower($leaf), $keys)) return $p;
    }
    return '';
  }

  private static function find_path_by_meta_key($paths, $meta_key) {
    $mk = strtolower($meta_key);
    $mk_norm = preg_replace('/[^a-z0-9]/', '', $mk);
    // Candidate names: full key and last token after underscore
    $last_token = $mk;
    if (strpos($mk, '_') !== false) {
      $parts = explode('_', $mk);
      $last_token = end($parts);
    }
    $cands = array($mk, $last_token);
    $cands_norm = array_map(function($s){ return preg_replace('/[^a-z0-9]/','', strtolower($s)); }, $cands);

    foreach ($paths as $p) {
      $leaf = substr($p, strrpos($p, '.') !== false ? strrpos($p, '.')+1 : 0);
      $leaf = preg_replace('/\[\d+\]$/', '', $leaf);
      $leaf_norm = preg_replace('/[^a-z0-9]/', '', strtolower($leaf));
      if (in_array($leaf_norm, $cands_norm, true)) {
        return $p;
      }
    }
    return '';
  }
}