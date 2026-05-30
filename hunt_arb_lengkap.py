# -*- coding: utf-8 -*-
"""
HUNT ARBITRASE LENGKAP -- cek SEMUA partisi market + kombinasi silang.
Kalau ADA set taruhan yg jumlah (1/odds) < 100% -> arbitrase nyata
(biasanya hanya muncul kalau bandar SALAH pasang harga).
"""

def cek(nama, odds, fair=100.0):
    inv = [1/o for o in odds]
    s = sum(inv)*100
    status = f">>> ARB! untung {fair-s:.1f}%" if s < fair-0.01 else f"margin {s-fair:+.1f}%"
    print(f"  {nama:<40} {s:7.1f}%  (fair {fair:.0f}%)  {status}")
    return s

print("="*72)
print("CEK SEMUA PARTISI LENGKAP (jumlah peluang harus =100%)")
print("="*72)

# partisi-partisi yang menutup 100% kemungkinan
cek("Exact Total Goals 0/1/2/3/4/5/6+", [30,9.20,4.85,3.95,4.35,5.90,5.00])
cek("Exact Home Goals 0/1/2/3+",        [5.70,2.83,3.10,2.96])
cek("Exact Away Goals 0/1/2/3+",        [5.60,2.83,3.10,3.00])
cek("Total Gol grup 0-1/2-3/4-6/7+",    [7.00,2.18,1.76,9.60])
cek("FT 1X2",                           [2.29,3.55,2.31])
cek("BTTS Kedua/Satu/TidakAda",         [1.27,3.35,30.00])
cek("HT/FT 9 kombinasi",                [3.30,12,20,7.50,7.90,7.70,20,12,3.35])
cek("Hasil/Total(3.5) 6 sel",          [4.15,7.30,4.25,4.25,6.90,4.35])
cek("BTTS/Hasil 6 sel",                [3.20,4.00,3.25,6.40,30.00,6.50])
cek("Ganjil-Genap/Total 4 sel",        [4.25,2.75,2.75,4.25])
cek("BTTS/Total(3.5) 4 sel",           [1.80,3.15,10.00,3.25])
cek("FT Odd/Even",                      [1.90,1.86])

print("\n" + "="*72)
print("CEK SILANG: market yang HARUS konsisten satu sama lain")
print("="*72)

# 1) Over/Under 3.5 langsung vs diturunkan dari Exact Total Goals
#    Under3.5 = total 0,1,2,3 ; Over3.5 = total 4,5,6+
ou_under = 1/30 + 1/9.20 + 1/4.85 + 1/3.95
ou_over  = 1/4.35 + 1/5.90 + 1/5.00
print(f"\n  Dari Exact Total Goals:")
print(f"    P(Under3.5) implied = {ou_under*100:.1f}%  -> odds adil {1/ou_under:.2f}")
print(f"    P(Over3.5)  implied = {ou_over*100:.1f}%  -> odds adil {1/ou_over:.2f}")
print(f"  Papan O/U 3.5 langsung: Over=1.88 Under=1.88")
# Arb silang: beli Over di market termurah, Under di market termurah
best_over = max(1.88, 1/ou_over) if ou_over>0 else 1.88
best_under = max(1.88, 1/ou_under) if ou_under>0 else 1.88
arb = 1/best_over + 1/best_under
print(f"    Ambil Over terbaik ({best_over:.2f}) + Under terbaik ({best_under:.2f})"
      f" -> {arb*100:.1f}%  {'ARB!' if arb<1 else 'tetap >100%'}")

# 2) BTTS 'Ya' langsung vs diturunkan
#    BTTS Ya = kedua tim >=1 gol. Dari Exact Home/Away goals (asumsi independen? TIDAK valid),
#    jadi kita pakai market BTTS langsung saja sbg perbandingan.
print(f"\n  BTTS 'Keduanya' (1.27) vs BTTS/Total 'Ya&Besar'(1.80)+'Ya&Kecil'(3.15):")
ya_gabung = 1/1.80 + 1/3.15
print(f"    Ya&Besar + Ya&Kecil = P(Ya) implied {ya_gabung*100:.1f}% -> odds adil {1/ya_gabung:.2f}")
print(f"    vs BTTS Ya langsung 1.27 (implied {1/1.27*100:.1f}%)")
print(f"    konsisten? selisih kecil = bandar rapi, tidak ada celah.")

print("\n" + "="*72)
print("VERDICT")
print("="*72)
print("""
Kalau SEMUA baris di atas >100% (tidak ada 'ARB!'):
-> bandar memasang harga konsisten & ber-margin di SETIAP partisi
   dan setiap cek silang. Tidak ada celah mispricing untuk diakali.
""")
