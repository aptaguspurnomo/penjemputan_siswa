<?php
// superadmin_credentials.php
session_start();

// --- KONFIGURASI SUPER ADMIN ---
define('SUPER_ADMIN_USER_ID', 1); 
define('SUPER_ADMIN_EXPECTED_PAGE_PASSWORD', 'PasswordSuperAdminSangatRahasia123!@#'); 

// --- INISIALISASI VARIABEL ---
$is_page_authorized = false; 
$error_msg_page_auth = '';      
$message_reset = '';       
$admin_users = [];
$fetch_error = null;
$show_reset_form_for_admin_id = null; 
$admin_to_reset_username = '';

// --- OTORISASI AKSES HALAMAN ---
if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == SUPER_ADMIN_USER_ID && isset($_SESSION['role']) && $_SESSION['role'] == 'admin') {
    if (isset($_POST['super_admin_password_page'])) {
        if ($_POST['super_admin_password_page'] === SUPER_ADMIN_EXPECTED_PAGE_PASSWORD) {
            $_SESSION['superadmin_page_authorized'] = true;
            $is_page_authorized = true;
        } else {
            $error_msg_page_auth = "Password halaman super admin salah.";
        }
    } elseif (isset($_SESSION['superadmin_page_authorized']) && $_SESSION['superadmin_page_authorized'] === true) {
        $is_page_authorized = true;
    }
} else { // Jika belum login sebagai admin utama, langsung blok
     $_SESSION['flash_message_login'] = ['type' => 'error', 'text' => 'Akses ditolak. Anda harus login sebagai Admin Utama terlebih dahulu.'];
    header('Location: ../login.php'); // Sesuaikan path jika perlu
    exit;
}


if (!$is_page_authorized) {
    // Tampilkan form otorisasi halaman super admin jika belum atau gagal
    ?>
    <!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><title>Otorisasi Halaman Super Admin</title><script src="https://cdn.tailwindcss.com"></script></head><body class="bg-gray-900 text-white flex items-center justify-center h-screen"><div class="bg-gray-800 p-8 rounded-lg shadow-xl w-full max-w-sm"><h1 class="text-2xl font-bold text-yellow-400 mb-6 text-center">Otorisasi Halaman Super Admin</h1><?php if (!empty($error_msg_page_auth)): ?><p class="text-red-400 bg-red-900 p-3 rounded mb-4"><?php echo htmlspecialchars($error_msg_page_auth); ?></p><?php endif; ?><form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>"><label for="super_admin_password_page" class="block mb-2 text-sm font-medium">Masukkan Password Khusus Halaman Ini:</label><input type="password" name="super_admin_password_page" id="super_admin_password_page" class="bg-gray-700 border border-gray-600 text-white sm:text-sm rounded-lg focus:ring-yellow-500 focus:border-yellow-500 block w-full p-2.5" required><button type="submit" class="w-full mt-4 text-white bg-yellow-600 hover:bg-yellow-700 focus:ring-4 focus:outline-none focus:ring-yellow-800 font-medium rounded-lg text-sm px-5 py-2.5 text-center">Otorisasi Halaman</button></form></div></body></html>
    <?php
    exit;
}

// --- KONEKSI DATABASE (Hanya jika otorisasi halaman berhasil) ---
$db_connection_error = null;
// Asumsi file ini ada di root penjemputan_siswa
if (file_exists(__DIR__ . '/includes/db.php')) { 
     require_once __DIR__ . '/includes/db.php';
} else {
    $db_connection_error = "Kritis: File db.php tidak ditemukan pada path: " . __DIR__ . "/includes/db.php";
}

if ($db_connection_error || (isset($conn) && $conn->connect_error) ) {
    $fetch_error = $db_connection_error ?: (isset($conn) ? "Koneksi database gagal: " . $conn->connect_error : "Variabel koneksi tidak terdefinisi.");
    error_log("Superadmin Creds - DB Error: " . $fetch_error);
}


// --- LOGIKA RESET PASSWORD (SETELAH SUBMIT FORM RESET) ---
if (!$fetch_error && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['perform_reset_password'])) {
    $admin_id_to_reset = isset($_POST['admin_id_to_reset_hidden']) ? (int)$_POST['admin_id_to_reset_hidden'] : 0;
    $new_password_plain = isset($_POST['new_admin_password']) ? $_POST['new_admin_password'] : '';
    $confirm_new_password_plain = isset($_POST['confirm_new_admin_password']) ? $_POST['confirm_new_admin_password'] : '';

    if ($admin_id_to_reset > 0 && $admin_id_to_reset != SUPER_ADMIN_USER_ID) { 
        if (!empty($new_password_plain)) {
            if ($new_password_plain === $confirm_new_password_plain) {
                if (strlen($new_password_plain) >= 6) { 
                    $new_password_hashed = md5($new_password_plain); 
                    $stmt_reset = $conn->prepare("UPDATE users SET password = ? WHERE id = ? AND role = 'admin'");
                    if ($stmt_reset) {
                        $stmt_reset->bind_param("si", $new_password_hashed, $admin_id_to_reset);
                        if ($stmt_reset->execute()) {
                            if ($stmt_reset->affected_rows > 0) {
                                $_SESSION['flash_superadmin_creds'] = ['type' => 'success', 'text' => "Password untuk admin ID {$admin_id_to_reset} berhasil diubah."];
                            } else {
                                $_SESSION['flash_superadmin_creds'] = ['type' => 'warning', 'text' => "Admin ID {$admin_id_to_reset} tidak ditemukan atau password tidak berubah."];
                            }
                        } else {
                            $_SESSION['flash_superadmin_creds'] = ['type' => 'error', 'text' => "Gagal mereset password: " . $stmt_reset->error];
                        }
                        $stmt_reset->close();
                    } else {
                        $_SESSION['flash_superadmin_creds'] = ['type' => 'error', 'text' => "Gagal mempersiapkan reset password: " . $conn->error];
                    }
                } else {
                     $_SESSION['flash_superadmin_creds'] = ['type' => 'error', 'text' => "Password baru minimal 6 karakter."];
                }
            } else {
                $_SESSION['flash_superadmin_creds'] = ['type' => 'error', 'text' => "Konfirmasi password baru tidak cocok."];
            }
        } else {
            $_SESSION['flash_superadmin_creds'] = ['type' => 'error', 'text' => "Password baru tidak boleh kosong."];
        }
    } elseif ($admin_id_to_reset == SUPER_ADMIN_USER_ID) {
         $_SESSION['flash_superadmin_creds'] = ['type' => 'warning', 'text' => "Anda tidak dapat mereset password Anda sendiri melalui form ini."];
    } else {
        $_SESSION['flash_superadmin_creds'] = ['type' => 'error', 'text' => "ID Admin tidak valid untuk reset."];
    }
    header("Location: " . htmlspecialchars($_SERVER['PHP_SELF'])); 
    exit;
}

// --- PERSIAPAN TAMPILAN RESET FORM (JIKA ADA REQUEST GET) ---
if (!$fetch_error && isset($conn) && !$conn->connect_error && isset($_GET['action']) && $_GET['action'] === 'show_reset_form' && isset($_GET['reset_admin_id'])) {
    $current_admin_id_to_reset = (int)$_GET['reset_admin_id'];
    if ($current_admin_id_to_reset > 0 && $current_admin_id_to_reset != SUPER_ADMIN_USER_ID) {
        $stmt_get_admin_name = $conn->prepare("SELECT username FROM users WHERE id = ? AND role = 'admin'");
        if($stmt_get_admin_name){
            $stmt_get_admin_name->bind_param("i", $current_admin_id_to_reset);
            $stmt_get_admin_name->execute();
            $result_admin_name = $stmt_get_admin_name->get_result();
            if($admin_name_row = $result_admin_name->fetch_assoc()){
                $admin_to_reset_username = $admin_name_row['username'];
                $show_reset_form_for_admin_id = $current_admin_id_to_reset; 
            } else {
                $message_reset .= "<p class='text-red-600 bg-red-100 p-3 rounded mb-4'>Admin dengan ID {$current_admin_id_to_reset} tidak ditemukan.</p>";
            }
            $stmt_get_admin_name->close();
        } else {
             $message_reset .= "<p class='text-red-600 bg-red-100 p-3 rounded mb-4'>Gagal mengambil data admin untuk reset: " . $conn->error . "</p>";
        }
    } elseif ($current_admin_id_to_reset == SUPER_ADMIN_USER_ID) {
        $message_reset .= "<p class='text-yellow-600 bg-yellow-100 p-3 rounded mb-4'>Anda tidak dapat mereset password Anda sendiri di sini.</p>";
    }
}


// Ambil pesan flash untuk reset
if (isset($_SESSION['flash_superadmin_creds'])) {
    $flash = $_SESSION['flash_superadmin_creds'];
    $border_color_class = ($flash['type'] === 'success' ? 'green' : ($flash['type'] === 'warning' ? 'yellow' : 'red'));
    $message_reset .= "<p class='" . ($flash['type'] === 'success' ? 'text-green-600 bg-green-100' : ($flash['type'] === 'warning' ? 'text-yellow-600 bg-yellow-100' : 'text-red-600 bg-red-100')) . 
                        " p-3 rounded mb-4 border border-{$border_color_class}-400'>" . htmlspecialchars($flash['text']) . "</p>";
    unset($_SESSION['flash_superadmin_creds']);
}


// Ambil data admin
if (!$fetch_error && isset($conn) && !$conn->connect_error) {
    $stmt_get_admins = $conn->prepare("SELECT id, username, password, nama, role FROM users WHERE role = 'admin'");
    if ($stmt_get_admins) {
        $stmt_get_admins->execute();
        $result_admins = $stmt_get_admins->get_result();
        while ($row_admin_data = $result_admins->fetch_assoc()) {
            $admin_users[] = $row_admin_data;
        }
        $stmt_get_admins->close();
    } else {
        $fetch_error = "Gagal mengambil data admin: " . $conn->error;
        error_log("Superadmin Creds - Gagal get_admins: " . $fetch_error);
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kredensial & Reset Password Admin - Super Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body { font-family: sans-serif; }
        .main-content-area { padding-top: 1rem; padding-bottom: 1rem; } 
    </style>
</head>
<body class="bg-gray-100 p-4 sm:p-8 main-content-area">
    <div class="container mx-auto max-w-4xl bg-white shadow-xl rounded-lg p-6">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 border-b pb-4">
            <h1 class="text-2xl sm:text-3xl font-bold text-red-700 mb-2 sm:mb-0"><i class="fas fa-user-shield mr-2"></i> Kredensial & Reset Password Admin</h1>
            <?php 
            // Tentukan path berdasarkan asumsi lokasi superadmin_credentials.php
            // Jika di root: 'admin/index.php'
            // Jika di admin/: 'index.php' (karena kita ada di dalam admin)
            $path_to_dir_of_this_file = basename(realpath(__DIR__));
            $admin_dashboard_path = ($path_to_dir_of_this_file === 'penjemputan_siswa') ? 'admin/index.php' : 'index.php'; 
            if (isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'admin'):
            ?>
            <a href="<?php echo $admin_dashboard_path; ?>" class="text-sm text-blue-600 hover:underline"><i class="fas fa-arrow-left mr-1"></i> Kembali ke Dashboard Admin</a>
            <?php endif; ?>
        </div>
        
        <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-md" role="alert">
             <div class="flex">
                <div class="py-1"><i class="fas fa-exclamation-triangle mr-3"></i></div>
                <div>
                    <p class="font-bold">PERINGATAN KEAMANAN SANGAT TINGGI!</p>
                    <p class="text-sm">Halaman ini memiliki akses untuk melihat hash password dan mereset password akun Admin. Gunakan dengan sangat hati-hati.</p>
                </div>
            </div>
        </div>

        <?php if (!empty($message_reset)): ?>
            <div class="mb-4"><?php echo $message_reset; ?></div>
        <?php endif; ?>

        <?php // --- FORM RESET PASSWORD BARU (DIKEMBALIKAN DAN DIPASTIKAN LENGKAP) ---
        if ($show_reset_form_for_admin_id !== null && !empty($admin_to_reset_username)): ?>
        <div id="resetPasswordFormContainer" class="mb-8 p-6 bg-yellow-50 border border-yellow-400 rounded-lg shadow-md">
            <h3 class="text-xl font-semibold text-yellow-800 mb-4">Reset Password untuk Admin: <span class="font-bold"><?php echo htmlspecialchars($admin_to_reset_username); ?></span> (ID: <?php echo $show_reset_form_for_admin_id; ?>)</h3>
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
                <input type="hidden" name="admin_id_to_reset_hidden" value="<?php echo $show_reset_form_for_admin_id; ?>">
                <div class="mb-4">
                    <label for="new_admin_password" class="block text-sm font-medium text-gray-700">Password Baru <span class="text-red-500">*</span></label>
                    <input type="password" name="new_admin_password" id="new_admin_password" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required minlength="6" autocomplete="new-password">
                    <p class="text-xs text-gray-500 mt-1">Minimal 6 karakter.</p>
                </div>
                <div class="mb-4">
                    <label for="confirm_new_admin_password" class="block text-sm font-medium text-gray-700">Konfirmasi Password Baru <span class="text-red-500">*</span></label>
                    <input type="password" name="confirm_new_admin_password" id="confirm_new_admin_password" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required autocomplete="new-password">
                </div>
                <div class="flex items-center space-x-3">
                    <button type="submit" name="perform_reset_password" class="inline-flex items-center px-4 py-2 bg-red-600 text-white text-sm font-medium rounded-md shadow-sm hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
                        <i class="fas fa-key mr-2"></i> Konfirmasi Reset Password
                    </button>
                    <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                        Batal
                    </a>
                </div>
            </form>
        </div>
        <?php endif; ?>
        <?php // --- AKHIR FORM RESET PASSWORD BARU --- ?>


        <?php 
        if ($show_reset_form_for_admin_id === null) { 
            if (isset($fetch_error)) {
                echo '<p class="text-red-500 bg-red-100 p-3 rounded mb-4">' . htmlspecialchars($fetch_error) . '</p>';
            } elseif (empty($admin_users)) {
                echo '<p class="text-gray-600">Tidak ada akun admin yang ditemukan.</p>';
            } else {
        ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-300">
                    <thead class="bg-gray-200">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">ID</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Nama</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Username</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Password (Hash MD5)</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($admin_users as $admin): ?>
                            <tr>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700"><?php echo $admin['id']; ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-800 font-medium"><?php echo htmlspecialchars($admin['nama']); ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600"><?php echo htmlspecialchars($admin['username']); ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-red-600 font-mono break-all" style="max-width: 200px; overflow-wrap: break-word;">
                                    <?php echo htmlspecialchars($admin['password']); ?>
                                    <button onclick="copyToClipboard('<?php echo htmlspecialchars(addslashes($admin['password'])); ?>', this)" class="ml-2 text-xs bg-gray-200 hover:bg-gray-300 text-gray-700 px-1 py-0.5 rounded" title="Salin Hash">
                                        <i class="far fa-copy"></i>
                                    </button>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-center">
                                    <?php if ($admin['id'] != SUPER_ADMIN_USER_ID): ?>
                                    <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>?action=show_reset_form&reset_admin_id=<?php echo $admin['id']; ?>#resetPasswordFormContainer" 
                                       class="text-white bg-orange-500 hover:bg-orange-600 px-3 py-1.5 rounded-md text-xs shadow-sm focus:outline-none focus:ring-2 focus:ring-orange-400 inline-flex items-center" 
                                       title="Reset Password Admin Ini">
                                        <i class="fas fa-key mr-1"></i> Reset Pass
                                    </a>
                                    <?php else: ?>
                                        <span class="text-xs text-gray-400 italic">(Akun Anda)</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($show_reset_form_for_admin_id === null): ?>
            <p class="mt-4 text-xs text-gray-500">
                <strong>Catatan:</strong> Password yang ditampilkan adalah hasil enkripsi MD5. Jika password direset, password baru perlu diinput manual.
            </p>
            <?php endif; ?>
        <?php 
            } 
        } 
        ?>
    </div>

<script>
function copyToClipboard(text, buttonElement) {
    navigator.clipboard.writeText(text).then(function() {
        const originalIconHtml = buttonElement.innerHTML; 
        buttonElement.innerHTML = '<i class="fas fa-check text-green-500"></i>'; 
        buttonElement.title = "Tersalin!";
        setTimeout(() => {
            buttonElement.innerHTML = originalIconHtml; 
            buttonElement.title = "Salin Hash";
        }, 2000);
    }, function(err) {
        console.error('Gagal menyalin hash: ', err);
        alert('Gagal menyalin hash. Coba salin manual.');
    });
}
</script>
</body>
</html>
<?php
if(isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>