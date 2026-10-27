<?php

/*
|--------------------------------------------------------------------------
| SESSION CONFIGURATION
|--------------------------------------------------------------------------
|
| File: config/session.php
|
| File-kan session ayuu bilaabaa oo keliya.
| MA SAMEYNAYO user test ah.
| MA BUUXINAYO business_id / branch_id / role.
|
| Login.php iyo auth.php ayaa mas'uul ka ah xogta user-ka.
|
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {

    $isHttps =
        (!empty($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off')
        ||
        (($_SERVER['SERVER_PORT'] ?? null) == 443);


    /*
    |--------------------------------------------------------------------------
    | SESSION SECURITY
    |--------------------------------------------------------------------------
    */

    ini_set(
        'session.use_strict_mode',
        '1'
    );

    ini_set(
        'session.use_only_cookies',
        '1'
    );

    ini_set(
        'session.cookie_httponly',
        '1'
    );


    /*
    |--------------------------------------------------------------------------
    | COOKIE SETTINGS
    |--------------------------------------------------------------------------
    */

    session_set_cookie_params([

        'lifetime' => 0,

        'path' => '/',

        'domain' => '',

        'secure' => $isHttps,

        'httponly' => true,

        'samesite' => 'Lax'

    ]);


    /*
    |--------------------------------------------------------------------------
    | START SESSION
    |--------------------------------------------------------------------------
    */

    session_start();
}


/*
|--------------------------------------------------------------------------
| IMPORTANT
|--------------------------------------------------------------------------
|
| Halkan HA GELIN:
|
| $_SESSION['user_id']
| $_SESSION['business_id']
| $_SESSION['branch_id']
| $_SESSION['role']
|
| Xogtaas login.php/auth.php ayaa maamulaya.
|
|--------------------------------------------------------------------------
*/