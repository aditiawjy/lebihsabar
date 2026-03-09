# Parser Page Sport Broadcast Redesign - Implementation Plan

**Goal:** Redesign parser-content.php with Sport Broadcast visual style, add validation engine, and implement dashboard metrics.

**Visual Direction:** Sport Broadcast theme - sharp contrast, operational data-first, status-driven color coding (emerald=valid, amber=warning, rose=invalid, blue=primary action).

---

## Task 1: Create check_matches_health.php Endpoint

**Files to Create:**
- `check_matches_health.php`

**Step 1:** Create the endpoint that reads matches.csv and returns health metrics

```php
<?php
// check_matches_health.php - Read-only health check endpoint
```

**Step 2:** Implement duplicate detection with 4-component key (time|home|away|league)

**Step 3:** Return JSON with: duplicateCount, duplicateIndexes, dailyMetrics

---

## Task 2: Update parser-content.php - Layout Structure

**Files to Modify:**
- `parser-content.php`

**Step 1:** Restructure HTML with Sport Broadcast layout
- Top status bar (server time + data feed indicator)
- Daily health cards row (5 cards)
- Two-column workbench (input left, validation right)
- Results zone with status rails

**Step 2:** Update CSS styles
- Sport Broadcast color palette
- Card layouts with status rails
- Typography hierarchy
- Animation classes

---

## Task 3: Implement Validation Engine (Client-side)

**Files to Modify:**
- `parser-content.php`

**Step 1:** Add validation functions
- `validateMatch(match, idx)` - validates single match
- `validateBatch(matches)` - validates all matches

**Step 2:** Rules implementation:
- match_time must be valid datetime
- home_team, away_team, league required
- home_team !== away_team
- Scores must be null or integer >= 0
- Warning: FT exists but FH empty

**Step 3:** Visual feedback system
- Status badges per match card
- Validation summary panel
- Color-coded status rails

---

## Task 4: Integrate Duplicate Detection

**Files to Modify:**
- `parser-content.php`

**Step 1:** Call check_matches_health.php before save

**Step 2:** Display duplicate indicators:
- Badge "DUPLICATE (SKIP)" on duplicate cards
- Count in summary panel
- Visual distinction (muted styling)

---

## Task 5: Add Dashboard Health Cards

**Files to Modify:**
- `parser-content.php`

**Step 1:** Create 5 metric cards:
1. Total match hari ini
2. League aktif hari ini
3. Pending score (FT kosong)
4. Data invalid (current session)
5. Duplicate (current session vs CSV)

**Step 2:** Fetch on page load and update after parse

---

## Task 6: Update Save Logic

**Files to Modify:**
- `save_matches_csv.php`

**Step 1:** Change duplicate key to 4-component: `time|home|away|league`

**Step 2:** Enrich response with duplicate details

**Step 3:** Ensure timezone consistency (Asia/Jakarta)

---

## Task 7: Testing & Verification

**Step 1:** Test scenarios:
- Valid data → save success
- Missing league → invalid, save disabled
- Negative score → invalid
- Home = Away → invalid
- Duplicate (same time/home/away/league) → detected, skipped
- Same time/home/away but different league → NOT duplicate

**Step 2:** Syntax checks:
- php -l parser-content.php
- php -l save_matches_csv.php
- php -l check_matches_health.php

---

**Success Criteria:**
- Parser page has Sport Broadcast visual style
- Validation works client-side with clear feedback
- Duplicate detection uses 4-component key
- Dashboard shows 5 health metrics
- Save only works when no invalid data
- All manual test scenarios pass
