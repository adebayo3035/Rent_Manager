<?php
// var_dump(function_exists('fastcgi_finish_request'));
$answer = "Nigeria";
$hashed = password_hash(strtolower(trim($answer)), PASSWORD_DEFAULT);
print("Hashed Password is " . $hashed);