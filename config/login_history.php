<?php

if (!function_exists('recordLoginHistory')) {

    function recordLoginHistory(
        PDO $conn,
        string $eventType,
        ?int $userId = null,
        ?int $businessId = null,
        ?int $branchId = null,
        ?string $username = null,
        ?string $failureReason = null
    ): void {

        $allowedEvents = [
            'LOGIN_SUCCESS',
            'LOGIN_FAILED',
            'LOGOUT',
            'SESSION_TIMEOUT'
        ];

        if (!in_array($eventType, $allowedEvents, true)) {
            return;
        }

        $ipAddress =
            $_SERVER['REMOTE_ADDR'] ?? null;

        $userAgent =
            $_SERVER['HTTP_USER_AGENT'] ?? null;

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
                $ipAddress,
                $userAgent,
                $failureReason
            ]);

        } catch (Throwable $e) {

            error_log(
                'Login History Error: '
                . $e->getMessage()
            );
        }
    }
}