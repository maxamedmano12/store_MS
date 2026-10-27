<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$globalCallCsrfToken = $_SESSION['csrf_token'];
?>

<!-- SUPER ADMIN GLOBAL INCOMING CALL POPUP -->
<div
    id="globalIncomingCallPopup"
    class="fixed right-4 top-4 z-[99999] hidden w-[min(390px,calc(100vw-2rem))] overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-2xl"
>
    <div class="h-1.5 bg-gradient-to-r from-blue-500 via-indigo-500 to-violet-500"></div>

    <div class="p-4">
        <div class="flex items-start gap-3">
            <div
                id="globalCallAvatar"
                class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-blue-600 text-sm font-black text-white"
            >
                U
            </div>

            <div class="min-w-0 flex-1">
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p
                            id="globalCallName"
                            class="truncate text-sm font-extrabold text-slate-900"
                        >
                            Incoming Call
                        </p>

                        <p
                            id="globalCallType"
                            class="mt-0.5 text-[11px] font-medium text-slate-500"
                        >
                            Incoming voice call...
                        </p>
                    </div>

                    <span class="relative flex h-3 w-3 shrink-0">
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex h-3 w-3 rounded-full bg-emerald-500"></span>
                    </span>
                </div>

                <div class="mt-3 flex items-center gap-2">
                    <button
                        id="globalCallDeclineButton"
                        type="button"
                        class="inline-flex flex-1 items-center justify-center gap-2 rounded-xl bg-red-50 px-3 py-2.5 text-[11px] font-extrabold text-red-600 transition hover:bg-red-100"
                    >
                        <span>✕</span>
                        Decline
                    </button>

                    <button
                        id="globalCallOpenButton"
                        type="button"
                        class="inline-flex flex-1 items-center justify-center gap-2 rounded-xl bg-emerald-600 px-3 py-2.5 text-[11px] font-extrabold text-white shadow-lg transition hover:bg-emerald-700"
                    >
                        <span id="globalCallOpenIcon">📞</span>
                        Open Call
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<footer
    class="
        mt-8 flex flex-col
        gap-2 border-t border-slate-200
        px-5 py-5 text-[11px]
        text-slate-500
        sm:flex-row
        sm:items-center
        sm:justify-between
        md:px-7
    "
>
    <p>
        © <?= date('Y') ?> Business Management System
    </p>

    <p>
        Version 1.0.0
    </p>
</footer>

<script>
(function () {
    const currentPage = (window.location.pathname.split('/').pop() || '').toLowerCase();

    // chat.php already has the complete call screen.
    if (currentPage === 'chat.php') {
        return;
    }

    const callsApi = '../chat/api/calls.php';
    const csrfToken = <?= json_encode($globalCallCsrfToken) ?>;

    const popup = document.getElementById('globalIncomingCallPopup');
    const nameEl = document.getElementById('globalCallName');
    const typeEl = document.getElementById('globalCallType');
    const avatarEl = document.getElementById('globalCallAvatar');
    const declineButton = document.getElementById('globalCallDeclineButton');
    const openButton = document.getElementById('globalCallOpenButton');
    const openIcon = document.getElementById('globalCallOpenIcon');

    if (!popup) return;

    let currentIncomingCall = null;
    let ringtoneAudioContext = null;
    let ringtoneTimer = null;
    let ringtonePlaying = false;

    function initialsFromName(name) {
        const parts = String(name || 'User')
            .trim()
            .split(/\s+/)
            .filter(Boolean);

        if (!parts.length) return 'U';

        return parts.slice(0, 2)
            .map(part => part.charAt(0).toUpperCase())
            .join('');
    }

    function getAudioContext() {
        const AudioContextClass =
            window.AudioContext ||
            window.webkitAudioContext;

        if (!AudioContextClass) return null;

        if (!ringtoneAudioContext) {
            ringtoneAudioContext = new AudioContextClass();
        }

        return ringtoneAudioContext;
    }

    async function unlockAudio() {
        const context = getAudioContext();

        if (context && context.state === 'suspended') {
            try {
                await context.resume();
            } catch (_) {}
        }
    }

    function playRingPulse() {
        const context = getAudioContext();

        if (!context || context.state !== 'running') return;

        const now = context.currentTime;

        [
            { time: 0.00, frequency: 740, duration: 0.22 },
            { time: 0.27, frequency: 880, duration: 0.28 }
        ].forEach(note => {
            const oscillator = context.createOscillator();
            const gain = context.createGain();

            oscillator.type = 'sine';
            oscillator.frequency.setValueAtTime(
                note.frequency,
                now + note.time
            );

            gain.gain.setValueAtTime(
                0.0001,
                now + note.time
            );

            gain.gain.exponentialRampToValueAtTime(
                0.16,
                now + note.time + 0.025
            );

            gain.gain.exponentialRampToValueAtTime(
                0.0001,
                now + note.time + note.duration
            );

            oscillator.connect(gain);
            gain.connect(context.destination);

            oscillator.start(now + note.time);
            oscillator.stop(
                now + note.time + note.duration + 0.04
            );
        });
    }

    async function startRingtone() {
        if (ringtonePlaying) return;

        ringtonePlaying = true;

        await unlockAudio();

        if (!ringtonePlaying) return;

        playRingPulse();

        clearInterval(ringtoneTimer);

        ringtoneTimer = setInterval(() => {
            if (ringtonePlaying) {
                playRingPulse();
            }
        }, 1500);
    }

    function stopRingtone() {
        ringtonePlaying = false;
        clearInterval(ringtoneTimer);
        ringtoneTimer = null;
    }

    ['pointerdown', 'touchstart', 'keydown'].forEach(eventName => {
        document.addEventListener(
            eventName,
            unlockAudio,
            { once: true, passive: true }
        );
    });

    function showPopup(call) {
        currentIncomingCall = call;

        const callerName =
            call.caller_name ||
            call.other_user_name ||
            'Incoming Call';

        const isVideo = call.call_type === 'video';

        nameEl.textContent = callerName;
        typeEl.textContent = isVideo
            ? 'Incoming video call...'
            : 'Incoming voice call...';

        avatarEl.textContent = initialsFromName(callerName);
        openIcon.textContent = isVideo ? '📹' : '📞';

        popup.classList.remove('hidden');
        startRingtone();
    }

    function hidePopup() {
        stopRingtone();
        popup.classList.add('hidden');
        currentIncomingCall = null;
    }

    async function fetchJson(url, options = {}) {
        const response = await fetch(url, {
            credentials: 'same-origin',
            cache: 'no-store',
            ...options
        });

        let data;

        try {
            data = await response.json();
        } catch (_) {
            throw new Error('Invalid server response.');
        }

        if (!response.ok || data?.ok === false) {
            throw new Error(
                data?.message ||
                data?.error ||
                'Request failed.'
            );
        }

        return data;
    }

    async function pollIncomingCall() {
        try {
            const data = await fetchJson(
                callsApi + '?action=incoming&_=' + Date.now()
            );

            if (data.call) {
                const newId = Number(data.call.id || 0);
                const oldId = Number(currentIncomingCall?.id || 0);

                if (!currentIncomingCall || newId !== oldId) {
                    showPopup(data.call);
                }

                return;
            }

            if (currentIncomingCall) {
                hidePopup();
            }
        } catch (error) {
            console.error('Super Admin global call:', error);
        }
    }

    async function declineIncomingCall() {
        if (!currentIncomingCall) return;

        const callId = Number(currentIncomingCall.id || 0);

        if (!callId) {
            hidePopup();
            return;
        }

        declineButton.disabled = true;
        openButton.disabled = true;

        try {
            const form = new FormData();

            form.append('action', 'decline');
            form.append('call_id', String(callId));
            form.append('csrf_token', csrfToken);

            await fetchJson(callsApi, {
                method: 'POST',
                body: form
            });
        } catch (error) {
            console.error('Decline call:', error);
        } finally {
            hidePopup();
            declineButton.disabled = false;
            openButton.disabled = false;
        }
    }

    function openIncomingCall() {
        if (!currentIncomingCall) return;

        stopRingtone();

        const callId = Number(currentIncomingCall.id || 0);
        const conversationId =
            Number(currentIncomingCall.conversation_id || 0);

        const params = new URLSearchParams();

        if (conversationId) {
            params.set(
                'conversation_id',
                String(conversationId)
            );
        }

        if (callId) {
            params.set(
                'incoming_call',
                String(callId)
            );
        }

        window.location.href =
            'chat.php' +
            (params.toString() ? '?' + params.toString() : '');
    }

    declineButton.addEventListener(
        'click',
        declineIncomingCall
    );

    openButton.addEventListener(
        'click',
        openIncomingCall
    );

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            pollIncomingCall();
        }
    });

    window.addEventListener(
        'beforeunload',
        stopRingtone
    );

    pollIncomingCall();

    setInterval(
        pollIncomingCall,
        1500
    );
})();
</script>
