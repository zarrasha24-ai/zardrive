<?php
// Copy to config.local.php on the server and fill in. Never upload config.local.php anywhere else.
return [
    'dsn'   => 'mysql:host=localhost;dbname=CPANELUSER_zardrive;charset=utf8mb4',
    'user'  => 'CPANELUSER_zardrive',
    'pass'  => 'PUT-THE-DATABASE-PASSWORD-HERE',
    'debug' => false,          // true only on a test copy: shows login codes in the API reply
    'tz'    => 'Asia/Karachi',
    'sms'   => null,           // later: a function that sends the login code by SMS
];
