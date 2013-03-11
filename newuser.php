<?php
$password = PW;

$email = EMAIL;
$salt = base64_encode(openssl_random_pseudo_bytes(32));
$digest = hash("sha256", $salt . $password);
?>

INSERT INTO users (email, salt, digest) VALUES ("<?=$email?>", "<?=$salt?>", "<?=$digest?>");

