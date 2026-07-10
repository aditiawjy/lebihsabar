// Background script for Live Data Browser Extension

// Live update state management
let isLiveMode = false;
let liveUpdateInterval = null;
let currentTabId = null;

// Restore state in case the service worker was restarted
chrome.storage.local.get(['isLiveMode', 'currentTabId'], (res) => {
  if (res.isLiveMode && res.currentTabId) {
    isLiveMode = true;
    currentTabId = res.currentTabId;
    console.log('Service worker restored live state for tab', currentTabId);
  }
});

// Alarm listener to perform live updates even when service worker wakes up
chrome.alarms.onAlarm.addListener((alarm) => {
  if (alarm.name !== 'liveUpdate') return;
  // Fetch latest state from storage to survive worker restarts
  chrome.storage.local.get(['isLiveMode', 'currentTabId'], (res) => {
    if (res.isLiveMode && res.currentTabId) {
      isLiveMode = true;
      currentTabId = res.currentTabId;
      updateLiveData();
    }
  });
});

// Listen for messages from content scripts and popup
chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
  if (request.action === 'sendToServer') {
    // Send data to Python server
    sendDataToServer(request.data)
      .then(response => {
        sendResponse({ success: true, data: response });
      })
      .catch(error => {
        console.error('Error sending to server:', error);
        sendResponse({ success: false, error: error.message });
      });
    
    return true; // Indicate async response
  }
  
  if (request.action === 'getPageContent') {
    // Get content from active tab
    getPageContent()
      .then(content => {
        sendResponse({ success: true, content: content });
      })
      .catch(error => {
        console.error('Error getting page content:', error);
        sendResponse({ success: false, error: error.message });
      });
    
    return true;
  }
  
  if (request.action === 'toggleLiveMode') {
    toggleLiveMode(request.tabId);
    sendResponse({ success: true, isLiveMode: isLiveMode });
    return true;
  }
  
  if (request.action === 'getLiveStatus') {
    sendResponse({ success: true, isLiveMode: isLiveMode });
    return true;
  }
  
  if (request.action === 'checkServerStatus') {
    checkServerStatus()
      .then(status => {
        sendResponse({ success: true, status: status });
      })
      .catch(error => {
        sendResponse({ success: false, error: error.message });
      });
    return true;
  }
});

// Function to send data to Python server
async function sendDataToServer(data) {
  try {
    const response = await fetch('http://127.0.0.1:5000/api/live-data', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(data)
    });
    
    if (!response.ok) {
      throw new Error(`HTTP error! status: ${response.status}`);
    }
    
    return await response.json();
  } catch (error) {
    console.error('Server communication error:', error);
    throw error;
  }
}

// Function to check server status
async function checkServerStatus() {
  try {
    const response = await fetch('http://127.0.0.1:5000/api/status', {
      method: 'GET',
      headers: {
        'Content-Type': 'application/json',
      }
    });
    
    if (response.ok) {
      const data = await response.json();
      return {
        online: true,
        data: data
      };
    } else {
      return {
        online: false,
        error: `Server returned ${response.status}`
      };
    }
  } catch (error) {
    return {
      online: false,
      error: error.message
    };
  }
}

// Function to get page content from active tab
async function getPageContent() {
  try {
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    
    if (!tab) {
      throw new Error('No active tab found');
    }
    
    // Check if we can inject scripts into this tab
    if (tab.url.startsWith('chrome://') || tab.url.startsWith('chrome-extension://') || tab.url.startsWith('edge://') || tab.url.startsWith('about:')) {
      throw new Error('Cannot access browser internal pages');
    }
    
    // Inject content script to extract data
    const results = await chrome.scripting.executeScript({
      target: { tabId: tab.id },
      function: extractPageContent
    });
    
    if (results && results[0] && results[0].result) {
      return {
        ...results[0].result,
        tabId: tab.id,
        timestamp: new Date().toISOString()
      };
    } else {
      throw new Error('Failed to extract page content');
    }
  } catch (error) {
    console.error('Error in getPageContent:', error);
    throw error;
  }
}

// Function to extract page content (injected into page)
function extractPageContent() {
  try {
    // Extract basic page information
    const pageData = {
      title: document.title,
      url: window.location.href,
      text: document.body ? document.body.innerText : '',
      html: document.documentElement.outerHTML,
      timestamp: new Date().toISOString()
    };
    
    // Extract betting data if available
    const bettingData = extractBettingData();
    if (bettingData && bettingData.length > 0) {
      pageData.bettingData = bettingData;
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
    
    // Extract links
    const links = [];
    const linkElements = document.querySelectorAll('a[href]');
    linkElements.forEach(link => {
      links.push({
        text: link.textContent.trim(),
        href: link.href,
        title: link.title || ''
      });
    });
    pageData.links = links.slice(0, 50); // Limit to first 50 links
    
    return pageData;
  } catch (error) {
    console.error('Error extracting page content:', error);
    return {
      title: document.title || 'Unknown',
      url: window.location.href,
      error: error.message,
      timestamp: new Date().toISOString()
    };
  }
}

// Function to extract betting data from page
function extractBettingData() {
  const bettingData = [];
  
  try {
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
    
    // Look for betting patterns in text
    if (textContent.includes('vs') || textContent.includes('Live') || textContent.includes('odds')) {
      const lines = textContent.split('\n').map(line => line.trim()).filter(line => line);
      
      // Simple pattern matching for betting data
      for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        
        // Look for match patterns
        if (line.includes('vs') && (line.includes('Live') || /\d+[H']/.test(line))) {
          const match = {
            text: line,
            type: 'match',
            timestamp: new Date().toISOString()
          };
          
          // Look for odds in nearby lines
          for (let j = Math.max(0, i - 2); j < Math.min(lines.length, i + 3); j++) {
            if (/\d+\.\d+/.test(lines[j])) {
              match.odds = lines[j];
              break;
            }
          }
          
          bettingData.push(match);
        }
      }
    }
  } catch (error) {
    console.error('Error extracting betting data:', error);
  }
  
  return bettingData;
}

// Function to toggle live mode
function toggleLiveMode(tabId) {
  if (isLiveMode) {
    // Stop live mode
    isLiveMode = false;
    currentTabId = null;
    chrome.alarms.clear('liveUpdate');
    chrome.storage.local.set({ isLiveMode: false, currentTabId: null });
    console.log('Live mode stopped');
  } else {
    // Start live mode
    isLiveMode = true;
    currentTabId = tabId;
    chrome.alarms.create('liveUpdate', { periodInMinutes: 0.1 }); // Every 6 seconds
    chrome.storage.local.set({ isLiveMode: true, currentTabId: tabId });
    console.log('Live mode started for tab', tabId);
  }
}

// Function to update live data
async function updateLiveData() {
  if (!isLiveMode || !currentTabId) return;
  
  try {
    const content = await getPageContentFromTab(currentTabId);
    if (content) {
      // Send to server
      await sendDataToServer(content);
      console.log('Live data updated');
    }
  } catch (error) {
    console.error('Error updating live data:', error);
  }
}

// Function to get page content from specific tab
async function getPageContentFromTab(tabId) {
  try {
    const tab = await chrome.tabs.get(tabId);
    
    if (!tab) {
      throw new Error('Tab not found');
    }
    
    // Check if we can inject scripts into this tab
    if (tab.url.startsWith('chrome://') || tab.url.startsWith('chrome-extension://')) {
      throw new Error('Cannot access browser internal pages');
    }
    
    const results = await chrome.scripting.executeScript({
      target: { tabId: tabId },
      function: extractPageContent
    });
    
    if (results && results[0] && results[0].result) {
      return {
        ...results[0].result,
        tabId: tabId,
        timestamp: new Date().toISOString()
      };
    }
    
    return null;
  } catch (error) {
    console.error('Error getting content from tab:', error);
    return null;
  }
}

// Extension lifecycle events
chrome.runtime.onInstalled.addListener(() => {
  console.log('Live Data Browser Extension installed');
});

chrome.tabs.onUpdated.addListener((tabId, changeInfo, tab) => {
  if (changeInfo.status === 'complete' && isLiveMode && tabId === currentTabId) {
    updateLiveData();
  }
});

chrome.tabs.onActivated.addListener((activeInfo) => {
  if (isLiveMode && activeInfo.tabId !== currentTabId) {
    // Tab changed, update current tab
    currentTabId = activeInfo.tabId;
    chrome.storage.local.set({ currentTabId: activeInfo.tabId });
  }
});

chrome.tabs.onRemoved.addListener((tabId) => {
  if (isLiveMode && tabId === currentTabId) {
    // Current live tab was closed, stop live mode
    toggleLiveMode(null);
  }
});