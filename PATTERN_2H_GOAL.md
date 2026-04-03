# Pattern Prediksi Goal Babak Kedua — SABA Virtual PES/FC24

> Update terus setiap ada data baru dari goal_log.csv
> Data per 03/04/2026 (~250 match)

---

## TIER 1 — Pasti Ada Goal 2H (100%)

### P1: Gol Pertama 1H di Mnt 8'
**Record: 9/9 = 100%**
**Kondisi:** Gol pertama match muncul di mnt 8 babak pertama.
**Logika:** First goal sangat telat = match baru hidup, momentum lanjut ke 2H.
**League:** 16min dan 20min (15min hampir tidak pernah sampai mnt 8)

---

### P2: Selisih 2+ & Last Gol 1H Mnt 7' & Gap >= 3 Mnt
**Record: 4/4 = 100%**
**Kondisi:** Skor selisih 2+ (misal 3-1, 2-0) DAN gol terakhir 1H di mnt 7' DAN jarak antar gol tidak berdekatan (gap >= 3 mnt).
**Logika:** Tim tertinggal jauh, gol tersebar (bukan burst singkat), masih ada momentum masuk 2H.
**Contoh:** Gol mnt 2', 5', 7' skor 3-1 → pasti ada 2H gol. BUKAN mnt 5'&7' (gap 2, burst singkat).

---

### P3: AH + Gap Balas >= 3 Menit (15min & 16min saja)
**Record: 8/8 = 100%**
**Kondisi:** Away cetak duluan, Home balas dengan jeda >= 3 menit, skor seri 1-1. HANYA berlaku 15min dan 16min league.
**Logika:** Balas yang lambat = tensi masih tinggi, belum tuntas.
**Contoh:** Away mnt 2', Home mnt 6' (gap 4 mnt) → 100%. TIDAK berlaku 20min league (hanya 33%).

---

### P4: 1 Gol di 1H Mnt 8'+, AWAY Scorer, 16min & 20min
**Record: 3/3 = 100%**
**Kondisi:** Hanya 1 gol sepanjang 1H, gol itu di mnt 8 atau 9, dan yang cetak adalah AWAY. League 16min atau 20min.
**Logika:** Away unggul telat, Home wajib kejar di 2H.
**Catatan:** Kalau HOME yang cetak mnt 8-9 → tidak sekuat ini (Away bisa menyerah).

---

### P5: First Mnt 0-2' + Last Mnt 7'+ + Selisih <=1 + Gap >= 3 Mnt
**Record: 4/4 = 100%**
**Kondisi:** Gol pertama mnt 0-2', ada gol lagi di mnt 7'+, skor akhir 1H selisih max 1, jarak antar gol >= 3 mnt.
**Logika:** Match aktif dari awal sampai akhir 1H, ketat, belum ada yang menang.
**Contoh:** Away gol mnt 1', Home balas mnt 7' → skor 1-1 (gap 6 mnt) → pasti ada gol 2H.

---

### P6: Seri 1-1 + Gol Penyama di Mnt 7'
**Record: 7/7 = 100%**
**Kondisi:** Skor seri 1-1 (tepat 2 gol di 1H) DAN gol ke-2 (penyama) terjadi di mnt 7'. Gap berapapun tetap berlaku.
**Logika:** Disamakan di detik-detik akhir 1H = kedua tim masuk 2H dengan tensi maksimal.
**Contoh:** Away gol mnt 1', Home penyama mnt 7' (gap 6) → FT 2-2 ✅. Bahkan gap 1 mnt pun berlaku (Monaco vs Bayern gap 1 mnt → ada 2H gol).

---

### P7: Seri 1-1 + Gap Antar Gol >= 5 Menit
**Record: 9/9 = 100%**
**Kondisi:** Skor seri 1-1 (tepat 2 gol) DAN jarak antar kedua gol minimal 5 menit.
**Logika:** Gap panjang = perjuangan keras sebelum dibalas, kedua tim masih bertenaga penuh.
**Contoh:** Away mnt 1', Home mnt 6' (gap 5) → 100%. Away mnt 1', Home mnt 8' (gap 7) → 100%.

---

### P8: Away Comeback di 1H (Urutan Scorer HAA)
**Record: 5/5 = 100%**
**Kondisi:** Home cetak duluan di 1H, tapi Away balik unggul di akhir 1H (skor 1-2). Urutan scorer HAA.
**Logika:** Home yang sempat unggul lalu dilewati Away → Home mati-matian kejar di 2H.
**Contoh:** Home mnt 2', Away mnt 3'&5' → skor 1-2 → FT 1-4 ✅. Inter vs FC Koln: H mnt 2', A mnt 4'&5' → FT 3-3 ✅.

---

### P9: AH Seri 1-1 + Gap >= 5 Menit
**Record: 4/4 = 100%**
**Kondisi:** Away cetak duluan, Home balas (seri 1-1), gap antara kedua gol >= 5 menit.
**Logika:** Subset dari P7, tapi spesifik urutan Away-Home. Kombinasi terkuat.
**Contoh:** Away mnt 1', Home mnt 6' (gap 5, seq AH) → 100%.

---

## TIER 2 — Sangat Mungkin Ada Goal 2H (87-98%)

### P10: 0-0 di 1H
**Record: 44/45 = 98%**
**Kondisi:** Tidak ada gol sama sekali di 1H.
**Logika:** Kedua tim menyerang penuh di 2H karena tidak ada yang unggul.
**Catatan:** Dipertahankan meski bukan 100% karena sample besar (45 data).

---

### P11: Switches 2+ (Balas Membalas >= 2x)
**Record: 16/17 = 94%**
**Kondisi:** Ada minimal 2x pergantian siapa yang cetak gol di 1H (misal H→A→H atau A→H→A).
**Logika:** Match kompetitif dengan banyak aksi balas, tensi tinggi lanjut ke 2H.
**Pengecualian:** Gagal hanya Getafe vs Sevilla (AHA mnt 3,4,5 — semua berdekatan, burst singkat).

---

### P12: Total Gol 1H >= 4
**Record: 13/14 = 93%**
**Kondisi:** Ada 4 gol atau lebih di babak pertama.
**Logika:** Match sangat aktif di 1H = pasti lanjut di 2H.

---

### P13: First Mnt 0-2' + Last Mnt 7'+
**Record: 17/18 = 94%**
**Kondisi:** Gol pertama di mnt 0-2' DAN gol terakhir di mnt 7'+, minimal 2 gol.
**Catatan:** Versi lebih longgar dari P5 (tanpa syarat selisih dan gap).

---

### P14: Seri + Gap Antar Gol >= 4 Menit
**Record: 13/14 = 93%**
**Kondisi:** Skor seri di akhir 1H DAN jarak antar gol minimal 4 menit.

---

---

## TIER 3 — SKIP (Jangan Bet)

| Kondisi | Data | Penjelasan |
|---|---|---|
| Skor 2-0 atau 3-0 di 1H, gol berdekatan (burst) | 0% late gol | Tim tertinggal sudah menyerah |
| 1 gol 1H di mnt 1-6, HOME unggul | Rendah | Home bertahan tipis |
| AH seri 1-1, gap <= 2 mnt | 75-80% | Burst singkat, momentum cepat habis |
| 20min league + AH gap 3+ mnt | 33% | Pattern ini TIDAK berlaku di 20min |

**Contoh NO 2H goal:**
- Bayern vs Leverkusen: 2-0 gol mnt 0'&1' (burst) → FT 2-0
- Monaco vs Man City: 2-0 gol mnt 5'&7' (burst) → FT 2-0
- Qatar vs Korea: 1-0 gol mnt 9', HOME scorer → FT 1-0

---

## Catatan Per League

| League | Durasi 2H | Last gol paling sering |
|---|---|---|
| 15 Mins (PES Club) | ~7-8 mnt real | 2H 6' (20%) dan 2H 7' (18%) |
| 16 Mins (FC24) | ~8 mnt real | 2H 7' (23%), 2H 8' (12%) |
| 20 Mins (PES Intl) | ~10 mnt real | 2H 7' (19%), 2H 9' (15%), 2H 10' (10%) |

**Catatan league:**
- P1, P4: tidak berlaku 15min (mnt 8 hampir tidak ada)
- P3 (AH gap 3+): HANYA 15min & 16min, jangan pakai untuk 20min
- 20min league punya waktu 2H lebih panjang → lebih banyak kesempatan late gol

---

## Summary Akurasi

| # | Pattern | Record | Akurasi |
|---|---|---|---|
| P1 | Gol pertama 1H di mnt 8' | 9/9 | **100%** |
| P2 | Selisih 2+ & last mnt 7' & gap >=3 | 4/4 | **100%** |
| P3 | AH gap >=3 mnt, 15min/16min | 8/8 | **100%** |
| P4 | 1 gol mnt 8'+, AWAY, 16/20min | 3/3 | **100%** |
| P5 | First 0-2' + last 7'+ + selisih<=1 + gap>=3 | 4/4 | **100%** |
| P6 | Seri 1-1 + gol penyama mnt 7' | 7/7 | **100%** |
| P7 | Seri 1-1 + gap >= 5 mnt | 9/9 | **100%** |
| P8 | Away comeback 1H (HAA) | 5/5 | **100%** |
| P9 | AH seri 1-1 + gap >= 5 mnt | 4/4 | **100%** |
| P10 | 0-0 di 1H | 44/45 | **98%** |
| P11 | Switches 2+ (balas >=2x) | 16/17 | **94%** |
| P12 | Total gol 1H >= 4 | 13/14 | **93%** |
| P13 | First 0-2' + last 7'+ | 17/18 | **94%** |
| P14 | Seri + gap >= 4 mnt | 13/14 | **93%** |
