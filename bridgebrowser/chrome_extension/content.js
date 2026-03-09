// Content script for Live Data Browser Extension
// This script runs on every webpage

(function() {
    'use strict';
    
    // Flag to prevent multiple initializations
    if (window.liveDataBrowserInitialized) {
        return;
    }
    window.liveDataBrowserInitialized = true;
    
    console.log('Live Data Browser Extension content script loaded');
    
    // Extract specific betting data
    function extractBettingData() {
        const bettingData = [];
        
        // Get text content from main document and iframes
        let textContent = document.body ? document.body.innerText : '';
        
        // Also check iframes for content
        const iframes = document.querySelectorAll('iframe');
        iframes.forEach(iframe => {
            try {
                if (iframe.contentDocument && iframe.contentDocument.body) {
                    textContent += '\n' + iframe.contentDocument.body.innerText;
                }
            } catch (e) {
                // Cross-origin iframe, skip
            }
        });
        
        console.log('Extracting betting data from text length:', textContent.length);
        
        // Parse live betting data from text content
        if (textContent.includes('Live') || textContent.includes('vs') || textContent.includes('odds')) {
            const lines = textContent.split('\n').map(line => line.trim()).filter(line => line);
            
            let currentMatch = null;
            let currentLeague = '';
            
            for (let i = 0; i < lines.length; i++) {
                const line = lines[i];
                
                // Detect league names
                if (line.includes('LEAGUE') || line.includes('CUP') || line.includes('CHAMPIONS') || line.includes('BUNDESLIGA') || line.includes('SERIE A') || line.includes('LA LIGA') || line.includes('PREMIER')) {
                    currentLeague = line;
                    continue;
                }
                
                // Detect match status (time patterns like "2H 5'", "1H 4'", "Live", etc.)
                if (/^\d+H\s+\d+'$/.test(line) || line === 'Live' || /^\d+H\s*\d+'?$/.test(line) || /^\d+H\s+\d+$/.test(line) || /^\d+'$/.test(line)) {
                    console.log('Found match status:', line);
                    if (currentMatch) {
                        bettingData.push(currentMatch);
                    }
                    
                    currentMatch = {
                        league: currentLeague,
                        teams: '',
                        status: line,
                        time: line,
                        scores: [],
                        betTypes: [],
                        odds: {},
                        lastUpdated: new Date().toLocaleTimeString(),
                        liveTimestamp: new Date().toISOString()
                    };
                    
                    // Look for team names and scores in surrounding lines
                    let j = Math.max(0, i - 5);
                    while (j < lines.length && j < i + 15) {
                        const nextLine = lines[j];
                        
                        // Pattern for team names with "vs" or team names with scores
                        if (nextLine.includes('vs') || (nextLine.match(/\w+/) && j !== i)) {
                            // Check if this looks like a team matchup
                            if (nextLine.includes('vs')) {
                                currentMatch.teams = nextLine;
                            } else if (!currentMatch.teams && nextLine.length > 3 && nextLine.length < 50) {
                                // Look for team names in nearby lines
                                const teamPattern = /([A-Za-z\s]+)\s+(\d+)\s*-\s*(\d+)\s+([A-Za-z\s]+)/;
                                const match = nextLine.match(teamPattern);
                                if (match) {
                                    currentMatch.teams = `${match[1].trim()} vs ${match[4].trim()}`;
                                    currentMatch.scores = [match[2], match[3]];
                                }
                            }
                        }
                        
                        // Look for odds patterns
                        if (/\d+\.\d+/.test(nextLine) && nextLine.length < 20) {
                            const odds = nextLine.match(/\d+\.\d+/g);
                            if (odds && odds.length > 0) {
                                if (odds.length >= 3) {
                                    currentMatch.odds = {
                                        home: odds[0],
                                        draw: odds[1],
                                        away: odds[2]
                                    };
                                } else if (odds.length === 2) {
                                    currentMatch.odds = {
                                        over: odds[0],
                                        under: odds[1]
                                    };
                                }
                            }
                        }
                        
                        // Look for score patterns
                        if (/\d+\s*-\s*\d+/.test(nextLine) && !currentMatch.scores.length) {
                            const scoreMatch = nextLine.match(/(\d+)\s*-\s*(\d+)/);
                            if (scoreMatch) {
                                currentMatch.scores = [scoreMatch[1], scoreMatch[2]];
                            }
                        }
                        
                        j++;
                    }
                }
                
                // Look for specific betting markets
                if (line.includes('Over') || line.includes('Under') || line.includes('Goals')) {
                    if (currentMatch) {
                        currentMatch.betTypes.push({
                            type: 'Goals',
                            market: line,
                            timestamp: new Date().toISOString()
                        });
                    }
                }
            }
            
            // Add the last match if exists
            if (currentMatch) {
                bettingData.push(currentMatch);
            }
        }
        
        // Extract data from structured elements (tables, divs with specific classes)
        const matchElements = document.querySelectorAll('[class*="match"], [class*="game"], [class*="event"], [class*="fixture"]');
        matchElements.forEach(element => {
            try {
                const elementText = element.innerText;
                if (elementText.includes('vs') || elementText.includes('Live') || /\d+H/.test(elementText)) {
                    const match = {
                        source: 'DOM_element',
                        content: elementText,
                        className: element.className,
                        timestamp: new Date().toISOString()
                    };
                    
                    // Try to extract structured data
                    const timeElement = element.querySelector('[class*="time"], [class*="status"], [class*="live"]');
                    if (timeElement) {
                        match.time = timeElement.innerText;
                    }
                    
                    const teamElements = element.querySelectorAll('[class*="team"], [class*="participant"]');
                    if (teamElements.length >= 2) {
                        match.teams = `${teamElements[0].innerText} vs ${teamElements[1].innerText}`;
                    }
                    
                    const scoreElements = element.querySelectorAll('[class*="score"]');
                    if (scoreElements.length >= 2) {
                        match.scores = [scoreElements[0].innerText, scoreElements[1].innerText];
                    }
                    
                    const oddsElements = element.querySelectorAll('[class*="odd"], [class*="price"]');
                    if (oddsElements.length > 0) {
                        match.odds = {};
                        oddsElements.forEach((odd, index) => {
                            match.odds[`option_${index}`] = odd.innerText;
                        });
                    }
                    
                    bettingData.push(match);
                }
            } catch (e) {
                console.error('Error processing match element:', e);
            }
        });
        
        console.log('Extracted betting data:', bettingData.length, 'matches');
        return bettingData;
    }
    
    // Extract general page data
    function extractPageData() {
        try {
            const pageData = {
                title: document.title,
                url: window.location.href,
                text: document.body ? document.body.innerText.substring(0, 10000) : '', // Limit text size
                timestamp: new Date().toISOString(),
                domain: window.location.hostname
            };
            
            // Extract betting data
            const bettingData = extractBettingData();
            if (bettingData && bettingData.length > 0) {
                pageData.bettingData = bettingData;
                pageData.hasBettingData = true;
            } else {
                pageData.hasBettingData = false;
            }
            
            // Extract meta information
            const metaData = {};
            const metaTags = document.querySelectorAll('meta');
            metaTags.forEach(meta => {
                const name = meta.getAttribute('name') || meta.getAttribute('property');
                const content = meta.getAttribute('content');
                if (name && content) {
                    metaData[name] = content;
                }
            });
            pageData.meta = metaData;
            
            // Check if this is a betting/sports site
            const bettingKeywords = ['bet', 'odds', 'sport', 'football', 'soccer', 'live', 'match', 'game'];
            const isBettingSite = bettingKeywords.some(keyword => 
                document.title.toLowerCase().includes(keyword) || 
                window.location.hostname.toLowerCase().includes(keyword)
            );
            pageData.isBettingSite = isBettingSite;
            
            return pageData;
        } catch (error) {
            console.error('Error extracting page data:', error);
            return {
                title: document.title || 'Unknown',
                url: window.location.href,
                error: error.message,
                timestamp: new Date().toISOString()
            };
        }
    }
    
    // Listen for messages from background script
    chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
        if (request.action === 'extractData') {
            try {
                const data = extractPageData();
                sendResponse({ success: true, data: data });
            } catch (error) {
                sendResponse({ success: false, error: error.message });
            }
        }
        
        if (request.action === 'extractBettingData') {
            try {
                const bettingData = extractBettingData();
                sendResponse({ success: true, data: bettingData });
            } catch (error) {
                sendResponse({ success: false, error: error.message });
            }
        }
        
        if (request.action === 'getPageInfo') {
            try {
                const info = {
                    title: document.title,
                    url: window.location.href,
                    domain: window.location.hostname,
                    timestamp: new Date().toISOString()
                };
                sendResponse({ success: true, data: info });
            } catch (error) {
                sendResponse({ success: false, error: error.message });
            }
        }
        
        return true; // Indicate async response
    });
    
    // Auto-detect and report betting data changes
    let lastBettingDataHash = '';
    
    function checkForUpdates() {
        try {
            const bettingData = extractBettingData();
            const currentHash = JSON.stringify(bettingData).length; // Simple hash
            
            if (currentHash !== lastBettingDataHash && bettingData.length > 0) {
                lastBettingDataHash = currentHash;
                
                // Send update to background script
                chrome.runtime.sendMessage({
                    action: 'bettingDataUpdate',
                    data: {
                        url: window.location.href,
                        timestamp: new Date().toISOString(),
                        bettingData: bettingData
                    }
                }).catch(error => {
                    console.log('Background script not available:', error);
                });
            }
        } catch (error) {
            console.error('Error checking for updates:', error);
        }
    }
    
    // Check for updates periodically if this looks like a betting site
    const bettingKeywords = ['bet', 'odds', 'sport', 'live'];
    const isBettingSite = bettingKeywords.some(keyword => 
        document.title.toLowerCase().includes(keyword) || 
        window.location.hostname.toLowerCase().includes(keyword)
    );
    
    if (isBettingSite) {
        // Initial check
        setTimeout(checkForUpdates, 2000);
        
        // Periodic checks
        setInterval(checkForUpdates, 10000); // Every 10 seconds
        
        // Listen for DOM changes
        const observer = new MutationObserver((mutations) => {
            let shouldCheck = false;
            mutations.forEach((mutation) => {
                if (mutation.type === 'childList' || mutation.type === 'characterData') {
                    shouldCheck = true;
                }
            });
            
            if (shouldCheck) {
                setTimeout(checkForUpdates, 1000); // Debounce
            }
        });
        
        observer.observe(document.body, {
            childList: true,
            subtree: true,
            characterData: true
        });
        
        console.log('Live Data Browser Extension: Monitoring betting site for changes');
    }
    
    // Send initial page load notification
    setTimeout(() => {
        chrome.runtime.sendMessage({
            action: 'pageLoaded',
            data: {
                url: window.location.href,
                title: document.title,
                isBettingSite: isBettingSite,
                timestamp: new Date().toISOString()
            }
        }).catch(error => {
            console.log('Background script not available:', error);
        });
    }, 1000);
    
})();