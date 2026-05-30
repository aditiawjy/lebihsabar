# -*- coding: utf-8 -*-
"""
Cek apakah ADA arbitrase di dalam SATU papan odds (Croatia v Brazil).
Metode: hitung 'overround' = jumlah implied probability tiap market.
  - overround < 100%  -> ADA arbitrase (untung pasti)
  - overround > 100%  -> TIDAK ada arb; selisih = margin bandar
Arbitrase NYATA butuh odds dari BANDAR BERBEDA, bukan satu papan.
"""

def overround(nama, odds):
    inv = [1/o for o in odds]
    s = sum(inv)*100
    print(f"  {nama:<32} overround = {s:6.1f}%   "
          f"({'ARB!' if s<100 else f'margin {s-100:.1f}%'})")
    return s

print("="*60)
print("ANALISA ARBITRASE -- satu papan odds Croatia v Brazil")
print("="*60)

# market 2-arah (fair = 100%)
print("\nMarket 2-arah (fair = 100%):")
overround("Over/Under 3.5",      [1.88, 1.88])
overround("Over/Under 1.75 (HT)",[1.97, 1.79])
overround("FT Odd/Even",         [1.90, 1.86])
overround("Both teams: Ya/Tidak",[1.27, 30.00])  # approx via BTTS? lihat bawah

# market 3-arah (fair = 100%)
print("\nMarket 3-arah (fair = 100%):")
overround("FT 1X2",              [2.29, 3.55, 2.31])
overround("HT 1X2",              [2.69, 2.46, 2.69])
overround("BTTS (Kedua/Satu/Tdk)",[1.27, 3.35, 30.00])
overround("Total Gol 0-1/2-3/4-6/7+",[7.00, 2.18, 1.76, 9.60])

# double chance (3 pasang, fair = 200%)
print("\nDouble Chance (fair = 200%):")
dc = [1.39, 1.15, 1.40]
s = sum(1/o for o in dc)*100
print(f"  Double Chance (1X/12/X2)         total = {s:6.1f}%   "
      f"(fair 200% -> margin {s-200:.1f}%)")

# HT/FT 9 kombinasi (fair = 100%)
print("\nHT/FT (9 kombinasi, fair = 100%):")
htft = [3.30,12.00,20.00,7.50,7.90,7.70,20.00,12.00,3.35]
overround("HT/FT semua kombinasi", htft)

print("\n" + "="*60)
print("KESIMPULAN")
print("="*60)
print("""
SEMUA market overround-nya DI ATAS 100% (atau 200% utk double chance).
Artinya: TIDAK ADA satu pun kombinasi taruhan di papan ini yang
menghasilkan untung pasti. Selisih di atas 100% itu = MARGIN BANDAR
yang Anda bayar, mau pasang kombinasi apa pun.

Arbitrase SEJATI hanya muncul kalau:
  - Bandar A pasang Croatia 2.29  DAN
  - Bandar B pasang Brazil 2.40 (untuk match SAMA)
  -> 1/2.29 + 1/2.40 bisa < 100% -> baru untung pasti.

Masalahnya untuk VIRTUAL FOOTBALL:
  - Game ini hasil RNG internal operator. Hampir selalu cuma
    DISEDIAKAN SATU operator -> tidak ada bandar ke-2 untuk dibandingkan.
  - Jadi arbitrase praktis MUSTAHIL di virtual football.
""")
