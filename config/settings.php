<?php

function getSetting(
    PDO $conn,
    string $key,
    $default = null
) {
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
}

function updateSetting(
    PDO $conn,
    string $key,
    $value
): void {

    $stmt = $conn->prepare("
        INSERT INTO system_settings (
            setting_key,
            setting_value
        )
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value)
    ");

    $stmt->execute([
        $key,
        $value
    ]);
}