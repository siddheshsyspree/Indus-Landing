<?php
header('Content-Type: application/json');

$req1 = file_get_contents('php://input');
$req = json_decode($req1);

function clean_field($value) {
  $value = is_string($value) ? $value : '';
  return trim(str_replace(["\r", "\n"], ' ', $value));
}

$full_name    = clean_field($req->fullName ?? '');
$company_name = clean_field($req->companyName ?? '');
$city         = clean_field($req->city ?? '');
$age          = clean_field($req->age ?? '');
$designation  = clean_field($req->designation ?? '');
$referral     = clean_field($req->referral ?? '');
$mobile       = clean_field($req->mobile ?? '');
$email        = clean_field($req->email ?? '');

if ($full_name === '' || $company_name === '' || $city === '' || $mobile === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  http_response_code(400);
  echo json_encode(["status" => "error", "message" => "Missing or invalid fields"]);
  exit;
}

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

$config_path = __DIR__ . '/zoho-config.php';
if (file_exists($config_path)) {
  $config = require $config_path;
  fire_and_forget(
    'https://www.theindusclub.com/process-lead.php',
    [
      'fullName'    => $full_name,
      'companyName' => $company_name,
      'city'        => $city,
      'age'         => $age,
      'designation' => $designation,
      'referral'    => $referral,
      'mobile'      => $mobile,
      'email'       => $email,
    ],
    ['X-Internal-Secret' => $config['internal_secret'] ?? '']
  );
} else {
  error_log('register-landing.php: zoho-config.php not found, skipping process-lead dispatch');
}

echo json_encode(["status" => "success"]);
