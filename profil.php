<?php
// 1. Mulai session dan include koneksi database
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Cek apakah pengguna sudah login, jika tidak, redirect ke login
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

include 'includes/db.php'; // Koneksi ke database

// --- Fungsi Bantu untuk Hashing Password (SANGAT PENTING: Ganti MD5 dengan password_hash) ---
function hashUserProfilePassword($password) {
    // return password_hash($password, PASSWORD_DEFAULT); // Idealnya seperti ini
    return MD5($password); // TIDAK AMAN, konsisten dengan sistem Anda saat ini
}
// -------------------------------------------------------------------------------

$message = '';
$user_id_to_edit = $_SESSION['user_id']; // User hanya bisa edit profilnya sendiri
$current_role = $_SESSION['role'];      // Untuk penyesuaian tampilan jika perlu

// Ambil data user saat ini
$stmt_user = $conn->prepare("SELECT username, nama FROM users WHERE id = ?");
$current_db_username = $_SESSION['username']; // Ambil dari session sebagai fallback awal
$current_db_nama = $_SESSION['nama_user'] ?? ''; // Ambil dari session sebagai fallback awal

if ($stmt_user) {
    $stmt_user->bind_param("i", $user_id_to_edit);
    $stmt_user->execute();
    $result_user = $stmt_user->get_result();
    if ($result_user->num_rows === 1) {
        $user_data = $result_user->fetch_assoc();
        // Update dengan data terbaru dari DB, jaga-jaga jika session belum refresh
        $current_db_username = $user_data['username'];
        $current_db_nama = $user_data['nama'];
    } else {
        // Jika user di session tidak ada di DB (jarang terjadi jika session valid)
        session_destroy();
        header('Location: login.php');
        exit;
    }
    $stmt_user->close();
} else {
    // Jika prepare statement gagal, gunakan data dari session saja
    $message = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Tidak dapat mengambil data profil terbaru. Menampilkan data dari sesi.</p>";
}


// Logika pemrosesan form update profil
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profil_pengguna'])) {
    $new_nama = isset($_POST['nama_lengkap']) ? trim($_POST['nama_lengkap']) : $current_db_nama;
    // Username tidak diizinkan diubah di sini untuk kesederhanaan. Jika ingin, tambahkan validasi duplikasi.
    // $new_username = isset($_POST['username']) ? trim($_POST['username']) : $current_db_username;

    $password_lama = isset($_POST['password_lama']) ? $_POST['password_lama'] : '';
    $password_baru = isset($_POST['password_baru']) ? $_POST['password_baru'] : '';
    $konfirmasi_password_baru = isset($_POST['konfirmasi_password_baru']) ? $_POST['konfirmasi_password_baru'] : '';

    $update_success = false;

    // Validasi dasar
    if (empty($new_nama)) {
        $message = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Nama Lengkap tidak boleh kosong.</p>";
    } else {
        $conn->begin_transaction();
        try {
            $sql_update_user = "UPDATE users SET nama = ?";
            $types = "s";
            $params = [$new_nama];

            // Logika update password jika diisi
            if (!empty($password_baru)) {
                if (empty($password_lama)) {
                    throw new Exception("Masukkan password lama Anda untuk mengubah password.");
                }
                if ($password_baru !== $konfirmasi_password_baru) {
                    throw new Exception("Password baru dan konfirmasi password tidak cocok.");
                }
                if (strlen($password_baru) < 6) { // Contoh validasi panjang password
                     throw new Exception("Password baru minimal harus 6 karakter.");
                }

                // Verifikasi password lama
                $stmt_verify_pass = $conn->prepare("SELECT password FROM users WHERE id = ?");
                if (!$stmt_verify_pass) throw new Exception("Error prepare (verify pass): " . $conn->error);
                $stmt_verify_pass->bind_param("i", $user_id_to_edit);
                $stmt_verify_pass->execute();
                $res_verify_pass = $stmt_verify_pass->get_result();
                if ($res_verify_pass->num_rows === 1) {
                    $user_pass_data = $res_verify_pass->fetch_assoc();
                    if (hashUserProfilePassword($password_lama) !== $user_pass_data['password']) {
                        throw new Exception("Password lama yang Anda masukkan salah.");
                    }
                } else {
                    throw new Exception("Gagal memverifikasi pengguna.");
                }
                $stmt_verify_pass->close();

                // Jika password lama cocok, tambahkan update password ke query
                $sql_update_user .= ", password = ?";
                $types .= "s";
                $params[] = hashUserProfilePassword($password_baru);
            }

            $sql_update_user .= " WHERE id = ?";
            $types .= "i";
            $params[] = $user_id_to_edit;

            $stmt_update = $conn->prepare($sql_update_user);
            if (!$stmt_update) throw new Exception("Error prepare (update user): " . $conn->error);

            $stmt_update->bind_param($types, ...$params);
            if (!$stmt_update->execute()) throw new Exception("Gagal memperbarui profil: " . $stmt_update->error);
            
            $stmt_update->close();
            $conn->commit();
            $update_success = true;

            // Update session jika nama berubah
            $_SESSION['nama_user'] = $new_nama;
            $current_db_nama = $new_nama; // Update variabel lokal juga

            $message = "<p class='text-green-500 p-3 bg-green-100 border border-green-400 rounded'>Profil berhasil diperbarui.</p>";
            if(!empty($password_baru)) $message .= "<p class='text-sm text-green-600'>Password Anda juga telah diubah.</p>";

        } catch (Exception $e) {
            $conn->rollback();
            $message = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Update Gagal: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
}

// Tentukan $assets_path_prefix untuk header.php
// Karena profil.php ada di root, path ke assets adalah 'assets/'
$assets_path_prefix = ''; 
// Definisikan $current_page_depth agar header tahu kita di root (untuk path menu)
$current_page_depth = 'root';
include 'includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <h2 class="text-2xl font-bold mb-6 text-gray-800"><i class="fas fa-user-edit mr-2"></i> Edit Akun Saya</h2>

    <?php if (!empty($message)): ?>
        <div class="mb-4">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <div class="bg-white shadow-md rounded-lg p-6 md:p-8 max-w-lg mx-auto">
        <form method="POST" action="profil.php" class="space-y-6"> <!-- Action ke file ini sendiri -->
            <div>
                <label for="username_display" class="block text-sm font-medium text-gray-700">Username</label>
                <input type="text" name="username_display" id="username_display" value="<?php echo htmlspecialchars($current_db_username); ?>" 
                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm bg-gray-100 focus:outline-none sm:text-sm" readonly>
                <p class="mt-1 text-xs text-gray-500">Username tidak dapat diubah.</p>
            </div>
            <div>
                <label for="nama_lengkap" class="block text-sm font-medium text-gray-700">Nama Lengkap</label>
                <input type="text" name="nama_lengkap" id="nama_lengkap" 
                       value="<?php echo htmlspecialchars(isset($_POST['nama_lengkap']) && !empty($message) ? $_POST['nama_lengkap'] : $current_db_nama); ?>" 
                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required>
            </div>

            <hr class="my-6 border-gray-300">
            <h3 class="text-lg font-medium text-gray-900">Ubah Password (Opsional)</h3>
            <p class="text-sm text-gray-500">Isi field berikut hanya jika Anda ingin mengubah password Anda.</p>

            <div>
                <label for="password_lama" class="block text-sm font-medium text-gray-700">Password Lama</label>
                <input type="password" name="password_lama" id="password_lama" placeholder="Masukkan password lama Anda" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
            </div>
            <div>
                <label for="password_baru" class="block text-sm font-medium text-gray-700">Password Baru</label>
                <input type="password" name="password_baru" id="password_baru" placeholder="Minimal 6 karakter" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
            </div>
            <div>
                <label for="konfirmasi_password_baru" class="block text-sm font-medium text-gray-700">Konfirmasi Password Baru</label>
                <input type="password" name="konfirmasi_password_baru" id="konfirmasi_password_baru" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
            </div>

            <button type="submit" name="update_profil_pengguna" class="w-full inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                <i class="fas fa-save mr-2"></i> Simpan Perubahan
            </button>
        </form>
    </div>
</div>

<?php
include 'includes/footer.php';
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>