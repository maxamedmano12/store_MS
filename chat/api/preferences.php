<?php

require_once __DIR__ . '/_bootstrap.php';

$userId =
    (int)($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {

    chatJson([
        'ok' => false,
        'message' => 'Unauthorized.'
    ], 401);
}


/*
|--------------------------------------------------------------------------
| BACKGROUND IMAGE STREAM
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && ($_GET['action'] ?? '') === 'background'
) {

    $stmt =
        $conn->prepare("
            SELECT background_image
            FROM chat_preferences
            WHERE user_id = ?
            LIMIT 1
        ");

    $stmt->execute([
        $userId
    ]);

    $image =
        $stmt->fetchColumn();


    if (!$image) {

        http_response_code(404);
        exit;
    }


    $folder =
        dirname(__DIR__)
        . '/uploads/backgrounds';


    $root =
        realpath($folder);

    if ($root === false) {

        http_response_code(404);
        exit;
    }


    $file =
        realpath(
            $root
            . DIRECTORY_SEPARATOR
            . basename((string)$image)
        );


    if (
        $file === false
        || !is_file($file)
        || strpos(
            $file,
            $root . DIRECTORY_SEPARATOR
        ) !== 0
    ) {

        http_response_code(404);
        exit;
    }


    $finfo =
        new finfo(FILEINFO_MIME_TYPE);

    $mime =
        (string)$finfo->file($file);


    if (
        !in_array(
            $mime,
            [
                'image/jpeg',
                'image/png',
                'image/webp'
            ],
            true
        )
    ) {

        http_response_code(404);
        exit;
    }


    header(
        'Content-Type: '
        . $mime
    );

    header(
        'Cache-Control: private, max-age=300'
    );

    readfile($file);

    exit;
}


/*
|--------------------------------------------------------------------------
| GET PREFERENCES
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $stmt =
        $conn->prepare("
            SELECT
                theme_color,
                background_type,
                background_color,
                background_image,
                dark_mode
            FROM chat_preferences
            WHERE user_id = ?
            LIMIT 1
        ");

    $stmt->execute([
        $userId
    ]);

    $preferences =
        $stmt->fetch(PDO::FETCH_ASSOC);


    if (!$preferences) {

        $preferences = [

            'theme_color' =>
                '#2563eb',

            'background_type' =>
                'color',

            'background_color' =>
                '#f8fafc',

            'background_image' =>
                null,

            'dark_mode' =>
                0
        ];
    }


    $preferences['background_url'] =
        !empty(
            $preferences['background_image']
        )
            ? '../chat/api/preferences.php?action=background&v='
                . time()
            : null;


    chatJson([
        'ok' => true,
        'preferences' =>
            $preferences
    ]);
}


/*
|--------------------------------------------------------------------------
| POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    chatJson([
        'ok' => false,
        'message' => 'Method not allowed.'
    ], 405);
}


/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

$csrf =
    (string)($_POST['csrf_token'] ?? '');

$sessionCsrf =
    (string)($_SESSION['csrf_token'] ?? '');

if (
    $csrf === ''
    || $sessionCsrf === ''
    || !hash_equals(
        $sessionCsrf,
        $csrf
    )
) {

    chatJson([
        'ok' => false,
        'message' =>
            'Invalid CSRF token.'
    ], 403);
}


/*
|--------------------------------------------------------------------------
| CURRENT
|--------------------------------------------------------------------------
*/

$stmt =
    $conn->prepare("
        SELECT *
        FROM chat_preferences
        WHERE user_id = ?
        LIMIT 1
    ");

$stmt->execute([
    $userId
]);

$current =
    $stmt->fetch(PDO::FETCH_ASSOC);


if (!$current) {

    $current = [

        'theme_color' =>
            '#2563eb',

        'background_type' =>
            'color',

        'background_color' =>
            '#f8fafc',

        'background_image' =>
            null,

        'dark_mode' =>
            0

    ];
}


/*
|--------------------------------------------------------------------------
| VALUES
|--------------------------------------------------------------------------
*/

$themeColor =
    trim(
        (string)(
            $_POST['theme_color']
            ?? $current['theme_color']
        )
    );


$backgroundColor =
    trim(
        (string)(
            $_POST['background_color']
            ?? $current['background_color']
        )
    );


$backgroundType =
    trim(
        (string)(
            $_POST['background_type']
            ?? $current['background_type']
        )
    );


$darkMode =
    (int)(
        $_POST['dark_mode']
        ?? $current['dark_mode']
    ) === 1
        ? 1
        : 0;


/*
|--------------------------------------------------------------------------
| COLOR VALIDATION
|--------------------------------------------------------------------------
*/

if (
    !preg_match(
        '/^#[0-9A-Fa-f]{6}$/',
        $themeColor
    )
) {
    $themeColor =
        '#2563eb';
}


if (
    !preg_match(
        '/^#[0-9A-Fa-f]{6}$/',
        $backgroundColor
    )
) {
    $backgroundColor =
        '#f8fafc';
}


if (
    !in_array(
        $backgroundType,
        ['color', 'image'],
        true
    )
) {
    $backgroundType =
        'color';
}


$backgroundImage =
    $current['background_image'];


/*
|--------------------------------------------------------------------------
| REMOVE BACKGROUND
|--------------------------------------------------------------------------
*/

$removeBackground =
    (int)(
        $_POST['remove_background']
        ?? 0
    ) === 1;


if ($removeBackground) {

    if ($backgroundImage) {

        $old =
            dirname(__DIR__)
            . '/uploads/backgrounds/'
            . basename(
                (string)$backgroundImage
            );

        if (is_file($old)) {
            @unlink($old);
        }
    }

    $backgroundImage = null;
    $backgroundType = 'color';
}


/*
|--------------------------------------------------------------------------
| NEW BACKGROUND IMAGE
|--------------------------------------------------------------------------
*/

if (
    isset(
        $_FILES['background_image']
    )
    && is_array(
        $_FILES['background_image']
    )
    && (
        $_FILES['background_image']['error']
        ?? UPLOAD_ERR_NO_FILE
    ) === UPLOAD_ERR_OK
) {

    $file =
        $_FILES['background_image'];


    if (
        (int)$file['size']
        >
        (8 * 1024 * 1024)
    ) {

        chatJson([
            'ok' => false,
            'message' =>
                'Background image must be under 8MB.'
        ], 422);
    }


    $finfo =
        new finfo(
            FILEINFO_MIME_TYPE
        );

    $mime =
        strtolower(
            (string)$finfo->file(
                $file['tmp_name']
            )
        );


    $allowed = [

        'image/jpeg' =>
            'jpg',

        'image/png' =>
            'png',

        'image/webp' =>
            'webp'

    ];


    if (!isset($allowed[$mime])) {

        chatJson([
            'ok' => false,
            'message' =>
                'Background must be JPG, PNG or WEBP.'
        ], 422);
    }


    $folder =
        dirname(__DIR__)
        . '/uploads/backgrounds';


    if (!is_dir($folder)) {

        mkdir(
            $folder,
            0755,
            true
        );
    }


    $newName =
        'background_'
        . $userId
        . '_'
        . bin2hex(
            random_bytes(12)
        )
        . '.'
        . $allowed[$mime];


    $destination =
        $folder
        . '/'
        . $newName;


    if (
        !move_uploaded_file(
            $file['tmp_name'],
            $destination
        )
    ) {

        chatJson([
            'ok' => false,
            'message' =>
                'Background could not be saved.'
        ], 500);
    }


    if ($backgroundImage) {

        $old =
            $folder
            . '/'
            . basename(
                (string)$backgroundImage
            );

        if (is_file($old)) {
            @unlink($old);
        }
    }


    $backgroundImage =
        $newName;

    $backgroundType =
        'image';
}


/*
|--------------------------------------------------------------------------
| SAVE
|--------------------------------------------------------------------------
*/

$stmt =
    $conn->prepare("
        INSERT INTO chat_preferences (
            user_id,
            theme_color,
            background_type,
            background_color,
            background_image,
            dark_mode
        )
        VALUES (?, ?, ?, ?, ?, ?)

        ON DUPLICATE KEY UPDATE

            theme_color =
                VALUES(theme_color),

            background_type =
                VALUES(background_type),

            background_color =
                VALUES(background_color),

            background_image =
                VALUES(background_image),

            dark_mode =
                VALUES(dark_mode)
    ");


$stmt->execute([

    $userId,

    $themeColor,

    $backgroundType,

    $backgroundColor,

    $backgroundImage,

    $darkMode

]);


chatJson([
    'ok' => true,

    'message' =>
        'Chat appearance updated.',

    'preferences' => [

        'theme_color' =>
            $themeColor,

        'background_type' =>
            $backgroundType,

        'background_color' =>
            $backgroundColor,

        'background_image' =>
            $backgroundImage,

        'background_url' =>
            $backgroundImage
                ? '../chat/api/preferences.php?action=background&v='
                    . time()
                : null,

        'dark_mode' =>
            $darkMode

    ]
]);