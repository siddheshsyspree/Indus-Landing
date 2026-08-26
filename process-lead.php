<?php
header('Content-Type: application/json');

$config_path = __DIR__ . '/zoho-config.php';
if (!file_exists($config_path)) {
  http_response_code(500);
  exit;
}
$config = require $config_path;

$provided_secret = $_SERVER['HTTP_X_INTERNAL_SECRET'] ?? '';
if (!hash_equals($config['internal_secret'] ?? '', $provided_secret)) {
  http_response_code(403);
  exit;
}

$req = json_decode(file_get_contents('php://input'));

$full_name    = is_string($req->fullName ?? null) ? $req->fullName : '';
$company_name = is_string($req->companyName ?? null) ? $req->companyName : '';
$city         = is_string($req->city ?? null) ? $req->city : '';
$age          = is_string($req->age ?? null) ? $req->age : '';
$designation  = is_string($req->designation ?? null) ? $req->designation : '';
$referral     = is_string($req->referral ?? null) ? $req->referral : '';
$mobile       = is_string($req->mobile ?? null) ? $req->mobile : '';
$email        = is_string($req->email ?? null) ? $req->email : '';

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

function push_lead_to_zoho($config, $full_name, $company_name, $city, $age, $designation, $referral, $mobile, $email) {
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

push_lead_to_zoho($config, $full_name, $company_name, $city, $age, $designation, $referral, $mobile, $email);

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

$mail_headers  = "From: The Indus Club Website <no-reply@theindusclub.com>\r\n";
$mail_headers .= "Reply-To: " . $email . "\r\n";

$mail = mail($to, $subject, $message, $mail_headers);

if (!$mail) {
  error_log('process-lead.php: mail() failed for submission from ' . $email);
}

echo json_encode(["status" => "done"]);
