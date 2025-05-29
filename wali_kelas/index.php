<?php
// wali_kelas/index.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Pastikan PhpSpreadsheet di-load
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// BAGIAN 1: INCLUDE DATABASE DAN VERIFIKASI AWAL
$conn_error_message_global = null;
if (file_exists(__DIR__ . '/../includes/db.php')) {
    include_once __DIR__ . '/../includes/db.php';
} else {
    $conn_error_message_global = "Error: File konfigurasi database tidak ditemukan.";
}

if (!isset($conn) && !$conn_error_message_global) {
    $conn_error_message_global = "Error: Koneksi database tidak terdefinisi setelah include db.php.";
} elseif (isset($conn) && is_object($conn) && property_exists($conn, 'connect_error') && $conn->connect_error && !$conn_error_message_global) {
    $conn_error_message_global = "Error: Gagal terhubung ke database: " . htmlspecialchars($conn->connect_error);
}

// VERIFIKASI SESI DAN ROLE
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'wali_kelas') {
    if (!$conn_error_message_global && !isset($_SESSION['flash_message_wali_kehadiran'])) {
         $_SESSION['flash_message_wali_kehadiran'] = ['type' => 'error', 'text' => 'Anda harus login sebagai wali kelas.'];
    }
    header('Location: ../login.php');
    exit;
}

$loggedInUserId = (int)$_SESSION['user_id'];
$kelas_id_wali = null;

// AMBIL KELAS ID WALI KELAS
if (isset($_SESSION['kelas_id_wali']) && !empty($_SESSION['kelas_id_wali'])) {
    $kelas_id_wali = (int)$_SESSION['kelas_id_wali'];
} else {
    if ($loggedInUserId && isset($conn) && !$conn_error_message_global) {
        $stmt_get_kelas = $conn->prepare("SELECT kelas_id FROM users WHERE id = ? AND role = 'wali_kelas'");
        if ($stmt_get_kelas) {
            $stmt_get_kelas->bind_param("i", $loggedInUserId);
            $stmt_get_kelas->execute();
            $result_kelas = $stmt_get_kelas->get_result();
            if ($row_kelas = $result_kelas->fetch_assoc()) {
                if (!empty($row_kelas['kelas_id'])) {
                    $kelas_id_wali = (int)$row_kelas['kelas_id'];
                    $_SESSION['kelas_id_wali'] = $kelas_id_wali;
                }
            }
            $stmt_get_kelas->close();
        } else {
            error_log("Wali Kelas Index: Gagal prepare statement (get kelas_id wali): " . $conn->error);
        }
    }
}

$tanggal_hari_ini = date('Y-m-d');
$message_data_page = null;
$students = [];
$nama_kelas_wali = "Kelas Tidak Ditemukan";
$errorMessage = $conn_error_message_global;

// FUNGSI PEMBANTU
if (!function_exists('formatStatusKehadiranWaliKelas')) {
    function formatStatusKehadiranWaliKelas($status_db) {
        if ($status_db === null || $status_db === '') {
             return 'Belum Diisi';
        }
        return htmlspecialchars($status_db);
    }
}

// LOGIKA PEMROSESAN FORM (POST REQUESTS)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ... (Logika POST Anda tetap sama seperti sebelumnya) ...
    if (!isset($conn) || (is_object($conn) && property_exists($conn, 'connect_error') && $conn->connect_error)) {
        $_SESSION['flash_message_wali_kehadiran'] = ['type' => 'error', 'text' => 'Koneksi database gagal. Tidak dapat memproses permintaan.'];
        header("Location: index.php");
        exit;
    }

    $redirect_needed = true;

    if (isset($_POST['action']) && isset($_POST['student_id']) && 
        in_array($_POST['action'], ['Masuk', 'Tidak Masuk', 'Izin', 'Sakit'])) {
        if ($loggedInUserId && $kelas_id_wali) {
            $student_id_to_update = (int)$_POST['student_id'];
            $action = $_POST['action'];
            $new_status = '';

            if ($action === 'Masuk') $new_status = 'Proses Belajar';
            elseif ($action === 'Tidak Masuk') $new_status = 'Tidak Hadir';
            elseif ($action === 'Izin') $new_status = 'Izin';
            elseif ($action === 'Sakit') $new_status = 'Sakit';

            if (!empty($new_status)) {
                $stmt_update_kehadiran = $conn->prepare(
                    "INSERT INTO status_kehadiran (siswa_id, tanggal, status, dicatat_oleh_user_id)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE status = VALUES(status), dicatat_oleh_user_id = VALUES(dicatat_oleh_user_id), timestamp_catat = NOW()"
                );
                if ($stmt_update_kehadiran) {
                    $stmt_update_kehadiran->bind_param("issi", $student_id_to_update, $tanggal_hari_ini, $new_status, $loggedInUserId);
                    if ($stmt_update_kehadiran->execute()) {
                        $_SESSION['flash_message_wali_kehadiran'] = ['type' => 'success', 'text' => 'Status siswa berhasil diperbarui.'];
                    } else {
                        error_log("MySQL Execute Error (update kehadiran): " . $stmt_update_kehadiran->error);
                        $_SESSION['flash_message_wali_kehadiran'] = ['type' => 'error', 'text' => 'Gagal memperbarui status siswa. Silakan coba lagi.'];
                    }
                    $stmt_update_kehadiran->close();
                } else {
                    error_log("MySQL Prepare Error (update kehadiran): " . $conn->error);
                    $_SESSION['flash_message_wali_kehadiran'] = ['type' => 'error', 'text' => 'Terjadi kesalahan sistem (DBP01).'];
                }
            } else {
                $_SESSION['flash_message_wali_kehadiran'] = ['type' => 'warning', 'text' => 'Aksi tidak dikenal atau status baru kosong.'];
            }
        } else {
            $_SESSION['flash_message_wali_kehadiran'] = ['type' => 'error', 'text' => 'Sesi tidak valid atau Anda tidak terhubung dengan kelas.'];
        }
    }
    elseif (isset($_POST['reset_individual_status']) && isset($_POST['student_id_to_reset'])) {
        if ($loggedInUserId && $kelas_id_wali) {
            $student_id_to_reset_individual = (int)$_POST['student_id_to_reset'];
            $status_default_reset_kehadiran = 'Belum Diisi';

            $stmt_cek_siswa = $conn->prepare("SELECT id FROM siswa WHERE id = ? AND kelas_id = ?");
            if ($stmt_cek_siswa) {
                $stmt_cek_siswa->bind_param("ii", $student_id_to_reset_individual, $kelas_id_wali);
                $stmt_cek_siswa->execute();
                $result_cek_siswa = $stmt_cek_siswa->get_result();
                if ($result_cek_siswa->num_rows === 1) {
                    $conn->begin_transaction();
                    try {
                        $stmt_reset_kehadiran_ind = $conn->prepare(
                            "INSERT INTO status_kehadiran (siswa_id, tanggal, status, dicatat_oleh_user_id)
                             VALUES (?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE status = VALUES(status), dicatat_oleh_user_id = VALUES(dicatat_oleh_user_id), timestamp_catat = NOW()"
                        );
                        if (!$stmt_reset_kehadiran_ind) throw new Exception("Gagal prepare reset kehadiran individual: " . $conn->error);

                        $stmt_reset_kehadiran_ind->bind_param("issi", $student_id_to_reset_individual, $tanggal_hari_ini, $status_default_reset_kehadiran, $loggedInUserId);
                        if (!$stmt_reset_kehadiran_ind->execute()) {
                            throw new Exception("Gagal reset status kehadiran individual: " . $stmt_reset_kehadiran_ind->error);
                        }
                        $stmt_reset_kehadiran_ind->close();

                        $stmt_delete_penjemputan_ind = $conn->prepare(
                            "DELETE FROM status_penjemputan 
                             WHERE siswa_id = ? AND DATE(waktu_update_status) = ?"
                        );
                        if (!$stmt_delete_penjemputan_ind) throw new Exception("Gagal prepare hapus penjemputan individual: " . $conn->error);
                        
                        $stmt_delete_penjemputan_ind->bind_param("is", $student_id_to_reset_individual, $tanggal_hari_ini);
                        if (!$stmt_delete_penjemputan_ind->execute()) {
                            // error_log("Gagal hapus status penjemputan siswa ID {$student_id_to_reset_individual}: " . $stmt_delete_penjemputan_ind->error);
                        }
                        $penjemputan_direset = $stmt_delete_penjemputan_ind->affected_rows > 0;
                        $stmt_delete_penjemputan_ind->close();

                        $conn->commit();
                        $pesan_sukses_ind = "Status siswa berhasil direset.";
                        if ($penjemputan_direset) {
                             $pesan_sukses_ind .= " Status penjemputan juga direset.";
                        }
                        $_SESSION['flash_message_wali_kehadiran'] = ['type' => 'success', 'text' => $pesan_sukses_ind];

                    } catch (Exception $e) {
                        $conn->rollback();
                        error_log("Exception saat reset individual: " . $e->getMessage());
                        $_SESSION['flash_message_wali_kehadiran'] = ['type' => 'error', 'text' => "Gagal mereset data siswa: Terjadi kesalahan."];
                    }
                } else {
                    $_SESSION['flash_message_wali_kehadiran'] = ['type' => 'error', 'text' => 'Siswa tidak valid atau bukan dari kelas Anda.'];
                }
                $stmt_cek_siswa->close();
            } else {
                $_SESSION['flash_message_wali_kehadiran'] = ['type' => 'error', 'text' => 'Gagal memvalidasi siswa.'];
            }
        } else {
            $_SESSION['flash_message_wali_kehadiran'] = ['type' => 'error', 'text' => 'Sesi tidak valid atau Anda tidak terhubung dengan kelas.'];
        }
    }
    elseif (isset($_POST['reset_total_absensi_hari_ini'])) {
        if ($loggedInUserId && $kelas_id_wali) {
            $status_default_reset_kehadiran = 'Belum Diisi';
            $siswa_ids_in_kelas = [];
            $stmt_get_siswa_kelas = $conn->prepare("SELECT id FROM siswa WHERE kelas_id = ?");
            if ($stmt_get_siswa_kelas) {
                $stmt_get_siswa_kelas->bind_param("i", $kelas_id_wali);
                $stmt_get_siswa_kelas->execute();
                $result_siswa_kelas = $stmt_get_siswa_kelas->get_result();
                while ($row_siswa = $result_siswa_kelas->fetch_assoc()) {
                    $siswa_ids_in_kelas[] = $row_siswa['id'];
                }
                $stmt_get_siswa_kelas->close();

                if (!empty($siswa_ids_in_kelas)) {
                    $conn->begin_transaction();
                    try {
                        $stmt_reset_kehadiran = $conn->prepare(
                            "INSERT INTO status_kehadiran (siswa_id, tanggal, status, dicatat_oleh_user_id)
                             VALUES (?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE status = VALUES(status), dicatat_oleh_user_id = VALUES(dicatat_oleh_user_id), timestamp_catat = NOW()"
                        );
                        if (!$stmt_reset_kehadiran) throw new Exception("Gagal prepare reset kehadiran: " . $conn->error);
                        $berhasil_reset_kehadiran_count = 0;
                        foreach ($siswa_ids_in_kelas as $siswa_id_reset) {
                            $stmt_reset_kehadiran->bind_param("issi", $siswa_id_reset, $tanggal_hari_ini, $status_default_reset_kehadiran, $loggedInUserId);
                            if ($stmt_reset_kehadiran->execute()) {
                                $berhasil_reset_kehadiran_count++;
                            } else { error_log("Gagal reset absensi siswa ID {$siswa_id_reset}: " . $stmt_reset_kehadiran->error); }
                        }
                        $stmt_reset_kehadiran->close();

                        $stmt_delete_penjemputan = $conn->prepare(
                            "DELETE FROM status_penjemputan 
                             WHERE siswa_id = ? AND DATE(waktu_update_status) = ?"
                        );
                        if (!$stmt_delete_penjemputan) throw new Exception("Gagal prepare hapus penjemputan: " . $conn->error);
                        $siswa_terpengaruh_penjemputan = 0;
                        foreach ($siswa_ids_in_kelas as $siswa_id_delete_penjemputan) {
                            $stmt_delete_penjemputan->bind_param("is", $siswa_id_delete_penjemputan, $tanggal_hari_ini);
                            if ($stmt_delete_penjemputan->execute()) {
                                if ($stmt_delete_penjemputan->affected_rows > 0) { $siswa_terpengaruh_penjemputan++; }
                            } else { error_log("Gagal hapus status penjemputan siswa ID {$siswa_id_delete_penjemputan}: " . $stmt_delete_penjemputan->error); }
                        }
                        $stmt_delete_penjemputan->close();

                        $conn->commit();
                        $pesan_sukses = "Absensi untuk {$berhasil_reset_kehadiran_count} siswa berhasil direset.";
                        if ($siswa_terpengaruh_penjemputan > 0) {
                            $pesan_sukses .= " Status penjemputan hari ini untuk {$siswa_terpengaruh_penjemputan} siswa juga telah direset/dihapus.";
                        }
                        $_SESSION['flash_message_wali_kehadiran'] = ['type' => 'success', 'text' => $pesan_sukses];

                    } catch (Exception $e) {
                        $conn->rollback();
                        error_log("Exception saat reset total: " . $e->getMessage());
                        $_SESSION['flash_message_wali_kehadiran'] = ['type' => 'error', 'text' => "Gagal mereset data: Terjadi kesalahan."];
                    }
                } else {
                    $_SESSION['flash_message_wali_kehadiran'] = ['type' => 'info', 'text' => 'Tidak ada siswa di kelas Anda untuk direset datanya.'];
                }
            } else {
                 $_SESSION['flash_message_wali_kehadiran'] = ['type' => 'error', 'text' => 'Gagal mengambil daftar siswa untuk reset.'];
            }
        } else {
            $_SESSION['flash_message_wali_kehadiran'] = ['type' => 'error', 'text' => 'Sesi tidak valid atau Anda tidak terhubung dengan kelas untuk mereset data.'];
        }
    } else {
        $redirect_needed = false;
    }

    if ($redirect_needed) {
        header("Location: index.php");
        exit;
    }
}

// --- SINKRONISASI STATUS KEHADIRAN SAAT LOAD HALAMAN ---
if ($loggedInUserId && $kelas_id_wali && !$errorMessage && isset($conn) && !($conn->connect_error ?? false) ) {
    // ... (Logika sinkronisasi tetap sama) ...
    $status_proses_belajar_kehadiran = 'Proses Belajar';
    $status_menunggu_penjemputan_kehadiran = 'Menunggu Penjemputan';
    $status_sudah_dijemput_penjemputan = 'sudah_dijemput';
    $status_kehadiran_pulang = 'Pulang';

    // 1. SINKRONISASI KE 'PULANG'
    $query_sync_pulang = "
        SELECT sk.siswa_id FROM status_kehadiran sk JOIN siswa s ON sk.siswa_id = s.id
        WHERE s.kelas_id = ? AND sk.tanggal = ? AND (sk.status = ? OR sk.status = ?)
          AND EXISTS ( SELECT 1 FROM status_penjemputan sp_latest WHERE sp_latest.siswa_id = sk.siswa_id AND sp_latest.status = ? AND DATE(sp_latest.waktu_update_status) = ? 
                AND sp_latest.waktu_update_status = (SELECT MAX(sp_inner.waktu_update_status) FROM status_penjemputan sp_inner WHERE sp_inner.siswa_id = sk.siswa_id AND DATE(sp_inner.waktu_update_status) = ?))";
    $stmt_sync_pulang = $conn->prepare($query_sync_pulang);
    if ($stmt_sync_pulang) {
        $stmt_sync_pulang->bind_param("issssss", $kelas_id_wali, $tanggal_hari_ini, $status_proses_belajar_kehadiran, $status_menunggu_penjemputan_kehadiran, $status_sudah_dijemput_penjemputan, $tanggal_hari_ini, $tanggal_hari_ini);
        $stmt_sync_pulang->execute(); $result_sync_pulang = $stmt_sync_pulang->get_result(); $siswa_ids_to_update_pulang = [];
        while ($row_sync_pulang = $result_sync_pulang->fetch_assoc()) { $siswa_ids_to_update_pulang[] = $row_sync_pulang['siswa_id']; }
        $stmt_sync_pulang->close();
        if (!empty($siswa_ids_to_update_pulang)) {
            $stmt_update_batch_pulang = $conn->prepare("UPDATE status_kehadiran SET status = ?, timestamp_catat = NOW() WHERE siswa_id = ? AND tanggal = ? AND (status = ? OR status = ?)");
            if ($stmt_update_batch_pulang) {
                $updated_count_pulang = 0;
                foreach ($siswa_ids_to_update_pulang as $s_id_pulang) {
                    $stmt_update_batch_pulang->bind_param("sisss", $status_kehadiran_pulang, $s_id_pulang, $tanggal_hari_ini, $status_proses_belajar_kehadiran, $status_menunggu_penjemputan_kehadiran);
                    if ($stmt_update_batch_pulang->execute() && $stmt_update_batch_pulang->affected_rows > 0) { $updated_count_pulang++; }
                } $stmt_update_batch_pulang->close();
                if ($updated_count_pulang > 0 && !isset($_SESSION['flash_message_wali_kehadiran'])) { $_SESSION['flash_message_wali_kehadiran_sync'] = ['type' => 'info', 'text' => "{$updated_count_pulang} siswa otomatis diupdate menjadi 'Pulang'."];}
            } else { error_log("MySQL Prepare Error (update batch pulang): " . $conn->error); }
        }
    } else { error_log("MySQL Prepare Error (sync pulang): " . $conn->error); }

    // 2. SINKRONISASI KE 'MENUNGGU PENJEMPUTAN'
    $status_penjemputan_proses = ['perjalanan_jemput', 'lima_menit_sampai', 'sudah_sampai_sekolah'];
    $placeholders_penjemputan = implode(',', array_fill(0, count($status_penjemputan_proses), '?'));
    $query_sync_menunggu = "
        SELECT sk.siswa_id FROM status_kehadiran sk JOIN siswa s ON sk.siswa_id = s.id
        WHERE s.kelas_id = ? AND sk.tanggal = ? AND sk.status = ? 
          AND EXISTS ( SELECT 1 FROM status_penjemputan sp_latest WHERE sp_latest.siswa_id = sk.siswa_id AND sp_latest.status IN ({$placeholders_penjemputan}) AND DATE(sp_latest.waktu_update_status) = ?
                AND sp_latest.waktu_update_status = (SELECT MAX(sp_inner.waktu_update_status) FROM status_penjemputan sp_inner WHERE sp_inner.siswa_id = sk.siswa_id AND DATE(sp_inner.waktu_update_status) = ?))";
    $stmt_sync_menunggu = $conn->prepare($query_sync_menunggu);
    if ($stmt_sync_menunggu) {
        $bind_params_types = "iss" . str_repeat('s', count($status_penjemputan_proses)) . "ss";
        $bind_params_values = array_merge([$kelas_id_wali, $tanggal_hari_ini, $status_proses_belajar_kehadiran], $status_penjemputan_proses, [$tanggal_hari_ini, $tanggal_hari_ini]);
        $ref_values = []; foreach($bind_params_values as $key => $value) { $ref_values[$key] = &$bind_params_values[$key]; }
        call_user_func_array(array($stmt_sync_menunggu, 'bind_param'), array_merge([$bind_params_types], $ref_values));
        $stmt_sync_menunggu->execute(); $result_sync_menunggu = $stmt_sync_menunggu->get_result(); $siswa_ids_to_update_menunggu = [];
        while ($row_sync_menunggu = $result_sync_menunggu->fetch_assoc()) { $siswa_ids_to_update_menunggu[] = $row_sync_menunggu['siswa_id']; }
        $stmt_sync_menunggu->close();
        if (!empty($siswa_ids_to_update_menunggu)) {
            $stmt_update_batch_menunggu = $conn->prepare("UPDATE status_kehadiran SET status = ?, timestamp_catat = NOW() WHERE siswa_id = ? AND tanggal = ? AND status = ?");
            if ($stmt_update_batch_menunggu) {
                $updated_count_menunggu = 0;
                foreach ($siswa_ids_to_update_menunggu as $s_id_menunggu) {
                    $stmt_update_batch_menunggu->bind_param("siss", $status_menunggu_penjemputan_kehadiran, $s_id_menunggu, $tanggal_hari_ini, $status_proses_belajar_kehadiran);
                    if ($stmt_update_batch_menunggu->execute() && $stmt_update_batch_menunggu->affected_rows > 0) { $updated_count_menunggu++; }
                } $stmt_update_batch_menunggu->close();
                if ($updated_count_menunggu > 0 && !isset($_SESSION['flash_message_wali_kehadiran']) && !isset($_SESSION['flash_message_wali_kehadiran_sync'])) { $_SESSION['flash_message_wali_kehadiran_sync'] = ['type' => 'info', 'text' => "{$updated_count_menunggu} siswa otomatis diupdate menjadi 'Menunggu Penjemputan'."];}
            } else { error_log("MySQL Prepare Error (update batch menunggu penjemputan): " . $conn->error); }
        }
    } else { error_log("MySQL Prepare Error (sync menunggu penjemputan): " . $conn->error); }
}


// --- PENGAMBILAN DATA UTAMA UNTUK TAMPILAN (SETELAH SINKRONISASI) ---
if (!$loggedInUserId && !$errorMessage) { $errorMessage = "Sesi pengguna tidak valid. Silakan login kembali."; }
if (!$kelas_id_wali && !$errorMessage) { $errorMessage = "Anda tidak terhubung dengan kelas manapun. Silakan hubungi Administrator."; }

if (!$errorMessage && isset($conn) && !($conn->connect_error ?? false)) {
    $stmt_nama_kelas_main = $conn->prepare("SELECT nama_kelas FROM kelas WHERE id = ?");
    if ($stmt_nama_kelas_main) {
        $stmt_nama_kelas_main->bind_param("i", $kelas_id_wali); $stmt_nama_kelas_main->execute(); $result_nama_kelas_main = $stmt_nama_kelas_main->get_result();
        if ($row_nama_kelas_main = $result_nama_kelas_main->fetch_assoc()) { $nama_kelas_wali = htmlspecialchars($row_nama_kelas_main['nama_kelas']); }
        else { $nama_kelas_wali = "Kelas (ID: " . htmlspecialchars($kelas_id_wali) . ") Tidak Ditemukan"; $errorMessage = $errorMessage ?: "Data kelas tidak ditemukan."; }
        $stmt_nama_kelas_main->close();
    } else { $errorMessage = $errorMessage ?: "Gagal ambil nama kelas: " . htmlspecialchars($conn->error); error_log("MySQL Prepare Error (get nama kelas main): " . $conn->error); }

    if (!$errorMessage) {
        $students = [];
        $sql_get_students = "SELECT s.id, s.nama_siswa, sk.status AS status_kehadiran FROM siswa s LEFT JOIN status_kehadiran sk ON s.id = sk.siswa_id AND sk.tanggal = ? WHERE s.kelas_id = ? ORDER BY s.nama_siswa ASC";
        $stmt_get_students = $conn->prepare($sql_get_students);
        if ($stmt_get_students) {
            $stmt_get_students->bind_param("si", $tanggal_hari_ini, $kelas_id_wali); $stmt_get_students->execute(); $result_students = $stmt_get_students->get_result();
            while ($row_student = $result_students->fetch_assoc()) { $students[] = ['id' => $row_student['id'], 'nama' => $row_student['nama_siswa'], 'status_kehadiran' => $row_student['status_kehadiran'] ?? 'Belum Diisi']; }
            $stmt_get_students->close();
        } else { $errorMessage = $errorMessage ?: "Gagal ambil data siswa: " . htmlspecialchars($conn->error); error_log("MySQL Prepare Error (get students main): " . $conn->error); }
    }
} elseif (!$errorMessage) { $errorMessage = $conn_error_message_global ?: "Koneksi database tidak tersedia."; }


// PENGAMBILAN PESAN FLASH
if (isset($_SESSION['flash_message_wali_kehadiran'])) {
    $message_data_page = $_SESSION['flash_message_wali_kehadiran'];
    unset($_SESSION['flash_message_wali_kehadiran']);
} elseif (isset($_SESSION['flash_message_wali_kehadiran_sync']) && !$message_data_page) {
    $message_data_page = $_SESSION['flash_message_wali_kehadiran_sync'];
    unset($_SESSION['flash_message_wali_kehadiran_sync']);
}

// LOGIKA DOWNLOAD EXCEL
if (isset($_GET['download_excel_absensi']) && $kelas_id_wali && empty($errorMessage) && isset($conn) && !($conn->connect_error ?? false)) {
    // ... (Logika download excel tetap sama) ...
    if (!empty($students)) {
        $spreadsheet = new Spreadsheet(); $sheet = $spreadsheet->getActiveSheet(); $nama_kelas_safe = preg_replace('/[^a-zA-Z0-9_]/', '', $nama_kelas_wali); $sheet->setTitle('Absensi ' . $nama_kelas_safe);
        $nama_wk_excel = isset($_SESSION['nama_user']) ? $_SESSION['nama_user'] : 'Wali Kelas';
        $sheet->setCellValue('A1', 'Laporan Absensi Siswa'); $sheet->mergeCells('A1:C1'); $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14); $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->setCellValue('A2', 'Kelas: ' . $nama_kelas_wali); $sheet->mergeCells('A2:C2'); $sheet->getStyle('A2')->getFont()->setBold(true);
        $sheet->setCellValue('A3', 'Wali Kelas: ' . $nama_wk_excel); $sheet->mergeCells('A3:C3'); $sheet->getStyle('A3')->getFont()->setBold(true);
        $sheet->setCellValue('A4', 'Tanggal: ' . date("d F Y", strtotime($tanggal_hari_ini))); $sheet->mergeCells('A4:C4'); $sheet->getStyle('A4')->getFont()->setBold(true);
        $sheet->getRowDimension(1)->setRowHeight(20); $sheet->getRowDimension(2)->setRowHeight(18); $sheet->getRowDimension(3)->setRowHeight(18); $sheet->getRowDimension(4)->setRowHeight(18); $sheet->getStyle('A2:A4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $headers = ['No', 'Nama Siswa', 'Status Kehadiran']; $start_row_table = 6; $sheet->fromArray($headers, NULL, 'A' . $start_row_table);
        $headerStyleArray = ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4A5568']]];
        $sheet->getStyle('A'.$start_row_table.':' . $sheet->getHighestColumn() . $start_row_table)->applyFromArray($headerStyleArray);
        foreach (range('A', $sheet->getHighestColumn()) as $columnID) { $sheet->getColumnDimension($columnID)->setAutoSize(true); }
        $rowNum = $start_row_table + 1; $no_excel = 1;
        foreach ($students as $student_excel) { $rowDataArray = [$no_excel++, htmlspecialchars($student_excel['nama']), formatStatusKehadiranWaliKelas($student_excel['status_kehadiran'])]; $sheet->fromArray($rowDataArray, NULL, 'A' . $rowNum++); }
        $last_row_table = $rowNum -1; $styleArrayTable = ['borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FF000000'],],],];
        $sheet->getStyle('A'.$start_row_table.':'.$sheet->getHighestColumn().$last_row_table)->applyFromArray($styleArrayTable);
        $filename_kelas_part = str_replace(' ', '_', $nama_kelas_safe); $filename = 'absensi_' . $filename_kelas_part . '_' . $tanggal_hari_ini . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment;filename="' . $filename . '"'); header('Cache-Control: max-age=0');
        if (ob_get_length()) ob_end_clean(); $writer = new Xlsx($spreadsheet); $writer->save('php://output'); exit;
    } else { $_SESSION['flash_message_wali_kehadiran'] = ['type' => 'warning', 'text' => 'Tidak ada data absensi untuk diunduh.']; header('Location: index.php'); exit; }
}

// PERSIAPAN UNTUK INCLUDE HEADER
$assets_path_prefix = '../';
$nama_wali_kelas_display = isset($_SESSION['nama_user']) ? htmlspecialchars($_SESSION['nama_user']) : 'Wali Kelas';
// $additional_meta_tags_in_head = '<meta http-equiv="refresh" content="10;url=index.php">'; // Dihapus, diganti JS
$additional_meta_tags_in_head = '';


include '../includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
        <div>
            <h2 class="text-3xl font-bold text-gray-800"><i class="fas fa-clipboard-user mr-2 text-indigo-600"></i>Dashboard Absensi Wali Kelas</h2>
            <p class="text-gray-600">Wali Kelas: <strong class="font-semibold"><?php echo $nama_wali_kelas_display; ?></strong></p>
            <p class="text-gray-600">Kelas: <strong class="font-semibold"><?php echo htmlspecialchars($nama_kelas_wali); ?></strong></p>
            <p class="text-gray-600">Tanggal: <strong class="font-semibold"><?php echo date("d M Y", strtotime($tanggal_hari_ini)); ?></strong></p>
        </div>
        <div class="w-full sm:w-auto flex flex-col items-stretch sm:items-end space-y-3 sm:space-y-0">
             <!-- PERUBAHAN 1: Elemen HTML untuk Jam dan Tanggal -->
            <div id="realTimeClockWKIndex" class="text-sm text-gray-700 bg-gray-100 px-4 py-2 rounded-lg shadow-md text-center sm:text-right mb-2 sm:mb-0 w-full">
                Memuat jam...
            </div>
            <div class="flex flex-col sm:flex-row gap-3 w-full">
                <?php if ($kelas_id_wali && empty($errorMessage) && !empty($students)): ?>
                <form method="POST" action="index.php" class="w-full sm:w-auto" onsubmit="return confirm('PERHATIAN! Anda akan mereset semua status absensi siswa di kelas ini untuk hari ini menjadi \'Belum Diisi\', termasuk menghapus log penjemputan hari ini. Lanjutkan?');">
                    <button type="submit" name="reset_total_absensi_hari_ini"
                            class="w-full px-4 py-2.5 bg-red-600 text-white font-semibold rounded-lg shadow-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-opacity-75 transition ease-in-out duration-150 flex items-center justify-center">
                        <i class="fas fa-power-off mr-2"></i> Reset Total Absensi
                    </button>
                </form>
                <a href="index.php?download_excel_absensi=1" 
                   class="w-full sm:w-auto inline-flex items-center justify-center px-4 py-2.5 bg-green-600 text-white font-semibold rounded-lg shadow-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-opacity-75 transition ease-in-out duration-150">
                    <i class="fas fa-file-excel mr-2"></i> Download Absensi
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($message_data_page): ?>
        <div class="mb-6 p-4 rounded-md text-sm shadow <?php 
            if ($message_data_page['type'] === 'success') echo 'bg-green-100 border-l-4 border-green-500 text-green-700';
            elseif ($message_data_page['type'] === 'error') echo 'bg-red-100 border-l-4 border-red-500 text-red-700';
            elseif ($message_data_page['type'] === 'warning') echo 'bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700';
            else echo 'bg-blue-100 border-l-4 border-blue-500 text-blue-700'; 
        ?>">
            <div class="flex">
                <div class="flex-shrink-0">
                    <?php 
                    if ($message_data_page['type'] === 'success') echo '<i class="fas fa-check-circle fa-lg"></i>';
                    elseif ($message_data_page['type'] === 'error') echo '<i class="fas fa-times-circle fa-lg"></i>';
                    elseif ($message_data_page['type'] === 'warning') echo '<i class="fas fa-exclamation-triangle fa-lg"></i>';
                    else echo '<i class="fas fa-info-circle fa-lg"></i>'; 
                    ?>
                </div>
                <div class="ml-3"><p class="font-medium"><?php echo htmlspecialchars($message_data_page['text']); ?></p></div>
            </div>
        </div>
    <?php endif; ?>
    
    <?php if ($errorMessage): ?>
        <div class="p-4 mb-4 text-sm text-red-700 bg-red-100 border-l-4 border-red-500 rounded-md shadow">
            <div class="flex"><div class="flex-shrink-0"><i class="fas fa-times-circle fa-lg"></i></div><div class="ml-3"><p class="font-medium"><?php echo htmlspecialchars($errorMessage); ?></p></div></div>
        </div>
    <?php elseif (empty($students) && $kelas_id_wali && !$errorMessage): ?>
        <div class="p-4 mb-4 text-sm text-blue-700 bg-blue-100 border-l-4 border-blue-500 rounded-md shadow">
             <div class="flex"><div class="flex-shrink-0"><i class="fas fa-info-circle fa-lg"></i></div><div class="ml-3"><p class="font-medium">Tidak ada siswa yang terdaftar di kelas <strong class="font-semibold"><?php echo htmlspecialchars($nama_kelas_wali); ?></strong>.</p></div></div>
        </div>
    <?php elseif (!empty($students)): ?>
    <div class="bg-white shadow-xl rounded-lg overflow-x-auto">
        <h3 class="text-xl font-semibold p-5 border-b border-gray-200 text-gray-700">Daftar Kehadiran Siswa</h3>
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">No</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nama Siswa</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status Kehadiran</th>
                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Aksi (Ubah Status)</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php $no = 1; foreach ($students as $student): ?>
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?= $no++; ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars($student['nama']); ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                            <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full
                                <?php
                                $status_hadir = $student['status_kehadiran'];
                                if ($status_hadir === 'Proses Belajar') echo 'bg-green-100 text-green-800 border border-green-300';
                                elseif ($status_hadir === 'Menunggu Penjemputan') echo 'bg-teal-100 text-teal-800 border border-teal-300';
                                elseif ($status_hadir === 'Pulang') echo 'bg-purple-100 text-purple-800 border border-purple-300';
                                elseif ($status_hadir === 'Tidak Hadir') echo 'bg-red-100 text-red-800 border border-red-300';
                                elseif ($status_hadir === 'Izin') echo 'bg-blue-100 text-blue-800 border border-blue-300';
                                elseif ($status_hadir === 'Sakit') echo 'bg-orange-100 text-orange-800 border border-orange-300';
                                else echo 'bg-yellow-100 text-yellow-800 border border-yellow-300';
                                ?>">
                                <?= formatStatusKehadiranWaliKelas($status_hadir); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-center space-x-1 sm:space-x-2">
                            <?php $is_status_final_hari_ini = in_array($student['status_kehadiran'], ['Pulang', 'Tidak Hadir']); ?>
                            <?php $is_menunggu_atau_proses = in_array($student['status_kehadiran'], ['Menunggu Penjemputan', 'Proses Belajar']); ?>
                            
                            <form method="POST" action="index.php" class="inline-block">
                                <input type="hidden" name="student_id" value="<?= $student['id']; ?>">
                                <button type="submit" name="action" value="Masuk" title="Ubah status menjadi Proses Belajar"
                                        class="px-3 py-1.5 text-xs font-medium rounded-md text-white bg-green-500 hover:bg-green-600 focus:outline-none focus:ring-2 focus:ring-green-400 focus:ring-opacity-75 disabled:opacity-60 disabled:cursor-not-allowed"
                                        <?= ($student['status_kehadiran'] === 'Proses Belajar' || $is_status_final_hari_ini || $student['status_kehadiran'] === 'Menunggu Penjemputan') ? 'disabled' : ''; ?>>
                                    <i class="fas fa-check-circle sm:mr-1"></i> <span class="hidden sm:inline">Masuk</span>
                                </button>
                            </form>
                            <form method="POST" action="index.php" class="inline-block">
                                <input type="hidden" name="student_id" value="<?= $student['id']; ?>">
                                <button type="submit" name="action" value="Tidak Masuk" title="Ubah status menjadi Tidak Hadir"
                                        class="px-3 py-1.5 text-xs font-medium rounded-md text-white bg-red-500 hover:bg-red-600 focus:outline-none focus:ring-2 focus:ring-red-400 focus:ring-opacity-75 disabled:opacity-60 disabled:cursor-not-allowed"
                                        <?= ($is_status_final_hari_ini || ($student['status_kehadiran'] !== 'Belum Diisi' && !$is_menunggu_atau_proses && $student['status_kehadiran'] !== 'Izin' && $student['status_kehadiran'] !== 'Sakit') ) ? 'disabled' : ''; ?>>
                                    <i class="fas fa-times-circle sm:mr-1"></i> <span class="hidden sm:inline">Tidak Masuk</span>
                                </button>
                            </form>
                            <form method="POST" action="index.php" class="inline-block">
                                <input type="hidden" name="student_id" value="<?= $student['id']; ?>">
                                <button type="submit" name="action" value="Izin" title="Ubah status menjadi Izin"
                                        class="px-3 py-1.5 text-xs font-medium rounded-md text-white bg-blue-500 hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-400 focus:ring-opacity-75 disabled:opacity-60 disabled:cursor-not-allowed"
                                        <?= ($student['status_kehadiran'] === 'Izin' || $is_status_final_hari_ini || $student['status_kehadiran'] === 'Menunggu Penjemputan') ? 'disabled' : ''; ?>>
                                    <i class="fas fa-envelope-open-text sm:mr-1"></i> <span class="hidden sm:inline">Izin</span>
                                </button>
                            </form>
                             <form method="POST" action="index.php" class="inline-block">
                                <input type="hidden" name="student_id" value="<?= $student['id']; ?>">
                                <button type="submit" name="action" value="Sakit" title="Ubah status menjadi Sakit"
                                        class="px-3 py-1.5 text-xs font-medium rounded-md text-white bg-orange-500 hover:bg-orange-600 focus:outline-none focus:ring-2 focus:ring-orange-400 focus:ring-opacity-75 disabled:opacity-60 disabled:cursor-not-allowed"
                                        <?= ($student['status_kehadiran'] === 'Sakit' || $is_status_final_hari_ini || $student['status_kehadiran'] === 'Menunggu Penjemputan') ? 'disabled' : ''; ?>>
                                    <i class="fas fa-notes-medical sm:mr-1"></i> <span class="hidden sm:inline">Sakit</span>
                                </button>
                            </form>
                            <?php if ($student['status_kehadiran'] !== 'Belum Diisi'): ?>
                            <form method="POST" action="index.php" class="inline-block" onsubmit="return confirm('Anda yakin ingin mereset status absensi dan penjemputan untuk siswa <?= htmlspecialchars($student['nama']); ?> menjadi \'Belum Diisi\'?');">
                                <input type="hidden" name="student_id_to_reset" value="<?= $student['id']; ?>">
                                <button type="submit" name="reset_individual_status" title="Reset status siswa ini"
                                        class="px-3 py-1.5 text-xs font-medium rounded-md text-white bg-yellow-500 hover:bg-yellow-600 focus:outline-none focus:ring-2 focus:ring-yellow-400 focus:ring-opacity-75">
                                    <i class="fas fa-undo sm:mr-1"></i> <span class="hidden sm:inline">Reset</span>
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- PERUBAHAN 2: Script JavaScript untuk Jam Real-time -->
<script>
    function updateRealTimeClockWKIndex() { // Nama fungsi unik
        const clockElement = document.getElementById('realTimeClockWKIndex'); // ID elemen unik
        if (clockElement) {
            const now = new Date();
            const days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
            const months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

            const dayName = days[now.getDay()];
            const day = now.getDate();
            const monthName = months[now.getMonth()];
            const year = now.getFullYear();
            
            let hours = now.getHours();
            let minutes = now.getMinutes();
            let seconds = now.getSeconds();

            hours = hours < 10 ? '0' + hours : hours;
            minutes = minutes < 10 ? '0' + minutes : minutes;
            seconds = seconds < 10 ? '0' + seconds : seconds;

            clockElement.innerHTML = `${dayName}, ${day} ${monthName} ${year}<br><strong class="text-lg">${hours}:${minutes}:${seconds}</strong>`;
        }
    }

    setInterval(updateRealTimeClockWKIndex, 1000);
    updateRealTimeClockWKIndex();

    // Script auto-reload
    var isSubmittingFormWKIndex = false; // Flag unik
    document.addEventListener('submit', function(event) {
        // Hanya set flag jika form ada di halaman ini (misalnya, tidak dari iframe atau komponen lain)
        if (event.target.closest('div.container')) { // Sesuaikan selector jika perlu
             isSubmittingFormWKIndex = true;
        }
    });

    setTimeout(function() {
        if (!isSubmittingFormWKIndex) {
            const urlParams = new URLSearchParams(window.location.search);
            if (!urlParams.has('download_excel_absensi')) { // Sesuaikan nama parameter
                var activeElement = document.activeElement;
                if (activeElement && (activeElement.tagName === 'INPUT' || activeElement.tagName === 'TEXTAREA' || activeElement.tagName === 'SELECT')) {
                    // Jangan reload jika user sedang berinteraksi dengan form
                } else {
                     window.location.reload(true); 
                }
            }
        }
    }, 10000); // Reload setiap 10 detik (sesuaikan jika perlu)
</script>

<?php include '../includes/footer.php'; if(isset($conn) && $conn instanceof mysqli) $conn->close(); ?>