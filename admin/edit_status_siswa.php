<?php
// 1. Mulai session dan include koneksi database
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include '../includes/db.php';

// 2. Cek otentikasi dan otorisasi admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

// 3. Inisialisasi variabel
$message = '';
$siswa_id = isset($_GET['siswa_id']) ? (int)$_GET['siswa_id'] : 0;
$current_status_data = null;
$siswa_data = null;
$status_history = [];

// Referensi halaman kembali
$ref_kelas_id = isset($_GET['ref_kelas_id']) ? (int)$_GET['ref_kelas_id'] : null;
$ref_page = 'status_siswa.php'; // Default
if ($ref_kelas_id) {
    $ref_page .= '?kelas_id=' . $ref_kelas_id;
}


if ($siswa_id <= 0) {
    $_SESSION['flash_message_edit_status'] = ["type" => "error", "text" => "ID Siswa tidak valid."];
    header('Location: ' . $ref_page);
    exit;
}

// Ambil data siswa
$stmt_siswa = $conn->prepare("SELECT s.nama_siswa, k.nama_kelas FROM siswa s JOIN kelas k ON s.kelas_id = k.id WHERE s.id = ?");
if ($stmt_siswa) {
    $stmt_siswa->bind_param("i", $siswa_id);
    $stmt_siswa->execute();
    $result_siswa = $stmt_siswa->get_result();
    if ($result_siswa->num_rows > 0) {
        $siswa_data = $result_siswa->fetch_assoc();
    } else {
        $_SESSION['flash_message_edit_status'] = ["type" => "error", "text" => "Data siswa tidak ditemukan."];
        header('Location: ' . $ref_page);
        exit;
    }
    $stmt_siswa->close();
} else {
    // Handle error prepare
    $_SESSION['flash_message_edit_status'] = ["type" => "error", "text" => "Gagal memuat data siswa: " . $conn->error];
    header('Location: ' . $ref_page);
    exit;
}


// Logika untuk memproses form update status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $new_status = isset($_POST['status']) ? trim($_POST['status']) : '';
    $nama_penjemput_input = isset($_POST['nama_penjemput']) ? trim($_POST['nama_penjemput']) : null;
    $catatan_input = isset($_POST['catatan']) ? trim($_POST['catatan']) : null;

    // Validasi status (sesuaikan dengan ENUM Anda)
    $valid_statuses = ['masuk_kelas', 'proses_belajar', 'perjalanan_jemput', 'lima_menit_sampai', 'sudah_sampai_sekolah', 'sudah_dijemput', 'tidak_hadir_info_jemput'];
    if (empty($new_status) || !in_array($new_status, $valid_statuses)) {
        $message = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Status yang dipilih tidak valid.</p>";
    } else {
        // Insert status baru. waktu_update_status akan otomatis diisi oleh MySQL (DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)
        $stmt_insert = $conn->prepare("INSERT INTO status_penjemputan (siswa_id, status, nama_penjemput, catatan) VALUES (?, ?, ?, ?)");
        if ($stmt_insert) {
            $stmt_insert->bind_param("isss", $siswa_id, $new_status, $nama_penjemput_input, $catatan_input);
            if ($stmt_insert->execute()) {
                $_SESSION['flash_message_edit_status'] = ["type" => "success", "text" => "Status siswa '" . htmlspecialchars($siswa_data['nama_siswa']) . "' berhasil diperbarui."];
                header('Location: ' . $ref_page);
                exit;
            } else {
                $message = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Gagal memperbarui status: " . htmlspecialchars($stmt_insert->error) . "</p>";
            }
            $stmt_insert->close();
        } else {
            $message = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Gagal mempersiapkan statement update: " . htmlspecialchars($conn->error) . "</p>";
        }
    }
}


// Ambil status terakhir siswa untuk ditampilkan di form (jika ada)
// PERBAIKAN DI SINI: ganti waktu_penjemputan menjadi waktu_update_status
// Baris 116 kemungkinan ada di dalam query ini atau query serupa untuk histori
$stmt_current_status = $conn->prepare("SELECT status, nama_penjemput, catatan, waktu_update_status 
                                       FROM status_penjemputan 
                                       WHERE siswa_id = ? 
                                       ORDER BY waktu_update_status DESC 
                                       LIMIT 1");
if ($stmt_current_status) {
    $stmt_current_status->bind_param("i", $siswa_id);
    $stmt_current_status->execute();
    $result_current_status = $stmt_current_status->get_result();
    if ($result_current_status->num_rows > 0) {
        $current_status_data = $result_current_status->fetch_assoc();
    }
    $stmt_current_status->close();
} else {
    // Jika prepare gagal, set pesan error untuk ditampilkan di bawah
    $message = "<p class='text-red-500'>Error memuat status saat ini: " . htmlspecialchars($conn->error) . "</p>";
}


// Ambil histori status siswa
// PERBAIKAN DI SINI JUGA: ganti waktu_penjemputan menjadi waktu_update_status
$stmt_history = $conn->prepare("SELECT status, nama_penjemput, catatan, waktu_update_status 
                                FROM status_penjemputan 
                                WHERE siswa_id = ? 
                                ORDER BY waktu_update_status DESC");
if ($stmt_history) {
    $stmt_history->bind_param("i", $siswa_id);
    $stmt_history->execute();
    $result_history = $stmt_history->get_result();
    while ($row_history = $result_history->fetch_assoc()) {
        $status_history[] = $row_history;
    }
    $stmt_history->close();
} else {
    // Jika prepare gagal, tambahkan pesan error
     $message .= "<p class='text-red-500'>Error memuat histori status: " . htmlspecialchars($conn->error) . "</p>";
}


// Menampilkan flash message jika ada dari redirect sebelumnya
if (isset($_SESSION['flash_message_edit_status'])) {
    $flash = $_SESSION['flash_message_edit_status'];
    $message_class = $flash['type'] === 'success' ? 'text-green-700 bg-green-100 border-green-300' : 'text-red-700 bg-red-100 border-red-300';
    $message = "<div class='p-4 mb-4 text-sm border rounded-md {$message_class}'>" . htmlspecialchars($flash['text']) . "</div>" . $message; // Gabung dengan message yang mungkin ada
    unset($_SESSION['flash_message_edit_status']);
}

// Fungsi format status (bisa di-include dari file lain atau didefinisikan di sini jika belum)
if (!function_exists('formatStatusPenjemputan')) {
    function formatStatusPenjemputan($status_db) {
        $status_display = [
            'masuk_kelas' => 'Masuk Kelas',
            'tidak_masuk' => 'Tidak Masuk',
            'proses_belajar' => 'Proses Belajar',
            'perjalanan_jemput' => 'Perjalanan Jemput',
            'lima_menit_sampai' => '5 Menit Sampai',
            'sudah_sampai_sekolah' => 'Sudah Sampai Sekolah',
            'sudah_dijemput' => 'Sudah Dijemput',
            'tidak_hadir_info_jemput' => 'Tidak Hadir (Info Jemput)'
        ];
        return $status_display[$status_db] ?? ucfirst(str_replace('_', ' ', $status_db));
    }
}

$assets_path_prefix = '../';
include '../includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-gray-800">
            <i class="fas fa-edit mr-2"></i>Edit Status Penjemputan Siswa
        </h2>
        <a href="<?php echo htmlspecialchars($ref_page); ?>" class="text-indigo-600 hover:text-indigo-800">
            « Kembali ke Daftar Status
        </a>
    </div>

    <?php if (!empty($message)) echo $message; ?>

    <?php if ($siswa_data): ?>
    <div class="bg-white shadow-md rounded-lg p-6 mb-8">
        <h3 class="text-xl font-semibold mb-1 text-gray-700"><?php echo htmlspecialchars($siswa_data['nama_siswa']); ?></h3>
        <p class="text-sm text-gray-500 mb-4">Kelas: <?php echo htmlspecialchars($siswa_data['nama_kelas']); ?></p>

        <form method="POST" action="edit_status_siswa.php?siswa_id=<?php echo $siswa_id; ?><?php echo $ref_kelas_id ? '&ref_kelas_id='.$ref_kelas_id : ''; ?>" class="space-y-6">
            <div>
                <label for="status" class="block text-sm font-medium text-gray-700">Status Baru</label>
                <select name="status" id="status" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required>
                    <option value="">Pilih Status</option>
                    <?php
                    // Sesuaikan dengan ENUM di tabel status_penjemputan
                    $possible_statuses = ['masuk_kelas', 'proses_belajar', 'perjalanan_jemput', 'lima_menit_sampai', 'sudah_sampai_sekolah', 'sudah_dijemput', 'tidak_hadir_info_jemput'];
                    $current_status_value = $current_status_data['status'] ?? '';
                    foreach ($possible_statuses as $stat_val) {
                        // selected jika status saat ini sama dengan opsi, tapi umumnya kita ingin user memilih status BARU
                        // Jadi 'selected' mungkin tidak diperlukan di sini, kecuali Anda ingin pre-fill dengan status lama.
                        // Untuk form update status, biasanya kita tidak pre-fill field status, tapi field lain seperti catatan.
                        echo "<option value='{$stat_val}'>" . formatStatusPenjemputan($stat_val) . "</option>";
                    }
                    ?>
                </select>
            </div>

            <div>
                <label for="nama_penjemput" class="block text-sm font-medium text-gray-700">Nama Penjemput (opsional)</label>
                <input type="text" name="nama_penjemput" id="nama_penjemput" value="<?php echo htmlspecialchars($current_status_data['nama_penjemput'] ?? ''); ?>" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" placeholder="Jika status 'Sudah Dijemput' atau relevan">
            </div>

            <div>
                <label for="catatan" class="block text-sm font-medium text-gray-700">Catatan (opsional)</label>
                <textarea name="catatan" id="catatan" rows="3" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" placeholder="Informasi tambahan..."><?php echo htmlspecialchars($current_status_data['catatan'] ?? ''); ?></textarea>
            </div>

            <button type="submit" name="update_status" class="inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                <i class="fas fa-save mr-2"></i> Simpan Status Baru
            </button>
        </form>
    </div>

    <div class="bg-white shadow-md rounded-lg p-6">
        <h3 class="text-xl font-semibold mb-4 text-gray-700">Histori Status Penjemputan</h3>
        <?php if (!empty($status_history)): ?>
            <ul class="divide-y divide-gray-200">
                <?php foreach ($status_history as $history_item): ?>
                    <li class="py-3">
                        <div class="flex justify-between items-center">
                            <p class="text-sm font-medium text-indigo-600"><?php echo formatStatusPenjemputan($history_item['status']); ?></p>
                            <p class="text-xs text-gray-500">
                                <?php echo (new DateTime($history_item['waktu_update_status']))->format('d M Y, H:i'); ?>
                            </p>
                        </div>
                        <?php if (!empty($history_item['nama_penjemput'])): ?>
                            <p class="text-sm text-gray-600">Penjemput: <?php echo htmlspecialchars($history_item['nama_penjemput']); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($history_item['catatan'])): ?>
                            <p class="text-sm text-gray-500 mt-1">Catatan: "<?php echo nl2br(htmlspecialchars($history_item['catatan'])); ?>"</p>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="text-gray-500">Belum ada histori status untuk siswa ini.</p>
        <?php endif; ?>
    </div>

    <?php else: ?>
        <p class="text-red-500">Data siswa tidak dapat dimuat.</p>
    <?php endif; ?>

</div>

<?php
include '../includes/footer.php';
$conn->close();
?>