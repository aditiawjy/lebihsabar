<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="10"> <!-- Auto-refresh setiap 10 detik -->
    <title>Antigravity Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-900 text-slate-100 min-h-screen p-8">

    <div class="max-w-4xl mx-auto">
        <header class="mb-10 flex items-center justify-between">
            <div>
                <h1 class="text-3xl font-bold bg-clip-text text-transparent bg-gradient-to-r from-blue-400 to-purple-500">
                    Antigravity Manager
                </h1>
                <p class="text-slate-400 mt-2">Real-time Quota Monitoring (Family Sharing Pool)</p>
            </div>
            <div class="text-right">
                <div class="text-sm text-slate-500">Status System</div>
                <div class="text-emerald-400 font-mono font-bold">ONLINE ●</div>
            </div>
        </header>

        <?php
        require 'Manager.php';
        $manager = new AntigravityManager();
        $config = $manager->getConfig();
        $source = $config['source'] ?? 'Manual Configuration';
        $usageData = json_decode(file_get_contents('usage.json'), true) ?? [];
        $today = date('Y-m-d');
        ?>

        <div class="mb-6 flex items-center gap-2 text-sm text-slate-400">
            <span>Data Source:</span>
            <span class="px-2 py-1 rounded bg-slate-800 border border-slate-700 text-<?php echo ($source === 'Manual Configuration') ? 'yellow' : 'emerald'; ?>-400 font-mono">
                <?php echo $source; ?>
            </span>
            <?php if ($source === 'Manual Configuration'): ?>
                <span class="text-xs text-slate-600">(Login via 'opencode auth login' to sync real accounts)</span>
            <?php endif; ?>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($config['accounts'] as $acc): 
                $used = $usageData[$acc['id']][$today] ?? 0;
                $limit = $acc['daily_limit'];
                $percent = ($used / $limit) * 100;
                $color = $percent > 90 ? 'bg-red-500' : ($percent > 50 ? 'bg-yellow-500' : 'bg-blue-500');
            ?>
            
            <!-- Account Card -->
            <div class="bg-slate-800 rounded-2xl p-6 border border-slate-700 relative overflow-hidden group hover:border-blue-500/50 transition-all">
                <div class="absolute top-0 right-0 p-4 opacity-10 group-hover:opacity-20 transition-opacity">
                    <svg class="w-24 h-24" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg>
                </div>

                <div class="relative z-10">
                    <div class="flex justify-between items-start mb-4">
                        <div>
                            <span class="text-xs font-bold px-2 py-1 rounded bg-slate-700 text-slate-300 uppercase tracking-wider mb-2 inline-block">
                                <?php echo $acc['provider']; ?>
                            </span>
                            <h3 class="font-bold text-lg truncate w-48" title="<?php echo $acc['email']; ?>">
                                <?php echo $acc['email']; ?>
                            </h3>
                            <div class="mt-1 text-sm text-slate-400 font-mono">
                                <?php echo $acc['model'] ?? ($acc['tier'] == 'flash' ? 'Gemini 3 Flash' : 'Gemini 3 Pro'); ?>
                            </div>
                        </div>
                        <div class="text-right">
                            <span class="text-xs text-slate-400">Tier</span>
                            <div class="font-bold <?php echo ($acc['tier'] == 'flash') ? 'text-yellow-400' : 'text-blue-400'; ?>">
                                <?php echo strtoupper($acc['tier']); ?>
                            </div>
                        </div>
                    </div>

                    <!-- Progress Bar -->
                    <div class="mb-2 flex justify-between text-sm">
                        <span class="text-slate-400">Usage</span>
                        <span class="font-mono text-white"><?php echo $used; ?> / <?php echo $limit; ?></span>
                    </div>
                    <div class="h-3 bg-slate-900 rounded-full overflow-hidden">
                        <div class="<?php echo $color; ?> h-full transition-all duration-500" style="width: <?php echo $percent; ?>%"></div>
                    </div>
                    
                    <div class="mt-4 pt-4 border-t border-slate-700/50 flex justify-between items-center">
                        <span class="text-xs text-slate-500">Status</span>
                        <?php if ($percent >= 100): ?>
                            <span class="text-xs font-bold text-red-400 flex items-center gap-1">
                                ● DEPLETED
                            </span>
                        <?php else: ?>
                            <span class="text-xs font-bold text-emerald-400 flex items-center gap-1">
                                ● READY
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Simulation Box -->
        <div class="mt-12 bg-slate-800 rounded-2xl p-8 border border-slate-700">
            <h2 class="text-xl font-bold mb-4">Simulasi Koneksi Opencode</h2>
            <form action="" method="POST" class="flex gap-4">
                <input type="text" name="prompt" placeholder="Ketik prompt test di sini..." class="flex-1 bg-slate-900 border border-slate-600 rounded-xl px-4 py-3 text-white focus:border-blue-500 outline-none">
                <button type="submit" name="test" class="bg-blue-600 hover:bg-blue-500 text-white font-bold px-8 py-3 rounded-xl transition-all">
                    Test Request
                </button>
            </form>

            <?php
            if (isset($_POST['test'])) {
                echo '<div class="mt-6 p-4 bg-slate-900 rounded-xl border border-slate-600 font-mono text-sm">';
                echo '<div class="text-slate-400 mb-2">> Connecting to Antigravity Pool...</div>';
                
                $result = $manager->chat($_POST['prompt']);
                
                if ($result['status'] === 'success') {
                    echo '<div class="text-emerald-400 mb-1">✔ Success!</div>';
                    echo '<div class="text-blue-300 mb-2">Account Used: ' . $result['account_used'] . '</div>';
                    echo '<div class="text-white border-l-2 border-slate-500 pl-3">' . $result['data'] . '</div>';
                } else {
                    echo '<div class="text-red-500">✘ Error: ' . $result['message'] . '</div>';
                }
                echo '</div>';
                
                // Refresh page to update charts
                echo '<meta http-equiv="refresh" content="2">';
            }
            ?>
        </div>
    </div>

</body>
</html>
