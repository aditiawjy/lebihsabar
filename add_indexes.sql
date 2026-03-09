-- =====================================================
-- Index Optimization untuk Tabel Matches
-- =====================================================
-- Index ini akan mempercepat query next/last match dan agregasi
-- Jalankan script ini dengan: mysql -u user -p database < add_indexes.sql
-- =====================================================

USE REDACTED_DBNAME;

-- Cek dan hapus index yang sudah ada (jika ada duplikat)
DROP INDEX IF EXISTS idx_match_time ON matches;
DROP INDEX IF EXISTS idx_ft_home_match_time ON matches;
DROP INDEX IF EXISTS idx_league_match_time ON matches;
DROP INDEX IF EXISTS idx_home_team_match_time ON matches;
DROP INDEX IF EXISTS idx_away_team_match_time ON matches;

-- 1. Index untuk match_time (untuk sorting dan filtering berdasarkan waktu)
CREATE INDEX idx_match_time ON matches(match_time);

-- 2. Index untuk ft_home + match_time (untuk filtering skor FT dan waktu)
CREATE INDEX idx_ft_home_match_time ON matches(ft_home, match_time);

-- 3. Index untuk league + match_time (untuk query per liga dengan sorting waktu)
CREATE INDEX idx_league_match_time ON matches(league, match_time);

-- 4. Index untuk home_team + match_time (untuk next/last match tim home)
CREATE INDEX idx_home_team_match_time ON matches(home_team, match_time);

-- 5. Index untuk away_team + match_time (untuk next/last match tim away)
CREATE INDEX idx_away_team_match_time ON matches(away_team, match_time);

-- Tampilkan index yang sudah dibuat
SHOW INDEX FROM matches;

-- =====================================================
-- Selesai! Index berhasil ditambahkan
-- =====================================================
