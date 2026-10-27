<?php

require_once __DIR__ . '/_bootstrap.php';

$currentUserId =
    (int)($_SESSION['user_id'] ?? 0);

if ($currentUserId <= 0) {

    chatJson([
        'ok' => false,
        'message' => 'Unauthorized.'
    ], 401);
}


/*
|--------------------------------------------------------------------------
| GET PROFILE PICTURE
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $targetUserId =
        (int)(
            $_GET['user_id']
            ?? $currentUserId
        );

    if ($targetUserId <= 0) {
        http_response_code(422);
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | ACCESS
    |--------------------------------------------------------------------------
    */

    if (
        $targetUserId !== $currentUserId
        && !chatCanContact($targetUserId)
    ) {

        http_response_code(403);
        exit;
    }


    $stmt =
        $conn->prepare("
            SELECT profile_picture
            FROM users
            WHERE id = ?
              AND status = 'Active'
            LIMIT 1
        ");

    $stmt->execute([
        $targetUserId
    ]);

    $path =
        $stmt->fetchColumn();


    if (
        !$path
        || trim((string)$path) === ''
    ) {

        http_response_code(404);
        exit;
    }


    $uploadRoot =
        dirname(__DIR__)
        . '/uploads/profiles';


    $rootReal =
        realpath($uploadRoot);

    if ($rootReal === false) {
        http_response_code(404);
        exit;
    }


    $filePath =
        $rootReal
        . DIRECTORY_SEPARATOR
        . basename((string)$path);


    $fileReal =
        realpath($filePath);


    if (
        $fileReal === false
        || !is_file($fileReal)
        || strpos(
            $fileReal,
            $rootReal . DIRECTORY_SEPARATOR
        ) !== 0
    ) {

        http_response_code(404);
        exit;
    }


    $finfo =
        new finfo(FILEINFO_MIME_TYPE);

    $mime =
        (string)$finfo->file(
            $fileReal
        );


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
        'Content-Length: '
        . filesize($fileReal)
    );

    header(
        'Cache-Control: private, max-age=300'
    );

    readfile($fileReal);

    exit;
}


/*
|--------------------------------------------------------------------------
| POST ONLY AFTER GET
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
        'message' => 'Invalid CSRF token.'
    ], 403);
}


/*
|--------------------------------------------------------------------------
| REMOVE PROFILE PICTURE
|--------------------------------------------------------------------------
*/

$action =
    trim(
        (string)($_POST['action'] ?? '')
    );


if ($action === 'remove') {

    $stmt =
        $conn->prepare("
            SELECT profile_picture
            FROM users
            WHERE id = ?
            LIMIT 1
        ");

    $stmt->execute([
        $currentUserId
    ]);

    $old =
        $stmt->fetchColumn();


    $update =
        $conn->prepare("
            UPDATE users
            SET profile_picture = NULL
            WHERE id = ?
        ");

    $update->execute([
        $currentUserId
    ]);


    if ($old) {

        $oldPath =
            dirname(__DIR__)
            . '/uploads/profiles/'
            . basename((string)$old);

        if (is_file($oldPath)) {
            @unlink($oldPath);
        }
    }


    chatJson([
        'ok' => true,
        'message' =>
            'Profile picture removed.'
    ]);
}


/*
|--------------------------------------------------------------------------
| FILE
|--------------------------------------------------------------------------
*/

if (
    !isset($_FILES['profile_picture'])
    || !is_array(
        $_FILES['profile_picture']
    )
) {

    chatJson([
        'ok' => false,
        'message' =>
            'Choose a profile picture.'
    ], 422);
}


$file =
    $_FILES['profile_picture'];


if (
    ($file['error'] ?? UPLOAD_ERR_NO_FILE)
    !== UPLOAD_ERR_OK
) {

    chatJson([
        'ok' => false,
        'message' =>
            'Profile picture upload failed.'
    ], 422);
}


$tmp =
    (string)$file['tmp_name'];

$size =
    (int)$file['size'];


/*
|--------------------------------------------------------------------------
| MAX 5MB
|--------------------------------------------------------------------------
*/

if (
    $size <= 0
    || $size >
        (5 * 1024 * 1024)
) {

    chatJson([
        'ok' => false,
        'message' =>
            'Profile picture must be less than 5MB.'
    ], 422);
}


/*
|--------------------------------------------------------------------------
| MIME
|--------------------------------------------------------------------------
*/

$finfo =
    new finfo(FILEINFO_MIME_TYPE);

$mime =
    strtolower(
        (string)$finfo->file($tmp)
    );


$allowed = [

    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',

];


if (!isset($allowed[$mime])) {

    chatJson([
        'ok' => false,
        'message' =>
            'Only JPG, PNG and WEBP are allowed.'
    ], 422);
}


/*
|--------------------------------------------------------------------------
| FOLDER
|--------------------------------------------------------------------------
*/

$folder =
    dirname(__DIR__)
    . '/uploads/profiles';


if (!is_dir($folder)) {

    mkdir(
        $folder,
        0755,
        true
    );
}


/*
|--------------------------------------------------------------------------
| OLD IMAGE
|--------------------------------------------------------------------------
*/

$stmt =
    $conn->prepare("
        SELECT profile_picture
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

$stmt->execute([
    $currentUserId
]);

$oldPicture =
    $stmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| NEW NAME
|--------------------------------------------------------------------------
*/

$fileName =
    'profile_'
    . $currentUserId
    . '_'
    . bin2hex(
        random_bytes(12)
    )
    . '.'
    . $allowed[$mime];


$destination =
    $folder
    . '/'
    . $fileName;


if (
    !move_uploaded_file(
        $tmp,
        $destination
    )
) {

    chatJson([
        'ok' => false,
        'message' =>
            'Profile picture could not be saved.'
    ], 500);
}


/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/

try {

    $update =
        $conn->prepare("
            UPDATE users
            SET profile_picture = ?
            WHERE id = ?
        ");

    $update->execute([
        $fileName,
        $currentUserId
    ]);

} catch (Throwable $e) {

    @unlink($destination);

    chatJson([
        'ok' => false,
        'message' =>
            'Could not update profile picture.'
    ], 500);
}


/*
|--------------------------------------------------------------------------
| DELETE OLD
|--------------------------------------------------------------------------
*/

if ($oldPicture) {

    $oldPath =
        $folder
        . '/'
        . basename(
            (string)$oldPicture
        );

    if (
        is_file($oldPath)
        && $oldPath !== $destination
    ) {
        @unlink($oldPath);
    }
}


chatJson([
    'ok' => true,

    'message' =>
        'Profile picture updated.',

    'profile_url' =>
        '../chat/api/profile-picture.php?user_id='
        . $currentUserId
        . '&v='
        . time()
]);