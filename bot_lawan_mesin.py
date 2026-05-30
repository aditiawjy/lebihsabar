# -*- coding: utf-8 -*-
"""
BOT vs MESIN RNG.
Kita pakai "otak mesin" (machine learning) untuk melawan mesin virtual.
Metode: WALK-FORWARD VALIDATION.
- Bot HANYA boleh belajar dari match-match SEBELUMNYA.
- Lalu memprediksi match berikutnya yang BELUM PERNAH dilihat.
- Ini cara jujur menguji apakah ada pola yang bisa diuangkan.

Kalau bot bisa menang konsisten di atas baseline -> ada pola.
Kalau bot cuma setara baseline / kalah -> mesinnya acak, tidak bisa dipecahkan.
"""
import statistics

# (jam, menit, home, away, fh_h, fh_a, ft_h, ft_a)
data = [
    ("12:00",0,"Argentina","Ghana",0,1,0,1),("12:15",15,"Belgium","USA",0,0,5,0),
    ("12:30",30,"Portugal","France",2,0,4,1),("12:45",45,"Mexico","Sweden",0,1,0,2),
    ("13:00",0,"Finland","Germany",2,1,2,2),("13:15",15,"Morocco","Denmark",1,1,1,2),
    ("13:30",30,"Hungary","Norway",0,0,0,0),("13:45",45,"Qatar","Scotland",0,0,0,1),
    ("14:00",0,"Belgium","Mexico",0,0,1,1),("14:15",15,"Italy","Croatia",0,0,0,0),
    ("14:30",30,"Netherlands","England",1,0,2,0),("14:45",45,"Romania","Denmark",0,0,0,0),
    ("15:00",0,"Argentina","Ukraine",0,2,0,3),("15:15",15,"USA","Ghana",2,0,3,0),
    ("15:30",30,"Netherlands","Spain",1,2,1,4),("15:45",45,"Wales","Denmark",0,0,0,3),
    ("16:00",0,"Italy","Czech Republic",1,0,2,1),("16:15",15,"Sweden","Morocco",1,2,2,2),
    ("16:30",30,"Poland","Norway",0,0,1,0),("16:45",45,"Finland","Mexico",0,1,0,2),
    ("17:00",0,"Romania","Scotland",0,0,1,1),("17:15",15,"Sweden","Argentina",2,1,2,2),
    ("17:30",30,"Mexico","Croatia",0,1,1,2),("17:45",45,"England","Belgium",0,0,0,0),
    ("18:00",0,"Spain","Germany",0,0,0,0),("18:15",15,"Ghana","Portugal",0,2,1,2),
    ("18:30",30,"Denmark","Morocco",1,1,1,2),("18:45",45,"Czech Republic","Qatar",1,0,2,0),
    ("19:00",0,"Hungary","New Zealand",0,1,0,2),("19:15",15,"France","Netherlands",0,0,0,1),
    ("19:30",30,"Argentina","Ukraine",2,0,2,0),("19:45",45,"USA","Wales",0,0,0,2),
    ("20:00",0,"Netherlands","Germany",1,0,1,1),("20:15",15,"Spain","Italy",0,1,2,1),
    ("20:30",30,"Finland","Poland",0,0,0,0),("20:45",45,"Belgium","Mexico",0,2,0,4),
    ("21:00",0,"Morocco","Sweden",1,0,4,2),("21:15",15,"Scotland","Denmark",0,0,1,0),
    ("21:30",30,"Norway","Czech Republic",0,0,0,0),("21:45",45,"Morocco","USA",0,1,0,2),
    ("22:00",0,"France","Portugal",2,1,4,1),("22:15",15,"Hungary","Qatar",1,0,1,1),
    ("22:30",30,"Italy","Poland",0,0,0,3),("22:45",45,"Netherlands","Ukraine",1,1,1,4),
    ("23:00",0,"Czech Republic","Argentina",1,1,1,1),("23:15",15,"Romania","Belgium",0,1,1,1),
    ("23:30",30,"England","Netherlands",1,0,3,0),("23:45",45,"Wales","Mexico",0,1,0,1),
    ("00:00",0,"Sweden","Ghana",0,1,0,1),("00:15",15,"Belgium","USA",6,1,9,1),
    ("00:30",30,"Italy","Spain",0,2,1,6),("00:45",45,"Sweden","Finland",0,1,1,1),
]

def outcome(r):
    """0=home menang, 1=seri, 2=away menang"""
    if r[6] > r[7]: return 0
    if r[6] == r[7]: return 1
    return 2

def over25(r):
    return 1 if (r[6]+r[7]) > 2.5 else 0

n = len(data)
labels_1x2 = [outcome(r) for r in data]
labels_ov  = [over25(r) for r in data]

print("="*60)
print("BOT (otak mesin) vs MESIN RNG  --  Walk-Forward Test")
print("="*60)

# ---------------------------------------------------------------
# STRATEGI BOT: pelajari semua data masa lalu, cari kelas paling sering,
# plus coba fitur menit-slot. Lalu prediksi match berikutnya.
# Bot mulai prediksi dari match ke-11 (butuh data belajar dulu).
# ---------------------------------------------------------------
START = 10

def bot_predict_1x2(history):
    """Bot belajar dari history: prediksi pakai kombinasi (menit-slot) + global."""
    # hitung frekuensi outcome global
    freq = [0,0,0]
    for r in history:
        freq[outcome(r)] += 1
    return freq.index(max(freq))  # tebak kelas paling sering muncul

def bot_predict_1x2_byslot(history, slot):
    """Bot lebih pintar: belajar pola per menit-slot."""
    freq = [0,0,0]
    for r in history:
        if r[1] == slot:
            freq[outcome(r)] += 1
    if sum(freq) < 3:  # data slot kurang, pakai global
        return bot_predict_1x2(history)
    return freq.index(max(freq))

def bot_predict_over(history):
    o = sum(over25(r) for r in history)
    return 1 if o*2 > len(history) else 0

# jalankan walk-forward
hit_global=0; hit_slot=0; hit_over=0; tot=0
for i in range(START, n):
    hist = data[:i]          # HANYA masa lalu
    real = data[i]           # match yang diprediksi (belum dilihat)

    p1 = bot_predict_1x2(hist)
    p2 = bot_predict_1x2_byslot(hist, real[1])
    p3 = bot_predict_over(hist)

    if p1 == outcome(real): hit_global += 1
    if p2 == outcome(real): hit_slot   += 1
    if p3 == over25(real):  hit_over   += 1
    tot += 1

print(f"\nJumlah match yang diprediksi (out-of-sample): {tot}\n")

print("--- HASIL PREDIKSI 1X2 (3 pilihan, tebak buta = 33%) ---")
print(f"Bot strategi global  : {hit_global}/{tot} = {hit_global/tot*100:.1f}%")
print(f"Bot strategi per-slot: {hit_slot}/{tot} = {hit_slot/tot*100:.1f}%")
print(f"Tebak acak teoretis  : 33.3%")

print("\n--- HASIL PREDIKSI OVER/UNDER 2.5 (2 pilihan, tebak buta = 50%) ---")
print(f"Bot: {hit_over}/{tot} = {hit_over/tot*100:.1f}%")

# ---------------------------------------------------------------
# UJI UANG: misal bot taruh tiap prediksi 1X2 dengan odds rata2 pasar.
# Virtual football odds: home~2.5, draw~3.2, away~2.5 (margin bandar ~7%)
# ---------------------------------------------------------------
print("\n" + "="*60)
print("SIMULASI UANG (pakai win-rate bot terbaik di atas)")
print("="*60)
odds = {0:2.5, 1:3.2, 2:2.5}  # odds tipikal mesin virtual
modal = 0.0
taruhan = 1.0  # 1 unit per match
for i in range(START, n):
    hist = data[:i]; real = data[i]
    pred = bot_predict_1x2_byslot(hist, real[1])
    modal -= taruhan
    if pred == outcome(real):
        modal += taruhan * odds[pred]
print(f"Total taruhan : {tot} unit")
print(f"Saldo akhir   : {modal:+.2f} unit")
print(f"ROI           : {modal/tot*100:+.1f}% per taruhan")
print("\n(ROI negatif = bot kalah melawan margin bandar, walaupun 'otak mesin')")
