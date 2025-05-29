<?php
// 1. Mulai session dan include koneksi database & autoload (SEBELUM APAPUN)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include '../includes/db.php';
require '../vendor/autoload.php'; // Untuk PhpSpreadsheet

use PhpOffice\PhpSpreadsheet\IOFactory;

// 2. Cek otentikasi dan otorisasi (SEBELUM output apapun)
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php'); // Redirect jika tidak diizinkan
    exit;
}

// 3. Inisialisasi variabel dan konstanta
$message = '';
$DEFAULT_WALI_PASSWORD_FALLBACK = 'walmur123'; // Ganti default password
$selected_kelas_id_filter = isset($_GET['filter_kelas_id']) && $_GET['filter_kelas_id'] !== '' ? (int)$_GET['filter_kelas_id'] : null;

// --- Fungsi Bantu untuk Hashing Password ---
function hashPassword($password) {
    // return password_hash($password, PASSWORD_DEFAULT); // Idealnya
    return MD5($password); // TIDAK AMAN, sesuaikan dengan sistem Anda jika sudah pakai password_hash
}
// -------------------------------------------

// 4. Logika Pemrosesan Form (Tambah, Hapus, Upload) - SEMUA DI SINI SEBELUM HEADER
// Logika Tambah siswa (form manual)
if (isset($_POST['tambah'])) {
    $nama_siswa = isset($_POST['nama_siswa']) ? trim($_POST['nama_siswa']) : '';
    $kelas_id_form = isset($_POST['kelas_id']) ? (int)$_POST['kelas_id'] : 0;
    $username_wali = isset($_POST['username_wali_murid']) ? trim($_POST['username_wali_murid']) : '';
    $nama_wali_lengkap = isset($_POST['nama_wali_murid_lengkap']) ? trim($_POST['nama_wali_murid_lengkap']) : '';
    $password_wali_input = isset($_POST['password_wali_murid']) ? $_POST['password_wali_murid'] : '';

    if (empty($nama_siswa) || $kelas_id_form <= 0 || empty($username_wali) || empty($nama_wali_lengkap)) {
        $_SESSION['flash_message_manage_siswa'] = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Nama Siswa, Kelas, Username Wali, dan Nama Wali harus diisi!</p>";
    } else {
        $wali_murid_db_id = null;
        $conn->begin_transaction();
        try {
            // Cek atau buat/update user wali murid
            $stmt_check_wali = $conn->prepare("SELECT id, role FROM users WHERE username = ?");
            if (!$stmt_check_wali) throw new Exception("Error prepare (cek wali): " . $conn->error);
            $stmt_check_wali->bind_param("s", $username_wali);
            $stmt_check_wali->execute();
            $result_check_wali = $stmt_check_wali->get_result();

            if ($result_check_wali->num_rows > 0) { // Username wali sudah ada
                $existing_wali = $result_check_wali->fetch_assoc();
                if ($existing_wali['role'] === 'wali_murid') {
                    $wali_murid_db_id = $existing_wali['id'];
                    // Update nama dan password jika diisi
                    if (!empty($password_wali_input)) {
                        $hashed_new_password = hashPassword($password_wali_input);
                        $stmt_update_pass_wali = $conn->prepare("UPDATE users SET password = ?, nama = ? WHERE id = ?");
                        if (!$stmt_update_pass_wali) throw new Exception("Error prepare (update data wali): " . $conn->error);
                        $stmt_update_pass_wali->bind_param("ssi", $hashed_new_password, $nama_wali_lengkap, $wali_murid_db_id);
                        if (!$stmt_update_pass_wali->execute()) throw new Exception("Gagal update data wali: " . $stmt_update_pass_wali->error);
                        $stmt_update_pass_wali->close();
                    } else { // Hanya update nama jika password tidak diisi dan nama berbeda
                        $stmt_update_nama_wali = $conn->prepare("UPDATE users SET nama = ? WHERE id = ? AND nama <> ?");
                         if (!$stmt_update_nama_wali) throw new Exception("Error prepare (update nama wali): " . $conn->error);
                        $stmt_update_nama_wali->bind_param("sis", $nama_wali_lengkap, $wali_murid_db_id, $nama_wali_lengkap);
                        $stmt_update_nama_wali->execute(); // Tidak perlu cek error di sini, update jika perlu
                        $stmt_update_nama_wali->close();
                    }
                } else {
                    throw new Exception("Username '<strong>" . htmlspecialchars($username_wali) . "</strong>' sudah terdaftar tetapi bukan sebagai Wali Murid.");
                }
            } else { // Username wali baru -> buat
                $password_to_hash = !empty($password_wali_input) ? $password_wali_input : $DEFAULT_WALI_PASSWORD_FALLBACK;
                $hashed_password = hashPassword($password_to_hash);
                $role_wali = 'wali_murid';
                $stmt_create_wali = $conn->prepare("INSERT INTO users (username, password, role, nama) VALUES (?, ?, ?, ?)");
                if (!$stmt_create_wali) throw new Exception("Error prepare (buat wali): " . $conn->error);
                $stmt_create_wali->bind_param("ssss", $username_wali, $hashed_password, $role_wali, $nama_wali_lengkap);
                if (!$stmt_create_wali->execute()) throw new Exception("Gagal membuat akun Wali Murid baru: " . $stmt_create_wali->error);
                $wali_murid_db_id = $stmt_create_wali->insert_id;
                $stmt_create_wali->close();
            }
            $stmt_check_wali->close();

            // Validasi kelas
            $stmt_kelas_form = $conn->prepare("SELECT id FROM kelas WHERE id = ?");
            if (!$stmt_kelas_form) throw new Exception("Error prepare (kelas): " . $conn->error);
            $stmt_kelas_form->bind_param("i", $kelas_id_form);
            $stmt_kelas_form->execute();
            $result_kelas_form = $stmt_kelas_form->get_result();
            if ($result_kelas_form->num_rows === 0) throw new Exception("Kelas yang dipilih tidak valid!");
            $stmt_kelas_form->close();

            // Insert siswa
            $stmt_insert_siswa = $conn->prepare("INSERT INTO siswa (nama_siswa, kelas_id, id_wali_murid) VALUES (?, ?, ?)");
            if (!$stmt_insert_siswa) throw new Exception("Error prepare (insert siswa): " . $conn->error);
            $stmt_insert_siswa->bind_param("sii", $nama_siswa, $kelas_id_form, $wali_murid_db_id);
            if (!$stmt_insert_siswa->execute()) throw new Exception("Error menambah siswa: " . $stmt_insert_siswa->error);
            $stmt_insert_siswa->close();
            
            $conn->commit();
            $_SESSION['flash_message_manage_siswa'] = "<p class='text-green-500 p-3 bg-green-100 border border-green-400 rounded'>Siswa '<strong>" . htmlspecialchars($nama_siswa) . "</strong>' berhasil ditambahkan/diperbarui.</p>";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['flash_message_manage_siswa'] = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Operasi Gagal: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
    // Redirect dengan mempertahankan filter kelas
    $redirect_url_tambah = 'manage_siswa.php';
    if ($selected_kelas_id_filter) {
        $redirect_url_tambah .= '?filter_kelas_id=' . $selected_kelas_id_filter;
    }
    header('Location: ' . $redirect_url_tambah);
    exit;
}


// Logika Hapus siswa (individual)
if (isset($_GET['hapus']) && ctype_digit((string)$_GET['hapus'])) {
    $id_hapus = (int)$_GET['hapus'];
    $redirect_url_hapus = 'manage_siswa.php';
    if ($selected_kelas_id_filter) {
        $redirect_url_hapus .= '?filter_kelas_id=' . $selected_kelas_id_filter;
    }

    $stmt_get_nama = $conn->prepare("SELECT nama_siswa FROM siswa WHERE id = ?");
    $nama_siswa_deleted = "Siswa";
    if($stmt_get_nama){ $stmt_get_nama->bind_param("i", $id_hapus); $stmt_get_nama->execute(); $result_nama = $stmt_get_nama->get_result(); if($result_nama->num_rows > 0) $nama_siswa_deleted = $result_nama->fetch_assoc()['nama_siswa']; $stmt_get_nama->close(); }

    $conn->begin_transaction();
    try {
        $stmt_delete_status = $conn->prepare("DELETE FROM status_penjemputan WHERE siswa_id = ?");
        if (!$stmt_delete_status) throw new Exception("Error prepare (delete status): " . $conn->error);
        $stmt_delete_status->bind_param("i", $id_hapus);
        if (!$stmt_delete_status->execute()) throw new Exception("Gagal hapus status terkait: " . $stmt_delete_status->error);
        $stmt_delete_status->close();

        $stmt_delete_siswa = $conn->prepare("DELETE FROM siswa WHERE id = ?");
        if (!$stmt_delete_siswa) throw new Exception("Error prepare (delete siswa): " . $conn->error);
        $stmt_delete_siswa->bind_param("i", $id_hapus);
        if (!$stmt_delete_siswa->execute()) throw new Exception("Gagal hapus siswa: " . $stmt_delete_siswa->error);
        
        if ($stmt_delete_siswa->affected_rows > 0) {
            $_SESSION['flash_message_manage_siswa'] = "<p class='text-green-500 p-3 bg-green-100 border border-green-400 rounded'>Siswa '<strong>" . htmlspecialchars($nama_siswa_deleted) . "</strong>' dan riwayat statusnya berhasil dihapus.</p>";
        } else {
            $_SESSION['flash_message_manage_siswa'] = "<p class='text-yellow-500 p-3 bg-yellow-100 border border-yellow-400 rounded'>Siswa tidak ditemukan atau sudah dihapus.</p>";
        }
        $stmt_delete_siswa->close();
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['flash_message_manage_siswa'] = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Error saat menghapus: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    header('Location: ' . $redirect_url_hapus);
    exit;
}

// --- BARU: Logika Hapus Semua Siswa per Kelas ---
if (isset($_POST['hapus_siswa_per_kelas_submit']) && isset($_POST['kelas_id_to_delete_all_from']) && ctype_digit((string)$_POST['kelas_id_to_delete_all_from'])) {
    $kelas_id_for_mass_delete = (int)$_POST['kelas_id_to_delete_all_from'];
    $redirect_url_mass_delete = 'manage_siswa.php';
    if ($selected_kelas_id_filter) { // Seharusnya sama dengan $kelas_id_for_mass_delete
        $redirect_url_mass_delete .= '?filter_kelas_id=' . $selected_kelas_id_filter;
    }

    // Dapatkan nama kelas untuk pesan feedback
    $nama_kelas_deleted = "Kelas";
    $stmt_get_kelas_nama = $conn->prepare("SELECT nama_kelas FROM kelas WHERE id = ?");
    if ($stmt_get_kelas_nama) {
        $stmt_get_kelas_nama->bind_param("i", $kelas_id_for_mass_delete);
        $stmt_get_kelas_nama->execute();
        $result_kelas_nama = $stmt_get_kelas_nama->get_result();
        if ($result_kelas_nama->num_rows > 0) {
            $nama_kelas_deleted = $result_kelas_nama->fetch_assoc()['nama_kelas'];
        }
        $stmt_get_kelas_nama->close();
    }

    $conn->begin_transaction();
    try {
        // Hapus status penjemputan untuk semua siswa di kelas tersebut
        $stmt_delete_status_kelas = $conn->prepare("DELETE sp FROM status_penjemputan sp JOIN siswa s ON sp.siswa_id = s.id WHERE s.kelas_id = ?");
        if (!$stmt_delete_status_kelas) throw new Exception("Error prepare (delete status per kelas): " . $conn->error);
        $stmt_delete_status_kelas->bind_param("i", $kelas_id_for_mass_delete);
        if (!$stmt_delete_status_kelas->execute()) throw new Exception("Gagal hapus status terkait untuk kelas: " . $stmt_delete_status_kelas->error);
        $status_deleted_count = $stmt_delete_status_kelas->affected_rows;
        $stmt_delete_status_kelas->close();

        // Hapus semua siswa dari kelas tersebut
        $stmt_delete_siswa_kelas = $conn->prepare("DELETE FROM siswa WHERE kelas_id = ?");
        if (!$stmt_delete_siswa_kelas) throw new Exception("Error prepare (delete siswa per kelas): " . $conn->error);
        $stmt_delete_siswa_kelas->bind_param("i", $kelas_id_for_mass_delete);
        if (!$stmt_delete_siswa_kelas->execute()) throw new Exception("Gagal hapus siswa dari kelas: " . $stmt_delete_siswa_kelas->error);
        $siswa_deleted_count = $stmt_delete_siswa_kelas->affected_rows;

        if ($siswa_deleted_count > 0) {
            $_SESSION['flash_message_manage_siswa'] = "<p class='text-green-500 p-3 bg-green-100 border border-green-400 rounded'>Berhasil menghapus $siswa_deleted_count siswa dan $status_deleted_count riwayat status terkait dari kelas '<strong>" . htmlspecialchars($nama_kelas_deleted) . "</strong>'.</p>";
        } else {
            $_SESSION['flash_message_manage_siswa'] = "<p class='text-yellow-500 p-3 bg-yellow-100 border border-yellow-400 rounded'>Tidak ada siswa yang ditemukan atau sudah dihapus dari kelas '<strong>" . htmlspecialchars($nama_kelas_deleted) . "</strong>'.</p>";
        }
        $stmt_delete_siswa_kelas->close();
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['flash_message_manage_siswa'] = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Error saat menghapus siswa per kelas: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    header('Location: ' . $redirect_url_mass_delete);
    exit;
}
// --- AKHIR BARU: Logika Hapus Semua Siswa per Kelas ---


// Logika Upload Excel
if (isset($_POST['upload']) && isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === UPLOAD_ERR_OK) {
    $file_tmp_name = $_FILES['excel_file']['tmp_name'];
    $conn->begin_transaction();
    try {
        $spreadsheet = IOFactory::load($file_tmp_name);
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestRow();
        $success_count = 0; $error_count = 0; $skipped_rows_details = [];
        $wali_created_count = 0; $wali_updated_count = 0;

        for ($row = 2; $row <= $highestRow; $row++) {
            $nama_siswa_excel = trim($sheet->getCellByColumnAndRow(1, $row)->getValue());
            $nama_kelas_excel = trim($sheet->getCellByColumnAndRow(2, $row)->getValue());
            $username_wali_excel = trim($sheet->getCellByColumnAndRow(3, $row)->getValue());
            $nama_wali_excel = trim($sheet->getCellByColumnAndRow(4, $row)->getValue());
            $password_wali_excel = (string) $sheet->getCellByColumnAndRow(5, $row)->getValue();

            if (empty($nama_siswa_excel) && empty($nama_kelas_excel) && empty($username_wali_excel) && empty($nama_wali_excel)) continue;
            if (empty($nama_siswa_excel) || empty($nama_kelas_excel) || empty($username_wali_excel) || empty($nama_wali_excel)) {
                $skipped_rows_details[] = "Baris $row: Data tidak lengkap."; $error_count++; continue;
            }

            $kelas_id_excel = null;
            $stmt_kelas_excel = $conn->prepare("SELECT id FROM kelas WHERE nama_kelas = ?");
            if(!$stmt_kelas_excel) throw new Exception("Prepare error (kelas excel): ".$conn->error);
            $stmt_kelas_excel->bind_param("s", $nama_kelas_excel); $stmt_kelas_excel->execute();
            $res_kelas_excel = $stmt_kelas_excel->get_result();
            if ($res_kelas_excel->num_rows === 0) { $skipped_rows_details[] = "Baris $row: Kelas '$nama_kelas_excel' tidak ditemukan."; $error_count++; $stmt_kelas_excel->close(); continue; }
            $kelas_id_excel = $res_kelas_excel->fetch_assoc()['id']; $stmt_kelas_excel->close();

            $wali_murid_db_id_excel = null;
            $stmt_check_wali_excel = $conn->prepare("SELECT id, role FROM users WHERE username = ?");
            if(!$stmt_check_wali_excel) throw new Exception("Prepare error (cek wali excel): ".$conn->error);
            $stmt_check_wali_excel->bind_param("s", $username_wali_excel); $stmt_check_wali_excel->execute();
            $res_check_wali_excel = $stmt_check_wali_excel->get_result();
            if ($res_check_wali_excel->num_rows > 0) {
                $existing_wali_excel = $res_check_wali_excel->fetch_assoc();
                if ($existing_wali_excel['role'] === 'wali_murid') {
                    $wali_murid_db_id_excel = $existing_wali_excel['id'];
                    if (!empty(trim($password_wali_excel))) {
                        $hashed_new_pass_excel = hashPassword(trim($password_wali_excel));
                        $stmt_upd_pass_wali_excel = $conn->prepare("UPDATE users SET password = ?, nama = ? WHERE id = ?");
                        if(!$stmt_upd_pass_wali_excel) throw new Exception("Prepare error (update pass wali excel): ".$conn->error);
                        $stmt_upd_pass_wali_excel->bind_param("ssi", $hashed_new_pass_excel, $nama_wali_excel, $wali_murid_db_id_excel);
                        if(!$stmt_upd_pass_wali_excel->execute()) throw new Exception("Gagal update wali excel: ".$stmt_upd_pass_wali_excel->error);
                        $stmt_upd_pass_wali_excel->close(); $wali_updated_count++;
                    } else {
                        $stmt_upd_nama_excel = $conn->prepare("UPDATE users SET nama = ? WHERE id = ? AND nama <> ?");
                        if(!$stmt_upd_nama_excel) throw new Exception("Prepare error (update nama wali excel): ".$conn->error);
                        $stmt_upd_nama_excel->bind_param("sis", $nama_wali_excel, $wali_murid_db_id_excel, $nama_wali_excel);
                        $stmt_upd_nama_excel->execute(); $stmt_upd_nama_excel->close();
                    }
                } else { $skipped_rows_details[] = "Baris $row: Username Wali '$username_wali_excel' ada tapi bukan wali murid."; $error_count++; $stmt_check_wali_excel->close(); continue; }
            } else {
                $pass_to_hash_excel = !empty(trim($password_wali_excel)) ? trim($password_wali_excel) : $DEFAULT_WALI_PASSWORD_FALLBACK;
                $hashed_pass_excel = hashPassword($pass_to_hash_excel);
                $role_wali_excel = 'wali_murid';
                $stmt_create_wali_excel = $conn->prepare("INSERT INTO users (username, password, role, nama) VALUES (?, ?, ?, ?)");
                 if(!$stmt_create_wali_excel) throw new Exception("Prepare error (create wali excel): ".$conn->error);
                $stmt_create_wali_excel->bind_param("ssss", $username_wali_excel, $hashed_pass_excel, $role_wali_excel, $nama_wali_excel);
                if ($stmt_create_wali_excel->execute()) { $wali_murid_db_id_excel = $stmt_create_wali_excel->insert_id; $wali_created_count++; }
                else { $skipped_rows_details[] = "Baris $row: Gagal buat Wali '$username_wali_excel'. Error: " . $stmt_create_wali_excel->error; $error_count++; $stmt_create_wali_excel->close(); continue; }
                $stmt_create_wali_excel->close();
            }
            $stmt_check_wali_excel->close();

            $stmt_insert_excel = $conn->prepare("INSERT INTO siswa (nama_siswa, kelas_id, id_wali_murid) VALUES (?, ?, ?)");
            if(!$stmt_insert_excel) throw new Exception("Prepare error (insert siswa excel): ".$conn->error);
            $stmt_insert_excel->bind_param("sii", $nama_siswa_excel, $kelas_id_excel, $wali_murid_db_id_excel);
            if ($stmt_insert_excel->execute()) { $success_count++; }
            else { $skipped_rows_details[] = "Baris $row (Siswa: $nama_siswa_excel): Gagal simpan siswa. Error: " . $stmt_insert_excel->error; $error_count++; }
            $stmt_insert_excel->close();
        } // end for loop

        if ($error_count > 0) {
            $conn->rollback();
            $msg_excel = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Impor Excel Gagal/sebagian. $success_count siswa diproses, $error_count gagal. ($wali_created_count wali baru, $wali_updated_count wali diupdate sebelum rollback).</p>";
            if (!empty($skipped_rows_details)) { $msg_excel .= "<p class='text-sm text-gray-700 mt-2'>Detail:</p><ul class='list-disc list-inside text-sm text-red-600 max-h-40 overflow-y-auto'>"; foreach ($skipped_rows_details as $skip) { $msg_excel .= "<li>" . htmlspecialchars($skip) . "</li>"; } $msg_excel .= "</ul>"; }
        } else {
            $conn->commit();
            $msg_parts_excel = [];
            if ($success_count > 0) $msg_parts_excel[] = "$success_count data siswa dari Excel berhasil diimpor";
            if ($wali_created_count > 0) $msg_parts_excel[] = "$wali_created_count akun wali murid baru dibuat";
            if ($wali_updated_count > 0) $msg_parts_excel[] = "$wali_updated_count akun wali murid diupdate";
            if (!empty($msg_parts_excel)) { $msg_excel = "<p class='text-green-500 p-3 bg-green-100 border border-green-400 rounded'>" . implode("! ", $msg_parts_excel) . "!</p>"; }
            else { $msg_excel = "<p class='text-yellow-500 p-3 bg-yellow-100 border border-yellow-400 rounded'>Tidak ada data valid diimpor dari Excel.</p>"; }
        }
        $_SESSION['flash_message_manage_siswa'] = $msg_excel;

    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollback();
        $_SESSION['flash_message_manage_siswa'] = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Error proses Excel: " . htmlspecialchars($e->getMessage()) . "</p>";
    }

    $redirect_url_upload = 'manage_siswa.php';
    if ($selected_kelas_id_filter) {
        $redirect_url_upload .= '?filter_kelas_id=' . $selected_kelas_id_filter;
    }
    header('Location: ' . $redirect_url_upload);
    exit;
}


// Menampilkan flash message yang mungkin diset oleh logika di atas
if (isset($_SESSION['flash_message_manage_siswa'])) {
    $message = $_SESSION['flash_message_manage_siswa'];
    unset($_SESSION['flash_message_manage_siswa']);
}

// Ambil daftar kelas untuk dropdown filter dan form tambah
$kelas_options_all = [];
$query_kelas_all = "SELECT id, nama_kelas FROM kelas ORDER BY nama_kelas";
$result_kelas_all = $conn->query($query_kelas_all);
if ($result_kelas_all) {
    while ($row_kelas_item = $result_kelas_all->fetch_assoc()) {
        $kelas_options_all[] = $row_kelas_item;
    }
}

// Ambil daftar username wali murid untuk datalist di form tambah
$wali_murid_usernames_options = [];
$query_wali_opts = "SELECT username FROM users WHERE role = 'wali_murid' ORDER BY username";
$result_wali_opts = $conn->query($query_wali_opts);
if ($result_wali_opts && $result_wali_opts->num_rows > 0) {
    while ($row_wali_opt = $result_wali_opts->fetch_assoc()) {
        $wali_murid_usernames_options[] = $row_wali_opt['username'];
    }
    $result_wali_opts->free();
}

// 5. SEKARANG BARU INCLUDE header.php
$assets_path_prefix = '../';
include '../includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <h2 class="text-2xl font-bold mb-6 text-gray-800"><i class="fas fa-user-friends mr-2"></i> Manajemen Siswa & Wali Murid</h2>

    <?php if (!empty($message)): ?>
        <div class="mb-4">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <!-- Form Tambah Siswa & Wali Murid -->
    <div class="bg-white shadow-md rounded-lg p-6 mb-8">
        <h3 class="text-xl font-semibold mb-4 text-gray-700">Tambah Siswa & Wali Murid Baru/Update Wali</h3>
        <form method="POST" action="manage_siswa.php<?php echo $selected_kelas_id_filter ? '?filter_kelas_id='.$selected_kelas_id_filter : ''; ?>" class="space-y-6">
            <fieldset class="border border-gray-300 p-4 rounded-md">
                <legend class="text-lg font-medium text-gray-700 px-2">Data Siswa</legend>
                <div class="space-y-4 mt-2">
                    <div>
                        <label for="nama_siswa_form" class="block text-sm font-medium text-gray-700">Nama Lengkap Siswa</label>
                        <input type="text" name="nama_siswa" id="nama_siswa_form" placeholder="Nama Lengkap Siswa" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required value="<?php echo isset($_POST['nama_siswa']) && !empty($message) ? htmlspecialchars($_POST['nama_siswa']) : ''; ?>">
                    </div>
                    <div>
                        <label for="kelas_id_form" class="block text-sm font-medium text-gray-700">Kelas</label>
                        <select name="kelas_id" id="kelas_id_form" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required>
                            <option value="">Pilih Kelas</option>
                            <?php
                            $selected_kelas_form_val = isset($_POST['kelas_id']) && !empty($message) ? (int)$_POST['kelas_id'] : 0;
                            foreach ($kelas_options_all as $kelas_opt_f_item) {
                                $selected_f_item = ($kelas_opt_f_item['id'] == $selected_kelas_form_val) ? 'selected' : '';
                                echo "<option value='{$kelas_opt_f_item['id']}' $selected_f_item>" . htmlspecialchars($kelas_opt_f_item['nama_kelas']) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                </div>
            </fieldset>

            <fieldset class="border border-gray-300 p-4 rounded-md">
                <legend class="text-lg font-medium text-gray-700 px-2">Data Wali Murid</legend>
                <p class="text-xs text-gray-500 mb-2">Jika username baru, akun akan dibuat. Jika sudah ada (role wali murid), data (nama, password jika diisi) akan diupdate.</p>
                <div class="space-y-4 mt-2">
                    <div>
                        <label for="username_wali_murid_form" class="block text-sm font-medium text-gray-700">Username Wali Murid</label>
                        <input type="text" name="username_wali_murid" id="username_wali_murid_form" list="wali_murid_list_form" placeholder="Ketik Username (baru atau yang sudah ada)" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required value="<?php echo isset($_POST['username_wali_murid']) && !empty($message) ? htmlspecialchars($_POST['username_wali_murid']) : ''; ?>">
                        <datalist id="wali_murid_list_form">
                            <?php foreach ($wali_murid_usernames_options as $username_wali_opt_item): ?>
                                <option value="<?php echo htmlspecialchars($username_wali_opt_item); ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div>
                        <label for="nama_wali_murid_lengkap_form" class="block text-sm font-medium text-gray-700">Nama Lengkap Wali Murid</label>
                        <input type="text" name="nama_wali_murid_lengkap" id="nama_wali_murid_lengkap_form" placeholder="Nama Lengkap Wali Murid" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required value="<?php echo isset($_POST['nama_wali_murid_lengkap']) && !empty($message) ? htmlspecialchars($_POST['nama_wali_murid_lengkap']) : ''; ?>">
                    </div>
                    <div>
                        <label for="password_wali_murid_form" class="block text-sm font-medium text-gray-700">Password Wali Murid</label>
                        <input type="password" name="password_wali_murid" id="password_wali_murid_form" placeholder="Kosongkan jika tidak ingin mengubah/default untuk baru" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                        <p class="mt-1 text-xs text-gray-500">Untuk wali baru, jika dikosongkan, password default (<strong><?php echo htmlspecialchars($DEFAULT_WALI_PASSWORD_FALLBACK); ?></strong>) akan digunakan.</p>
                    </div>
                </div>
            </fieldset>
            <button type="submit" name="tambah" class="inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                <i class="fas fa-save mr-2"></i> Simpan Data
            </button>
        </form>
    </div>

    <!-- Form Upload Excel -->
    <div class="bg-white shadow-md rounded-lg p-6 mb-8">
        <h3 class="text-xl font-semibold mb-4 text-gray-700">Upload Data Siswa dari Excel</h3>
        <p class="text-sm text-gray-600 mb-1">Format Excel:</p>
        <ul class="list-disc list-inside text-sm text-gray-600 mb-2">
            <li>Kolom A: Nama Siswa</li><li>Kolom B: Nama Kelas</li><li>Kolom C: Username Wali</li>
            <li>Kolom D: Nama Wali</li><li>Kolom E: Password Wali (opsional)</li>
        </ul>
        <form method="POST" enctype="multipart/form-data" action="manage_siswa.php<?php echo $selected_kelas_id_filter ? '?filter_kelas_id='.$selected_kelas_id_filter : ''; ?>">
            <div class="flex flex-col sm:flex-row items-center space-y-2 sm:space-y-0 sm:space-x-4">
                <input type="file" name="excel_file" accept=".xlsx,.xls" class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100" required>
                <button type="submit" name="upload" class="w-full sm:w-auto inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700">
                    <i class="fas fa-upload mr-2"></i> Upload Excel
                </button>
            </div>
        </form>
    </div>

    <!-- Filter Kelas untuk Tabel -->
    <div class="mb-4 flex justify-end">
        <form method="GET" action="manage_siswa.php" class="flex items-center space-x-2">
            <label for="filter_kelas_id_table_display" class="text-sm font-medium text-gray-700">Filter Kelas Tabel:</label>
            <select name="filter_kelas_id" id="filter_kelas_id_table_display" class="p-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" onchange="this.form.submit()">
                <option value="">Semua Kelas</option>
                <?php foreach ($kelas_options_all as $kelas_opt_t_item): ?>
                    <option value="<?php echo $kelas_opt_t_item['id']; ?>" <?php echo ($selected_kelas_id_filter == $kelas_opt_t_item['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($kelas_opt_t_item['nama_kelas']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <noscript><button type="submit" class="p-2 bg-gray-200 rounded text-sm">Filter</button></noscript>
            <?php if ($selected_kelas_id_filter): ?>
                <a href="manage_siswa.php" class="text-sm text-indigo-600 hover:underline">(Reset Filter)</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Tabel Siswa -->
    <div class="bg-white shadow-md rounded-lg overflow-x-auto">
        <div class="flex justify-between items-center p-4 border-b">
            <h3 class="text-xl font-semibold text-gray-700">Daftar Siswa <?php 
                if ($selected_kelas_id_filter && count($kelas_options_all) > 0) {
                    foreach($kelas_options_all as $ko_item) { if ($ko_item['id'] == $selected_kelas_id_filter) { echo "(Kelas: " . htmlspecialchars($ko_item['nama_kelas']) . ")"; break; } }
                }
            ?></h3>
            
            <!-- BARU: Tombol Hapus Semua Siswa di Kelas Ini -->
            <?php if ($selected_kelas_id_filter): ?>
                <?php
                $nama_kelas_terfilter = "kelas yang dipilih";
                foreach($kelas_options_all as $ko_item) { 
                    if ($ko_item['id'] == $selected_kelas_id_filter) { 
                        $nama_kelas_terfilter = htmlspecialchars($ko_item['nama_kelas']); 
                        break; 
                    } 
                }
                ?>
                <form method="POST" action="manage_siswa.php<?php echo $selected_kelas_id_filter ? '?filter_kelas_id='.$selected_kelas_id_filter : ''; ?>" 
                      onsubmit="return confirm('Anda YAKIN ingin menghapus SEMUA siswa dari kelas <?php echo addslashes($nama_kelas_terfilter); ?>? Tindakan ini tidak dapat diurungkan dan juga akan menghapus semua riwayat status penjemputan mereka.');" 
                      class="ml-4">
                    <input type="hidden" name="kelas_id_to_delete_all_from" value="<?php echo htmlspecialchars($selected_kelas_id_filter); ?>">
                    <button type="submit" name="hapus_siswa_per_kelas_submit" 
                            class="px-3 py-2 text-xs font-medium text-center text-white bg-red-700 rounded-lg hover:bg-red-800 focus:ring-4 focus:outline-none focus:ring-red-300 dark:bg-red-600 dark:hover:bg-red-700 dark:focus:ring-red-800">
                        <i class="fas fa-users-slash mr-1"></i> Hapus Semua di Kelas <?php echo $nama_kelas_terfilter; ?>
                    </button>
                </form>
            <?php endif; ?>
            <!-- AKHIR BARU -->
        </div>

        <table class="w-full min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nama Siswa</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kelas</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nama Wali</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Username Wali</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Pass Wali (Hash)</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Aksi</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php
                // Query untuk menampilkan siswa dengan filter kelas
                $sql_select_siswa_display = "SELECT s.id AS siswa_id, s.nama_siswa, k.nama_kelas, 
                                            u.nama AS nama_wali_murid, u.username AS username_wali_murid,
                                            u.password AS password_wali_hashed 
                                     FROM siswa s
                                     LEFT JOIN kelas k ON s.kelas_id = k.id
                                     LEFT JOIN users u ON s.id_wali_murid = u.id ";
                $params_select_siswa_display = [];
                $types_select_siswa_display = "";

                if ($selected_kelas_id_filter !== null) {
                    $sql_select_siswa_display .= " WHERE s.kelas_id = ? ";
                    $params_select_siswa_display[] = $selected_kelas_id_filter;
                    $types_select_siswa_display .= "i";
                }
                $sql_select_siswa_display .= " ORDER BY k.nama_kelas, s.nama_siswa";

                $stmt_select_siswa_display = $conn->prepare($sql_select_siswa_display);
                $data_siswa_tabel_display = [];
                if ($stmt_select_siswa_display) {
                    if (!empty($params_select_siswa_display)) {
                        $stmt_select_siswa_display->bind_param($types_select_siswa_display, ...$params_select_siswa_display);
                    }
                    $stmt_select_siswa_display->execute();
                    $result_siswa_tabel_display = $stmt_select_siswa_display->get_result();
                    if($result_siswa_tabel_display){
                        while($row_s_t_item = $result_siswa_tabel_display->fetch_assoc()){
                            $data_siswa_tabel_display[] = $row_s_t_item;
                        }
                    }
                    $stmt_select_siswa_display->close();
                }


                if (!empty($data_siswa_tabel_display)) {
                    foreach ($data_siswa_tabel_display as $row_siswa_item_display) {
                        echo "<tr>
                                <td class='px-6 py-4 whitespace-nowrap text-sm text-gray-500'>" . htmlspecialchars($row_siswa_item_display['siswa_id']) . "</td>
                                <td class='px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900'>" . htmlspecialchars($row_siswa_item_display['nama_siswa']) . "</td>
                                <td class='px-6 py-4 whitespace-nowrap text-sm text-gray-500'>" . htmlspecialchars($row_siswa_item_display['nama_kelas'] ?? 'N/A') . "</td>
                                <td class='px-6 py-4 whitespace-nowrap text-sm text-gray-500'>" . htmlspecialchars($row_siswa_item_display['nama_wali_murid'] ?? 'N/A') . "</td>
                                <td class='px-6 py-4 whitespace-nowrap text-sm text-gray-500'>" . htmlspecialchars($row_siswa_item_display['username_wali_murid'] ?? 'N/A') . "</td>
                                <td class='px-6 py-4 whitespace-nowrap text-xs text-gray-400 break-all' title='" . htmlspecialchars($row_siswa_item_display['password_wali_hashed'] ?? 'N/A') . "'>" . (!empty($row_siswa_item_display['password_wali_hashed']) ? substr(htmlspecialchars($row_siswa_item_display['password_wali_hashed']), 0, 10) . "..." : 'N/A') . "</td>
                                <td class='px-6 py-4 whitespace-nowrap text-sm font-medium space-x-1 md:space-x-2'>
                                    <a href='edit_siswa.php?id=" . htmlspecialchars($row_siswa_item_display['siswa_id']) . ($selected_kelas_id_filter ? '&ref_kelas_id='.$selected_kelas_id_filter.'&ref_page=manage_siswa' : '&ref_page=manage_siswa') . "' class='text-indigo-600 hover:text-indigo-900' title='Edit Data Siswa'><i class='fas fa-user-edit'></i><span class='hidden sm:inline ml-1'>Edit</span></a>
                                    <a href='manage_siswa.php?hapus=" . htmlspecialchars($row_siswa_item_display['siswa_id']) . ($selected_kelas_id_filter ? '&filter_kelas_id='.$selected_kelas_id_filter : '') . "' class='text-red-600 hover:text-red-900' onclick='return confirm(\"Hapus siswa " . htmlspecialchars(addslashes($row_siswa_item_display['nama_siswa'])) . "? Ini juga akan menghapus riwayat statusnya.\")' title='Hapus Siswa'><i class='fas fa-trash'></i><span class='hidden sm:inline ml-1'>Hapus</span></a>
                                </td>
                              </tr>";
                    }
                } else {
                    $colspan_tabel_display = 7;
                     if (isset($stmt_select_siswa_display) && $stmt_select_siswa_display && $stmt_select_siswa_display->error) {
                        echo "<tr><td colspan='$colspan_tabel_display' class='px-6 py-4 text-sm text-red-500 text-center'>Gagal mengambil data siswa.</td></tr>";
                    } else {
                        echo "<tr><td colspan='$colspan_tabel_display' class='px-6 py-4 text-sm text-gray-500 text-center'>Tidak ada data siswa yang cocok dengan filter atau belum ada data.</td></tr>";
                    }
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

<?php
include '../includes/footer.php';
$conn->close();
?>