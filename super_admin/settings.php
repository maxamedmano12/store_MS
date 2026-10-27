<?php

require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/settings.php';
require_once '../config/audit.php';

requireSuperAdmin();

if (
    !isset($_SESSION['csrf_token'])
    || empty($_SESSION['csrf_token'])
) {
    $_SESSION['csrf_token'] =
        bin2hex(
            random_bytes(32)
        );
}

function e($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

$message = '';
$messageType = '';

if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {

    $csrfToken =
        $_POST['csrf_token']
        ?? '';

    if (
        !hash_equals(
            $_SESSION['csrf_token'],
            $csrfToken
        )
    ) {

        $message =
            'Security token is invalid.';

        $messageType =
            'error';

    } else {

        $systemTitle =
            trim(
                $_POST['system_title']
                ?? ''
            );

        $timezone =
            trim(
                $_POST['timezone']
                ?? 'Africa/Mogadishu'
            );

        $currency =
            trim(
                $_POST['currency']
                ?? 'USD'
            );

        $sessionTimeout =
            (int) (
                $_POST[
                    'session_timeout_minutes'
                ]
                ?? 30
            );

        $failedLoginLimit =
            (int) (
                $_POST[
                    'failed_login_limit'
                ]
                ?? 5
            );

        $lockoutMinutes =
            (int) (
                $_POST[
                    'lockout_minutes'
                ]
                ?? 15
            );

        $subscriptionGraceDays =
            (int) (
                $_POST[
                    'subscription_grace_days'
                ]
                ?? 3
            );

        $maintenanceMode =
            isset(
                $_POST[
                    'maintenance_mode'
                ]
            )
                ? '1'
                : '0';


        $sidebarColor =
            trim(
                $_POST['sidebar_color']
                ?? '#071527'
            );

        if (
            !preg_match(
                '/^#[0-9A-Fa-f]{6}$/',
                $sidebarColor
            )
        ) {
            $sidebarColor = '#071527';
        }


        if ($systemTitle === '') {

            $message =
                'System title is required.';

            $messageType =
                'error';

        } elseif (
            $sessionTimeout < 5
            || $sessionTimeout > 1440
        ) {

            $message =
                'Session timeout must be between 5 and 1440 minutes.';

            $messageType =
                'error';

        } elseif (
            $failedLoginLimit < 1
            || $failedLoginLimit > 20
        ) {

            $message =
                'Failed login limit must be between 1 and 20.';

            $messageType =
                'error';

        } elseif (
            $lockoutMinutes < 1
            || $lockoutMinutes > 1440
        ) {

            $message =
                'Lockout duration is invalid.';

            $messageType =
                'error';

        } elseif (
            $subscriptionGraceDays < 0
            || $subscriptionGraceDays > 365
        ) {

            $message =
                'Grace days value is invalid.';

            $messageType =
                'error';

        } else {

            try {

                $oldSettings = [
                    'system_title' =>
                        getSetting(
                            $conn,
                            'system_title'
                        ),

                    'timezone' =>
                        getSetting(
                            $conn,
                            'timezone'
                        ),

                    'currency' =>
                        getSetting(
                            $conn,
                            'currency'
                        ),

                    'session_timeout_minutes' =>
                        getSetting(
                            $conn,
                            'session_timeout_minutes'
                        ),

                    'failed_login_limit' =>
                        getSetting(
                            $conn,
                            'failed_login_limit'
                        ),

                    'lockout_minutes' =>
                        getSetting(
                            $conn,
                            'lockout_minutes'
                        ),

                    'subscription_grace_days' =>
                        getSetting(
                            $conn,
                            'subscription_grace_days'
                        ),

                    'maintenance_mode' =>
                        getSetting(
                            $conn,
                            'maintenance_mode'
                        ),

                    'sidebar_color' =>
                        getSetting(
                            $conn,
                            'sidebar_color',
                            '#071527'
                        )
                ];


                $conn->beginTransaction();


                updateSetting(
                    $conn,
                    'system_title',
                    $systemTitle
                );

                updateSetting(
                    $conn,
                    'timezone',
                    $timezone
                );

                updateSetting(
                    $conn,
                    'currency',
                    $currency
                );

                updateSetting(
                    $conn,
                    'session_timeout_minutes',
                    $sessionTimeout
                );

                updateSetting(
                    $conn,
                    'failed_login_limit',
                    $failedLoginLimit
                );

                updateSetting(
                    $conn,
                    'lockout_minutes',
                    $lockoutMinutes
                );

                updateSetting(
                    $conn,
                    'subscription_grace_days',
                    $subscriptionGraceDays
                );

                updateSetting(
                    $conn,
                    'maintenance_mode',
                    $maintenanceMode
                );


                updateSetting(
                    $conn,
                    'sidebar_color',
                    $sidebarColor
                );


                $conn->commit();


                $newSettings = [
                    'system_title' =>
                        $systemTitle,

                    'timezone' =>
                        $timezone,

                    'currency' =>
                        $currency,

                    'session_timeout_minutes' =>
                        $sessionTimeout,

                    'failed_login_limit' =>
                        $failedLoginLimit,

                    'lockout_minutes' =>
                        $lockoutMinutes,

                    'subscription_grace_days' =>
                        $subscriptionGraceDays,

                    'maintenance_mode' =>
                        $maintenanceMode,

                    'sidebar_color' =>
                        $sidebarColor
                ];


                logActivity(
                    $conn,
                    'UPDATE',
                    'System Settings',
                    null,
                    'Updated system settings',
                    $oldSettings,
                    $newSettings
                );


                $message =
                    'Settings saved successfully.';

                $messageType =
                    'success';

            } catch (Throwable $e) {

                if (
                    $conn->inTransaction()
                ) {
                    $conn->rollBack();
                }

                $message =
                    'Failed to save settings.';

                $messageType =
                    'error';
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| LOAD CURRENT SETTINGS
|--------------------------------------------------------------------------
*/

$systemTitle =
    getSetting(
        $conn,
        'system_title',
        'Wholesale Management System'
    );

$timezone =
    getSetting(
        $conn,
        'timezone',
        'Africa/Mogadishu'
    );

$currency =
    getSetting(
        $conn,
        'currency',
        'USD'
    );

$sessionTimeout =
    getSetting(
        $conn,
        'session_timeout_minutes',
        '30'
    );

$failedLoginLimit =
    getSetting(
        $conn,
        'failed_login_limit',
        '5'
    );

$lockoutMinutes =
    getSetting(
        $conn,
        'lockout_minutes',
        '15'
    );

$subscriptionGraceDays =
    getSetting(
        $conn,
        'subscription_grace_days',
        '3'
    );

$maintenanceMode =
    getSetting(
        $conn,
        'maintenance_mode',
        '0'
    );


$sidebarColor =
    getSetting(
        $conn,
        'sidebar_color',
        '#071527'
    );

if (
    !preg_match(
        '/^#[0-9A-Fa-f]{6}$/',
        $sidebarColor
    )
) {
    $sidebarColor = '#071527';
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

    <title>
        System Settings
    </title>

    <link
        rel="icon"
        type="image/png"
        href="../assets/images/favicon.png"
    >

    <script src="https://cdn.tailwindcss.com"></script>

    <script src="https://unpkg.com/lucide@latest"></script>

    <link
        rel="stylesheet"
        href="../assets/css/app.css"
    >

</head>


<body class="bg-slate-100 text-slate-800">

<div class="min-h-screen">

    <?php
    require 'includes/sidebar.php';
    ?>


    <div class="lg:pl-72">

        <?php
        require 'includes/header.php';
        ?>


        <main
            class="
                page-enter
                p-4
                sm:p-6
                lg:p-8
            "
        >

            <div
                class="
                    mb-6
                    flex
                    flex-col
                    gap-4
                    xl:flex-row
                    xl:items-center
                    xl:justify-between
                "
            >

                <div>

                    <p
                        class="
                            text-sm
                            font-semibold
                            uppercase
                            tracking-wider
                            text-blue-600
                        "
                    >
                        System Control
                    </p>

                    <h1
                        class="
                            mt-1
                            text-2xl
                            font-bold
                            text-slate-900
                            sm:text-3xl
                        "
                    >
                        System Settings
                    </h1>

                    <p
                        class="
                            mt-1
                            text-sm
                            text-slate-500
                        "
                    >
                        Configure general,
                        security and subscription
                        settings.
                    </p>

                </div>


                <div
                    class="
                        flex
                        items-center
                        gap-2
                        rounded-2xl
                        bg-white
                        px-4
                        py-2.5
                        text-sm
                        text-slate-600
                        shadow-sm
                        ring-1
                        ring-slate-200
                    "
                >

                    <i
                        data-lucide="shield-check"
                        class="
                            h-4
                            w-4
                            text-emerald-600
                        "
                    ></i>

                    Super Admin Only

                </div>

            </div>


            <?php if ($message): ?>

                <div
                    class="
                        mb-6
                        rounded-2xl
                        border
                        p-4
                        text-sm
                        font-medium

                        <?= $messageType === 'success'
                            ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                            : 'border-red-200 bg-red-50 text-red-700'
                        ?>
                    "
                >
                    <?= e($message) ?>
                </div>

            <?php endif; ?>


            <form
                method="POST"
                class="
                    grid
                    grid-cols-1
                    gap-6
                    xl:grid-cols-3
                "
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(
                        $_SESSION[
                            'csrf_token'
                        ]
                    ) ?>"
                >


                <!-- GENERAL -->

                <div
                    class="
                        xl:col-span-2
                        rounded-3xl
                        border
                        border-slate-200
                        bg-white
                        shadow-sm
                    "
                >

                    <div
                        class="
                            border-b
                            border-slate-200
                            px-6
                            py-5
                        "
                    >

                        <div
                            class="
                                flex
                                items-center
                                gap-3
                            "
                        >

                            <div
                                class="
                                    flex
                                    h-11
                                    w-11
                                    items-center
                                    justify-center
                                    rounded-2xl
                                    bg-blue-50
                                    text-blue-600
                                "
                            >

                                <i
                                    data-lucide="settings"
                                    class="h-5 w-5"
                                ></i>

                            </div>


                            <div>

                                <h2
                                    class="
                                        font-bold
                                        text-slate-900
                                    "
                                >
                                    General Settings
                                </h2>

                                <p
                                    class="
                                        mt-0.5
                                        text-sm
                                        text-slate-500
                                    "
                                >
                                    Main system
                                    configuration.
                                </p>

                            </div>

                        </div>

                    </div>


                    <div
                        class="
                            grid
                            grid-cols-1
                            gap-5
                            p-6
                            md:grid-cols-2
                        "
                    >

                        <div
                            class="
                                md:col-span-2
                            "
                        >

                            <label
                                class="
                                    mb-2
                                    block
                                    text-sm
                                    font-semibold
                                    text-slate-700
                                "
                            >
                                System Title
                            </label>

                            <input
                                type="text"
                                name="system_title"
                                value="<?= e(
                                    $systemTitle
                                ) ?>"
                                required
                                class="
                                    w-full
                                    rounded-xl
                                    border
                                    border-slate-200
                                    px-4
                                    py-3
                                    text-sm
                                    outline-none
                                    transition
                                    focus:border-blue-500
                                    focus:ring-4
                                    focus:ring-blue-500/10
                                "
                            >

                        </div>


                        <div>

                            <label
                                class="
                                    mb-2
                                    block
                                    text-sm
                                    font-semibold
                                    text-slate-700
                                "
                            >
                                Timezone
                            </label>

                            <select
                                name="timezone"
                                class="
                                    w-full
                                    rounded-xl
                                    border
                                    border-slate-200
                                    bg-white
                                    px-4
                                    py-3
                                    text-sm
                                "
                            >

                                <?php
                                $timezones = [
                                    'Africa/Mogadishu',
                                    'Africa/Nairobi',
                                    'UTC'
                                ];
                                ?>

                                <?php foreach (
                                    $timezones
                                    as $zone
                                ): ?>

                                    <option
                                        value="<?= e(
                                            $zone
                                        ) ?>"
                                        <?= $timezone === $zone
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >
                                        <?= e($zone) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <div>

                            <label
                                class="
                                    mb-2
                                    block
                                    text-sm
                                    font-semibold
                                    text-slate-700
                                "
                            >
                                Currency
                            </label>

                            <select
                                name="currency"
                                class="
                                    w-full
                                    rounded-xl
                                    border
                                    border-slate-200
                                    bg-white
                                    px-4
                                    py-3
                                    text-sm
                                "
                            >

                                <?php
                                $currencies = [
                                    'USD',
                                    'SOS',
                                    'SLSH'
                                ];
                                ?>

                                <?php foreach (
                                    $currencies
                                    as $currencyOption
                                ): ?>

                                    <option
                                        value="<?= e(
                                            $currencyOption
                                        ) ?>"
                                        <?= $currency ===
                                            $currencyOption
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >
                                        <?= e(
                                            $currencyOption
                                        ) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <div class="md:col-span-2">

                            <label
                                class="
                                    mb-2
                                    block
                                    text-sm
                                    font-semibold
                                    text-slate-700
                                "
                            >
                                Sidebar Color
                            </label>

                            <div
                                class="
                                    flex
                                    flex-col
                                    gap-3
                                    sm:flex-row
                                    sm:items-center
                                "
                            >

                                <input
                                    id="sidebarColor"
                                    type="color"
                                    name="sidebar_color"
                                    value="<?= e($sidebarColor) ?>"
                                    class="
                                        h-12
                                        w-full
                                        cursor-pointer
                                        rounded-xl
                                        border
                                        border-slate-200
                                        bg-white
                                        p-1
                                        sm:w-20
                                    "
                                >

                                <input
                                    id="sidebarColorText"
                                    type="text"
                                    value="<?= e($sidebarColor) ?>"
                                    readonly
                                    class="
                                        w-full
                                        rounded-xl
                                        border
                                        border-slate-200
                                        bg-slate-50
                                        px-4
                                        py-3
                                        text-sm
                                        font-semibold
                                        uppercase
                                        text-slate-700
                                    "
                                >

                                <button
                                    id="resetSidebarColor"
                                    type="button"
                                    class="
                                        inline-flex
                                        h-12
                                        items-center
                                        justify-center
                                        gap-2
                                        rounded-xl
                                        border
                                        border-slate-200
                                        bg-white
                                        px-4
                                        text-sm
                                        font-semibold
                                        text-slate-600
                                        transition
                                        hover:border-blue-200
                                        hover:bg-blue-50
                                        hover:text-blue-600
                                    "
                                >
                                    <i
                                        data-lucide="rotate-ccw"
                                        class="h-4 w-4"
                                    ></i>
                                    Default
                                </button>

                            </div>

                            <div
                                class="
                                    mt-3
                                    flex
                                    flex-wrap
                                    gap-2
                                "
                            >
                                <?php
                                $sidebarPresets = [
                                    '#071527',
                                    '#0f172a',
                                    '#111827',
                                    '#1e3a8a',
                                    '#312e81',
                                    '#3b0764',
                                    '#064e3b'
                                ];
                                ?>

                                <?php foreach ($sidebarPresets as $preset): ?>
                                    <button
                                        type="button"
                                        class="sidebar-color-preset h-9 w-9 rounded-xl border-2 border-white shadow ring-1 ring-slate-200 transition hover:scale-110"
                                        data-color="<?= e($preset) ?>"
                                        style="background-color: <?= e($preset) ?>;"
                                        aria-label="Use <?= e($preset) ?> sidebar color"
                                    ></button>
                                <?php endforeach; ?>
                            </div>

                            <p
                                class="
                                    mt-2
                                    text-xs
                                    text-slate-500
                                "
                            >
                                This color is applied to the System Admin sidebar on every page.
                            </p>

                        </div>

                    </div>

                </div>


                <!-- STATUS -->

                <div
                    class="
                        rounded-3xl
                        border
                        border-slate-200
                        bg-white
                        p-6
                        shadow-sm
                    "
                >

                    <div
                        class="
                            flex
                            items-center
                            gap-3
                        "
                    >

                        <div
                            class="
                                flex
                                h-11
                                w-11
                                items-center
                                justify-center
                                rounded-2xl
                                bg-violet-50
                                text-violet-600
                            "
                        >

                            <i
                                data-lucide="server"
                                class="h-5 w-5"
                            ></i>

                        </div>


                        <div>

                            <h2
                                class="
                                    font-bold
                                    text-slate-900
                                "
                            >
                                System Status
                            </h2>

                            <p
                                class="
                                    text-sm
                                    text-slate-500
                                "
                            >
                                Availability control.
                            </p>

                        </div>

                    </div>


                    <label
                        class="
                            mt-6
                            flex
                            cursor-pointer
                            items-center
                            justify-between
                            rounded-2xl
                            border
                            border-slate-200
                            p-4
                        "
                    >

                        <div>

                            <p
                                class="
                                    text-sm
                                    font-bold
                                    text-slate-800
                                "
                            >
                                Maintenance Mode
                            </p>

                            <p
                                class="
                                    mt-1
                                    text-xs
                                    text-slate-500
                                "
                            >
                                Block normal business
                                users temporarily.
                            </p>

                        </div>


                        <input
                            type="checkbox"
                            name="maintenance_mode"
                            value="1"
                            class="
                                h-5
                                w-5
                                rounded
                                border-slate-300
                                text-blue-600
                            "
                            <?= $maintenanceMode === '1'
                                ? 'checked'
                                : ''
                            ?>
                        >

                    </label>

                </div>


                <!-- SECURITY -->

                <div
                    class="
                        xl:col-span-2
                        rounded-3xl
                        border
                        border-slate-200
                        bg-white
                        shadow-sm
                    "
                >

                    <div
                        class="
                            border-b
                            border-slate-200
                            px-6
                            py-5
                        "
                    >

                        <div
                            class="
                                flex
                                items-center
                                gap-3
                            "
                        >

                            <div
                                class="
                                    flex
                                    h-11
                                    w-11
                                    items-center
                                    justify-center
                                    rounded-2xl
                                    bg-red-50
                                    text-red-600
                                "
                            >

                                <i
                                    data-lucide="shield"
                                    class="h-5 w-5"
                                ></i>

                            </div>


                            <div>

                                <h2
                                    class="
                                        font-bold
                                        text-slate-900
                                    "
                                >
                                    Security Settings
                                </h2>

                                <p
                                    class="
                                        mt-0.5
                                        text-sm
                                        text-slate-500
                                    "
                                >
                                    Session and login
                                    protection.
                                </p>

                            </div>

                        </div>

                    </div>


                    <div
                        class="
                            grid
                            grid-cols-1
                            gap-5
                            p-6
                            md:grid-cols-3
                        "
                    >

                        <div>

                            <label
                                class="
                                    mb-2
                                    block
                                    text-sm
                                    font-semibold
                                    text-slate-700
                                "
                            >
                                Session Timeout
                            </label>

                            <div
                                class="relative"
                            >

                                <input
                                    type="number"
                                    name="session_timeout_minutes"
                                    min="5"
                                    max="1440"
                                    value="<?= e(
                                        $sessionTimeout
                                    ) ?>"
                                    class="
                                        w-full
                                        rounded-xl
                                        border
                                        border-slate-200
                                        px-4
                                        py-3
                                        pr-16
                                        text-sm
                                        outline-none
                                        focus:border-blue-500
                                    "
                                >

                                <span
                                    class="
                                        absolute
                                        right-4
                                        top-3
                                        text-xs
                                        text-slate-400
                                    "
                                >
                                    min
                                </span>

                            </div>

                        </div>


                        <div>

                            <label
                                class="
                                    mb-2
                                    block
                                    text-sm
                                    font-semibold
                                    text-slate-700
                                "
                            >
                                Failed Login Limit
                            </label>

                            <input
                                type="number"
                                name="failed_login_limit"
                                min="1"
                                max="20"
                                value="<?= e(
                                    $failedLoginLimit
                                ) ?>"
                                class="
                                    w-full
                                    rounded-xl
                                    border
                                    border-slate-200
                                    px-4
                                    py-3
                                    text-sm
                                    outline-none
                                    focus:border-blue-500
                                "
                            >

                        </div>


                        <div>

                            <label
                                class="
                                    mb-2
                                    block
                                    text-sm
                                    font-semibold
                                    text-slate-700
                                "
                            >
                                Lockout Duration
                            </label>

                            <div
                                class="relative"
                            >

                                <input
                                    type="number"
                                    name="lockout_minutes"
                                    min="1"
                                    max="1440"
                                    value="<?= e(
                                        $lockoutMinutes
                                    ) ?>"
                                    class="
                                        w-full
                                        rounded-xl
                                        border
                                        border-slate-200
                                        px-4
                                        py-3
                                        pr-16
                                        text-sm
                                        outline-none
                                        focus:border-blue-500
                                    "
                                >

                                <span
                                    class="
                                        absolute
                                        right-4
                                        top-3
                                        text-xs
                                        text-slate-400
                                    "
                                >
                                    min
                                </span>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- SUBSCRIPTION -->

                <div
                    class="
                        rounded-3xl
                        border
                        border-slate-200
                        bg-white
                        p-6
                        shadow-sm
                    "
                >

                    <div
                        class="
                            flex
                            items-center
                            gap-3
                        "
                    >

                        <div
                            class="
                                flex
                                h-11
                                w-11
                                items-center
                                justify-center
                                rounded-2xl
                                bg-emerald-50
                                text-emerald-600
                            "
                        >

                            <i
                                data-lucide="calendar-clock"
                                class="h-5 w-5"
                            ></i>

                        </div>


                        <div>

                            <h2
                                class="
                                    font-bold
                                    text-slate-900
                                "
                            >
                                Subscription
                            </h2>

                            <p
                                class="
                                    text-sm
                                    text-slate-500
                                "
                            >
                                Expiry behavior.
                            </p>

                        </div>

                    </div>


                    <div class="mt-6">

                        <label
                            class="
                                mb-2
                                block
                                text-sm
                                font-semibold
                                text-slate-700
                            "
                        >
                            Grace Period
                        </label>

                        <div class="relative">

                            <input
                                type="number"
                                name="subscription_grace_days"
                                min="0"
                                max="365"
                                value="<?= e(
                                    $subscriptionGraceDays
                                ) ?>"
                                class="
                                    w-full
                                    rounded-xl
                                    border
                                    border-slate-200
                                    px-4
                                    py-3
                                    pr-16
                                    text-sm
                                    outline-none
                                    focus:border-blue-500
                                "
                            >

                            <span
                                class="
                                    absolute
                                    right-4
                                    top-3
                                    text-xs
                                    text-slate-400
                                "
                            >
                                days
                            </span>

                        </div>

                    </div>

                </div>


                <!-- SAVE -->

                <div
                    class="
                        xl:col-span-3
                        flex
                        justify-end
                    "
                >

                    <button
                        type="submit"
                        class="
                            inline-flex
                            items-center
                            gap-2
                            rounded-xl
                            bg-blue-600
                            px-6
                            py-3
                            text-sm
                            font-bold
                            text-white
                            shadow-lg
                            shadow-blue-600/20
                            transition
                            hover:-translate-y-0.5
                            hover:bg-blue-700
                        "
                    >

                        <i
                            data-lucide="save"
                            class="h-4 w-4"
                        ></i>

                        Save Settings

                    </button>

                </div>

            </form>

        </main>


        <?php
        require 'includes/footer.php';
        ?>

    </div>

</div>


<script src="../assets/js/app.js"></script>

<script>

if (
    typeof lucide !== 'undefined'
) {
    lucide.createIcons();
}


const sidebarColorInput =
    document.getElementById('sidebarColor');

const sidebarColorText =
    document.getElementById('sidebarColorText');

const sidebarElement =
    document.getElementById('sidebar');

function applySidebarPreview(color) {
    if (sidebarColorText) {
        sidebarColorText.value = color.toUpperCase();
    }

    if (sidebarElement) {
        sidebarElement.style.backgroundColor = color;
        sidebarElement.style.backgroundImage = 'none';
    }
}

sidebarColorInput?.addEventListener(
    'input',
    function () {
        applySidebarPreview(this.value);
    }
);

document
    .querySelectorAll('.sidebar-color-preset')
    .forEach(function (button) {
        button.addEventListener('click', function () {
            const color = this.dataset.color;

            if (!color || !sidebarColorInput) {
                return;
            }

            sidebarColorInput.value = color;
            applySidebarPreview(color);
        });
    });

document
    .getElementById('resetSidebarColor')
    ?.addEventListener('click', function () {
        const defaultColor = '#071527';

        if (sidebarColorInput) {
            sidebarColorInput.value = defaultColor;
        }

        applySidebarPreview(defaultColor);
    });

</script>

</body>

</html>