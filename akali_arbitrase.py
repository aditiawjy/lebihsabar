# -*- coding: utf-8 -*-
"""
PERCOBAAN 'MENGAKALI' -- serius, bukan basa-basi.
Strategi paling agresif: DUTCHING = pasang SEMUA kemungkinan hasil sekaligus
supaya satu di antaranya PASTI menang.

Kalau total biaya untuk menjamin balik 1 unit < 1 unit -> ARBITRASE NYATA.
Kalau >= 1 unit -> mustahil untung (itu margin bandar).

Kita uji di market Correct Score (lengkap, krn ada 'AOS' = skor lainnya).
"""

# Correct Score FT (dari papan Anda). AOS = Any Other Score (menutup sisa).
correct_score = {
    "1-0":16, "0-0":30, "0-1":16, "2-0":18, "1-1":9.2, "0-2":18,
    "2-1":10, "2-2":11, "1-2":10, "3-0":29, "3-3":26, "0-3":29,
    "3-1":16, "4-4":134, "1-3":16, "3-2":17, "AOS":9.6, "2-3":17,
    "4-0":63, "0-4":65, "4-1":35, "1-4":35, "4-2":36, "2-4":37,
    "4-3":59, "3-4":60,
}

def dutch(nama, odds_map):
    # untuk jamin balik 1 unit pada outcome i -> pasang 1/odds_i
    total = sum(1/o for o in odds_map.values())
    print(f"\n{nama}")
    print(f"  jumlah outcome     : {len(odds_map)}")
    print(f"  total biaya (sum 1/odds) = {total:.4f} unit utk jaminan balik 1 unit")
    if total < 1:
        print(f"  >>> ARBITRASE! untung pasti {(1-total)*100:.1f}% <<<")
    else:
        print(f"  >>> RUGI pasti {(total-1)*100:.1f}%. Tidak ada arb. (itu margin bandar)")
    return total

print("="*62)
print("PERCOBAAN MENGAKALI: dutching (pasang semua outcome)")
print("="*62)

dutch("Correct Score FT (semua skor + AOS)", correct_score)

# coba juga market lain yang partisinya lengkap
dutch("FT 1X2", {"Croatia":2.29, "Seri":3.55, "Brazil":2.31})
dutch("Total Gol 0-1/2-3/4-6/7+", {"0-1":7.00,"2-3":2.18,"4-6":1.76,"7+":9.60})
dutch("HT/FT (9 kombinasi)", {
    "HH":3.30,"HD":12.00,"HA":20.00,"DH":7.50,"DD":7.90,
    "DA":7.70,"AH":20.00,"AD":12.00,"AA":3.35})
dutch("Skor seri grup", {"Seri(bukan0-0)":4.00,"0-0":30.00,"non-draw?":None} 
      if False else {"placeholder":1})  # skip, tidak lengkap

print("\n" + "="*62)
print("AKALI LEVEL 2: campur antar-market yang OVERLAP")
print("="*62)
print("""
Ide: 'Over 3.5' (1.88) + dutching skor-skor di bawah 3.5 gol.
Tapi tiap market sudah ber-margin >100%, jadi menggabungkannya
hanya MENUMPUK margin, bukan menghapusnya. Matematika no-arbitrage:
kalau setiap market konsisten dgn SATU ukuran peluang yg jumlahnya
>100%, maka TIDAK ADA kombinasi linear yg menghasilkan untung pasti.
""")

print("="*62)
print("VERDICT")
print("="*62)
print("""
Mengakali = MUSTAHIL di satu papan ini. Tiap upaya dutching biayanya
>1 unit (rugi pasti). Bukan karena triknya kurang pintar -- tapi
karena bandar sudah memasang harga supaya semua jalan tertutup.

Satu-satunya arbitrase NYATA butuh BANDAR KEDUA. Di virtual football
RNG (satu operator), bandar kedua tidak ada. Jadi: tidak bisa.
""")
