-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 29, 2025 at 09:00 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `mitm6294_penjemputan_siswa`
--

-- --------------------------------------------------------

--
-- Table structure for table `kelas`
--

CREATE TABLE `kelas` (
  `id` int(11) NOT NULL,
  `nama_kelas` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `kelas`
--

INSERT INTO `kelas` (`id`, `nama_kelas`) VALUES
(1, 'Kelas 1A'),
(2, 'Kelas 1B'),
(3, 'Kelas 2A'),
(5, 'Kelas 2B');

-- --------------------------------------------------------

--
-- Table structure for table `pengaturan`
--

CREATE TABLE `pengaturan` (
  `id` int(11) NOT NULL,
  `nama_sekolah` varchar(100) DEFAULT 'Nama Sekolah Default',
  `logo_sekolah` varchar(255) DEFAULT NULL,
  `header_image` varchar(255) DEFAULT NULL,
  `footer_text` varchar(255) DEFAULT '© Aplikasi Penjemputan Siswa',
  `header_color` varchar(7) DEFAULT '#FFFFFF' COMMENT 'Warna latar header (hex)',
  `footer_color` varchar(7) DEFAULT '#333333' COMMENT 'Warna latar footer (hex)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `pengaturan`
--

INSERT INTO `pengaturan` (`id`, `nama_sekolah`, `logo_sekolah`, `header_image`, `footer_text`, `header_color`, `footer_color`) VALUES
(1, 'SD Islam Al Azhar Solo Baru', 'assets/uploads/1748311238_ICON_WEB.png', 'assets/uploads/1748311238_Cover-Masjid-dan-Gedung-Sekolah-2024-versi-terbaru-2048x709.jpg', '@2024 SD Al Azhar Solo Baru - Aplikasi Jemput Siswa', '#9914ff', '#9914ff');

-- --------------------------------------------------------

--
-- Table structure for table `pengumuman_sekolah`
--

CREATE TABLE `pengumuman_sekolah` (
  `id` int(11) NOT NULL,
  `judul_pengumuman` varchar(255) DEFAULT NULL,
  `isi_pengumuman` text NOT NULL,
  `tanggal_mulai` datetime NOT NULL,
  `tanggal_kadaluarsa` datetime DEFAULT NULL,
  `dibuat_oleh` int(11) DEFAULT NULL,
  `timestamp_dibuat` timestamp NOT NULL DEFAULT current_timestamp(),
  `timestamp_diupdate` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `pengumuman_sekolah`
--

INSERT INTO `pengumuman_sekolah` (`id`, `judul_pengumuman`, `isi_pengumuman`, `tanggal_mulai`, `tanggal_kadaluarsa`, `dibuat_oleh`, `timestamp_dibuat`, `timestamp_diupdate`) VALUES
(1, 'Libur', 'hari ini libur ya sampe bsok', '2025-05-29 06:35:00', '2025-05-29 06:48:00', 1, '2025-05-28 23:36:10', '2025-05-28 23:43:36');

-- --------------------------------------------------------

--
-- Table structure for table `siswa`
--

CREATE TABLE `siswa` (
  `id` int(11) NOT NULL,
  `nama_siswa` varchar(100) NOT NULL,
  `kelas_id` int(11) NOT NULL,
  `id_wali_murid` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `siswa`
--

INSERT INTO `siswa` (`id`, `nama_siswa`, `kelas_id`, `id_wali_murid`) VALUES
(8, 'Anas Asyrofi', 1, 10);

-- --------------------------------------------------------

--
-- Table structure for table `status_kehadiran`
--

CREATE TABLE `status_kehadiran` (
  `id` int(11) NOT NULL,
  `siswa_id` int(11) NOT NULL,
  `tanggal` date NOT NULL,
  `status` varchar(50) NOT NULL,
  `dicatat_oleh_user_id` int(11) DEFAULT NULL,
  `timestamp_catat` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `status_kehadiran`
--

INSERT INTO `status_kehadiran` (`id`, `siswa_id`, `tanggal`, `status`, `dicatat_oleh_user_id`, `timestamp_catat`) VALUES
(3, 8, '2025-05-27', 'Belum Diisi', 8, '2025-05-27 03:12:51'),
(5, 8, '2025-05-28', 'Proses Belajar', 8, '2025-05-27 22:44:50'),
(6, 8, '2025-05-29', 'Proses Belajar', 8, '2025-05-28 23:37:22');

-- --------------------------------------------------------

--
-- Table structure for table `status_penjemputan`
--

CREATE TABLE `status_penjemputan` (
  `id` int(11) NOT NULL,
  `siswa_id` int(11) NOT NULL,
  `status` enum('masuk_kelas','proses_belajar','perjalanan_jemput','lima_menit_sampai','sudah_sampai_sekolah','sudah_dijemput','tidak_hadir_info_jemput') NOT NULL,
  `nama_penjemput` varchar(100) DEFAULT NULL,
  `waktu_update_status` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `catatan` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `status_penjemputan`
--

INSERT INTO `status_penjemputan` (`id`, `siswa_id`, `status`, `nama_penjemput`, `waktu_update_status`, `catatan`) VALUES
(5, 8, 'perjalanan_jemput', 'Agus', '2025-05-28 05:45:12', 'Proses jemput dimulai. Penjemput: Agus. Catatan: Pajero'),
(6, 8, 'sudah_sampai_sekolah', 'Agus', '2025-05-28 05:45:15', 'Status diperbarui. Penjemput: Agus.');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','wali_kelas','wali_murid') NOT NULL,
  `kelas_id` int(11) DEFAULT NULL,
  `nama` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `role`, `kelas_id`, `nama`) VALUES
(1, 'admin', '21232f297a57a5a743894a0e4a801fc3', 'admin', NULL, 'Administrator Utama'),
(3, 'walikelas_1b', '4aad94abb2f82d854784f554533a0a06', 'wali_kelas', 2, 'Bapak Guru Agus (1B)'),
(4, 'wali_budi', 'f7452d55d915a679116c113943d6019d', 'wali_murid', NULL, 'Bapak Budi Hartono'),
(5, 'wali_rini', 'f7452d55d915a679116c113943d6019d', 'wali_murid', NULL, 'Ibu Rini Susanti'),
(6, 'wali_dewi', '1f9d7c0634139672f92c693488f78032', 'wali_murid', NULL, 'Ibu Dewi Lestari'),
(7, 'ortu_adi', 'f7452d55d915a679116c113943d6019d', 'wali_murid', NULL, 'Bapak Eko (Ortu Adi)'),
(8, 'yuli', '4a01a05a350e1c7710c989f1211245a8', 'wali_kelas', 1, 'Yuliana'),
(9, 'Anas', '3506e5b8bbd66f5985a0d4531c5b4e1f', 'wali_murid', NULL, 'Anas Asyrofi'),
(10, 'Agus', '3506e5b8bbd66f5985a0d4531c5b4e1f', 'wali_murid', NULL, 'Agus Purnomo');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `kelas`
--
ALTER TABLE `kelas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nama_kelas` (`nama_kelas`);

--
-- Indexes for table `pengaturan`
--
ALTER TABLE `pengaturan`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `pengumuman_sekolah`
--
ALTER TABLE `pengumuman_sekolah`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_kadaluarsa` (`tanggal_kadaluarsa`),
  ADD KEY `idx_mulai` (`tanggal_mulai`),
  ADD KEY `fk_pengumuman_user` (`dibuat_oleh`);

--
-- Indexes for table `siswa`
--
ALTER TABLE `siswa`
  ADD PRIMARY KEY (`id`),
  ADD KEY `kelas_id` (`kelas_id`),
  ADD KEY `id_wali_murid` (`id_wali_murid`);

--
-- Indexes for table `status_kehadiran`
--
ALTER TABLE `status_kehadiran`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unik_kehadiran` (`siswa_id`,`tanggal`),
  ADD KEY `dicatat_oleh_user_id` (`dicatat_oleh_user_id`);

--
-- Indexes for table `status_penjemputan`
--
ALTER TABLE `status_penjemputan`
  ADD PRIMARY KEY (`id`),
  ADD KEY `siswa_id` (`siswa_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `kelas_id` (`kelas_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `kelas`
--
ALTER TABLE `kelas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `pengaturan`
--
ALTER TABLE `pengaturan`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `pengumuman_sekolah`
--
ALTER TABLE `pengumuman_sekolah`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `siswa`
--
ALTER TABLE `siswa`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `status_kehadiran`
--
ALTER TABLE `status_kehadiran`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `status_penjemputan`
--
ALTER TABLE `status_penjemputan`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `pengumuman_sekolah`
--
ALTER TABLE `pengumuman_sekolah`
  ADD CONSTRAINT `fk_pengumuman_user` FOREIGN KEY (`dibuat_oleh`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `siswa`
--
ALTER TABLE `siswa`
  ADD CONSTRAINT `siswa_ibfk_1` FOREIGN KEY (`kelas_id`) REFERENCES `kelas` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `siswa_ibfk_2` FOREIGN KEY (`id_wali_murid`) REFERENCES `users` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `status_kehadiran`
--
ALTER TABLE `status_kehadiran`
  ADD CONSTRAINT `status_kehadiran_ibfk_1` FOREIGN KEY (`siswa_id`) REFERENCES `siswa` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `status_kehadiran_ibfk_2` FOREIGN KEY (`dicatat_oleh_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `status_penjemputan`
--
ALTER TABLE `status_penjemputan`
  ADD CONSTRAINT `status_penjemputan_ibfk_1` FOREIGN KEY (`siswa_id`) REFERENCES `siswa` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`kelas_id`) REFERENCES `kelas` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
