<?php

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'Super Admin') {
    header('Location: ../business/dashboard.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$userId   = (int)($_SESSION['user_id'] ?? 0);
$fullName = (string)($_SESSION['full_name'] ?? 'Super Admin');
$csrfToken = (string)$_SESSION['csrf_token'];

/*
|--------------------------------------------------------------------------
| CURRENT USER PROFILE PICTURE
|--------------------------------------------------------------------------
*/
$myProfilePicture = null;

try {
    $profileStmt = $conn->prepare("
        SELECT profile_picture
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $profileStmt->execute([$userId]);

    $profileRow = $profileStmt->fetch(PDO::FETCH_ASSOC);

    if (!empty($profileRow['profile_picture'])) {
        $myProfilePicture = (string)$profileRow['profile_picture'];
    }
} catch (Throwable $e) {
    $myProfilePicture = null;
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

    <title>Global Chat | Super Admin</title>

    <link
        rel="icon"
        type="image/png"
        href="../assets/images/favicon.png"
    >

    <script src="https://cdn.tailwindcss.com"></script>

    <style>

        * {
            box-sizing: border-box;
        }

        :root {
            --theme: #2563eb;
            --chat-bg: #f8fafc;
            --panel: #ffffff;
            --text: #0f172a;
            --muted: #64748b;
            --border: #e2e8f0;
        }

        body {
            margin: 0;
            font-family:
                Inter,
                ui-sans-serif,
                system-ui,
                -apple-system,
                BlinkMacSystemFont,
                "Segoe UI",
                sans-serif;

            background: #f1f5f9;
            color: var(--text);
        }

        body.dark-chat {

            --panel: #111827;
            --text: #f8fafc;
            --muted: #94a3b8;
            --border: #334155;

            background: #020617;
        }

        button,
        input,
        select,
        textarea {
            font: inherit;
        }

        .app {
            width: 100%;
            height: 100vh;
            display: flex;
            overflow: hidden;
        }

        .contacts-panel {

            width: 390px;
            min-width: 390px;

            display: flex;
            flex-direction: column;

            background: var(--panel);

            border-right:
                1px solid var(--border);

            position: relative;

            z-index: 20;
        }

        .contacts-top {

            padding: 17px;

            border-bottom:
                1px solid var(--border);
        }

        .top-row {

            display: flex;
            align-items: center;
            justify-content: space-between;

            gap: 10px;
        }

        .title {

            font-size: 19px;
            font-weight: 900;
        }

        .subtitle {

            font-size: 11px;
            color: var(--muted);

            margin-top: 2px;
        }

        .dashboard {

            text-decoration: none;

            background: #0f172a;
            color: white;

            padding: 8px 11px;

            border-radius: 10px;

            font-size: 11px;
            font-weight: 800;
        }

        .my-card {

            margin-top: 14px;

            padding: 11px;

            display: flex;
            align-items: center;

            gap: 10px;

            border-radius: 15px;

            border:
                1px solid var(--border);

            background: #f8fafc;
        }

        .dark-chat .my-card {
            background: #1e293b;
        }

        .avatar {

            width: 43px;
            height: 43px;

            min-width: 43px;

            border-radius: 50%;

            display: flex;
            align-items: center;
            justify-content: center;

            overflow: hidden;

            object-fit: cover;

            background: #e2e8f0;

            color: #334155;

            font-weight: 900;
        }

        .avatar.large {

            width: 47px;
            height: 47px;

            min-width: 47px;
        }

        .avatar img {

            width: 100%;
            height: 100%;

            object-fit: cover;
        }

        .user-meta {

            min-width: 0;
            flex: 1;
        }

        .user-name {

            font-size: 13px;
            font-weight: 900;

            white-space: nowrap;

            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-role {

            color: var(--muted);

            font-size: 10px;

            margin-top: 2px;
        }

        .icon-btn {

            width: 38px;
            height: 38px;

            border-radius: 12px;

            border:
                1px solid var(--border);

            background: var(--panel);

            color: var(--text);

            cursor: pointer;

            display: inline-flex;

            align-items: center;
            justify-content: center;
        }

        .icon-btn:hover {
            border-color: var(--theme);
        }

        .filters {

            padding: 12px;

            display: grid;

            grid-template-columns:
                repeat(2, minmax(0, 1fr));

            gap: 8px;

            border-bottom:
                1px solid var(--border);
        }

        .filters select,
        .filters input {

            width: 100%;

            border:
                1px solid var(--border);

            background: var(--chat-bg);

            color: var(--text);

            border-radius: 11px;

            padding: 9px 10px;

            outline: none;

            font-size: 11px;
        }

        .filters .full {
            grid-column: 1 / -1;
        }

        .contacts-list {

            flex: 1;

            overflow-y: auto;

            padding: 8px;
        }

        .contact {

            width: 100%;

            display: flex;

            gap: 10px;

            border: 0;

            background: transparent;

            text-align: left;

            color: var(--text);

            padding: 10px;

            border-radius: 14px;

            cursor: pointer;
        }

        .contact:hover,
        .contact.active {

            background: #eff6ff;
        }

        .dark-chat .contact:hover,
        .dark-chat .contact.active {

            background: #1e293b;
        }

        .avatar-wrap {

            position: relative;
        }

        .online-dot {

            width: 12px;
            height: 12px;

            border-radius: 50%;

            background: #22c55e;

            border:
                2px solid var(--panel);

            position: absolute;

            right: 0;
            bottom: 0;
        }

        .contact-body {

            flex: 1;
            min-width: 0;
        }

        .contact-top {

            display: flex;
            justify-content: space-between;

            gap: 7px;
        }

        .contact-name {

            font-size: 13px;
            font-weight: 900;

            white-space: nowrap;

            overflow: hidden;
            text-overflow: ellipsis;
        }

        .contact-sub {

            margin-top: 2px;

            color: var(--muted);

            font-size: 9px;
        }

        .contact-last {

            margin-top: 4px;

            font-size: 11px;

            color: var(--muted);

            white-space: nowrap;

            overflow: hidden;
            text-overflow: ellipsis;
        }

        .unread {

            min-width: 20px;
            height: 20px;

            padding: 0 6px;

            border-radius: 999px;

            display: inline-flex;

            align-items: center;
            justify-content: center;

            background: var(--theme);

            color: white;

            font-size: 9px;
            font-weight: 900;
        }

        .chat-panel {

            flex: 1;
            min-width: 0;

            display: flex;
            flex-direction: column;

            position: relative;

            background: var(--chat-bg);
        }

        .chat-header {

            height: 76px;
            min-height: 76px;

            padding: 11px 17px;

            display: flex;
            align-items: center;
            justify-content: space-between;

            gap: 12px;

            background: var(--panel);

            border-bottom:
                1px solid var(--border);
        }

        .chat-person {

            display: flex;
            align-items: center;

            gap: 10px;

            min-width: 0;
        }

        .chat-name {

            font-size: 14px;
            font-weight: 900;
        }

        .chat-status {

            color: var(--muted);

            font-size: 10px;

            margin-top: 2px;
        }

        .header-actions {

            display: flex;
            gap: 7px;
        }

        .mobile-back {
            display: none;
        }

        .messages {

            flex: 1;

            overflow-y: auto;

            padding: 20px;

            background-color:
                var(--chat-bg);

            background-position:
                center;

            background-size:
                cover;
        }

        .empty {

            height: 100%;

            display: flex;

            align-items: center;
            justify-content: center;

            text-align: center;

            color: var(--muted);

            padding: 30px;
        }

        .message-row {

            margin: 8px 0;

            display: flex;
        }

        .message-row.mine {
            justify-content: flex-end;
        }

        .message-row.other {
            justify-content: flex-start;
        }

        .bubble {

            max-width:
                min(72%, 620px);

            padding:
                9px 11px 7px;

            border-radius: 16px;

            border:
                1px solid var(--border);

            background: var(--panel);

            overflow-wrap: anywhere;
        }

        .mine .bubble {

            background:
                var(--theme);

            color: white;

            border-color:
                transparent;

            border-bottom-right-radius:
                5px;
        }

        .other .bubble {

            border-bottom-left-radius:
                5px;
        }

        .message-text {

            white-space: pre-wrap;

            font-size: 13px;

            line-height: 1.45;
        }

        .message-meta {

            margin-top: 5px;

            display: flex;
            align-items: center;
            justify-content: flex-end;

            gap: 5px;

            font-size: 9px;

            opacity: .75;
        }

        .ticks.read {
            color: #bae6fd;
        }

        .reply-box {

            margin-bottom: 7px;

            padding: 5px 7px;

            border-left:
                3px solid currentColor;

            background:
                rgba(148,163,184,.15);

            border-radius: 7px;

            font-size: 10px;
        }

        .reply-box strong {

            display: block;

            font-size: 9px;
        }

        .reply-action {

            margin-top: 5px;

            border: 0;

            padding: 0;

            background: transparent;

            color: inherit;

            font-size: 9px;

            cursor: pointer;

            opacity: .7;
        }

        .system-message {

            width: fit-content;

            max-width: 80%;

            margin: 12px auto;

            padding: 6px 11px;

            border-radius: 999px;

            background:
                rgba(100,116,139,.13);

            color: var(--muted);

            font-size: 10px;

            text-align: center;
        }

        .media-image,
        .media-video {

            max-width: 100%;
            max-height: 340px;

            display: block;

            border-radius: 12px;

            margin-bottom: 6px;
        }

        .voice-player {

            width:
                min(300px, 100%);

            margin-bottom: 5px;
        }

        .file-card,
        .view-card {

            display: flex;

            align-items: center;

            gap: 8px;

            padding: 9px 10px;

            border:
                1px solid currentColor;

            border-radius: 11px;

            background:
                rgba(255,255,255,.08);

            margin-bottom: 5px;

            font-size: 11px;
        }

        .file-card a {

            color: inherit;

            text-decoration: none;

            font-weight: 800;
        }

        .view-card {

            cursor: pointer;

            font-weight: 800;
        }

        .reply-preview,
        .attachment-preview {

            display: none;

            background: var(--panel);

            border-top:
                1px solid var(--border);

            padding: 9px 14px;
        }

        .reply-preview.show {
            display: flex;
        }

        .attachment-preview.show {
            display: block;
        }

        .reply-preview {

            align-items: center;
            justify-content: space-between;

            gap: 10px;
        }

        .preview-row {

            display: flex;

            align-items: center;
            justify-content: space-between;

            gap: 10px;
        }

        .preview-text {

            min-width: 0;

            color: var(--muted);

            font-size: 10px;

            white-space: nowrap;

            overflow: hidden;
            text-overflow: ellipsis;
        }

        .view-once-option {

            margin-top: 7px;

            display: none;

            align-items: center;

            gap: 7px;

            font-size: 11px;
        }

        .view-once-option.show {
            display: flex;
        }

        .composer {

            padding: 10px 13px;

            background: var(--panel);

            border-top:
                1px solid var(--border);
        }

        .composer-row {

            display: flex;

            align-items: flex-end;

            gap: 7px;
        }

        .message-input {

            flex: 1;

            min-height: 42px;
            max-height: 120px;

            resize: none;

            padding: 10px 12px;

            border:
                1px solid var(--border);

            border-radius: 15px;

            background: var(--chat-bg);

            color: var(--text);

            outline: none;
        }

        .send-btn {

            width: 43px;
            height: 43px;

            min-width: 43px;

            border: 0;

            border-radius: 50%;

            background:
                var(--theme);

            color: white;

            cursor: pointer;

            font-weight: 900;
        }

        .recording {

            background:
                #dc2626 !important;

            animation:
                pulse 1s infinite;
        }

        @keyframes pulse {

            50% {
                opacity: .6;
            }
        }

        .modal-backdrop {

            display: none;

            position: fixed;

            inset: 0;

            z-index: 100;

            align-items: center;
            justify-content: center;

            padding: 18px;

            background:
                rgba(15,23,42,.55);
        }

        .modal-backdrop.show {
            display: flex;
        }

        .modal {

            width:
                min(460px,100%);

            max-height: 90vh;

            overflow-y: auto;

            padding: 20px;

            border-radius: 21px;

            background:
                var(--panel);

            color:
                var(--text);

            box-shadow:
                0 25px 70px rgba(0,0,0,.25);
        }

        .modal-title {

            margin-bottom: 15px;

            font-size: 17px;

            font-weight: 900;
        }

        .field {

            margin-bottom: 13px;
        }

        .field label {

            display: block;

            margin-bottom: 5px;

            font-size: 10px;

            font-weight: 900;
        }

        .field input,
        .field select {

            width: 100%;

            padding: 9px 10px;

            border-radius: 10px;

            border:
                1px solid var(--border);

            background:
                var(--chat-bg);

            color:
                var(--text);
        }

        .modal-actions {

            display: flex;

            justify-content: flex-end;

            gap: 7px;

            margin-top: 15px;
        }

        .btn {

            border: 0;

            border-radius: 10px;

            padding: 9px 13px;

            cursor: pointer;

            font-size: 11px;

            font-weight: 900;
        }

        .btn-primary {

            background:
                var(--theme);

            color: white;
        }

        .btn-secondary {

            background:
                #e2e8f0;

            color:
                #0f172a;
        }

        .btn-danger {

            background:
                #fee2e2;

            color:
                #b91c1c;
        }

        .toast {

            position: fixed;

            right: 18px;
            bottom: 18px;

            z-index: 200;

            max-width: 330px;

            padding: 11px 14px;

            border-radius: 11px;

            background:
                #0f172a;

            color:
                white;

            font-size: 11px;

            opacity: 0;

            transform:
                translateY(25px);

            pointer-events: none;

            transition: .2s;
        }

        .toast.show {

            opacity: 1;

            transform:
                translateY(0);
        }

        @media (max-width: 850px) {

            .contacts-panel {

                width: 100%;
                min-width: 100%;
            }

            .chat-panel {

                position: fixed;

                inset: 0;

                z-index: 40;

                transform:
                    translateX(100%);

                transition:
                    transform .25s;
            }

            .chat-panel.mobile-open {

                transform:
                    translateX(0);
            }

            .mobile-back {

                display:
                    inline-flex;
            }

            .bubble {

                max-width: 87%;
            }

            .messages {

                padding:
                    13px 9px;
            }
        }


        /* =========================================================
           CALL HISTORY
        ========================================================= */
        .call-history-list {
            display: flex;
            flex-direction: column;
            gap: 9px;
        }

        .call-history-item {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 11px 12px;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: var(--chat-bg);
        }

        .call-history-icon {
            width: 42px;
            height: 42px;
            min-width: 42px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--panel);
            font-size: 19px;
        }

        .call-history-body {
            flex: 1;
            min-width: 0;
        }

        .call-history-title {
            font-size: 12px;
            font-weight: 900;
        }

        .call-history-sub {
            margin-top: 3px;
            color: var(--muted);
            font-size: 10px;
        }

        .call-history-missed .call-history-title {
            color: #dc2626;
        }

        .call-history-empty {
            padding: 25px 10px;
            text-align: center;
            color: var(--muted);
            font-size: 11px;
        }

        /* =========================================================
           VOICE / VIDEO CALL
        ========================================================= */
        .call-overlay {
            position: fixed;
            inset: 0;
            z-index: 99999;
            background: rgba(2, 6, 23, .82);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            align-items: center;
            justify-content: center;
        }

        .incoming-call-card {
            width: min(92vw, 370px);
            background: #fff;
            border-radius: 28px;
            padding: 35px 25px 28px;
            text-align: center;
            box-shadow: 0 30px 80px rgba(0,0,0,.35);
            animation: callPopupIn .22s ease;
        }

        @keyframes callPopupIn {
            from { opacity: 0; transform: scale(.9); }
            to { opacity: 1; transform: scale(1); }
        }

        .call-avatar {
            width: 92px;
            height: 92px;
            margin: 0 auto;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg,#2563eb,#7c3aed);
            color: #fff;
            font-size: 34px;
            font-weight: 800;
            overflow: hidden;
            box-shadow: 0 10px 35px rgba(37,99,235,.28);
        }

        .call-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .incoming-call-name {
            margin-top: 18px;
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
        }

        .incoming-call-type {
            margin-top: 7px;
            color: #64748b;
            font-size: 14px;
        }

        .incoming-call-animation {
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            margin-top: 10px;
        }

        .incoming-call-animation span {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #22c55e;
            animation: callRingAnimation 1.2s infinite;
        }

        .incoming-call-animation span:nth-child(2) { animation-delay: .15s; }
        .incoming-call-animation span:nth-child(3) { animation-delay: .3s; }

        @keyframes callRingAnimation {
            0%,100% { opacity: .3; transform: scale(.8); }
            50% { opacity: 1; transform: scale(1.3); }
        }

        .incoming-call-actions {
            display: flex;
            justify-content: center;
            gap: 42px;
            margin-top: 22px;
        }

        .call-action {
            width: 65px;
            height: 65px;
            border: 0;
            border-radius: 50%;
            color: #fff;
            font-size: 26px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform .18s ease, box-shadow .18s ease;
        }

        .call-action:hover { transform: scale(1.08); }
        .accept-call { background: #22c55e; box-shadow: 0 10px 28px rgba(34,197,94,.38); }
        .decline-call { background: #ef4444; box-shadow: 0 10px 28px rgba(239,68,68,.38); }

        .active-call-overlay { background: #020617; }

        .active-call-container {
            position: relative;
            width: 100%;
            height: 100%;
            overflow: hidden;
            background: linear-gradient(150deg,#0f172a,#020617);
        }

        .remote-video {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            background: #020617;
        }

        .local-video {
            position: absolute;
            z-index: 5;
            right: 24px;
            top: 24px;
            width: 190px;
            height: 260px;
            object-fit: cover;
            border-radius: 20px;
            background: #111827;
            border: 2px solid rgba(255,255,255,.75);
            box-shadow: 0 15px 40px rgba(0,0,0,.4);
        }

        .voice-call-background {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #fff;
            background: radial-gradient(circle at top,#1e3a8a,#0f172a 52%,#020617);
        }

        .active-avatar {
            width: 125px;
            height: 125px;
            font-size: 44px;
            box-shadow: 0 0 0 12px rgba(255,255,255,.06);
        }

        .active-call-name { margin-top: 22px; font-size: 26px; font-weight: 800; }
        .active-call-status { margin-top: 7px; font-size: 14px; color: rgba(255,255,255,.7); }
        .call-duration { margin-top: 9px; font-size: 17px; font-weight: 700; letter-spacing: 1px; }

        .call-top-info {
            position: absolute;
            z-index: 7;
            top: 25px;
            left: 25px;
            padding: 10px 15px;
            border-radius: 14px;
            color: #fff;
            background: rgba(15,23,42,.52);
            backdrop-filter: blur(10px);
        }

        .call-top-name { font-weight: 800; }
        .call-top-status { margin-top: 2px; font-size: 12px; color: rgba(255,255,255,.75); }

        .call-controls {
            position: absolute;
            z-index: 10;
            left: 50%;
            bottom: 32px;
            transform: translateX(-50%);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 18px;
            padding: 13px 18px;
            border-radius: 28px;
            background: rgba(15,23,42,.68);
            backdrop-filter: blur(16px);
        }

        .call-control-button {
            min-width: 62px;
            border: 0;
            background: rgba(255,255,255,.12);
            color: #fff;
            border-radius: 18px;
            padding: 11px 12px;
            cursor: pointer;
            font-size: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            transition: .18s ease;
        }

        .call-control-button:hover { background: rgba(255,255,255,.2); }
        .call-control-button span { font-size: 10px; }
        .call-control-button.is-off { background: #475569; }
        .call-control-button.is-muted { background: #f59e0b; }
        .call-control-button.end-call-button { background: #ef4444; }
        .call-control-button.end-call-button:hover { background: #dc2626; }

        @media (max-width: 650px) {
            .local-video {
                width: 110px;
                height: 155px;
                right: 12px;
                top: 70px;
                border-radius: 14px;
            }
            .call-controls {
                bottom: 20px;
                width: calc(100% - 24px);
                gap: 8px;
                padding: 10px;
            }
            .call-control-button {
                flex: 1;
                min-width: 0;
                padding: 10px 5px;
            }
            .active-avatar { width: 100px; height: 100px; }
            .active-call-name { font-size: 22px; }
        }


        /* =========================================================
           STATUS / UPDATES
        ========================================================= */
        .chat-section-tabs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 7px;
            padding: 10px 12px 0;
        }

        .chat-section-tab {
            border: 1px solid var(--border);
            background: var(--panel);
            color: var(--muted);
            border-radius: 12px;
            padding: 9px 10px;
            font-size: 12px;
            font-weight: 900;
            cursor: pointer;
            transition: .2s ease;
        }

        .chat-section-tab.active {
            background: var(--theme);
            border-color: var(--theme);
            color: #fff;
            box-shadow: 0 8px 18px rgba(37,99,235,.18);
        }

        .updates-panel {
            display: none;
            padding: 12px;
            overflow-y: auto;
            flex: 1;
        }

        .updates-panel.show {
            display: block !important;
        }

        .my-status-card,
        .status-user-card {
            width: 100%;
            border: 1px solid var(--border);
            background: var(--panel);
            color: var(--text);
            border-radius: 16px;
            padding: 11px;
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            margin-bottom: 9px;
            text-align: left;
        }

        .status-ring {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            padding: 3px;
            background:
                conic-gradient(
                    var(--theme),
                    #8b5cf6,
                    #06b6d4,
                    var(--theme)
                );
            flex: 0 0 auto;
        }

        .status-ring.seen {
            background: #cbd5e1;
        }

        .status-ring-inner {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background: var(--panel);
            border: 2px solid var(--panel);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            font-size: 14px;
            font-weight: 900;
            color: #fff;
        }

        .status-ring-inner img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .status-card-meta {
            min-width: 0;
            flex: 1;
        }

        .status-card-name {
            font-size: 12px;
            font-weight: 900;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .status-card-sub {
            margin-top: 3px;
            color: var(--muted);
            font-size: 10px;
        }

        .status-add {
            margin-left: auto;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--theme);
            color: #fff;
            font-weight: 900;
        }

        .updates-heading {
            font-size: 10px;
            font-weight: 900;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: var(--muted);
            margin: 14px 2px 8px;
        }

        .status-empty {
            color: var(--muted);
            font-size: 11px;
            text-align: center;
            padding: 18px 8px;
        }

        .status-create-tabs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-bottom: 14px;
        }

        .status-create-tab {
            border: 1px solid var(--border);
            background: var(--chat-bg);
            color: var(--text);
            border-radius: 11px;
            padding: 9px;
            font-weight: 800;
            cursor: pointer;
        }

        .status-create-tab.active {
            background: var(--theme);
            border-color: var(--theme);
            color: #fff;
        }

        .status-textarea {
            width: 100%;
            min-height: 120px;
            resize: vertical;
            border: 1px solid var(--border);
            border-radius: 13px;
            padding: 12px;
            outline: none;
            background: var(--chat-bg);
            color: var(--text);
            font: inherit;
        }

        .status-color-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .status-viewer {
            width: min(470px, 100%);
            height: min(760px, calc(100vh - 30px));
            border-radius: 24px;
            overflow: hidden;
            position: relative;
            background: #0f172a;
            color: white;
            display: flex;
            flex-direction: column;
        }

        .status-viewer-top {
            padding: 14px 14px 10px;
            z-index: 4;
            background: linear-gradient(to bottom, rgba(0,0,0,.55), transparent);
        }

        .status-progress {
            height: 3px;
            border-radius: 99px;
            background: rgba(255,255,255,.25);
            overflow: hidden;
            margin-bottom: 11px;
        }

        .status-progress-bar {
            width: 0%;
            height: 100%;
            background: #fff;
        }

        .status-viewer-user {
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .status-viewer-avatar {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background: var(--theme);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            font-weight: 900;
        }

        .status-viewer-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .status-viewer-body {
            position: relative;
            flex: 1;
            min-height: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            overflow: hidden;
        }

        .status-viewer-body img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            background: #020617;
        }

        .status-text-content {
            width: 100%;
            height: 100%;
            min-height: 360px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 35px;
            font-size: 28px;
            line-height: 1.35;
            font-weight: 900;
            word-break: break-word;
        }

        .status-caption {
            position: absolute;
            left: 12px;
            right: 12px;
            bottom: 12px;
            background: rgba(15,23,42,.72);
            backdrop-filter: blur(8px);
            border-radius: 13px;
            padding: 10px 12px;
            font-size: 12px;
            line-height: 1.45;
        }

        .status-viewer-actions {
            position: absolute;
            top: 14px;
            right: 14px;
            z-index: 6;
            display: flex;
            gap: 7px;
        }

        .status-viewer-action {
            width: 34px;
            height: 34px;
            border: 0;
            border-radius: 50%;
            background: rgba(15,23,42,.72);
            color: white;
            cursor: pointer;
            font-weight: 900;
        }

        .status-viewer-nav {
            position: absolute;
            inset: 72px 0 0;
            z-index: 5;
            display: grid;
            grid-template-columns: 1fr 1fr;
        }

        .status-viewer-nav button {
            border: 0;
            background: transparent;
            cursor: pointer;
        }

        .status-viewers-list {
            display: grid;
            gap: 8px;
            max-height: 350px;
            overflow-y: auto;
        }

        .status-viewer-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            border-bottom: 1px solid var(--border);
            padding: 9px 0;
            font-size: 12px;
        }


    </style>

</head>

<body>


<div class="app">


    <!-- CONTACTS -->
    <aside class="contacts-panel">


        <div class="contacts-top">


            <div class="top-row">

                <div>

                    <div class="title">
                        Global Chat
                    </div>

                    <div class="subtitle">
                        Super Admin Control
                    </div>

                </div>


                <a
                    href="dashboard.php"
                    class="dashboard"
                >
                    ← Dashboard
                </a>

            </div>


            <div class="my-card">


                <div
                    class="avatar"
                    id="myAvatar"
                >
                    <?php if ($myProfilePicture): ?>
                        <img
                            src="../chat/api/profile-picture.php?user_id=<?= (int)$userId ?>&v=<?= time() ?>"
                            alt="<?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>"
                            onerror="
                                this.remove();
                                document.getElementById('myAvatar').textContent =
                                    <?= json_encode(strtoupper(substr($fullName, 0, 1))) ?>;
                            "
                        >
                    <?php else: ?>
                        <?= htmlspecialchars(
                            strtoupper(substr($fullName, 0, 1))
                        ) ?>
                    <?php endif; ?>
                </div>


                <div class="user-meta">

                    <div class="user-name">
                        <?= htmlspecialchars($fullName) ?>
                    </div>

                    <div class="user-role">
                        Super Admin
                    </div>

                </div>


                <button
                    class="icon-btn"
                    id="profileButton"
                >
                    👤
                </button>

                <button
                    class="icon-btn"
                    id="notificationButton"
                    type="button"
                    title="Turn notifications ON"
                    aria-label="Turn notifications on"
                    style="width:auto;padding:0 11px"
                >
                    🔕 OFF
                </button>


            </div>


        </div>


        <!-- FILTERS -->

        <div class="filters">


            <select id="businessFilter">

                <option value="">
                    All Businesses
                </option>

            </select>


            <select id="branchFilter">

                <option value="">
                    All Branches
                </option>

            </select>


            <input
                type="search"
                class="full"
                id="contactSearch"
                placeholder="Search user..."
            >


        </div>



        <div class="chat-section-tabs">
            <button
                type="button"
                class="chat-section-tab active"
                id="chatsTabButton"
            >
                💬 Chats
            </button>

            <button
                type="button"
                class="chat-section-tab"
                id="updatesTabButton"
            >
                🟢 Status
            </button>
        </div>

        <div
            class="contacts-list"
            id="contactsList"
        >

            <div class="empty">
                Loading users...
            </div>

        </div>



        <div class="updates-panel" id="updatesPanel">
            <button type="button" class="my-status-card" id="addStatusButton">
                <div class="status-ring">
                    <div class="status-ring-inner" id="myStatusAvatar">
                        <?php if ($myProfilePicture): ?>
                            <img
                                src="../chat/api/profile-picture.php?user_id=<?= (int)$userId ?>&v=<?= time() ?>"
                                alt="<?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>"
                                onerror="
                                    this.remove();
                                    document.getElementById('myStatusAvatar').textContent =
                                        <?= json_encode(strtoupper(substr($fullName, 0, 1))) ?>;
                                "
                            >
                        <?php else: ?>
                            <?= htmlspecialchars(strtoupper(substr($fullName, 0, 1))) ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="status-card-meta">
                    <div class="status-card-name">My Status</div>
                    <div class="status-card-sub" id="myStatusSub">
                        Tap to add a status update
                    </div>
                </div>

                <div class="status-add">+</div>
            </button>

            <div class="updates-heading">Recent updates</div>

            <div id="statusUsersList">
                <div class="status-empty">Loading updates...</div>
            </div>
        </div>


    </aside>


    <!-- CHAT -->

    <main
        class="chat-panel"
        id="chatPanel"
    >


        <header class="chat-header">


            <div class="chat-person">


                <button
                    class="icon-btn mobile-back"
                    id="mobileBack"
                >
                    ←
                </button>


                <div
                    class="avatar large"
                    id="chatAvatar"
                >
                    ?
                </div>


                <div>

                    <div
                        class="chat-name"
                        id="chatName"
                    >
                        Select user
                    </div>


                    <div
                        class="chat-status"
                        id="chatStatus"
                    >
                        Business → Branch → User
                    </div>

                </div>


            </div>


            <div
                class="header-actions"
                id="headerActions"
                style="display:none"
            >


                <button
                    class="icon-btn"
                    id="callHistoryButton"
                    type="button"
                    title="Call History"
                >
                    🕘
                </button>


                <button
                    class="icon-btn"
                    id="voiceCallButton"
                    type="button"
                    title="Voice Call"
                >
                    📞
                </button>


                <button
                    class="icon-btn"
                    id="videoCallButton"
                    type="button"
                    title="Video Call"
                >
                    📹
                </button>


                <button
                    class="icon-btn"
                    id="disappearingButton"
                >
                    ⏱
                </button>


                <button
                    class="icon-btn"
                    id="appearanceButton"
                >
                    🎨
                </button>


            </div>


        </header>


        <section
            class="messages"
            id="messages"
        >

            <div class="empty">

                <div>

                    <div
                        style="
                            font-size:45px;
                            margin-bottom:10px
                        "
                    >
                        🌐
                    </div>

                    <strong>
                        Global Chat
                    </strong>

                    <div
                        style="
                            margin-top:5px;
                            font-size:11px
                        "
                    >
                        Choose any business user.
                    </div>

                </div>

            </div>

        </section>


        <!-- REPLY -->

        <div
            class="reply-preview"
            id="replyPreview"
        >

            <div class="preview-text">

                <strong id="replyName"></strong>

                <span id="replyText"></span>

            </div>


            <button
                class="icon-btn"
                id="cancelReply"
            >
                ×
            </button>

        </div>


        <!-- ATTACHMENT -->

        <div
            class="attachment-preview"
            id="attachmentPreview"
        >

            <div class="preview-row">

                <div
                    style="min-width:0"
                >

                    <div
                        id="attachmentName"
                        style="
                            font-size:11px;
                            font-weight:900
                        "
                    ></div>

                    <div
                        id="attachmentInfo"
                        style="
                            font-size:9px;
                            color:var(--muted)
                        "
                    ></div>

                </div>


                <button
                    class="icon-btn"
                    id="cancelAttachment"
                >
                    ×
                </button>

            </div>


            <label
                class="view-once-option"
                id="viewOnceOption"
            >

                <input
                    type="checkbox"
                    id="viewOnceCheckbox"
                >

                👁 View Once

            </label>


        </div>


        <!-- COMPOSER -->

        <footer
            class="composer"
            id="composer"
            style="display:none"
        >


            <div class="composer-row">


                <input
                    type="file"
                    hidden
                    id="attachmentInput"
                    accept="image/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip"
                >


                <button
                    class="icon-btn"
                    id="attachButton"
                >
                    📎
                </button>


                <button
                    class="icon-btn"
                    id="voiceButton"
                >
                    🎤
                </button>


                <textarea
                    class="message-input"
                    id="messageInput"
                    rows="1"
                    placeholder="Type a message..."
                ></textarea>


                <button
                    class="send-btn"
                    id="sendButton"
                >
                    ➤
                </button>


            </div>


        </footer>


    </main>


</div>


<!-- =========================================================
     CREATE STATUS MODAL
========================================================= -->
<div class="modal-backdrop" id="statusCreateModal">
    <div class="modal">
        <div class="modal-title">🟢 Add Status</div>

        <div class="status-create-tabs">
            <button type="button" class="status-create-tab active" id="statusTextTab">
                ✍️ Text / Emoji
            </button>
            <button type="button" class="status-create-tab" id="statusImageTab">
                🖼️ Image
            </button>
        </div>

        <div id="statusTextCreate">
            <div class="field">
                <label>Text or Emoji</label>
                <textarea
                    class="status-textarea"
                    id="statusTextInput"
                    maxlength="1000"
                    placeholder="Write your status..."
                ></textarea>
            </div>

            <div class="status-color-row">
                <div class="field">
                    <label>Background</label>
                    <input type="color" id="statusBackgroundColor" value="#1d4ed8">
                </div>

                <div class="field">
                    <label>Text Color</label>
                    <input type="color" id="statusTextColor" value="#ffffff">
                </div>
            </div>
        </div>

        <div id="statusImageCreate" style="display:none">
            <div class="field">
                <label>Choose Image</label>
                <input
                    type="file"
                    id="statusImageInput"
                    accept="image/jpeg,image/png,image/webp,image/gif"
                >
            </div>

            <div class="field">
                <label>Caption (optional)</label>
                <textarea
                    class="status-textarea"
                    id="statusCaptionInput"
                    maxlength="1000"
                    placeholder="Add a caption..."
                    style="min-height:90px"
                ></textarea>
            </div>
        </div>

        <div class="modal-actions">
            <button
                type="button"
                class="btn btn-secondary"
                data-close="statusCreateModal"
            >
                Cancel
            </button>

            <button
                type="button"
                class="btn btn-primary"
                id="publishStatusButton"
            >
                Publish
            </button>
        </div>
    </div>
</div>


<!-- =========================================================
     STATUS VIEWER
========================================================= -->
<div class="modal-backdrop" id="statusViewerModal">
    <div class="status-viewer">
        <div class="status-viewer-top">
            <div class="status-progress">
                <div class="status-progress-bar" id="statusProgressBar"></div>
            </div>

            <div class="status-viewer-user">
                <div class="status-viewer-avatar" id="statusViewerAvatar">U</div>

                <div>
                    <div style="font-size:12px;font-weight:900" id="statusViewerName">
                        Status
                    </div>
                    <div style="font-size:10px;opacity:.75" id="statusViewerTime">
                        Just now
                    </div>
                </div>
            </div>
        </div>

        <div class="status-viewer-actions">
            <button
                type="button"
                class="status-viewer-action"
                id="statusViewersButton"
                title="Seen by"
                style="display:none"
            >
                👁
            </button>

            <button
                type="button"
                class="status-viewer-action"
                id="deleteStatusButton"
                title="Delete"
                style="display:none"
            >
                🗑
            </button>

            <button
                type="button"
                class="status-viewer-action"
                id="closeStatusViewer"
                title="Close"
            >
                ✕
            </button>
        </div>

        <div class="status-viewer-body" id="statusViewerBody"></div>

        <div class="status-viewer-nav">
            <button type="button" id="previousStatusButton" aria-label="Previous status"></button>
            <button type="button" id="nextStatusButton" aria-label="Next status"></button>
        </div>
    </div>
</div>


<!-- =========================================================
     STATUS VIEWERS MODAL
========================================================= -->
<div class="modal-backdrop" id="statusViewersModal">
    <div class="modal">
        <div class="modal-title">👁 Seen by</div>

        <div class="status-viewers-list" id="statusViewersList">
            <div class="status-empty">No views yet.</div>
        </div>

        <div class="modal-actions">
            <button
                type="button"
                class="btn btn-secondary"
                data-close="statusViewersModal"
            >
                Close
            </button>
        </div>
    </div>
</div>


<!-- =========================================================
     CALL HISTORY MODAL
========================================================= -->
<div class="modal-backdrop" id="callHistoryModal">
    <div class="modal">
        <div class="modal-title">🕘 Call History</div>

        <div id="callHistoryList" class="call-history-list">
            <div class="call-history-empty">No call history.</div>
        </div>

        <div class="modal-actions">
            <button
                class="btn btn-secondary"
                data-close="callHistoryModal"
                type="button"
            >
                Close
            </button>
        </div>
    </div>
</div>


<!-- =========================================================
     INCOMING CALL MODAL
========================================================= -->
<div id="incomingCallModal" class="call-overlay" style="display:none">
    <div class="incoming-call-card">
        <div class="call-avatar" id="incomingCallAvatar">?</div>
        <div class="incoming-call-name" id="incomingCallName">Incoming Call</div>
        <div class="incoming-call-type" id="incomingCallType">Voice call...</div>
        <div class="incoming-call-animation"><span></span><span></span><span></span></div>
        <div class="incoming-call-actions">
            <button type="button" class="call-action decline-call" id="declineCallButton" title="Decline">📵</button>
            <button type="button" class="call-action accept-call" id="acceptCallButton" title="Accept">📞</button>
        </div>
    </div>
</div>

<!-- =========================================================
     ACTIVE CALL SCREEN
========================================================= -->
<div id="activeCallModal" class="call-overlay active-call-overlay" style="display:none">
    <div class="active-call-container">
        <video id="remoteVideo" class="remote-video" autoplay playsinline></video>

        <div id="voiceCallBackground" class="voice-call-background">
            <div class="call-avatar active-avatar" id="activeCallAvatar">?</div>
            <div class="active-call-name" id="activeCallName">User</div>
            <div class="active-call-status" id="activeCallStatus">Calling...</div>
            <div class="call-duration" id="callDuration">00:00</div>
        </div>

        <video id="localVideo" class="local-video" autoplay muted playsinline style="display:none"></video>

        <div class="call-top-info">
            <div class="call-top-name" id="callTopName">User</div>
            <div class="call-top-status" id="callTopStatus">Connecting...</div>
        </div>

        <div class="call-controls">
            <button type="button" class="call-control-button" id="muteCallButton" title="Mute microphone">🎤<span>Mute</span></button>
            <button type="button" class="call-control-button" id="cameraCallButton" title="Camera">📹<span>Camera</span></button>
            <button type="button" class="call-control-button" id="switchCameraButton" title="Switch camera" style="display:none">🔄<span>Switch</span></button>
            <button type="button" class="call-control-button" id="speakerCallButton" title="Speaker">🔊<span>Speaker</span></button>
            <button type="button" class="call-control-button end-call-button" id="endCallButton" title="End call">📞<span>End</span></button>
        </div>
    </div>
</div>


<!-- APPEARANCE -->

<div
    class="modal-backdrop"
    id="appearanceModal"
>

    <div class="modal">


        <div class="modal-title">
            🎨 Chat Appearance
        </div>


        <div class="field">

            <label>
                Theme Color
            </label>

            <input
                type="color"
                id="themeColor"
                value="#2563eb"
            >

        </div>


        <div class="field">

            <label>
                Background Color
            </label>

            <input
                type="color"
                id="backgroundColor"
                value="#f8fafc"
            >

        </div>


        <div class="field">

            <label>
                Background Image
            </label>

            <input
                type="file"
                id="backgroundImage"
                accept="image/jpeg,image/png,image/webp"
            >

        </div>


        <div class="field">

            <label
                style="
                    display:flex;
                    gap:7px;
                    align-items:center
                "
            >

                <input
                    type="checkbox"
                    id="darkMode"
                    style="width:auto"
                >

                Dark Mode

            </label>

        </div>


        <div class="modal-actions">


            <button
                class="btn btn-danger"
                id="removeBackground"
            >
                Remove Background
            </button>


            <button
                class="btn btn-secondary"
                data-close="appearanceModal"
            >
                Cancel
            </button>


            <button
                class="btn btn-primary"
                id="saveAppearance"
            >
                Save
            </button>


        </div>


    </div>

</div>


<!-- DISAPPEARING -->

<div
    class="modal-backdrop"
    id="disappearingModal"
>

    <div class="modal">


        <div class="modal-title">
            ⏱ Disappearing Messages
        </div>


        <div class="field">

            <label>
                Duration
            </label>

            <select
                id="disappearingSelect"
            >

                <option value="0">
                    Off
                </option>

                <option value="3600">
                    1 Hour
                </option>

                <option value="86400">
                    24 Hours
                </option>

                <option value="604800">
                    7 Days
                </option>

                <option value="2592000">
                    30 Days
                </option>

                <option value="custom">
                    Custom
                </option>

            </select>

        </div>


        <div
            class="field"
            id="customTimeWrap"
            style="display:none"
        >

            <label>
                Custom Minutes
            </label>

            <input
                type="number"
                id="customMinutes"
                min="1"
                max="525600"
            >

        </div>


        <div class="modal-actions">


            <button
                class="btn btn-secondary"
                data-close="disappearingModal"
            >
                Cancel
            </button>


            <button
                class="btn btn-primary"
                id="saveDisappearing"
            >
                Save
            </button>


        </div>


    </div>

</div>


<!-- PROFILE -->

<div
    class="modal-backdrop"
    id="profileModal"
>

    <div class="modal">


        <div class="modal-title">
            👤 Profile Picture
        </div>


        <div
            style="
                display:flex;
                justify-content:center;
                margin-bottom:17px
            "
        >

            <div
                class="avatar"
                id="profilePreview"
                style="
                    width:100px;
                    height:100px;
                    font-size:28px
                "
            >

                <?= htmlspecialchars(
                    strtoupper(substr($fullName, 0, 1))
                ) ?>

            </div>

        </div>


        <div class="field">

            <label>
                Choose Picture
            </label>

            <input
                type="file"
                id="profilePictureInput"
                accept="image/jpeg,image/png,image/webp"
            >

        </div>


        <div class="modal-actions">


            <button
                class="btn btn-danger"
                id="removeProfilePicture"
            >
                Remove
            </button>


            <button
                class="btn btn-secondary"
                data-close="profileModal"
            >
                Cancel
            </button>


            <button
                class="btn btn-primary"
                id="saveProfilePicture"
            >
                Upload
            </button>


        </div>


    </div>

</div>


<div
    class="toast"
    id="toast"
></div>


<script>

const csrfToken =
    <?= json_encode($csrfToken) ?>;

const currentUserId =
    <?= (int)$userId ?>;


const API = {

    contacts:
        '../chat/api/contacts.php',

    open:
        '../chat/api/open-conversation.php',

    messages:
        '../chat/api/messages.php',

    send:
        '../chat/api/send.php',

    upload:
        '../chat/api/upload.php',

    presence:
        '../chat/api/presence.php',

    status:
        '../chat/api/status.php',

    updates:
        '../chat/api/statuses.php',

    receipts:
        '../chat/api/receipts.php',

    preferences:
        '../chat/api/preferences.php',

    profile:
        '../chat/api/profile-picture.php',

    disappearing:
        '../chat/api/disappearing.php',

    calls:
        '../chat/api/calls.php',

    notifications:
        '../chat/api/notifications.php'
};


let contacts = [];

let currentConversationId = 0;
let currentTarget = null;

let lastMessageId = 0;

let replyTo = null;
let selectedAttachment = null;

let messageTimer = null;
let statusTimer = null;
let receiptTimer = null;

let typingTimer = null;
let lastTypingSent = 0;

let mediaRecorder = null;
let voiceChunks = [];
let isRecording = false;

/* Voice / Video Call state */
let activeCallId = 0;
let activeCallData = null;
let incomingCallData = null;
let peerConnection = null;
let localStream = null;
let lastCallSignalId = 0;
let callSignalTimer = null;
let callStatusTimer = null;
let incomingCallTimer = null;
let callDurationTimer = null;
let callConnectedAt = null;
let pendingIceCandidates = [];
let callBusy = false;
let currentCameraFacingMode = 'user';
let cameraSwitchBusy = false;
let ringtoneAudioContext = null;
let ringtoneTimer = null;
let ringtonePlaying = false;




const contactsList =
    document.getElementById(
        'contactsList'
    );

const businessFilter =
    document.getElementById(
        'businessFilter'
    );

const branchFilter =
    document.getElementById(
        'branchFilter'
    );

const contactSearch =
    document.getElementById(
        'contactSearch'
    );


/* Status / Updates elements */
const chatsTabButton = document.getElementById('chatsTabButton');
const updatesTabButton = document.getElementById('updatesTabButton');
const updatesPanel = document.getElementById('updatesPanel');
const statusUsersList = document.getElementById('statusUsersList');
const addStatusButton = document.getElementById('addStatusButton');
const myStatusSub = document.getElementById('myStatusSub');
const statusCreateModal = document.getElementById('statusCreateModal');
const statusTextTab = document.getElementById('statusTextTab');
const statusImageTab = document.getElementById('statusImageTab');
const statusTextCreate = document.getElementById('statusTextCreate');
const statusImageCreate = document.getElementById('statusImageCreate');
const statusTextInput = document.getElementById('statusTextInput');
const statusBackgroundColor = document.getElementById('statusBackgroundColor');
const statusTextColor = document.getElementById('statusTextColor');
const statusImageInput = document.getElementById('statusImageInput');
const statusCaptionInput = document.getElementById('statusCaptionInput');
const publishStatusButton = document.getElementById('publishStatusButton');
const statusViewerModal = document.getElementById('statusViewerModal');
const statusViewerAvatar = document.getElementById('statusViewerAvatar');
const statusViewerName = document.getElementById('statusViewerName');
const statusViewerTime = document.getElementById('statusViewerTime');
const statusViewerBody = document.getElementById('statusViewerBody');
const statusProgressBar = document.getElementById('statusProgressBar');
const statusViewersButton = document.getElementById('statusViewersButton');
const deleteStatusButton = document.getElementById('deleteStatusButton');
const closeStatusViewer = document.getElementById('closeStatusViewer');
const previousStatusButton = document.getElementById('previousStatusButton');
const nextStatusButton = document.getElementById('nextStatusButton');
const statusViewersModal = document.getElementById('statusViewersModal');
const statusViewersList = document.getElementById('statusViewersList');

let statusUpdates = [];
let currentStatusGroup = [];
let currentStatusIndex = 0;
let statusProgressTimer = null;
let statusCreateMode = 'text';

const chatPanel =
    document.getElementById(
        'chatPanel'
    );

const messagesBox =
    document.getElementById(
        'messages'
    );

const composer =
    document.getElementById(
        'composer'
    );

const headerActions =
    document.getElementById(
        'headerActions'
    );

const chatName =
    document.getElementById(
        'chatName'
    );

const chatStatus =
    document.getElementById(
        'chatStatus'
    );

const chatAvatar =
    document.getElementById(
        'chatAvatar'
    );

const messageInput =
    document.getElementById(
        'messageInput'
    );

const sendButton =
    document.getElementById(
        'sendButton'
    );

const attachmentInput =
    document.getElementById(
        'attachmentInput'
    );

const attachmentPreview =
    document.getElementById(
        'attachmentPreview'
    );

const attachmentName =
    document.getElementById(
        'attachmentName'
    );

const attachmentInfo =
    document.getElementById(
        'attachmentInfo'
    );

const viewOnceOption =
    document.getElementById(
        'viewOnceOption'
    );

const viewOnceCheckbox =
    document.getElementById(
        'viewOnceCheckbox'
    );


/* Call History elements */
const callHistoryButton = document.getElementById('callHistoryButton');
const callHistoryModal = document.getElementById('callHistoryModal');
const callHistoryList = document.getElementById('callHistoryList');

/* Voice / Video Call elements */
const voiceCallButton = document.getElementById('voiceCallButton');
const videoCallButton = document.getElementById('videoCallButton');
const incomingCallModal = document.getElementById('incomingCallModal');
const incomingCallAvatar = document.getElementById('incomingCallAvatar');
const incomingCallName = document.getElementById('incomingCallName');
const incomingCallType = document.getElementById('incomingCallType');
const acceptCallButton = document.getElementById('acceptCallButton');
const declineCallButton = document.getElementById('declineCallButton');
const activeCallModal = document.getElementById('activeCallModal');
const remoteVideo = document.getElementById('remoteVideo');
const localVideo = document.getElementById('localVideo');
const voiceCallBackground = document.getElementById('voiceCallBackground');
const activeCallAvatar = document.getElementById('activeCallAvatar');
const activeCallName = document.getElementById('activeCallName');
const activeCallStatus = document.getElementById('activeCallStatus');
const callDuration = document.getElementById('callDuration');
const callTopName = document.getElementById('callTopName');
const callTopStatus = document.getElementById('callTopStatus');
const muteCallButton = document.getElementById('muteCallButton');
const cameraCallButton = document.getElementById('cameraCallButton');
const switchCameraButton = document.getElementById('switchCameraButton');
const speakerCallButton = document.getElementById('speakerCallButton');
const endCallButton = document.getElementById('endCallButton');


function escapeHtml(value) {

    const div =
        document.createElement('div');

    div.textContent =
        value ?? '';

    return div.innerHTML;
}


function initials(name) {

    const parts =
        String(name || '?')
            .trim()
            .split(/\s+/)
            .filter(Boolean);

    if (!parts.length) {
        return '?';
    }

    return (
        (parts[0][0] || '') +
        (parts[1]?.[0] || '')
    ).toUpperCase();
}


function avatarHtml(
    name,
    profile
) {

    if (profile) {

        return `
            <img
                src="${escapeHtml(profile)}"
                alt=""
            >
        `;
    }

    return escapeHtml(
        initials(name)
    );
}


function showToast(message) {

    const toast =
        document.getElementById(
            'toast'
        );

    toast.textContent =
        message;

    toast.classList.add(
        'show'
    );

    clearTimeout(
        window.toastTimer
    );

    window.toastTimer =
        setTimeout(
            () =>
                toast.classList.remove(
                    'show'
                ),
            2500
        );
}


function openModal(id) {

    document
        .getElementById(id)
        ?.classList.add(
            'show'
        );
}


function closeModal(id) {

    document
        .getElementById(id)
        ?.classList.remove(
            'show'
        );
}


document
    .querySelectorAll(
        '[data-close]'
    )
    .forEach(button => {

        button.addEventListener(
            'click',
            () =>
                closeModal(
                    button.dataset.close
                )
        );
    });


async function jsonFetch(
    url,
    options = {}
) {

    const response =
        await fetch(
            url,
            {
                cache: 'no-store',
                ...options
            }
        );

    let data;

    try {

        data =
            await response.json();

    } catch {

        throw new Error(
            'Invalid server response.'
        );
    }


    if (
        !response.ok
        || !data.ok
    ) {

        throw new Error(
            data.message ||
            'Request failed.'
        );
    }


    return data;
}


function formatTime(value) {

    if (!value) {
        return '';
    }

    const date =
        new Date(
            String(value)
                .replace(' ', 'T')
        );

    if (
        Number.isNaN(
            date.getTime()
        )
    ) {
        return '';
    }

    return date
        .toLocaleTimeString(
            [],
            {
                hour: '2-digit',
                minute: '2-digit'
            }
        );
}


function formatBytes(bytes) {

    bytes =
        Number(bytes || 0);

    if (bytes < 1024) {

        return bytes + ' B';
    }

    if (bytes < 1048576) {

        return (
            bytes / 1024
        ).toFixed(1) + ' KB';
    }

    return (
        bytes / 1048576
    ).toFixed(1) + ' MB';
}


/*
|--------------------------------------------------------------------------
| CONTACTS
|--------------------------------------------------------------------------
*/

async function loadContacts() {

    try {

        const data =
            await jsonFetch(
                API.contacts
            );

        contacts =
            Array.isArray(
                data.contacts
            )
                ? data.contacts
                : [];

        buildBusinessOptions();

        buildBranchOptions();

        renderContacts();

    } catch (error) {

        contactsList.innerHTML = `
            <div class="empty">
                ${escapeHtml(
                    error.message
                )}
            </div>
        `;
    }
}


function buildBusinessOptions() {

    const current =
        businessFilter.value;

    const businesses =
        new Map();


    contacts.forEach(contact => {

        if (
            contact.business_id
            && contact.business_name
        ) {

            businesses.set(
                Number(
                    contact.business_id
                ),
                contact.business_name
            );
        }
    });


    businessFilter.innerHTML = `
        <option value="">
            All Businesses
        </option>
    `;


    [...businesses.entries()]
        .sort(
            (a, b) =>
                String(a[1])
                    .localeCompare(
                        String(b[1])
                    )
        )
        .forEach(
            ([id, name]) => {

                businessFilter
                    .insertAdjacentHTML(
                        'beforeend',
                        `
                            <option value="${id}">
                                ${escapeHtml(name)}
                            </option>
                        `
                    );
            }
        );


    if (
        [...businesses.keys()]
            .includes(
                Number(current)
            )
    ) {

        businessFilter.value =
            current;
    }
}


function buildBranchOptions() {

    const selectedBusiness =
        Number(
            businessFilter.value ||
            0
        );

    const current =
        branchFilter.value;

    const branches =
        new Map();


    contacts.forEach(contact => {

        if (
            selectedBusiness
            && Number(
                contact.business_id
            ) !== selectedBusiness
        ) {
            return;
        }


        if (
            contact.branch_id
            && contact.branch_name
        ) {

            branches.set(
                Number(
                    contact.branch_id
                ),
                contact.branch_name
            );
        }
    });


    branchFilter.innerHTML = `
        <option value="">
            All Branches
        </option>
    `;


    [...branches.entries()]
        .sort(
            (a, b) =>
                String(a[1])
                    .localeCompare(
                        String(b[1])
                    )
        )
        .forEach(
            ([id, name]) => {

                branchFilter
                    .insertAdjacentHTML(
                        'beforeend',
                        `
                            <option value="${id}">
                                ${escapeHtml(name)}
                            </option>
                        `
                    );
            }
        );


    if (
        [...branches.keys()]
            .includes(
                Number(current)
            )
    ) {

        branchFilter.value =
            current;
    }
}


function renderContacts() {

    const query =
        contactSearch.value
            .trim()
            .toLowerCase();

    const selectedBusiness =
        Number(
            businessFilter.value ||
            0
        );

    const selectedBranch =
        Number(
            branchFilter.value ||
            0
        );


    const filtered =
        contacts.filter(contact => {

            if (
                selectedBusiness
                && Number(
                    contact.business_id
                ) !== selectedBusiness
            ) {
                return false;
            }


            if (
                selectedBranch
                && Number(
                    contact.branch_id
                ) !== selectedBranch
            ) {
                return false;
            }


            const haystack = [

                contact.full_name,
                contact.username,
                contact.role,
                contact.business_name,
                contact.branch_name

            ]
            .join(' ')
            .toLowerCase();


            return haystack.includes(
                query
            );
        });


    if (!filtered.length) {

        contactsList.innerHTML = `
            <div class="empty">
                No users found.
            </div>
        `;

        return;
    }


    contactsList.innerHTML =
        filtered.map(contact => {

            const active =
                Number(contact.id) ===
                Number(
                    currentTarget?.id
                );

            return `
                <button
                    class="
                        contact
                        ${active ? 'active' : ''}
                    "
                    data-user-id="${Number(contact.id)}"
                >

                    <div class="avatar-wrap">

                        <div class="avatar">

                            ${avatarHtml(
                                contact.full_name,
                                contact.profile_url
                            )}

                        </div>

                        ${
                            Number(
                                contact.is_online
                            ) === 1

                            ? `
                                <span class="online-dot"></span>
                            `

                            : ''
                        }

                    </div>


                    <div class="contact-body">


                        <div class="contact-top">


                            <div class="contact-name">

                                ${escapeHtml(
                                    contact.full_name
                                )}

                            </div>


                            ${
                                Number(
                                    contact.unread_count
                                ) > 0

                                ? `
                                    <span class="unread">
                                        ${Number(
                                            contact.unread_count
                                        )}
                                    </span>
                                `

                                : ''
                            }


                        </div>


                        <div class="contact-sub">

                            ${escapeHtml(
                                contact.business_name ||
                                'System'
                            )}

                            ${
                                contact.branch_name
                                ? ' → ' +
                                  escapeHtml(
                                      contact.branch_name
                                  )
                                : ''
                            }

                            • ${escapeHtml(
                                contact.role || ''
                            )}

                        </div>


                        <div class="contact-last">

                            ${escapeHtml(
                                contact.last_message ||
                                contact.last_seen_text ||
                                ''
                            )}

                        </div>


                    </div>


                </button>
            `;
        })
        .join('');


    contactsList
        .querySelectorAll(
            '.contact'
        )
        .forEach(button => {

            button.addEventListener(
                'click',
                () =>
                    openConversation(
                        Number(
                            button.dataset
                                .userId
                        )
                    )
            );
        });
}


businessFilter.addEventListener(
    'change',
    () => {

        branchFilter.value = '';

        buildBranchOptions();

        renderContacts();
    }
);


branchFilter.addEventListener(
    'change',
    renderContacts
);


contactSearch.addEventListener(
    'input',
    renderContacts
);


/*
|--------------------------------------------------------------------------
| OPEN CONVERSATION
|--------------------------------------------------------------------------
*/

async function openConversation(
    targetUserId
) {

    try {

        const form =
            new FormData();

        form.append(
            'csrf_token',
            csrfToken
        );

        form.append(
            'target_user_id',
            targetUserId
        );


        const data =
            await jsonFetch(
                API.open,
                {
                    method: 'POST',
                    body: form
                }
            );


        currentConversationId =
            Number(
                data.conversation_id
            );

        currentTarget =
            data.user;


        lastMessageId = 0;

        clearReply();
        clearAttachment();


        chatName.textContent =
            currentTarget.full_name;


        let extra = [];

        if (
            currentTarget.business_name
        ) {
            extra.push(
                currentTarget.business_name
            );
        }

        if (
            currentTarget.branch_name
        ) {
            extra.push(
                currentTarget.branch_name
            );
        }


        chatStatus.textContent =
            currentTarget.status_text ||
            extra.join(' • ') ||
            'Offline';


        chatAvatar.innerHTML =
            avatarHtml(
                currentTarget.full_name,
                currentTarget.profile_url
            );


        composer.style.display =
            'block';

        headerActions.style.display =
            'flex';


        chatPanel.classList.add(
            'mobile-open'
        );


        renderContacts();


        messagesBox.innerHTML = `
            <div class="empty">
                Loading messages...
            </div>
        `;


        await loadMessages(true);

        await sendPresence(false);

        await loadStatus();


        startPolling();


    } catch (error) {

        showToast(
            error.message
        );
    }
}


/*
|--------------------------------------------------------------------------
| MESSAGES
|--------------------------------------------------------------------------
*/

async function loadMessages(
    initial = false
) {

    if (!currentConversationId) {
        return;
    }


    const conversationAtStart =
        currentConversationId;


    let url =
        API.messages +
        '?conversation_id=' +
        encodeURIComponent(
            conversationAtStart
        );


    if (
        !initial
        && lastMessageId > 0
    ) {

        url +=
            '&after_id=' +
            encodeURIComponent(
                lastMessageId
            );
    }


    try {

        const data =
            await jsonFetch(url);


        if (
            conversationAtStart !==
            currentConversationId
        ) {
            return;
        }


        const incoming =
            Array.isArray(
                data.messages
            )
                ? data.messages
                : [];


        if (initial) {

            messagesBox.innerHTML = '';

            if (!incoming.length) {

                messagesBox.innerHTML = `
                    <div
                        class="empty"
                        id="noMessages"
                    >
                        Start conversation.
                    </div>
                `;
            }

        } else if (
            incoming.length
        ) {

            document
                .getElementById(
                    'noMessages'
                )
                ?.remove();
        }


        incoming.forEach(
            appendMessage
        );


        if (incoming.length) {

            lastMessageId =
                Math.max(
                    lastMessageId,
                    ...incoming.map(
                        message =>
                            Number(
                                message.id
                            )
                    )
                );

            scrollBottom();
        }


        applyReceipts(
            data.receipts || []
        );


    } catch (error) {

        console.error(
            error
        );
    }
}


function appendMessage(message) {

    if (
        document.getElementById(
            'message-' +
            message.id
        )
    ) {
        return;
    }


    if (
        message.message_type ===
        'system'
    ) {

        const div =
            document.createElement(
                'div'
            );

        div.id =
            'message-' +
            message.id;

        div.className =
            'system-message';

        div.textContent =
            message.message || '';

        messagesBox.appendChild(
            div
        );

        return;
    }


    const mine =
        Number(
            message.is_mine
        ) === 1;


    const row =
        document.createElement(
            'div'
        );

    row.id =
        'message-' +
        message.id;

    row.className =
        'message-row ' +
        (
            mine
                ? 'mine'
                : 'other'
        );


    let replyHtml = '';

    if (message.reply) {

        replyHtml = `
            <div class="reply-box">

                <strong>
                    ${escapeHtml(
                        message.reply
                            .sender_name
                    )}
                </strong>

                ${escapeHtml(
                    message.reply
                        .message || ''
                )}

            </div>
        `;
    }


    const mediaHtml =
        buildMedia(
            message,
            mine
        );


    const textHtml =
        message.message

        ? `
            <div class="message-text">
                ${escapeHtml(
                    message.message
                )}
            </div>
        `

        : '';


    row.innerHTML = `
        <div class="bubble">

            ${replyHtml}

            ${mediaHtml}

            ${textHtml}

            <button
                class="reply-action"
                data-id="${Number(message.id)}"
                data-name="${escapeHtml(
                    message.sender_name
                )}"
                data-text="${escapeHtml(
                    message.message ||
                    mediaLabel(message)
                )}"
            >
                ↩ Reply
            </button>

            <div class="message-meta">

                ${escapeHtml(
                    formatTime(
                        message.created_at
                    )
                )}

                ${
                    mine
                        ? tickHtml(
                            message.status,
                            message.id
                        )
                        : ''
                }

            </div>

        </div>
    `;


    messagesBox.appendChild(
        row
    );


    row
        .querySelector(
            '.reply-action'
        )
        ?.addEventListener(
            'click',
            event => {

                setReply({
                    id:
                        Number(
                            event.currentTarget
                                .dataset.id
                        ),

                    sender_name:
                        event.currentTarget
                            .dataset.name,

                    message:
                        event.currentTarget
                            .dataset.text
                });
            }
        );


    row
        .querySelectorAll(
            '[data-view-once]'
        )
        .forEach(button => {

            button.addEventListener(
                'click',
                () =>
                    openViewOnce(
                        message,
                        button
                    )
            );
        });
}


function mediaLabel(message) {

    switch (
        message.message_type
    ) {

        case 'image':
            return 'Photo';

        case 'video':
            return 'Video';

        case 'voice':
            return 'Voice message';

        case 'file':
            return (
                message.attachment_name ||
                'File'
            );

        default:
            return 'Message';
    }
}


function buildMedia(
    message,
    mine
) {

    const type =
        message.message_type;


    if (
        ![
            'image',
            'video',
            'voice',
            'file'
        ].includes(type)
    ) {
        return '';
    }


    const viewOnce =
        Number(
            message.is_view_once
        ) === 1;


    if (viewOnce) {

        if (mine) {

            return `
                <div class="view-card">

                    ${
                        message.view_once_state ===
                        'opened'

                        ? '👁 Opened'

                        : (
                            type === 'voice'
                                ? '🎤 Listen Once sent'
                                : '👁 View Once sent'
                        )
                    }

                </div>
            `;
        }


        if (
            message.view_once_state ===
            'opened'
            || !message.media_url
        ) {

            return `
                <div class="view-card">
                    👁 Opened
                </div>
            `;
        }


        return `
            <button
                type="button"
                class="view-card"
                data-view-once="${Number(message.id)}"
            >

                ${
                    type === 'voice'
                        ? '🎤 Listen Once'
                        : '👁 Open View Once'
                }

            </button>
        `;
    }


    if (!message.media_url) {
        return '';
    }


    const url =
        escapeHtml(
            message.media_url
        );


    if (type === 'image') {

        return `
            <img
                class="media-image"
                src="${url}"
                alt=""
                loading="lazy"
            >
        `;
    }


    if (type === 'video') {

        return `
            <video
                class="media-video"
                src="${url}"
                controls
            ></video>
        `;
    }


    if (type === 'voice') {

        return `
            <audio
                class="voice-player"
                src="${url}"
                controls
            ></audio>
        `;
    }


    return `
        <div class="file-card">

            📎

            <a
                href="${url}"
                target="_blank"
                rel="noopener"
            >
                ${escapeHtml(
                    message.attachment_name ||
                    'Open file'
                )}
            </a>

        </div>
    `;
}


async function openViewOnce(
    message,
    button
) {

    if (!message.media_url) {
        return;
    }


    button.disabled = true;

    button.textContent =
        'Opening...';


    try {

        const response =
            await fetch(
                message.media_url,
                {
                    cache:
                        'no-store'
                }
            );


        if (!response.ok) {

            throw new Error(
                await response.text()
                ||
                'Unable to open.'
            );
        }


        const blob =
            await response.blob();

        const blobUrl =
            URL.createObjectURL(
                blob
            );


        const wrapper =
            document.createElement(
                'div'
            );


        if (
            message.message_type ===
            'image'
        ) {

            wrapper.innerHTML = `
                <img
                    class="media-image"
                    src="${blobUrl}"
                    alt=""
                >
            `;

        } else if (
            message.message_type ===
            'video'
        ) {

            wrapper.innerHTML = `
                <video
                    class="media-video"
                    src="${blobUrl}"
                    controls
                    autoplay
                ></video>
            `;

        } else {

            wrapper.innerHTML = `
                <audio
                    class="voice-player"
                    src="${blobUrl}"
                    controls
                    autoplay
                ></audio>
            `;
        }


        button.replaceWith(
            wrapper
        );


        setTimeout(
            () =>
                URL.revokeObjectURL(
                    blobUrl
                ),
            600000
        );


    } catch (error) {

        button.textContent =
            '👁 Opened';

        showToast(
            error.message
        );
    }
}


function tickHtml(
    status,
    id
) {

    let text = '✓';

    let css =
        'ticks';


    if (
        status === 'delivered'
        || status === 'read'
    ) {
        text = '✓✓';
    }


    if (
        status === 'read'
    ) {
        css += ' read';
    }


    return `
        <span
            class="${css}"
            id="ticks-${Number(id)}"
        >
            ${text}
        </span>
    `;
}


function applyReceipts(
    receipts
) {

    receipts.forEach(
        receipt => {

            const tick =
                document.getElementById(
                    'ticks-' +
                    Number(receipt.id)
                );

            if (!tick) {
                return;
            }


            if (
                receipt.status ===
                'read'
            ) {

                tick.textContent =
                    '✓✓';

                tick.classList.add(
                    'read'
                );

            } else if (
                receipt.status ===
                'delivered'
            ) {

                tick.textContent =
                    '✓✓';

                tick.classList.remove(
                    'read'
                );

            } else {

                tick.textContent =
                    '✓';

                tick.classList.remove(
                    'read'
                );
            }
        }
    );
}


async function loadReceipts() {

    if (
        !currentConversationId
    ) {
        return;
    }


    try {

        const data =
            await jsonFetch(
                API.receipts +
                '?conversation_id=' +
                currentConversationId
            );


        applyReceipts(
            data.receipts || []
        );


    } catch (error) {

        console.error(
            error
        );
    }
}


/*
|--------------------------------------------------------------------------
| SEND TEXT
|--------------------------------------------------------------------------
*/

async function sendCurrent() {

    if (!currentConversationId) {
        return;
    }

    if (selectedAttachment) {
        await sendAttachment();
        return;
    }

    const message = messageInput.value.trim();

    if (!message) {
        return;
    }

    const conversationAtSend = currentConversationId;
    const replySnapshot = replyTo ? { ...replyTo } : null;

    sendButton.disabled = true;
    const oldButtonText = sendButton.textContent;
    sendButton.textContent = '...';

    try {

        const form = new FormData();

        form.append('csrf_token', csrfToken);
        form.append('conversation_id', conversationAtSend);
        form.append('message', message);

        if (replySnapshot) {
            form.append('reply_to_id', replySnapshot.id);
        }

        const data = await jsonFetch(
            API.send,
            {
                method: 'POST',
                body: form
            }
        );

        if (conversationAtSend !== currentConversationId) {
            return;
        }

        messageInput.value = '';
        messageInput.style.height = '';
        clearReply();

        document
            .getElementById('noMessages')
            ?.remove();

        if (data.message_data) {

            const sentMessage = {
                ...data.message_data,
                sender_name:
                    data.message_data.sender_name ||
                    <?= json_encode($fullName) ?>,
                is_mine: 1,
                status:
                    data.message_data.status || 'sent',
                message_type:
                    data.message_data.message_type || 'text',
                reply:
                    data.message_data.reply ||
                    (
                        replySnapshot
                            ? {
                                id: Number(replySnapshot.id || 0),
                                sender_id: Number(replySnapshot.sender_id || 0),
                                sender_name: replySnapshot.sender_name || 'User',
                                message: replySnapshot.message || '',
                                message_type: replySnapshot.message_type || 'text'
                            }
                            : null
                    ),
                attachment_name:
                    data.message_data.attachment_name || null,
                attachment_mime:
                    data.message_data.attachment_mime || null,
                media_url:
                    data.message_data.media_url || null,
                is_view_once:
                    Number(data.message_data.is_view_once || 0),
                view_once_state:
                    data.message_data.view_once_state || null
            };

            appendMessage(sentMessage);

            const sentId = Number(sentMessage.id || 0);

            if (sentId > 0) {
                lastMessageId = Math.max(
                    Number(lastMessageId || 0),
                    sentId
                );
            }

            scrollBottom();

        } else {
            await loadMessages(false);
        }

        loadContacts().catch(error => console.error(error));

    } catch (error) {

        showToast(error.message);

    } finally {

        sendButton.disabled = false;
        sendButton.textContent = oldButtonText || '➤';
    }
}


sendButton.addEventListener(
    'click',
    sendCurrent
);


messageInput.addEventListener(
    'keydown',
    event => {

        if (
            event.key === 'Enter'
            && !event.shiftKey
        ) {

            event.preventDefault();

            sendCurrent();
        }
    }
);


/*
|--------------------------------------------------------------------------
| TYPING
|--------------------------------------------------------------------------
*/

messageInput.addEventListener(
    'input',
    () => {

        const now =
            Date.now();


        if (
            now - lastTypingSent >
            1200
        ) {

            sendPresence(true);

            lastTypingSent = now;
        }


        clearTimeout(
            typingTimer
        );


        typingTimer =
            setTimeout(
                () =>
                    sendPresence(false),
                1600
            );
    }
);


/*
|--------------------------------------------------------------------------
| ATTACHMENT
|--------------------------------------------------------------------------
*/

document
    .getElementById(
        'attachButton'
    )
    .addEventListener(
        'click',
        () => {

            if (
                currentConversationId
            ) {
                attachmentInput.click();
            }
        }
    );


attachmentInput.addEventListener(
    'change',
    () => {

        const file =
            attachmentInput.files?.[0];


        if (!file) {
            return;
        }


        selectedAttachment =
            file;


        attachmentName.textContent =
            file.name;

        attachmentInfo.textContent =
            formatBytes(
                file.size
            );


        attachmentPreview.classList.add(
            'show'
        );


        const viewable =
            file.type.startsWith(
                'image/'
            )
            ||
            file.type.startsWith(
                'video/'
            );


        viewOnceOption.classList.toggle(
            'show',
            viewable
        );


        if (!viewable) {

            viewOnceCheckbox.checked =
                false;
        }
    }
);


document
    .getElementById(
        'cancelAttachment'
    )
    .addEventListener(
        'click',
        clearAttachment
    );


function clearAttachment() {

    selectedAttachment = null;

    attachmentInput.value = '';

    attachmentPreview.classList.remove(
        'show'
    );

    viewOnceOption.classList.remove(
        'show'
    );

    viewOnceCheckbox.checked =
        false;
}


async function sendAttachment() {

    if (
        !selectedAttachment
        ||
        !currentConversationId
    ) {
        return;
    }


    try {

        const form =
            new FormData();

        form.append(
            'csrf_token',
            csrfToken
        );

        form.append(
            'conversation_id',
            currentConversationId
        );

        form.append(
            'attachment',
            selectedAttachment
        );

        form.append(
            'caption',
            messageInput.value.trim()
        );

        form.append(
            'is_view_once',
            viewOnceCheckbox.checked
                ? '1'
                : '0'
        );


        if (replyTo) {

            form.append(
                'reply_to_id',
                replyTo.id
            );
        }


        await jsonFetch(
            API.upload,
            {
                method: 'POST',
                body: form
            }
        );


        messageInput.value = '';

        clearAttachment();
        clearReply();

        await loadMessages(false);

        await loadContacts();


    } catch (error) {

        showToast(
            error.message
        );
    }
}


/*
|--------------------------------------------------------------------------
| VOICE
|--------------------------------------------------------------------------
*/

const voiceButton =
    document.getElementById(
        'voiceButton'
    );


voiceButton.addEventListener(
    'click',
    async () => {

        if (
            !currentConversationId
        ) {
            return;
        }


        if (isRecording) {

            stopRecording();

        } else {

            await startRecording();
        }
    }
);


async function startRecording() {

    try {

        const stream =
            await navigator
                .mediaDevices
                .getUserMedia({
                    audio: true
                });


        voiceChunks = [];


        mediaRecorder =
            new MediaRecorder(
                stream
            );


        mediaRecorder
            .addEventListener(
                'dataavailable',
                event => {

                    if (
                        event.data.size
                        > 0
                    ) {

                        voiceChunks.push(
                            event.data
                        );
                    }
                }
            );


        mediaRecorder
            .addEventListener(
                'stop',
                async () => {

                    const mime =
                        mediaRecorder
                            .mimeType
                        ||
                        'audio/webm';


                    const blob =
                        new Blob(
                            voiceChunks,
                            {
                                type:
                                    mime
                            }
                        );


                    stream
                        .getTracks()
                        .forEach(
                            track =>
                                track.stop()
                        );


                    await uploadVoice(
                        blob,
                        mime
                    );
                }
            );


        mediaRecorder.start();


        isRecording = true;


        voiceButton.classList.add(
            'recording'
        );

        voiceButton.textContent =
            '■';


    } catch (error) {

        showToast(
            'Microphone permission required.'
        );
    }
}


function stopRecording() {

    if (!mediaRecorder) {
        return;
    }


    if (
        mediaRecorder.state ===
        'inactive'
    ) {
        return;
    }


    isRecording = false;


    voiceButton.classList.remove(
        'recording'
    );

    voiceButton.textContent =
        '🎤';


    mediaRecorder.stop();
}


async function uploadVoice(
    blob,
    mime
) {

    try {

        const extension =
            mime.includes('ogg')
                ? 'ogg'
                : (
                    mime.includes('mp4')
                        ? 'm4a'
                        : 'webm'
                );


        const file =
            new File(
                [blob],
                'voice_' +
                Date.now() +
                '.' +
                extension,
                {
                    type: mime
                }
            );


        const form =
            new FormData();

        form.append(
            'csrf_token',
            csrfToken
        );

        form.append(
            'conversation_id',
            currentConversationId
        );

        form.append(
            'attachment',
            file
        );

        form.append(
            'media_kind',
            'voice'
        );

        form.append(
            'is_view_once',
            '0'
        );


        if (replyTo) {

            form.append(
                'reply_to_id',
                replyTo.id
            );
        }


        await jsonFetch(
            API.upload,
            {
                method: 'POST',
                body: form
            }
        );


        clearReply();

        await loadMessages(false);


    } catch (error) {

        showToast(
            error.message
        );
    }
}


/*
|--------------------------------------------------------------------------
| REPLY
|--------------------------------------------------------------------------
*/

function setReply(message) {

    replyTo = {

        id:
            Number(
                message.id
            ),

        sender_name:
            message.sender_name,

        message:
            message.message
    };


    document
        .getElementById(
            'replyName'
        )
        .textContent =
            replyTo.sender_name +
            ': ';


    document
        .getElementById(
            'replyText'
        )
        .textContent =
            replyTo.message;


    document
        .getElementById(
            'replyPreview'
        )
        .classList.add(
            'show'
        );


    messageInput.focus();
}


function clearReply() {

    replyTo = null;


    document
        .getElementById(
            'replyPreview'
        )
        .classList.remove(
            'show'
        );
}


document
    .getElementById(
        'cancelReply'
    )
    .addEventListener(
        'click',
        clearReply
    );


/*
|--------------------------------------------------------------------------
| PRESENCE
|--------------------------------------------------------------------------
*/

async function sendPresence(
    typing = false
) {

    try {

        const form =
            new FormData();

        form.append(
            'csrf_token',
            csrfToken
        );

        form.append(
            'conversation_id',
            currentConversationId ||
            0
        );

        form.append(
            'is_typing',
            (
                typing
                &&
                currentConversationId
            )
                ? '1'
                : '0'
        );


        await fetch(
            API.presence,
            {
                method: 'POST',
                body: form,
                cache: 'no-store'
            }
        );


    } catch (error) {

        console.error(
            error
        );
    }
}


/*
|--------------------------------------------------------------------------
| STATUS
|--------------------------------------------------------------------------
*/

async function loadStatus() {

    if (
        !currentConversationId
    ) {
        return;
    }


    try {

        const data =
            await jsonFetch(
                API.status +
                '?conversation_id=' +
                currentConversationId
            );


        chatStatus.textContent =
            data.status_text ||
            'Offline';


    } catch (error) {

        console.error(
            error
        );
    }
}


/*
|--------------------------------------------------------------------------
| DISAPPEARING
|--------------------------------------------------------------------------
*/

document
    .getElementById(
        'disappearingButton'
    )
    .addEventListener(
        'click',
        async () => {

            if (
                !currentConversationId
            ) {
                return;
            }


            try {

                const data =
                    await jsonFetch(
                        API.disappearing +
                        '?conversation_id=' +
                        currentConversationId
                    );


                const seconds =
                    Number(
                        data.disappearing_seconds
                        || 0
                    );


                const standards = [
                    0,
                    3600,
                    86400,
                    604800,
                    2592000
                ];


                if (
                    standards.includes(
                        seconds
                    )
                ) {

                    document
                        .getElementById(
                            'disappearingSelect'
                        )
                        .value =
                            String(seconds);

                    document
                        .getElementById(
                            'customTimeWrap'
                        )
                        .style.display =
                            'none';

                } else {

                    document
                        .getElementById(
                            'disappearingSelect'
                        )
                        .value =
                            'custom';

                    document
                        .getElementById(
                            'customMinutes'
                        )
                        .value =
                            Math.round(
                                seconds / 60
                            );

                    document
                        .getElementById(
                            'customTimeWrap'
                        )
                        .style.display =
                            'block';
                }


                openModal(
                    'disappearingModal'
                );


            } catch (error) {

                showToast(
                    error.message
                );
            }
        }
    );


document
    .getElementById(
        'disappearingSelect'
    )
    .addEventListener(
        'change',
        event => {

            document
                .getElementById(
                    'customTimeWrap'
                )
                .style.display =
                    event.target.value ===
                    'custom'
                        ? 'block'
                        : 'none';
        }
    );


document
    .getElementById(
        'saveDisappearing'
    )
    .addEventListener(
        'click',
        async () => {

            const select =
                document.getElementById(
                    'disappearingSelect'
                );


            let seconds =
                Number(
                    select.value
                );


            if (
                select.value ===
                'custom'
            ) {

                const minutes =
                    Number(
                        document
                            .getElementById(
                                'customMinutes'
                            )
                            .value
                    );


                if (
                    minutes < 1
                ) {

                    showToast(
                        'Enter minutes.'
                    );

                    return;
                }


                seconds =
                    Math.round(
                        minutes * 60
                    );
            }


            try {

                const form =
                    new FormData();

                form.append(
                    'csrf_token',
                    csrfToken
                );

                form.append(
                    'conversation_id',
                    currentConversationId
                );

                form.append(
                    'seconds',
                    seconds
                );


                const data =
                    await jsonFetch(
                        API.disappearing,
                        {
                            method:
                                'POST',

                            body:
                                form
                        }
                    );


                closeModal(
                    'disappearingModal'
                );


                showToast(
                    'Disappearing: ' +
                    data.label
                );


                await loadMessages(
                    false
                );


            } catch (error) {

                showToast(
                    error.message
                );
            }
        }
    );


/*
|--------------------------------------------------------------------------
| APPEARANCE
|--------------------------------------------------------------------------
*/

document
    .getElementById(
        'appearanceButton'
    )
    .addEventListener(
        'click',
        () =>
            openModal(
                'appearanceModal'
            )
    );


async function loadPreferences() {

    try {

        const data =
            await jsonFetch(
                API.preferences
            );


        applyPreferences(
            data.preferences
        );


    } catch (error) {

        console.error(
            error
        );
    }
}


function applyPreferences(
    preferences
) {

    if (!preferences) {
        return;
    }


    const theme =
        preferences.theme_color
        ||
        '#2563eb';


    const background =
        preferences.background_color
        ||
        '#f8fafc';


    document
        .documentElement
        .style.setProperty(
            '--theme',
            theme
        );


    document
        .documentElement
        .style.setProperty(
            '--chat-bg',
            background
        );


    document
        .getElementById(
            'themeColor'
        )
        .value =
            theme;


    document
        .getElementById(
            'backgroundColor'
        )
        .value =
            background;


    document
        .getElementById(
            'darkMode'
        )
        .checked =
            Number(
                preferences.dark_mode
            ) === 1;


    document.body
        .classList.toggle(
            'dark-chat',
            Number(
                preferences.dark_mode
            ) === 1
        );


    if (
        preferences.background_type ===
        'image'
        &&
        preferences.background_url
    ) {

        messagesBox.style
            .backgroundImage =
                `url("${preferences.background_url}")`;

    } else {

        messagesBox.style
            .backgroundImage =
                'none';
    }
}


document
    .getElementById(
        'saveAppearance'
    )
    .addEventListener(
        'click',
        async () => {

            try {

                const form =
                    new FormData();


                form.append(
                    'csrf_token',
                    csrfToken
                );


                form.append(
                    'theme_color',
                    document
                        .getElementById(
                            'themeColor'
                        )
                        .value
                );


                form.append(
                    'background_color',
                    document
                        .getElementById(
                            'backgroundColor'
                        )
                        .value
                );


                form.append(
                    'dark_mode',
                    document
                        .getElementById(
                            'darkMode'
                        )
                        .checked
                            ? '1'
                            : '0'
                );


                const file =
                    document
                        .getElementById(
                            'backgroundImage'
                        )
                        .files?.[0];


                if (file) {

                    form.append(
                        'background_image',
                        file
                    );

                    form.append(
                        'background_type',
                        'image'
                    );

                } else {

                    form.append(
                        'background_type',
                        'color'
                    );
                }


                const data =
                    await jsonFetch(
                        API.preferences,
                        {
                            method:
                                'POST',

                            body:
                                form
                        }
                    );


                applyPreferences(
                    data.preferences
                );


                closeModal(
                    'appearanceModal'
                );


                showToast(
                    'Appearance saved.'
                );


            } catch (error) {

                showToast(
                    error.message
                );
            }
        }
    );


document
    .getElementById(
        'removeBackground'
    )
    .addEventListener(
        'click',
        async () => {

            try {

                const form =
                    new FormData();


                form.append(
                    'csrf_token',
                    csrfToken
                );


                form.append(
                    'remove_background',
                    '1'
                );


                const data =
                    await jsonFetch(
                        API.preferences,
                        {
                            method:
                                'POST',

                            body:
                                form
                        }
                    );


                applyPreferences(
                    data.preferences
                );


                showToast(
                    'Background removed.'
                );


            } catch (error) {

                showToast(
                    error.message
                );
            }
        }
    );


/*
|--------------------------------------------------------------------------
| PROFILE
|--------------------------------------------------------------------------
*/

document
    .getElementById(
        'profileButton'
    )
    .addEventListener(
        'click',
        () =>
            openModal(
                'profileModal'
            )
    );


document
    .getElementById(
        'profilePictureInput'
    )
    .addEventListener(
        'change',
        event => {

            const file =
                event.target
                    .files?.[0];


            if (!file) {
                return;
            }


            const url =
                URL.createObjectURL(
                    file
                );


            document
                .getElementById(
                    'profilePreview'
                )
                .innerHTML = `
                    <img
                        src="${url}"
                        alt=""
                    >
                `;
        }
    );


document
    .getElementById(
        'saveProfilePicture'
    )
    .addEventListener(
        'click',
        async () => {

            const input =
                document.getElementById(
                    'profilePictureInput'
                );


            const file =
                input.files?.[0];


            if (!file) {

                showToast(
                    'Choose image.'
                );

                return;
            }


            try {

                const form =
                    new FormData();


                form.append(
                    'csrf_token',
                    csrfToken
                );


                form.append(
                    'profile_picture',
                    file
                );


                const data =
                    await jsonFetch(
                        API.profile,
                        {
                            method:
                                'POST',

                            body:
                                form
                        }
                    );


                document
                    .getElementById(
                        'myAvatar'
                    )
                    .innerHTML = `
                        <img
                            src="${escapeHtml(
                                data.profile_url
                            )}"
                        >
                    `;

                if (document.getElementById('myStatusAvatar')) {
                    document.getElementById('myStatusAvatar').innerHTML = `
                        <img src="${escapeHtml(data.profile_url)}" alt="">
                    `;
                }


                closeModal(
                    'profileModal'
                );


                showToast(
                    'Profile updated.'
                );


            } catch (error) {

                showToast(
                    error.message
                );
            }
        }
    );


document
    .getElementById(
        'removeProfilePicture'
    )
    .addEventListener(
        'click',
        async () => {

            try {

                const form =
                    new FormData();


                form.append(
                    'csrf_token',
                    csrfToken
                );


                form.append(
                    'action',
                    'remove'
                );


                await jsonFetch(
                    API.profile,
                    {
                        method:
                            'POST',

                        body:
                            form
                    }
                );


                document
                    .getElementById(
                        'myAvatar'
                    )
                    .textContent =
                        <?= json_encode(
                            strtoupper(
                                substr(
                                    $fullName,
                                    0,
                                    1
                                )
                            )
                        ) ?>;


                closeModal(
                    'profileModal'
                );


                showToast(
                    'Profile removed.'
                );


            } catch (error) {

                showToast(
                    error.message
                );
            }
        }
    );


/*
|--------------------------------------------------------------------------
| POLLING
|--------------------------------------------------------------------------
*/

function startPolling() {

    clearInterval(
        messageTimer
    );

    clearInterval(
        statusTimer
    );

    clearInterval(
        receiptTimer
    );


    messageTimer =
        setInterval(
            () =>
                loadMessages(false),
            1200
        );


    statusTimer =
        setInterval(
            loadStatus,
            1500
        );


    receiptTimer =
        setInterval(
            loadReceipts,
            1500
        );
}


/*
|--------------------------------------------------------------------------
| MOBILE
|--------------------------------------------------------------------------
*/

document
    .getElementById(
        'mobileBack'
    )
    .addEventListener(
        'click',
        () => {

            chatPanel.classList.remove(
                'mobile-open'
            );
        }
    );


function scrollBottom() {

    requestAnimationFrame(
        () => {

            messagesBox.scrollTop =
                messagesBox.scrollHeight;
        }
    );
}



/*
|--------------------------------------------------------------------------
| CALL HISTORY
|--------------------------------------------------------------------------
*/

function formatCallDuration(seconds) {
    seconds = Math.max(0, Number(seconds || 0));

    if (!seconds) {
        return '';
    }

    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;

    if (hours > 0) {
        return [
            String(hours).padStart(2, '0'),
            String(minutes).padStart(2, '0'),
            String(secs).padStart(2, '0')
        ].join(':');
    }

    return [
        String(minutes).padStart(2, '0'),
        String(secs).padStart(2, '0')
    ].join(':');
}


function formatCallDate(value) {
    if (!value) return '';

    const date = new Date(
        String(value).replace(' ', 'T')
    );

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return date.toLocaleString([], {
        year: 'numeric',
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit'
    });
}


function callHistoryLabel(call) {
    const direction =
        call.direction === 'outgoing'
            ? 'Outgoing'
            : 'Incoming';

    const type =
        call.call_type === 'video'
            ? 'Video call'
            : 'Voice call';

    if (
        call.status === 'missed'
        && call.direction === 'incoming'
    ) {
        return 'Missed ' + type.toLowerCase();
    }

    if (call.status === 'declined') {
        return direction + ' • Declined';
    }

    if (call.status === 'cancelled') {
        return direction + ' • Cancelled';
    }

    if (call.status === 'ringing') {
        return direction + ' • Ringing';
    }

    return direction + ' • ' + type;
}


function renderCallHistory(calls) {
    if (!callHistoryList) return;

    if (!Array.isArray(calls) || !calls.length) {
        callHistoryList.innerHTML = `
            <div class="call-history-empty">
                No call history for this conversation.
            </div>
        `;
        return;
    }

    callHistoryList.innerHTML = calls.map(call => {
        const missed =
            call.status === 'missed'
            && call.direction === 'incoming';

        const icon =
            call.call_type === 'video'
                ? '📹'
                : '📞';

        const duration =
            formatCallDuration(
                call.duration_seconds
            );

        const details = [
            formatCallDate(call.started_at),
            duration ? 'Duration ' + duration : '',
            call.status === 'ended' ? 'Ended' : '',
            missed ? 'No answer' : ''
        ].filter(Boolean).join(' • ');

        return `
            <div class="call-history-item ${missed ? 'call-history-missed' : ''}">
                <div class="call-history-icon">
                    ${icon}
                </div>

                <div class="call-history-body">
                    <div class="call-history-title">
                        ${escapeHtml(callHistoryLabel(call))}
                    </div>

                    <div class="call-history-sub">
                        ${escapeHtml(details)}
                    </div>
                </div>
            </div>
        `;
    }).join('');
}


async function loadCallHistory() {
    if (!currentConversationId) {
        showToast('Select a user first.');
        return;
    }

    if (callHistoryList) {
        callHistoryList.innerHTML = `
            <div class="call-history-empty">
                Loading call history...
            </div>
        `;
    }

    openModal('callHistoryModal');

    try {
        const data = await jsonFetch(
            API.calls +
            '?action=history&conversation_id=' +
            encodeURIComponent(currentConversationId) +
            '&limit=50'
        );

        renderCallHistory(data.calls || []);
    } catch (error) {
        if (callHistoryList) {
            callHistoryList.innerHTML = `
                <div class="call-history-empty">
                    ${escapeHtml(error.message)}
                </div>
            `;
        }
    }
}


callHistoryButton?.addEventListener(
    'click',
    loadCallHistory
);



/*
|--------------------------------------------------------------------------
| INCOMING CALL RINGTONE
|--------------------------------------------------------------------------
*/

function playRingtoneTone() {
    if (!ringtoneAudioContext) return;

    const ctx = ringtoneAudioContext;
    const now = ctx.currentTime;

    // Modern two-note phone ring: ding-ding, then a short pause.
    const notes = [
        { start: 0.00, frequency: 659.25, duration: 0.22 },
        { start: 0.28, frequency: 783.99, duration: 0.28 }
    ];

    notes.forEach(note => {
        const oscillator = ctx.createOscillator();
        const gain = ctx.createGain();

        oscillator.type = 'sine';
        oscillator.frequency.setValueAtTime(note.frequency, now + note.start);

        gain.gain.setValueAtTime(0.0001, now + note.start);
        gain.gain.exponentialRampToValueAtTime(0.14, now + note.start + 0.025);
        gain.gain.exponentialRampToValueAtTime(0.07, now + note.start + note.duration * 0.65);
        gain.gain.exponentialRampToValueAtTime(0.0001, now + note.start + note.duration);

        oscillator.connect(gain);
        gain.connect(ctx.destination);

        oscillator.start(now + note.start);
        oscillator.stop(now + note.start + note.duration + 0.02);
    });
}


function startRingtone() {
    if (ringtonePlaying) return;

    ringtonePlaying = true;

    try {
        const AudioContextClass =
            window.AudioContext ||
            window.webkitAudioContext;

        if (!AudioContextClass) {
            ringtonePlaying = false;
            return;
        }

        if (!ringtoneAudioContext) {
            ringtoneAudioContext =
                new AudioContextClass();
        }

        if (ringtoneAudioContext.state === 'suspended') {
            ringtoneAudioContext.resume().catch(() => {});
        }

        playRingtoneTone();

        clearInterval(ringtoneTimer);

        ringtoneTimer = setInterval(() => {
            if (!ringtonePlaying) return;
            playRingtoneTone();
        }, 1850);

    } catch (error) {
        console.warn('Ringtone unavailable:', error);
        ringtonePlaying = false;
    }
}


function stopRingtone() {
    ringtonePlaying = false;

    clearInterval(ringtoneTimer);
    ringtoneTimer = null;
}


function unlockRingtoneAudio() {
    try {
        const AudioContextClass =
            window.AudioContext ||
            window.webkitAudioContext;

        if (!AudioContextClass) return;

        if (!ringtoneAudioContext) {
            ringtoneAudioContext =
                new AudioContextClass();
        }

        if (ringtoneAudioContext.state === 'suspended') {
            ringtoneAudioContext.resume().catch(() => {});
        }
    } catch (error) {
        console.warn('Audio unlock unavailable:', error);
    }
}

document.addEventListener('click', unlockRingtoneAudio, { once: true });
document.addEventListener('touchstart', unlockRingtoneAudio, { once: true });
document.addEventListener('keydown', unlockRingtoneAudio, { once: true });


/*
|--------------------------------------------------------------------------
| VOICE / VIDEO CALLS (WEBRTC)
|--------------------------------------------------------------------------
*/

const rtcConfiguration = {
    iceServers: [
        { urls: 'stun:stun.l.google.com:19302' },
        { urls: 'stun:stun1.l.google.com:19302' }
    ]
};

function setCallOverlay(element, show) {
    if (!element) return;
    element.style.display = show ? 'flex' : 'none';
}

function callAvatarHtml(name, userId = 0, profileUrl = '') {
    if (profileUrl) {
        return avatarHtml(name, profileUrl);
    }
    if (userId > 0) {
        return avatarHtml(
            name,
            '../chat/api/profile-picture.php?user_id=' + encodeURIComponent(userId)
        );
    }
    return escapeHtml(initials(name));
}

async function callPost(action, values = {}) {
    const form = new FormData();
    form.append('csrf_token', csrfToken);
    form.append('action', action);
    Object.entries(values).forEach(([key, value]) => {
        if (value !== undefined && value !== null) {
            form.append(key, String(value));
        }
    });
    return jsonFetch(API.calls, {
        method: 'POST',
        body: form
    });
}

function otherCallUser(call) {
    if (!call) return { id: 0, name: 'User' };
    if (Number(call.caller_id) === Number(currentUserId)) {
        return {
            id: Number(call.receiver_id || 0),
            name: call.receiver_name || currentTarget?.full_name || 'User'
        };
    }
    return {
        id: Number(call.caller_id || 0),
        name: call.caller_name || 'User'
    };
}

async function prepareLocalMedia(callType) {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        throw new Error('Camera/Microphone is not supported in this browser.');
    }

    if (localStream) {
        localStream.getTracks().forEach(track => track.stop());
        localStream = null;
    }

    let joinedWithoutCamera = false;

    /*
    |--------------------------------------------------------------------------
    | VIDEO CALL WITH VOICE-ONLY FALLBACK
    |--------------------------------------------------------------------------
    | If the camera is busy/in use (for example Chrome is already using the
    | only webcam while Edge accepts the same call), do not reject the call.
    | Retry with microphone only and continue the WebRTC call.
    |--------------------------------------------------------------------------
    */
    if (callType === 'video') {
        try {
            localStream = await navigator.mediaDevices.getUserMedia({
                audio: true,
                video: {
                    facingMode: { ideal: currentCameraFacingMode }
                }
            });
        } catch (cameraError) {
            console.warn(
                'Camera unavailable. Retrying as voice-only:',
                cameraError
            );

            try {
                localStream = await navigator.mediaDevices.getUserMedia({
                    audio: true,
                    video: false
                });

                joinedWithoutCamera = true;

                showToast(
                    'Camera is busy or unavailable. Call connected with voice only.'
                );
            } catch (audioError) {
                console.error('Microphone unavailable:', audioError);

                throw new Error(
                    'Camera and microphone are unavailable. Close other apps using them and try again.'
                );
            }
        }
    } else {
        localStream = await navigator.mediaDevices.getUserMedia({
            audio: true,
            video: false
        });
    }

    localVideo.srcObject = localStream;

    const hasVideoTrack =
        localStream &&
        localStream.getVideoTracks().length > 0;

    /*
    | If this was a video call but the camera was busy:
    | - keep the call connected
    | - send microphone audio
    | - hide local-camera controls
    | - still allow remote video to be displayed
    */
    localVideo.style.display =
        callType === 'video' && hasVideoTrack
            ? 'block'
            : 'none';

    cameraCallButton.style.display =
        callType === 'video' && hasVideoTrack
            ? 'flex'
            : 'none';

    switchCameraButton.style.display =
        callType === 'video' && hasVideoTrack
            ? 'flex'
            : 'none';

    voiceCallBackground.style.display =
        callType === 'voice'
            ? 'flex'
            : 'none';

    muteCallButton.classList.remove('is-muted');
    muteCallButton.innerHTML = '🎤<span>Mute</span>';

    cameraCallButton.classList.remove('is-off');
    cameraCallButton.innerHTML = '📹<span>Camera</span>';

    switchCameraButton.innerHTML = '🔄<span>Back</span>';

    speakerCallButton.classList.remove('is-off');
    speakerCallButton.innerHTML = '🔊<span>Speaker</span>';

    return {
        joinedWithoutCamera,
        hasVideoTrack
    };
}

function createCallPeerConnection() {
    if (peerConnection) {
        try { peerConnection.close(); } catch (_) {}
    }

    peerConnection = new RTCPeerConnection(rtcConfiguration);
    pendingIceCandidates = [];

    if (localStream) {
        localStream.getTracks().forEach(track => {
            peerConnection.addTrack(track, localStream);
        });
    }

    peerConnection.ontrack = event => {
        const [stream] = event.streams;
        if (stream) {
            remoteVideo.srcObject = stream;
            remoteVideo.play().catch(() => {});
        }
    };

    peerConnection.onicecandidate = event => {
        if (!event.candidate || !activeCallId) return;
        callPost('signal', {
            call_id: activeCallId,
            signal_type: 'ice',
            signal_data: JSON.stringify(event.candidate.toJSON())
        }).catch(error => console.error('ICE signal:', error));
    };

    peerConnection.onconnectionstatechange = () => {
        if (!peerConnection) return;
        const state = peerConnection.connectionState;
        if (state === 'connected') {
            setCallStatusText('Connected');
            startCallDuration();
        } else if (state === 'failed') {
            showToast('Call connection failed.');
        }
    };
}

async function flushPendingIce() {
    if (!peerConnection?.remoteDescription) return;
    while (pendingIceCandidates.length) {
        const candidate = pendingIceCandidates.shift();
        try {
            await peerConnection.addIceCandidate(candidate);
        } catch (error) {
            console.error('ICE candidate:', error);
        }
    }
}

function setCallStatusText(text) {
    if (activeCallStatus) activeCallStatus.textContent = text;
    if (callTopStatus) callTopStatus.textContent = text;
}

function showActiveCall(call) {
    const other = otherCallUser(call);
    const callType = call.call_type || 'voice';

    activeCallName.textContent = other.name;
    callTopName.textContent = other.name;
    activeCallAvatar.innerHTML = callAvatarHtml(other.name, other.id, currentTarget?.profile_url || '');
    setCallStatusText(call.status === 'accepted' ? 'Connecting...' : 'Calling...');
    callDuration.textContent = '00:00';

    remoteVideo.style.display = callType === 'video' ? 'block' : 'none';
    voiceCallBackground.style.display = callType === 'video' ? 'none' : 'flex';
    localVideo.style.display = callType === 'video' ? 'block' : 'none';
    cameraCallButton.style.display = callType === 'video' ? 'flex' : 'none';
    switchCameraButton.style.display = callType === 'video' ? 'flex' : 'none';

    setCallOverlay(activeCallModal, true);
}

function showIncomingCall(call) {
    incomingCallData = call;
    incomingCallName.textContent = call.caller_name || 'Incoming Call';
    incomingCallType.textContent = call.call_type === 'video'
        ? 'Incoming video call...'
        : 'Incoming voice call...';
    incomingCallAvatar.innerHTML = callAvatarHtml(
        call.caller_name || 'User',
        Number(call.caller_id || 0),
        ''
    );
    setCallOverlay(incomingCallModal, true);
    startRingtone();
}

function hideIncomingCall() {
    stopRingtone();
    setCallOverlay(incomingCallModal, false);
    incomingCallData = null;
}


async function switchActiveCamera() {
    if (cameraSwitchBusy || !localStream || !peerConnection) {
        return;
    }

    const currentVideoTrack = localStream.getVideoTracks()[0];

    if (!currentVideoTrack) {
        showToast('Camera is not available.');
        return;
    }

    cameraSwitchBusy = true;
    switchCameraButton.disabled = true;
    switchCameraButton.innerHTML = '⏳<span>Switching</span>';

    const nextFacingMode = currentCameraFacingMode === 'user'
        ? 'environment'
        : 'user';

    try {
        const newVideoStream = await navigator.mediaDevices.getUserMedia({
            audio: false,
            video: {
                facingMode: { ideal: nextFacingMode }
            }
        });

        const newVideoTrack = newVideoStream.getVideoTracks()[0];

        if (!newVideoTrack) {
            throw new Error('No camera was found.');
        }

        const videoSender = peerConnection
            .getSenders()
            .find(sender => sender.track && sender.track.kind === 'video');

        if (!videoSender) {
            newVideoTrack.stop();
            throw new Error('Video sender is not available.');
        }

        const wasEnabled = currentVideoTrack.enabled;
        newVideoTrack.enabled = wasEnabled;

        await videoSender.replaceTrack(newVideoTrack);

        localStream.removeTrack(currentVideoTrack);
        localStream.addTrack(newVideoTrack);
        currentVideoTrack.stop();

        localVideo.srcObject = localStream;
        localVideo.play().catch(() => {});

        currentCameraFacingMode = nextFacingMode;

        switchCameraButton.innerHTML =
            currentCameraFacingMode === 'environment'
                ? '🤳<span>Front</span>'
                : '🔄<span>Back</span>';

        showToast(
            currentCameraFacingMode === 'environment'
                ? 'Back camera selected.'
                : 'Front camera selected.'
        );
    } catch (error) {
        console.error('Switch camera:', error);

        showToast(
            error.message ||
            'Could not switch camera. This device may have only one camera.'
        );
    } finally {
        cameraSwitchBusy = false;
        switchCameraButton.disabled = false;

        switchCameraButton.innerHTML =
            currentCameraFacingMode === 'environment'
                ? '🤳<span>Front</span>'
                : '🔄<span>Back</span>';
    }
}

async function startOutgoingCall(callType) {
    if (callBusy || activeCallId) {
        showToast('You are already in a call.');
        return;
    }
    if (!currentConversationId || !currentTarget?.id) {
        showToast('Open a conversation first.');
        return;
    }

    callBusy = true;
    try {
        if (callType === 'video') {
            currentCameraFacingMode = 'user';
        }

        await prepareLocalMedia(callType);

        const data = await callPost('start', {
            conversation_id: currentConversationId,
            receiver_id: Number(currentTarget.id),
            call_type: callType
        });

        activeCallData = data.call;
        activeCallId = Number(data.call.id || 0);
        lastCallSignalId = 0;
        callConnectedAt = null;

        showActiveCall(activeCallData);
        createCallPeerConnection();

        const offer = await peerConnection.createOffer();
        await peerConnection.setLocalDescription(offer);

        await callPost('signal', {
            call_id: activeCallId,
            signal_type: 'offer',
            signal_data: JSON.stringify(offer)
        });

        startCallPolling();
    } catch (error) {
        cleanupCall(false);
        showToast(error.message || 'Could not start call.');
    } finally {
        callBusy = false;
    }
}

async function acceptIncomingCall() {
    stopRingtone();
    if (!incomingCallData || callBusy) return;
    callBusy = true;

    try {
        const callId = Number(incomingCallData.id || 0);
        const callType = incomingCallData.call_type || 'voice';

        if (callType === 'video') {
            currentCameraFacingMode = 'user';
        }

        await prepareLocalMedia(callType);
        const data = await callPost('accept', { call_id: callId });

        activeCallData = data.call;
        activeCallId = callId;
        lastCallSignalId = 0;
        callConnectedAt = null;

        hideIncomingCall();
        showActiveCall(activeCallData);
        createCallPeerConnection();
        startCallPolling();
        await pollCallSignals();
    } catch (error) {
        showToast(error.message || 'Could not accept call.');
        cleanupCall(false);
    } finally {
        callBusy = false;
    }
}

async function declineIncomingCall() {
    stopRingtone();
    if (!incomingCallData) return;
    const callId = Number(incomingCallData.id || 0);
    try {
        await callPost('decline', { call_id: callId });
    } catch (error) {
        console.error('Decline call:', error);
    }
    hideIncomingCall();
}

async function endActiveCall() {
    stopRingtone();
    if (!activeCallId) {
        cleanupCall(false);
        return;
    }

    const callId = activeCallId;
    try {
        await callPost('end', { call_id: callId });
    } catch (error) {
        console.error('End call:', error);
    }
    cleanupCall(false);
}

async function pollIncomingCall() {
    if (activeCallId || callBusy) return;

    try {
        const data = await jsonFetch(API.calls + '?action=incoming');
        if (data.call) {
            const incomingId = Number(data.call.id || 0);
            if (!incomingCallData || Number(incomingCallData.id) !== incomingId) {
                showIncomingCall(data.call);
            }
        } else if (incomingCallData) {
            hideIncomingCall();
        }
    } catch (error) {
        console.error('Incoming call:', error);
    }
}

async function pollCallStatus() {
    if (!activeCallId) return;

    try {
        const data = await jsonFetch(
            API.calls + '?action=poll&call_id=' + encodeURIComponent(activeCallId)
        );
        const call = data.call;
        activeCallData = call;

        if (call.status === 'accepted') {
            setCallStatusText(peerConnection?.connectionState === 'connected' ? 'Connected' : 'Connecting...');
            if (call.answered_at && !callConnectedAt) {
                callConnectedAt = new Date(String(call.answered_at).replace(' ', 'T'));
                startCallDuration();
            }
        }

        if (['declined','ended','cancelled','missed'].includes(call.status)) {
            const labels = {
                declined: 'Call declined.',
                ended: 'Call ended.',
                cancelled: 'Call cancelled.',
                missed: 'Missed call.'
            };
            showToast(labels[call.status] || 'Call finished.');
            cleanupCall(false);
        }
    } catch (error) {
        console.error('Call status:', error);
    }
}

async function pollCallSignals() {
    if (!activeCallId || !peerConnection) return;

    try {
        const data = await jsonFetch(
            API.calls +
            '?action=signals&call_id=' + encodeURIComponent(activeCallId) +
            '&after_id=' + encodeURIComponent(lastCallSignalId)
        );

        for (const signal of (data.signals || [])) {
            lastCallSignalId = Math.max(lastCallSignalId, Number(signal.id || 0));

            let payload;
            try {
                payload = JSON.parse(signal.signal_data);
            } catch (_) {
                continue;
            }

            if (signal.signal_type === 'offer') {
                if (peerConnection.signalingState !== 'stable' || peerConnection.remoteDescription) {
                    continue;
                }
                await peerConnection.setRemoteDescription(new RTCSessionDescription(payload));
                await flushPendingIce();

                const answer = await peerConnection.createAnswer();
                await peerConnection.setLocalDescription(answer);
                await callPost('signal', {
                    call_id: activeCallId,
                    signal_type: 'answer',
                    signal_data: JSON.stringify(answer)
                });
            }

            if (signal.signal_type === 'answer') {
                if (!peerConnection.remoteDescription) {
                    await peerConnection.setRemoteDescription(new RTCSessionDescription(payload));
                    await flushPendingIce();
                }
            }

            if (signal.signal_type === 'ice') {
                const candidate = new RTCIceCandidate(payload);
                if (peerConnection.remoteDescription) {
                    await peerConnection.addIceCandidate(candidate);
                } else {
                    pendingIceCandidates.push(candidate);
                }
            }
        }
    } catch (error) {
        console.error('Call signals:', error);
    }
}

function startCallPolling() {
    clearInterval(callSignalTimer);
    clearInterval(callStatusTimer);

    callSignalTimer = setInterval(pollCallSignals, 700);
    callStatusTimer = setInterval(pollCallStatus, 1000);

    pollCallSignals();
    pollCallStatus();
}

function startCallDuration() {
    if (callDurationTimer) return;
    if (!callConnectedAt) callConnectedAt = new Date();

    const updateDuration = () => {
        const seconds = Math.max(0, Math.floor((Date.now() - callConnectedAt.getTime()) / 1000));
        const mins = String(Math.floor(seconds / 60)).padStart(2, '0');
        const secs = String(seconds % 60).padStart(2, '0');
        callDuration.textContent = mins + ':' + secs;
    };

    updateDuration();
    callDurationTimer = setInterval(updateDuration, 1000);
}

function cleanupCall(showMessage = false) {
    clearInterval(callSignalTimer);
    clearInterval(callStatusTimer);
    clearInterval(callDurationTimer);
    callSignalTimer = null;
    callStatusTimer = null;
    callDurationTimer = null;

    if (peerConnection) {
        try { peerConnection.onicecandidate = null; } catch (_) {}
        try { peerConnection.ontrack = null; } catch (_) {}
        try { peerConnection.close(); } catch (_) {}
        peerConnection = null;
    }

    if (localStream) {
        localStream.getTracks().forEach(track => track.stop());
        localStream = null;
    }

    if (remoteVideo) remoteVideo.srcObject = null;
    if (localVideo) localVideo.srcObject = null;

    activeCallId = 0;
    activeCallData = null;
    lastCallSignalId = 0;
    callConnectedAt = null;
    pendingIceCandidates = [];
    callBusy = false;
    cameraSwitchBusy = false;
    currentCameraFacingMode = 'user';

    if (switchCameraButton) {
        switchCameraButton.style.display = 'none';
        switchCameraButton.disabled = false;
        switchCameraButton.innerHTML = '🔄<span>Back</span>';
    }

    setCallOverlay(activeCallModal, false);
    hideIncomingCall();

    if (
        callHistoryModal?.classList.contains('show')
        && currentConversationId
    ) {
        loadCallHistory();
    }

    if (showMessage) showToast('Call ended.');
}

muteCallButton?.addEventListener('click', () => {
    if (!localStream) return;
    const tracks = localStream.getAudioTracks();
    if (!tracks.length) return;
    const enable = !tracks[0].enabled;
    tracks.forEach(track => track.enabled = enable);
    muteCallButton.classList.toggle('is-muted', !enable);
    muteCallButton.innerHTML = enable ? '🎤<span>Mute</span>' : '🔇<span>Unmute</span>';
});

cameraCallButton?.addEventListener('click', () => {
    if (!localStream) return;
    const tracks = localStream.getVideoTracks();
    if (!tracks.length) return;
    const enable = !tracks[0].enabled;
    tracks.forEach(track => track.enabled = enable);
    cameraCallButton.classList.toggle('is-off', !enable);
    cameraCallButton.innerHTML = enable ? '📹<span>Camera</span>' : '🚫<span>Camera Off</span>';
});

switchCameraButton?.addEventListener('click', switchActiveCamera);

speakerCallButton?.addEventListener('click', () => {
    remoteVideo.muted = !remoteVideo.muted;
    speakerCallButton.classList.toggle('is-off', remoteVideo.muted);
    speakerCallButton.innerHTML = remoteVideo.muted
        ? '🔇<span>Speaker Off</span>'
        : '🔊<span>Speaker</span>';
});

voiceCallButton?.addEventListener('click', () => startOutgoingCall('voice'));
videoCallButton?.addEventListener('click', () => startOutgoingCall('video'));
acceptCallButton?.addEventListener('click', acceptIncomingCall);
declineCallButton?.addEventListener('click', declineIncomingCall);
endCallButton?.addEventListener('click', endActiveCall);



/*
|--------------------------------------------------------------------------
| BROWSER NOTIFICATIONS - ON / OFF
|--------------------------------------------------------------------------
*/

const notificationButton =
    document.getElementById('notificationButton');

let notificationPollTimer = null;
let notificationPrimed = false;

let newestNotificationMessageId = 0;
let newestNotificationCallId = 0;

const notificationPreferenceKey =
    'bakhaar_chat_notifications_enabled';


function chatNotificationsEnabled() {

    return localStorage.getItem(
        notificationPreferenceKey
    ) === '1';
}


function setChatNotificationsEnabled(enabled) {

    localStorage.setItem(
        notificationPreferenceKey,
        enabled ? '1' : '0'
    );
}


function updateNotificationButton() {

    if (!notificationButton) {
        return;
    }

    notificationButton.style.width = 'auto';
    notificationButton.style.padding = '0 11px';

    if (!('Notification' in window)) {

        notificationButton.textContent =
            '🔕 Unsupported';

        notificationButton.title =
            'Notifications are not supported by this browser';

        notificationButton.disabled = true;

        return;
    }

    notificationButton.disabled = false;

    if (Notification.permission === 'denied') {

        notificationButton.textContent =
            '🔕 Blocked';

        notificationButton.title =
            'Notifications blocked in browser settings';

        notificationButton.setAttribute(
            'aria-label',
            'Notifications blocked'
        );

        notificationButton.style.borderColor =
            '#ef4444';

        return;
    }

    if (
        Notification.permission === 'granted' &&
        chatNotificationsEnabled()
    ) {

        notificationButton.textContent =
            '🔔 ON';

        notificationButton.title =
            'Turn notifications OFF';

        notificationButton.setAttribute(
            'aria-label',
            'Turn notifications off'
        );

        notificationButton.style.borderColor =
            '#22c55e';

        return;
    }

    notificationButton.textContent =
        '🔕 OFF';

    notificationButton.title =
        'Turn notifications ON';

    notificationButton.setAttribute(
        'aria-label',
        'Turn notifications on'
    );

    notificationButton.style.borderColor =
        '#94a3b8';
}


async function registerChatServiceWorker() {

    if (!('serviceWorker' in navigator)) {
        return null;
    }

    try {

        return await navigator.serviceWorker.register(
            '../sw.js'
        );

    } catch (error) {

        console.error(
            'Service worker registration failed:',
            error
        );

        return null;
    }
}


async function toggleChatNotifications() {

    if (!('Notification' in window)) {

        showToast(
            'This browser does not support notifications.'
        );

        return;
    }

    if (Notification.permission === 'denied') {

        updateNotificationButton();

        showToast(
            'Notifications are blocked. Enable them from browser site settings.'
        );

        return;
    }


    /*
    | ON -> OFF
    */
    if (
        Notification.permission === 'granted' &&
        chatNotificationsEnabled()
    ) {

        setChatNotificationsEnabled(false);

        clearInterval(
            notificationPollTimer
        );

        notificationPollTimer = null;
        notificationPrimed = false;

        updateNotificationButton();

        showToast(
            'Notifications turned OFF.'
        );

        return;
    }


    /*
    | OFF -> ON
    */
    try {

        await registerChatServiceWorker();

        let permission =
            Notification.permission;

        if (permission !== 'granted') {

            permission =
                await Notification.requestPermission();
        }

        if (permission === 'granted') {

            setChatNotificationsEnabled(true);

            updateNotificationButton();

            await primeNotificationState();

            startNotificationPolling();

            showToast(
                'Notifications turned ON.'
            );

        } else {

            setChatNotificationsEnabled(false);

            updateNotificationButton();

            if (permission === 'denied') {

                showToast(
                    'Notifications are blocked. Enable them from browser site settings.'
                );
            }
        }

    } catch (error) {

        console.error(error);

        showToast(
            'Could not change notifications.'
        );
    }
}


async function showBrowserNotification(
    title,
    options = {}
) {

    if (
        !('Notification' in window) ||
        Notification.permission !== 'granted' ||
        !chatNotificationsEnabled()
    ) {
        return;
    }

    const url =
        options.url ||
        window.location.href;

    const notificationOptions = {

        body:
            options.body || '',

        icon:
            options.icon ||
            '../assets/images/favicon.png',

        badge:
            '../assets/images/favicon.png',

        tag:
            options.tag ||
            `bakhaar-${Date.now()}`,

        renotify: true,

        data: {
            url
        }
    };


    try {

        const registration =
            await navigator.serviceWorker.ready;

        await registration.showNotification(
            title,
            notificationOptions
        );

        return;

    } catch (error) {

        /*
        | Fall back to regular browser notification.
        */
    }


    try {

        const notice =
            new Notification(
                title,
                notificationOptions
            );

        notice.onclick = () => {

            window.focus();

            if (url) {
                window.location.href = url;
            }

            notice.close();
        };

    } catch (error) {

        console.error(
            'Notification failed:',
            error
        );
    }
}


function chatNotificationUrl(
    conversationId = 0
) {

    const url =
        new URL(
            window.location.href
        );

    if (conversationId) {

        url.searchParams.set(
            'conversation_id',
            String(conversationId)
        );
    }

    return url.href;
}


async function fetchNotificationFeed() {

    const response =
        await fetch(
            API.notifications,
            {
                method: 'GET',

                headers: {
                    'Accept':
                        'application/json'
                },

                cache:
                    'no-store'
            }
        );

    const data =
        await response.json();

    if (
        !response.ok ||
        !data.success
    ) {

        throw new Error(
            data.message ||
            'Unable to load notifications.'
        );
    }

    return data;
}


async function primeNotificationState() {

    if (
        !('Notification' in window) ||
        Notification.permission !== 'granted' ||
        !chatNotificationsEnabled()
    ) {
        return;
    }

    try {

        const data =
            await fetchNotificationFeed();

        const messages =
            Array.isArray(data.messages)
                ? data.messages
                : [];

        const calls =
            Array.isArray(data.calls)
                ? data.calls
                : [];


        newestNotificationMessageId =
            messages.reduce(
                (max, item) =>
                    Math.max(
                        max,
                        Number(item.id) || 0
                    ),
                newestNotificationMessageId
            );


        newestNotificationCallId =
            calls.reduce(
                (max, item) =>
                    Math.max(
                        max,
                        Number(item.id) || 0
                    ),
                newestNotificationCallId
            );


        notificationPrimed = true;

    } catch (error) {

        console.error(
            'Notification prime failed:',
            error
        );
    }
}


async function pollBrowserNotifications() {

    if (
        !notificationPrimed ||
        Notification.permission !== 'granted' ||
        !chatNotificationsEnabled()
    ) {
        return;
    }


    try {

        const data =
            await fetchNotificationFeed();

        const messages =
            Array.isArray(data.messages)
                ? data.messages
                : [];

        const calls =
            Array.isArray(data.calls)
                ? data.calls
                : [];


        const newMessages =
            messages
                .filter(
                    item =>
                        (Number(item.id) || 0) >
                        newestNotificationMessageId
                )
                .sort(
                    (a, b) =>
                        Number(a.id) -
                        Number(b.id)
                );


        for (const message of newMessages) {

            const messageId =
                Number(message.id) || 0;

            newestNotificationMessageId =
                Math.max(
                    newestNotificationMessageId,
                    messageId
                );


            /*
            | Haddii Super Admin-ku isla conversation-kan
            | hadda eegayo, notification kale lama soo saarayo.
            */
            const sameOpenConversation =
                !document.hidden &&
                Number(currentConversationId) ===
                    Number(message.conversation_id);

            if (sameOpenConversation) {
                continue;
            }


            await showBrowserNotification(
                message.sender_name ||
                'New message',
                {
                    body:
                        message.preview ||
                        'New message',

                    tag:
                        `chat-message-${messageId}`,

                    url:
                        chatNotificationUrl(
                            message.conversation_id
                        )
                }
            );
        }


        const newCalls =
            calls
                .filter(
                    item =>
                        (Number(item.id) || 0) >
                        newestNotificationCallId
                )
                .sort(
                    (a, b) =>
                        Number(a.id) -
                        Number(b.id)
                );


        for (const call of newCalls) {

            const callId =
                Number(call.id) || 0;

            newestNotificationCallId =
                Math.max(
                    newestNotificationCallId,
                    callId
                );


            /*
            | Marka chat-kan la eegayo,
            | incoming-call popup-ka gudaha page-ka ayaa ku filan.
            */
            if (!document.hidden) {
                continue;
            }


            const isVideo =
                call.call_type === 'video';


            await showBrowserNotification(
                call.caller_name ||
                'Incoming call',
                {
                    body:
                        isVideo
                            ? '📹 Incoming video call'
                            : '📞 Incoming voice call',

                    tag:
                        `chat-call-${callId}`,

                    url:
                        chatNotificationUrl(
                            call.conversation_id
                        )
                }
            );
        }

    } catch (error) {

        console.error(
            'Notification polling failed:',
            error
        );
    }
}


function startNotificationPolling() {

    clearInterval(
        notificationPollTimer
    );

    if (
        !('Notification' in window) ||
        Notification.permission !== 'granted' ||
        !chatNotificationsEnabled()
    ) {
        return;
    }

    notificationPollTimer =
        setInterval(
            pollBrowserNotifications,
            3000
        );
}


notificationButton?.addEventListener(
    'click',
    toggleChatNotifications
);


/*
|--------------------------------------------------------------------------
| INIT
|--------------------------------------------------------------------------
*/

async function init() {

    updateNotificationButton();

    await registerChatServiceWorker();

    if (
        'Notification' in window &&
        Notification.permission === 'granted' &&
        chatNotificationsEnabled()
    ) {
        await primeNotificationState();
        startNotificationPolling();
    }

    await loadPreferences();

    await loadContacts();

    await sendPresence(false);

    await pollIncomingCall();

    clearInterval(incomingCallTimer);
    incomingCallTimer = setInterval(pollIncomingCall, 1500);
}


init();


setInterval(
    () =>
        sendPresence(false),
    15000
);


setInterval(
    loadContacts,
    20000
);



/*
|--------------------------------------------------------------------------
| STATUS / UPDATES
|--------------------------------------------------------------------------
*/

function escapeStatusHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function statusRelativeTime(value) {
    if (!value) return '';

    const date = new Date(String(value).replace(' ', 'T'));
    const diff = Math.max(0, Date.now() - date.getTime());
    const minutes = Math.floor(diff / 60000);

    if (minutes < 1) return 'Just now';
    if (minutes < 60) return `${minutes}m ago`;

    const hours = Math.floor(minutes / 60);

    if (hours < 24) return `${hours}h ago`;

    return date.toLocaleString();
}

function statusAvatarHtml(status) {
    const name = status.full_name || 'User';

    if (status.profile_picture) {
        return `<img src="${escapeStatusHtml(status.profile_picture)}" alt="">`;
    }

    return escapeStatusHtml(
        name.trim().charAt(0).toUpperCase() || 'U'
    );
}

function openChatsSection() {
    chatsTabButton?.classList.add('active');
    updatesTabButton?.classList.remove('active');

    contactsList.style.display = '';
    document.querySelector('.filters')?.style.removeProperty('display');
    updatesPanel?.classList.remove('show');
}

async function openUpdatesSection() {
    updatesTabButton?.classList.add('active');
    chatsTabButton?.classList.remove('active');

    contactsList.style.display = 'none';
    document.querySelector('.filters')?.style.setProperty('display', 'none');
    updatesPanel?.classList.add('show');

    await loadStatusUpdates();
}

function groupStatusesByUser(statuses) {
    const map = new Map();

    statuses.forEach(status => {
        const id = Number(status.user_id || 0);

        if (!map.has(id)) {
            map.set(id, []);
        }

        map.get(id).push(status);
    });

    return [...map.values()].map(group =>
        group.sort(
            (a, b) =>
                new Date(a.created_at).getTime() -
                new Date(b.created_at).getTime()
        )
    );
}

function renderStatusUpdates() {
    if (!statusUsersList) return;

    const myStatuses = statusUpdates
        .filter(item => Number(item.user_id) === Number(currentUserId));

    if (myStatuses.length) {
        const latest = myStatuses
            .slice()
            .sort(
                (a, b) =>
                    new Date(b.created_at).getTime() -
                    new Date(a.created_at).getTime()
            )[0];

        myStatusSub.textContent =
            `${myStatuses.length} active • ${statusRelativeTime(latest.created_at)}`;
    } else {
        myStatusSub.textContent = 'Tap to add a status update';
    }

    const groups = groupStatusesByUser(
        statusUpdates.filter(
            item => Number(item.user_id) !== Number(currentUserId)
        )
    );

    if (!groups.length) {
        statusUsersList.innerHTML =
            '<div class="status-empty">No recent updates.</div>';
        return;
    }

    statusUsersList.innerHTML = groups.map(group => {
        const latest = group[group.length - 1];
        const name = latest.full_name || 'User';

        return `
            <button
                type="button"
                class="status-user-card"
                data-status-user="${Number(latest.user_id)}"
            >
                <div class="status-ring">
                    <div class="status-ring-inner">
                        ${statusAvatarHtml(latest)}
                    </div>
                </div>

                <div class="status-card-meta">
                    <div class="status-card-name">
                        ${escapeStatusHtml(name)}
                    </div>

                    <div class="status-card-sub">
                        ${escapeStatusHtml(statusRelativeTime(latest.created_at))}
                        • ${group.length} update${group.length === 1 ? '' : 's'}
                    </div>
                </div>
            </button>
        `;
    }).join('');

    statusUsersList
        .querySelectorAll('[data-status-user]')
        .forEach(button => {
            button.addEventListener('click', () => {
                openStatusGroup(
                    Number(button.dataset.statusUser)
                );
            });
        });
}

async function loadStatusUpdates() {
    try {
        const data = await jsonFetch(
            API.updates + '?action=list&_=' + Date.now()
        );

        statusUpdates = Array.isArray(data.statuses)
            ? data.statuses
            : [];

        renderStatusUpdates();
    } catch (error) {
        console.error('Status updates:', error);

        if (statusUsersList) {
            statusUsersList.innerHTML =
                '<div class="status-empty">Could not load updates.</div>';
        }
    }
}

function switchStatusCreateMode(mode) {
    statusCreateMode = mode;

    const textMode = mode === 'text';

    statusTextTab?.classList.toggle('active', textMode);
    statusImageTab?.classList.toggle('active', !textMode);

    statusTextCreate.style.display = textMode ? '' : 'none';
    statusImageCreate.style.display = textMode ? 'none' : '';
}

function openStatusCreateModal() {
    switchStatusCreateMode('text');
    statusCreateModal?.classList.add('show');
}

async function publishStatus() {
    publishStatusButton.disabled = true;
    publishStatusButton.textContent = 'Publishing...';

    try {
        const form = new FormData();
        form.append('csrf_token', csrfToken);

        if (statusCreateMode === 'text') {
            const content = statusTextInput.value.trim();

            if (!content) {
                throw new Error('Write your status first.');
            }

            form.append('action', 'create');
            form.append(
                'status_type',
                /^\p{Extended_Pictographic}+$/u.test(content.replace(/\s/g, ''))
                    ? 'emoji'
                    : 'text'
            );
            form.append('text_content', content);
            form.append(
                'background_color',
                statusBackgroundColor.value
            );
            form.append(
                'text_color',
                statusTextColor.value
            );
        } else {
            const file = statusImageInput.files?.[0];

            if (!file) {
                throw new Error('Choose an image first.');
            }

            form.append('action', 'upload');
            form.append('image', file);
            form.append(
                'caption',
                statusCaptionInput.value.trim()
            );
        }

        const data = await jsonFetch(
            API.updates,
            {
                method: 'POST',
                body: form
            }
        );

        statusCreateModal.classList.remove('show');

        statusTextInput.value = '';
        statusCaptionInput.value = '';
        statusImageInput.value = '';

        showToast('Status published.');

        await loadStatusUpdates();

        if (data.status) {
            openStatusGroup(Number(data.status.user_id), Number(data.status.id));
        }
    } catch (error) {
        showToast(error.message || 'Could not publish status.');
    } finally {
        publishStatusButton.disabled = false;
        publishStatusButton.textContent = 'Publish';
    }
}

function openStatusGroup(userId, preferredStatusId = 0) {
    currentStatusGroup = statusUpdates
        .filter(item => Number(item.user_id) === Number(userId))
        .sort(
            (a, b) =>
                new Date(a.created_at).getTime() -
                new Date(b.created_at).getTime()
        );

    if (!currentStatusGroup.length) return;

    currentStatusIndex = preferredStatusId
        ? Math.max(
            0,
            currentStatusGroup.findIndex(
                item => Number(item.id) === Number(preferredStatusId)
            )
        )
        : 0;

    statusViewerModal.classList.add('show');
    renderCurrentStatus();
}

async function markStatusViewed(statusId) {
    try {
        const form = new FormData();

        form.append('action', 'view');
        form.append('status_id', String(statusId));
        form.append('csrf_token', csrfToken);

        await fetch(
            API.updates,
            {
                method: 'POST',
                body: form,
                cache: 'no-store'
            }
        );
    } catch (_) {}
}

function renderCurrentStatus() {
    clearInterval(statusProgressTimer);

    const status = currentStatusGroup[currentStatusIndex];

    if (!status) {
        closeStatusViewerNow();
        return;
    }

    statusViewerName.textContent =
        status.full_name || 'Status';

    statusViewerTime.textContent =
        statusRelativeTime(status.created_at);

    statusViewerAvatar.innerHTML =
        statusAvatarHtml(status);

    statusViewerBody.innerHTML = '';

    if (
        status.status_type === 'image' ||
        status.status_type === 'image_caption'
    ) {
        const image = document.createElement('img');
        image.src = status.media_url || '';
        image.alt = 'Status';
        statusViewerBody.appendChild(image);

        if (status.caption) {
            const caption = document.createElement('div');
            caption.className = 'status-caption';
            caption.textContent = status.caption;
            statusViewerBody.appendChild(caption);
        }
    } else {
        const content = document.createElement('div');
        content.className = 'status-text-content';
        content.style.background =
            status.background_color || '#1d4ed8';
        content.style.color =
            status.text_color || '#ffffff';
        content.textContent =
            status.text_content || '';
        statusViewerBody.appendChild(content);
    }

    statusViewersButton.style.display =
        status.is_mine ? '' : 'none';

    deleteStatusButton.style.display =
        status.is_mine ? '' : 'none';

    markStatusViewed(status.id);

    statusProgressBar.style.transition = 'none';
    statusProgressBar.style.width = '0%';

    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            statusProgressBar.style.transition =
                'width 6s linear';
            statusProgressBar.style.width = '100%';
        });
    });

    statusProgressTimer = setTimeout(() => {
        nextStatus();
    }, 6000);
}

function previousStatus() {
    if (currentStatusIndex > 0) {
        currentStatusIndex--;
        renderCurrentStatus();
    }
}

function nextStatus() {
    if (currentStatusIndex < currentStatusGroup.length - 1) {
        currentStatusIndex++;
        renderCurrentStatus();
        return;
    }

    closeStatusViewerNow();
}

function closeStatusViewerNow() {
    clearTimeout(statusProgressTimer);
    statusViewerModal?.classList.remove('show');
    currentStatusGroup = [];
    currentStatusIndex = 0;
}

async function loadStatusViewers() {
    const status = currentStatusGroup[currentStatusIndex];

    if (!status || !status.is_mine) return;

    try {
        const data = await jsonFetch(
            API.updates +
            '?action=viewers&status_id=' +
            encodeURIComponent(status.id)
        );

        const viewers = Array.isArray(data.viewers)
            ? data.viewers
            : [];

        statusViewersList.innerHTML = viewers.length
            ? viewers.map(viewer => `
                <div class="status-viewer-row">
                    <strong>${escapeStatusHtml(viewer.full_name || 'User')}</strong>
                    <span>${escapeStatusHtml(statusRelativeTime(viewer.viewed_at))}</span>
                </div>
            `).join('')
            : '<div class="status-empty">No views yet.</div>';

        statusViewersModal.classList.add('show');
    } catch (error) {
        showToast(error.message || 'Could not load viewers.');
    }
}

async function deleteCurrentStatus() {
    const status = currentStatusGroup[currentStatusIndex];

    if (!status || !status.is_mine) return;

    if (!confirm('Delete this status?')) return;

    try {
        const form = new FormData();

        form.append('action', 'delete');
        form.append('status_id', String(status.id));
        form.append('csrf_token', csrfToken);

        await jsonFetch(
            API.updates,
            {
                method: 'POST',
                body: form
            }
        );

        closeStatusViewerNow();
        showToast('Status deleted.');
        await loadStatusUpdates();
    } catch (error) {
        showToast(error.message || 'Could not delete status.');
    }
}

chatsTabButton?.addEventListener(
    'click',
    openChatsSection
);

updatesTabButton?.addEventListener(
    'click',
    openUpdatesSection
);

/*
|--------------------------------------------------------------------------
| MY STATUS
|--------------------------------------------------------------------------
| - Clicking My Status opens my active statuses if any exist.
| - Clicking the + button creates a new status.
| - Own status viewer keeps Seen by and Delete controls.
|--------------------------------------------------------------------------
*/
addStatusButton?.addEventListener(
    'click',
    event => {
        if (event.target.closest('.status-add')) {
            event.stopPropagation();
            openStatusCreateModal();
            return;
        }

        const myStatuses = statusUpdates
            .filter(item => Number(item.user_id) === Number(currentUserId))
            .sort(
                (a, b) =>
                    new Date(a.created_at).getTime() -
                    new Date(b.created_at).getTime()
            );

        if (myStatuses.length) {
            openStatusGroup(Number(currentUserId));
        } else {
            openStatusCreateModal();
        }
    }
);

statusTextTab?.addEventListener(
    'click',
    () => switchStatusCreateMode('text')
);

statusImageTab?.addEventListener(
    'click',
    () => switchStatusCreateMode('image')
);

publishStatusButton?.addEventListener(
    'click',
    publishStatus
);

previousStatusButton?.addEventListener(
    'click',
    previousStatus
);

nextStatusButton?.addEventListener(
    'click',
    nextStatus
);

closeStatusViewer?.addEventListener(
    'click',
    closeStatusViewerNow
);

statusViewersButton?.addEventListener(
    'click',
    loadStatusViewers
);

deleteStatusButton?.addEventListener(
    'click',
    deleteCurrentStatus
);





document.addEventListener('DOMContentLoaded', function () {
    if (typeof openChatsSection === 'function') {
        openChatsSection();
    }
});

</script>

</body>
</html>