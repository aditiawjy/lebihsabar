# -*- coding: utf-8 -*-
"""
SIGNAL SCANNER.
Ide user (VALID secara konsep): jangan taruh tiap match.
Tunggu MOMEN / SINYAL tertentu muncul, baru taruh.

Yang diuji:
1. Cari banyak aturan sinyal di HARI-1, pilih yang win-rate tertinggi.
2. Pakai sinyal terbaik itu di HARI-2 (data baru).
   - Kalau sinyal NYATA -> tetap akurat di hari-2.
   - Kalau sinyal palsu -> akurasi runtuh ke baseline.
"""
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

# fitur tiap match
def feat(r):
    fh = r[4]+r[5]; ft = r[6]+r[7]; sh = ft-fh
    return {
        "slot": r[1],
        "fh_total": fh,
        "fh_draw": r[4]==r[5],
        "fh_over0": fh>0,
        "result": "H" if r[6]>r[7] else ("D" if r[6]==r[7] else "A"),
        "over25": ft>2.5,
        "btts": r[6]>0 and r[7]>0,
        "sh_goal": sh>0,
    }

# ---- definisikan banyak SINYAL: (kondisi pemicu, target taruhan) ----
# kondisi memakai match SEBELUMNYA (prev) + match SEKARANG babak-1 (cur).
signals = []

# Sinyal tipe A: berbasis babak-1 match sekarang -> prediksi hasil FT
signals.append(("FH seri 0-0 -> FT Over2.5",
    lambda prev,cur: cur["fh_total"]==0,
    lambda cur: cur["over25"]))
signals.append(("FH ada gol -> FT Over2.5",
    lambda prev,cur: cur["fh_over0"],
    lambda cur: cur["over25"]))
signals.append(("FH 2+ gol -> FT Over2.5",
    lambda prev,cur: cur["fh_total"]>=2,
    lambda cur: cur["over25"]))
signals.append(("FH seri -> FT seri",
    lambda prev,cur: cur["fh_draw"],
    lambda cur: cur["result"]=="D"))

# Sinyal tipe B: berbasis match sebelumnya (momentum/balasan)
signals.append(("Prev Under2.5 -> cur Over2.5",
    lambda prev,cur: prev and not prev["over25"],
    lambda cur: cur["over25"]))
signals.append(("Prev Over2.5 -> cur Over2.5",
    lambda prev,cur: prev and prev["over25"],
    lambda cur: cur["over25"]))
signals.append(("Prev no SH goal -> cur ada SH goal",
    lambda prev,cur: prev and not prev["sh_goal"],
    lambda cur: cur["sh_goal"]))
signals.append(("Prev BTTS -> cur BTTS",
    lambda prev,cur: prev and prev["btts"],
    lambda cur: cur["btts"]))

# Sinyal tipe C: slot menit
for s in (0,15,30,45):
    signals.append((f"Slot :{s:02d} -> Away menang",
        (lambda ss: (lambda prev,cur: cur["slot"]==ss))(s),
        lambda cur: cur["result"]=="A"))
    signals.append((f"Slot :{s:02d} -> Over2.5",
        (lambda ss: (lambda prev,cur: cur["slot"]==ss))(s),
        lambda cur: cur["over25"]))

def evaluasi(data, trig, target):
    feats = [feat(r) for r in data]
    hit=0; n=0
    for i in range(len(feats)):
        prev = feats[i-1] if i>0 else None
        cur = feats[i]
        if trig(prev, cur):
            n += 1
            if target(cur): hit += 1
    return hit, n

print("="*72)
print("SIGNAL SCANNER  --  cari sinyal akurat di HARI-1, uji di HARI-2")
print("="*72)
print(f"{'SINYAL':<38}{'HARI-1':>16}{'HARI-2':>16}")
print("-"*72)

hasil = []
for nama, trig, target in signals:
    h1,n1 = evaluasi(hari1, trig, target)
    h2,n2 = evaluasi(hari2, trig, target)
    wr1 = h1/n1*100 if n1 else 0
    wr2 = h2/n2*100 if n2 else 0
    hasil.append((nama, wr1, n1, wr2, n2))
    print(f"{nama:<38}{h1:>3}/{n1:<3}={wr1:>4.0f}%   {h2:>3}/{n2:<3}={wr2:>4.0f}%")

# Ambil 3 sinyal TERBAIK di hari-1 (n minimal 6), lihat nasibnya di hari-2
print("\n" + "="*72)
print("3 SINYAL 'TERBAIK' versi HARI-1 (dengan n>=6) -- apa kabarnya di HARI-2?")
print("="*72)
layak = [x for x in hasil if x[2]>=6]
layak.sort(key=lambda x: x[1], reverse=True)
for nama, wr1, n1, wr2, n2 in layak[:3]:
    drop = wr1 - wr2
    print(f"  {nama}")
    print(f"     Hari-1: {wr1:.0f}%  ->  Hari-2: {wr2:.0f}%   (berubah {drop:+.0f} poin)")
print("\nKalau sinyal NYATA: akurasi hari-2 ~ sama dengan hari-1.")
print("Kalau akurasi AMBRUK di hari-2: sinyal itu cuma kebetulan hari-1.")
