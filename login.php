<?php
// Bismillah.
// Aplikasi ini dibuat oleh apt. Agus Purnomo, MM, email: apt.aguspurnomo@gmail.com
// Aplikasi ini dibuat untuk Al Azhar Solo Baru

session_start();

// Jika user sudah login, redirect ke dashboard masing-masing
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] == 'admin') header('Location: admin/index.php');
    elseif ($_SESSION['role'] == 'wali_kelas') header('Location: wali_kelas/index.php');
    elseif ($_SESSION['role'] == 'wali_murid') header('Location: wali_murid/index.php');
    exit;
}

// Include koneksi database
// Pastikan file db.php tidak menghasilkan output apapun sebelum header dikirim oleh skrip ini.
if (file_exists(__DIR__ . '/includes/db.php')) { // Gunakan __DIR__ untuk path yang lebih robust
    require_once __DIR__ . '/includes/db.php';
} else {
    // Jika db.php tidak ada, aplikasi tidak bisa berjalan.
    // Kita bisa menampilkan pesan error dasar atau log dan exit.
    error_log("Kritis: File db.php tidak ditemukan.");
    die("Error konfigurasi sistem. Silakan hubungi administrator.");
}


$login_error = '';
// Path default untuk gambar dan teks, akan ditimpa dari DB jika ada
$header_image_path = 'assets/images/default-header-image.png'; 
$footer_text_marquee = 'Selamat Datang di Aplikasi Penjemputan Siswa!';
$nama_sekolah_display = 'Nama Sekolah Default';
$header_color_setting = '#4A5568'; // Warna default jika tidak ada di DB (mis: abu-abu gelap)
$PASSWORD_DEFAULT_WALI_MURID_LOGIN = 'walmur123'; // Password default untuk wali murid

// Ambil pengaturan dari database
if (isset($conn) && !$conn->connect_error) {
    $query_pengaturan = "SELECT nama_sekolah, header_image, footer_text, header_color FROM pengaturan WHERE id = 1 LIMIT 1";
    $result_pengaturan = $conn->query($query_pengaturan);
    if ($result_pengaturan && $result_pengaturan->num_rows > 0) {
        $pengaturan = $result_pengaturan->fetch_assoc();
        if (!empty($pengaturan['nama_sekolah'])) {
            $nama_sekolah_display = htmlspecialchars($pengaturan['nama_sekolah']);
        }
        
        if (!empty($pengaturan['header_image'])) {
            $db_header_path = ltrim($pengaturan['header_image'], '/'); 
            // Cek apakah file ada relatif terhadap root dokumen web, bukan __DIR__
            if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/' . $db_header_path) || file_exists($db_header_path)) {
                 // Path yang disimpan di DB adalah relatif terhadap root web
                $header_image_path = $db_header_path;
            } else {
                error_log("Login Page: Header image dari DB ('" . $pengaturan['header_image'] . "') tidak ditemukan. Menggunakan default.");
            }
        }
        if (!empty($pengaturan['footer_text'])) {
            $footer_text_marquee = htmlspecialchars($pengaturan['footer_text']);
        }
        if (!empty($pengaturan['header_color']) && preg_match('/^#[a-f0-9]{6}$/i', $pengaturan['header_color'])) {
            $header_color_setting = htmlspecialchars($pengaturan['header_color']);
        }
    } else {
        error_log("Login Page: Pengaturan default (ID: 1) tidak ditemukan di tabel 'pengaturan'.");
    }
} elseif (isset($conn) && $conn->connect_error) {
    error_log("Login Page: Koneksi database gagal: " . $conn->connect_error);
    $login_error = "Tidak dapat terhubung ke sistem. Silakan coba lagi nanti."; // Pesan umum untuk user
} else {
    error_log("Login Page: Variabel koneksi \$conn tidak terdefinisi.");
    $login_error = "Error konfigurasi sistem. Silakan hubungi administrator.";
}


function verifyPasswordLogin($input_password, $hashed_password_from_db, $is_wali_murid = false) {
    global $PASSWORD_DEFAULT_WALI_MURID_LOGIN;
    if ($is_wali_murid) {
        if (empty($input_password)) {
            return (md5($PASSWORD_DEFAULT_WALI_MURID_LOGIN) === $hashed_password_from_db || md5('') === $hashed_password_from_db);
        }
        // Jika wali murid input password default, cek dengan md5(default)
        if ($input_password === $PASSWORD_DEFAULT_WALI_MURID_LOGIN) {
             return (md5($input_password) === $hashed_password_from_db);
        }
        // Jika wali murid input password lain (sudah diubah), atau untuk role lain
    }
    return md5($input_password) === $hashed_password_from_db;
}


if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($conn) || $conn->connect_error) {
        $login_error = "Koneksi database bermasalah. Silakan coba lagi nanti.";
    } else {
        $username = isset($_POST['username']) ? trim($_POST['username']) : '';
        $password_input = isset($_POST['password']) ? $_POST['password'] : ''; 
        $expected_role_from_button = isset($_POST['login_as']) ? trim($_POST['login_as']) : null;

        if (empty($username)) {
            $login_error = "Username harus diisi!";
        } else {
            $stmt = $conn->prepare("SELECT id, username, password, role, kelas_id, nama FROM users WHERE username = ?");
            if ($stmt) {
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows === 1) {
                    $user = $result->fetch_assoc();
                    $actual_role_from_db = $user['role'];
                    $password_valid = false;

                    if ($actual_role_from_db !== 'admin') { 
                        $role_map_button = ['wali_kelas' => 'wali_kelas', 'wali_murid' => 'wali_murid'];
                        if (isset($role_map_button[$expected_role_from_button])) {
                            if ($role_map_button[$expected_role_from_button] !== $actual_role_from_db) {
                                 $login_error = "Akun ini adalah " . ucfirst(str_replace('_', ' ', $actual_role_from_db)) . ". Silakan gunakan tombol login yang sesuai.";
                            }
                        } else {
                            $login_error = "Jenis login tidak valid. Akun ini adalah " . ucfirst(str_replace('_', ' ', $actual_role_from_db)) . ".";
                        }
                    }
                    // Jika admin, tidak ada validasi tombol $expected_role_from_button

                    if (empty($login_error)) {
                        if ($actual_role_from_db === 'wali_murid') {
                            $password_valid = verifyPasswordLogin($password_input, $user['password'], true);
                        } else { 
                            if (empty($password_input)) {
                                $login_error = "Password harus diisi untuk login sebagai " . ucfirst(str_replace('_',' ',$actual_role_from_db)) . ".";
                            } else {
                                $password_valid = verifyPasswordLogin($password_input, $user['password'], false);
                            }
                        }
                    }

                    if (empty($login_error) && $password_valid) {
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['nama_user'] = $user['nama'];
                        $_SESSION['role'] = $actual_role_from_db;
                        
                        if ($actual_role_from_db == 'wali_kelas' && !empty($user['kelas_id'])) {
                            $_SESSION['kelas_id_wali'] = $user['kelas_id'];
                        } else {
                            unset($_SESSION['kelas_id_wali']);
                        }
                        
                        // Set app_settings ke session agar bisa diakses di header/footer halaman lain
                        $_SESSION['app_settings'] = [
                            'nama_sekolah' => $nama_sekolah_display,
                            'header_image' => $header_image_path,
                            'footer_text'  => $footer_text_marquee,
                            'header_color' => $header_color_setting
                            // Anda bisa menambahkan 'footer_color' jika ada di tabel pengaturan dan diperlukan
                        ];

                        if ($actual_role_from_db == 'admin') header('Location: admin/index.php');
                        elseif ($actual_role_from_db == 'wali_kelas') header('Location: wali_kelas/index.php');
                        elseif ($actual_role_from_db == 'wali_murid') header('Location: wali_murid/index.php');
                        else $login_error = "Role pengguna tidak dikenal setelah login berhasil!"; 
                        
                        if (empty($login_error)) exit; 
                        
                    } elseif (empty($login_error) && !$password_valid) {
                        $login_error = "Username atau password salah!";
                    }

                } else {
                    $login_error = "Username atau password salah!";
                }
                $stmt->close();
            } else {
                $login_error = "Terjadi kesalahan pada sistem (DB Prepare Error). Silakan coba lagi nanti.";
                error_log("Login error - DB Prepare failed: " . $conn->error);
            }
        }
    }
}
// Tutup koneksi SETELAH semua logika PHP yang memerlukan DB selesai, SEBELUM output HTML dimulai.
if(isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Aplikasi Penjemputan Siswa<?php echo !empty($nama_sekolah_display) && $nama_sekolah_display !== 'Nama Sekolah Default' ? ' - ' . htmlspecialchars($nama_sekolah_display) : ''; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="icon" href="assets/images/favicon.ico" type="image/x-icon">
    <style>
        html, body { height: 100%; margin: 0; padding: 0; }
        body {
            background-color: #f0f4f8; 
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            display: flex; /* Menggunakan flex untuk centering wrapper */
            flex-direction: column; /* Agar footer bisa di bawah */
        }
        .top-header-strip {
            width: 100%;
            padding: 0.75rem 0; 
            background-color: <?php echo htmlspecialchars($header_color_setting); ?>;
            position: fixed; 
            top: 0;
            left: 0;
            z-index: 100; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .login-container-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            flex-grow: 1; /* Mengambil sisa ruang vertikal */
            width: 100%;
            padding: 1rem;
            padding-top: 5rem; /* Ruang untuk top-header-strip (0.75rem * 2 (padding) + estimasi tinggi konten) */
            padding-bottom: 5rem; /* Ruang untuk marquee-container */
        }
        .login-container {
            background-color: rgba(255, 255, 255, 0.98);
            width: 100%;
            max-width: 28rem; /* max-w-md */
        }
        .marquee-container { 
            background-color: <?php echo htmlspecialchars($header_color_setting); ?>e6; 
            color: white; 
            padding: 0.5rem 0; 
            overflow: hidden; 
            position: fixed; 
            bottom: 0; 
            left: 0; 
            width: 100%; 
            z-index: 50;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
        }
        .marquee-text { display: inline-block; white-space: nowrap; animation: marquee 25s linear infinite; padding-left: 100%; font-size: 0.875rem; }
        @keyframes marquee { 0% { transform: translateX(0%); } 100% { transform: translateX(-100%); } }
    </style>
</head>
<body>

    <div class="top-header-strip">
        <!-- Konten strip header (opsional) -->
    </div>

    <div class="login-container-wrapper"> 
        <div class="p-6 md:p-8 rounded-xl shadow-2xl login-container">
            <?php 
            // Cek lagi path header image relatif terhadap root web
            $web_root_header_image_path = ltrim($header_image_path, '/');
            if (!empty($header_image_path) && file_exists($web_root_header_image_path)): 
            ?>
                <img src="<?php echo htmlspecialchars($web_root_header_image_path); ?>?t=<?php echo time();?>" alt="Header Sekolah" class="w-full h-auto max-h-32 sm:max-h-40 mx-auto object-contain mb-5 rounded-md">
            <?php else: ?>
                <div class="w-full h-24 sm:h-32 bg-gray-200 flex items-center justify-center mb-5 rounded-md text-gray-400">
                    <i class="fas fa-school fa-3x"></i>
                </div>
            <?php endif; ?>

            <h1 class="text-2xl sm:text-3xl font-bold text-center text-indigo-700 mb-1">
                Aplikasi Penjemputan Siswa
            </h1>
            <?php if (!empty($nama_sekolah_display) && $nama_sekolah_display !== 'Nama Sekolah Default'): ?>
                <p class="text-md sm:text-lg text-center text-gray-700 mb-4 font-semibold"><?php echo htmlspecialchars($nama_sekolah_display); ?></p>
            <?php endif; ?>

            <?php if (!empty($login_error)): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-3 mb-4 rounded-md shadow text-sm" role="alert">
                    <div class="flex">
                        <div class="py-1"><i class="fas fa-times-circle mr-2"></i></div>
                        <div>
                            <p class="font-bold">Login Gagal</p>
                            <p><?php echo htmlspecialchars($login_error); ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php" class="space-y-5">
                <div>
                    <label for="username" class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-user mr-1.5 text-gray-500"></i> Username</label>
                    <input type="text" name="username" id="username" class="w-full p-3 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition duration-150" required value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" placeholder="Masukkan username Anda">
                </div>
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-lock mr-1.5 text-gray-500"></i> Password</label>
                    <input type="password" name="password" id="password" class="w-full p-3 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition duration-150" placeholder="Wali Murid: bisa kosong/default">
                    <p class="mt-1.5 text-xs text-gray-500">Wali Murid dapat mengosongkan atau menggunakan password default jika belum diubah.</p>
                </div>

                <div class="space-y-3 pt-4">
                    <button type="submit" name="login_as" value="wali_murid" class="w-full flex items-center justify-center bg-emerald-600 text-white py-3 px-4 rounded-lg hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500 font-semibold shadow-md hover:shadow-lg transition duration-150 text-base">
                        <i class="fas fa-users mr-2"></i> Login sebagai Wali Murid
                    </button>
                    <button type="submit" name="login_as" value="wali_kelas" class="w-full flex items-center justify-center bg-sky-600 text-white py-3 px-4 rounded-lg hover:bg-sky-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-sky-500 font-semibold shadow-md hover:shadow-lg transition duration-150 text-base">
                        <i class="fas fa-chalkboard-teacher mr-2"></i> Login sebagai Wali Kelas
                    </button>
                </div>
                 <p class="text-xs text-center text-gray-500 pt-3">
                    Lupa password atau butuh bantuan? Hubungi Administrator Sekolah.
                </p>
            </form>
        </div>
    </div>

    <?php if (!empty($footer_text_marquee)): ?>
    <div class="marquee-container">
        <div class="marquee-text">
            <span><?php echo htmlspecialchars($footer_text_marquee); ?></span>
            <span class="ml-16"><?php echo htmlspecialchars($footer_text_marquee); ?></span>
            <span class="ml-16"><?php echo htmlspecialchars($footer_text_marquee); ?></span>
        </div>
    </div>
    <?php endif; ?>
</body>
</html>