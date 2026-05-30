# -*- coding: utf-8 -*-
"""
HIPOTESIS BARU dari user:
  (1) Apakah URUTAN / IDENTITAS TIM punya kekuatan prediksi?
  (2) Apakah URUTAN SKOR punya memori? (skor match N -> prediksi match N+1)

Diuji dengan forward test: train Hari1+2, test Hari3.
"""
from collections import defaultdict, Counter

# (jam, slot, home, away, fh_h, fh_a, ft_h, ft_a)
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
hari3 = [
 ("12:00",0,"Belgium","Spain",1,0,1,1),("12:30",30,"Denmark","Norway",1,1,2,3),
 ("12:45",45,"Mexico","Scotland",1,0,1,3),("13:00",0,"Finland","Morocco",0,0,1,1),
 ("13:15",15,"England","Netherlands",0,0,0,0),("13:30",30,"Wales","Mexico",0,0,0,1),
 ("13:45",45,"Italy","Romania",2,0,4,0),("14:00",0,"France","Netherlands",0,0,0,0),
 ("14:15",15,"Sweden","Wales",0,1,0,1),("15:00",0,"Czech Republic","Qatar",0,1,0,2),
 ("15:15",15,"Mexico","Spain",1,1,1,1),("15:30",30,"USA","Norway",0,1,1,2),
 ("15:45",45,"New Zealand","Hungary",1,0,3,0),("16:00",0,"Sweden","France",1,0,1,0),
 ("16:15",15,"Ukraine","Croatia",0,1,0,1),("16:30",30,"Wales","Ghana",0,0,1,0),
 ("16:45",45,"England","Denmark",1,1,2,2),("17:00",0,"Romania","Scotland",1,0,1,0),
 ("17:15",15,"Sweden","Argentina",3,1,3,1),("17:30",30,"Hungary","Poland",0,0,2,2),
 ("17:45",45,"Belgium","Netherlands",0,0,1,1),("18:00",0,"Mexico","Finland",3,0,4,0),
 ("18:15",15,"Poland","Germany",0,2,0,3),("18:30",30,"Portugal","Ghana",0,2,1,2),
 ("18:45",45,"Czech Republic","Italy",0,0,0,1),("19:00",0,"Sweden","Croatia",0,0,1,1),
 ("19:15",15,"Wales","Norway",0,0,0,0),("19:30",30,"Spain","Czech Republic",1,1,3,2),
 ("19:45",45,"Morocco","Denmark",1,0,2,0),("20:00",0,"Mexico","Italy",0,2,0,2),
 ("20:15",15,"Hungary","New Zealand",0,0,0,1),("20:30",30,"Portugal","Argentina",0,0,0,0),
 ("20:45",45,"France","Ukraine",1,0,3,0),("21:00",0,"Belgium","Netherlands",1,0,1,1),
 ("21:15",15,"Poland","USA",0,1,0,2),("21:30",30,"Croatia","Portugal",0,0,1,0),
 ("21:45",45,"Scotland","Norway",1,0,1,0),("22:00",0,"Hungary","Ukraine",0,0,1,0),
 ("22:30",30,"Argentina","Scotland",0,0,2,0),("22:45",45,"Croatia","Netherlands",2,0,2,1),
 ("23:00",0,"Germany","Spain",1,0,1,1),("23:15",15,"Italy","Romania",1,2,1,2),
 ("23:30",30,"Finland","New Zealand",0,0,0,1),("23:45",45,"England","Argentina",2,2,3,2),
 ("00:00",0,"Morocco","Wales",0,1,1,1),("00:15",15,"Qatar","Hungary",0,0,0,1),
 ("00:30",30,"Italy","Mexico",0,0,0,1),("00:45",45,"Ghana","Sweden",0,0,0,0),
]

def res(r):  # hasil FT
    return "H" if r[6]>r[7] else ("D" if r[6]==r[7] else "A")
def tot(r):
    return r[6]+r[7]

train = hari1+hari2
test  = hari3
ALL = train+test

# =====================================================================
# HIPOTESIS 1: IDENTITAS TIM
# Apakah tim tertentu konsisten? (mis. "Belgium sering menang", "match X sering Over")
# =====================================================================
print("="*70)
print("HIPOTESIS 1: apakah IDENTITAS TIM punya kekuatan prediksi?")
print("="*70)

def team_stats(data):
    st = defaultdict(lambda: {"main":0,"menang":0,"gol":0})
    for r in data:
        h,a = r[2],r[3]
        st[h]["main"]+=1; st[a]["main"]+=1
        st[h]["gol"]+=r[6]; st[a]["gol"]+=r[7]
        if r[6]>r[7]: st[h]["menang"]+=1
        elif r[7]>r[6]: st[a]["menang"]+=1
    return st

st_tr = team_stats(train)
st_te = team_stats(test)
print(f"\n{'TIM':<16}{'TRAIN win%':>12}{'TEST win%':>12}  (apakah konsisten?)")
print("-"*54)
# bandingkan win rate tim di train vs test
teams = sorted(st_tr.keys())
diffs=[]
for t in teams:
    if st_tr[t]["main"]>=4 and st_te.get(t,{}).get("main",0)>=2:
        wtr = st_tr[t]["menang"]/st_tr[t]["main"]*100
        wte = st_te[t]["menang"]/st_te[t]["main"]*100
        diffs.append(abs(wtr-wte))
        print(f"{t:<16}{wtr:>11.0f}%{wte:>11.0f}%")
if diffs:
    print(f"\nRata-rata PERUBAHAN win% tim (train->test): {sum(diffs)/len(diffs):.0f} poin")
    print("Kalau identitas tim NYATA -> perubahan kecil. Kalau acak -> perubahan besar/liar.")

# =====================================================================
# HIPOTESIS 2: URUTAN SKOR (Markov) - apakah skor match N memprediksi match N+1?
# =====================================================================
print("\n" + "="*70)
print("HIPOTESIS 2: apakah URUTAN SKOR punya memori? (skor N -> hasil N+1)")
print("="*70)

# bangun transisi dari TRAIN: hasil match -> hasil match berikut
def transisi(data):
    trans = defaultdict(Counter)
    for i in range(len(data)-1):
        trans[res(data[i])][res(data[i+1])]+=1
    return trans

tr = transisi(train)
print("\nDari TRAIN, sesudah hasil X, match berikut jadi apa?")
for prev in ["H","D","A"]:
    c = tr[prev]; s = sum(c.values())
    if s:
        print(f"  setelah {prev}: H={c['H']/s*100:.0f}% D={c['D']/s*100:.0f}% A={c['A']/s*100:.0f}%  (n={s})")

# baseline keseluruhan
allc = Counter(res(r) for r in train); s=sum(allc.values())
print(f"  BASELINE   : H={allc['H']/s*100:.0f}% D={allc['D']/s*100:.0f}% A={allc['A']/s*100:.0f}%")
print("  -> kalau angka 'setelah X' MIRIP baseline = skor sebelumnya TIDAK berpengaruh.")

# Uji prediksi Markov di TEST: prediksi hasil terbanyak setelah hasil sebelumnya
print("\nUji 'prediktor Markov' (pakai aturan dari train) di TEST hari-3:")
hit=n=0
for i in range(1,len(test)):
    prev = res(test[i-1])
    c = tr[prev]
    if not c: continue
    pred = c.most_common(1)[0][0]   # tebakan dari pola train
    n+=1
    if pred==res(test[i]): hit+=1
print(f"  Akurasi Markov di test: {hit}/{n} = {hit/n*100:.0f}%")
# bandingkan dengan tebak kelas mayoritas saja
maj = allc.most_common(1)[0][0]
hitm = sum(1 for r in test if res(r)==maj)
print(f"  Tebak kelas mayoritas '{maj}' saja: {hitm}/{len(test)} = {hitm/len(test)*100:.0f}%")
print("  -> kalau Markov TIDAK lebih baik dari tebak mayoritas = urutan skor tak berguna.")

# =====================================================================
# HIPOTESIS 3: skor PERSIS berulang (mis. apakah skor 0-1 sering diikuti skor tertentu)
# =====================================================================
print("\n" + "="*70)
print("HIPOTESIS 3: apakah SKOR PERSIS berulang dalam urutan? (autокorelasi total gol)")
print("="*70)
def autocorr_tot(data):
    xs=[tot(r) for r in data[:-1]]; ys=[tot(r) for r in data[1:]]
    mx=sum(xs)/len(xs); my=sum(ys)/len(ys)
    cov=sum((a-mx)*(b-my) for a,b in zip(xs,ys))/len(xs)
    import math
    sx=math.sqrt(sum((a-mx)**2 for a in xs)/len(xs))
    sy=math.sqrt(sum((b-my)**2 for b in ys)/len(ys))
    return cov/(sx*sy) if sx*sy else 0
print(f"  Autokorelasi total gol (match N vs N+1) di SEMUA data: {autocorr_tot(ALL):+.3f}")
print("  (mendekati 0 = skor match berikut TIDAK bergantung skor sebelumnya = acak)")
