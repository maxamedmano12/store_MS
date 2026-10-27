<?php

/*
|--------------------------------------------------------------------------
| CENTRAL AUTHENTICATION / AUTHORIZATION
|--------------------------------------------------------------------------
| File: config/auth.php
|
| Usage:
|   Super Admin page:
|       require_once '../config/auth.php';
|       requireSuperAdmin();
|
|   Business page:
|       require_once 'config/auth.php';
|       requireBusinessUser();
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {

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
}


/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
| auth.php loads the database itself, so pages do not need to load
| database.php before auth.php.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/database.php';


/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

if (!function_exists('authSetting')) {

    function authSetting(
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
}


if (!function_exists('authE')) {

    function authE($value): string
    {
        return htmlspecialchars(
            (string)$value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}


if (!function_exists('isSuperAdmin')) {

    function isSuperAdmin(): bool
    {
        return
            ($_SESSION['role'] ?? '') === 'Super Admin'
            && ($_SESSION['auth_context'] ?? 'super_admin') === 'super_admin';
    }
}


if (!function_exists('currentUserId')) {

    function currentUserId(): ?int
    {
        return isset($_SESSION['user_id'])
            ? (int)$_SESSION['user_id']
            : null;
    }
}


if (!function_exists('currentBusinessId')) {

    function currentBusinessId(): ?int
    {
        return isset($_SESSION['business_id'])
            && $_SESSION['business_id'] !== null
            ? (int)$_SESSION['business_id']
            : null;
    }
}


if (!function_exists('currentBranchId')) {

    function currentBranchId(): ?int
    {
        return isset($_SESSION['branch_id'])
            && $_SESSION['branch_id'] !== null
            ? (int)$_SESSION['branch_id']
            : null;
    }
}


if (!function_exists('currentRole')) {

    function currentRole(): ?string
    {
        return isset($_SESSION['role'])
            ? (string)$_SESSION['role']
            : null;
    }
}


if (!function_exists('authLoginHistory')) {

    function authLoginHistory(
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
            // Auth must continue even when history logging fails.
        }
    }
}


if (!function_exists('destroyAuthSession')) {

    function destroyAuthSession(
        ?string $redirect = null
    ): void {

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {

            $params =
                session_get_cookie_params();

            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        if ($redirect !== null) {
            header('Location: ' . $redirect);
            exit;
        }
    }
}


if (!function_exists('authBlockedPage')) {

    function authBlockedPage(
        string $title,
        string $message,
        string $icon = '🔒'
    ): void {

        http_response_code(403);

        ?>
        <!DOCTYPE html>
        <html lang="en">

        <head>

            <meta charset="UTF-8">

            <meta
                name="viewport"
                content="width=device-width, initial-scale=1.0"
            >

            <title><?= authE($title) ?></title>

            <link
                rel="icon"
                type="image/png"
                href="/bakhaar/assets/images/favicon.png"
            >

            <script src="https://cdn.tailwindcss.com"></script>

        </head>

        <body
            class="
                min-h-screen bg-slate-100
                flex items-center justify-center
                p-5
            "
        >

            <div
                class="
                    w-full max-w-md rounded-3xl
                    bg-white p-8 text-center
                    shadow-xl shadow-slate-200/70
                "
            >

                <div
                    class="
                        mx-auto flex h-16 w-16
                        items-center justify-center
                        rounded-2xl bg-slate-100
                        text-3xl
                    "
                >
                    <?= authE($icon) ?>
                </div>

                <h1
                    class="
                        mt-5 text-xl font-black
                        text-slate-900
                    "
                >
                    <?= authE($title) ?>
                </h1>

                <p
                    class="
                        mt-3 text-sm leading-6
                        text-slate-500
                    "
                >
                    <?= authE($message) ?>
                </p>

                <a
                    href="/bakhaar/logout.php"
                    class="
                        mt-6 inline-flex h-11
                        items-center justify-center
                        rounded-xl bg-slate-900
                        px-5 text-sm font-bold
                        text-white transition
                        hover:bg-slate-800
                    "
                >
                    Back to Login
                </a>

            </div>

        </body>

        </html>
        <?php

        exit;
    }
}


/*
|--------------------------------------------------------------------------
| BASIC SESSION CHECK
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['user_id'])) {

    header(
        'Location: /bakhaar/login.php'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| SETTINGS
|--------------------------------------------------------------------------
*/

$authSessionTimeoutMinutes = max(
    1,
    (int)authSetting(
        $conn,
        'session_timeout_minutes',
        30
    )
);

$authGraceDays = max(
    0,
    (int)authSetting(
        $conn,
        'subscription_grace_days',
        3
    )
);

$authMaintenanceMode =
    (string)authSetting(
        $conn,
        'maintenance_mode',
        '0'
    ) === '1';


/*
|--------------------------------------------------------------------------
| INACTIVITY TIMEOUT
|--------------------------------------------------------------------------
*/

$authNow = time();

if (
    isset($_SESSION['last_activity'])
    && (
        $authNow
        - (int)$_SESSION['last_activity']
    ) > (
        $authSessionTimeoutMinutes * 60
    )
) {

    authLoginHistory(
        $conn,
        currentUserId(),
        currentBusinessId(),
        currentBranchId(),
        $_SESSION['username'] ?? null,
        'SESSION_TIMEOUT',
        'Session inactive for too long'
    );

    destroyAuthSession(
        '/bakhaar/login.php?timeout=1'
    );
}

$_SESSION['last_activity'] =
    $authNow;


/*
|--------------------------------------------------------------------------
| SESSION ID REGENERATION
|--------------------------------------------------------------------------
*/

if (
    empty($_SESSION['session_regenerated_at'])
    || (
        $authNow
        - (int)$_SESSION['session_regenerated_at']
    ) >= 900
) {

    session_regenerate_id(true);

    $_SESSION['session_regenerated_at'] =
        $authNow;
}


/*
|--------------------------------------------------------------------------
| REFRESH CURRENT USER FROM DATABASE
|--------------------------------------------------------------------------
*/

$authUserStmt = $conn->prepare("
    SELECT
        u.id,
        u.full_name,
        u.username,
        u.status AS user_status,

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

    WHERE u.id = ?

    LIMIT 1
");

$authUserStmt->execute([
    (int)$_SESSION['user_id']
]);

$authUser =
    $authUserStmt->fetch();


/*
|--------------------------------------------------------------------------
| USER NO LONGER EXISTS
|--------------------------------------------------------------------------
*/

if (!$authUser) {

    authLoginHistory(
        $conn,
        currentUserId(),
        currentBusinessId(),
        currentBranchId(),
        $_SESSION['username'] ?? null,
        'SESSION_TIMEOUT',
        'User no longer exists'
    );

    destroyAuthSession(
        '/bakhaar/login.php'
    );
}


/*
|--------------------------------------------------------------------------
| USER ACCOUNT STATUS
|--------------------------------------------------------------------------
*/

if (
    $authUser['user_status']
    !== 'Active'
) {

    authLoginHistory(
        $conn,
        (int)$authUser['id'],
        $authUser['business_id'] !== null
            ? (int)$authUser['business_id']
            : null,
        $authUser['branch_id'] !== null
            ? (int)$authUser['branch_id']
            : null,
        $authUser['username'],
        'SESSION_TIMEOUT',
        'User account inactive'
    );

    destroyAuthSession(
        '/bakhaar/login.php?account=inactive'
    );
}


/*
|--------------------------------------------------------------------------
| REFRESH SESSION VALUES
|--------------------------------------------------------------------------
| Session-ka waxaa request kasta laga soo cusboonaysiiyaa database-ka.
| auth_context wuxuu si cad u kala saaraa Super Admin iyo Business User.
|--------------------------------------------------------------------------
*/

$_SESSION['user_id'] =
    (int)$authUser['id'];

$_SESSION['full_name'] =
    $authUser['full_name'];

$_SESSION['username'] =
    $authUser['username'];

$_SESSION['role'] =
    $authUser['role_name'];

if ($authUser['role_name'] === 'Super Admin') {

    $_SESSION['auth_context'] = 'super_admin';

    // Super Admin is global and must never inherit tenant state.
    $_SESSION['business_id'] = null;
    $_SESSION['business_name'] = null;
    $_SESSION['branch_id'] = null;
    $_SESSION['branch_name'] = null;

} else {

    $_SESSION['auth_context'] = 'business';

    $_SESSION['business_id'] =
        $authUser['business_id'] !== null
            ? (int)$authUser['business_id']
            : null;

    $_SESSION['business_name'] =
        $authUser['business_name'];

    $_SESSION['branch_id'] =
        $authUser['branch_id'] !== null
            ? (int)$authUser['branch_id']
            : null;

    $_SESSION['branch_name'] =
        $authUser['branch_name'];
}


/*
|--------------------------------------------------------------------------
| SUPER ADMIN
|--------------------------------------------------------------------------
*/

if (isSuperAdmin()) {

    // Super Admin is global.
    $_SESSION['business_id'] = null;
    $_SESSION['business_name'] = null;
    $_SESSION['branch_id'] = null;
    $_SESSION['branch_name'] = null;

    $_SESSION['subscription_id'] = null;
    $_SESSION['subscription_plan'] = null;
    $_SESSION['subscription_expiry'] = null;
    $_SESSION['subscription_max_branches'] = null;
    $_SESSION['subscription_in_grace'] = false;
    $_SESSION['subscription_grace_days_left'] = 0;

} else {

    /*
    |--------------------------------------------------------------------------
    | BUSINESS USER MUST HAVE BUSINESS
    |--------------------------------------------------------------------------
    */

    $authBusinessId =
        $authUser['business_id'] !== null
            ? (int)$authUser['business_id']
            : null;

    if ($authBusinessId === null) {

        authBlockedPage(
            'Business Missing',
            'User-kan business looma xilsaarin. La xiriir System Administrator.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | MANUAL BUSINESS SUSPENSION
    |--------------------------------------------------------------------------
    | Suspended is treated as a manual/system suspension.
    | Expired is NOT blocked here; subscription logic below is the source
    | of truth for expiry and grace period.
    |--------------------------------------------------------------------------
    */

    if (
        $authUser['business_status']
        === 'Suspended'
    ) {

        authBlockedPage(
            'Business Suspended',
            'Meheraddan hadda waa suspended. Xogta waa badbaadsan tahay laakiin access-ka waa xiran yahay.',
            '⛔'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | BRANCH SECURITY
    |--------------------------------------------------------------------------
    */

    if (
        $authUser['branch_id'] !== null
    ) {

        if (
            $authUser['branch_business_id']
            === null
            || (int)$authUser['branch_business_id']
                !== $authBusinessId
        ) {

            authBlockedPage(
                'Invalid Branch Assignment',
                'Branch-ka user-kan loo xilsaaray kama tirsana business-kiisa.'
            );
        }

        if (
            $authUser['branch_status']
            !== 'Active'
        ) {

            authBlockedPage(
                'Branch Inactive',
                'Branch-kan hadda waa xiran yahay.',
                '🏢'
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | LATEST SUBSCRIPTION
    |--------------------------------------------------------------------------
    */

    $authSubStmt =
        $conn->prepare("
            SELECT
                s.id,
                s.start_date,
                s.expiry_date,
                s.status,
                s.plan_id,

                sp.plan_name,
                sp.max_branches

            FROM subscriptions s

            INNER JOIN subscription_plans sp
                ON sp.id = s.plan_id

            WHERE s.business_id = ?

            ORDER BY
                s.expiry_date DESC,
                s.id DESC

            LIMIT 1
        ");

    $authSubStmt->execute([
        $authBusinessId
    ]);

    $authSubscription =
        $authSubStmt->fetch();


    /*
    |--------------------------------------------------------------------------
    | NO SUBSCRIPTION
    |--------------------------------------------------------------------------
    */

    if (!$authSubscription) {

        authBlockedPage(
            'Subscription Required',
            'Meheraddan ma laha subscription. Fadlan la xiriir System Administrator.',
            '💳'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | MANUALLY SUSPENDED SUBSCRIPTION
    |--------------------------------------------------------------------------
    */

    if (
        $authSubscription['status']
        === 'Suspended'
    ) {

        authBlockedPage(
            'Subscription Suspended',
            'Subscription-ka meheraddan waa suspended.',
            '⛔'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | DATE CALCULATION
    |--------------------------------------------------------------------------
    */

    try {

        $authToday =
            new DateTime('today');

        $authStartDate =
            new DateTime(
                $authSubscription['start_date']
            );

        $authExpiryDate =
            new DateTime(
                $authSubscription['expiry_date']
            );

        $authGraceEnd =
            (clone $authExpiryDate);

        if ($authGraceDays > 0) {

            $authGraceEnd->modify(
                '+' . $authGraceDays . ' days'
            );
        }

    } catch (Throwable $e) {

        authBlockedPage(
            'Subscription Error',
            'Subscription date-ka lama akhrin karo. La xiriir System Administrator.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | FUTURE SUBSCRIPTION
    |--------------------------------------------------------------------------
    */

    if (
        $authToday < $authStartDate
    ) {

        authBlockedPage(
            'Subscription Not Started',
            'Subscription-kan wali ma bilaaban.',
            '🗓️'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | ACTIVE / GRACE / EXPIRED
    |--------------------------------------------------------------------------
    */

    $authInGrace = false;
    $authGraceDaysLeft = 0;

    if (
        $authToday <= $authExpiryDate
    ) {

        /*
        |--------------------------------------------------------------------------
        | VALID SUBSCRIPTION
        |--------------------------------------------------------------------------
        */

        // If an old process marked this subscription Expired but the date is
        // still valid, restore it to Active unless it was manually suspended.
        if (
            $authSubscription['status']
            === 'Expired'
        ) {

            try {

                $stmt = $conn->prepare("
                    UPDATE subscriptions
                    SET status = 'Active'
                    WHERE id = ?
                      AND status = 'Expired'
                ");

                $stmt->execute([
                    (int)$authSubscription['id']
                ]);

                $authSubscription['status'] =
                    'Active';

            } catch (Throwable $e) {
                // Access can continue because the date is valid.
            }
        }

        // A valid subscription re-activates an Expired business,
        // but never overrides Suspended.
        if (
            $authUser['business_status']
            === 'Expired'
        ) {

            try {

                $stmt = $conn->prepare("
                    UPDATE businesses
                    SET status = 'Active'
                    WHERE id = ?
                      AND status = 'Expired'
                ");

                $stmt->execute([
                    $authBusinessId
                ]);

                $authUser['business_status'] =
                    'Active';

            } catch (Throwable $e) {
                // Continue because subscription itself is valid.
            }
        }

    } elseif (
        $authGraceDays > 0
        && $authToday <= $authGraceEnd
    ) {

        /*
        |--------------------------------------------------------------------------
        | GRACE PERIOD
        |--------------------------------------------------------------------------
        */

        $authInGrace = true;

        $authGraceDaysLeft =
            (int)$authToday
                ->diff($authGraceEnd)
                ->format('%a');

        // Grace is allowed even if business was previously marked Expired.
        // Do not override Suspended (already blocked above).

    } else {

        /*
        |--------------------------------------------------------------------------
        | EXPIRED AFTER GRACE
        |--------------------------------------------------------------------------
        */

        try {

            $conn->beginTransaction();

            $stmt = $conn->prepare("
                UPDATE subscriptions
                SET status = 'Expired'
                WHERE id = ?
                  AND status <> 'Suspended'
            ");

            $stmt->execute([
                (int)$authSubscription['id']
            ]);

            $stmt = $conn->prepare("
                UPDATE businesses
                SET status = 'Expired'
                WHERE id = ?
                  AND status <> 'Suspended'
            ");

            $stmt->execute([
                $authBusinessId
            ]);

            $conn->commit();

        } catch (Throwable $e) {

            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
        }

        authBlockedPage(
            'Subscription Expired',
            'Subscription-ka meheraddan wuu dhacay, grace period-kuna wuu dhammaaday. Fadlan renew samee.',
            '⌛'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | SAVE SUBSCRIPTION INTO SESSION
    |--------------------------------------------------------------------------
    */

    $_SESSION['subscription_id'] =
        (int)$authSubscription['id'];

    $_SESSION['subscription_plan'] =
        $authSubscription['plan_name'];

    $_SESSION['subscription_expiry'] =
        $authSubscription['expiry_date'];

    $_SESSION['subscription_max_branches'] =
        $authSubscription['max_branches'] !== null
            ? (int)$authSubscription['max_branches']
            : null;

    $_SESSION['subscription_in_grace'] =
        $authInGrace;

    $_SESSION['subscription_grace_days_left'] =
        $authGraceDaysLeft;
}


/*
|--------------------------------------------------------------------------
| MAINTENANCE MODE
|--------------------------------------------------------------------------
| Super Admin bypasses maintenance mode.
|--------------------------------------------------------------------------
*/

if (
    $authMaintenanceMode
    && !isSuperAdmin()
) {

    authBlockedPage(
        'Maintenance Mode',
        'System-ku hadda maintenance ayuu ku jiraa. Fadlan mar dambe isku day.',
        '🛠️'
    );
}


/*
|--------------------------------------------------------------------------
| UPDATE USER ACTIVITY
|--------------------------------------------------------------------------
| Update database no more than once per minute.
|--------------------------------------------------------------------------
*/

if (
    empty($_SESSION['last_activity_db_update'])
    || (
        $authNow
        - (int)$_SESSION['last_activity_db_update']
    ) >= 60
) {

    try {

        $stmt = $conn->prepare("
            UPDATE users
            SET last_activity = NOW()
            WHERE id = ?
        ");

        $stmt->execute([
            (int)$_SESSION['user_id']
        ]);

        $_SESSION['last_activity_db_update'] =
            $authNow;

    } catch (Throwable $e) {
        // Do not break auth if column/table update fails.
    }
}


/*
|--------------------------------------------------------------------------
| PAGE GUARDS
|--------------------------------------------------------------------------
*/

if (!function_exists('requireSuperAdmin')) {

    function requireSuperAdmin(): void
    {
        if (
            !isSuperAdmin()
            || ($_SESSION['auth_context'] ?? '') !== 'super_admin'
            || currentBusinessId() !== null
            || currentBranchId() !== null
        ) {
            authBlockedPage(
                'Access Denied',
                'Boggan waxaa geli kara Super Admin oo keliya.'
            );
        }
    }
}


if (!function_exists('requireBusinessUser')) {

    function requireBusinessUser(): void
    {
        if (
            ($_SESSION['auth_context'] ?? '') !== 'business'
            || isSuperAdmin()
            || currentBusinessId() === null
        ) {
            authBlockedPage(
                'Access Denied',
                'Boggan waxaa loogu talagalay users-ka business-ka.'
            );
        }
    }
}


if (!function_exists('requireBusinessAdmin')) {

    function requireBusinessAdmin(): void
    {
        if (
            ($_SESSION['auth_context'] ?? '') !== 'business'
            || isSuperAdmin()
            || currentRole() !== 'Business Admin'
            || currentBusinessId() === null
        ) {
            authBlockedPage(
                'Access Denied',
                'Boggan waxaa geli kara Business Admin oo keliya.'
            );
        }
    }
}
