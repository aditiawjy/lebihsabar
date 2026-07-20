# -*- coding: utf-8 -*-
"""
CARI PATTERN TERBAIK -- forward test sebenarnya.
TRAIN  : Hari-1 + Hari-2 (101 match) -> cari pattern paling akurat.
TEST   : Hari-3 (48 match, BELUM PERNAH dilihat) -> apakah pattern bertahan?

Pattern yg lolos = akurasi TINGGI di train DAN tetap tinggi di test.
"""
from collections import defaultdict

hari1 = [
    ("12:00",0,0,1,0,1),("12:15",15,0,0,5,0),("12:30",30,2,0,4,1),("12:45",45,0,1,0,2),
    ("13:00",0,2,1,2,2),("13:15",15,1,1,1,2),("13:30",30,0,0,0,0),("13:45",45,0,0,0,1),
    ("14:00",0,0,0,1,1),("14:15",15,0,0,0,0),("14:30",30,1,0,2,0),("14:45",45,0,0,0,0),
    ("15:00",0,0,2,0,3),("15:15",15,2,0,3,0),("15:30",30,1,2,1,4),("15:45",45,0,0,0,3),
    ("16:00",0,1,0,2,1),("16:15",15,1,2,2,2),("16:30",30,0,0,1,0),("16:45",45,0,1,0,2),
    ("17:00",0,0,0,1,1),("17:15",15,2,1,2,2),("17:30",30,0,1,1,2),("17:45",45,0,0,0,0),
    ("18:00",0,0,0,0,0),("18:15",15,0,2,1,2),("18:30",30,1,1,1,2),("18:45",45,1,0,2,0),
    ("19:00",0,0,1,0,2),("19:15",15,0,0,0,1),("19:30",30,2,0,2,0),("19:45",45,0,0,0,2),
    ("20:00",0,1,0,1,1),("20:15",15,0,1,2,1),("20:30",30,0,0,0,0),("20:45",45,0,2,0,4),
    ("21:00",0,1,0,4,2),("21:15",15,0,0,1,0),("21:30",30,0,0,0,0),("21:45",45,0,1,0,2),
    ("22:00",0,2,1,4,1),("22:15",15,1,0,1,1),("22:30",30,0,0,0,3),("22:45",45,1,1,1,4),
    ("23:00",0,1,1,1,1),("23:15",15,0,1,1,1),("23:30",30,1,0,3,0),("23:45",45,0,1,0,1),
    ("00:00",0,0,1,0,1),("00:15",15,6,1,9,1),("00:30",30,0,2,1,6),("00:45",45,0,1,1,1),
]
hari2 = [
    ("12:00",0,0,2,1,3),("12:15",15,0,1,0,3),("12:30",30,1,0,3,0),("12:45",45,0,0,1,0),
    ("13:00",0,0,0,1,0),("13:15",15,0,1,0,2),("13:30",30,0,1,2,2),("13:45",45,1,0,1,1),
    ("14:00",0,3,0,5,1),("14:15",15,2,1,3,1),("14:30",30,1,0,2,0),("14:45",45,1,0,1,0),
    ("15:00",0,0,0,0,0),("15:15",15,0,0,1,2),("15:45",45,0,0,2,0),("16:00",0,0,0,0,0),
    ("16:15",15,2,0,3,0),("16:30",30,0,1,0,2),("17:00",0,2,2,3,3),("17:15",15,1,0,1,0),
    ("17:30",30,0,2,0,2),("17:45",45,0,2,0,2),("18:00",0,0,1,0,2),("18:15",15,0,1,1,1),
    ("18:30",30,0,0,1,1),("18:45",45,1,0,2,2),("19:00",0,0,1,0,1),("19:15",15,1,1,3,2),
    ("19:30",30,0,1,2,3),("19:45",45,0,1,0,1),("20:00",0,1,1,3,1),("20:15",15,1,2,2,2),
    ("20:30",30,1,2,1,2),("21:00",0,2,0,2,0),("21:15",15,0,1,1,1),("21:30",30,1,1,3,2),
    ("21:45",45,2,0,4,0),("22:00",0,0,1,1,1),("22:15",15,0,0,2,0),("22:30",30,0,0,0,1),
    ("22:45",45,0,0,0,0),("23:00",0,2,2,2,3),("23:15",15,0,2,1,3),("23:30",30,0,0,1,2),
    ("23:45",45,0,0,3,0),("00:00",0,0,0,1,0),("00:15",15,0,2,1,3),("00:30",30,1,0,1,1),
    ("00:45",45,0,1,1,2),
]
hari3 = [
    ("12:00",0,1,0,1,1),("12:30",30,1,1,2,3),("12:45",45,1,0,1,3),("13:00",0,0,0,1,1),
    ("13:15",15,0,0,0,0),("13:30",30,0,0,0,1),("13:45",45,2,0,4,0),("14:00",0,0,0,0,0),
    ("14:15",15,0,1,0,1),("15:00",0,0,1,0,2),("15:15",15,1,1,1,1),("15:30",30,0,1,1,2),
    ("15:45",45,1,0,3,0),("16:00",0,1,0,1,0),("16:15",15,0,1,0,1),("16:30",30,0,0,1,0),
    ("16:45",45,1,1,2,2),("17:00",0,1,0,1,0),("17:15",15,3,1,3,1),("17:30",30,0,0,2,2),
    ("17:45",45,0,0,1,1),("18:00",0,3,0,4,0),("18:15",15,0,2,0,3),("18:30",30,0,2,1,2),
    ("18:45",45,0,0,0,1),("19:00",0,0,0,1,1),("19:15",15,0,0,0,0),("19:30",30,1,1,3,2),
    ("19:45",45,1,0,2,0),("20:00",0,0,2,0,2),("20:15",15,0,0,0,1),("20:30",30,0,0,0,0),
    ("20:45",45,1,0,3,0),("21:00",0,1,0,1,1),("21:15",15,0,1,0,2),("21:30",30,0,0,1,0),
    ("21:45",45,1,0,1,0),("22:00",0,0,0,1,0),("22:30",30,0,0,2,0),("22:45",45,2,0,2,1),
    ("23:00",0,1,0,1,1),("23:15",15,1,2,1,2),("23:30",30,0,0,0,1),("23:45",45,2,2,3,2),
    ("00:00",0,0,1,1,1),("00:15",15,0,0,0,1),("00:30",30,0,0,0,1),("00:45",45,0,0,0,0),
]
# format tuple: (jam, slot, fh_h, fh_a, ft_h, ft_a)

def feat(r):
    fh = r[2]+r[3]; ft = r[4]+r[5]; sh = ft-fh
    return {
        "slot": r[1], "fh_total": fh, "ft_total": ft, "sh": sh,
        "fh_draw": r[2]==r[3], "fh_over0": fh>0,
        "result": "H" if r[4]>r[5] else ("D" if r[4]==r[5] else "A"),
        "over15": ft>1.5, "over25": ft>2.5, "over35": ft>3.5,
        "btts": r[4]>0 and r[5]>0, "sh_goal": sh>0,
    }

# daftar pattern (trigger berbasis match sekarang/sebelumnya, target taruhan)
patterns = [
    ("FH>=1 gol -> Over1.5", lambda p,c: c["fh_over0"], lambda c: c["over15"]),
    ("FH>=2 gol -> Over2.5", lambda p,c: c["fh_total"]>=2, lambda c: c["over25"]),
    ("FH>=1 gol -> Over2.5", lambda p,c: c["fh_over0"], lambda c: c["over25"]),
    ("FH 0-0 -> Under2.5",   lambda p,c: c["fh_total"]==0, lambda c: not c["over25"]),
    ("FH 0-0 -> Under1.5",   lambda p,c: c["fh_total"]==0, lambda c: not c["over15"]),
    ("FH seri -> FT seri",   lambda p,c: c["fh_draw"], lambda c: c["result"]=="D"),
    ("FH tim unggul -> menang", lambda p,c: not c["fh_draw"], 
        lambda c: True),  # placeholder, dihitung khusus di bawah
    ("Selalu Over1.5",       lambda p,c: True, lambda c: c["over15"]),
    ("Selalu ada gol SH",    lambda p,c: True, lambda c: c["sh_goal"]),
    ("Prev Under -> cur Over", lambda p,c: p and not p["over25"], lambda c: c["over25"]),
]
# slot patterns
for s in (0,15,30,45):
    patterns.append((f"Slot:{s:02d} -> Away", (lambda ss:(lambda p,c:c["slot"]==ss))(s), lambda c:c["result"]=="A"))
    patterns.append((f"Slot:{s:02d} -> Over2.5", (lambda ss:(lambda p,c:c["slot"]==ss))(s), lambda c:c["over25"]))

# pattern khusus "tim unggul babak1 -> menang FT" perlu data home/away
def evaluasi(data, trig, target, khusus=None):
    feats = [feat(r) for r in data]
    hit=n=0
    for i in range(len(feats)):
        p = feats[i-1] if i>0 else None
        c = feats[i]
        if trig(p,c):
            if khusus=="unggul":
                r = data[i]
                if r[2]==r[3]: continue
                n+=1
                fh_lead = "H" if r[2]>r[3] else "A"
                if c["result"]==fh_lead: hit+=1
            else:
                n+=1
                if target(c): hit+=1
    return hit,n

train = hari1 + hari2
test  = hari3

print("="*74)
print("FORWARD TEST: train Hari1+2 (101 match)  ->  test Hari3 (48 match, baru)")
print("="*74)
print(f"{'PATTERN':<26}{'TRAIN (hari1+2)':>22}{'TEST (hari3)':>22}")
print("-"*74)

rows=[]
for nama, trig, target in patterns:
    khusus = "unggul" if "unggul" in nama else None
    ht,nt = evaluasi(train, trig, target, khusus)
    hh,nh = evaluasi(test, trig, target, khusus)
    wt = ht/nt*100 if nt else 0
    wh = hh/nh*100 if nh else 0
    rows.append((nama,wt,nt,wh,nh))
    print(f"{nama:<26}{ht:>4}/{nt:<3}={wt:>4.0f}%{'':6}{hh:>4}/{nh:<3}={wh:>4.0f}%")

print("\n" + "="*74)
print("PATTERN PALING AKURAT di TRAIN (n>=15) -> dicek di TEST hari-3")
print("="*74)
layak = [r for r in rows if r[2]>=15]
layak.sort(key=lambda x:x[1], reverse=True)
for nama,wt,nt,wh,nh in layak[:5]:
    status = "BERTAHAN" if abs(wt-wh)<=8 else "AMBRUK / berubah"
    print(f"  {nama:<26} train {wt:.0f}% -> test {wh:.0f}%  ({status})")

print("""
CATATAN PENTING tentang odds:
- Pattern 'FH>=1 gol -> Over1.5' & 'FH>=2 -> Over2.5' biasanya BERTAHAN,
  tapi itu cuma matematika (gol sudah terjadi) -> odds-nya kecil (1.1-1.25)
  -> bandar sudah memasang harga -> ROI ~nol.
- Pattern berbasis slot/jam/history -> cek apakah AMBRUK di test.
""")
