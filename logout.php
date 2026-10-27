<?php

/*
|--------------------------------------------------------------------------
| START SESSION SAFELY
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


require_once 'config/database.php';
require_once 'config/login_history.php';


/*
|--------------------------------------------------------------------------
| SAVE CURRENT SESSION DATA
|--------------------------------------------------------------------------
|
| Waxaan marka hore kaydinaynaa xogta user-ka ka hor inta aan session-ka
| la nadiifin.
|
*/

$userId =
    isset($_SESSION['user_id'])
        ? (int) $_SESSION['user_id']
        : null;


$businessId =
    isset($_SESSION['business_id'])
    && $_SESSION['business_id'] !== null
        ? (int) $_SESSION['business_id']
        : null;


$branchId =
    isset($_SESSION['branch_id'])
    && $_SESSION['branch_id'] !== null
        ? (int) $_SESSION['branch_id']
        : null;


$username =
    isset($_SESSION['username'])
        ? (string) $_SESSION['username']
        : null;


/*
|--------------------------------------------------------------------------
| RECORD LOGOUT
|--------------------------------------------------------------------------
*/

if ($userId !== null) {

    try {

        recordLoginHistory(
            $conn,
            'LOGOUT',
            $userId,
            $businessId,
            $branchId,
            $username
        );

    } catch (Throwable $e) {

        error_log(
            'Logout History Error: '
            . $e->getMessage()
        );
    }
}


/*
|--------------------------------------------------------------------------
| CLEAR ALL SESSION VALUES
|--------------------------------------------------------------------------
*/

$_SESSION = [];


/*
|--------------------------------------------------------------------------
| DELETE SESSION COOKIE
|--------------------------------------------------------------------------
*/

if (ini_get('session.use_cookies')) {

    $params =
        session_get_cookie_params();


    setcookie(
        session_name(),
        '',
        [
            'expires' => time() - 42000,

            'path' =>
                $params['path'] ?? '/',

            'domain' =>
                $params['domain'] ?? '',

            'secure' =>
                (bool) ($params['secure'] ?? false),

            'httponly' =>
                (bool) ($params['httponly'] ?? true),

            'samesite' =>
                $params['samesite'] ?? 'Lax'
        ]
    );
}


/*
|--------------------------------------------------------------------------
| DESTROY SERVER SESSION
|--------------------------------------------------------------------------
*/

session_destroy();


/*
|--------------------------------------------------------------------------
| OPTIONAL EXTRA COOKIE CLEANUP
|--------------------------------------------------------------------------
|
| Haddii browser-ku hayo PHPSESSID duug ah, mar labaad ayaan hubinaynaa.
|
*/

if (isset($_COOKIE[session_name()])) {

    unset(
        $_COOKIE[session_name()]
    );
}


/*
|--------------------------------------------------------------------------
| REDIRECT TO LOGIN
|--------------------------------------------------------------------------
*/

header(
    'Location: login.php'
);

exit;