import csv

with open('goal_log_vsoccer.csv', mode='r', encoding='utf-8') as f:
    matches = [m for m in csv.DictReader(f) if m['goals']]

processed_matches = []

for m in matches:
    goals_str = m['goals']
    parts = [p.strip() for p in goals_str.split('|')]
    
    h1_goals = []
    h2_goals = []
    for p in parts:
        half_min = p.split(' ')[0]
        min_str = p.split(' ')[1].replace("'", "")
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

    processed_matches.append({
        'match': f"{m['home_team']} vs {m['away_team']}",
        'league': m['league'],
        'h1_count': h1_count,
        'h2_count': h2_count,
        'total_goals': total_goals,
        'ko_line': ko_line,
        'ko_line_raw': ko_line_raw,
        'first_h1_min': min(h1_goals) if h1_goals else 99,
        'last_h1_min': max(h1_goals) if h1_goals else -1,
        'h2_goals': h2_goals
    })

def print_result(title, filtered, target_fn, target_desc):
    if not filtered:
        return
    win = [m for m in filtered if target_fn(m)]
    rate = len(win) / len(filtered) * 100
    print(f"==================================================")
    print(f"[POLA ACCURACY HIGH] {title}")
    print(f"==================================================")
    print(f" Target: {target_desc}")
    print(f" Sample Size: {len(filtered)} pertandingan")
    print(f" Berhasil (WIN): {len(win)} match")
    print(f" WIN RATE: {rate:.1f}%")
    print(f"--------------------------------------------------")
    print(f" Contoh Match Berhasil:")
    for m in win[:4]:
        print(f"  • {m['league']} | {m['match']} -> 1H: {m['h1_count']} gol | 2H: {m['h2_count']} gol (KO: {m['ko_line_raw']})")
    print("\n")

# Pattern 1: Babak 1 persis 3 gol & KO Line >= 5.5 -> 2H Meledak >= 3 gol
p1 = [m for m in processed_matches if m['h1_count'] == 3 and m['ko_line'] >= 5.5]
print_result("POLA 1: Babak 1 Meledak 3 Gol (1H = 3 Gol) + KO Line >= 5.5",
             p1,
             lambda m: m['h2_count'] >= 3,
             "Babak 2 Meledak Minimal 3 Gol Lagi (2H >= 3)")

# Pattern 2: KO Line Super Tinggi (>= 7.0) -> Babak 2 Minim 2 Gol
p2 = [m for m in processed_matches if m['ko_line'] >= 7.0]
print_result("POLA 2: KO Line Super Tinggi (KO Line >= 7.0)",
             p2,
             lambda m: m['h2_count'] >= 2,
             "Babak 2 Minimal Terjadi 2 Gol (2H >= 2)")

# Pattern 3: Babak 1 Berakhir 2 atau 3 Gol -> Menit 75'-90'+ PASTI ADA GOL (Late Goal)
p3 = [m for m in processed_matches if m['h1_count'] in [2, 3]]
print_result("POLA 3: Babak 1 Terjadi 2 atau 3 Gol (1H = 2 atau 3 Gol)",
             p3,
             lambda m: any(g >= 75 for g in m['h2_goals']),
             "Pasti Ada Gol di Menit 75' ke Atas (Late Goal 75'+)")

# Pattern 4: Liga Paling Banyak 2H Goal (Women's Nations League & Champions League)
p4 = [m for m in processed_matches if ("Women" in m['league'] or "Champions League" in m['league']) and m['ko_line'] >= 5.5]
print_result("POLA 4: Liga 'Champions League' / 'Women League' + KO Line >= 5.5",
             p4,
             lambda m: m['h2_count'] >= 2,
             "Babak 2 Minimal 2 Gol (2H >= 2)")

