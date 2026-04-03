# Pattern Prediksi Goal Babak Kedua — SABA Virtual PES/FC24

> Update terus setiap ada data baru dari goal_log.csv
> Data per 03/04/2026 (~222 match)
> Format sample: `[tanggal] Match | HT | Kondisi | Hasil`

---

## TIER 1 — Pasti Ada Goal 2H (Confidence 100%)

### Pattern #1: Gol Pertama 1H di Mnt 8'
**Logika:** First goal sangat telat di 1H = match baru "hidup", momentum langsung lanjut ke 2H.

**Record: 8/8 = 100%**

**Kondisi:** Gol pertama match muncul di menit 8 babak pertama.
**Sinyal masuk:** Deteksi first goal di 1H mnt 8' → antisipasi pasti ada gol 2H.

---

### Pattern #2: Selisih 2+ & Last Gol 1H Mnt 7'
**Logika:** Tim tertinggal jauh dan gol terakhir di menit akhir 1H = masih ada semangat kejar, lanjut di 2H.

**Record: 8/8 = 100%**

**Kondisi:** Skor selisih 2+ gol (misal 3-1, 2-0), dan gol terakhir 1H terjadi di mnt 7'.
**Contoh:** 1H → gol di mnt 2', 5', 7' dengan skor akhir 3-1 → pasti ada 2H gol.

---

### Pattern #3: AH + Gap Balas >= 3 Menit
**Logika:** Away cetak duluan, Home membalas tapi lambat (3+ menit kemudian) = masih ada tensi, belum tuntas.

**Record: 8/8 = 100%**

**Kondisi:** Away cetak gol duluan, Home balas dengan jeda >= 3 menit. Contoh: Away mnt 2', Home mnt 6' (gap 4 mnt).
**Sinyal masuk:** Deteksi urutan scorer AH dengan gap lebar → pasti lanjut di 2H.

---

### Pattern #4: 1 Gol Doang di 1H Mnt 8'+
**Logika:** Match sepi sepanjang 1H, tiba-tiba gol di detik akhir = momentum baru dimulai, lanjut ke 2H.

**Record: 10/10 = 100%**

**Kondisi:** Hanya ada 1 gol sepanjang 1H, dan gol itu terjadi di mnt 8 atau 9.
**Berlaku untuk:** 16min dan 20min league (15min league jarang sampai mnt 8).

---

### Pattern #5: First Mnt 0-2' + Last Mnt 7' + Selisih <=1
**Logika:** Gol sangat awal + gol sangat telat + skor masih ketat = match aktif dari awal sampai akhir 1H, belum ada yang menang.

**Record: 6/6 = 100%**

**Kondisi:** Gol pertama di mnt 0-2', ada gol lagi di mnt 7'+, dan skor akhir 1H selisih maksimal 1 gol (seri atau 1-0/0-1).
**Contoh:** Away gol mnt 1', Home balas mnt 7' → skor 1-1 → pasti ada gol 2H.

---

## TIER 2 — Sangat Mungkin Ada Goal 2H (Confidence 87-98%)

### Pattern #6: 0-0 di 1H
**Logika:** Tidak ada tim yang unggul → kedua tim menyerang penuh di 2H.

**Record: 44/45 = 98%** — hanya 2 match FT 0-0 total dari 45 data.

**Sinyal masuk:** HT 0-0 → langsung antisipasi gol di 2H.

---

### Pattern #7: First Mnt 0-2' + Last Mnt 7'+
**Logika:** Gol pertama sangat awal + gol terakhir sangat telat = match aktif rentang panjang, momentum lanjut ke 2H.

**Record: 17/18 = 94%**

**Kondisi:** Gol pertama di mnt 0-2' DAN gol terakhir di mnt 7'+, minimal 2 gol di 1H.

---

### Pattern #8: Seri + Gap Antar Gol >= 4 Menit
**Logika:** Skor seri + jarak antar gol lebar = kedua tim bergantian susah payah, masih kompetitif, belum selesai.

**Record: 13/14 = 93%**

**Kondisi:** Skor seri di akhir 1H (1-1, 2-2, dll) DAN jarak antar gol di 1H minimal 4 menit.
**Contoh:** Gol di mnt 1' dan 6' (gap 5 mnt), skor 1-1 → 93% ada gol 2H.

---

### Pattern #9: Seri + Last Gol Mnt 6'
**Logika:** Gol penyama di mnt 6 = match baru seri di saat-saat akhir, belum ada yang puas.

**Record: 9/10 = 90%**

**Kondisi:** Skor seri (1-1, 2-2, dll) dan gol terakhir 1H terjadi di mnt 6.

---

### Pattern #10: Balas 1x + Last Mnt 6'
**Logika:** Ada satu aksi balas gol di 1H, dan gol terakhir di mnt 6 = tensi masih tinggi masuk 2H.

**Record: 13/15 = 87%**

**Kondisi:** Ada minimal 1 balas-balasan gol di 1H, dan gol terakhir terjadi di mnt 6.

---

## TIER 3 — Kondisi TIDAK Ada 2H Goal (Skip Bet)

| Kondisi | Penjelasan |
|---|---|
| HT 1-0, hanya 1 gol, gol di mnt 1'-5' | Tim unggul tipis → bertahan, tidak menyerang |
| Away unggul 2+ gol saat HT + tidak aktif di 2H awal | Home sudah menyerah mental |
| Skor 2-0 atau 3-0 sebelum 2H mnt 7 | 0% late gol dari data |

**Contoh no-goal 2H:**
- Bayer vs Lille: HT 1-0 (goal 1H mnt 1' saja) → 2H 0-0
- Man City vs Roma: HT 1-0 (goal 1H mnt 7' saja) → 2H 0-0
- Bayern vs Leicester: HT 1-0 (goal 1H mnt 3' saja) → 2H 0-0

---

## Catatan Per League

| League | Durasi 2H | Mnt akhir 2H | Last gol paling sering |
|---|---|---|---|
| 15 Mins (PES Club) | ~7-8 mnt | 2H 6-7' | 2H 6' (20%) dan 2H 7' (18%) |
| 16 Mins (FC24) | ~8 mnt | 2H 7-8' | 2H 7' (23%) |
| 20 Mins (PES Intl) | ~10 mnt | 2H 9-10' | 2H 7' (19%), 2H 9' (15%) |

**Catatan penting:**
- Pattern #1 (mnt 8') dan #4 (1 gol mnt 8'+): **tidak berlaku untuk 15min league** (mnt 8 hampir tidak pernah ada)
- Untuk 15min league: window bet sangat sempit jika signal muncul di mnt 7'

---

## Kombinasi Signal Terkuat (Stack Pattern)

### Signal 3 Layer — Masuk Langsung:
1. Away leading HT **+**
2. Ada goal di 2H mnt 1'-5' **+**
3. Skor masih dalam 1 gol jarak

→ **Confidence sangat tinggi**

### Signal 2 Layer:
- HT 0-0 + Match sudah aktif (ada goal masuk di awal 2H) → **beli**
- HT 1-1 + Ada goal di 2H mnt 0'-3' → **beli**

---

## Summary Akurasi Per Pattern

| # | Pattern | Record | Akurasi |
|---|---|---|---|
| 1 | Gol pertama 1H di mnt 8' | 8/8 | **100%** |
| 2 | Selisih 2+ & last gol 1H mnt 7' | 8/8 | **100%** |
| 3 | AH + gap balas >= 3 mnt | 8/8 | **100%** |
| 4 | 1 gol doang di 1H mnt 8'+ | 10/10 | **100%** |
| 5 | First mnt 0-2' + last mnt 7' + selisih <=1 | 6/6 | **100%** |
| 6 | 0-0 di 1H | 44/45 | **98%** |
| 7 | First mnt 0-2' + last mnt 7'+ | 17/18 | **94%** |
| 8 | Seri + gap antar gol >= 4 mnt | 13/14 | **93%** |
| 9 | Seri + last gol mnt 6' | 9/10 | **90%** |
| 10 | Balas 1x + last mnt 6' | 13/15 | **87%** |

---

## Template Pencatatan Data Baru

```
[DD/MM/YYYY] | League | Home vs Away
HT Score: X-X
1H Goals: menit1, menit2, ...
2H Goals: menit1, menit2, ...
Pattern match: #1/#2/#3/... / tidak ada
Prediksi: [ada/tidak ada 2H goal]
Hasil: ✓ / ✗
```
