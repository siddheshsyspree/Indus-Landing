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

ignore_user_abort(true);
set_time_limit(30);
ob_start();
echo json_encode(["status" => "success"]);
header('Content-Length: ' . ob_get_length());
header('Connection: close');
ob_end_flush();
flush();
if (function_exists('fastcgi_finish_request')) {
  fastcgi_finish_request();
}

function get_zoho_access_token($config) {
  $cache_path = __DIR__ . '/zoho-token-cache.json';

  if (file_exists($cache_path)) {
    $cached = json_decode(file_get_contents($cache_path), true);
    if (($cached['expires_at'] ?? 0) > time() + 60) {
      return $cached['access_token'];
    }
  }

  $token_response = @file_get_contents($config['accounts_domain'] . '/oauth/v2/token?' . http_build_query([
    'grant_type'    => 'refresh_token',
    'client_id'     => $config['client_id'],
    'client_secret' => $config['client_secret'],
    'refresh_token' => $config['refresh_token'],
  ]), false, stream_context_create(['http' => ['method' => 'POST']]));

  if ($token_response === false) {
    error_log('Zoho CRM push failed: could not reach accounts.zoho.com for access token');
    return null;
  }

  $token_data = json_decode($token_response, true);
  $access_token = $token_data['access_token'] ?? null;
  if (!$access_token) {
    error_log('Zoho CRM push failed: no access_token in response - ' . $token_response);
    return null;
  }

  file_put_contents($cache_path, json_encode([
    'access_token' => $access_token,
    'expires_at'   => time() + ($token_data['expires_in'] ?? 3600),
  ]));

  return $access_token;
}

function push_lead_to_zoho($full_name, $company_name, $city, $age, $designation, $referral, $mobile, $email) {
  $config_path = __DIR__ . '/zoho-config.php';
  if (!file_exists($config_path)) {
    error_log('Zoho CRM push skipped: zoho-config.php not found');
    return false;
  }
  $config = require $config_path;

  $access_token = get_zoho_access_token($config);
  if (!$access_token) {
    return false;
  }

  $name_parts = preg_split('/\s+/', $full_name, 2);
  $first_name = $name_parts[0] ?? '';
  $last_name  = $name_parts[1] ?? $name_parts[0] ?? '';

  $notes = [];
  if ($age !== '') $notes[] = "Age: $age";
  if ($referral !== '') $notes[] = "How they learned about us: $referral";

  $lead = [
    'Last_Name'   => $last_name,
    'First_Name'  => $first_name,
    'Company'     => $company_name,
    'Email'       => $email,
    'Mobile'      => $mobile,
    'City'        => $city,
    'Designation' => $designation,
    'Source_Of_Contact' => 'Landing Page',
    'Description' => implode("\n", $notes) . "\n\nSubmitted via theindusclub.com/landing.html",
  ];

  $payload = json_encode(['data' => [$lead]]);

  $ch = curl_init($config['api_domain'] . '/crm/v2/Leads');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
      'Authorization: Zoho-oauthtoken ' . $access_token,
      'Content-Type: application/json',
    ],
    CURLOPT_TIMEOUT        => 10,
  ]);
  $result = curl_exec($ch);
  $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

  $record_status = json_decode($result, true)['data'][0]['status'] ?? null;
  if ($http_code >= 200 && $http_code < 300 && $record_status === 'success') {
    return true;
  }

  error_log("Zoho CRM push failed (HTTP $http_code): $result");
  return false;
}

push_lead_to_zoho($full_name, $company_name, $city, $age, $designation, $referral, $mobile, $email);

$to = 'contact@theindusclub.com';
$subject = "THE INDUS CLUB | Register Your Interest";

$message  = "New membership interest submitted from theindusclub.com/landing.html\n\n";
$message .= "Name - " . $full_name . "\n";
$message .= "Company Name - " . $company_name . "\n";
$message .= "Email - " . $email . "\n";
$message .= "Mobile - " . $mobile . "\n";
$message .= "City - " . $city . "\n";
$message .= "Age - " . $age . "\n";
$message .= "Designation - " . $designation . "\n";
$message .= "How they learned about us - " . $referral . "\n";

$headers  = "From: The Indus Club Website <no-reply@theindusclub.com>\r\n";
$headers .= "Reply-To: " . $email . "\r\n";

$mail = mail($to, $subject, $message, $headers);

if (!$mail) {
  error_log('register-landing.php: mail() failed for submission from ' . $email);
}
