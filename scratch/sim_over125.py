import csv

with open('goal_log_vsoccer.csv', mode='r', encoding='utf-8') as f:
    matches = [m for m in csv.DictReader(f) if m['goals']]

target_matches = []
for m in matches:
    parts = [p.strip() for p in m['goals'].split('|')]
    h1 = []
    h2 = []
    for p in parts:
        h = p.split(' ')[0]
        mn = int(p.split(' ')[1].replace("'", ""))
        if h == '1H': h1.append(mn)
        elif h == '2H': h2.append(mn)
        
    c1 = len(h1)
    if c1 in [2, 3]:
        h2_before_70 = [g for g in h2 if g < 70]
        h2_after_70 = [g for g in h2 if g >= 70]
        
        target_matches.append({
            'match': f"{m['home_team']} vs {m['away_team']}",
            'league': m['league'],
            'c1': c1,
            'h2_before_70': len(h2_before_70),
            'h2_after_70_count': len(h2_after_70),
            'h2_goals': h2
        })

# Focus ONLY on Condition A: NO GOAL at all in 2H up to minute 70
cond_a = [m for m in target_matches if m['h2_before_70'] == 0]

# Target 1: Minimal 1 gol di sisa waktu (>= 1 gol di menit 70-90+) -> Menang Over 0.75 (Setengah) / Push 1 / Menang 0.5
win_at_least_1 = [m for m in cond_a if m['h2_after_70_count'] >= 1]

# Target 2: Minimal 2 gol di sisa waktu (>= 2 gol di menit 70-90+) -> Menang FULL Over 1.25
win_at_least_2 = [m for m in cond_a if m['h2_after_70_count'] >= 2]

print("=== SIMULASI PASARAN BANDAR OVER 1.25 DI MENIT 70' (KONDISI A) ===\n")
print(f"Total Match Puasa Gol di 2H s/d Menit 70': {len(cond_a)} match\n")

print(f"1. Minimal Terjadi 1 Gol (Menit 70'-90'+): {len(win_at_least_1)} match ({len(win_at_least_1)/len(cond_a)*100:.1f}%)")
print(f"2. Terjadi 2 Gol atau Lebih (Menit 70'-90'+): {len(win_at_least_2)} match ({len(win_at_least_2)/len(cond_a)*100:.1f}%)")
print(f"3. Sisa waktu 0 Gol (Rungkad/Loss Full): {len(cond_a) - len(win_at_least_1)} match\n")

print("--- DETAIL HASIL SISA WAKTU MENIT 70'-90'+ ---")
for m in cond_a:
    cnt = m['h2_after_70_count']
    if cnt >= 2:
        res = "WIN FULL (2+ Gol)"
    elif cnt == 1:
        res = "HALF WIN / PUSH (1 Gol)"
    else:
        res = "LOSS FULL (0 Gol)"
    print(f" • [{res}] {m['league']} | {m['match']} -> Gol sisa: {m['h2_after_70_count']} gol (menit: {m['h2_goals']})")

