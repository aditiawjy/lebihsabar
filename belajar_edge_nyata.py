# -*- coding: utf-8 -*-
"""
PELAJARAN: beda "pola palsu (RNG)" vs "pola nyata (ada edge)".
Pakai metode yang SAMA dengan analisa virtual football tadi:
  - backtest
  - out-of-sample validation (latih di set A, uji di set B)
  - hitung edge / ROI
  - Monte Carlo

Dua dunia dibandingkan:
  DUNIA 1: RNG tanpa memori        -> seperti virtual football (TIDAK bisa diprediksi)
  DUNIA 2: proses dengan memori    -> seperti harga pasar (PUNYA pola yang bertahan)
"""
import random
import statistics

random.seed(7)

# ============================================================
# DUNIA 1: RNG murni (tanpa memori) -- seperti virtual football
# ============================================================
def dunia_rng(n):
    """Tiap langkah acak total. Tidak ada hubungan antar langkah."""
    return [random.choice([-1, 1]) for _ in range(n)]

# ============================================================
# DUNIA 2: proses dengan MEAN-REVERSION (punya memori) -- seperti pasar
# ============================================================
def dunia_pasar(n):
    """
    Proses dengan MEAN-REVERSION pada RETURN (bukan harga).
    Aturan: return hari ini = -phi * return kemarin + noise.
    phi positif -> setelah turun cenderung naik (autokorelasi NEGATIF nyata).
    Ini pola yang BERTAHAN di data baru = edge sungguhan.
    """
    phi = 0.45            # kekuatan pola (cukup besar agar tak tertelan noise)
    deret = []
    prev = 0.0
    for _ in range(n):
        ret = -phi * prev + random.gauss(0, 1.0)
        deret.append(ret)
        prev = ret
    return deret

# ============================================================
# STRATEGI yang sama untuk dua dunia:
# "kalau langkah sebelumnya negatif, taruhan langkah berikut akan positif"
# (logika mean-reversion / beli saat turun)
# ============================================================
def backtest(deret):
    hit = 0; n = 0
    for i in range(1, len(deret)):
        if deret[i-1] < 0:        # SINYAL: sebelumnya turun
            n += 1
            if deret[i] > 0:      # benar kalau berikutnya naik
                hit += 1
    return hit, n

def autocorr(deret):
    """Korelasi langkah-i dengan langkah-(i+1). Inti dari 'ada memori atau tidak'."""
    xs = deret[:-1]; ys = deret[1:]
    mx, my = statistics.mean(xs), statistics.mean(ys)
    cov = sum((a-mx)*(b-my) for a,b in zip(xs,ys))/len(xs)
    sx = statistics.pstdev(xs); sy = statistics.pstdev(ys)
    return cov/(sx*sy) if sx*sy else 0

print("="*64)
print("METODE SAMA, DUA JENIS DATA -- mana yang punya pola NYATA?")
print("="*64)

for nama, generator in [("DUNIA 1: RNG (virtual football)", dunia_rng),
                        ("DUNIA 2: PASAR (mean-reversion)", dunia_pasar)]:
    # data latih (in-sample) dan data uji (out-of-sample) -- TERPISAH
    latih = generator(2000)
    uji   = generator(2000)

    h1,n1 = backtest(latih)
    h2,n2 = backtest(uji)
    wr1 = h1/n1*100; wr2 = h2/n2*100

    print(f"\n{nama}")
    print(f"  Autokorelasi          : {autocorr(latih):+.3f}")
    print(f"  Win-rate IN-SAMPLE    : {wr1:.1f}%")
    print(f"  Win-rate OUT-OF-SAMPLE: {wr2:.1f}%   (selisih {abs(wr1-wr2):.1f} poin)")
    if abs(autocorr(latih)) < 0.08:
        print(f"  -> Autokorelasi ~0  = TIDAK ada memori = pola PALSU (tak bisa diuangkan)")
    else:
        print(f"  -> Autokorelasi kuat = ADA memori = pola NYATA, bertahan di data baru")

print("\n" + "="*64)
print("INTI PELAJARAN")
print("="*64)
print("""
Perhatikan:
- DUNIA 1 (RNG): win-rate mentok ~50%, autokorelasi ~0.
  Pola APAPUN yang ditemukan akan AMBRUK di out-of-sample.
  --> persis seperti virtual football Anda. Tak ada edge.

- DUNIA 2 (PASAR): win-rate jelas >50% DAN BERTAHAN di out-of-sample,
  karena autokorelasinya nyata (negatif = mean reversion).
  --> ini namanya EDGE. Inilah yang dicari quant/trader.

Kuncinya: TES OUT-OF-SAMPLE adalah pemisah pola asli vs palsu.
Skill ini (yang baru Anda pakai) bernilai tinggi di:
  - analisa saham / crypto
  - arbitrase odds antar-bandar (matematis, bukan tebak-tebakan)
  - A/B testing produk, forecasting bisnis, deteksi anomali
""")
