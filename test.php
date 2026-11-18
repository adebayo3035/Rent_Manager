<?php
// var_dump(function_exists('fastcgi_finish_request'));
$answer = "Nigeria";
$hashed = password_hash(strtolower(trim($answer)), PASSWORD_DEFAULT);
print("Hashed Password is " . $hashed);

$token = bin2hex(random_bytes(32));


print("token signature is: " . $token);



session_start();
echo "<pre>";
print_r($_SESSION);
echo "</pre>";
?>
