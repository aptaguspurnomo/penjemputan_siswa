<?php
// wali_murid/index.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Autentikasi dan Otorisasi Awal
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'wali_murid') {
    if (!isset($_SESSION['flash_message_login'])) { 
        $_SESSION['flash_message_login'] = ['type' => 'error', 'text' => 'Anda harus login sebagai wali murid.'];
    }
    header('Location: ../login.php');
    exit;
}

// 2. Include Koneksi Database & Set Zona Waktu PHP
$db_connection_error = null; 
// PENTING: Atur zona waktu PHP agar konsisten dengan data dan MySQL Anda. Sesuaikan jika perlu.
date_default_timezone_set('Asia/Jakarta'); // Contoh untuk WIB

if (file_exists(__DIR__ . '/../includes/db.php')) {
    require_once __DIR__ . '/../includes/db.php';
    if (!isset($conn)) {
        $db_connection_error = "Variabel koneksi \$conn tidak terdefinisi setelah include db.php.";
    } elseif ($conn->connect_error) {
        $db_connection_error = "Koneksi DB Gagal: " . htmlspecialchars($conn->connect_error);
    }
} else {
    $db_connection_error = "File konfigurasi database (db.php) tidak ditemukan.";
}

if ($db_connection_error) {
    error_log("DB Connection Error on wali_murid/index.php: " . $db_connection_error);
}

// 3. Inisialisasi Variabel Utama Halaman
$assets_path_prefix = '../';
$wali_murid_id = (int)$_SESSION['user_id']; // Pastikan integer
$tanggal_hari_ini = date('Y-m-d');
$now_db_format = date('Y-m-d H:i:s'); // Waktu saat ini untuk perbandingan pengumuman

$message_data_page = null; 
if (isset($_SESSION['flash_message_wali_dashboard'])) {
    $message_data_page = $_SESSION['flash_message_wali_dashboard'];
    unset($_SESSION['flash_message_wali_dashboard']);
}

if (!$message_data_page && $db_connection_error) { // Tampilkan error koneksi jika tidak ada flash lain
    $message_data_page = ['type' => 'error', 'text' => $db_connection_error];
}

// 4. Fungsi Helper
if (!function_exists('formatStatusDisplayWali')) {
    function formatStatusDisplayWali($status_db, $is_kehadiran = false) {
        if ($status_db === null || $status_db === '') {
            return $is_kehadiran ? 'Belum Diisi Wali Kelas' : 'Belum ada info';
        }
        $status_map_penjemputan = [
            'masuk_kelas' => 'Masuk Sekolah', 'proses_belajar' => 'Proses Belajar',
            'perjalanan_jemput' => 'Dalam Perjalanan Jemput', 'lima_menit_sampai' => 'Penjemput 5 Menit Lagi Sampai',
            'sudah_sampai_sekolah' => 'Penjemput Sudah Sampai di Sekolah', 'sudah_dijemput' => 'Sudah Dijemput',
            'tidak_hadir_info_jemput' => 'Tidak Hadir (Info Jemput)'
        ];
        $status_map_kehadiran = [
            'Proses Belajar' => 'Proses Belajar', 'Tidak Hadir' => 'Tidak Hadir',
            'Izin' => 'Izin', 'Sakit' => 'Sakit', 'Belum Diisi' => 'Belum Diisi Wali Kelas',
            'Menunggu Penjemputan' => 'Menunggu Penjemputan', 'Pulang' => 'Pulang'
        ];
        $map_to_use = $is_kehadiran ? $status_map_kehadiran : $status_map_penjemputan;
        return $map_to_use[$status_db] ?? ucfirst(str_replace('_', ' ', $status_db));
    }
}

$flow_status_wali = [
    'perjalanan_jemput' => ['lima_menit_sampai' => 'Saya 5 Menit Lagi Sampai', 'sudah_sampai_sekolah' => 'Saya Sudah Sampai di Sekolah'],
    'lima_menit_sampai' => ['sudah_sampai_sekolah' => 'Saya Sudah Sampai di Sekolah'],
    'sudah_sampai_sekolah' => ['sudah_dijemput' => 'Konfirmasi Sudah Menjemput Anak'],
];

// 5. Logika Aksi POST (Hanya jika koneksi DB berhasil)
if (!$db_connection_error && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($conn)) {
    $redirect_needed_post = true; 
    if (isset($_POST['set_penjemput_and_start'])) {
        $siswa_id_action = isset($_POST['siswa_id']) ? (int)$_POST['siswa_id'] : 0;
        $nama_penjemput_action = isset($_POST['nama_penjemput_input']) ? trim($_POST['nama_penjemput_input']) : '';
        $catatan_wali_murid_asli = isset($_POST['catatan_penjemput_input']) ? trim($_POST['catatan_penjemput_input']) : null;
        if (empty($catatan_wali_murid_asli)) $catatan_wali_murid_asli = null; 

        if (empty($siswa_id_action) || empty($nama_penjemput_action)) {
            $_SESSION['flash_message_wali_dashboard'] = ['type' => 'error', 'text' => 'Siswa dan Nama Penjemput tidak boleh kosong.'];
        } else {
            $stmt_cek_anak = $conn->prepare("SELECT id FROM siswa WHERE id = ? AND id_wali_murid = ?");
            if(!$stmt_cek_anak){
                $_SESSION['flash_message_wali_dashboard'] = ['type' => 'error', 'text' => 'Error DB (C00).'];
            } else {
                $stmt_cek_anak->bind_param("ii", $siswa_id_action, $wali_murid_id);
                $stmt_cek_anak->execute();
                $result_cek_anak = $stmt_cek_anak->get_result();

                if ($result_cek_anak->num_rows === 1) {
                    $stmt_cek_kehadiran = $conn->prepare("SELECT status FROM status_kehadiran WHERE siswa_id = ? AND tanggal = ?");
                    $status_kehadiran_hari_ini_db_post = null;
                    if ($stmt_cek_kehadiran) {
                        $stmt_cek_kehadiran->bind_param("is", $siswa_id_action, $tanggal_hari_ini);
                        $stmt_cek_kehadiran->execute();
                        $result_kehadiran_post = $stmt_cek_kehadiran->get_result();
                        if ($row_kehadiran_post = $result_kehadiran_post->fetch_assoc()) {
                            $status_kehadiran_hari_ini_db_post = $row_kehadiran_post['status'];
                        }
                        $stmt_cek_kehadiran->close();
                    } else {
                         $_SESSION['flash_message_wali_dashboard'] = ['type' => 'error', 'text' => 'Error DB (C02).'];
                    }
                    
                    $status_valid_untuk_jemput = ['Proses Belajar', 'Menunggu Penjemputan'];

                    if (!in_array($status_kehadiran_hari_ini_db_post, $status_valid_untuk_jemput)) {
                        $display_status_kehadiran = formatStatusDisplayWali($status_kehadiran_hari_ini_db_post, true);
                        $_SESSION['flash_message_wali_dashboard'] = ['type' => 'warning', 'text' => "Siswa belum dalam status yang valid untuk dijemput. Status saat ini: " . htmlspecialchars($display_status_kehadiran) . "."];
                    } else { 
                        $_SESSION['penjemput_nama_cycle'][$siswa_id_action] = $nama_penjemput_action;
                        $_SESSION['pickup_flow_active'][$siswa_id_action] = true;
                        $new_status_db = 'perjalanan_jemput';
                        
                        $stmt_cek_existing_jemput = $conn->prepare("SELECT id FROM status_penjemputan WHERE siswa_id = ? AND DATE(waktu_update_status) = ?");
                        $existing_jemput_id_today = null;
                        if($stmt_cek_existing_jemput) {
                            $stmt_cek_existing_jemput->bind_param("is", $siswa_id_action, $tanggal_hari_ini);
                            $stmt_cek_existing_jemput->execute();
                            $res_cek_existing = $stmt_cek_existing_jemput->get_result();
                            if($row_existing = $res_cek_existing->fetch_assoc()) {
                                $existing_jemput_id_today = $row_existing['id'];
                            }
                            $stmt_cek_existing_jemput->close();
                        }

                        $is_success_post = false;
                        $error_msg_post = '';

                        if ($existing_jemput_id_today) {
                            $stmt_update = $conn->prepare("UPDATE status_penjemputan SET status = ?, nama_penjemput = ?, catatan = ?, waktu_update_status = NOW() WHERE id = ?");
                            if ($stmt_update) {
                                $stmt_update->bind_param("sssi", $new_status_db, $nama_penjemput_action, $catatan_wali_murid_asli, $existing_jemput_id_today);
                                $is_success_post = $stmt_update->execute();
                                $error_msg_post = $is_success_post ? '' : $stmt_update->error;
                                $stmt_update->close();
                            } else {
                                $error_msg_post = "DB Prepare Error (Update): " . $conn->error;
                            }
                        } else {
                            $stmt_insert_new = $conn->prepare("INSERT INTO status_penjemputan (siswa_id, status, nama_penjemput, catatan, waktu_update_status) VALUES (?, ?, ?, ?, NOW())");
                            if ($stmt_insert_new) {
                                $stmt_insert_new->bind_param("isss", $siswa_id_action, $new_status_db, $nama_penjemput_action, $catatan_wali_murid_asli);
                                $is_success_post = $stmt_insert_new->execute();
                                $error_msg_post = $is_success_post ? '' : $stmt_insert_new->error;
                                $stmt_insert_new->close();
                            } else {
                                 $error_msg_post = "DB Prepare Error (Insert): " . $conn->error;
                            }
                        }

                        if ($is_success_post) {
                            $pesan_sukses = "Status anak kini: " . formatStatusDisplayWali($new_status_db) . ". Penjemput: " . htmlspecialchars($nama_penjemput_action) . ".";
                            if ($catatan_wali_murid_asli) {
                                $pesan_sukses .= " Catatan Anda: \"" . htmlspecialchars($catatan_wali_murid_asli) . "\"";
                            }
                            $_SESSION['flash_message_wali_dashboard'] = ['type' => 'success', 'text' => $pesan_sukses];
                        } else {
                            $_SESSION['flash_message_wali_dashboard'] = ['type' => 'error', 'text' => "Gagal memulai status: " . htmlspecialchars($error_msg_post)];
                            unset($_SESSION['penjemput_nama_cycle'][$siswa_id_action], $_SESSION['pickup_flow_active'][$siswa_id_action]);
                        }
                    }
                } else {
                    $_SESSION['flash_message_wali_dashboard'] = ['type' => 'error', 'text' => 'Anak tidak valid.'];
                }
                if(isset($stmt_cek_anak)) $stmt_cek_anak->close();
            }
        }
    }
    elseif (isset($_POST['update_status_sequential'])) {
        $siswa_id_action = isset($_POST['siswa_id']) ? (int)$_POST['siswa_id'] : 0;
        $new_status_db = isset($_POST['new_status']) ? trim($_POST['new_status']) : '';
        $nama_penjemput_action = $_SESSION['penjemput_nama_cycle'][$siswa_id_action] ?? null;

        if (empty($siswa_id_action) || empty($new_status_db)) {
            $_SESSION['flash_message_wali_dashboard'] = ['type' => 'error', 'text' => 'Data update tidak valid.'];
        } elseif (!isset($_SESSION['pickup_flow_active'][$siswa_id_action]) || !$nama_penjemput_action) {
            $_SESSION['flash_message_wali_dashboard'] = ['type' => 'warning', 'text' => 'Proses penjemputan belum dimulai dengan benar.'];
        } else {
            $stmt_cek = $conn->prepare("SELECT id FROM siswa WHERE id = ? AND id_wali_murid = ?");
            if(!$stmt_cek){
                 $_SESSION['flash_message_wali_dashboard'] = ['type' => 'error', 'text' => 'Error DB (C03).'];
            } else {
                $stmt_cek->bind_param("ii", $siswa_id_action, $wali_murid_id);
                $stmt_cek->execute();
                $result_cek = $stmt_cek->get_result();
                if ($result_cek->num_rows === 1) {
                    $catatan_db_sequential = null; 
                    
                    $stmt_insert_seq = $conn->prepare("INSERT INTO status_penjemputan (siswa_id, status, nama_penjemput, catatan, waktu_update_status) VALUES (?, ?, ?, ?, NOW())");
                    if ($stmt_insert_seq) {
                        $stmt_insert_seq->bind_param("isss", $siswa_id_action, $new_status_db, $nama_penjemput_action, $catatan_db_sequential);
                        if ($stmt_insert_seq->execute()) {
                            $_SESSION['flash_message_wali_dashboard'] = ['type' => 'success', 'text' => "Status anak diperbarui: " . formatStatusDisplayWali($new_status_db) . "."];
                            if ($new_status_db === 'sudah_dijemput') {
                                unset($_SESSION['penjemput_nama_cycle'][$siswa_id_action], $_SESSION['pickup_flow_active'][$siswa_id_action]);
                            }
                        } else {
                            $_SESSION['flash_message_wali_dashboard'] = ['type' => 'error', 'text' => "Gagal update status: " . htmlspecialchars($stmt_insert_seq->error)];
                        }
                        $stmt_insert_seq->close();
                    } else {
                        $_SESSION['flash_message_wali_dashboard'] = ['type' => 'error', 'text' => "Error DB (P02): " . $conn->error];
                    }
                } else {
                    $_SESSION['flash_message_wali_dashboard'] = ['type' => 'error', 'text' => 'Anak tidak valid.'];
                }
                $stmt_cek->close();
            }
        }
    } else {
        $redirect_needed_post = false;
    }

    if ($redirect_needed_post) {
        header("Location: index.php");
        exit;
    }
}

// --- DATA FETCHING for display (Hanya jika koneksi DB berhasil) ---
$anak_list = [];
$pengumuman_aktif_list = []; 
$db_error_fetching = null; 

if (!$db_connection_error && isset($conn)) {
    // 1. Ambil data anak
    $query_anak = <<<SQL
SELECT 
    s.id AS siswa_id, 
    s.nama_siswa, 
    k.nama_kelas,
    wk.nama AS nama_wali_kelas,
    sp_today.status AS status_penjemputan_hari_ini,
    sp_today.nama_penjemput AS penjemput_hari_ini_db,
    sp_today.waktu_update_status AS waktu_penjemputan_hari_ini,
    sp_today.catatan AS catatan_penjemputan_hari_ini, 
    sk_today.status AS status_kehadiran_hari_ini 
FROM siswa s
JOIN kelas k ON s.kelas_id = k.id
LEFT JOIN users wk ON k.id = wk.kelas_id AND wk.role = 'wali_kelas'
LEFT JOIN (
    SELECT 
        sp1.siswa_id, 
        sp1.status, 
        sp1.nama_penjemput, 
        sp1.waktu_update_status, 
        sp1.catatan 
    FROM status_penjemputan sp1
    INNER JOIN (
        SELECT 
            siswa_id, 
            MAX(waktu_update_status) AS max_waktu_update 
        FROM status_penjemputan 
        WHERE DATE(waktu_update_status) = ?
        GROUP BY siswa_id
    ) sp2 ON sp1.siswa_id = sp2.siswa_id AND sp1.waktu_update_status = sp2.max_waktu_update
) sp_today ON s.id = sp_today.siswa_id
LEFT JOIN status_kehadiran sk_today ON s.id = sk_today.siswa_id AND sk_today.tanggal = ?
WHERE s.id_wali_murid = ? 
ORDER BY s.nama_siswa ASC
SQL;

    $stmt_anak_fetch = $conn->prepare($query_anak);
    if ($stmt_anak_fetch) {
        $stmt_anak_fetch->bind_param("ssi", $tanggal_hari_ini, $tanggal_hari_ini, $wali_murid_id);
        $stmt_anak_fetch->execute();
        $result_anak_fetch = $stmt_anak_fetch->get_result();
        if ($result_anak_fetch) {
            while ($row_anak_fetch = $result_anak_fetch->fetch_assoc()) { 
                $anak_list[] = $row_anak_fetch;
                $s_id_fetch = $row_anak_fetch['siswa_id'];
                $status_jemput_db_fetch = $row_anak_fetch['status_penjemputan_hari_ini'];
                $nama_penjemput_db_fetch = $row_anak_fetch['penjemput_hari_ini_db'];
                $flow_db_statuses = ['perjalanan_jemput', 'lima_menit_sampai', 'sudah_sampai_sekolah'];
                if (in_array($status_jemput_db_fetch, $flow_db_statuses)) {
                    if (!isset($_SESSION['pickup_flow_active'][$s_id_fetch]) || !$_SESSION['pickup_flow_active'][$s_id_fetch]) {
                        $_SESSION['pickup_flow_active'][$s_id_fetch] = true;
                    }
                    $_SESSION['penjemput_nama_cycle'][$s_id_fetch] = $nama_penjemput_db_fetch;
                } elseif ($status_jemput_db_fetch === 'sudah_dijemput') {
                    if (isset($_SESSION['pickup_flow_active'][$s_id_fetch])) unset($_SESSION['pickup_flow_active'][$s_id_fetch]);
                    if (isset($_SESSION['penjemput_nama_cycle'][$s_id_fetch])) unset($_SESSION['penjemput_nama_cycle'][$s_id_fetch]);
                }
            }
        } else {
            $db_error_fetching = "Error mengambil data anak (exec): " . htmlspecialchars($stmt_anak_fetch->error);
            error_log($db_error_fetching . " | Query: " . preg_replace('/\s+/', ' ', $query_anak));
        }
        $stmt_anak_fetch->close();
    } else {
        $db_error_fetching = "Error DB (prepare fetch anak): " . htmlspecialchars($conn->error);
        error_log($db_error_fetching . " | Query: " . preg_replace('/\s+/', ' ', $query_anak));
    }

    // 2. Ambil Pengumuman Aktif
    $stmt_get_pengumuman_aktif = $conn->prepare(
        "SELECT id, judul_pengumuman, isi_pengumuman, tanggal_mulai 
         FROM pengumuman_sekolah 
         WHERE tanggal_mulai <= ? 
           AND (tanggal_kadaluarsa IS NULL OR tanggal_kadaluarsa > ?)
         ORDER BY tanggal_mulai DESC, id DESC"
    );
    if ($stmt_get_pengumuman_aktif) {
        $stmt_get_pengumuman_aktif->bind_param("ss", $now_db_format, $now_db_format);
        $stmt_get_pengumuman_aktif->execute();
        $result_pengumuman_aktif = $stmt_get_pengumuman_aktif->get_result();
        while ($row_p = $result_pengumuman_aktif->fetch_assoc()) {
            $pengumuman_aktif_list[] = $row_p;
        }
        $stmt_get_pengumuman_aktif->close();
    } else {
        if (!$db_error_fetching) { 
            $db_error_fetching = "Error mengambil pengumuman (prepare): " . htmlspecialchars($conn->error);
        }
        error_log("Wali Murid Index: Gagal prepare statement (get pengumuman aktif): " . $conn->error);
    }
}

if (!$message_data_page && $db_error_fetching) { // Jika belum ada flash, dan ada error fetch, set pesan
    $message_data_page = ['type' => 'error', 'text' => $db_error_fetching];
}

$additional_meta_tags_in_head = '';
include '../includes/header.php';
?>

<div class="container mx-auto px-4 py-12">
    <div class="flex flex-col md:flex-row justify-between items-center mb-8">
        <div class="text-center md:text-left mb-4 md:mb-0">
            <h1 class="text-4xl font-bold text-gray-800">
                <i class="fas fa-tachometer-alt mr-3 text-indigo-600"></i>Dashboard Wali Murid
            </h1>
            <p class="text-lg text-gray-600 mt-2">Selamat datang, <?php echo htmlspecialchars($_SESSION['nama_user'] ?? $_SESSION['username']); ?>!</p>
        </div>
        <div id="realTimeClockWali" class="text-sm text-gray-700 bg-gray-100 px-4 py-2 rounded-lg shadow-md text-center md:text-right">
            Memuat jam...
        </div>
    </div>

    <?php if ($message_data_page): ?>
        <div class="mb-6 p-4 rounded-md text-sm shadow <?php 
            $type_class = 'bg-blue-100 border-blue-500 text-blue-700'; 
            if ($message_data_page['type'] === 'success') $type_class = 'bg-green-100 border-l-4 border-green-500 text-green-700';
            elseif ($message_data_page['type'] === 'error') $type_class = 'bg-red-100 border-l-4 border-red-500 text-red-700';
            elseif ($message_data_page['type'] === 'warning') $type_class = 'bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700';
            echo $type_class; 
        ?>">
            <div class="flex">
                <div class="flex-shrink-0">
                    <?php 
                    $icon_class = 'fas fa-info-circle fa-lg'; 
                    if ($message_data_page['type'] === 'success') $icon_class = 'fas fa-check-circle fa-lg';
                    elseif ($message_data_page['type'] === 'error') $icon_class = 'fas fa-times-circle fa-lg';
                    elseif ($message_data_page['type'] === 'warning') $icon_class = 'fas fa-exclamation-triangle fa-lg';
                    echo "<i class='{$icon_class}'></i>"; 
                    ?>
                </div>
                <div class="ml-3">
                    <p class="font-medium"><?php echo htmlspecialchars($message_data_page['text']); ?></p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($pengumuman_aktif_list)): ?>
        <div class="mb-8 space-y-4">
            <?php foreach ($pengumuman_aktif_list as $idx_pengumuman => $pengumuman): ?>
                <div class="bg-blue-50 border-l-4 border-blue-500 text-blue-700 p-4 rounded-md shadow-md" role="alert">
                    <div class="flex items-start">
                        <div class="flex-shrink-0 pt-0.5">
                            <i class="fas fa-bullhorn fa-lg text-blue-600"></i>
                        </div>
                        <div class="ml-3 flex-1">
                            <?php if (!empty($pengumuman['judul_pengumuman'])): ?>
                                <p class="font-bold text-blue-800"><?php echo htmlspecialchars($pengumuman['judul_pengumuman']); ?></p>
                            <?php else: ?>
                                <p class="font-bold text-blue-800">PENGUMUMAN</p>
                            <?php endif; ?>
                            <div class="mt-1 text-sm text-blue-700 prose prose-sm max-w-none break-words">
                                <?php echo nl2br(htmlspecialchars($pengumuman['isi_pengumuman'])); ?>
                            </div>
                            <p class="text-xs text-blue-600 mt-2">
                                Dipublikasikan: <?php echo (new DateTime($pengumuman['tanggal_mulai']))->format('d M Y, H:i'); ?>
                            </p>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>


    <?php if (!empty($anak_list)): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-8">
            <?php foreach ($anak_list as $anak_data_loop): ?>
                <?php
                $s_id = $anak_data_loop['siswa_id'];
                $status_penjemputan_db_hari_ini = $anak_data_loop['status_penjemputan_hari_ini'];
                $nama_penjemput_db_hari_ini = $anak_data_loop['penjemput_hari_ini_db'];
                $waktu_penjemputan_db_hari_ini = $anak_data_loop['waktu_penjemputan_hari_ini'];
                $catatan_db_hari_ini = $anak_data_loop['catatan_penjemputan_hari_ini']; 
                $status_kehadiran = $anak_data_loop['status_kehadiran_hari_ini'];
                $nama_siswa_untuk_modal = htmlspecialchars($anak_data_loop['nama_siswa']);
                
                $flow_aktif_session = isset($_SESSION['pickup_flow_active'][$s_id]) && $_SESSION['pickup_flow_active'][$s_id];
                $nama_penjemput_ui = $_SESSION['penjemput_nama_cycle'][$s_id] ?? $nama_penjemput_db_hari_ini;

                $show_status_jemput_block = false;
                if ($flow_aktif_session || ($status_penjemputan_db_hari_ini !== null && !in_array($status_penjemputan_db_hari_ini, ['masuk_kelas', 'proses_belajar', '']))) {
                    $show_status_jemput_block = true;
                }
                
                $status_penjemputan_display = $status_penjemputan_db_hari_ini;

                $status_kehadiran_valid_untuk_jemput = ['Proses Belajar', 'Menunggu Penjemputan'];
                $bisa_mulai_jemput_ui = in_array($status_kehadiran, $status_kehadiran_valid_untuk_jemput);

                if ($flow_aktif_session || in_array($status_penjemputan_db_hari_ini, ['sudah_dijemput', 'tidak_hadir_info_jemput'])) {
                    $bisa_mulai_jemput_ui = false;
                }
                if (in_array($status_kehadiran, ['Tidak Hadir', 'Pulang', 'Izin', 'Sakit'])) {
                    $bisa_mulai_jemput_ui = false;
                }

                $show_status_kehadiran_block = true;
                $status_penjemputan_yang_menyembunyikan_kehadiran = [
                    'perjalanan_jemput', 'lima_menit_sampai', 
                    'sudah_sampai_sekolah', 'sudah_dijemput'
                ];
                if ($flow_aktif_session || 
                    ($status_penjemputan_db_hari_ini !== null && 
                     in_array($status_penjemputan_db_hari_ini, $status_penjemputan_yang_menyembunyikan_kehadiran))
                   ) {
                    $show_status_kehadiran_block = false;
                }
                ?>
                <div class="bg-white rounded-xl shadow-2xl overflow-hidden transform hover:scale-[1.02] transition-transform duration-300 ease-in-out flex flex-col"
                     data-siswa-id="<?php echo $s_id; ?>"
                     data-nama-siswa="<?php echo $nama_siswa_untuk_modal; ?>"
                     data-status-jemput="<?php echo htmlspecialchars($status_penjemputan_db_hari_ini ?? ''); ?>"
                     data-flow-aktif="<?php echo $flow_aktif_session ? 'true' : 'false'; ?>">
                    
                    <div class="p-6 flex-grow">
                        <div class="flex items-center mb-4">
                            <div class="p-3 rounded-full bg-indigo-500 text-white shadow-md mr-4">
                                <i class="fas fa-user-graduate fa-lg"></i>
                            </div>
                            <div>
                                <h3 class="text-2xl font-bold text-gray-800"><?php echo htmlspecialchars($anak_data_loop['nama_siswa']); ?></h3>
                                <p class="text-sm text-gray-500">Kelas: <?php echo htmlspecialchars($anak_data_loop['nama_kelas'] ?? 'N/A'); ?></p>
                                <p class="text-sm text-gray-500">Wali Kelas: <?php echo htmlspecialchars($anak_data_loop['nama_wali_kelas'] ?? 'N/A'); ?></p>
                            </div>
                        </div>

                        <div class="space-y-3 mb-6">
                            <?php if ($show_status_kehadiran_block): ?>
                            <div class="info-badge <?php 
                                $kehadiran_color_class = 'bg-gray-100 text-gray-700 border-gray-300';
                                if ($status_kehadiran === 'Proses Belajar') $kehadiran_color_class = 'bg-green-100 text-green-700 border-green-400';
                                elseif ($status_kehadiran === 'Menunggu Penjemputan') $kehadiran_color_class = 'bg-teal-100 text-teal-700 border-teal-400';
                                elseif ($status_kehadiran === 'Pulang') $kehadiran_color_class = 'bg-purple-100 text-purple-700 border-purple-400';
                                elseif (in_array($status_kehadiran, ['Tidak Hadir', 'Izin', 'Sakit'])) $kehadiran_color_class = 'bg-red-100 text-red-700 border-red-400';
                                echo $kehadiran_color_class;
                                ?>">
                                <i class="fas fa-clipboard-check mr-2"></i>
                                <span>Kehadiran (Wali Kelas): <strong><?php echo formatStatusDisplayWali($status_kehadiran, true); ?></strong></span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($show_status_jemput_block): ?>
                            <div class="info-badge <?php 
                                $penjemputan_color_class = 'bg-gray-100 text-gray-700 border-gray-300';
                                if ($status_penjemputan_display === 'sudah_dijemput') $penjemputan_color_class = 'bg-green-100 text-green-700 border-green-400';
                                elseif (in_array($status_penjemputan_display, ['perjalanan_jemput', 'lima_menit_sampai', 'sudah_sampai_sekolah'])) $penjemputan_color_class = 'bg-blue-100 text-blue-700 border-blue-400';
                                echo $penjemputan_color_class;
                                ?>">
                                <i class="fas fa-car-side mr-2"></i>
                                <div class="flex-1">
                                    Status Jemput (Anda): <strong><?php echo formatStatusDisplayWali($status_penjemputan_display); ?></strong>
                                    <?php if (!empty($nama_penjemput_ui)): ?>
                                        <span class="text-xs block">(oleh <?php echo htmlspecialchars($nama_penjemput_ui); ?>)</span>
                                    <?php endif; ?>
                                    <?php if (!empty($waktu_penjemputan_db_hari_ini)): ?>
                                        <span class="text-xs block mt-0.5"><?php echo (new DateTime($waktu_penjemputan_db_hari_ini))->format('d M Y, H:i'); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($catatan_db_hari_ini)): ?>
                                        <span class="text-xs block mt-1 pt-1 border-t border-gray-200">Catatan: <?php echo nl2br(htmlspecialchars($catatan_db_hari_ini)); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="bg-gray-100 px-6 py-5">
                        <?php 
                        $status_penjemputan_final_ui = ['sudah_dijemput', 'tidak_hadir_info_jemput'];
                        $status_kehadiran_final_wk = ['Tidak Hadir', 'Pulang', 'Izin', 'Sakit'];

                        if (in_array($status_penjemputan_db_hari_ini, $status_penjemputan_final_ui) || 
                            (in_array($status_kehadiran, $status_kehadiran_final_wk) && !$flow_aktif_session) ): ?>
                            <p class="text-sm text-gray-600 italic text-center py-2">
                                <?php 
                                if ($status_penjemputan_db_hari_ini === 'sudah_dijemput') echo '<i class="fas fa-check-circle text-green-500 mr-1"></i> Anak sudah dijemput.'; 
                                elseif ($status_penjemputan_db_hari_ini === 'tidak_hadir_info_jemput') echo '<i class="fas fa-times-circle text-red-500 mr-1"></i> Dikonfirmasi tidak dijemput.';
                                elseif ($status_kehadiran === 'Tidak Hadir') echo '<i class="fas fa-user-times text-red-500 mr-1"></i> Tidak masuk sekolah (info Wali Kelas).';
                                elseif ($status_kehadiran === 'Pulang') echo '<i class="fas fa-door-open text-purple-500 mr-1"></i> Sudah pulang (info Wali Kelas).';
                                elseif ($status_kehadiran === 'Izin') echo '<i class="fas fa-envelope-open-text text-blue-500 mr-1"></i> Izin (info Wali Kelas).';
                                elseif ($status_kehadiran === 'Sakit') echo '<i class="fas fa-notes-medical text-orange-500 mr-1"></i> Sakit (info Wali Kelas).';
                                ?>
                            </p>
                        <?php 
                        elseif ($flow_aktif_session && isset($_SESSION['penjemput_nama_cycle'][$s_id])): 
                            $next_statuses = $flow_status_wali[$status_penjemputan_display] ?? [];
                        ?>
                            <p class="text-xs text-gray-500 mb-2 text-center">Penjemput: <strong class="text-indigo-600"><?php echo htmlspecialchars($_SESSION['penjemput_nama_cycle'][$s_id]); ?></strong></p>
                            <?php if (!empty($next_statuses)): ?>
                                <div class="space-y-2">
                                    <?php foreach ($next_statuses as $next_key => $btn_text): ?>
                                        <form method="POST" action="index.php">
                                            <input type="hidden" name="siswa_id" value="<?php echo $s_id; ?>">
                                            <input type="hidden" name="new_status" value="<?php echo $next_key; ?>">
                                            <button type="submit" name="update_status_sequential"
                                                    class="w-full flex items-center justify-center px-4 py-2.5 border border-transparent text-sm font-medium rounded-lg shadow-md text-white transition-colors duration-150 <?php 
                                                        if ($next_key === 'lima_menit_sampai') echo 'bg-sky-500 hover:bg-sky-600 focus:ring-sky-400';
                                                        elseif ($next_key === 'sudah_sampai_sekolah') echo 'bg-teal-500 hover:bg-teal-600 focus:ring-teal-400';
                                                        elseif ($next_key === 'sudah_dijemput') echo 'bg-green-500 hover:bg-green-600 focus:ring-green-400';
                                                        else echo 'bg-indigo-600 hover:bg-indigo-700 focus:ring-indigo-500';
                                                    ?> focus:outline-none focus:ring-2 focus:ring-offset-2">
                                                <i class="fas fa-arrow-right mr-2"></i> <?php echo htmlspecialchars($btn_text); ?>
                                            </button>
                                        </form>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-sm text-blue-600 italic text-center py-2"><i class="fas fa-hourglass-half mr-1"></i> Proses jemput hampir selesai atau menunggu konfirmasi sekolah.</p>
                            <?php endif; ?>
                        <?php 
                        elseif ($bisa_mulai_jemput_ui): ?>
                            <p class="text-sm font-medium text-gray-700 mb-2 text-center">Siapa yang akan menjemput?</p>
                            <form method="POST" action="index.php" class="space-y-3">
                                <input type="hidden" name="siswa_id" value="<?php echo $s_id; ?>">
                                <div>
                                    <label for="nama_penjemput_input_<?php echo $s_id; ?>" class="block text-xs font-medium text-gray-600 sr-only">Nama Penjemput:</label>
                                    <input type="text" name="nama_penjemput_input" id="nama_penjemput_input_<?php echo $s_id; ?>" required 
                                           class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                           placeholder="Nama Penjemput (Wajib)">
                                </div>
                                <div>
                                    <label for="catatan_penjemput_input_<?php echo $s_id; ?>" class="block text-xs font-medium text-gray-600 sr-only">Catatan (Opsional):</label>
                                    <textarea name="catatan_penjemput_input" id="catatan_penjemput_input_<?php echo $s_id; ?>" rows="2"
                                              class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"
                                              placeholder="Catatan (Opsional, mis: ciri-ciri kendaraan)"></textarea>
                                </div>
                                <button type="submit" name="set_penjemput_and_start"
                                        class="w-full flex items-center justify-center px-4 py-2.5 border border-transparent text-sm font-medium rounded-lg shadow-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-colors duration-150">
                                    <i class="fas fa-route mr-2"></i> Perjalanan Jemput
                                </button>
                            </form>
                        <?php 
                        else: ?>
                             <p class="text-sm text-gray-500 italic text-center py-2">
                                <i class="fas fa-hourglass-start mr-1 text-yellow-500"></i> Menunggu konfirmasi kehadiran dari Wali Kelas atau status anak tidak memungkinkan untuk dijemput saat ini.
                             </p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php elseif (!$db_error_fetching && !$db_connection_error): // Hanya tampilkan jika tidak ada error koneksi atau fetching ?>
        <div class="text-center py-20">
            <i class="fas fa-users fa-4x text-gray-300 mb-6"></i>
            <p class="text-xl text-gray-500">Belum ada data anak yang terhubung dengan akun Anda.</p>
            <p class="text-gray-400 mt-2">Pastikan data anak sudah ditambahkan oleh Administrator.</p>
        </div>
    <?php endif; ?>
</div>

<div id="pickupReminderModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full flex items-center justify-center hidden z-50 transition-opacity duration-300 ease-out opacity-0">
    <div class="relative mx-auto p-5 border w-11/12 sm:w-2/3 md:w-1/2 lg:w-1/3 shadow-lg rounded-md bg-white transform transition-all duration-300 ease-out scale-95 opacity-0" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <div class="mt-3 text-center">
            <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-yellow-100">
                <i class="fas fa-exclamation-triangle text-2xl text-yellow-500"></i>
            </div>
            <h3 class="text-lg leading-6 font-medium text-gray-900 mt-2" id="modalTitle">PERINGATAN PENJEMPUTAN</h3>
            <div class="mt-2 px-7 py-3">
                <p class="text-sm text-gray-500" id="modalMessageElement">
                    Penjemput sudah sampai di sekolah untuk <strong id="modalNamaSiswa" class="text-indigo-600">anak Anda</strong>.
                    <br><br>
                    Jika Anda sudah bersama anak, silakan klik tombol "Konfirmasi Sudah Menjemput Anak" pada kartu siswa yang bersangkutan.
                </p>
            </div>
            <div class="items-center px-4 py-3">
                <button id="closeModalButton" class="px-4 py-2 bg-gray-500 text-white text-base font-medium rounded-md w-full shadow-sm hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-300 transition-colors">
                    Saya Mengerti
                </button>
            </div>
        </div>
    </div>
</div>

<style>
    .info-badge { display: flex; align-items: center; padding: 0.5rem 0.75rem; border-radius: 0.5rem; font-size: 0.875rem; border-width: 1px; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); }
    .info-badge i { opacity: 0.8; }
    #pickupReminderModal.opacity-100 { opacity: 1; }
    #pickupReminderModal .scale-100 { transform: scale(1); }
    /* Tailwind Typography Prose styling (jika Anda menggunakannya) */
    .prose-sm :where(p):not(:where([class~="not-prose"] *)) { margin-top: 0.8em; margin-bottom: 0.8em; }
    .prose :where(strong):not(:where([class~="not-prose"] *)) { font-weight: 600; }
</style>

<script>
    function updateRealTimeClockWali() {
        const clockElement = document.getElementById('realTimeClockWali');
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
            clockElement.innerHTML = `<i class="far fa-calendar-alt mr-1.5"></i> ${dayName}, ${day} ${monthName} ${year}<br><i class="far fa-clock mr-1.5"></i> <strong class="text-lg">${hours}:${minutes}:${seconds}</strong>`;
        }
    }
    setInterval(updateRealTimeClockWali, 1000);
    updateRealTimeClockWali();

    document.addEventListener('DOMContentLoaded', function() {
        const modal = document.getElementById('pickupReminderModal');
        const modalContent = modal ? modal.querySelector('div[role="dialog"], .relative') : null;
        const closeModalButton = document.getElementById('closeModalButton');
        const modalMessageElement = document.getElementById('modalMessageElement');
        const modalNamaSiswaSpan = document.getElementById('modalNamaSiswa');

        const siswaCards = document.querySelectorAll('div[data-siswa-id]');
        const alertedSiswaModal = new Set(); 

        function showModal(namaSiswa) {
            if (!modal || !modalContent || !modalNamaSiswaSpan || !modalMessageElement) return;
            
            modalNamaSiswaSpan.textContent = namaSiswa;
            modalMessageElement.innerHTML = `Penjemput sudah sampai di sekolah untuk <strong class="text-indigo-600">${namaSiswa}</strong>.
                                <br><br>
                                Jika Anda sudah bersama ${namaSiswa}, silakan klik tombol "Konfirmasi Sudah Menjemput Anak" pada kartu siswa yang bersangkutan.`;
            
            modal.classList.remove('hidden');
            requestAnimationFrame(() => { 
                modal.classList.remove('opacity-0');
                if(modalContent) modalContent.classList.remove('scale-95', 'opacity-0');
                modal.classList.add('opacity-100');
                if(modalContent) modalContent.classList.add('scale-100', 'opacity-100');
            });
        }

        function hideModal() {
            if (!modal || !modalContent) return;
            modal.classList.remove('opacity-100');
            if(modalContent) modalContent.classList.remove('scale-100', 'opacity-100');
            modal.classList.add('opacity-0');
            if(modalContent) modalContent.classList.add('scale-95', 'opacity-0');
            
            setTimeout(() => {
                modal.classList.add('hidden');
            }, 300); 
        }

        if (closeModalButton) {
            closeModalButton.addEventListener('click', hideModal);
        }
        if (modal) {
            modal.addEventListener('click', function(event) {
                if (event.target === modal) {
                    hideModal();
                }
            });
        }
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && modal && !modal.classList.contains('hidden')) {
                hideModal();
            }
        });

        siswaCards.forEach(card => {
            const siswaId = card.dataset.siswaId;
            const namaSiswa = card.dataset.namaSiswa || 'anak Anda';
            const statusJemput = card.dataset.statusJemput;
            const flowAktif = card.dataset.flowAktif === 'true';

            if (statusJemput === 'sudah_sampai_sekolah' && flowAktif && !alertedSiswaModal.has(siswaId)) {
                showModal(namaSiswa);
                alertedSiswaModal.add(siswaId);
            }
        });

        var isSubmittingFormWali = false; 
        document.addEventListener('submit', function(event) {
            if (event.target.method && event.target.method.toLowerCase() === 'post') {
                if (event.target.closest('div.container')) {
                     isSubmittingFormWali = true;
                }
            }
        });

        setTimeout(function() {
            if (!isSubmittingFormWali) { 
                var activeElement = document.activeElement;
                if (activeElement && (activeElement.tagName === 'INPUT' || activeElement.tagName === 'TEXTAREA' || activeElement.tagName === 'SELECT')) {
                } else {
                     window.location.reload(true); 
                }
            }
        }, 30000);
    });
</script>

<?php
include '../includes/footer.php';
if(isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>