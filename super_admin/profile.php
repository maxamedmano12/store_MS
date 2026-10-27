<?php
require_once '../config/database.php';

require_once '../config/auth.php';
require_once '../config/audit.php';

requireSuperAdmin();

if (
    !isset($_SESSION['csrf_token'])
    || empty($_SESSION['csrf_token'])
) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
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

$userId = (int) $_SESSION['user_id'];


/*
|--------------------------------------------------------------------------
| GET CURRENT PROFILE
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare("
    SELECT
        id,
        full_name,
        username,
        password,
        status,
        created_at
    FROM users
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$userId]);

$profile = $stmt->fetch();

if (!$profile) {
    header('Location: ../logout.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| UPDATE PROFILE
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $csrfToken = $_POST['csrf_token'] ?? '';

    if (
        !hash_equals(
            $_SESSION['csrf_token'],
            $csrfToken
        )
    ) {
        $message = 'Security token-ka waa khalad.';
        $messageType = 'error';

    } else {

        $fullName = trim(
            $_POST['full_name'] ?? ''
        );

        $username = trim(
            $_POST['username'] ?? ''
        );

        $currentPassword =
            $_POST['current_password']
            ?? '';

        $newPassword =
            $_POST['new_password']
            ?? '';

        $confirmPassword =
            $_POST['confirm_password']
            ?? '';


        if ($fullName === '') {

            $message = 'Full name waa required.';
            $messageType = 'error';

        } elseif ($username === '') {

            $message = 'Username waa required.';
            $messageType = 'error';

        } else {

            try {

                /*
                |--------------------------------------------------------------------------
                | USERNAME UNIQUE CHECK
                |--------------------------------------------------------------------------
                */

                $check = $conn->prepare("
                    SELECT id
                    FROM users
                    WHERE username = ?
                    AND id <> ?
                    LIMIT 1
                ");

                $check->execute([
                    $username,
                    $userId
                ]);

                if ($check->fetch()) {
                    throw new Exception(
                        'Username-kan user kale ayaa isticmaalaya.'
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | OLD VALUES
                |--------------------------------------------------------------------------
                */

                $oldValues = [
                    'full_name' =>
                        $profile['full_name'],

                    'username' =>
                        $profile['username']
                ];


                /*
                |--------------------------------------------------------------------------
                | PASSWORD CHANGE
                |--------------------------------------------------------------------------
                */

                $passwordSql = '';
                $params = [
                    $fullName,
                    $username
                ];

                if ($newPassword !== '') {

                    if ($currentPassword === '') {
                        throw new Exception(
                            'Geli current password-kaaga.'
                        );
                    }

                    if (
                        !password_verify(
                            $currentPassword,
                            $profile['password']
                        )
                    ) {
                        throw new Exception(
                            'Current password waa khalad.'
                        );
                    }

                    if (strlen($newPassword) < 6) {
                        throw new Exception(
                            'New password ugu yaraan 6 xaraf ha noqdo.'
                        );
                    }

                    if (
                        $newPassword
                        !== $confirmPassword
                    ) {
                        throw new Exception(
                            'Passwords isku mid ma aha.'
                        );
                    }

                    $hashedPassword =
                        password_hash(
                            $newPassword,
                            PASSWORD_DEFAULT
                        );

                    $passwordSql =
                        ', password = ?';

                    $params[] =
                        $hashedPassword;
                }


                $params[] = $userId;


                /*
                |--------------------------------------------------------------------------
                | UPDATE
                |--------------------------------------------------------------------------
                */

                $update = $conn->prepare("
                    UPDATE users
                    SET
                        full_name = ?,
                        username = ?
                        $passwordSql
                    WHERE id = ?
                ");

                $update->execute($params);


                /*
                |--------------------------------------------------------------------------
                | REFRESH SESSION
                |--------------------------------------------------------------------------
                */

                $_SESSION['full_name'] =
                    $fullName;

                $_SESSION['username'] =
                    $username;


                /*
                |--------------------------------------------------------------------------
                | AUDIT
                |--------------------------------------------------------------------------
                */

                logActivity(
                    $conn,
                    'UPDATE',
                    'Profile',
                    $userId,
                    'Super Admin updated profile',
                    $oldValues,
                    [
                        'full_name' =>
                            $fullName,

                        'username' =>
                            $username,

                        'password_changed' =>
                            $newPassword !== ''
                    ]
                );


                $message =
                    'Profile-ka waa la update gareeyay.';

                $messageType =
                    'success';


                /*
                |--------------------------------------------------------------------------
                | RELOAD
                |--------------------------------------------------------------------------
                */

                $stmt->execute([$userId]);

                $profile =
                    $stmt->fetch();

            } catch (Throwable $e) {

                $message =
                    $e->getMessage();

                $messageType =
                    'error';
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

<title>My Profile</title>

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


<body
    class="
        bg-[#f7f9fc]
        text-slate-800
        antialiased
        dark:bg-slate-950
        dark:text-slate-100
    "
>

<?php
require_once 'includes/sidebar.php';
?>

<div class="min-h-screen lg:ml-[270px]">

<?php
require_once 'includes/header.php';
?>


<main
    class="
        page-enter
        px-4 py-6
        md:px-7 md:py-7
    "
>


    <!-- PAGE HEADER -->

    <div class="mb-6">

        <div
            class="
                mb-2
                flex items-center
                gap-2
                text-[11px]
                text-slate-400
            "
        >

            <a
                href="dashboard.php"
                class="hover:text-blue-600"
            >
                Dashboard
            </a>

            <i
                data-lucide="chevron-right"
                class="h-3 w-3"
            ></i>

            <span>
                My Profile
            </span>

        </div>


        <h1
            class="
                text-2xl
                font-extrabold
                tracking-tight
                text-slate-900
                dark:text-white
            "
        >
            My Profile
        </h1>

        <p
            class="
                mt-1
                text-[12px]
                text-slate-500
            "
        >
            Manage your Super Admin
            account information.
        </p>

    </div>


    <?php if ($message !== ''): ?>

        <div
            class="
                mb-5
                rounded-xl
                border
                p-4
                text-sm
                <?= $messageType === 'success'
                    ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                    : 'border-red-200 bg-red-50 text-red-700'
                ?>
            "
        >
            <?= e($message) ?>
        </div>

    <?php endif; ?>


    <div
        class="
            grid
            grid-cols-1
            gap-6
            xl:grid-cols-3
        "
    >


        <!-- PROFILE CARD -->

        <div
            class="
                rounded-3xl
                border
                border-slate-200
                bg-white
                p-6
                text-center
                shadow-sm
                dark:border-slate-800
                dark:bg-slate-900
            "
        >

            <div
                class="
                    mx-auto
                    flex
                    h-24
                    w-24
                    items-center
                    justify-center
                    rounded-3xl
                    bg-gradient-to-br
                    from-blue-500
                    to-indigo-600
                    text-3xl
                    font-black
                    text-white
                    shadow-xl
                    shadow-blue-500/20
                "
            >
                <?= e(
                    strtoupper(
                        substr(
                            $profile['full_name'],
                            0,
                            1
                        )
                    )
                ) ?>
            </div>


            <h2
                class="
                    mt-5
                    text-lg
                    font-bold
                    text-slate-900
                    dark:text-white
                "
            >
                <?= e(
                    $profile['full_name']
                ) ?>
            </h2>


            <p
                class="
                    mt-1
                    text-sm
                    text-slate-500
                "
            >
                @<?= e(
                    $profile['username']
                ) ?>
            </p>


            <div
                class="
                    mt-4
                    inline-flex
                    items-center
                    gap-2
                    rounded-full
                    bg-blue-50
                    px-3
                    py-1.5
                    text-xs
                    font-bold
                    text-blue-600
                "
            >

                <i
                    data-lucide="shield-check"
                    class="h-4 w-4"
                ></i>

                Super Admin

            </div>


            <div
                class="
                    mt-6
                    border-t
                    border-slate-100
                    pt-5
                    text-left
                    dark:border-slate-800
                "
            >

                <p
                    class="
                        text-xs
                        text-slate-400
                    "
                >
                    Account Created
                </p>

                <p
                    class="
                        mt-1
                        text-sm
                        font-semibold
                    "
                >
                    <?= e(
                        $profile['created_at']
                    ) ?>
                </p>

            </div>

        </div>


        <!-- FORM -->

        <div
            class="
                xl:col-span-2
                rounded-3xl
                border
                border-slate-200
                bg-white
                shadow-sm
                dark:border-slate-800
                dark:bg-slate-900
            "
        >

            <div
                class="
                    border-b
                    border-slate-200
                    px-6
                    py-5
                    dark:border-slate-800
                "
            >

                <h2
                    class="
                        font-bold
                        text-slate-900
                        dark:text-white
                    "
                >
                    Account Settings
                </h2>

                <p
                    class="
                        mt-1
                        text-xs
                        text-slate-500
                    "
                >
                    Update your personal
                    and login information.
                </p>

            </div>


            <form
                method="POST"
                class="p-6"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e(
                        $_SESSION['csrf_token']
                    ) ?>"
                >


                <div
                    class="
                        grid
                        grid-cols-1
                        gap-5
                        md:grid-cols-2
                    "
                >

                    <div>

                        <label
                            class="
                                mb-2
                                block
                                text-xs
                                font-bold
                            "
                        >
                            Full Name
                        </label>

                        <input
                            type="text"
                            name="full_name"
                            required
                            value="<?= e(
                                $profile['full_name']
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
                                transition
                                focus:border-blue-500
                                focus:ring-4
                                focus:ring-blue-500/10
                                dark:border-slate-700
                                dark:bg-slate-800
                            "
                        >

                    </div>


                    <div>

                        <label
                            class="
                                mb-2
                                block
                                text-xs
                                font-bold
                            "
                        >
                            Username
                        </label>

                        <input
                            type="text"
                            name="username"
                            required
                            value="<?= e(
                                $profile['username']
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
                                focus:ring-4
                                focus:ring-blue-500/10
                                dark:border-slate-700
                                dark:bg-slate-800
                            "
                        >

                    </div>

                </div>


                <div
                    class="
                        my-6
                        border-t
                        border-slate-100
                        dark:border-slate-800
                    "
                ></div>


                <div class="mb-4">

                    <h3
                        class="
                            text-sm
                            font-bold
                        "
                    >
                        Change Password
                    </h3>

                    <p
                        class="
                            mt-1
                            text-xs
                            text-slate-500
                        "
                    >
                        Leave these fields
                        empty if you do not
                        want to change password.
                    </p>

                </div>


                <div
                    class="
                        grid
                        grid-cols-1
                        gap-5
                        md:grid-cols-3
                    "
                >

                    <div>

                        <label
                            class="
                                mb-2
                                block
                                text-xs
                                font-bold
                            "
                        >
                            Current Password
                        </label>

                        <input
                            type="password"
                            name="current_password"
                            autocomplete="current-password"
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
                                dark:border-slate-700
                                dark:bg-slate-800
                            "
                        >

                    </div>


                    <div>

                        <label
                            class="
                                mb-2
                                block
                                text-xs
                                font-bold
                            "
                        >
                            New Password
                        </label>

                        <input
                            type="password"
                            name="new_password"
                            autocomplete="new-password"
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
                                dark:border-slate-700
                                dark:bg-slate-800
                            "
                        >

                    </div>


                    <div>

                        <label
                            class="
                                mb-2
                                block
                                text-xs
                                font-bold
                            "
                        >
                            Confirm Password
                        </label>

                        <input
                            type="password"
                            name="confirm_password"
                            autocomplete="new-password"
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
                                dark:border-slate-700
                                dark:bg-slate-800
                            "
                        >

                    </div>

                </div>


                <div
                    class="
                        mt-6
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
                            px-5
                            py-3
                            text-xs
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

                        Save Profile

                    </button>

                </div>

            </form>

        </div>

    </div>

</main>


<?php
require_once 'includes/footer.php';
?>

</div>


<script src="../assets/js/app.js"></script>

<script>
if (typeof lucide !== 'undefined') {
    lucide.createIcons();
}
</script>

</body>

</html>