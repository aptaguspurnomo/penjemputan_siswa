<?php
// admin/index.php

// Aktifkan error reporting untuk development
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/db.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo "<!DOCTYPE html><html><head><title>Akses Ditolak</title><script src='https://cdn.tailwindcss.com'></script></head><body class='bg-gray-100 flex items-center justify-center h-screen'><div class='text-center'><h1 class='text-2xl font-bold text-red-600 mb-4'>Akses Ditolak</h1><p class='text-red-500'>Anda harus login sebagai admin untuk mengakses halaman ini.</p><a href='../login.php' class='mt-4 inline-block bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded'>Kembali ke Login</a></div></body></html>";
    exit;
}

// --- VARIABEL UTAMA ---
$message_app_settings = ''; // Pesan untuk pengaturan aplikasi
$message_pengumuman = ''; // Pesan untuk manajemen pengumuman
$upload_dir_from_root = 'assets/uploads/'; // Relative to web root
$target_dir_for_upload = realpath(__DIR__ . '/../' . $upload_dir_from_root) . '/'; // Absolute server path

$db_ready_for_color_settings = false;
$admin_user_id = (int)$_SESSION['user_id'];

// --- PENGATURAN APLIKASI (LOGO, WARNA, DLL) ---
$current_settings = [
    'id' => 1, 'logo_sekolah' => '', 'header_image' => '',
    'footer_text'  => 'Default Footer (DB Error)', 'nama_sekolah' => 'Sekolah Default (DB Error)',
    'header_color' => '#FFFFFF', 'footer_color' => '#333333'
];

if (isset($conn) && $conn->ping()) {
    $check_columns_query = "SHOW COLUMNS FROM pengaturan LIKE 'header_color'";
    $check_columns_result = $conn->query($check_columns_query);
    if ($check_columns_result && $check_columns_result->num_rows > 0) {
        $db_ready_for_color_settings = true;
    } else {
        $message_app_settings .= "<p class='text-yellow-600 p-3 bg-yellow-100 border border-yellow-400 rounded'><strong>Peringatan:</strong> Kolom 'header_color'/'footer_color' tidak ada. Fitur warna dinonaktifkan. <a href='#' onclick='alert(\"ALTER TABLE pengaturan ADD COLUMN header_color VARCHAR(7) DEFAULT \\'#FFFFFF\\', ADD COLUMN footer_color VARCHAR(7) DEFAULT \\'#333333\\';\")' class='underline'>Lihat Query</a></p>";
    }

    $query_current_settings = $db_ready_for_color_settings ? 
        "SELECT id, logo_sekolah, header_image, footer_text, nama_sekolah, header_color, footer_color FROM pengaturan WHERE id = 1 LIMIT 1" :
        "SELECT id, logo_sekolah, header_image, footer_text, nama_sekolah FROM pengaturan WHERE id = 1 LIMIT 1";
    
    $result_current_settings = $conn->query($query_current_settings);

    if ($result_current_settings) {
        if ($result_current_settings->num_rows > 0) {
            $fetched_settings = $result_current_settings->fetch_assoc();
            $current_settings = array_merge($current_settings, $fetched_settings);
            if (!$db_ready_for_color_settings) {
                $current_settings['header_color'] = '#FFFFFF';
                $current_settings['footer_color'] = '#333333';
            }
        } else {
            // Baris pengaturan tidak ada, buat default
            $default_nama_sekolah = 'Nama Sekolah Anda';
            $default_footer_text = '© ' . date('Y') . ' Aplikasi Penjemputan Siswa.';
            $default_header_color = '#FFFFFF';
            $default_footer_color = '#333333';

            if ($db_ready_for_color_settings) {
                $stmt_insert_default = $conn->prepare("INSERT INTO pengaturan (id, nama_sekolah, footer_text, header_color, footer_color) VALUES (1, ?, ?, ?, ?)");
                $stmt_insert_default->bind_param("ssss", $default_nama_sekolah, $default_footer_text, $default_header_color, $default_footer_color);
            } else {
                $stmt_insert_default = $conn->prepare("INSERT INTO pengaturan (id, nama_sekolah, footer_text) VALUES (1, ?, ?)");
                $stmt_insert_default->bind_param("ss", $default_nama_sekolah, $default_footer_text);
            }
            if ($stmt_insert_default && $stmt_insert_default->execute()) {
                $current_settings['nama_sekolah'] = $default_nama_sekolah;
                $current_settings['footer_text'] = $default_footer_text;
                if ($db_ready_for_color_settings) {
                    $current_settings['header_color'] = $default_header_color;
                    $current_settings['footer_color'] = $default_footer_color;
                }
                $message_app_settings .= "<p class='text-blue-600 p-3 bg-blue-100 border border-blue-400 rounded'>Baris pengaturan default dibuat.</p>";
            } else {
                $message_app_settings .= "<p class='text-red-600 p-3 bg-red-100 border border-red-400 rounded'>Gagal buat pengaturan default: " . htmlspecialchars($conn->error) . "</p>";
            }
            if ($stmt_insert_default) $stmt_insert_default->close();
        }
    } else {
        $message_app_settings .= "<p class='text-red-600 p-3 bg-red-100 border border-red-400 rounded'>Error ambil pengaturan: " . htmlspecialchars($conn->error) . "</p>";
    }
} else {
    $message_app_settings = "<p class='text-red-600 p-3 bg-red-100 border border-red-400 rounded'>Koneksi DB gagal. Pengaturan tidak dapat diakses.</p>";
}


// --- LOGIKA PENGUMUMAN SEKOLAH (Ambil data) ---
$pengumuman_list = [];
$edit_pengumuman_data = null;

if (isset($conn) && $conn->ping()) {
    // Ambil semua pengumuman
    $stmt_get_all_pengumuman = $conn->prepare("SELECT p.*, u.nama AS nama_pembuat FROM pengumuman_sekolah p LEFT JOIN users u ON p.dibuat_oleh = u.id ORDER BY p.timestamp_diupdate DESC");
    if ($stmt_get_all_pengumuman) {
        $stmt_get_all_pengumuman->execute();
        $result_all_pengumuman = $stmt_get_all_pengumuman->get_result();
        while ($row_pengumuman = $result_all_pengumuman->fetch_assoc()) {
            $pengumuman_list[] = $row_pengumuman;
        }
        $stmt_get_all_pengumuman->close();
    } else {
        $message_pengumuman .= "<p class='text-red-600 p-3 bg-red-100 border border-red-400 rounded'>Gagal ambil daftar pengumuman: " . htmlspecialchars($conn->error) . "</p>";
    }

    // Cek request edit
    if (isset($_GET['edit_pengumuman_id'])) {
        $edit_id = (int)$_GET['edit_pengumuman_id'];
        $stmt_get_edit = $conn->prepare("SELECT * FROM pengumuman_sekolah WHERE id = ?");
        if ($stmt_get_edit) {
            $stmt_get_edit->bind_param("i", $edit_id);
            $stmt_get_edit->execute();
            $result_edit = $stmt_get_edit->get_result();
            $edit_pengumuman_data = ($result_edit->num_rows === 1) ? $result_edit->fetch_assoc() : null;
            if (!$edit_pengumuman_data) {
                $message_pengumuman .= "<p class='text-yellow-600 p-3 bg-yellow-100 border border-yellow-400 rounded'>Pengumuman untuk diedit tidak ditemukan.</p>";
            }
            $stmt_get_edit->close();
        } else {
            $message_pengumuman .= "<p class='text-red-600 p-3 bg-red-100 border border-red-400 rounded'>Gagal ambil data pengumuman edit: " . htmlspecialchars($conn->error) . "</p>";
        }
    }
}


// --- PROSES FORM (POST REQUESTS) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($conn) && $conn->ping()) {
    
    // Proses Form Pengaturan Aplikasi
    if (isset($_POST['save_app_settings'])) {
        $new_nama_sekolah = isset($_POST['nama_sekolah']) ? trim($_POST['nama_sekolah']) : $current_settings['nama_sekolah'];
        $new_footer_text  = isset($_POST['footer_text']) ? trim($_POST['footer_text']) : $current_settings['footer_text'];
        
        $new_header_color = $current_settings['header_color'];
        $new_footer_color = $current_settings['footer_color'];
        if ($db_ready_for_color_settings) {
            $new_header_color = (isset($_POST['header_color']) && preg_match('/^#[a-f0-9]{6}$/i', $_POST['header_color'])) ? $_POST['header_color'] : $current_settings['header_color'];
            $new_footer_color = (isset($_POST['footer_color']) && preg_match('/^#[a-f0-9]{6}$/i', $_POST['footer_color'])) ? $_POST['footer_color'] : $current_settings['footer_color'];
        }

        $new_logo_path_db = $current_settings['logo_sekolah'];
        $new_header_path_db = $current_settings['header_image'];
        $upload_errors = [];
        $allowed_types = ["jpg", "jpeg", "png", "gif", "webp"];

        function process_image_upload_admin($file_input_name, $current_db_path, $target_dir_abs, $db_path_prefix_from_root, &$upload_errors_array, $field_label = "Gambar") {
            if (isset($_FILES[$file_input_name]) && $_FILES[$file_input_name]["error"] === UPLOAD_ERR_OK) {
                $file_name_original = basename($_FILES[$file_input_name]["name"]);
                $safe_name = time() . "_" . preg_replace("/[^a-zA-Z0-9._-]/", "_", $file_name_original);
                $target_file_server_abs = rtrim($target_dir_abs, '/') . '/' . $safe_name;
                $path_for_db = rtrim($db_path_prefix_from_root, '/') . '/' . $safe_name;
                global $allowed_types; // Akses global variable

                $imageFileType = strtolower(pathinfo($target_file_server_abs, PATHINFO_EXTENSION));

                if (!in_array($imageFileType, $allowed_types)) {
                    $upload_errors_array[] = "Hanya file JPG, JPEG, PNG, GIF, WEBP yang diizinkan untuk $field_label.";
                    return $current_db_path;
                }
                if ($_FILES[$file_input_name]["size"] > 2097152) { // 2MB
                    $upload_errors_array[] = "Ukuran file $field_label terlalu besar (maks 2MB).";
                    return $current_db_path;
                }
                if (!is_dir($target_dir_abs)) {
                    if (!mkdir($target_dir_abs, 0775, true)) {
                        $upload_errors_array[] = "Gagal membuat direktori upload: " . $target_dir_abs;
                        error_log("Gagal membuat direktori: " . $target_dir_abs);
                        return $current_db_path;
                    }
                }
                if (move_uploaded_file($_FILES[$file_input_name]["tmp_name"], $target_file_server_abs)) {
                    // Hapus file lama jika ada dan bukan path default
                    if (!empty($current_db_path) && $current_db_path !== $path_for_db && file_exists(realpath(__DIR__ . '/../' . $current_db_path)) && strpos($current_db_path, 'default') === false) {
                        // unlink(realpath(__DIR__ . '/../' . $current_db_path)); // Aktifkan jika ingin menghapus file lama
                    }
                    return $path_for_db;
                } else {
                    $upload_errors_array[] = "Gagal mengupload $field_label. Periksa izin folder.";
                    error_log("Gagal move_uploaded_file untuk $target_file_server_abs");
                    return $current_db_path;
                }
            } elseif (isset($_FILES[$file_input_name]) && $_FILES[$file_input_name]["error"] !== UPLOAD_ERR_NO_FILE) {
                $upload_errors_array[] = "Error saat upload $field_label (Kode: " . $_FILES[$file_input_name]["error"] . ").";
            }
            return $current_db_path;
        }

        $new_logo_path_db = process_image_upload_admin('logo_sekolah', $current_settings['logo_sekolah'], $target_dir_for_upload, $upload_dir_from_root, $upload_errors, "Logo Sekolah");
        $new_header_path_db = process_image_upload_admin('header_image', $current_settings['header_image'], $target_dir_for_upload, $upload_dir_from_root, $upload_errors, "Header Image");

        if (empty($upload_errors)) {
            if ($db_ready_for_color_settings) {
                $stmt_update_app = $conn->prepare("UPDATE pengaturan SET nama_sekolah = ?, logo_sekolah = ?, header_image = ?, footer_text = ?, header_color = ?, footer_color = ? WHERE id = 1");
                $stmt_update_app->bind_param("ssssss", $new_nama_sekolah, $new_logo_path_db, $new_header_path_db, $new_footer_text, $new_header_color, $new_footer_color);
            } else {
                $stmt_update_app = $conn->prepare("UPDATE pengaturan SET nama_sekolah = ?, logo_sekolah = ?, header_image = ?, footer_text = ? WHERE id = 1");
                $stmt_update_app->bind_param("ssss", $new_nama_sekolah, $new_logo_path_db, $new_header_path_db, $new_footer_text);
            }

            if ($stmt_update_app && $stmt_update_app->execute()) {
                $_SESSION['flash_message_admin_index'] = ['type' => 'success', 'text' => 'Pengaturan aplikasi berhasil disimpan!'];
                // Update $current_settings agar tampilan langsung refresh
                $current_settings = array_merge($current_settings, [
                    'nama_sekolah' => $new_nama_sekolah, 'logo_sekolah' => $new_logo_path_db,
                    'header_image' => $new_header_path_db, 'footer_text'  => $new_footer_text,
                    'header_color' => $new_header_color, 'footer_color' => $new_footer_color
                ]);
                $_SESSION['app_settings'] = $current_settings; // Update juga di session
            } else {
                $_SESSION['flash_message_admin_index'] = ['type' => 'error', 'text' => 'Error menyimpan pengaturan aplikasi: ' . htmlspecialchars($stmt_update_app ? $stmt_update_app->error : $conn->error)];
            }
            if ($stmt_update_app) $stmt_update_app->close();
        } else {
            $error_list_html = "<ul>";
            foreach ($upload_errors as $err) { $error_list_html .= "<li>" . htmlspecialchars($err) . "</li>"; }
            $error_list_html .= "</ul>";
            $_SESSION['flash_message_admin_index'] = ['type' => 'error', 'text' => 'Gagal menyimpan karena error upload:<br>' . $error_list_html];
        }
        header("Location: index.php#pengaturan-aplikasi");
        exit;
    }
    // Proses Form Pengumuman Sekolah
    elseif (isset($_POST['save_pengumuman'])) {
        // ... (Logika penyimpanan/update pengumuman seperti di versi sebelumnya) ...
        $pengumuman_id = isset($_POST['pengumuman_id']) ? (int)$_POST['pengumuman_id'] : 0;
        $judul_pengumuman = isset($_POST['judul_pengumuman']) ? trim($_POST['judul_pengumuman']) : null;
        $isi_pengumuman = isset($_POST['isi_pengumuman']) ? trim($_POST['isi_pengumuman']) : '';
        $tanggal_mulai_str = isset($_POST['tanggal_mulai']) ? trim($_POST['tanggal_mulai']) : '';
        $tanggal_kadaluarsa_str = isset($_POST['tanggal_kadaluarsa']) ? trim($_POST['tanggal_kadaluarsa']) : null;

        if (empty($isi_pengumuman) || empty($tanggal_mulai_str)) {
            $_SESSION['flash_message_admin_index'] = ['type' => 'error', 'text' => 'Isi Pengumuman dan Tanggal Mulai wajib diisi.'];
        } else {
            try {
                $tanggal_mulai_dt = new DateTime($tanggal_mulai_str);
                $tanggal_mulai_db = $tanggal_mulai_dt->format('Y-m-d H:i:s');
                $tanggal_kadaluarsa_db = null;
                if (!empty($tanggal_kadaluarsa_str)) {
                    $tanggal_kadaluarsa_dt = new DateTime($tanggal_kadaluarsa_str);
                    $tanggal_kadaluarsa_db = $tanggal_kadaluarsa_dt->format('Y-m-d H:i:s');
                    if ($tanggal_kadaluarsa_dt <= $tanggal_mulai_dt) {
                        throw new Exception("Tanggal Kadaluarsa harus setelah Tanggal Mulai.");
                    }
                }

                if ($pengumuman_id > 0) { 
                    $stmt_pengumuman = $conn->prepare("UPDATE pengumuman_sekolah SET judul_pengumuman = ?, isi_pengumuman = ?, tanggal_mulai = ?, tanggal_kadaluarsa = ?, dibuat_oleh = ? WHERE id = ?");
                    $stmt_pengumuman->bind_param("ssssii", $judul_pengumuman, $isi_pengumuman, $tanggal_mulai_db, $tanggal_kadaluarsa_db, $admin_user_id, $pengumuman_id);
                } else { 
                    $stmt_pengumuman = $conn->prepare("INSERT INTO pengumuman_sekolah (judul_pengumuman, isi_pengumuman, tanggal_mulai, tanggal_kadaluarsa, dibuat_oleh) VALUES (?, ?, ?, ?, ?)");
                    $stmt_pengumuman->bind_param("ssssi", $judul_pengumuman, $isi_pengumuman, $tanggal_mulai_db, $tanggal_kadaluarsa_db, $admin_user_id);
                }

                if ($stmt_pengumuman && $stmt_pengumuman->execute()) {
                    $_SESSION['flash_message_admin_index'] = ['type' => 'success', 'text' => 'Pengumuman berhasil ' . ($pengumuman_id > 0 ? 'diperbarui' : 'disimpan') . '.'];
                } else {
                    $_SESSION['flash_message_admin_index'] = ['type' => 'error', 'text' => 'Gagal ' . ($pengumuman_id > 0 ? 'memperbarui' : 'menyimpan') . ' pengumuman: ' . htmlspecialchars($stmt_pengumuman ? $stmt_pengumuman->error : $conn->error)];
                }
                if($stmt_pengumuman) $stmt_pengumuman->close();

            } catch (Exception $e) {
                $_SESSION['flash_message_admin_index'] = ['type' => 'error', 'text' => 'Error tanggal: ' . $e->getMessage()];
            }
        }
        header("Location: index.php#pengumuman-sekolah");
        exit;
    } 
    elseif (isset($_POST['delete_pengumuman_id'])) {
        // ... (Logika delete pengumuman seperti di versi sebelumnya) ...
        $delete_id = (int)$_POST['delete_pengumuman_id'];
        $stmt_delete = $conn->prepare("DELETE FROM pengumuman_sekolah WHERE id = ?");
        if ($stmt_delete) {
            $stmt_delete->bind_param("i", $delete_id);
            if ($stmt_delete->execute()) {
                $_SESSION['flash_message_admin_index'] = ['type' => 'success', 'text' => 'Pengumuman berhasil dihapus.'];
            } else {
                $_SESSION['flash_message_admin_index'] = ['type' => 'error', 'text' => 'Gagal menghapus pengumuman: ' . htmlspecialchars($stmt_delete->error)];
            }
            $stmt_delete->close();
        } else {
             $_SESSION['flash_message_admin_index'] = ['type' => 'error', 'text' => 'Gagal mempersiapkan hapus pengumuman: ' . htmlspecialchars($conn->error)];
        }
        header("Location: index.php#pengumuman-sekolah");
        exit;
    }
}

// Ambil pesan flash setelah redirect
if (isset($_SESSION['flash_message_admin_index'])) {
    // Gabungkan dengan pesan yang mungkin sudah ada
    $flash_info = $_SESSION['flash_message_admin_index'];
    $combined_message = "<p class='" . ($flash_info['type'] === 'success' ? 'text-green-600' : ($flash_info['type'] === 'warning' ? 'text-yellow-600' : 'text-red-600')) . 
                        " p-3 bg-" . ($flash_info['type'] === 'success' ? 'green' : ($flash_info['type'] === 'warning' ? 'yellow' : 'red')) . "-100" .
                        " border border-" . ($flash_info['type'] === 'success' ? 'green' : ($flash_info['type'] === 'warning' ? 'yellow' : 'red')) . "-400 rounded'>" .
                        $flash_info['text'] . "</p>"; // text sudah di-htmlspecialchars saat set session
    
    if (strpos($_SERVER['REQUEST_URI'], '#pengumuman-sekolah') !== false) {
        $message_pengumuman .= $combined_message;
    } else {
        $message_app_settings .= $combined_message;
    }
    unset($_SESSION['flash_message_admin_index']);
}


// Include Header
$page_title = "Admin Dashboard";
$assets_path_prefix = '../'; // Untuk header.php
require_once '../includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="flex flex-col md:flex-row justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-800 mb-4 md:mb-0"><i class="fas fa-cogs mr-2"></i>Admin Dashboard</h1>
        <div id="adminRealTimeClock" class="text-sm text-gray-700 bg-gray-100 px-4 py-2 rounded-lg shadow-md text-center md:text-right">
            Memuat jam...
        </div>
    </div>

    <!-- Bagian Pengaturan Aplikasi -->
    <div id="pengaturan-aplikasi" class="mb-12">
        <h2 class="text-2xl font-semibold mb-6 text-gray-700 border-b pb-2"><i class="fas fa-tools mr-2"></i> Pengaturan Aplikasi Umum</h2>
        <?php if (!empty($message_app_settings)): ?>
            <div class="mb-4"><?php echo $message_app_settings; ?></div>
        <?php endif; ?>
        <form method="POST" enctype="multipart/form-data" action="index.php#pengaturan-aplikasi" class="bg-white shadow-lg rounded-xl p-6 md:p-8 space-y-6">
            <input type="hidden" name="save_app_settings" value="1">
            <div>
                <label for="nama_sekolah" class="block text-sm font-medium text-gray-700">Nama Sekolah</label>
                <input type="text" name="nama_sekolah" id="nama_sekolah" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" placeholder="Masukkan Nama Sekolah" value="<?php echo htmlspecialchars($current_settings['nama_sekolah'] ?? ''); ?>">
            </div>

            <div>
                <label for="logo_sekolah" class="block text-sm font-medium text-gray-700"><i class="fas fa-image mr-1"></i> Upload Logo Sekolah</label>
                <?php if (!empty($current_settings['logo_sekolah']) && file_exists('../' . $current_settings['logo_sekolah'])): ?>
                    <img src="../<?php echo htmlspecialchars($current_settings['logo_sekolah']); ?>?t=<?php echo time(); ?>" alt="Logo Sekolah Saat Ini" class="my-2 h-20 w-auto object-contain border p-1 rounded">
                    <p class="text-xs text-gray-500 mb-1">Logo saat ini. Upload file baru untuk mengganti.</p>
                <?php elseif (!empty($current_settings['logo_sekolah'])): ?>
                     <p class="text-xs text-yellow-600 bg-yellow-100 p-2 rounded my-1">Logo saat ini (<?php echo htmlspecialchars($current_settings['logo_sekolah']); ?>) tidak ditemukan. Silakan upload ulang.</p>
                <?php endif; ?>
                <input type="file" name="logo_sekolah" id="logo_sekolah" accept="image/png, image/jpeg, image/gif, image/webp" class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
            </div>

            <div>
                <label for="header_image" class="block text-sm font-medium text-gray-700"><i class="fas fa-image mr-1"></i> Upload Header Image (Misal: 1200x80px)</label>
                 <?php if (!empty($current_settings['header_image']) && file_exists('../' . $current_settings['header_image'])): ?>
                    <img src="../<?php echo htmlspecialchars($current_settings['header_image']); ?>?t=<?php echo time(); ?>" alt="Header Image Saat Ini" class="my-2 h-20 w-full object-cover border p-1 rounded">
                    <p class="text-xs text-gray-500 mb-1">Header saat ini. Upload file baru untuk mengganti.</p>
                <?php elseif (!empty($current_settings['header_image'])): ?>
                     <p class="text-xs text-yellow-600 bg-yellow-100 p-2 rounded my-1">Header saat ini (<?php echo htmlspecialchars($current_settings['header_image']); ?>) tidak ditemukan. Silakan upload ulang.</p>
                <?php endif; ?>
                <input type="file" name="header_image" id="header_image" accept="image/png, image/jpeg, image/gif, image/webp" class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
            </div>

            <div>
                <label for="footer_text" class="block text-sm font-medium text-gray-700"><i class="fas fa-text-height mr-1"></i> Teks Footer (Marquee di Halaman Login)</label>
                <input type="text" name="footer_text" id="footer_text" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" placeholder="Masukkan teks footer" value="<?php echo htmlspecialchars($current_settings['footer_text'] ?? ''); ?>">
            </div>

            <?php if ($db_ready_for_color_settings): ?>
            <fieldset class="border border-gray-300 p-4 rounded-md">
                <legend class="text-lg font-medium text-gray-700 px-2">Pengaturan Warna</legend>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-2">
                    <div>
                        <label for="header_color_picker" class="block text-sm font-medium text-gray-700"><i class="fas fa-palette mr-1"></i> Warna Latar Header</label>
                        <div class="flex items-center mt-1">
                            <input type="color" id="header_color_picker" value="<?php echo htmlspecialchars($current_settings['header_color'] ?? '#FFFFFF'); ?>" class="h-10 w-16 border border-gray-300 rounded-md shadow-sm p-1 cursor-pointer">
                            <input type="text" name="header_color" id="header_color_text" value="<?php echo htmlspecialchars($current_settings['header_color'] ?? '#FFFFFF'); ?>" class="ml-2 px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm w-32" placeholder="#RRGGBB" pattern="^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$">
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Pilih warna atau masukkan kode hex (misal: #3B82F6).</p>
                    </div>
                    <div>
                        <label for="footer_color_picker" class="block text-sm font-medium text-gray-700"><i class="fas fa-palette mr-1"></i> Warna Latar Footer (Marquee)</label>
                        <div class="flex items-center mt-1">
                            <input type="color" id="footer_color_picker" value="<?php echo htmlspecialchars($current_settings['footer_color'] ?? '#333333'); ?>" class="h-10 w-16 border border-gray-300 rounded-md shadow-sm p-1 cursor-pointer">
                             <input type="text" name="footer_color" id="footer_color_text" value="<?php echo htmlspecialchars($current_settings['footer_color'] ?? '#333333'); ?>" class="ml-2 px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm w-32" placeholder="#RRGGBB" pattern="^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$">
                        </div>
                         <p class="text-xs text-gray-500 mt-1">Pilih warna atau masukkan kode hex (misal: #1F2937).</p>
                    </div>
                </div>
            </fieldset>
            <?php endif; ?>

            <button type="submit" class="inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                <i class="fas fa-save mr-2"></i> Simpan Pengaturan Aplikasi
            </button>
        </form>
    </div>

    <!-- Bagian Pengumuman Sekolah -->
    <div id="pengumuman-sekolah" class="mb-12">
        <h2 class="text-2xl font-semibold mb-6 text-gray-700 border-b pb-2"><i class="fas fa-bullhorn mr-2"></i> Manajemen Pengumuman Sekolah</h2>
        <?php if (!empty($message_pengumuman)): ?>
            <div class="mb-4"><?php echo $message_pengumuman; ?></div>
        <?php endif; ?>

        <form method="POST" action="index.php#pengumuman-sekolah" class="bg-white shadow-lg rounded-xl p-6 md:p-8 space-y-6 mb-8">
            <input type="hidden" name="pengumuman_id" value="<?php echo htmlspecialchars($edit_pengumuman_data['id'] ?? 0); ?>">
            <h3 class="text-xl font-medium text-gray-800"><?php echo $edit_pengumuman_data ? 'Edit' : 'Buat'; ?> Pengumuman Baru</h3>
            
            <div>
                <label for="judul_pengumuman" class="block text-sm font-medium text-gray-700">Judul Pengumuman (Opsional)</label>
                <input type="text" name="judul_pengumuman" id="judul_pengumuman" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" value="<?php echo htmlspecialchars($edit_pengumuman_data['judul_pengumuman'] ?? ''); ?>" placeholder="Mis: Libur Nasional">
            </div>

            <div>
                <label for="isi_pengumuman" class="block text-sm font-medium text-gray-700">Isi Pengumuman <span class="text-red-500">*</span></label>
                <textarea name="isi_pengumuman" id="isi_pengumuman" rows="4" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" placeholder="Tulis isi pengumuman di sini..."><?php echo htmlspecialchars($edit_pengumuman_data['isi_pengumuman'] ?? ''); ?></textarea>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="tanggal_mulai" class="block text-sm font-medium text-gray-700">Tanggal & Jam Mulai Tayang <span class="text-red-500">*</span></label>
                    <input type="datetime-local" name="tanggal_mulai" id="tanggal_mulai" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" value="<?php echo isset($edit_pengumuman_data['tanggal_mulai']) ? (new DateTime($edit_pengumuman_data['tanggal_mulai']))->format('Y-m-d\TH:i') : ''; ?>">
                </div>
                <div>
                    <label for="tanggal_kadaluarsa" class="block text-sm font-medium text-gray-700">Tanggal & Jam Kadaluarsa (Opsional)</label>
                    <input type="datetime-local" name="tanggal_kadaluarsa" id="tanggal_kadaluarsa" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" value="<?php echo isset($edit_pengumuman_data['tanggal_kadaluarsa']) ? (new DateTime($edit_pengumuman_data['tanggal_kadaluarsa']))->format('Y-m-d\TH:i') : ''; ?>">
                    <p class="text-xs text-gray-500 mt-1">Kosongkan jika tidak ada batas waktu.</p>
                </div>
            </div>
            
            <div class="flex items-center space-x-4">
                <button type="submit" name="save_pengumuman" class="inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                    <i class="fas fa-save mr-2"></i> <?php echo $edit_pengumuman_data ? 'Update' : 'Simpan'; ?> Pengumuman
                </button>
                <?php if ($edit_pengumuman_data): ?>
                    <a href="index.php#pengumuman-sekolah" class="px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">Batal Edit</a>
                <?php endif; ?>
            </div>
        </form>

        <h3 class="text-xl font-medium text-gray-800 mt-10 mb-4">Daftar Pengumuman Tersimpan</h3>
        <?php if (!empty($pengumuman_list)): ?>
            <div class="overflow-x-auto bg-white shadow-md rounded-lg">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Judul</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Isi (Ringkas)</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Mulai</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kadaluarsa</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($pengumuman_list as $p): ?>
                            <?php
                                $now_dt = new DateTime('now', new DateTimeZone('Asia/Jakarta')); // Pastikan zona waktu sesuai
                                $mulai_dt = new DateTime($p['tanggal_mulai'], new DateTimeZone('Asia/Jakarta'));
                                $kadaluarsa_dt = !empty($p['tanggal_kadaluarsa']) ? new DateTime($p['tanggal_kadaluarsa'], new DateTimeZone('Asia/Jakarta')) : null;
                                $status_aktif = 'Nonaktif';
                                $status_class = 'bg-gray-200 text-gray-700';
                                if ($now_dt >= $mulai_dt && ($kadaluarsa_dt === null || $now_dt < $kadaluarsa_dt)) {
                                    $status_aktif = 'Aktif';
                                    $status_class = 'bg-green-100 text-green-700';
                                } elseif ($kadaluarsa_dt !== null && $now_dt >= $kadaluarsa_dt) {
                                    $status_aktif = 'Kadaluarsa';
                                    $status_class = 'bg-red-100 text-red-700';
                                } elseif ($now_dt < $mulai_dt) {
                                    $status_aktif = 'Terjadwal';
                                    $status_class = 'bg-blue-100 text-blue-700';
                                }
                            ?>
                            <tr>
                                <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($p['judul_pengumuman'] ?: '-'); ?></td>
                                <td class="px-4 py-3 text-sm text-gray-600 max-w-md truncate" title="<?php echo htmlspecialchars($p['isi_pengumuman']); ?>"><?php echo htmlspecialchars(mb_strimwidth($p['isi_pengumuman'], 0, 70, "...")); ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500"><?php echo $mulai_dt->format('d M Y, H:i'); ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500"><?php echo $kadaluarsa_dt ? $kadaluarsa_dt->format('d M Y, H:i') : 'Tidak Ada'; ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm">
                                    <span class="px-2 py-0.5 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $status_class; ?>">
                                        <?php echo $status_aktif; ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm font-medium space-x-2">
                                    <a href="index.php?edit_pengumuman_id=<?php echo $p['id']; ?>#pengumuman-sekolah" class="text-indigo-600 hover:text-indigo-900" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form method="POST" action="index.php#pengumuman-sekolah" class="inline-block" onsubmit="return confirm('Anda yakin ingin menghapus pengumuman ini?');">
                                        <input type="hidden" name="delete_pengumuman_id" value="<?php echo $p['id']; ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-900" title="Hapus">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-gray-500 text-center py-4">Belum ada pengumuman yang dibuat.</p>
        <?php endif; ?>
    </div>
</div>

<script>
    function updateAdminRealTimeClock() {
        const clockElement = document.getElementById('adminRealTimeClock');
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
    setInterval(updateAdminRealTimeClock, 1000);
    updateAdminRealTimeClock();

    document.addEventListener('DOMContentLoaded', function () {
        // JS untuk color picker agar sinkron dengan input text
        const headerColorPicker = document.getElementById('header_color_picker');
        const headerColorText = document.getElementById('header_color_text');
        if (headerColorPicker && headerColorText) {
            headerColorPicker.addEventListener('input', () => headerColorText.value = headerColorPicker.value);
            headerColorText.addEventListener('input', () => { // Ganti 'change' ke 'input' agar lebih responsif
                if (/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/.test(headerColorText.value)) {
                    headerColorPicker.value = headerColorText.value;
                }
            });
        }

        const footerColorPicker = document.getElementById('footer_color_picker');
        const footerColorText = document.getElementById('footer_color_text');
        if (footerColorPicker && footerColorText) {
            footerColorPicker.addEventListener('input', () => footerColorText.value = footerColorPicker.value);
            footerColorText.addEventListener('input', () => {
                if (/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/.test(footerColorText.value)) {
                    footerColorPicker.value = footerColorText.value;
                }
            });
        }
    });
</script>

<?php
require_once '../includes/footer.php';
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>