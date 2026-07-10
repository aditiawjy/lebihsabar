function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function getMatchTeams(match) {
    return match?.teams || `${match?.homeTeam || 'Unknown'} vs ${match?.awayTeam || 'Unknown'}`;
}

function createMatchKey(match) {
    return JSON.stringify({
        league: match?.league || 'N/A',
        teams: getMatchTeams(match)
    });
}

function parseLocaleFloat(value) {
    return parseFloat(String(value || '').replace(',', '.'));
}

function normalizeTeamName(value) {
    return String(value || '')
        .toLowerCase()
        .replace(/\([^)]*\)/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

function toThresholdNumber(value, fallback = 1.95) {
    const parsed = Number(value);
    return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
}

function parseMatchMinute(status) {
    const m = String(status || '').trim().match(/^(1H|2H)\s+(\d+)'$/i);
    if (!m) return { half: null, min: -1 };
    return { half: m[1].toUpperCase(), min: parseInt(m[2], 10) };
}

function isKickoffMinute(status) {
    return /^1H\s+[01]'$/i.test(String(status || '').trim());
}

function isSecondHalfStart(status) {
    return /^2H\s+[01]'$/i.test(String(status || '').trim());
}

function isSecondHalfStatus(match) {
    const status = String(match?.status || '').trim();
    return /^2H\s+\d+'$/i.test(status);
}

function getShMinute(status) {
    const m = String(status || '').match(/^2H\s+(\d+)'/i);
    return m ? parseInt(m[1], 10) : -1;
}

function delay(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

function normalizeWatchMarketSelection(value, fallback = 'o0.75') {
    const rawValue = String(value || '').trim().replace(',', '.').toLowerCase().replace(/\s+/g, '');
    if (!rawValue) {
        return fallback;
    }

    const normalized = rawValue.startsWith('o') ? rawValue : `o${rawValue}`;
    const match = normalized.match(/\d+(?:\.\d+)?/);
    if (!match) {
        return fallback;
    }

    const numeric = Number(match[0]);
    if (!Number.isFinite(numeric) || numeric <= 0) {
        return fallback;
    }

    return `o${Number(numeric).toString()}`;
}

function formatWatchMarketForDisplay(value) {
    const normalizedSelection = normalizeWatchMarketSelection(value, 'o0.75');
    return `O/U ${normalizedSelection.replace(/^o/, '')}`;
}

function normalizeMarketValue(value) {
    return String(value || '')
        .toLowerCase()
        .replace(/\s+/g, '');
}

function parseSelectionValue(value) {
    const normalized = String(value || '').replace(/,/g, '.').toLowerCase().trim();
    const match = normalized.match(/\d+(?:\.\d+)?/);
    if (!match) {
        return null;
    }

    const numeric = Number(match[0]);
    return Number.isFinite(numeric) ? numeric : null;
}

function isSelectionMatch(label, marketName, wantedSelection) {
    const normalizedLabel = normalizeMarketValue(label);
    const normalizedWanted = normalizeMarketValue(wantedSelection);

    if (!normalizedWanted) {
        return false;
    }

    if (
        normalizedLabel === normalizedWanted
        || normalizedLabel.includes(normalizedWanted)
        || normalizedWanted.includes(normalizedLabel)
    ) {
        return true;
    }

    const wantedValue = parseSelectionValue(normalizedWanted);
    if (wantedValue === null) {
        return false;
    }

    const labelValue = parseSelectionValue(normalizedLabel);
    if (labelValue !== null && labelValue === wantedValue) {
        return true;
    }

    const marketValue = parseSelectionValue(marketName);
    return marketValue !== null && marketValue === wantedValue;
}
