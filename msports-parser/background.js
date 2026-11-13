// Background Service Worker untuk handle API calls
// Ini bypass CORS karena berjalan di context extension, bukan web page

const API_ENDPOINT = 'http://127.0.0.1/sabaraja/api_msports_sync.php';

// Helper: Async wrapper untuk fetch dengan timeout
async function syncData(data) {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 120000); // 120s timeout untuk remote DB

    try {
        const response = await fetch(API_ENDPOINT, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data),
            signal: controller.signal
        });

        clearTimeout(timeoutId);

        const contentType = response.headers.get('content-type') || '';
        const bodyText = await response.text();
        console.log('📥 Background: API response status:', response.status);

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${bodyText.slice(0, 200)}`);
        }

        if (contentType.includes('application/json')) {
            return JSON.parse(bodyText);
        }

        try {
            return JSON.parse(bodyText);
        } catch (err) {
            throw new Error(`Non-JSON response: ${bodyText.slice(0, 200)}`);
        }
    } catch (error) {
        clearTimeout(timeoutId);
        if (error.name === 'AbortError') {
            throw new Error('Request timeout (120s)');
        }
        throw error;
    }
}

chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
    if (request.action === 'syncLeague16Results') {
        if (!sender.url || new URL(sender.url).hostname !== 'msports.m88m8idtop.com') {
            sendResponse({success:false,error:'Unexpected Results source'});
            return false;
        }
        const league = 'SABA INTERNATIONAL FRIENDLY Virtual FC 24 - 16 Mins Play';
        const matches = Array.isArray(request.matches) ? request.matches.filter(m => m.league === league) : [];
        if (!matches.length) { sendResponse({success:false,error:'No completed League 16 results'}); return false; }
        (async () => {
            try {
                const response = await fetch('http://127.0.0.1/lebihsabar/save_matches_csv.php', {
                    method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({matches})
                });
                const result = await response.json();
                if (!response.ok || !result.success) throw new Error(result.message || 'CSV sync failed');
                sendResponse({success:true,result});
            } catch (error) { sendResponse({success:false,error:error.message}); }
        })();
        return true;
    }
    if (request.action === 'syncToDatabase') {
        console.log('📤 Background: Received sync request with', request.data?.length || 0, 'leagues');

        // Gunakan async IIFE untuk handling yang lebih bersih
        (async () => {
            try {
                const result = await syncData(request.data);
                console.log('✅ Background SYNC SUCCESS:', result);
                sendResponse({ success: true, result: result });
            } catch (error) {
                console.error('❌ Background SYNC ERROR:', error);
                sendResponse({ success: false, error: error.message || 'Fetch failed' });
            }
        })();

        // Return true to indicate async response
        return true;
    }

    // Untuk action lain yang tidak dikenali
    return false;
});
