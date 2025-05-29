<?php
// -----------------------------------------------------------------------------
// BAGIAN 1: INISIALISASI DAN VALIDASI SESI (DENGAN DEBUG TAMBAHAN)
// -----------------------------------------------------------------------------
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    // echo "DEBUG (edit_akun.php): Memulai sesi...<br>";
    session_start();
} else {
    // echo "DEBUG (edit_akun.php): Sesi sudah aktif.<br>";
}

// DEBUG: Tampilkan isi sesi untuk melihat nilainya sebelum validasi
// echo "DEBUG (edit_akun.php): Isi awal _SESSION: <pre>" . htmlspecialchars(print_r($_SESSION, true)) . "</pre>";

// Verifikasi sesi dan role untuk halaman ini
if (!isset($_SESSION['user_id'])) {
    // echo "DEBUG (edit_akun.php): GAGAL - _SESSION['user_id'] tidak diset. Redirecting ke login...<br>";
    if (!isset($_SESSION['flash_message_akun_wk'])) {
        $_SESSION['flash_message_akun_wk'] = ['type' => 'error', 'messages' => ['Sesi Anda tidak valid atau telah berakhir. Silakan login kembali.']];
    }
    // Asumsi login.php untuk wali kelas ada di folder yang sama (wali_kelas/)
    // Jika login.php ada di root, gunakan '../login.php'
    header('Location: login.php');
    exit;
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'wali_kelas') {
    // echo "DEBUG (edit_akun.php): GAGAL - _SESSION['role'] bukan 'wali_kelas' atau tidak diset. Role saat ini: " . ($_SESSION['role'] ?? 'Tidak ada') . ". Redirecting ke login...<br>";
    if (!isset($_SESSION['flash_message_akun_wk'])) {
        $_SESSION['flash_message_akun_wk'] = ['type' => 'error', 'messages' => ['Akses ditolak. Anda harus login sebagai wali kelas.']];
    }
    // Asumsi login.php untuk wali kelas ada di folder yang sama (wali_kelas/)
    // Jika login.php ada di root, gunakan '../login.php'
    header('Location: login.php');
    exit;
}

// Jika lolos, tampilkan pesan sukses debug
// echo "DEBUG (edit_akun.php): SUKSES - Sesi user_id dan role wali_kelas terverifikasi.<br>";

// -----------------------------------------------------------------------------
// BAGIAN 2: KONEKSI DATABASE (JIKA BELUM ADA DARI HEADER)
// -----------------------------------------------------------------------------
$conn_error_message = '';
if (!isset($conn)) {
    // echo "DEBUG (edit_akun.php): Variabel \$conn tidak diset, mencoba include db.php...<br>";
    if (file_exists(__DIR__ . '/../includes/db.php')) {
        include_once __DIR__ . '/../includes/db.php';
        // echo "DEBUG (edit_akun.php): File db.php di-include.<br>";
    } else {
        // echo "DEBUG (edit_akun.php): File db.php TIDAK ditemukan di " . __DIR__ . "/../includes/db.php<br>";
    }

    if (!isset($conn)) {
        $conn_error_message = "Error: Koneksi database tidak dapat dibuat (file db.php tidak ditemukan atau \$conn tidak diset setelah include).";
        // echo "DEBUG (edit_akun.php): \$conn masih tidak diset setelah mencoba include.<br>";
    } elseif (is_object($conn) && property_exists($conn, 'connect_error') && $conn->connect_error) {
        $conn_error_message = "Error: Gagal terhubung ke database: " . htmlspecialchars($conn->connect_error);
        // echo "DEBUG (edit_akun.php): Koneksi DB gagal: " . htmlspecialchars($conn->connect_error) . "<br>";
    } else {
        // echo "DEBUG (edit_akun.php): Koneksi DB berhasil dibuat atau sudah ada.<br>";
    }
} else {
    // echo "DEBUG (edit_akun.php): Variabel \$conn sudah ada sebelumnya.<br>";
}


// -----------------------------------------------------------------------------
// BAGIAN 3: AMBIL DATA PENGGUNA SAAT INI
// -----------------------------------------------------------------------------
$data_wk = null;
$current_username_wk = $_SESSION['username'] ?? '';
$current_nama_wk = $_SESSION['nama_user'] ?? '';
$db_fetch_error_message = '';

if (empty($conn_error_message) && isset($conn)) {
    $user_id_saat_ini = $_SESSION['user_id'];
    // echo "DEBUG (edit_akun.php): Mencoba mengambil data untuk user_id: " . $user_id_saat_ini . "<br>";
    $stmt_data = $conn->prepare("SELECT username, nama FROM users WHERE id = ?");
    if ($stmt_data) {
        $stmt_data->bind_param("i", $user_id_saat_ini);
        $stmt_data->execute();
        $result_data = $stmt_data->get_result();
        if ($result_data->num_rows > 0) {
            $data_wk = $result_data->fetch_assoc();
            $current_username_wk = $data_wk['username'];
            $current_nama_wk = $data_wk['nama'];
            // echo "DEBUG (edit_akun.php): Data pengguna ditemukan di DB: Username=" . $current_username_wk . ", Nama=" . $current_nama_wk . "<br>";
        } else {
            $db_fetch_error_message = "Data akun tidak ditemukan di database untuk ID pengguna Anda.";
            // echo "DEBUG (edit_akun.php): Data pengguna TIDAK ditemukan di DB untuk ID: " . $user_id_saat_ini . "<br>";
        }
        $stmt_data->close();
    } else {
        $db_fetch_error_message = "Gagal mempersiapkan statement untuk mengambil data akun: " . htmlspecialchars($conn->error);
        // echo "DEBUG (edit_akun.php): Gagal prepare statement: " . htmlspecialchars($conn->error) . "<br>";
    }
} else {
    // echo "DEBUG (edit_akun.php): Tidak mencoba mengambil data DB karena ada conn_error_message atau \$conn tidak ada.<br>";
}

// -----------------------------------------------------------------------------
// BAGIAN 4: INCLUDE HEADER (SETELAH SEMUA LOGIKA PHP AWAL)
// -----------------------------------------------------------------------------
// echo "DEBUG (edit_akun.php): Akan meng-include header.php...<br>";
include_once __DIR__ . '/../includes/header.php';
// echo "DEBUG (edit_akun.php): header.php selesai di-include.<br>";
?>

<div class="container mx-auto mt-4 md:mt-8 px-4 py-8">
    <div class="max-w-2xl mx-auto bg-white p-6 md:p-8 rounded-xl shadow-2xl">
        <h1 class="text-2xl sm:text-3xl font-bold mb-6 text-center text-gray-800">Edit Akun Wali Kelas</h1>

        <?php
        // Tampilkan pesan error koneksi atau fetch DB jika ada
        if (!empty($conn_error_message)) {
            echo "<div class='mb-6 p-4 bg-red-100 border-l-4 border-red-500 text-red-700 rounded-md shadow-sm'><p class='font-medium'>Kesalahan Koneksi</p><p class='text-sm'>" . $conn_error_message . "</p></div>";
        }
        if (!empty($db_fetch_error_message)) {
            echo "<div class='mb-6 p-4 bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 rounded-md shadow-sm'><p class='font-medium'>Peringatan Data</p><p class='text-sm'>" . $db_fetch_error_message . "</p></div>";
        }

        // Tampilkan pesan flash dari proses_edit_akun.php atau dari validasi sesi
        if (isset($_SESSION['flash_message_akun_wk'])) {
            $flash = $_SESSION['flash_message_akun_wk'];
            $alert_bg_color = 'bg-blue-100'; $alert_border_color = 'border-blue-500'; $alert_text_color = 'text-blue-700'; $alert_title = 'Informasi';
            if ($flash['type'] === 'success') { $alert_bg_color = 'bg-green-100'; $alert_border_color = 'border-green-500'; $alert_text_color = 'text-green-700'; $alert_title = 'Berhasil'; }
            else if ($flash['type'] === 'error') { $alert_bg_color = 'bg-red-100'; $alert_border_color = 'border-red-500'; $alert_text_color = 'text-red-700'; $alert_title = 'Kesalahan';} // Judul diubah

            echo "<div class='mb-6 p-4 {$alert_bg_color} border-l-4 {$alert_border_color} {$alert_text_color} rounded-md shadow-sm'>";
            echo "<p class='font-bold'>{$alert_title}</p>";
            if (is_array($flash['messages'])) {
                foreach ($flash['messages'] as $msg) { echo "<p class='text-sm'>" . htmlspecialchars($msg) . "</p>"; }
            } else { echo "<p class='text-sm'>" . htmlspecialchars($flash['messages']) . "</p>"; }
            echo "</div>";
            unset($_SESSION['flash_message_akun_wk']);
        }
        ?>

        <?php if (empty($conn_error_message) && empty($db_fetch_error_message) ): // Tampilkan form hanya jika tidak ada error fatal ?>
        <form action="proses_edit_akun.php" method="POST" class="space-y-6">
            <div>
                <label for="form_username_wk" class="block text-sm font-medium text-gray-700 mb-1">
                    Username <span class="text-red-500">*</span>
                </label>
                <input type="text" name="username_wk" id="form_username_wk"
                       value="<?php echo htmlspecialchars($current_username_wk); ?>"
                       class="mt-1 block w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required>
                <p class="mt-1 text-xs text-gray-500">Username unik untuk login. Min. 4 karakter.</p>
            </div>

            <div>
                <label for="form_nama_wk" class="block text-sm font-medium text-gray-700 mb-1">
                    Nama Lengkap <span class="text-red-500">*</span>
                </label>
                <input type="text" name="nama_wk" id="form_nama_wk"
                       value="<?php echo htmlspecialchars($current_nama_wk); ?>"
                       class="mt-1 block w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required>
            </div>

            <hr class="my-6 border-gray-200">

            <p class="text-sm font-medium text-gray-700">Ubah Password (Opsional)</p>
            <div class="space-y-6">
                <div>
                    <label for="form_password_baru_wk" class="block text-sm font-medium text-gray-700 mb-1">
                        Password Baru
                    </label>
                    <input type="password" name="password_baru_wk" id="form_password_baru_wk"
                           class="mt-1 block w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" placeholder="Kosongkan jika tidak ingin mengubah">
                    <p class="mt-1 text-xs text-gray-500">Minimal 6 karakter.</p>
                </div>

                <div>
                    <label for="form_konfirmasi_password_baru_wk" class="block text-sm font-medium text-gray-700 mb-1">
                        Konfirmasi Password Baru
                    </label>
                    <input type="password" name="konfirmasi_password_baru_wk" id="form_konfirmasi_password_baru_wk"
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
        <?php else: // Jika ada error koneksi atau fetch data, tampilkan pesan ?>
            <p class="text-center text-red-600">Form tidak dapat ditampilkan karena terjadi kesalahan pada sistem atau data tidak ditemukan.</p>
            <div class="mt-6 text-center">
                 <a href="index.php"
                       class="py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition duration-150 ease-in-out">
                        Kembali ke Dashboard
                    </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
include_once __DIR__ . '/../includes/footer.php';
?>