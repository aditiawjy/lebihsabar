# -*- coding: utf-8 -*-
"""
TES PALING JUJUR: Out-of-Sample Validation.
Hari-1 = data lama (52 match). Hari-2 = data baru (49 match) yg belum pernah dilihat.

Logika:
- Kalau "pola" yang kita temukan di Hari-1 itu NYATA (sifat mesin),
  maka dia HARUS muncul lagi di Hari-2.
- Kalau di Hari-2 polanya HILANG / TERBALIK,
  maka pola Hari-1 itu cuma kebetulan (acak).
"""
import statistics
from collections import defaultdict

# ---- HARI 1 (jam, menit, home, away, fh_h, fh_a, ft_h, ft_a) ----
hari1 = [
    ("12:00",0,"Argentina","Ghana",0,1,0,1),("12:15",15,"Belgium","USA",0,0,5,0),
    ("12:30",30,"Portugal","France",2,0,4,1),("12:45",45,"Mexico","Sweden",0,1,0,2),
    ("13:00",0,"Finland","Germany",2,1,2,2),("13:15",15,"Morocco","Denmark",1,1,1,2),
    ("13:30",30,"Hungary","Norway",0,0,0,0),("13:45",45,"Qatar","Scotland",0,0,0,1),
    ("14:00",0,"Belgium","Mexico",0,0,1,1),("14:15",15,"Italy","Croatia",0,0,0,0),
    ("14:30",30,"Netherlands","England",1,0,2,0),("14:45",45,"Romania","Denmark",0,0,0,0),
    ("15:00",0,"Argentina","Ukraine",0,2,0,3),("15:15",15,"USA","Ghana",2,0,3,0),
    ("15:30",30,"Netherlands","Spain",1,2,1,4),("15:45",45,"Wales","Denmark",0,0,0,3),
    ("16:00",0,"Italy","Czech Republic",1,0,2,1),("16:15",15,"Sweden","Morocco",1,2,2,2),
    ("16:30",30,"Poland","Norway",0,0,1,0),("16:45",45,"Finland","Mexico",0,1,0,2),
    ("17:00",0,"Romania","Scotland",0,0,1,1),("17:15",15,"Sweden","Argentina",2,1,2,2),
    ("17:30",30,"Mexico","Croatia",0,1,1,2),("17:45",45,"England","Belgium",0,0,0,0),
    ("18:00",0,"Spain","Germany",0,0,0,0),("18:15",15,"Ghana","Portugal",0,2,1,2),
    ("18:30",30,"Denmark","Morocco",1,1,1,2),("18:45",45,"Czech Republic","Qatar",1,0,2,0),
    ("19:00",0,"Hungary","New Zealand",0,1,0,2),("19:15",15,"France","Netherlands",0,0,0,1),
    ("19:30",30,"Argentina","Ukraine",2,0,2,0),("19:45",45,"USA","Wales",0,0,0,2),
    ("20:00",0,"Netherlands","Germany",1,0,1,1),("20:15",15,"Spain","Italy",0,1,2,1),
    ("20:30",30,"Finland","Poland",0,0,0,0),("20:45",45,"Belgium","Mexico",0,2,0,4),
    ("21:00",0,"Morocco","Sweden",1,0,4,2),("21:15",15,"Scotland","Denmark",0,0,1,0),
    ("21:30",30,"Norway","Czech Republic",0,0,0,0),("21:45",45,"Morocco","USA",0,1,0,2),
    ("22:00",0,"France","Portugal",2,1,4,1),("22:15",15,"Hungary","Qatar",1,0,1,1),
    ("22:30",30,"Italy","Poland",0,0,0,3),("22:45",45,"Netherlands","Ukraine",1,1,1,4),
    ("23:00",0,"Czech Republic","Argentina",1,1,1,1),("23:15",15,"Romania","Belgium",0,1,1,1),
    ("23:30",30,"England","Netherlands",1,0,3,0),("23:45",45,"Wales","Mexico",0,1,0,1),
    ("00:00",0,"Sweden","Ghana",0,1,0,1),("00:15",15,"Belgium","USA",6,1,9,1),
    ("00:30",30,"Italy","Spain",0,2,1,6),("00:45",45,"Sweden","Finland",0,1,1,1),
]

# ---- HARI 2 (data BARU yang Anda kasih) ----
hari2 = [
    ("12:00",0,"Wales","Ghana",0,2,1,3),("12:15",15,"Sweden","England",0,1,0,3),
    ("12:30",30,"Morocco","Argentina",1,0,3,0),("12:45",45,"Mexico","Germany",0,0,1,0),
    ("13:00",0,"Qatar","Finland",0,0,1,0),("13:15",15,"Scotland","Czech Republic",0,1,0,2),
    ("13:30",30,"Germany","Poland",0,1,2,2),("13:45",45,"Croatia","Belgium",1,0,1,1),
    ("14:00",0,"Sweden","Netherlands",3,0,5,1),("14:15",15,"Ukraine","France",2,1,3,1),
    ("14:30",30,"Argentina","Mexico",1,0,2,0),("14:45",45,"Norway","Wales",1,0,1,0),
    ("15:00",0,"England","Croatia",0,0,0,0),("15:15",15,"Netherlands","Ghana",0,0,1,2),
    ("15:45",45,"USA","Morocco",0,0,2,0),("16:00",0,"Qatar","Romania",0,0,0,0),
    ("16:15",15,"Czech Republic","Spain",2,0,3,0),("16:30",30,"Argentina","Ukraine",0,1,0,2),
    ("17:00",0,"Italy","Sweden",2,2,3,3),("17:15",15,"France","Portugal",1,0,1,0),
    ("17:30",30,"Finland","Germany",0,2,0,2),("17:45",45,"Ghana","Belgium",0,2,0,2),
    ("18:00",0,"Scotland","Portugal",0,1,0,2),("18:15",15,"Finland","Netherlands",0,1,1,1),
    ("18:30",30,"Wales","Mexico",0,0,1,1),("18:45",45,"England","Portugal",1,0,2,2),
    ("19:00",0,"Netherlands","Denmark",0,1,0,1),("19:15",15,"Mexico","Hungary",1,1,3,2),
    ("19:30",30,"Poland","USA",0,1,2,3),("19:45",45,"Denmark","Argentina",0,1,0,1),
    ("20:00",0,"Norway","Morocco",1,1,3,1),("20:15",15,"Belgium","Ukraine",1,2,2,2),
    ("20:30",30,"Sweden","France",1,2,1,2),("21:00",0,"Romania","Croatia",2,0,2,0),
    ("21:15",15,"England","Argentina",0,1,1,1),("21:30",30,"Germany","Scotland",1,1,3,2),
    ("21:45",45,"Qatar","Wales",2,0,4,0),("22:00",0,"Spain","Belgium",0,1,1,1),
    ("22:15",15,"Morocco","Hungary",0,0,2,0),("22:30",30,"Czech Republic","Netherlands",0,0,0,1),
    ("22:45",45,"Poland","Belgium",0,0,0,0),("23:00",0,"Mexico","Hungary",2,2,2,3),
    ("23:15",15,"USA","Croatia",0,2,1,3),("23:30",30,"Finland","Sweden",0,0,1,2),
    ("23:45",45,"Denmark","Portugal",0,0,3,0),("00:00",0,"Italy","Romania",0,0,1,0),
    ("00:15",15,"Argentina","Ukraine",0,2,1,3),("00:30",30,"Wales","Norway",1,0,1,1),
    ("00:45",45,"France","Morocco",0,1,1,2),
]

def outcome(r):
    if r[6]>r[7]: return "H"
    if r[6]==r[7]: return "D"
    return "A"

def lapor_slot(data, judul):
    print(f"\n--- {judul} (n={len(data)}) ---")
    by = defaultdict(lambda: {"H":0,"D":0,"A":0,"n":0,"gol":0})
    for r in data:
        s = by[r[1]]
        s[outcome(r)] += 1; s["n"] += 1; s["gol"] += r[6]+r[7]
    for slot in sorted(by):
        s = by[slot]
        away_pct = s["A"]/s["n"]*100
        print(f"  :{slot:02d}  n={s['n']:2d}  avg gol={s['gol']/s['n']:.2f}  "
              f"H={s['H']} D={s['D']} A={s['A']}  (Away {away_pct:.0f}%)")
    return by

print("="*64)
print("PEMBUKTIAN: apakah pola Hari-1 bertahan di Hari-2?")
print("="*64)

b1 = lapor_slot(hari1, "HARI 1")
b2 = lapor_slot(hari2, "HARI 2 (data baru, out-of-sample)")

print("\n" + "="*64)
print("FOKUS: slot menit :45 (yang Hari-1 terlihat 'Away menang ~69%')")
print("="*64)
a1 = b1[45]; a2 = b2[45]
print(f"Hari-1  :45  -> Away {a1['A']}/{a1['n']} = {a1['A']/a1['n']*100:.0f}%   (H={a1['H']} D={a1['D']} A={a1['A']})")
print(f"Hari-2  :45  -> Away {a2['A']}/{a2['n']} = {a2['A']/a2['n']*100:.0f}%   (H={a2['H']} D={a2['D']} A={a2['A']})")

# gabungan
gab = hari1 + hari2
print("\n" + "="*64)
print("BASELINE STABIL (gabungan semua data)")
print("="*64)
N = len(gab)
ft = [r[6]+r[7] for r in gab]
sh = [(r[6]-r[4])+(r[7]-r[5]) for r in gab]
ada_sh = sum(1 for g in sh if g>0)
print(f"Total match: {N}")
print(f"Rata-rata gol FT      : {statistics.mean(ft):.2f}")
print(f"Over 2.5              : {sum(1 for t in ft if t>2.5)}/{N} = {sum(1 for t in ft if t>2.5)/N*100:.0f}%")
print(f"Ada gol babak 2       : {ada_sh}/{N} = {ada_sh/N*100:.0f}%")
hw = sum(1 for r in gab if outcome(r)=='H'); dr = sum(1 for r in gab if outcome(r)=='D'); aw = sum(1 for r in gab if outcome(r)=='A')
print(f"Home/Seri/Away        : {hw/N*100:.0f}% / {dr/N*100:.0f}% / {aw/N*100:.0f}%")
