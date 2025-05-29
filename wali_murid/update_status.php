<?php
// wali_murid/update_status.php
// Halaman ini akan menampilkan detail anak dan histori status penjemputannya.
// Asumsi: Aksi update status utama dilakukan dari index.php,
// file ini lebih untuk view detail atau jika ada cara update khusus di sini.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'wali_murid') {
    header('Location: ../login.php');
    exit;
}

include '../includes/db.php';
$assets_path_prefix = '../'; // Untuk header

$wali_murid_id = $_SESSION['user_id'];
$siswa_id_view = isset($_GET['siswa_id']) ? (int)$_GET['siswa_id'] : 0;
$message = '';
$anak_detail = null;
$histori_status = [];

// Fungsi format status, bisa di-include dari file helper jika ada
if (!function_exists('formatStatusDisplayWaliDetail')) { // Beri nama berbeda jika perlu
    function formatStatusDisplayWaliDetail($status_db) {
        $status_map = [
            'masuk_kelas' => 'Masuk Sekolah',
            'tidak_masuk' => 'Tidak Masuk Sekolah',
            'proses_belajar' => 'Proses Belajar',
            'perjalanan_jemput' => 'Dalam Perjalanan Jemput',
            'lima_menit_sampai' => 'Penjemput 5 Menit Lagi Sampai',
            'sudah_sampai_sekolah' => 'Penjemput Sudah Sampai di Sekolah',
            'sudah_dijemput' => 'Sudah Dijemput',
            'tidak_hadir_info_jemput' => 'Tidak Hadir (Info Jemput)'
        ];
        return $status_map[$status_db] ?? ucfirst(str_replace('_', ' ', $status_db ?? 'Belum ada info'));
    }
}


if ($siswa_id_view <= 0) {
    $_SESSION['flash_message_wali_dashboard'] = "<p class='text-red-500 p-3 bg-red-100 rounded'>ID Siswa tidak valid.</p>";
    header('Location: index.php');
    exit;
}

// Ambil data detail anak dan kelasnya
$stmt_anak = $conn->prepare("
    SELECT s.nama_siswa, k.nama_kelas 
    FROM siswa s 
    LEFT JOIN kelas k ON s.kelas_id = k.id 
    WHERE s.id = ? AND s.id_wali_murid = ?
");
if ($stmt_anak) {
    $stmt_anak->bind_param("ii", $siswa_id_view, $wali_murid_id);
    $stmt_anak->execute();
    $result_anak = $stmt_anak->get_result();
    if ($result_anak->num_rows > 0) {
        $anak_detail = $result_anak->fetch_assoc();
    } else {
        $_SESSION['flash_message_wali_dashboard'] = "<p class='text-red-500 p-3 bg-red-100 rounded'>Anak tidak ditemukan atau bukan milik Anda.</p>";
        header('Location: index.php');
        exit;
    }
    $stmt_anak->close();
} else {
    $message = "<p class='text-red-500 p-3 bg-red-100 rounded'>Error mengambil data anak: " . htmlspecialchars($conn->error) . "</p>";
}

// Ambil seluruh histori status penjemputan untuk anak ini
// PERBAIKAN UTAMA ADA DI SINI (jika baris 163 dari error sebelumnya merujuk ke query ini)
$stmt_histori = $conn->prepare("
    SELECT status, nama_penjemput, catatan, waktu_update_status 
    FROM status_penjemputan 
    WHERE siswa_id = ? 
    ORDER BY waktu_update_status DESC
");
if ($stmt_histori) {
    $stmt_histori->bind_param("i", $siswa_id_view);
    $stmt_histori->execute();
    $result_histori = $stmt_histori->get_result();
    if ($result_histori) {
        while ($row = $result_histori->fetch_assoc()) {
            $histori_status[] = $row;
        }
    } else {
        $message .= "<p class='text-red-500 p-3 bg-red-100 rounded'>Error mengambil histori status: " . htmlspecialchars($stmt_histori->error) . "</p>";
    }
    $stmt_histori->close();
} else {
    $message .= "<p class='text-red-500 p-3 bg-red-100 rounded'>Error mempersiapkan query histori: " . htmlspecialchars($conn->error) . "</p>";
}


// Menampilkan flash message jika ada dari redirect sebelumnya
if (isset($_SESSION['flash_message_detail_anak'])) { // Gunakan key session yang berbeda jika perlu
    $flash_msg_detail = $_SESSION['flash_message_detail_anak'];
    $message_class_detail = ($flash_msg_detail['type'] ?? 'info') === 'success' ? 'text-green-700 bg-green-100' : 'text-red-700 bg-red-100';
    $message = "<div class='p-4 mb-4 text-sm border rounded-md {$message_class_detail}'>" . htmlspecialchars($flash_msg_detail['text']) . "</div>" . $message;
    unset($_SESSION['flash_message_detail_anak']);
}

include '../includes/header.php'; // Include header setelah semua logika PHP
?>

<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-3xl font-bold text-gray-800">
            <i class="fas fa-history mr-2 text-indigo-600"></i>Histori Status Penjemputan
        </h2>
        <a href="index.php" class="text-indigo-600 hover:text-indigo-800 transition duration-150 ease-in-out">
            <i class="fas fa-arrow-left mr-1"></i> Kembali ke Dashboard
        </a>
    </div>

    <?php if (!empty($message)) : ?>
        <div class="mb-6"><?php echo $message; ?></div>
    <?php endif; ?>

    <?php if ($anak_detail) : ?>
        <div class="bg-white shadow-xl rounded-lg p-6 md:p-8 mb-8">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-4 pb-4 border-b border-gray-200">
                <div>
                    <h3 class="text-2xl font-semibold text-indigo-700"><?php echo htmlspecialchars($anak_detail['nama_siswa']); ?></h3>
                    <p class="text-md text-gray-600">Kelas: <?php echo htmlspecialchars($anak_detail['nama_kelas'] ?? 'N/A'); ?></p>
                </div>
                <!-- Tombol aksi lain bisa ditambahkan di sini jika perlu, misal link ke index.php untuk update -->
            </div>

            <?php if (!empty($histori_status)) : ?>
                <div class="space-y-6">
                    <?php foreach ($histori_status as $item) : ?>
                        <div class="p-4 border border-gray-200 rounded-lg hover:shadow-md transition-shadow bg-gray-50">
                            <div class="flex justify-between items-start mb-1">
                                <span class="px-3 py-1 text-sm font-semibold rounded-full
                                    <?php
                                    if ($item['status'] === 'sudah_dijemput') echo 'bg-green-100 text-green-700';
                                    elseif (in_array($item['status'], ['perjalanan_jemput', 'lima_menit_sampai', 'sudah_sampai_sekolah'])) echo 'bg-blue-100 text-blue-700';
                                    elseif (in_array($item['status'], ['tidak_masuk', 'tidak_hadir_info_jemput'])) echo 'bg-red-100 text-red-700';
                                    else echo 'bg-gray-100 text-gray-700';
                                    ?>">
                                    <?php echo formatStatusDisplayWaliDetail($item['status']); ?>
                                </span>
                                <span class="text-xs text-gray-500 whitespace-nowrap">
                                    <i class="far fa-clock mr-1"></i>
                                    <?php echo (new DateTime($item['waktu_update_status']))->format('d M Y, H:i:s'); ?>
                                </span>
                            </div>
                            <?php if (!empty($item['nama_penjemput'])) : ?>
                                <p class="text-sm text-gray-700 mt-1"><i class="fas fa-user-tag mr-2 text-gray-400"></i>Penjemput: <span class="font-medium"><?php echo htmlspecialchars($item['nama_penjemput']); ?></span></p>
                            <?php endif; ?>
                            <?php if (!empty($item['catatan'])) : ?>
                                <div class="mt-2 p-3 bg-white border border-gray-100 rounded text-sm text-gray-600">
                                    <p class="font-medium text-gray-700 mb-1">Catatan:</p>
                                    <blockquote class="italic pl-3 border-l-2 border-indigo-200">
                                        <?php echo nl2br(htmlspecialchars($item['catatan'])); ?>
                                    </blockquote>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <div class="text-center py-8">
                    <i class="fas fa-folder-open fa-3x text-gray-300 mb-3"></i>
                    <p class="text-gray-500">Belum ada histori status penjemputan untuk anak ini.</p>
                </div>
            <?php endif; ?>
        </div>
    <?php else : ?>
        <?php if (empty($message)) : // Tampilkan pesan ini hanya jika belum ada pesan error lain ?>
            <div class="text-center py-10">
                <i class="fas fa-exclamation-triangle fa-3x text-red-400 mb-4"></i>
                <p class="text-red-600">Data anak tidak dapat dimuat atau tidak ditemukan.</p>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php
include '../includes/footer.php';
$conn->close();
?>