<?php
// -----------------------------------------------------------------------------
// BAGIAN 1: INISIALISASI DAN VALIDASI SESI
// -----------------------------------------------------------------------------
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verifikasi sesi dan role untuk halaman ini
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'wali_murid') {
    if (!isset($_SESSION['flash_message_profil'])) {
        $_SESSION['flash_message_profil'] = ['type' => 'error', 'messages' => ['Anda harus login sebagai wali murid untuk mengakses halaman ini.']];
    }
    header('Location: login.php'); // Asumsi login.php ada di folder wali_murid
    exit;
}

// -----------------------------------------------------------------------------
// BAGIAN 2: KONEKSI DATABASE (JIKA BELUM ADA DARI HEADER)
// -----------------------------------------------------------------------------
$conn_error_message = '';
if (!isset($conn)) {
    if (file_exists(__DIR__ . '/../includes/db.php')) {
        include_once __DIR__ . '/../includes/db.php';
    }
    if (!isset($conn)) {
        $conn_error_message = "Error: Koneksi database tidak dapat dibuat.";
    } elseif (is_object($conn) && property_exists($conn, 'connect_error') && $conn->connect_error) {
        $conn_error_message = "Error: Gagal terhubung ke database: " . htmlspecialchars($conn->connect_error);
    }
}

// -----------------------------------------------------------------------------
// BAGIAN 3: AMBIL DATA PENGGUNA SAAT INI
// -----------------------------------------------------------------------------
$data_wali = null;
$current_username = $_SESSION['username'] ?? '';
$current_nama = $_SESSION['nama_user'] ?? ''; // Fallback ke nama_user dari sesi
$db_fetch_error_message = '';

if (empty($conn_error_message) && isset($conn)) {
    $user_id_saat_ini = $_SESSION['user_id'];
    $stmt_data = $conn->prepare("SELECT username, nama FROM users WHERE id = ?"); // Kolom 'nama' untuk nama lengkap
    if ($stmt_data) {
        $stmt_data->bind_param("i", $user_id_saat_ini);
        $stmt_data->execute();
        $result_data = $stmt_data->get_result();
        if ($result_data->num_rows > 0) {
            $data_wali = $result_data->fetch_assoc();
            $current_username = $data_wali['username'];
            $current_nama = $data_wali['nama']; // Ambil dari kolom 'nama'
        } else {
            $db_fetch_error_message = "Data profil tidak ditemukan di database.";
        }
        $stmt_data->close();
    } else {
        $db_fetch_error_message = "Gagal mengambil data profil: " . htmlspecialchars($conn->error);
    }
}

// -----------------------------------------------------------------------------
// BAGIAN 4: INCLUDE HEADER (SETELAH SEMUA LOGIKA PHP AWAL)
// -----------------------------------------------------------------------------
// Jika header.php mengatur $base_path_for_includes, pastikan itu benar untuk halaman ini.
// Misal: $current_page_depth = 'subfolder'; // Jika header.php Anda menggunakan ini
include_once __DIR__ . '/../includes/header.php';
?>

<div class="container mx-auto mt-4 md:mt-8 px-4 py-8">
    <div class="max-w-2xl mx-auto bg-white p-6 md:p-8 rounded-xl shadow-2xl">
        <h1 class="text-2xl sm:text-3xl font-bold mb-6 text-center text-gray-800">Edit Profil Anda</h1>

        <?php
        // Tampilkan pesan error koneksi atau fetch DB jika ada
        if (!empty($conn_error_message)) {
            echo "<div class='mb-6 p-4 bg-red-100 border-l-4 border-red-500 text-red-700 rounded-md shadow-sm'><p class='font-medium'>Kesalahan Koneksi</p><p class='text-sm'>" . $conn_error_message . "</p></div>";
        }
        if (!empty($db_fetch_error_message)) {
            echo "<div class='mb-6 p-4 bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 rounded-md shadow-sm'><p class='font-medium'>Peringatan Data</p><p class='text-sm'>" . $db_fetch_error_message . "</p></div>";
        }

        // Tampilkan pesan flash dari proses_edit_profil.php
        if (isset($_SESSION['flash_message_profil'])) {
            $flash = $_SESSION['flash_message_profil'];
            $alert_bg_color = 'bg-blue-100';
            $alert_border_color = 'border-blue-500';
            $alert_text_color = 'text-blue-700';
            $alert_title = 'Informasi';

            if ($flash['type'] === 'success') {
                $alert_bg_color = 'bg-green-100';
                $alert_border_color = 'border-green-500';
                $alert_text_color = 'text-green-700';
                $alert_title = 'Berhasil';
            } else if ($flash['type'] === 'error') {
                $alert_bg_color = 'bg-red-100';
                $alert_border_color = 'border-red-500';
                $alert_text_color = 'text-red-700';
                $alert_title = 'Kesalahan Validasi';
            }

            echo "<div class='mb-6 p-4 {$alert_bg_color} border-l-4 {$alert_border_color} {$alert_text_color} rounded-md shadow-sm'>";
            echo "<p class='font-bold'>{$alert_title}</p>";
            if (is_array($flash['messages'])) {
                foreach ($flash['messages'] as $msg) {
                    echo "<p class='text-sm'>" . htmlspecialchars($msg) . "</p>";
                }
            } else {
                echo "<p class='text-sm'>" . htmlspecialchars($flash['messages']) . "</p>";
            }
            echo "</div>";
            unset($_SESSION['flash_message_profil']);
        }
        ?>

        <?php if (empty($conn_error_message)): // Hanya tampilkan form jika koneksi DB OK dan tidak ada error fatal fetch data ?>
        <form action="proses_edit_profil.php" method="POST" class="space-y-6">
            <div>
                <label for="form_username_wali" class="block text-sm font-medium text-gray-700 mb-1">
                    Username <span class="text-red-500">*</span>
                </label>
                <input type="text" name="username_wali" id="form_username_wali"
                       value="<?php echo htmlspecialchars($current_username); ?>"
                       class="mt-1 block w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required>
                <p class="mt-1 text-xs text-gray-500">Username unik Anda untuk login. Min. 4 karakter (huruf, angka, titik, underscore).</p>
            </div>

            <div>
                <label for="form_nama_wali" class="block text-sm font-medium text-gray-700 mb-1">
                    Nama Lengkap Wali Murid <span class="text-red-500">*</span>
                </label>
                <input type="text" name="nama_wali" id="form_nama_wali"
                       value="<?php echo htmlspecialchars($current_nama); ?>"
                       class="mt-1 block w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required>
            </div>

            <hr class="my-6 border-gray-200">

            <p class="text-sm font-medium text-gray-700">Ubah Password (Opsional)</p>
            <div class="space-y-6">
                <div>
                    <label for="form_password_baru_wali" class="block text-sm font-medium text-gray-700 mb-1">
                        Password Baru
                    </label>
                    <input type="password" name="password_baru_wali" id="form_password_baru_wali"
                           class="mt-1 block w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" placeholder="Kosongkan jika tidak ingin mengubah">
                    <p class="mt-1 text-xs text-gray-500">Minimal 6 karakter.</p>
                </div>

                <div>
                    <label for="form_konfirmasi_password_baru_wali" class="block text-sm font-medium text-gray-700 mb-1">
                        Konfirmasi Password Baru
                    </label>
                    <input type="password" name="konfirmasi_password_baru_wali" id="form_konfirmasi_password_baru_wali"
                           class="mt-1 block w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" placeholder="Ulangi password baru">
                </div>
            </div>

            <div class="pt-5">
                <div class="flex justify-start space-x-3">
                    <button type="submit"
                            class="inline-flex justify-center py-2 px-6 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition duration-150 ease-in-out">
                        Simpan Perubahan
                    </button>
                    <a href="index.php"
                       class="py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition duration-150 ease-in-out">
                        Batal
                    </a>
                </div>
            </div>
        </form>
        <?php endif; // Akhir dari if koneksi DB OK ?>
    </div>
</div>

<?php
// Include footer.php
include_once __DIR__ . '/../includes/footer.php';
?>