# -*- coding: utf-8 -*-
"""
VERDICT FINAL.
Ambil pattern2 yang DULU terlihat 'paling kuat', uji di data HARI-5 (baru).
Pertanyaan user: adakah yang 100%? (cukup 2-3 pick/hari)
Jawaban dibiarkan datanya yang bicara.
"""
# hari-5: (slot, fh_h, fh_a, ft_h, ft_a)
hari5 = [
 (0,1,0,3,1),(15,1,2,1,3),(30,1,1,4,2),(45,0,0,0,0),(0,0,0,0,0),(15,0,0,0,0),
 (30,2,0,4,0),(45,1,1,1,2),(0,0,0,1,1),(15,1,0,1,0),(30,1,1,1,2),(45,0,1,0,3),
 (0,0,2,2,2),(15,0,1,0,2),(30,0,1,1,3),(45,2,1,3,1),(0,1,0,2,0),(15,0,2,0,2),
 (30,0,0,1,2),(45,2,1,2,1),(0,1,0,2,1),(15,0,0,0,0),(30,2,1,2,1),(45,2,0,3,0),
 (0,1,0,3,0),(15,1,1,2,2),(30,1,0,2,0),(45,1,0,2,0),(0,0,0,1,2),(15,0,0,1,0),
 (30,1,1,1,1),(45,0,3,2,3),(0,2,1,4,3),(15,0,0,4,1),(30,0,2,0,3),(45,0,0,1,0),
 (0,1,1,2,1),(15,0,1,1,2),(30,0,2,0,3),(45,2,0,2,2),(0,1,1,2,2),(15,1,0,1,4),
 (30,1,2,2,3),(45,0,1,1,1),(0,0,0,2,0),(15,0,0,0,0),(30,0,0,0,1),(45,0,2,0,6),
 (0,1,1,3,1),(15,1,2,1,2),(30,1,0,2,0),(45,1,0,1,1),
]

def fh(r): return r[1]+r[2]
def ft(r): return r[3]+r[4]
def res(r): return "H" if r[3]>r[4] else ("D" if r[3]==r[4] else "A")

n=len(hari5)
print(f"Data HARI-5 (baru): {n} match\n")
print("Uji pattern 'paling kuat' yang dulu kita temukan -- masih akurat?\n")

def cek(nama, trig, target):
    hit=tot=0
    for r in hari5:
        if trig(r):
            tot+=1
            if target(r): hit+=1
    wr = hit/tot*100 if tot else 0
    flag = " <-- 100%!" if wr==100 and tot>=3 else ""
    print(f"  {nama:<34} {hit:>2}/{tot:<2} = {wr:>5.0f}%{flag}")
    return wr,tot

cek("FH>=2 gol -> Over2.5",        lambda r: fh(r)>=2, lambda r: ft(r)>2.5)
cek("FH>=1 gol -> Over1.5",        lambda r: fh(r)>=1, lambda r: ft(r)>1.5)
cek("FH 0-0 -> Under2.5",          lambda r: fh(r)==0, lambda r: ft(r)<=2.5)
cek("FH 0-0 -> Under1.5",          lambda r: fh(r)==0, lambda r: ft(r)<=1.5)
cek("Slot:45 -> Away menang",      lambda r: r[0]==45, lambda r: res(r)=="A")
cek("Slot:15 -> Over2.5",          lambda r: r[0]==15, lambda r: ft(r)>2.5)
cek("Selalu ada gol babak-2",      lambda r: True,     lambda r: (ft(r)-fh(r))>0)
cek("Selalu Over1.5",              lambda r: True,     lambda r: ft(r)>1.5)

print("\n" + "="*56)
print("Adakah yang tembus 100% dan BISA dipakai sebelum match?")
print("="*56)
print("""
- 'FH>=2 -> Over2.5' dst: tinggi TAPI harus tunggu babak-1 selesai
  (bukan prediksi pra-match) + odds sudah kecil.
- Semua pattern PRA-MATCH (slot/jam/tim): jauh dari 100%, naik-turun acak.

Tidak ada pattern pra-match 100%. Konsisten dengan 5 hari data.
""")
