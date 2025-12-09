<?php
if (!defined('ABSPATH')) { exit; }

class PIFX_Cron {
  public static function init() {
    add_filter('cron_schedules', array(__CLASS__, 'add_schedules'));
    // Redirect to local wrapper for logging
    add_action(PIFX_EVENT_HOOK, array(__CLASS__, 'handle_cron_event'));
    // pifx_log("PIFX_Cron::init loaded. DOING_CRON: " . (defined('DOING_CRON') && DOING_CRON ? 'yes' : 'no'));
    // ensure schedule on each init
    add_action('init', array(__CLASS__, 'maybe_reschedule'));
  }

  public static function add_schedules($schedules) {
    if (!isset($schedules['pifx_15min'])) {
      $schedules['pifx_15min'] = array(
        'interval' => 15 * 60,
        'display' => __('Every 15 Minutes', 'property-importer-from-xml')
      );
    }
    if (!isset($schedules['pifx_30min'])) {
      $schedules['pifx_30min'] = array(
        'interval' => 30 * 60,
        'display' => __('Every 30 Minutes', 'property-importer-from-xml')
      );
    }
    return $schedules;
  }

  public static function schedule_event() {
    $settings = pifx_get_settings();
    $recurrence = in_array($settings['schedule'], array('pifx_15min','pifx_30min','hourly','twicedaily','daily')) ? $settings['schedule'] : 'hourly';
    // pifx_log("PIFX_Cron::schedule_event checking. Recurrence: $recurrence");
    if (!wp_next_scheduled(PIFX_EVENT_HOOK)) {
      // pifx_log("PIFX_Cron::schedule_event scheduling new event.");
      $first = time() + 60;
      if ($recurrence === 'daily' && !empty($settings['run_time'])) {
        $nt = self::next_daily_timestamp($settings['run_time']);
        if ($nt) { $first = $nt; }
      }
      wp_schedule_event($first, $recurrence, PIFX_EVENT_HOOK);
    }
  }

  public static function clear_scheduled_event() {
    $timestamp = wp_next_scheduled(PIFX_EVENT_HOOK);
    if ($timestamp) {
      wp_unschedule_event($timestamp, PIFX_EVENT_HOOK);
    }
  }

  public static function maybe_reschedule() {
    $settings = pifx_get_settings();
    // If event exists but with different recurrence, reschedule
    $timestamp = wp_next_scheduled(PIFX_EVENT_HOOK);
    $recurrence = in_array($settings['schedule'], array('pifx_15min','pifx_30min','hourly','twicedaily','daily')) ? $settings['schedule'] : 'hourly';
    if (!$timestamp) {
      self::schedule_event();
      return;
    }
    // WordPress doesn't expose event schedule easily; simplest: unschedule and schedule again on admin save
    // Here, just ensure exists; Admin save will call reschedule explicitly
  }

  public static function reschedule_now() {
    self::clear_scheduled_event();
    self::schedule_event();
  }

  private static function next_daily_timestamp($time_str) {
    if (!is_string($time_str) || !preg_match('/^\d{1,2}:\d{2}$/', $time_str)) { return 0; }
    $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone(wp_timezone_string());
    try {
      $now = new DateTime('now', $tz);
      list($h, $m) = array_map('intval', explode(':', $time_str));
      $target = new DateTime('now', $tz);
      $target->setTime($h, $m, 0);
      if ($target->getTimestamp() <= $now->getTimestamp()) {
        $target->modify('+1 day');
        $target->setTime($h, $m, 0);
      }
      return $target->getTimestamp();
    } catch (Exception $e) {
      return 0;
      return 0;
    }
  }

  public static function handle_cron_event() {
    // pifx_log("PIFX_Cron::handle_cron_event FIRED! Calling importer...");
    PIFX_Importer::import_once();
  }
}