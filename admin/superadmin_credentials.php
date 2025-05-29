<?php
// superadmin_credentials.php
// Versi sederhana: Menampilkan kredensial admin (hash password)
// Memerlukan login sebagai admin utama.

session_start();

// 1. Verifikasi Login Admin Utama
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['flash_message_login'] = ['type' => 'error', 'text' => 'Anda harus login sebagai admin untuk mengakses halaman ini.'];
    header('Location: ../login.php'); // Sesuaikan jika file ini tidak di root
    exit;
}

// 2. Include Koneksi Database
$db_connection_error = null;
$db_file_path = __DIR__ . '/includes/db.php'; // Path jika file ini ada di root

if (file_exists($db_file_path)) {
    require_once $db_file_path;
    if (!isset($conn)) {
        $db_connection_error = "Variabel koneksi \$conn tidak terdefinisi setelah include db.php.";
    } elseif ($conn->connect_error) {
        $db_connection_error = "Koneksi DB Gagal: " . htmlspecialchars($conn->connect_error);
    }
} else {
    $db_connection_error = "Kritis: File db.php tidak ditemukan pada path: " . htmlspecialchars($db_file_path);
}

if ($db_connection_error) {
    error_log("Superadmin Creds (Simple) - DB Error: " . $db_connection_error);
    // Kita akan tetap render halaman dengan pesan error
}

// --- INISIALISASI VARIABEL ---
$admin_users = [];
$fetch_error = $db_connection_error; // Gunakan error koneksi awal jika ada

// --- Ambil data admin ---
if (!$fetch_error && isset($conn)) { // Hanya jika koneksi ada dan tidak error
    $stmt = $conn->prepare("SELECT id, username, password, nama FROM users WHERE role = 'admin' ORDER BY id ASC");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $admin_users[] = $row;
        }
        $stmt->close();
    } else {
        $fetch_error = "Gagal mengambil data admin (prepare): " . $conn->error;
        error_log("Superadmin Creds (Simple) - Fetch Error: " . $fetch_error);
    }
}
// --- AKHIR PENGAMBILAN DATA ADMIN ---

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Kredensial Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body { font-family: sans-serif; }
        .main-content-area { padding-top: 1rem; padding-bottom: 1rem; } 
    </style>
</head>
<body class="bg-gray-100 p-4 sm:p-8 main-content-area">
    <div class="container mx-auto max-w-3xl bg-white shadow-xl rounded-lg p-6">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 border-b pb-4">
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 mb-2 sm:mb-0"><i class="fas fa-users-cog mr-2"></i> Daftar Kredensial Akun Admin</h1>
            <?php 
            // Path ke admin dashboard. Jika file ini di root, pathnya 'admin/index.php'
            // Jika file ini di dalam admin/, pathnya 'index.php'
            $admin_dashboard_path = (basename(realpath(__DIR__)) === 'penjemputan_siswa') ? 'admin/index.php' : 'index.php'; 
            ?>
            <a href="<?php echo $admin_dashboard_path; ?>" class="text-sm text-blue-600 hover:underline"><i class="fas fa-arrow-left mr-1"></i> Kembali ke Dashboard Admin</a>
        </div>
        
        <div class="bg-yellow-50 border-l-4 border-yellow-500 text-yellow-700 p-4 mb-6 rounded-md" role="alert">
            <div class="flex">
                <div class="py-1"><i class="fas fa-info-circle mr-3"></i></div>
                <div>
                    <p class="font-bold">INFORMASI</p>
                    <p class="text-sm">Halaman ini menampilkan daftar akun admin beserta hash password mereka (MD5). Password asli tidak dapat dilihat dari hash ini. Untuk mengubah password, lakukan melalui mekanisme reset password yang aman atau langsung di database oleh administrator server.</p>
                </div>
            </div>
        </div>

        <?php if (isset($fetch_error)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded-md shadow" role="alert">
                <p class="font-bold">Error:</p>
                <p><?php echo htmlspecialchars($fetch_error); ?></p>
            </div>
        <?php elseif (empty($admin_users)): ?>
            <div class="bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-4 mb-4 rounded-md shadow" role="alert">
                <p>Tidak ada akun admin yang ditemukan di sistem.</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-300">
                    <thead class="bg-gray-200">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">ID</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Nama Lengkap</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Username</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Password (Hash MD5)</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($admin_users as $admin): ?>
                            <tr>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700"><?php echo $admin['id']; ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-800 font-medium"><?php echo htmlspecialchars($admin['nama']); ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600"><?php echo htmlspecialchars($admin['username']); ?></td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500 font-mono break-all" style="max-width: 250px; overflow-wrap: break-word;">
                                    <?php echo htmlspecialchars($admin['password']); ?>
                                    <button onclick="copyToClipboard('<?php echo htmlspecialchars(addslashes($admin['password'])); ?>', this)" class="ml-2 text-xs bg-gray-200 hover:bg-gray-300 text-gray-700 px-1 py-0.5 rounded" title="Salin Hash">
                                        <i class="far fa-copy"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
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