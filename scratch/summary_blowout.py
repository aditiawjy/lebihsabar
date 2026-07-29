import csv

file_path = 'goal_log_vsoccer.csv'

with open(file_path, mode='r', encoding='utf-8') as f:
    matches = [m for m in csv.DictReader(f) if m.get('goals')]

blowout = []

for m in matches:
    parts = [p.strip() for p in m['goals'].split('|')]
    h1 = []
    h2 = []
    h1_score = "0-0"
    
    for p in parts:
        h = p.split(' ')[0]
        if h == '1H':
            h1.append(p)
            if '(' in p and ')' in p:
                h1_score = p.split('(')[1].replace(')', '').strip()
        elif h == '2H':
            h2.append(p)
            
    sh, sa = map(int, h1_score.split('-')) if h1_score != "0-0" else (0,0)
    diff = abs(sh - sa)
    
    if diff >= 3:
        blowout.append({
            'h1_score': h1_score,
            'diff': diff,
            'h2_cnt': len(h2),
            'ft': f"{m.get('final_home')}-{m.get('final_away')}",
            'goals': m['goals']
        })

tot = len(blowout)
over15 = sum(1 for m in blowout if m['h2_cnt'] >= 2)
over25 = sum(1 for m in blowout if m['h2_cnt'] >= 3)
zero_h2 = sum(1 for m in blowout if m['h2_cnt'] == 0)

print(f"SUMMARY RESULT SKOR 1H BANTAI / BEDA JAUH (SELISIH >= 3 GOL):")
print(f"Total Match                       : {tot} pertandingan")
print(f"Babak 2 Terjadi >= 2 Gol (Over 1.5): {over15} match ({over15/tot*100:.1f}%)")
print(f"Babak 2 Terjadi >= 3 Gol (Over 2.5): {over25} match ({over25/tot*100:.1f}%)")
print(f"Babak 2 Macet 0 Gol                : {zero_h2} match ({zero_h2/tot*100:.1f}%)")
