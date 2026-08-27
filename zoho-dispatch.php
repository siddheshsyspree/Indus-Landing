<?php
// Shared by any form handler (register-landing.php, thankyou.php, ...) that
// needs to hand a submission off to process-lead.php without making the
// visitor wait on the Zoho CRM push / email send.

function fire_and_forget($url, $data, $headers = []) {
  $parts = parse_url($url);
  $host = $parts['host'];
  $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
  $secure = ($parts['scheme'] ?? 'http') === 'https';
  $port = $parts['port'] ?? ($secure ? 443 : 80);
  $payload = json_encode($data);

  $remote = ($secure ? 'ssl://' : '') . $host . ':' . $port;
  $errno = 0;
  $errstr = '';
  $fp = @stream_socket_client($remote, $errno, $errstr, 3);
  if (!$fp) {
    error_log("fire_and_forget: could not connect to $remote ($errno $errstr)");
    return false;
  }
  stream_set_timeout($fp, 3);

  $header_lines = "Host: $host\r\n" .
    "Content-Type: application/json\r\n" .
    "Content-Length: " . strlen($payload) . "\r\n" .
    "Connection: Close\r\n";
  foreach ($headers as $name => $value) {
    $header_lines .= "$name: $value\r\n";
  }

  $out = "POST $path HTTP/1.1\r\n" . $header_lines . "\r\n" . $payload;
  fwrite($fp, $out);
  fclose($fp);
  return true;
}

function dispatch_to_zoho($fields) {
  $config_path = __DIR__ . '/zoho-config.php';
  if (!file_exists($config_path)) {
    error_log('dispatch_to_zoho: zoho-config.php not found, skipping process-lead dispatch');
    return;
  }
  $config = require $config_path;
  fire_and_forget(
    'https://www.theindusclub.com/process-lead.php',
    $fields,
    ['X-Internal-Secret' => $config['internal_secret'] ?? '']
  );
}
