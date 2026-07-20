# -*- coding: utf-8 -*-
"""
Uji klaim: kalau 2 match beruntun TIDAK ADA gol babak 2,
apakah match ke-3 cenderung ADA gol babak 2?
"""
data = [
    ("12:00","Argentina","Ghana",0,1,0,1),
    ("12:15","Belgium","USA",0,0,5,0),
    ("12:30","Portugal","France",2,0,4,1),
    ("12:45","Mexico","Sweden",0,1,0,2),
    ("13:00","Finland","Germany",2,1,2,2),
    ("13:15","Morocco","Denmark",1,1,1,2),
    ("13:30","Hungary","Norway",0,0,0,0),
    ("13:45","Qatar","Scotland",0,0,0,1),
    ("14:00","Belgium","Mexico",0,0,1,1),
    ("14:15","Italy","Croatia",0,0,0,0),
    ("14:30","Netherlands","England",1,0,2,0),
    ("14:45","Romania","Denmark",0,0,0,0),
    ("15:00","Argentina","Ukraine",0,2,0,3),
    ("15:15","USA","Ghana",2,0,3,0),
    ("15:30","Netherlands","Spain",1,2,1,4),
    ("15:45","Wales","Denmark",0,0,0,3),
    ("16:00","Italy","Czech Republic",1,0,2,1),
    ("16:15","Sweden","Morocco",1,2,2,2),
    ("16:30","Poland","Norway",0,0,1,0),
    ("16:45","Finland","Mexico",0,1,0,2),
    ("17:00","Romania","Scotland",0,0,1,1),
    ("17:15","Sweden","Argentina",2,1,2,2),
    ("17:30","Mexico","Croatia",0,1,1,2),
    ("17:45","England","Belgium",0,0,0,0),
    ("18:00","Spain","Germany",0,0,0,0),
    ("18:15","Ghana","Portugal",0,2,1,2),
    ("18:30","Denmark","Morocco",1,1,1,2),
    ("18:45","Czech Republic","Qatar",1,0,2,0),
    ("19:00","Hungary","New Zealand",0,1,0,2),
    ("19:15","France","Netherlands",0,0,0,1),
    ("19:30","Argentina","Ukraine",2,0,2,0),
    ("19:45","USA","Wales",0,0,0,2),
    ("20:00","Netherlands","Germany",1,0,1,1),
    ("20:15","Spain","Italy",0,1,2,1),
    ("20:30","Finland","Poland",0,0,0,0),
    ("20:45","Belgium","Mexico",0,2,0,4),
    ("21:00","Morocco","Sweden",1,0,4,2),
    ("21:15","Scotland","Denmark",0,0,1,0),
    ("21:30","Norway","Czech Republic",0,0,0,0),
    ("21:45","Morocco","USA",0,1,0,2),
    ("22:00","France","Portugal",2,1,4,1),
    ("22:15","Hungary","Qatar",1,0,1,1),
    ("22:30","Italy","Poland",0,0,0,3),
    ("22:45","Netherlands","Ukraine",1,1,1,4),
    ("23:00","Czech Republic","Argentina",1,1,1,1),
    ("23:15","Romania","Belgium",0,1,1,1),
    ("23:30","England","Netherlands",1,0,3,0),
    ("23:45","Wales","Mexico",0,1,0,1),
    ("00:00","Sweden","Ghana",0,1,0,1),
    ("00:15","Belgium","USA",6,1,9,1),
    ("00:30","Italy","Spain",0,2,1,6),
    ("00:45","Sweden","Finland",0,1,1,1),
]

# gol babak 2 = (ft_h - fh_h) + (ft_a - fh_a)
sh = [(r[5]-r[3])+(r[6]-r[4]) for r in data]  # second half goals per match
ada = [1 if g>0 else 0 for g in sh]  # 1 = ada gol babak 2

n = len(ada)
total_ada = sum(ada)
print(f"Total match: {n}")
print(f"Match ADA gol babak 2 : {total_ada}/{n} = {total_ada/n*100:.1f}%")
print(f"Match TANPA gol babak 2: {n-total_ada}/{n} = {(n-total_ada)/n*100:.1f}%")
print(f"\n=> Peluang DASAR ada gol babak 2 (tanpa lihat history) = {total_ada/n*100:.1f}%\n")

# UJI KLAIM: setelah 2 match beruntun TANPA gol babak 2,
# berapa peluang match ke-3 ADA gol babak 2?
hit = 0      # match ke-3 ada gol
trials = 0   # berapa kali kondisi "2 beruntun tanpa gol" terjadi
print("Mencari urutan: [tanpa][tanpa] -> match ke-3 = ?")
for i in range(n-2):
    if ada[i]==0 and ada[i+1]==0:
        trials += 1
        hasil = "ADA gol" if ada[i+2]==1 else "tanpa gol"
        print(f"  match {i+1},{i+2} tanpa gol -> match {i+3}: {hasil}")
        if ada[i+2]==1:
            hit += 1

print(f"\nKondisi '2 beruntun tanpa gol babak 2' terjadi: {trials} kali")
if trials>0:
    print(f"Match ke-3 ADA gol babak 2: {hit}/{trials} = {hit/trials*100:.1f}%")
    print(f"Bandingkan dengan peluang dasar: {total_ada/n*100:.1f}%")
else:
    print("Kondisi ini hampir tidak pernah terjadi di sample ini.")

# Sekalian uji kebalikannya: setelah 1 match tanpa gol, match berikut?
hit1=0; tr1=0
for i in range(n-1):
    if ada[i]==0:
        tr1+=1
        if ada[i+1]==1: hit1+=1
print(f"\nSetelah 1 match tanpa gol babak 2 -> berikutnya ada gol: {hit1}/{tr1} = {hit1/tr1*100:.1f}%")
print(f"(vs peluang dasar {total_ada/n*100:.1f}% -- kalau mirip = TIDAK ada efek)")
