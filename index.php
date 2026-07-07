<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

$pages = [
    'parser' => [
        'title' => 'Parsing Data',
        'nav' => 'Parsing Data',
        'include' => 'parser-content.php',
        'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
    ],
    'league-over15' => [
        'title' => 'Statistik League Over 1.5',
        'nav' => 'League Over 1.5',
        'include' => 'league-over15.php',
        'icon' => 'M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z',
    ],
    'streak' => [
        'title' => 'Streak Under 1.5',
        'nav' => 'Streak U1.5',
        'include' => 'streak-analysis.php',
        'icon' => 'M13 7h8m0 0v8m0-8l-8 8-4-4-6 6',
    ],
];

$requestedPage = $_GET['page'] ?? 'parser';
$page = array_key_exists($requestedPage, $pages) ? $requestedPage : 'parser';

$navLinkBaseClass = 'group flex items-center px-4 py-3.5 rounded-xl transition-all duration-200';
$navLinkActiveClass = 'bg-blue-600 text-white shadow-lg shadow-blue-600/20';
$navLinkIdleClass = 'text-slate-400 hover:bg-slate-800 hover:text-white';

$navIconBaseClass = 'w-5 h-5 mr-3';
$navIconActiveClass = 'text-white';
$navIconIdleClass = 'text-slate-500 group-hover:text-blue-400';

?>
<!DOCTYPE html>
<html lang="id" class="no-js">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars('Sabaraja - ' . $pages[$page]['title'], ENT_QUOTES, 'UTF-8'); ?></title>
    <script>
        document.documentElement.classList.remove('no-js');
    </script>
    <link rel="stylesheet" href="assets/app.css?v=<?php echo filemtime(__DIR__ . '/assets/app.css'); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; }

        /* Custom Scrollbar */
        .scrollbar-thin::-webkit-scrollbar {
            width: 4px;
        }
        .scrollbar-thin::-webkit-scrollbar-track {
            background: transparent;
        }
        .scrollbar-thin::-webkit-scrollbar-thumb {
            background: rgba(51, 65, 85, 0.5);
            border-radius: 4px;
        }
        .scrollbar-thin::-webkit-scrollbar-thumb:hover {
            background: rgba(51, 65, 85, 0.7);
        }

        /* Page Transition Animation */
        .page-transition {
            animation: fadeInUp 0.3s ease-out;
        }
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Smooth scroll */
        html {
            scroll-behavior: smooth;
        }

        /* Focus visible for keyboard navigation */
        *:focus-visible {
            outline: 2px solid #3b82f6;
            outline-offset: 2px;
        }

        @media (max-width: 1023px) {
            .no-js #appShell {
                display: block;
                overflow: visible;
            }

            .no-js #mobileMenuBtn,
            .no-js #sidebarOverlay {
                display: none !important;
            }

            .no-js #sidebar {
                position: static;
                inset: auto;
                width: auto;
                transform: none !important;
            }
        }
    </style>
</head>
<body class="bg-slate-50 text-slate-900">
    <div id="appShell" class="flex min-h-screen lg:h-screen overflow-hidden">
        <!-- Mobile Menu Button -->
        <button id="mobileMenuBtn" type="button" aria-label="Toggle navigation menu" aria-controls="sidebar" aria-expanded="false" 
            class="lg:hidden fixed top-4 left-4 z-50 p-3 bg-slate-900 text-white rounded-xl shadow-lg transition-all duration-300 hover:shadow-xl hover:scale-105 active:scale-95 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
            <!-- Hamburger Icon -->
            <svg id="hamburgerIcon" class="w-6 h-6 transition-all duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
            <!-- Close Icon -->
            <svg id="closeIcon" class="w-6 h-6 hidden transition-all duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>

        <!-- Sidebar Overlay -->
        <div id="sidebarOverlay" class="lg:hidden fixed inset-0 bg-black/60 backdrop-blur-sm z-30 hidden transition-opacity duration-300 opacity-0"></div>

        <!-- Sidebar -->
        <aside id="sidebar" class="fixed lg:static inset-y-0 left-0 z-40 w-72 bg-slate-900 text-white flex flex-col shadow-2xl transform -translate-x-full lg:translate-x-0 transition-transform duration-300 ease-in-out">
            <div class="p-8">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-blue-600 rounded-xl flex items-center justify-center shadow-lg shadow-blue-500/30">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                    </div>
                    <div>
                        <h1 class="text-xl font-bold tracking-tight">SABARAJA</h1>
                        <p class="text-slate-400 text-xs font-medium uppercase tracking-widest">Dashboard</p>
                    </div>
                </div>
            </div>
            
            <nav class="flex-1 px-4 space-y-1 overflow-y-auto scrollbar-thin scrollbar-thumb-slate-700 scrollbar-track-transparent">
                <?php foreach ($pages as $key => $config): ?>
                    <?php $isActive = $page === $key; ?>
                    <a
                        href="index.php?page=<?php echo urlencode($key); ?>"
                        aria-current="<?php echo $isActive ? 'page' : 'false'; ?>"
                        class="<?php echo $navLinkBaseClass; ?> <?php echo $isActive ? $navLinkActiveClass : $navLinkIdleClass; ?> focus:outline-none focus:ring-2 focus:ring-blue-500/50"
                    >
                        <svg class="<?php echo $navIconBaseClass; ?> <?php echo $isActive ? $navIconActiveClass : $navIconIdleClass; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?php echo htmlspecialchars($config['icon'], ENT_QUOTES, 'UTF-8'); ?>"/>
                        </svg>
                        <span class="font-medium"><?php echo htmlspecialchars($config['nav'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php if ($isActive): ?>
                            <span class="ml-auto w-1.5 h-1.5 rounded-full bg-white"></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="p-6 border-t border-slate-800">
                <div class="bg-slate-800/50 rounded-2xl p-4">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-slate-700 flex items-center justify-center">
                            <span class="text-xs font-bold text-slate-300">AD</span>
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-white leading-tight">Admin Sabaraja</p>
                            <p class="text-[10px] text-slate-500 font-medium uppercase">Management</p>
                        </div>
                    </div>
                </div>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 flex flex-col min-w-0 bg-slate-50">
            <!-- Top Navbar -->
            <header class="h-20 bg-white/80 backdrop-blur-md border-b border-slate-200 flex items-center justify-between pl-20 pr-4 lg:px-8 sticky top-0 z-10">
                <nav aria-label="Breadcrumb" class="flex min-w-0 items-center gap-2">
                    <ol class="flex min-w-0 items-center gap-2" itemscope itemtype="https://schema.org/BreadcrumbList">
                        <li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem" class="hidden lg:flex items-center gap-2">
                            <span itemprop="name" class="text-slate-400 text-sm font-medium">Dashboard</span>
                            <meta itemprop="position" content="1">
                            <svg class="w-4 h-4 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                        </li>
                        <li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem" class="min-w-0">
                            <span itemprop="name" class="block truncate text-slate-900 text-base font-semibold">
                                <?php echo htmlspecialchars($pages[$page]['title'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <meta itemprop="position" content="2">
                        </li>
                    </ol>
                </nav>
                
            </header>

            <nav aria-label="Primary" class="lg:hidden border-b border-slate-200 bg-white/95 px-3 py-2">
                <div class="flex gap-2 overflow-x-auto scrollbar-thin">
                    <?php foreach ($pages as $key => $config): ?>
                        <?php $isActive = $page === $key; ?>
                        <a
                            href="index.php?page=<?php echo urlencode($key); ?>"
                            class="shrink-0 rounded-full border px-3 py-2 text-xs font-semibold transition-colors <?php echo $isActive ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-slate-50 text-slate-600 hover:bg-slate-100'; ?>"
                            aria-current="<?php echo $isActive ? 'page' : 'false'; ?>"
                        >
                            <?php echo htmlspecialchars($config['nav'], ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </nav>

            <div class="flex-1 overflow-auto relative" id="mainContent">
                <!-- Page Loading Indicator (content area only) -->
                <div id="pageLoader" class="hidden absolute inset-0 bg-white/50 backdrop-blur-sm z-20 flex items-center justify-center">
                    <div class="flex flex-col items-center gap-3">
                        <svg class="animate-spin w-8 h-8 text-blue-600" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
                        </svg>
                        <span class="text-sm font-medium text-slate-600">Memuat...</span>
                    </div>
                </div>
                <div class="max-w-7xl mx-auto">
                    <div class="page-transition">
                        <?php
                        $includePath = __DIR__ . DIRECTORY_SEPARATOR . $pages[$page]['include'];
                        if (is_file($includePath)) {
                            define('SABARAJA_APP', true);
                            if (ob_get_level() > 0) {
                                @ob_flush();
                            }
                            @flush();
                            require $includePath;
                        } else {
                            http_response_code(500);
                            echo '<div class="m-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-700">Halaman tidak tersedia.</div>';
                        }
                        ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

<!-- Mobile Sidebar Script -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const hamburgerIcon = document.getElementById('hamburgerIcon');
    const closeIcon = document.getElementById('closeIcon');
    const desktopBreakpoint = 1024;

    if (mobileMenuBtn && sidebar && sidebarOverlay && hamburgerIcon && closeIcon) {
        function setMenuState(isOpen) {
            if (isOpen) {
                sidebarOverlay.classList.remove('hidden');
                setTimeout(() => sidebarOverlay.classList.remove('opacity-0'), 10);
            } else {
                sidebarOverlay.classList.add('opacity-0');
                setTimeout(() => sidebarOverlay.classList.add('hidden'), 300);
            }

            sidebar.classList.toggle('-translate-x-full', !isOpen);
            hamburgerIcon.classList.toggle('hidden', isOpen);
            closeIcon.classList.toggle('hidden', !isOpen);
            mobileMenuBtn.classList.toggle('bg-red-600', isOpen);
            mobileMenuBtn.classList.toggle('bg-slate-900', !isOpen);
            mobileMenuBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            document.body.style.overflow = isOpen ? 'hidden' : '';
        }

        function closeSidebar() {
            setMenuState(false);
        }

        function handleResize() {
            if (window.innerWidth >= desktopBreakpoint) {
                sidebar.classList.remove('-translate-x-full');
                sidebarOverlay.classList.add('hidden');
                hamburgerIcon.classList.remove('hidden');
                closeIcon.classList.add('hidden');
                mobileMenuBtn.classList.remove('bg-red-600');
                mobileMenuBtn.classList.add('bg-slate-900');
                mobileMenuBtn.setAttribute('aria-expanded', 'false');
                document.body.style.overflow = '';
                return;
            }

            closeSidebar();
        }

        mobileMenuBtn.addEventListener('click', function () {
            const isOpen = sidebar.classList.contains('-translate-x-full');
            setMenuState(isOpen);
        });

        sidebarOverlay.addEventListener('click', closeSidebar);

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && window.innerWidth < desktopBreakpoint) {
                closeSidebar();
            }
        });

        const sidebarLinks = sidebar.querySelectorAll('a');
        sidebarLinks.forEach(function (link) {
            link.addEventListener('click', function () {
                if (window.innerWidth < desktopBreakpoint) {
                    closeSidebar();
                }
            });
        });

        window.addEventListener('resize', handleResize);
        handleResize();
    }

    const pageLoader = document.getElementById('pageLoader');
    const navLinks = document.querySelectorAll('nav a[href^="index.php?page="]');
    navLinks.forEach(function(link) {
        link.addEventListener('click', function(e) {
            const currentPage = new URLSearchParams(window.location.search).get('page') || 'parser';
            const targetPage = new URLSearchParams(this.href.split('?')[1]).get('page');
            
            if (currentPage !== targetPage && pageLoader) {
                pageLoader.classList.remove('hidden');
            }
        });
    });

    window.addEventListener('load', function() {
        if (pageLoader) {
            pageLoader.classList.add('hidden');
        }
    });
});
</script>

</body>
</html>
