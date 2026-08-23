<?php
require_once '../app/config/config.php';
require_once '../app/helpers/Auth.php';

// Full logout: session wipe + remember-token deletion (all devices) + cookie clear.
Auth::logout();

header("Location: index.php");
exit;
