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

if ($mail) {
  echo json_encode(["status" => "success"]);
} else {
  http_response_code(500);
  echo json_encode(["status" => "error", "message" => "mail() failed"]);
}
