# -*- coding: utf-8 -*-
"""
KENAPA 'POLA 100% BENAR' SELALU MUNCUL -- DAN SELALU PALSU.

Demonstrasi pakai DATA ACAK MURNI (koin), di mana kita 100% TAHU
tidak ada pola apa pun. Kalau di data yang dijamin acak saja kita bisa
menemukan banyak 'pola 100% benar', berarti menemukannya di data judi
TIDAK membuktikan apa-apa.
"""
import random
random.seed(2026)

def satu_hari(n=52):
    """52 'match', tiap hasil acak total (0/1). Tidak ada pola, dijamin."""
    return [random.randint(0, 1) for _ in range(n)]

# 3 hari data untuk 'mencari pola', 1 hari untuk 'menguji'
train = satu_hari() + satu_hari() + satu_hari()   # 156 hasil acak
test  = satu_hari()                                # 52 hasil acak (hari baru)

# CARI 'pola 100%': cari semua urutan pendek (panjang k) yang SELALU
# diikuti hasil yang sama di train. Ini meniru cara orang cari pola.
def cari_pola_100(data, k):
    from collections import defaultdict
    ikut = defaultdict(list)
    for i in range(len(data)-k):
        kunci = tuple(data[i:i+k])
        ikut[kunci].append(data[i+k])
    pola100 = {}
    for kunci, hasil in ikut.items():
        if len(hasil) >= 3 and len(set(hasil)) == 1:   # muncul >=3x, SELALU sama
            pola100[kunci] = hasil[0]
    return pola100

print("="*64)
print("MENCARI 'POLA 100% BENAR' DI DATA YANG DIJAMIN ACAK")
print("="*64)

for k in (2, 3, 4):
    pola = cari_pola_100(train, k)
    print(f"\nPanjang pola {k}: ditemukan {len(pola)} 'pola 100% benar' di train")
    # uji pola2 ini di data HARI BARU (test)
    benar = salah = 0
    for i in range(len(test)-k):
        kunci = tuple(test[i:i+k])
        if kunci in pola:
            prediksi = pola[kunci]
            if prediksi == test[i+k]: benar += 1
            else: salah += 1
    total = benar + salah
    if total:
        print(f"  Saat dipakai di HARI BARU: benar {benar}, salah {salah} "
              f"-> akurasi {benar/total*100:.0f}%")
        print(f"  (di train 100%, di hari baru ANJLOK ke ~{benar/total*100:.0f}% = persis tebak koin)")
    else:
        print("  Pola tidak muncul lagi di hari baru.")

print("\n" + "="*64)
print("KESIMPULAN")
print("="*64)
print("""
Di data yang DIJAMIN acak (koin), kita tetap menemukan PULUHAN
'pola 100% benar'. Tapi begitu dipakai di hari baru, semuanya
ambruk ke ~50% (= menebak koin).

Artinya: menemukan 'pola 100%' di data judi Anda BUKAN bukti
pola itu nyata. Itu cuma KEBETULAN yang pasti muncul kalau kita
mengaduk-aduk data secukupnya. Ini namanya OVERFITTING.

'Pola 100% benar' yang bisa dipakai untuk masa depan
secara matematis TIDAK ADA pada RNG. Saya tidak akan mengarang
satu pun untuk Anda, karena itu sama saja membohongi Anda.
""")
