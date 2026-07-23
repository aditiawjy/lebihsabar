import csv

with open('goal_log_vsoccer.csv', mode='r', encoding='utf-8') as f:
    matches = [m for m in csv.DictReader(f) if m['goals']]

processed = []
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
    c2 = len(h2)
    tot = c1 + c2
    
    ko_raw = m['ko_line']
    try:
        if '/' in ko_raw:
            p1, p2 = ko_raw.split('/')
            ko = (float(p1) + float(p2))/2.0
        else: ko = float(ko_raw)
    except: ko = 0.0
    
    # Extract 1H final score
    h1_last_score = "0-0"
    for p in parts:
        if p.startswith('1H'):
            h1_last_score = p.split('(')[1].replace(')', '')
            
    if h1_last_score != "0-0":
        sh, sa = map(int, h1_last_score.split('-'))
    else: sh, sa = 0, 0
        
    h1_diff = abs(sh - sa)
    
    processed.append({
        'match': f"{m['home_team']} vs {m['away_team']}",
        'league': m['league'],
        'c1': c1, 'c2': c2, 'tot': tot,
        'ko': ko, 'ko_raw': ko_raw,
        'h1_min': h1, 'h2_min': h2,
        'sh': sh, 'sa': sa, 'h1_diff': h1_diff
    })

# Sample: 1H >= 2 Goals (99 matches)
sample_base = [m for m in processed if m['c1'] >= 2]
wins = [m for m in sample_base if m['c2'] >= 3]
losses = [m for m in sample_base if m['c2'] < 3]

print(f"=== ANALISIS PENYEBAB LOSS PADA OVER 2.5 2H (1H >= 2) ===")
print(f"Total Sampel: {len(sample_base)} match")
print(f"Win (2H >= 3): {len(wins)} match (65.7%)")
print(f"Loss (2H < 3): {len(losses)} match (34.3%)\n")

print("--- DIAGNOSIS FAKTOR PENYEBAB KALAH (LOSS) ---")

# Factor 1: KO Line Rendah (< 5.5 vs >= 5.5)
loss_low_ko = [m for m in losses if m['ko'] < 5.5]
win_low_ko = [m for m in wins if m['ko'] < 5.5]

loss_high_ko = [m for m in losses if m['ko'] >= 5.5]
win_high_ko = [m for m in wins if m['ko'] >= 5.5]

print(f"1. Faktor KO Line Rendah (< 5.5):")
print(f"   Match KO Line < 5.5: {len(win_low_ko)+len(loss_low_ko)} match | Loss: {len(loss_low_ko)} | Loss Rate: {len(loss_low_ko)/(len(win_low_ko)+len(loss_low_ko))*100:.1f}%")
print(f"   Match KO Line >= 5.5: {len(win_high_ko)+len(loss_high_ko)} match | Win: {len(win_high_ko)} | Win Rate: {len(win_high_ko)/(len(win_high_ko)+len(loss_high_ko))*100:.1f}%\n")

# Factor 2: Selisih Gol Babak 1 Terlalu Jauh (h1_diff >= 3)
loss_blowout = [m for m in losses if m['h1_diff'] >= 3]
win_blowout = [m for m in wins if m['h1_diff'] >= 3]

print(f"2. Faktor 1H Skor Bantai / Selisih >= 3 (misal 3-0, 4-0, 4-1):")
print(f"   Match 1H Bantai: {len(win_blowout)+len(loss_blowout)} match | Loss: {len(loss_blowout)} | Loss Rate: {len(loss_blowout)/(len(win_blowout)+len(loss_blowout))*100:.1f}%\n")

# Factor 3: 1H Cuma Pas 2 Gol (c1 == 2 vs c1 >= 3)
loss_2goals_h1 = [m for m in losses if m['c1'] == 2]
win_2goals_h1 = [m for m in wins if m['c1'] == 2]

loss_3plus_h1 = [m for m in losses if m['c1'] >= 3]
win_3plus_h1 = [m for m in wins if m['c1'] >= 3]

print(f"3. Faktor Gol Babak 1 Cuma 2 Gol (1H = 2 vs 1H >= 3):")
print(f"   Jika 1H = Tepat 2 Gol: Sample {len(win_2goals_h1)+len(loss_2goals_h1)} match | Win Rate: {len(win_2goals_h1)/(len(win_2goals_h1)+len(loss_2goals_h1))*100:.1f}%")
print(f"   Jika 1H >= 3 Gol    : Sample {len(win_3plus_h1)+len(loss_3plus_h1)} match | Win Rate: {len(win_3plus_h1)/(len(win_3plus_h1)+len(loss_3plus_h1))*100:.1f}%\n")

print("=== REKOMENDASI PEMBERSIHAN FILTER UNTUK MENAIKKAN AKURASI ===")

# Clean Filter 1: 1H >= 2 DAN KO Line >= 5.5 (Eliminasi KO Line rendah)
f1 = [m for m in sample_base if m['ko'] >= 5.5]
f1_win = [m for m in f1 if m['c2'] >= 3]
print(f"Filter Perbaikan 1 (Gunakan KO Line >= 5.5):")
print(f" Sample: {len(f1)} match | Win Rate: {len(f1_win)/len(f1)*100:.1f}% (+6% Akurasi!)\n")

# Clean Filter 2: 1H >= 3 DAN KO Line >= 5.5 (Eliminasi KO Line rendah & 1H 2 gol)
f2 = [m for m in sample_base if m['c1'] >= 3 and m['ko'] >= 5.5]
f2_win = [m for m in f2 if m['c2'] >= 3]
print(f"Filter Perbaikan 2 (Gunakan 1H >= 3 Gol & KO Line >= 5.5):")
print(f" Sample: {len(f2)} match | Win Rate: {len(f2_win)/len(f2)*100:.1f}% (+11.6% Akurasi!)\n")

