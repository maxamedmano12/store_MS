document.addEventListener('DOMContentLoaded', function () {

    /* =====================================
       MOBILE SIDEBAR
    ===================================== */

    const sidebar =
        document.getElementById('sidebar');

    const sidebarOverlay =
        document.getElementById('sidebarOverlay');

    const sidebarToggle =
        document.getElementById('sidebarToggle');

    const sidebarClose =
        document.getElementById('sidebarClose');


    function openSidebar() {

        if (!sidebar || !sidebarOverlay) {
            return;
        }

        // Open sidebar
        sidebar.classList.remove('-translate-x-full');

        // Show overlay
        sidebarOverlay.classList.remove('hidden');

        document.body.classList.add('overflow-hidden');

        requestAnimationFrame(function () {
            sidebarOverlay.classList.remove('opacity-0');
        });
    }


    function closeSidebar() {

        if (!sidebar || !sidebarOverlay) {
            return;
        }

        // Close sidebar
        sidebar.classList.add('-translate-x-full');

        // Fade overlay
        sidebarOverlay.classList.add('opacity-0');

        document.body.classList.remove('overflow-hidden');

        setTimeout(function () {

            if (sidebar.classList.contains('-translate-x-full')) {
                sidebarOverlay.classList.add('hidden');
            }

        }, 300);
    }


    // Menu button ☰
    sidebarToggle?.addEventListener('click', function (e) {

        e.preventDefault();
        e.stopPropagation();

        openSidebar();
    });


    // X button
    sidebarClose?.addEventListener('click', function (e) {

        e.preventDefault();

        closeSidebar();
    });


    // Click outside sidebar
    sidebarOverlay?.addEventListener('click', function () {

        closeSidebar();
    });


    // Close after clicking sidebar link on mobile
    sidebar?.querySelectorAll('a').forEach(function (link) {

        link.addEventListener('click', function () {

            if (window.innerWidth < 1024) {
                closeSidebar();
            }

        });

    });


    // ESC closes sidebar
    document.addEventListener('keydown', function (e) {

        if (e.key === 'Escape') {

            closeSidebar();

        }

    });


    // Fix when resizing mobile -> desktop
    window.addEventListener('resize', function () {

        if (window.innerWidth >= 1024) {

            sidebarOverlay?.classList.add(
                'hidden',
                'opacity-0'
            );

            document.body.classList.remove(
                'overflow-hidden'
            );

        } else {

            // When returning to mobile,
            // sidebar should start closed.
            sidebar?.classList.add(
                '-translate-x-full'
            );

        }

    });


    /* =====================================
       PROFILE DROPDOWN
    ===================================== */

    const profileButton =
        document.getElementById('profileButton');

    const profileDropdown =
        document.getElementById('profileDropdown');


    profileButton?.addEventListener('click', function (e) {

        e.preventDefault();
        e.stopPropagation();

        // Close notifications first
        notificationDropdown?.classList.add('hidden');

        profileDropdown?.classList.toggle('hidden');

    });


    /* =====================================
       NOTIFICATION DROPDOWN
    ===================================== */

    const notificationButton =
        document.getElementById('notificationButton');

    const notificationDropdown =
        document.getElementById('notificationDropdown');


    notificationButton?.addEventListener('click', function (e) {

        e.preventDefault();
        e.stopPropagation();

        // Close profile first
        profileDropdown?.classList.add('hidden');

        notificationDropdown?.classList.toggle('hidden');

    });


    /* =====================================
       CLOSE DROPDOWNS OUTSIDE
    ===================================== */

    document.addEventListener('click', function (e) {

        if (
            profileDropdown &&
            profileButton &&
            !profileDropdown.contains(e.target) &&
            !profileButton.contains(e.target)
        ) {

            profileDropdown.classList.add('hidden');

        }


        if (
            notificationDropdown &&
            notificationButton &&
            !notificationDropdown.contains(e.target) &&
            !notificationButton.contains(e.target)
        ) {

            notificationDropdown.classList.add('hidden');

        }

    });


    /* =====================================
       DARK MODE
    ===================================== */

    const themeToggle =
        document.getElementById('themeToggle');


    // Restore saved theme
    const savedTheme =
        localStorage.getItem('system-theme');


    if (savedTheme === 'dark') {

        document.documentElement.classList.add('dark');

    } else if (savedTheme === 'light') {

        document.documentElement.classList.remove('dark');

    }


    themeToggle?.addEventListener('click', function (e) {

        e.preventDefault();

        document.documentElement.classList.toggle('dark');


        if (
            document.documentElement.classList.contains('dark')
        ) {

            localStorage.setItem(
                'system-theme',
                'dark'
            );

        } else {

            localStorage.setItem(
                'system-theme',
                'light'
            );

        }

    });


    /* =====================================
       LUCIDE ICONS
    ===================================== */

    if (typeof lucide !== 'undefined') {

        lucide.createIcons();

    }

});