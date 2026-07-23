import csv
from collections import defaultdict

with open('goal_log_vsoccer.csv', mode='r', encoding='utf-8') as f:
    matches = [m for m in csv.DictReader(f) if m['goals']]

with open('goal_events_vsoccer.csv', mode='r', encoding='utf-8') as f:
    events = list(csv.DictReader(f))

# Let's organize events by match key (datetime + home_team)
match_events = defaultdict(list)
for e in events:
    key = (e['logged_at'], e['home_team'])
    match_events[key].append(e)

processed_matches = []

for m in matches:
    goals_str = m['goals']
    parts = [p.strip() for p in goals_str.split('|')]
    
    h1_goals = []
    h2_goals = []
    for p in parts:
        # e.g. "1H 12' (1-0)"
        half_min = p.split(' ')[0] # 1H
        min_str = p.split(' ')[1].replace("'", "") # 12
        try:
            minute = int(min_str)
        except:
            minute = 0
        if p.startswith('1H'):
            h1_goals.append(minute)
        elif p.startswith('2H'):
            h2_goals.append(minute)
            
    h1_count = len(h1_goals)
    h2_count = len(h2_goals)
    total_goals = h1_count + h2_count
    
    ko_line_raw = m['ko_line']
    try:
        if '/' in ko_line_raw:
            p1, p2 = ko_line_raw.split('/')
            ko_line = (float(p1) + float(p2)) / 2.0
        else:
            ko_line = float(ko_line_raw)
    except:
        ko_line = 0.0

    # Let's extract features:
    # 1. League category (e.g. Champions League, Nations League, France, etc.)
    league = m['league']
    
    # 2. H1 goal count exact (0, 1, 2, 3, 4+)
    # 3. Last goal minute of H1 (e.g. late H1 goal >= 40' vs early H1 goal <= 20')
    last_h1_min = max(h1_goals) if h1_goals else -1
    first_h1_min = min(h1_goals) if h1_goals else 99
    
    # 4. H1 score difference (home - away)
    f_home = int(m['final_home']) if m['final_home'] else 0
    f_away = int(m['final_away']) if m['final_away'] else 0
    
    # Let's check 2H outcome targets:
    # Target A: 2H >= 3 goals
    # Target B: 2H >= 2 goals
    # Target C: 2H > 1H
    # Target D: At least 1 goal in 2H (2H >= 1)
    # Target E: 2H Over 2.5 (>= 3 goals)
    
    processed_matches.append({
        'league': league,
        'h1_count': h1_count,
        'h2_count': h2_count,
        'total_goals': total_goals,
        'ko_line': ko_line,
        'ko_over': float(m['ko_over']) if m['ko_over'] else 0,
        'ko_under': float(m['ko_under']) if m['ko_under'] else 0,
        'last_h1_min': last_h1_min,
        'first_h1_min': first_h1_min,
        'h1_goals': h1_goals,
        'h2_goals': h2_goals
    })

print(f"Total Matches analyzed: {len(processed_matches)}\n")

def test_rule(name, condition_fn, target_fn):
    filtered = [m for m in processed_matches if condition_fn(m)]
    if not filtered:
        return
    success = [m for m in filtered if target_fn(m)]
    win_rate = len(success) / len(filtered) * 100
    if len(filtered) >= 5: # Only consider rules with at least 5 sample matches
        print(f"[{'HIGH ACCURACY (>70%)' if win_rate >= 70 else 'NORMAL'}] {name}")
        print(f"   Sample Size: {len(filtered)} match | Win: {len(success)} | Win Rate: {win_rate:.1f}%\n")

print("=== TESTING COMBINATION RULES FOR 2H GOALS (>70% WIN RATE) ===\n")

# Target 1: 2H >= 2 goals (Minimal 2 Gol di Babak 2)
print("--- TARGET: Babak 2 Minimal 2 Gol (2H Goals >= 2) ---")
test_rule("Rule 1: KO Line >= 6.5 DAN 1H Goals >= 2", 
          lambda m: m['ko_line'] >= 6.5 and m['h1_count'] >= 2,
          lambda m: m['h2_count'] >= 2)

test_rule("Rule 2: KO Line >= 7.0 (Semua Pertandingan KO Line Super Tinggi)", 
          lambda m: m['ko_line'] >= 7.0,
          lambda m: m['h2_count'] >= 2)

test_rule("Rule 3: Babak 1 Ada Gol Cepat (Gol Pertama <= 15') DAN KO Line >= 5.5", 
          lambda m: m['first_h1_min'] <= 15 and m['ko_line'] >= 5.5,
          lambda m: m['h2_count'] >= 2)

test_rule("Rule 4: Babak 1 Gol Pertama Menit 10-30' DAN KO Line >= 6.0", 
          lambda m: 10 <= m['first_h1_min'] <= 30 and m['ko_line'] >= 6.0,
          lambda m: m['h2_count'] >= 2)

print("\n--- TARGET: Babak 2 Minimal 3 Gol / Meledak (2H Goals >= 3) ---")
test_rule("Rule 5: KO Line >= 7.0 DAN 1H Goals <= 3", 
          lambda m: m['ko_line'] >= 7.0 and m['h1_count'] <= 3,
          lambda m: m['h2_count'] >= 3)

test_rule("Rule 6: Babak 1 persis 2 Gol DAN KO Line >= 6.0", 
          lambda m: m['h1_count'] == 2 and m['ko_line'] >= 6.0,
          lambda m: m['h2_count'] >= 3)

test_rule("Rule 7: Babak 1 persis 3 Gol DAN KO Line >= 5.5", 
          lambda m: m['h1_count'] == 3 and m['ko_line'] >= 5.5,
          lambda m: m['h2_count'] >= 3)

print("\n--- TARGET: Ada Gol Menit 75' ke atas (Late Goal 75'+) ---")
test_rule("Rule 8: KO Line >= 5.5 (Ada gol di menit 75'-90'+)", 
          lambda m: m['ko_line'] >= 5.5,
          lambda m: any(g >= 75 for g in m['h2_goals']))

test_rule("Rule 9: Babak 1 ada 2 atau 3 gol (Ada gol di menit 75'-90'+)", 
          lambda m: m['h1_count'] in [2, 3],
          lambda m: any(g >= 75 for g in m['h2_goals']))

test_rule("Rule 10: KO Line >= 6.0 DAN Gol Pertama H1 <= 15' (Ada gol di menit 75'+)", 
          lambda m: m['ko_line'] >= 6.0 and m['first_h1_min'] <= 15,
          lambda m: any(g >= 75 for g in m['h2_goals']))

