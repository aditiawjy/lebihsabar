// Live Data Browser Extension - Direct Browser Access + HTTP Server Fallback

let liveUpdateInterval = null;
let isLiveRunning = false;
let currentTab = null;
let serverData = [];

document.addEventListener('DOMContentLoaded', function() {
    // Get current tab
    chrome.tabs.query({active: true, currentWindow: true}, function(tabs) {
        currentTab = tabs[0];
        updateTabInfo();
    });
    
    // Direct browser access buttons
    document.getElementById('extractBtn').addEventListener('click', extractPageData);
    document.getElementById('sendToServerBtn').addEventListener('click', sendDataToServer);
    document.getElementById('getServerDataBtn').addEventListener('click', getServerData);
    document.getElementById('clearServerBtn').addEventListener('click', clearServerData);
    
    // HTTP server access (fallback)
    document.getElementById('httpAccess').addEventListener('click', tryHttpAccess);
    document.getElementById('refreshBtn').addEventListener('click', tryHttpAccess);
    
    // Live controls
    document.getElementById('startLiveBtn').addEventListener('click', startLiveUpdates);
    document.getElementById('stopLiveBtn').addEventListener('click', stopLiveUpdates);
    
    // Load from file
    document.getElementById('loadFromFileBtn').addEventListener('click', loadFromFile);
    
    // Auto-refresh toggle
    document.getElementById('autoRefreshToggle').addEventListener('change', toggleAutoRefresh);
    
    // Load data immediately
    extractPageData();
    
    // Update UI status
    updateLiveStatus();
    updateServerStatus();
});

// Update tab info
function updateTabInfo() {
    if (currentTab && currentTab.url && currentTab.title) {
        document.getElementById('currentUrl').textContent = currentTab.url;
        document.getElementById('currentTitle').textContent = currentTab.title;
    } else {
        document.getElementById('currentUrl').textContent = 'No URL available';
        document.getElementById('currentTitle').textContent = 'No title available';
    }
}

// Extract page data from current tab
async function extractPageData() {
    if (!currentTab) return;
    
    const statusEl = document.getElementById('extractStatus');
    const dataEl = document.getElementById('extractedData');
    
    statusEl.textContent = 'Mengekstrak data dari halaman...';
    statusEl.className = 'status';
    
    try {
        // Send message to content script via background
        const response = await chrome.runtime.sendMessage({
            action: 'getPageContent',
            tabId: currentTab.id
        });
        
        if (response && response.success && response.data) {
            statusEl.textContent = '[SUCCESS] Data berhasil diekstrak!';
            statusEl.className = 'status success';
            
            const data = response.data;
            
            // Display extracted data
            let displayText = `URL: ${data.url || 'Unknown'}\nTitle: ${data.title || 'Unknown'}\nTimestamp: ${data.timestamp || 'Unknown'}\n`;
            
            if (data.hasBettingData && data.bettingData) {
                displayText += `\nBetting Data Found: ${data.bettingData.length} matches\n`;
                data.bettingData.forEach((match, index) => {
                    displayText += `\nMatch ${index + 1}:\n`;
                    displayText += `  League: ${match.league || 'Unknown'}\n`;
                    displayText += `  Teams: ${match.teams || 'Unknown'}\n`;
                    displayText += `  Status: ${match.status || 'Unknown'}\n`;
                    displayText += `  Scores: ${match.scores ? match.scores.join('-') : 'N/A'}\n`;
                    if (match.odds && Object.keys(match.odds).length > 0) {
                        displayText += `  Odds: ${JSON.stringify(match.odds)}\n`;
                    }
                });
            } else {
                displayText += '\nNo betting data found on this page.';
            }
            
            dataEl.textContent = displayText;
            
            // Store data for potential server sending
            window.lastExtractedData = data;
            
        } else {
            throw new Error(response ? response.error : 'No response from content script');
        }
    } catch (error) {
        statusEl.textContent = `[ERROR] Gagal: ${error.message}`;
        statusEl.className = 'status error';
        dataEl.textContent = 'Pastikan halaman web telah dimuat dan ekstensi memiliki akses.';
    }
}

// Send data to server
async function sendDataToServer() {
    if (!window.lastExtractedData) {
        alert('Tidak ada data untuk dikirim. Ekstrak data terlebih dahulu.');
        return;
    }
    
    const statusEl = document.getElementById('serverStatus');
    statusEl.textContent = 'Mengirim data ke server...';
    statusEl.className = 'status';
    
    try {
        const response = await chrome.runtime.sendMessage({
            action: 'sendToServer',
            data: window.lastExtractedData
        });
        
        if (response && response.success) {
            statusEl.textContent = '[SUCCESS] Data berhasil dikirim ke server!';
            statusEl.className = 'status success';
        } else {
            throw new Error(response ? response.error : 'Gagal mengirim data');
        }
    } catch (error) {
        statusEl.textContent = `[ERROR] Gagal: ${error.message}`;
        statusEl.className = 'status error';
    }
}

// Get server data
async function getServerData() {
    const statusEl = document.getElementById('serverStatus');
    const dataEl = document.getElementById('serverData');
    
    statusEl.textContent = 'Mengambil data dari server...';
    statusEl.className = 'status';
    
    try {
        const response = await chrome.runtime.sendMessage({
            action: 'getServerData'
        });
        
        if (response && response.success) {
            statusEl.textContent = '[SUCCESS] Data server berhasil diambil!';
            statusEl.className = 'status success';
            
            serverData = response.data || [];
            
            if (serverData.length > 0) {
                let displayText = `Total data: ${serverData.length} entries\n\n`;
                serverData.slice(0, 5).forEach((item, index) => {
                    displayText += `${index + 1}. ${item.title || 'No title'} (${item.timestamp})\n`;
                });
                if (serverData.length > 5) {
                    displayText += `... dan ${serverData.length - 5} data lainnya`;
                }
                dataEl.textContent = displayText;
            } else {
                dataEl.textContent = 'Tidak ada data di server.';
            }
        } else {
            throw new Error(response ? response.error : 'Gagal mengambil data server');
        }
    } catch (error) {
        statusEl.textContent = `[ERROR] Gagal: ${error.message}`;
        statusEl.className = 'status error';
        dataEl.textContent = 'Pastikan server Python berjalan di localhost:5000';
    }
}

// Clear server data
async function clearServerData() {
    const statusEl = document.getElementById('serverStatus');
    
    statusEl.textContent = 'Menghapus data server...';
    statusEl.className = 'status';
    
    try {
        const response = await chrome.runtime.sendMessage({
            action: 'clearServerData'
        });
        
        if (response && response.success) {
            statusEl.textContent = '[SUCCESS] Data server berhasil dihapus!';
            statusEl.className = 'status success';
            document.getElementById('serverData').textContent = 'Data server telah dihapus.';
            serverData = [];
        } else {
            throw new Error(response ? response.error : 'Gagal menghapus data server');
        }
    } catch (error) {
        statusEl.textContent = `[ERROR] Gagal: ${error.message}`;
        statusEl.className = 'status error';
    }
}

// Load from file
function loadFromFile() {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = '.json';
    
    input.onchange = function(event) {
        const file = event.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                try {
                    const data = JSON.parse(e.target.result);
                    document.getElementById('extractedData').textContent = JSON.stringify(data, null, 2);
                    window.lastExtractedData = data;
                    
                    const statusEl = document.getElementById('extractStatus');
                    statusEl.textContent = '[SUCCESS] File berhasil dimuat!';
                    statusEl.className = 'status success';
                } catch (error) {
                    alert('Error parsing JSON file: ' + error.message);
                }
            };
            reader.readAsText(file);
        }
    };
    
    input.click();
}

// Toggle auto refresh
function toggleAutoRefresh() {
    const toggle = document.getElementById('autoRefreshToggle');
    if (toggle.checked) {
        startLiveUpdates();
    } else {
        stopLiveUpdates();
    }
}

// Update server status
function updateServerStatus() {
    chrome.runtime.sendMessage({action: 'checkServerStatus'}).then(response => {
        const statusEl = document.getElementById('serverConnectionStatus');
        if (response && response.success) {
            statusEl.textContent = 'Server Connected';
            statusEl.className = 'status success';
        } else {
            statusEl.textContent = 'Server Disconnected';
            statusEl.className = 'status error';
        }
    }).catch(() => {
        const statusEl = document.getElementById('serverConnectionStatus');
        statusEl.textContent = 'Server Disconnected';
        statusEl.className = 'status error';
    });
}

// Start live updates function
function startLiveUpdates() {
    if (isLiveRunning) return;
    
    isLiveRunning = true;
    updateLiveStatus();
    
    // Clear any existing interval
    if (liveUpdateInterval) {
        clearInterval(liveUpdateInterval);
    }
    
    // Enable live mode via background script
    chrome.runtime.sendMessage({action: 'enableLiveMode'});
    
    // Start real-time updates every 5 seconds
    liveUpdateInterval = setInterval(() => {
        extractPageData();
        if (window.lastExtractedData && window.lastExtractedData.hasBettingData) {
            sendDataToServer();
        }
    }, 5000);
    
    // Load data immediately when starting
    extractPageData();
}

// Stop live updates function
function stopLiveUpdates() {
    if (!isLiveRunning) return;
    
    isLiveRunning = false;
    updateLiveStatus();
    
    // Disable live mode via background script
    chrome.runtime.sendMessage({action: 'disableLiveMode'});
    
    // Clear the interval
    if (liveUpdateInterval) {
        clearInterval(liveUpdateInterval);
        liveUpdateInterval = null;
    }
}

// Update live status indicator
function updateLiveStatus() {
    const statusEl = document.getElementById('liveStatus');
    const startBtn = document.getElementById('startLiveBtn');
    const stopBtn = document.getElementById('stopLiveBtn');
    
    if (isLiveRunning) {
        statusEl.textContent = 'RUNNING';
        statusEl.className = 'live-status live-running';
        startBtn.disabled = true;
        stopBtn.disabled = false;
    } else {
        statusEl.textContent = 'STOPPED';
        statusEl.className = 'live-status live-stopped';
        startBtn.disabled = false;
        stopBtn.disabled = true;
    }
}

// HTTP server access function (fallback method)
async function tryHttpAccess() {
    const statusEl = document.getElementById('httpStatus');
    const dataEl = document.getElementById('httpData');
    
    statusEl.textContent = 'Mengakses data via HTTP Server...';
    statusEl.className = 'status';
    
    try {
        // Coba akses API Python terlebih dahulu
        const apiResponse = await fetch('http://localhost:5000/api/live-data?t=' + Date.now());
        
        if (apiResponse.ok) {
            const data = await apiResponse.json();
            statusEl.textContent = '[SUCCESS] Berhasil akses via API Server!';
            statusEl.className = 'status success';
            
            // Format data untuk tampilan yang lebih baik
            if (data.live_matches && data.prematch_matches) {
                const summary = `Live: ${data.live_matches.length} matches, Prematch: ${data.prematch_matches.length} matches`;
                dataEl.innerHTML = `<strong>${summary}</strong><br><pre>${JSON.stringify(data, null, 2)}</pre>`;
            } else {
                dataEl.textContent = JSON.stringify(data, null, 2);
            }
        } else {
            // Fallback ke akses langsung HTTP
            const httpPath = 'http://localhost/bridgebrowser/live_data.json';
            const directResponse = await fetch(httpPath + '?t=' + Date.now());
            
            if (directResponse.ok) {
                const data = await directResponse.json();
                statusEl.textContent = '[SUCCESS] Berhasil akses via HTTP (fallback)!';
                statusEl.className = 'status success';
                
                // Format data untuk tampilan yang lebih baik
                if (data.live_matches && data.prematch_matches) {
                    const summary = `Live: ${data.live_matches.length} matches, Prematch: ${data.prematch_matches.length} matches`;
                    dataEl.innerHTML = `<strong>${summary}</strong><br><pre>${JSON.stringify(data, null, 2)}</pre>`;
                } else {
                    dataEl.textContent = JSON.stringify(data, null, 2);
                }
            } else {
                throw new Error(`HTTP ${directResponse.status}: ${directResponse.statusText}`);
            }
        }
    } catch (error) {
        statusEl.textContent = `[ERROR] Gagal: ${error.message}`;
        statusEl.className = 'status error';
        dataEl.textContent = 'Pastikan API Server (port 5000) atau XAMPP Apache berjalan';
    }
}

// Listen for messages from background script
chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
    if (request.action === 'updateUI') {
        // Update UI with new data
        if (request.data) {
            const dataEl = document.getElementById('extractedData');
            if (dataEl) {
                dataEl.textContent = JSON.stringify(request.data, null, 2);
            }
        }
    }
    
    if (request.action === 'liveDataUpdate') {
        // Handle live data updates
        const statusEl = document.getElementById('extractStatus');
        if (statusEl) {
            statusEl.textContent = '[INFO] Live update received';
            statusEl.className = 'status info';
        }
        
        // Auto-extract if live mode is enabled
        if (isLiveRunning) {
            setTimeout(extractPageData, 1000);
        }
    }
    
    return true;
});