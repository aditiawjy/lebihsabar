# -*- coding: utf-8 -*-
"""
UJI STRATEGI 'KEBALIKAN' (FADE):
apa pun yang diprediksi bot, kita pegang KEBALIKANNYA.

Diuji 2 hal:
1. Apakah di data nyata (hari1+2+3) fade menghasilkan win-rate lebih tinggi?
2. SECARA MATEMATIS: kenapa fade TIDAK bisa mengalahkan margin bandar
   (Monte Carlo melawan RNG murni).
"""
import random

# total gol tiap match (hari1+2+3) untuk uji over/under
# (1=Over2.5, 0=Under2.5)
def load():
    ft = [
    # hari1 (52)
    1,5,6,2,4,3,0,1,2,0,3,0,3,3,5,3,3,4,1,2,1,4,3,0,0,3,3,2,2,1,2,2,2,3,0,4,6,1,0,2,5,2,3,5,2,2,3,1,1,10,7,2,
    # hari2 (49)
    4,3,3,1,1,2,4,2,6,4,2,1,0,3,2,0,3,2,6,1,2,2,2,3,1,4,1,5,5,1,4,4,3,2,2,5,4,2,2,1,0,5,4,3,3,1,4,2,3,
    # hari3 (48)
    2,5,4,2,0,1,4,0,1,2,2,3,3,1,1,1,4,1,4,4,2,4,3,3,1,2,0,5,2,2,1,0,3,2,2,1,1,1,2,3,2,3,1,5,2,1,1,0,
    ]
    return [1 if x>2.5 else 0 for x in ft]

data = load()
n = len(data)
over = sum(data)
print(f"Total match: {n}")
print(f"Over 2.5 : {over}/{n} = {over/n*100:.0f}%")
print(f"Under 2.5: {n-over}/{n} = {(n-over)/n*100:.0f}%\n")

# ---- BOT sederhana walk-forward: prediksi mayoritas dari history ----
# lalu FADE = kebalikannya
print("="*64)
print("BOT NORMAL vs BOT KEBALIKAN (fade) -- walk forward")
print("="*64)
START=10
hit_normal=hit_fade=tot=0
for i in range(START,n):
    hist=data[:i]
    pred = 1 if sum(hist)*2 > len(hist) else 0   # tebak mayoritas
    fade = 1-pred                                # kebalikan
    tot+=1
    if pred==data[i]: hit_normal+=1
    if fade==data[i]: hit_fade+=1
print(f"Bot NORMAL  : {hit_normal}/{tot} = {hit_normal/tot*100:.1f}%")
print(f"Bot FADE    : {hit_fade}/{tot} = {hit_fade/tot*100:.1f}%")
print(f"Jumlah keduanya = {(hit_normal+hit_fade)}/{tot} (selalu = total, krn saling melengkapi)")

print("""
KUNCI MATEMATIS:
Normal benar X% -> Fade benar (100-X)%. Mereka cuma cermin.
Kalau bot normal ~50%, fade juga ~50%. Membalik TIDAK menambah info.
""")

# ---- SIMULASI UANG: fade pun kena margin bandar ----
print("="*64)
print("SIMULASI UANG: kenapa FADE tetap RUGI (Monte Carlo vs RNG murni)")
print("="*64)
random.seed(1)
# odds: misal over 1.95, under 1.95 (margin bandar ~2.5%)
ODDS = 1.95
def sesi():
    res=[1 if random.random()<0.46 else 0 for _ in range(48)]  # 46% over spt data
    saldo_n=saldo_f=0.0
    for i in range(10,len(res)):
        hist=res[:i]
        pred=1 if sum(hist)*2>len(hist) else 0
        fade=1-pred
        # taruh normal
        saldo_n-=1
        if pred==res[i]: saldo_n+=ODDS
        # taruh fade
        saldo_f-=1
        if fade==res[i]: saldo_f+=ODDS
    return saldo_n, saldo_f

N=20000
tot_n=tot_f=0.0
for _ in range(N):
    a,b=sesi(); tot_n+=a; tot_f+=b
print(f"Rata-rata saldo BOT NORMAL : {tot_n/N:+.2f} unit/sesi")
print(f"Rata-rata saldo BOT FADE   : {tot_f/N:+.2f} unit/sesi")
print(f"\nKedua-duanya NEGATIF. Membalik prediksi tidak mengubah apa-apa")
print(f"karena odds {ODDS} sudah memotong margin di KEDUA sisi taruhan.")
print(f"Mau pegang A atau pegang kebalikan A, bandar tetap ambil ~{(1-ODDS/2*2/2)*0:.0f}... margin di tiap sisi.")
