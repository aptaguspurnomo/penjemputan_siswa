<?php
// 1. Mulai session dan include koneksi database
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include '../includes/db.php'; // Koneksi DB dibutuhkan oleh semua logika
require '../vendor/autoload.php'; // Untuk PhpSpreadsheet

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill; // Pastikan ini ada

// 2. Cek otentikasi dan otorisasi (sebelum output apapun)
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php'); // Redirect jika tidak diizinkan
    exit;
}

// 3. Inisialisasi variabel
$message = '';
$selected_kelas_id = isset($_GET['kelas_id']) && $_GET['kelas_id'] !== '' ? (int)$_GET['kelas_id'] : null;

// 4. Logika Hapus Siswa (Pindahkan ke atas sebelum include header)
if (isset($_GET['hapus_siswa']) && ctype_digit((string)$_GET['hapus_siswa'])) {
    $id_siswa_hapus = (int)$_GET['hapus_siswa'];
    $redirect_url = 'status_siswa.php'; // Base redirect URL
    if ($selected_kelas_id) {
        $redirect_url .= '?kelas_id=' . $selected_kelas_id; // Pertahankan filter kelas
    }

    $stmt_get_nama = $conn->prepare("SELECT nama_siswa FROM siswa WHERE id = ?");
    $nama_siswa_deleted = "Siswa";
    if ($stmt_get_nama) {
        $stmt_get_nama->bind_param("i", $id_siswa_hapus);
        $stmt_get_nama->execute();
        $result_nama = $stmt_get_nama->get_result();
        if ($result_nama->num_rows > 0) {
            $nama_siswa_deleted = $result_nama->fetch_assoc()['nama_siswa'];
        }
        $stmt_get_nama->close();
    }

    $conn->begin_transaction();
    try {
        // Hapus status penjemputan terkait dulu
        $stmt_delete_status = $conn->prepare("DELETE FROM status_penjemputan WHERE siswa_id = ?");
        if (!$stmt_delete_status) throw new Exception("Error prepare (delete status penjemputan): " . $conn->error);
        $stmt_delete_status->bind_param("i", $id_siswa_hapus);
        if (!$stmt_delete_status->execute()) throw new Exception("Gagal hapus status penjemputan terkait: " . $stmt_delete_status->error);
        $stmt_delete_status->close();

        // Hapus status kehadiran terkait (jika ada dan ingin dihapus juga)
        $stmt_delete_kehadiran = $conn->prepare("DELETE FROM status_kehadiran WHERE siswa_id = ?");
        if (!$stmt_delete_kehadiran) throw new Exception("Error prepare (delete status kehadiran): " . $conn->error);
        $stmt_delete_kehadiran->bind_param("i", $id_siswa_hapus);
        if (!$stmt_delete_kehadiran->execute()) throw new Exception("Gagal hapus status kehadiran terkait: " . $stmt_delete_kehadiran->error);
        $stmt_delete_kehadiran->close();

        // Hapus siswa
        $stmt_delete_siswa = $conn->prepare("DELETE FROM siswa WHERE id = ?");
        if (!$stmt_delete_siswa) throw new Exception("Error prepare (delete siswa): " . $conn->error);
        $stmt_delete_siswa->bind_param("i", $id_siswa_hapus);
        if (!$stmt_delete_siswa->execute()) throw new Exception("Gagal hapus siswa: " . $stmt_delete_siswa->error);
        
        if ($stmt_delete_siswa->affected_rows > 0) {
            $_SESSION['flash_message_status_siswa'] = "<p class='text-green-500 p-3 bg-green-100 border border-green-400 rounded'>Siswa '<strong>" . htmlspecialchars($nama_siswa_deleted) . "</strong>' dan riwayat statusnya berhasil dihapus.</p>";
        } else {
            $_SESSION['flash_message_status_siswa'] = "<p class='text-yellow-500 p-3 bg-yellow-100 border border-yellow-400 rounded'>Siswa tidak ditemukan atau sudah dihapus.</p>";
        }
        $stmt_delete_siswa->close();
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        if ($conn->errno == 1451 && strpos(strtolower($e->getMessage()), 'foreign key constraint fails') !== false) {
             $_SESSION['flash_message_status_siswa'] = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Error: Siswa tidak dapat dihapus karena masih direferensikan oleh data lain.</p>";
        } else {
            $_SESSION['flash_message_status_siswa'] = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Error saat menghapus: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
    header('Location: ' . $redirect_url);
    exit;
}


// 5. Logika untuk mendapatkan data tabel dan Excel
$data_for_table_and_excel = [];
$sql_where_clause = "";
$params_query = [];
$types_query = "";

if ($selected_kelas_id !== null) {
    $sql_where_clause = "WHERE s.kelas_id = ?";
    $params_query[] = $selected_kelas_id;
    $types_query .= "i";
}

// Query untuk mendapatkan status penjemputan terakhir
$query_status_data = "
    SELECT 
        s.id AS siswa_id, 
        s.nama_siswa, 
        k.nama_kelas,
        sp_latest.status AS status_terakhir,
        sp_latest.nama_penjemput,
        sp_latest.waktu_update_status AS waktu_status_terakhir 
    FROM siswa s
    LEFT JOIN kelas k ON s.kelas_id = k.id
    LEFT JOIN (
        SELECT 
            sp1.siswa_id, 
            sp1.status, 
            sp1.nama_penjemput, 
            sp1.waktu_update_status
        FROM status_penjemputan sp1
        INNER JOIN (
            SELECT siswa_id, MAX(waktu_update_status) AS max_waktu_update
            FROM status_penjemputan
            GROUP BY siswa_id
        ) sp2 ON sp1.siswa_id = sp2.siswa_id AND sp1.waktu_update_status = sp2.max_waktu_update
    ) sp_latest ON s.id = sp_latest.siswa_id
    $sql_where_clause
    ORDER BY k.nama_kelas, s.nama_siswa;
";

$stmt_status_data = $conn->prepare($query_status_data);
if ($stmt_status_data) {
    if (!empty($params_query)) {
        $stmt_status_data->bind_param($types_query, ...$params_query);
    }
    $stmt_status_data->execute();
    $result_status_data = $stmt_status_data->get_result();
    if($result_status_data){
        while ($row_data = $result_status_data->fetch_assoc()) {
            $data_for_table_and_excel[] = $row_data;
        }
    } else {
      // Handle jika $result_status_data adalah false (error eksekusi)
      $_SESSION['flash_message_status_siswa'] = "<p class='text-red-500'>Error mengambil data: " . htmlspecialchars($stmt_status_data->error) . "</p>";
    }
    $stmt_status_data->close();
} else {
    $_SESSION['flash_message_status_siswa'] = "<p class='text-red-500'>Error preparing statement untuk data: " . htmlspecialchars($conn->error) . "</p>";
}


if (isset($_GET['download_excel'])) {
    if (!empty($data_for_table_and_excel)) {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Status Siswa');
        $headers = ['ID Siswa', 'Nama Siswa', 'Kelas', 'Status Terakhir', 'Nama Penjemput', 'Waktu Status'];
        $sheet->fromArray($headers, NULL, 'A1');
        
        $headerStyleArray = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4F46E5']]
        ];
        $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->applyFromArray($headerStyleArray);
        
        foreach (range('A', $sheet->getHighestColumn()) as $columnID) {
            $sheet->getColumnDimension($columnID)->setAutoSize(true);
        }
        
        $rowNum = 2;
        foreach ($data_for_table_and_excel as $row_excel_item) {
            $rowDataArray = [
                $row_excel_item['siswa_id'],
                $row_excel_item['nama_siswa'],
                $row_excel_item['nama_kelas'] ?? 'N/A',
                !empty($row_excel_item['status_terakhir']) ? formatStatusPenjemputan($row_excel_item['status_terakhir']) : 'Belum ada status',
                $row_excel_item['nama_penjemput'] ?? '-',
                !empty($row_excel_item['waktu_status_terakhir']) ? (new DateTime($row_excel_item['waktu_status_terakhir']))->format('d M Y, H:i') : '-'
            ];
            $sheet->fromArray($rowDataArray, NULL, 'A' . $rowNum);
            $rowNum++;
        }
        
        $filename = 'status_siswa_';
        $kelas_options_for_filename = []; // Ambil ulang atau pastikan scope-nya benar
        $q_kelas_fn = "SELECT id, nama_kelas FROM kelas";
        $r_kelas_fn = $conn->query($q_kelas_fn);
        if($r_kelas_fn) { 
            while($row_kfn = $r_kelas_fn->fetch_assoc()){ 
                $kelas_options_for_filename[$row_kfn['id']] = $row_kfn['nama_kelas']; 
            } 
        }

        if ($selected_kelas_id && isset($kelas_options_for_filename[$selected_kelas_id])) {
            $filename .= str_replace(' ', '_', $kelas_options_for_filename[$selected_kelas_id]) . '_';
        }
        $filename .= date('Ymd_His') . '.xlsx';
        
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    } else {
        $_SESSION['flash_message_status_siswa'] = "<p class='text-yellow-500 p-3 bg-yellow-100 border border-yellow-400 rounded'>Tidak ada data untuk diunduh.</p>";
        $redirect_url_no_data_excel = 'status_siswa.php';
        if ($selected_kelas_id) { $redirect_url_no_data_excel .= '?kelas_id=' . $selected_kelas_id; }
        header('Location: ' . $redirect_url_no_data_excel);
        exit;
    }
}


// Menampilkan flash message
if (isset($_SESSION['flash_message_status_siswa'])) {
    $message = $_SESSION['flash_message_status_siswa'];
    unset($_SESSION['flash_message_status_siswa']);
}

// Ambil daftar kelas untuk dropdown filter
$kelas_options_for_dropdown = [];
$query_kelas_filter_dd = "SELECT id, nama_kelas FROM kelas ORDER BY nama_kelas";
$result_kelas_filter_dd = $conn->query($query_kelas_filter_dd);
if ($result_kelas_filter_dd) {
    while ($row_kelas_dd = $result_kelas_filter_dd->fetch_assoc()) {
        $kelas_options_for_dropdown[] = $row_kelas_dd;
    }
}

// 6. Sekarang baru include header.php
$assets_path_prefix = '../';
include '../includes/header.php';

// Fungsi format status
function formatStatusPenjemputan($status_db) {
    $status_display = [
        'masuk_kelas' => 'Masuk Kelas', // Perbaikan dari 'masuk' jika itu yang dimaksud
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
?>

<div class="container mx-auto px-4 py-8">
    <div class="flex flex-col md:flex-row justify-between items-center mb-6 gap-4">
        <h2 class="text-2xl font-bold text-gray-800"><i class="fas fa-tasks mr-2"></i> Status Penjemputan Siswa</h2>
        <div class="flex flex-wrap items-center gap-2">
            <form method="GET" action="status_siswa.php" class="flex items-center space-x-2">
                <label for="kelas_id_filter" class="text-sm font-medium text-gray-700 whitespace-nowrap">Filter Kelas:</label>
                <select name="kelas_id" id="kelas_id_filter" class="p-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" onchange="this.form.submit()">
                    <option value="">Semua Kelas</option>
                    <?php foreach ($kelas_options_for_dropdown as $kelas_dd): ?>
                        <option value="<?php echo $kelas_dd['id']; ?>" <?php echo ($selected_kelas_id == $kelas_dd['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($kelas_dd['nama_kelas']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <noscript><button type="submit" class="p-2 bg-gray-200 rounded text-sm">Filter</button></noscript>
            </form>
            <a href="status_siswa.php?download_excel=1<?php echo $selected_kelas_id ? '&kelas_id='.$selected_kelas_id : ''; ?>" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                <i class="fas fa-file-excel mr-2"></i> Download Excel
            </a>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div class="mb-4"><?php echo $message; ?></div>
    <?php endif; ?>

    <div class="bg-white shadow-md rounded-lg overflow-x-auto">
        <table class="w-full min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nama Siswa</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kelas</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status Terakhir</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Penjemput</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Waktu Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Aksi</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php
                if (!empty($data_for_table_and_excel)) {
                    foreach ($data_for_table_and_excel as $row) {
                        echo "<tr>";
                        echo "<td class='px-6 py-4 whitespace-nowrap text-sm text-gray-500'>" . htmlspecialchars($row['siswa_id']) . "</td>";
                        echo "<td class='px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900'>" . htmlspecialchars($row['nama_siswa']) . "</td>";
                        echo "<td class='px-6 py-4 whitespace-nowrap text-sm text-gray-500'>" . htmlspecialchars($row['nama_kelas'] ?? 'N/A') . "</td>";
                        echo "<td class='px-6 py-4 whitespace-nowrap text-sm text-gray-500'>";
                        echo !empty($row['status_terakhir']) ? htmlspecialchars(formatStatusPenjemputan($row['status_terakhir'])) : "<span class='text-gray-400 italic'>Belum ada</span>";
                        echo "</td>";
                        echo "<td class='px-6 py-4 whitespace-nowrap text-sm text-gray-500'>" . htmlspecialchars($row['nama_penjemput'] ?? '-') . "</td>";
                        echo "<td class='px-6 py-4 whitespace-nowrap text-sm text-gray-500'>";
                        echo !empty($row['waktu_status_terakhir']) ? (new DateTime($row['waktu_status_terakhir']))->format('d M Y, H:i') : "-";
                        echo "</td>";
                        echo "<td class='px-6 py-4 whitespace-nowrap text-sm font-medium space-x-1 md:space-x-2'>";
                        echo "<a href='edit_siswa.php?id=" . htmlspecialchars($row['siswa_id']) . ($selected_kelas_id ? '&ref_kelas_id='.$selected_kelas_id.'&ref_page=status_siswa' : '&ref_page=status_siswa') . "' class='text-indigo-600 hover:text-indigo-900' title='Edit Data Siswa'><i class='fas fa-user-edit'></i><span class='hidden sm:inline ml-1'>Siswa</span></a>";
                        echo "<a href='edit_status_siswa.php?siswa_id=" . htmlspecialchars($row['siswa_id']) . ($selected_kelas_id ? '&ref_kelas_id='.$selected_kelas_id : '') . "' class='text-sky-600 hover:text-sky-900' title='Edit Status Penjemputan'><i class='fas fa-clipboard-check'></i><span class='hidden sm:inline ml-1'>Status</span></a>";
                        echo "<a href='status_siswa.php?hapus_siswa=" . htmlspecialchars($row['siswa_id']) . ($selected_kelas_id ? '&kelas_id='.$selected_kelas_id : '') . "' class='text-red-600 hover:text-red-900' onclick='return confirm(\"Hapus siswa " . htmlspecialchars(addslashes($row['nama_siswa'])) . "? Ini juga akan menghapus riwayat status penjemputan dan kehadirannya.\")' title='Hapus Siswa'><i class='fas fa-trash'></i><span class='hidden sm:inline ml-1'>Hapus</span></a>";
                        echo "</td>";
                        echo "</tr>";
                    }
                } else {
                    $colspan = 7;
                    echo "<tr><td colspan='$colspan' class='px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center'>";
                    if (isset($_SESSION['flash_message_status_siswa']) && strpos($_SESSION['flash_message_status_siswa'], 'Error preparing statement') !== false) {
                        // Pesan sudah di-set sebelumnya jika ada error prepare
                    } elseif ($selected_kelas_id !== null) {
                        echo "Tidak ada data siswa di kelas yang dipilih.";
                    } else {
                        echo "Tidak ada data siswa yang ditemukan.";
                    }
                    echo "</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

<?php // ADD THIS SCRIPT SECTION FOR AUTO-RELOAD ?>
<?php if (empty($_GET['download_excel'])): // Only reload if not downloading excel ?>
<script>
    setTimeout(function() {
        // Check if the filter dropdown is currently focused to prevent reload during selection
        var kelasFilter = document.getElementById('kelas_id_filter');
        if (document.activeElement !== kelasFilter) {
            window.location.reload();
        } else {
            // If filter is focused, try again after a shorter delay
            // This is a simple way to avoid interrupting selection
            // A more robust solution might involve event listeners for blur/change
            console.log("Filter selected, delaying reload.");
            setTimeout(function() { window.location.reload(); }, 5000); // e.g., try again in 5s
        }
    }, 30000); // 30000 milliseconds = 30 seconds
</script>
<?php endif; ?>
<?php // END OF ADDED SCRIPT SECTION ?>

<?php
include '../includes/footer.php';
$conn->close();
?>