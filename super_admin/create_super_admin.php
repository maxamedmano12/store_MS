<?php

require_once '../config/database.php';

$username = 'admin';
$plainPassword = '123456';

$roleStmt = $conn->prepare("
    SELECT id
    FROM roles
    WHERE role_name = 'Super Admin'
    LIMIT 1
");
$roleStmt->execute();

$role = $roleStmt->fetch();

if (!$role) {
    die('Super Admin role not found.');
}

/* Hubi haddii admin hore u jiro */
$check = $conn->prepare("
    SELECT id
    FROM users
    WHERE username = ?
    LIMIT 1
");
$check->execute([$username]);

$existing = $check->fetch();

$hashedPassword = password_hash(
    $plainPassword,
    PASSWORD_DEFAULT
);

if ($existing) {

    /* Haddii uu hore u jiro, password-ka iyo role-ka sax */
    $stmt = $conn->prepare("
        UPDATE users
        SET
            business_id = NULL,
            branch_id = NULL,
            role_id = ?,
            full_name = 'Super Admin',
            password = ?,
            status = 'Active'
        WHERE id = ?
    ");

    $stmt->execute([
        $role['id'],
        $hashedPassword,
        $existing['id']
    ]);

    echo 'Super Admin updated successfully.';

} else {

    /* Haddii uusan jirin, samee */
    $stmt = $conn->prepare("
        INSERT INTO users (
            business_id,
            branch_id,
            role_id,
            full_name,
            username,
            password,
            status
        )
        VALUES (
            NULL,
            NULL,
            ?,
            'Super Admin',
            ?,
            ?,
            'Active'
        )
    ");

    $stmt->execute([
        $role['id'],
        $username,
        $hashedPassword
    ]);

    echo 'Super Admin created successfully.';
}