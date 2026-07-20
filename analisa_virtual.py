# -*- coding: utf-8 -*-
"""
Analisa data virtual football yang diberikan user.
Tujuan: cek apakah ADA pola berbasis waktu (jam/menit) atau pola lain
yang bisa dieksploitasi untuk prediksi.
"""
from collections import defaultdict
import statistics

# (waktu, menit_slot, home, away, fh_h, fh_a, ft_h, ft_a)
data = [
    ("12:00", 0, "Argentina","Ghana",0,1,0,1),
    ("12:15",15, "Belgium","USA",0,0,5,0),
    ("12:30",30, "Portugal","France",2,0,4,1),
    ("12:45",45, "Mexico","Sweden",0,1,0,2),
    ("13:00", 0, "Finland","Germany",2,1,2,2),
    ("13:15",15, "Morocco","Denmark",1,1,1,2),
    ("13:30",30, "Hungary","Norway",0,0,0,0),
    ("13:45",45, "Qatar","Scotland",0,0,0,1),
    ("14:00", 0, "Belgium","Mexico",0,0,1,1),
    ("14:15",15, "Italy","Croatia",0,0,0,0),
    ("14:30",30, "Netherlands","England",1,0,2,0),
    ("14:45",45, "Romania","Denmark",0,0,0,0),
    ("15:00", 0, "Argentina","Ukraine",0,2,0,3),
    ("15:15",15, "USA","Ghana",2,0,3,0),
    ("15:30",30, "Netherlands","Spain",1,2,1,4),
    ("15:45",45, "Wales","Denmark",0,0,0,3),
    ("16:00", 0, "Italy","Czech Republic",1,0,2,1),
    ("16:15",15, "Sweden","Morocco",1,2,2,2),
    ("16:30",30, "Poland","Norway",0,0,1,0),
    ("16:45",45, "Finland","Mexico",0,1,0,2),
    ("17:00", 0, "Romania","Scotland",0,0,1,1),
    ("17:15",15, "Sweden","Argentina",2,1,2,2),
    ("17:30",30, "Mexico","Croatia",0,1,1,2),
    ("17:45",45, "England","Belgium",0,0,0,0),
    ("18:00", 0, "Spain","Germany",0,0,0,0),
    ("18:15",15, "Ghana","Portugal",0,2,1,2),
    ("18:30",30, "Denmark","Morocco",1,1,1,2),
    ("18:45",45, "Czech Republic","Qatar",1,0,2,0),
    ("19:00", 0, "Hungary","New Zealand",0,1,0,2),
    ("19:15",15, "France","Netherlands",0,0,0,1),
    ("19:30",30, "Argentina","Ukraine",2,0,2,0),
    ("19:45",45, "USA","Wales",0,0,0,2),
    ("20:00", 0, "Netherlands","Germany",1,0,1,1),
    ("20:15",15, "Spain","Italy",0,1,2,1),
    ("20:30",30, "Finland","Poland",0,0,0,0),
    ("20:45",45, "Belgium","Mexico",0,2,0,4),
    ("21:00", 0, "Morocco","Sweden",1,0,4,2),
    ("21:15",15, "Scotland","Denmark",0,0,1,0),
    ("21:30",30, "Norway","Czech Republic",0,0,0,0),
    ("21:45",45, "Morocco","USA",0,1,0,2),
    ("22:00", 0, "France","Portugal",2,1,4,1),
    ("22:15",15, "Hungary","Qatar",1,0,1,1),
    ("22:30",30, "Italy","Poland",0,0,0,3),
    ("22:45",45, "Netherlands","Ukraine",1,1,1,4),
    ("23:00", 0, "Czech Republic","Argentina",1,1,1,1),
    ("23:15",15, "Romania","Belgium",0,1,1,1),
    ("23:30",30, "England","Netherlands",1,0,3,0),
    ("23:45",45, "Wales","Mexico",0,1,0,1),
    ("00:00", 0, "Sweden","Ghana",0,1,0,1),
    ("00:15",15, "Belgium","USA",6,1,9,1),
    ("00:30",30, "Italy","Spain",0,2,1,6),
    ("00:45",45, "Sweden","Finland",0,1,1,1),
]

n = len(data)
print(f"Total pertandingan: {n}\n")

# ---------- 1. Statistik dasar ----------
ft_totals = [r[6]+r[7] for r in data]
fh_totals = [r[4]+r[5] for r in data]
sh_totals = [(r[6]-r[4])+(r[7]-r[5]) for r in data]  # gol babak kedua

print("=== STATISTIK GOL ===")
print(f"Rata-rata gol FT  : {statistics.mean(ft_totals):.2f}")
print(f"Rata-rata gol FH  : {statistics.mean(fh_totals):.2f}")
print(f"Rata-rata gol BABAK 2: {statistics.mean(sh_totals):.2f}")
print(f"Over 2.5 (FT)     : {sum(1 for t in ft_totals if t>2.5)}/{n} = {sum(1 for t in ft_totals if t>2.5)/n*100:.0f}%")
print(f"BTTS (kedua cetak): {sum(1 for r in data if r[6]>0 and r[7]>0)}/{n} = {sum(1 for r in data if r[6]>0 and r[7]>0)/n*100:.0f}%")

# ---------- 2. Hasil (1X2) ----------
home_w = sum(1 for r in data if r[6]>r[7])
draw   = sum(1 for r in data if r[6]==r[7])
away_w = sum(1 for r in data if r[6]<r[7])
print("\n=== HASIL 1X2 ===")
print(f"Home menang: {home_w}/{n} = {home_w/n*100:.0f}%")
print(f"Seri       : {draw}/{n} = {draw/n*100:.0f}%")
print(f"Away menang: {away_w}/{n} = {away_w/n*100:.0f}%")

# ---------- 3. Pola berdasarkan SLOT MENIT (:00 :15 :30 :45) ----------
print("\n=== POLA BERDASARKAN MENIT SLOT (yang Anda minta) ===")
by_min = defaultdict(list)
for r in data:
    by_min[r[1]].append(r)
for slot in sorted(by_min):
    rows = by_min[slot]
    avg = statistics.mean([x[6]+x[7] for x in rows])
    hw = sum(1 for x in rows if x[6]>x[7])
    dr = sum(1 for x in rows if x[6]==x[7])
    aw = sum(1 for x in rows if x[6]<x[7])
    print(f"  :{slot:02d}  n={len(rows):2d}  avg gol={avg:.2f}  Home={hw} Seri={dr} Away={aw}")

# ---------- 4. Pola berdasarkan JAM ----------
print("\n=== POLA BERDASARKAN JAM ===")
by_hour = defaultdict(list)
for r in data:
    hour = r[0].split(":")[0]
    by_hour[hour].append(r[6]+r[7])
for h in sorted(by_hour):
    print(f"  jam {h}:00  n={len(by_hour[h])}  avg gol={statistics.mean(by_hour[h]):.2f}")

# ---------- 5. Uji "memori": apakah hasil match ke-i mempengaruhi ke-(i+1)? ----------
print("\n=== UJI KORELASI URUTAN (auto-correlation) ===")
# korelasi total gol match i dengan match berikutnya
xs = ft_totals[:-1]
ys = ft_totals[1:]
mx, my = statistics.mean(xs), statistics.mean(ys)
cov = sum((a-mx)*(b-my) for a,b in zip(xs,ys))/len(xs)
sx = statistics.pstdev(xs); sy = statistics.pstdev(ys)
corr = cov/(sx*sy) if sx*sy else 0
print(f"Korelasi gol match-i vs match-(i+1): r = {corr:.3f}")
print("(r mendekati 0 = tidak ada pengaruh dari match sebelumnya / acak)")

# ---------- 6. Streak comeback / FH != FT winner ----------
flip = sum(1 for r in data if (r[4]>r[5]) != (r[6]>r[7]) and not (r[4]==r[5]))
print(f"\nMatch di mana pemimpin babak 1 berubah/hilang: {flip}/{n}")
