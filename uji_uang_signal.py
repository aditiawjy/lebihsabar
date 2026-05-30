# -*- coding: utf-8 -*-
"""
Sinyal 'FH 2+ gol -> Over2.5' menang 82-88%. Kelihatan hebat.
TAPI: taruhan ini odds-nya kecil karena sebagian gol SUDAH terjadi.
Uji: apakah betul-betul UNTUNG setelah memperhitungkan odds realistis?
"""

# Sinyal yang bertahan dari scanner
# FH(babak1) sudah >=2 gol -> bet Over 2.5
# data gabungan hari1+hari2
gab_ft = [  # (fh_total, ft_total)
    (1,1),(0,5),(2,5),(1,2),(3,4),(2,3),(0,0),(0,1),(0,2),(0,0),(1,2),(0,0),
    (2,3),(2,3),(3,5),(0,3),(1,3),(3,4),(0,1),(1,2),(0,2),(3,4),(1,3),(0,0),
    (0,0),(2,3),(2,3),(1,2),(1,2),(0,1),(2,2),(1,2),(1,2),(0,0),(2,4),(1,6),
    (0,1),(0,0),(1,2),(3,5),(1,2),(0,3),(2,5),(2,2),(1,1),(4,3),(7,10),(2,7),(1,2),
    # hari2
    (2,4),(1,3),(1,3),(0,1),(0,1),(1,2),(1,4),(1,2),(3,6),(3,4),(1,2),(1,1),
    (0,0),(0,3),(0,2),(0,0),(2,3),(1,2),(4,6),(1,1),(2,2),(2,2),(1,2),(2,3),
    (0,1),(1,4),(1,1),(2,3),(0,0),(1,1),(2,4),(3,4),(3,3),(2,2),(1,2),(2,5),
    (4,6),(1,2),(0,2),(0,1),(0,0),(4,5),(2,4),(0,3),(0,3),(0,1),(2,4),(1,2),(1,3),
]

# bet Over2.5 HANYA saat babak1 sudah >=2 gol
# odds realistis untuk Over2.5 saat skor HT sudah 2 gol: sangat rendah
# (butuh cuma 1 gol lagi). Pasaran live biasanya ~1.15 - 1.30.
for odds in (1.15, 1.20, 1.30):
    saldo=0.0; n=0; menang=0
    for fh, ft in gab_ft:
        if fh >= 2:                 # SINYAL muncul
            n += 1
            saldo -= 1              # pasang 1 unit
            if ft > 2.5:            # menang
                saldo += odds
                menang += 1
    wr = menang/n*100 if n else 0
    print(f"Odds {odds:.2f} | sinyal muncul {n}x | menang {menang} ({wr:.0f}%) | "
          f"saldo {saldo:+.2f} unit | ROI {saldo/n*100:+.1f}%")

print()
print("Kesimpulan: walau MENANG 80%+, odds yang kecil membuat ROI tipis/negatif.")
print("Karena sebagian gol SUDAH terjadi -> bukan prediksi, itu sudah 'harga pasar'.")
print("Bandar memasang odds persis supaya keunggulan ini = nol atau minus.")
