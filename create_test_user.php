<?php

require_once 'config/database.php';

$password = password_hash('123456', PASSWORD_DEFAULT);

$stmt = $conn->prepare("
    INSERT INTO users (
        business_id,
        branch_id,
        role_id,
        full_name,
        username,
        password
    )
    VALUES (?, ?, ?, ?, ?, ?)
");

$stmt->execute([
    1,
    1,
    2,
    'Ahmed Ali',
    'kulmiye',
    $password
]);

echo "User created successfully.";