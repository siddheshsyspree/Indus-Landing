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

require __DIR__ . '/zoho-dispatch.php';

dispatch_to_zoho([
  'fullName'    => $full_name,
  'companyName' => $company_name,
  'city'        => $city,
  'age'         => $age,
  'designation' => $designation,
  'referral'    => $referral,
  'mobile'      => $mobile,
  'email'       => $email,
  'source'      => 'Google Ads',
]);

echo json_encode(["status" => "success"]);
