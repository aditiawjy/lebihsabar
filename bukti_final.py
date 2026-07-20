# -*- coding: utf-8 -*-
"""
BUKTI FINAL - Monte Carlo.
Pertanyaan: apakah profit +9.5% bot tadi BUKTI ada pola,
atau cuma keberuntungan sample kecil?

Cara uji paling jujur:
1. Buat mesin RNG MURNI ACAK (kita yang bikin, jadi 100% dijamin TANPA pola).
2. Lepas bot yang sama untuk menebak/bertaruh melawannya.
3. Ulangi 10.000 kali, lihat distribusi hasilnya.

Kalau bot SERING dapat profit melawan data yang kita TAHU acak,
maka profit +9.5% di data asli Anda TIDAK membuktikan apa-apa.
"""
import random

def generate_match():
    """Mesin acak: gol pakai distribusi Poisson-ish. TIDAK ADA pola, TIDAK ADA memori."""
    # rata2 ~1.3 gol per tim -> mirip data asli (~2.58 total)
    def pois(lam):
        # generator Poisson sederhana
        L = pow(2.718281828, -lam); k=0; p=1.0
        while True:
            k+=1; p*=random.random()
            if p<=L: return k-1
    fh_h, fh_a = pois(0.6), pois(0.6)
    sh_h, sh_a = pois(0.7), pois(0.7)
    return fh_h, fh_a, fh_h+sh_h, fh_a+sh_a

def outcome(ft_h, ft_a):
    if ft_h>ft_a: return 0
    if ft_h==ft_a: return 1
    return 2

odds = {0:2.5, 1:3.2, 2:2.5}

def satu_sesi(n_match=52, start=10):
    """Mainkan 1 sesi: bot belajar walk-forward, lalu hitung ROI."""
    matches = [generate_match() for _ in range(n_match)]
    outs = [outcome(m[2], m[3]) for m in matches]
    saldo = 0.0; tot = 0
    for i in range(start, n_match):
        hist = outs[:i]
        # bot tebak kelas paling sering (strategi 'pintar' yang sama)
        freq = [hist.count(0), hist.count(1), hist.count(2)]
        pred = freq.index(max(freq))
        saldo -= 1
        if pred == outs[i]:
            saldo += odds[pred]
        tot += 1
    return saldo, tot

# Jalankan 10.000 sesi melawan mesin yang DIJAMIN acak
random.seed(42)
N_SESI = 10000
hasil = [satu_sesi()[0] for _ in range(N_SESI)]

profit = sum(1 for s in hasil if s > 0)
profit_besar = sum(1 for s in hasil if s >= 4)  # >= +4 unit seperti hasil bot tadi
rata = sum(hasil)/len(hasil)

print("="*60)
print(f"MONTE CARLO: {N_SESI} sesi bot vs mesin DIJAMIN ACAK (tanpa pola)")
print("="*60)
print(f"\nRata-rata saldo akhir bot   : {rata:+.2f} unit per sesi")
print(f"Sesi di mana bot UNTUNG     : {profit}/{N_SESI} = {profit/N_SESI*100:.1f}%")
print(f"Sesi untung >= +4 unit      : {profit_besar}/{N_SESI} = {profit_besar/N_SESI*100:.1f}%")
print(f"\n>>> Ingat: hasil bot di data ASLI Anda tadi = +4.00 unit")
print(f">>> Di sini, melawan data yang DIJAMIN ACAK, hasil +4 unit")
print(f"    atau lebih terjadi pada {profit_besar/N_SESI*100:.1f}% sesi PURNI karena keberuntungan.")
print("\nKESIMPULAN:")
if profit_besar/N_SESI > 0.10:
    print(f"  Profit +4 unit itu SANGAT UMUM terjadi murni karena acak.")
    print(f"  Jadi 'kemenangan' bot di data Anda BUKAN bukti adanya pola.")
print(f"\n  Rata-rata jangka panjang = {rata:+.2f} unit/sesi (NEGATIF).")
print(f"  Inilah margin bandar yang memakan Anda. Mesin TIDAK bisa dipecahkan.")
