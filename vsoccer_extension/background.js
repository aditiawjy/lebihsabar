/*
 * Background service worker — satu-satunya tempat fetch ke http://localhost,
 * supaya lolos pembatasan mixed-content (halaman 1x2aaa = HTTPS).
 * Menerima pesan dari content.js lalu POST ke goal-log-save-vsoccer.php.
 */
const ENDPOINT = 'http://localhost/lebihsabar/goal-log-save-vsoccer.php';

chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
  if (!msg || msg.type !== 'vsoccer') return;
  fetch(ENDPOINT, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(msg.payload),
  })
    .then(r => r.json())
    .then(data => sendResponse({ ok: true, data }))
    .catch(err => sendResponse({ ok: false, error: String(err) }));
  return true; // async sendResponse
});
