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
$source       = is_string($req->source ?? null) && $req->source !== '' ? $req->source : 'Website Lead';
$gclid        = is_string($req->gclid ?? null) ? $req->gclid : '';

function get_zoho_access_token($config) {
  $cache_path = __DIR__ . '/zoho-token-cache.json';
  $refresh_token_hash = substr(md5($config['refresh_token']), 0, 12);

  if (file_exists($cache_path)) {
    $cached = json_decode(file_get_contents($cache_path), true);
    if (($cached['expires_at'] ?? 0) > time() + 60 && ($cached['refresh_token_hash'] ?? null) === $refresh_token_hash) {
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
    'refresh_token_hash' => $refresh_token_hash,
  ]));

  return $access_token;
}

function push_lead_to_zoho($config, $full_name, $company_name, $city, $age, $designation, $referral, $mobile, $email, $source, $gclid) {
  $access_token = get_zoho_access_token($config);
  if (!$access_token) {
    return false;
  }

  $name_parts = preg_split('/\s+/', $full_name, 2);
  $first_name = $name_parts[0] ?? '';
  $last_name  = $name_parts[1] ?? $name_parts[0] ?? '';

  $notes = ["Source: $source"];
  if ($age !== '') $notes[] = "Age: $age";
  if ($referral !== '') $notes[] = "How they learned about us: $referral";
  if ($gclid !== '') $notes[] = "Google Click ID: $gclid";

  $prospect = [
    'Name'        => $full_name,
    'Single_Line_1' => $first_name,
    'Last_Name'   => $last_name,
    'Company'     => $company_name,
    'Email'       => $email,
    'Mobile'      => $mobile,
    'Home_City'   => $city,
    'Designation' => $designation,
    'Description' => implode("\n", $notes) . "\n\nSubmitted via theindusclub.com",
  ];

  $payload = json_encode(['data' => [$prospect]]);

  $ch = curl_init($config['api_domain'] . '/crm/v2/Prospects');
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

  $response_data = json_decode($result, true)['data'][0] ?? null;
  $record_status = $response_data['status'] ?? null;
  if ($http_code < 200 || $http_code >= 300 || $record_status !== 'success') {
    error_log("Zoho CRM push failed (HTTP $http_code): $result");
    return false;
  }

  $record_id = $response_data['details']['id'] ?? null;
  if ($record_id) {
    // A workflow rule on the Prospects module resets Source_Of_Contact to a
    // default value on create, ignoring whatever is sent in the initial
    // payload. A follow-up update bypasses that workflow and sticks.
    $update_ch = curl_init($config['api_domain'] . '/crm/v2/Prospects/' . $record_id);
    curl_setopt_array($update_ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CUSTOMREQUEST  => 'PUT',
      CURLOPT_POSTFIELDS     => json_encode(['data' => [['Source_Of_Contact' => $source]]]),
      CURLOPT_HTTPHEADER     => [
        'Authorization: Zoho-oauthtoken ' . $access_token,
        'Content-Type: application/json',
      ],
      CURLOPT_TIMEOUT        => 10,
    ]);
    $update_result = curl_exec($update_ch);
    $update_http_code = curl_getinfo($update_ch, CURLINFO_HTTP_CODE);

    if ($update_http_code < 200 || $update_http_code >= 300) {
      error_log("Zoho CRM Source_Of_Contact update failed (HTTP $update_http_code): $update_result");
    }
  }

  return true;
}

push_lead_to_zoho($config, $full_name, $company_name, $city, $age, $designation, $referral, $mobile, $email, $source, $gclid);

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
