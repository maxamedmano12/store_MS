<?php

/*
|--------------------------------------------------------------------------
| SECURE SESSION SETTINGS
|--------------------------------------------------------------------------
*/

$isHttps =
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? null) == 443);

ini_set('session.use_strict_mode', '1');

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

require_once 'config/database.php';
require_once 'config/login_history.php';


/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function loginE($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function loginSetting(
    PDO $conn,
    string $key,
    $default = null
) {
    try {
        $stmt = $conn->prepare("
            SELECT setting_value
            FROM system_settings
            WHERE setting_key = ?
            LIMIT 1
        ");
        $stmt->execute([$key]);

        $value = $stmt->fetchColumn();

        return $value !== false
            ? $value
            : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function writeLoginHistory(
    PDO $conn,
    ?int $userId,
    ?int $businessId,
    ?int $branchId,
    ?string $username,
    string $eventType,
    ?string $failureReason = null
): void {
    try {
        $stmt = $conn->prepare("
            INSERT INTO login_history (
                user_id,
                business_id,
                branch_id,
                username,
                event_type,
                ip_address,
                user_agent,
                failure_reason
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $userId,
            $businessId,
            $branchId,
            $username,
            $eventType,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $failureReason,
        ]);
    } catch (Throwable $e) {
        // Login must continue even if history logging fails.
    }
}


/*
|--------------------------------------------------------------------------
| ALREADY LOGGED IN
|--------------------------------------------------------------------------
*/

if (isset($_SESSION['user_id'])) {

    $sessionRole = (string)($_SESSION['role'] ?? '');
    $sessionContext = (string)($_SESSION['auth_context'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | VALID EXISTING SESSION
    |--------------------------------------------------------------------------
    | Do not trust user_id alone. Role and auth_context must agree.
    */

    if (
        $sessionRole === 'Super Admin'
        && $sessionContext === 'super_admin'
    ) {
        header('Location: super_admin/dashboard.php');
        exit;
    }

    if (
        $sessionRole !== ''
        && $sessionRole !== 'Super Admin'
        && $sessionContext === 'business'
        && !empty($_SESSION['business_id'])
    ) {
        header('Location: business/dashboard.php');
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | STALE / MIXED SESSION
    |--------------------------------------------------------------------------
    | If old session data does not match its context, clear it and show login.
    */

    $_SESSION = [];
    session_regenerate_id(true);
}


/*
|--------------------------------------------------------------------------
| SETTINGS
|--------------------------------------------------------------------------
*/

$failedLoginLimit = max(
    1,
    (int) loginSetting(
        $conn,
        'failed_login_limit',
        5
    )
);

$lockoutMinutes = max(
    1,
    (int) loginSetting(
        $conn,
        'lockout_minutes',
        15
    )
);

$maintenanceMode =
    (string) loginSetting(
        $conn,
        'maintenance_mode',
        '0'
    ) === '1';


/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (
    empty($_SESSION['login_csrf'])
    || !is_string($_SESSION['login_csrf'])
) {
    $_SESSION['login_csrf'] = bin2hex(
        random_bytes(32)
    );
}


/*
|--------------------------------------------------------------------------
| LOGIN
|--------------------------------------------------------------------------
*/

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim(
        (string)($_POST['username'] ?? '')
    );

    $password =
        (string)($_POST['password'] ?? '');

    $csrf =
        (string)($_POST['csrf_token'] ?? '');


    if (
        !hash_equals(
            $_SESSION['login_csrf'],
            $csrf
        )
    ) {

        $error =
            'Request-ka login-ka ma saxna. Fadlan mar kale isku day.';

    } elseif (
        $username === ''
        || $password === ''
    ) {

        $error =
            'Fadlan geli username iyo password.';

    } else {

        $stmt = $conn->prepare("
            SELECT
                u.id,
                u.full_name,
                u.username,
                u.password,
                u.status,

                u.failed_login_attempts,
                u.locked_until,

                u.business_id,
                u.branch_id,

                r.role_name,

                b.business_name,
                b.status AS business_status,

                br.business_id AS branch_business_id,
                br.branch_name,
                br.status AS branch_status

            FROM users u

            INNER JOIN roles r
                ON r.id = u.role_id

            LEFT JOIN businesses b
                ON b.id = u.business_id

            LEFT JOIN branches br
                ON br.id = u.branch_id

            WHERE u.username = ?

            LIMIT 1
        ");

        $stmt->execute([$username]);

        $user = $stmt->fetch();


        /*
        |--------------------------------------------------------------------------
        | UNKNOWN USER
        |--------------------------------------------------------------------------
        */

        if (!$user) {

            writeLoginHistory(
                $conn,
                null,
                null,
                null,
                $username,
                'LOGIN_FAILED',
                'Invalid credentials'
            );

            // Small delay helps reduce brute-force speed.
            usleep(300000);

            $error =
                'Username ama password waa khalad.';

        } else {

            $userId =
                (int)$user['id'];

            $businessId =
                $user['business_id'] !== null
                    ? (int)$user['business_id']
                    : null;

            $branchId =
                $user['branch_id'] !== null
                    ? (int)$user['branch_id']
                    : null;


            /*
            |--------------------------------------------------------------------------
            | CURRENT LOCK
            |--------------------------------------------------------------------------
            */

            $lockedUntil =
                $user['locked_until'] ?? null;

            $isCurrentlyLocked = false;

            if (!empty($lockedUntil)) {

                try {
                    $lockTime =
                        new DateTime($lockedUntil);

                    $now =
                        new DateTime();

                    $isCurrentlyLocked =
                        $lockTime > $now;
                } catch (Throwable $e) {
                    $isCurrentlyLocked = false;
                }
            }


            if ($isCurrentlyLocked) {

                writeLoginHistory(
                    $conn,
                    $userId,
                    $businessId,
                    $branchId,
                    $username,
                    'LOGIN_FAILED',
                    'Account temporarily locked'
                );

                $error =
                    'Account-kan si ku-meel-gaar ah ayuu u xiran yahay. Fadlan wax yar kadib isku day.';

            } elseif (
                !password_verify(
                    $password,
                    $user['password']
                )
            ) {

                /*
                |--------------------------------------------------------------------------
                | WRONG PASSWORD
                |--------------------------------------------------------------------------
                */

                $newAttempts =
                    ((int)$user['failed_login_attempts']) + 1;

                if (
                    $newAttempts >=
                    $failedLoginLimit
                ) {

                    $lockStmt = $conn->prepare("
                        UPDATE users
                        SET
                            failed_login_attempts = ?,
                            locked_until = DATE_ADD(
                                NOW(),
                                INTERVAL ? MINUTE
                            )
                        WHERE id = ?
                    ");

                    $lockStmt->execute([
                        $newAttempts,
                        $lockoutMinutes,
                        $userId,
                    ]);

                    writeLoginHistory(
                        $conn,
                        $userId,
                        $businessId,
                        $branchId,
                        $username,
                        'LOGIN_FAILED',
                        'Too many failed attempts - account locked'
                    );

                    $error =
                        "Isku-dayo badan oo khaldan ayaa dhacay. Account-ka waxaa la xiray {$lockoutMinutes} daqiiqo.";

                } else {

                    $attemptStmt =
                        $conn->prepare("
                            UPDATE users
                            SET
                                failed_login_attempts = ?,
                                locked_until = NULL
                            WHERE id = ?
                        ");

                    $attemptStmt->execute([
                        $newAttempts,
                        $userId,
                    ]);

                    writeLoginHistory(
                        $conn,
                        $userId,
                        $businessId,
                        $branchId,
                        $username,
                        'LOGIN_FAILED',
                        'Invalid password'
                    );

                    $remaining =
                        max(
                            0,
                            $failedLoginLimit - $newAttempts
                        );

                    $error =
                        'Username ama password waa khalad.'
                        . (
                            $remaining > 0
                                ? " Waxaa kuu haray {$remaining} isku-day."
                                : ''
                        );
                }

            } elseif (
                $user['status'] !== 'Active'
            ) {

                writeLoginHistory(
                    $conn,
                    $userId,
                    $businessId,
                    $branchId,
                    $username,
                    'LOGIN_FAILED',
                    'User account inactive'
                );

                $error =
                    'Account-kan hadda ma shaqaynayo.';

            } elseif (
                $user['role_name'] !== 'Super Admin'
                && $user['business_status'] === 'Suspended'
            ) {

                /*
                |--------------------------------------------------------------------------
                | IMPORTANT
                |--------------------------------------------------------------------------
                | We block manual suspension here.
                | Subscription expiry/grace is handled by auth.php after login.
                */

                writeLoginHistory(
                    $conn,
                    $userId,
                    $businessId,
                    $branchId,
                    $username,
                    'LOGIN_FAILED',
                    'Business suspended'
                );

                $error =
                    'Meheraddan hadda waa suspended.';

            } elseif (
                $user['role_name'] !== 'Super Admin'
                && $branchId !== null
                && (
                    $user['branch_business_id'] === null
                    || (int)$user['branch_business_id']
                        !== $businessId
                )
            ) {

                writeLoginHistory(
                    $conn,
                    $userId,
                    $businessId,
                    $branchId,
                    $username,
                    'LOGIN_FAILED',
                    'Branch does not belong to business'
                );

                $error =
                    'Branch-ka user-kan loo xilsaaray ma waafaqsana meheraddiisa.';

            } elseif (
                $user['role_name'] !== 'Super Admin'
                && $branchId !== null
                && $user['branch_status'] !== 'Active'
            ) {

                writeLoginHistory(
                    $conn,
                    $userId,
                    $businessId,
                    $branchId,
                    $username,
                    'LOGIN_FAILED',
                    'Branch inactive'
                );

                $error =
                    'Branch-kan hadda waa xiran yahay.';

            } elseif (
                $maintenanceMode
                && $user['role_name'] !== 'Super Admin'
            ) {

                writeLoginHistory(
                    $conn,
                    $userId,
                    $businessId,
                    $branchId,
                    $username,
                    'LOGIN_FAILED',
                    'Maintenance mode'
                );

                $error =
                    'System-ku hadda maintenance ayuu ku jiraa. Fadlan mar dambe isku day.';

            } else {

                /*
                |--------------------------------------------------------------------------
                | SUCCESSFUL LOGIN
                |--------------------------------------------------------------------------
                */

                $successStmt =
                    $conn->prepare("
                        UPDATE users
                        SET
                            failed_login_attempts = 0,
                            locked_until = NULL,
                            last_login_at = NOW(),
                            last_login_ip = ?,
                            last_activity = NOW()
                        WHERE id = ?
                    ");

                $successStmt->execute([
                    $_SERVER['REMOTE_ADDR']
                        ?? null,
                    $userId,
                ]);


                /*
                |--------------------------------------------------------------------------
                | CREATE A CLEAN AUTH SESSION
                |--------------------------------------------------------------------------
                | Clear every value from the previous account first, then rotate
                | the session id and write only the current account's values.
                */

                $_SESSION = [];
                session_regenerate_id(true);

                $_SESSION['user_id'] =
                    $userId;

                $_SESSION['full_name'] =
                    $user['full_name'];

                $_SESSION['username'] =
                    $user['username'];

                $_SESSION['role'] =
                    $user['role_name'];

                /*
                |--------------------------------------------------------------------------
                | AUTH CONTEXT
                |--------------------------------------------------------------------------
                */

                if ($user['role_name'] === 'Super Admin') {

                    $_SESSION['auth_context'] =
                        'super_admin';

                    // Super Admin is global and must never inherit tenant data.
                    $_SESSION['business_id'] = null;
                    $_SESSION['business_name'] = null;
                    $_SESSION['branch_id'] = null;
                    $_SESSION['branch_name'] = null;

                } else {

                    $_SESSION['auth_context'] =
                        'business';

                    $_SESSION['business_id'] =
                        $businessId;

                    $_SESSION['business_name'] =
                        $user['business_name'];

                    $_SESSION['branch_id'] =
                        $branchId;

                    $_SESSION['branch_name'] =
                        $user['branch_name'];
                }

                $_SESSION['last_activity'] =
                    time();

                $_SESSION['session_regenerated_at'] =
                    time();


                writeLoginHistory(
                    $conn,
                    $userId,
                    $businessId,
                    $branchId,
                    $username,
                    'LOGIN_SUCCESS',
                    null
                );


                if (
                    $user['role_name']
                    === 'Super Admin'
                ) {

                    header(
                        'Location: super_admin/dashboard.php'
                    );

                } else {

                    header(
                        'Location: business/dashboard.php'
                    );
                }

                exit;
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Login</title>

    <link
        rel="icon"
        type="image/png"
        href="assets/images/favicon.png"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>

        body {
            min-height: 100vh;
            margin: 0;
            background:
                linear-gradient(
                    135deg,
                    #0b1f39,
                    #123d70
                );
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: Arial, sans-serif;
        }

        .login-card {
            width: 100%;
            max-width: 420px;
            background: #fff;
            padding: 35px;
            border-radius: 16px;
            box-shadow:
                0 25px 60px
                rgba(0,0,0,.22);
        }

        .login-icon {
            width: 60px;
            height: 60px;
            margin: 0 auto 18px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #eaf2ff;
            color: #246ff2;
            font-size: 27px;
        }

        .login-card h3 {
            text-align: center;
            font-weight: 700;
        }

        .login-card p {
            text-align: center;
            color: #8290a4;
            font-size: 14px;
            margin-bottom: 25px;
        }

        .form-control {
            min-height: 48px;
        }

        .btn-login {
            min-height: 48px;
            font-weight: 600;
        }

    </style>

</head>

<body>

<div class="login-card">

    <div class="login-icon">
        📦
    </div>

    <h3>
        Management System
    </h3>

    <p>
        Sign in to continue
    </p>


    <?php if ($error): ?>

        <div
            class="alert alert-danger"
            role="alert"
        >
            <?= loginE($error) ?>
        </div>

    <?php endif; ?>


    <form
        method="POST"
        autocomplete="on"
    >

        <input
            type="hidden"
            name="csrf_token"
            value="<?= loginE($_SESSION['login_csrf']) ?>"
        >

        <div class="mb-3">

            <label class="form-label">
                Username
            </label>

            <input
                type="text"
                name="username"
                class="form-control"
                autocomplete="username"
                value="<?= loginE($_POST['username'] ?? '') ?>"
                required
                autofocus
            >

        </div>


        <div class="mb-4">

            <label class="form-label">
                Password
            </label>

            <input
                type="password"
                name="password"
                class="form-control"
                autocomplete="current-password"
                required
            >

        </div>


        <button
            type="submit"
            class="btn btn-primary btn-login w-100"
        >
            Login
        </button>

    </form>

</div>

</body>
</html>
