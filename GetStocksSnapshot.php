<?php

/**
 * Class GetStocksSnapshot
 * api doc: @see https://services.stockdio.com/#!i_Data_GetStocksSnapshot
 */
final class GetStocksSnapshot
{
  private const SOURCE_URL = 'https://api.stockdio.com/data/financial/prices/v1/GetStocksSnapshot';
  private const DATE_FORMAT = 'Y-m-d H:i:s';
  private const TIMEZONE_MALTA = 'Europe/Malta';
  private string $apiKey = '';
  private string $symbols = '';
  private string $stockExchange = '';
  private string $cron_hook = 'cron_hook_finco_GetStocksSnapshot';
  private string $cron_id = 'cron_id_finco_GetStocksSnapshot';
  private const LOG_FILE = WP_CONTENT_DIR.'/cron-stockdio-log.txt';

  public function __construct()
  {
    $this->apiKey        = get_option('stock_exchange_stockdio_app_key') ?: '';
    $this->symbols       = get_field('symbols_GetStocksSnapshot_data', 'stock_exchange') ?? '';
    $this->stockExchange = get_field('stockExchange_GetStocksSnapshot_data', 'stock_exchange') ?? '';

    /* To set new start time for CRON
    Change start event time below in DateTime() object then uncomment this call */
    // $this->unschedule_cron_event();
    /* Reload any page to set new time start cron and comment it again
    END test */

    if ( ! wp_next_scheduled($this->cron_hook, [$this->cron_id])) {
      // for fast test set time: '01:05 pm' or 'tomorrow 1 am'
      $date      = new DateTime('tomorrow 3 am', new DateTimeZone(self::TIMEZONE_MALTA));
      $timestamp = $date->getTimestamp();
      wp_schedule_event($timestamp, 'daily', $this->cron_hook, [$this->cron_id]);
    }
    add_action($this->cron_hook, [$this, 'cron_handle']);
  }

  public function cron_handle($arg): void
  {
    if (wp_doing_cron() && isset($arg) && $arg === $this->cron_id) {
      $this->update_value_in_wp_options();
    }
  }

  public function update_value_in_wp_options(): void
  {
    if (empty($this->apiKey) || empty($this->symbols)) {
      return;
    }

    $query_arg['app-key'] = $this->apiKey;
    $query_arg['symbols'] = urlencode(trim($this->symbols, ';'));

    if ( ! empty($this->stockExchange)) {
      // according api doc If stockExchange not specified, USA will be used by default
      $query_arg['stockExchange'] = $this->stockExchange;
    }

    $args = [
      'timeout' => 10, // periodically, the API is slow to respond
    ];

    $url      = add_query_arg($query_arg, self::SOURCE_URL);
    $response = wp_safe_remote_get($url, $args);

    if (is_wp_error($response)) {
      $this->log_to_file('API request, WP_Error: '.$response->get_error_message());

      // Retry cron with single cron in 15 mins if remote api responses with timeout, and we do not know timeout value
      wp_schedule_single_event(time() + (15 * 60), $this->cron_hook, [$this->cron_id]);

      return;
    }

    $stdObj = json_decode(wp_remote_retrieve_body($response));

    if ( ! isset($stdObj->status->code) || $stdObj->status->code != 0) {
      $this->log_to_file('Status code of API response is not set or not equal 0.', $stdObj->status->code);

      return;
    }

    if (isset($stdObj->data->values[0][0]) && ! empty(trim($stdObj->data->values[0][0]))) {
      update_option('think_stockdio_api_GetStocksSnapshot', $stdObj->data->values);
      update_option('think_stockdio_api_GetStocksSnapshot_timestamp', $this->get_timestamp_in_malta_timezone());
    } else {
      $this->log_to_file('Required data is empty in response.', json_encode($stdObj, JSON_PRETTY_PRINT));
    }
  }

  /**
   * @return int Timestamp in seconds in Malta timezone
   */
  private function get_timestamp_in_malta_timezone(): int
  {
    $date = new DateTime('now', new DateTimeZone(self::TIMEZONE_MALTA));

    return $date->getTimestamp();
  }

  public function check_cron_event(): void
  {
    $timestamp = wp_next_scheduled($this->cron_hook, [$this->cron_id]);
    if ($timestamp) {
      $schedule      = wp_get_schedule($this->cron_hook, [$this->cron_id]);
      $readable_time = date(self::DATE_FORMAT, $timestamp);
      echo "Cron event is scheduled for: ".$readable_time."<br>";
      echo "Cron event schedule: ".$schedule."<br>";
    } else {
      echo "Cron event is not scheduled.<br>";
    }
  }

  private function unschedule_cron_event(): void
  {
    $timestamp = wp_next_scheduled($this->cron_hook, [$this->cron_id]);
    if ($timestamp) {
      wp_unschedule_event($timestamp, $this->cron_hook, [$this->cron_id]);
    }
  }

  /**
   * @param  string  $text
   * @param  int|float|string|bool|null  ...$add_args
   *
   * @return void
   */
  private function log_to_file(string $text, int|float|string|bool|null...$add_args): void
  {
    file_put_contents(self::LOG_FILE, "===========================================\n", FILE_APPEND);
    file_put_contents(self::LOG_FILE, date(self::DATE_FORMAT, time())."\n", FILE_APPEND);
    file_put_contents(self::LOG_FILE, $text."\n", FILE_APPEND);
    foreach ($add_args as $value) {
      $type  = gettype($value);
      $value = (is_null($value) || is_bool($value)) ? (int)$value : $value;
      file_put_contents(self::LOG_FILE, "{$type}: \t {$value}\n", FILE_APPEND);
    }
    file_put_contents(self::LOG_FILE, "\n", FILE_APPEND);
  }
}
