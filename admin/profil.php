<?php
// error_reporting(E_ALL);
// ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Jika pengguna bukan admin dan mencoba akses profil admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    // Jika hanya user biasa, arahkan ke profil.php di root (jika ada) atau login
    // header('Location: ../login.php');
    // exit;
    // Atau jika ingin hanya admin yang boleh akses halaman ini:
    echo "<p class='text-red-500 p-4 container mx-auto'>Akses ditolak. Halaman ini khusus untuk Admin.</p>";
    // Anda mungkin ingin membuat footer.php yang bisa di-include di sini juga
    exit;
}

// Path ke db.php sekarang dari admin/profil.php ke includes/db.php
include '../includes/db.php';

// --- Fungsi Bantu untuk Hashing Password ---
function hashAdminProfilePassword($password) {
    // return password_hash($password, PASSWORD_DEFAULT); // Idealnya
    return MD5($password); // TIDAK AMAN
}
// -------------------------------------------

$message = '';
$user_id_to_edit = $_SESSION['user_id'];
$current_username = $_SESSION['username'];

$stmt_user = $conn->prepare("SELECT username, nama FROM users WHERE id = ? AND role = 'admin'"); // Pastikan hanya admin
$current_db_username = '';
$current_db_nama = '';
if ($stmt_user) {
    $stmt_user->bind_param("i", $user_id_to_edit);
    $stmt_user->execute();
    $result_user = $stmt_user->get_result();
    if ($result_user->num_rows === 1) {
        $user_data = $result_user->fetch_assoc();
        $current_db_username = $user_data['username'];
        $current_db_nama = $user_data['nama'];
    } else {
        session_destroy();
        header('Location: ../login.php'); // Kembali ke login jika admin tidak ditemukan
        exit;
    }
    $stmt_user->close();
}


if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profil_admin'])) {
    $new_username = isset($_POST['username']) ? trim($_POST['username']) : $current_db_username;
    $new_nama = isset($_POST['nama_lengkap']) ? trim($_POST['nama_lengkap']) : $current_db_nama;
    $password_lama = isset($_POST['password_lama']) ? $_POST['password_lama'] : '';
    $password_baru = isset($_POST['password_baru']) ? $_POST['password_baru'] : '';
    $konfirmasi_password_baru = isset($_POST['konfirmasi_password_baru']) ? $_POST['konfirmasi_password_baru'] : '';

    $update_success = false;

    if (empty($new_username) || empty($new_nama)) {
        $message = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Username dan Nama Lengkap tidak boleh kosong.</p>";
    } else {
        $conn->begin_transaction();
        try {
            if ($new_username !== $current_db_username) {
                $stmt_check_username = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
                if (!$stmt_check_username) throw new Exception("Error prepare (cek username): " . $conn->error);
                $stmt_check_username->bind_param("si", $new_username, $user_id_to_edit);
                $stmt_check_username->execute();
                if ($stmt_check_username->get_result()->num_rows > 0) {
                    throw new Exception("Username '<strong>" . htmlspecialchars($new_username) . "</strong>' sudah digunakan oleh pengguna lain.");
                }
                $stmt_check_username->close();
            }

            $sql_update_user = "UPDATE users SET username = ?, nama = ?";
            $types = "ss";
            $params = [$new_username, $new_nama];

            if (!empty($password_baru)) {
                if (empty($password_lama)) {
                    throw new Exception("Masukkan password lama Anda untuk mengubah password.");
                }
                if ($password_baru !== $konfirmasi_password_baru) {
                    throw new Exception("Password baru dan konfirmasi password tidak cocok.");
                }
                if (strlen($password_baru) < 6) {
                     throw new Exception("Password baru minimal harus 6 karakter.");
                }

                $stmt_verify_pass = $conn->prepare("SELECT password FROM users WHERE id = ? AND role = 'admin'");
                if (!$stmt_verify_pass) throw new Exception("Error prepare (verify pass): " . $conn->error);
                $stmt_verify_pass->bind_param("i", $user_id_to_edit);
                $stmt_verify_pass->execute();
                $res_verify_pass = $stmt_verify_pass->get_result();
                if ($res_verify_pass->num_rows === 1) {
                    $user_pass_data = $res_verify_pass->fetch_assoc();
                    if (hashAdminProfilePassword($password_lama) !== $user_pass_data['password']) { // Gunakan fungsi hash yang sesuai
                        throw new Exception("Password lama yang Anda masukkan salah.");
                    }
                } else {
                    throw new Exception("Gagal memverifikasi admin.");
                }
                $stmt_verify_pass->close();

                $sql_update_user .= ", password = ?";
                $types .= "s";
                $params[] = hashAdminProfilePassword($password_baru); // Gunakan fungsi hash yang sesuai
            }

            $sql_update_user .= " WHERE id = ? AND role = 'admin'"; // Pastikan hanya update admin
            $types .= "i";
            $params[] = $user_id_to_edit;

            $stmt_update = $conn->prepare($sql_update_user);
            if (!$stmt_update) throw new Exception("Error prepare (update user): " . $conn->error);

            $stmt_update->bind_param($types, ...$params);
            if (!$stmt_update->execute()) throw new Exception("Gagal memperbarui profil admin: " . $stmt_update->error);
            
            $stmt_update->close();
            $conn->commit();
            $update_success = true;

            $_SESSION['username'] = $new_username;
            $_SESSION['nama_user'] = $new_nama;
            $current_db_username = $new_username;
            $current_db_nama = $new_nama;

            $message = "<p class='text-green-500 p-3 bg-green-100 border border-green-400 rounded'>Profil Admin berhasil diperbarui.</p>";
            if(!empty($password_baru)) $message .= "<p class='text-sm text-green-600'>Password Anda juga telah diubah.</p>";

        } catch (Exception $e) {
            $conn->rollback();
            $message = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Update Gagal: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
}

// Include header.php, karena profil.php ada di admin/, path ke includes/ adalah ../includes/
// Dan assets juga akan menggunakan ../assets/
$assets_path_prefix = '../'; // Untuk header.php agar path ke assets benar dari admin/
include '../includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <h2 class="text-2xl font-bold mb-6 text-gray-800"><i class="fas fa-user-shield mr-2"></i> Edit Akun Admin</h2>

    <?php if (!empty($message)): ?>
        <div class="mb-4">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <div class="bg-white shadow-md rounded-lg p-6 md:p-8 max-w-lg mx-auto">
        <form method="POST" action="profil.php" class="space-y-6"> <!-- action mengarah ke file ini sendiri -->
            <div>
                <label for="nama_lengkap" class="block text-sm font-medium text-gray-700">Nama Lengkap</label>
                <input type="text" name="nama_lengkap" id="nama_lengkap" value="<?php echo htmlspecialchars($current_db_nama); ?>" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required>
            </div>
            <div>
                <label for="username" class="block text-sm font-medium text-gray-700">Username</label>
                <input type="text" name="username" id="username" value="<?php echo htmlspecialchars($current_db_username); ?>" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required>
            </div>

            <hr class="my-6">
            <h3 class="text-lg font-medium text-gray-900">Ubah Password Admin (Opsional)</h3>

            <div>
                <label for="password_lama" class="block text-sm font-medium text-gray-700">Password Lama</label>
                <input type="password" name="password_lama" id="password_lama" placeholder="Masukkan password lama jika ingin ganti" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
            </div>
            <div>
                <label for="password_baru" class="block text-sm font-medium text-gray-700">Password Baru</label>
                <input type="password" name="password_baru" id="password_baru" placeholder="Minimal 6 karakter" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
            </div>
            <div>
                <label for="konfirmasi_password_baru" class="block text-sm font-medium text-gray-700">Konfirmasi Password Baru</label>
                <input type="password" name="konfirmasi_password_baru" id="konfirmasi_password_baru" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
            </div>

            <button type="submit" name="update_profil_admin" class="w-full inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                <i class="fas fa-save mr-2"></i> Simpan Perubahan Akun Admin
            </button>
        </form>
    </div>
</div>

<?php
// Path ke footer.php dari admin/profil.php adalah ../includes/footer.php
include '../includes/footer.php';
$conn->close();
?>