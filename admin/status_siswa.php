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
use PhpOffice\PhpSpreadsheet\Style\Fill;

// 2. Cek otentikasi dan otorisasi (sebelum output apapun)
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php'); // Redirect jika tidak diizinkan
    exit;
}

// 3. Inisialisasi variabel
$message = '';
$selected_kelas_id = isset($_GET['kelas_id']) && $_GET['kelas_id'] !== '' ? (int)$_GET['kelas_id'] : null;
$tanggal_hari_ini = date('Y-m-d'); // Untuk logika reset

// 4. Logika Hapus Siswa
if (isset($_GET['hapus_siswa']) && ctype_digit((string)$_GET['hapus_siswa'])) {
    // ... (Logika hapus siswa tetap sama) ...
    $id_siswa_hapus = (int)$_GET['hapus_siswa'];
    $redirect_url = 'status_siswa.php';
    if ($selected_kelas_id) {
        $redirect_url .= '?kelas_id=' . $selected_kelas_id;
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
        $stmt_delete_status = $conn->prepare("DELETE FROM status_penjemputan WHERE siswa_id = ?");
        if (!$stmt_delete_status) throw new Exception("Error prepare (delete status penjemputan): " . $conn->error);
        $stmt_delete_status->bind_param("i", $id_siswa_hapus);
        if (!$stmt_delete_status->execute()) throw new Exception("Gagal hapus status penjemputan: " . $stmt_delete_status->error);
        $stmt_delete_status->close();

        $stmt_delete_kehadiran = $conn->prepare("DELETE FROM status_kehadiran WHERE siswa_id = ?");
        if (!$stmt_delete_kehadiran) throw new Exception("Error prepare (delete status kehadiran): " . $conn->error);
        $stmt_delete_kehadiran->bind_param("i", $id_siswa_hapus);
        if (!$stmt_delete_kehadiran->execute()) throw new Exception("Gagal hapus status kehadiran: " . $stmt_delete_kehadiran->error);
        $stmt_delete_kehadiran->close();

        $stmt_delete_siswa = $conn->prepare("DELETE FROM siswa WHERE id = ?");
        if (!$stmt_delete_siswa) throw new Exception("Error prepare (delete siswa): " . $conn->error);
        $stmt_delete_siswa->bind_param("i", $id_siswa_hapus);
        if (!$stmt_delete_siswa->execute()) throw new Exception("Gagal hapus siswa: " . $stmt_delete_siswa->error);
        
        if ($stmt_delete_siswa->affected_rows > 0) {
            $_SESSION['flash_message_status_siswa'] = "<p class='text-green-500 p-3 bg-green-100 border border-green-400 rounded'>Siswa '<strong>" . htmlspecialchars($nama_siswa_deleted) . "</strong>' dan riwayatnya berhasil dihapus.</p>";
        } else {
            $_SESSION['flash_message_status_siswa'] = "<p class='text-yellow-500 p-3 bg-yellow-100 border border-yellow-400 rounded'>Siswa tidak ditemukan.</p>";
        }
        $stmt_delete_siswa->close();
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['flash_message_status_siswa'] = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    header('Location: ' . $redirect_url);
    exit;
}

// PENAMBAHAN: Logika Reset Total Status Penjemputan Hari Ini
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_total_penjemputan_hari_ini'])) {
    $conn->begin_transaction();
    try {
        $siswa_ids_to_reset = [];
        $sql_get_siswa_for_reset = "SELECT id FROM siswa";
        if ($selected_kelas_id !== null) {
            $sql_get_siswa_for_reset .= " WHERE kelas_id = ?";
        }
        $stmt_get_siswa = $conn->prepare($sql_get_siswa_for_reset);
        if ($selected_kelas_id !== null) {
            $stmt_get_siswa->bind_param("i", $selected_kelas_id);
        }
        $stmt_get_siswa->execute();
        $result_siswa_reset = $stmt_get_siswa->get_result();
        while ($row_s = $result_siswa_reset->fetch_assoc()) {
            $siswa_ids_to_reset[] = $row_s['id'];
        }
        $stmt_get_siswa->close();

        if (!empty($siswa_ids_to_reset)) {
            $placeholders = implode(',', array_fill(0, count($siswa_ids_to_reset), '?'));
            $types = str_repeat('i', count($siswa_ids_to_reset)) . 's';
            $params = array_merge($siswa_ids_to_reset, [$tanggal_hari_ini]);

            $stmt_reset_penjemputan = $conn->prepare(
                "DELETE FROM status_penjemputan 
                 WHERE siswa_id IN ($placeholders) AND DATE(waktu_update_status) = ?"
            );
            
            if (!$stmt_reset_penjemputan) {
                throw new Exception("Gagal prepare statement reset penjemputan: " . $conn->error);
            }

            $stmt_reset_penjemputan->bind_param($types, ...$params);
            if ($stmt_reset_penjemputan->execute()) {
                $affected_rows = $stmt_reset_penjemputan->affected_rows;
                $_SESSION['flash_message_status_siswa'] = "<p class='text-green-500 p-3 bg-green-100 border border-green-400 rounded'>Status penjemputan hari ini untuk " . htmlspecialchars($affected_rows) . " entri berhasil direset/dihapus.</p>";
            } else {
                throw new Exception("Gagal mereset status penjemputan: " . $stmt_reset_penjemputan->error);
            }
            $stmt_reset_penjemputan->close();
        } else {
            $_SESSION['flash_message_status_siswa'] = "<p class='text-yellow-500 p-3 bg-yellow-100 border border-yellow-400 rounded'>Tidak ada siswa yang ditemukan untuk direset status penjemputannya.</p>";
        }
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error reset total penjemputan: " . $e->getMessage());
        $_SESSION['flash_message_status_siswa'] = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Gagal mereset status penjemputan: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    $redirect_url_after_reset = 'status_siswa.php';
    if ($selected_kelas_id) {
        $redirect_url_after_reset .= '?kelas_id=' . $selected_kelas_id;
    }
    header('Location: ' . $redirect_url_after_reset);
    exit;
}


// 5. Logika untuk mendapatkan data tabel dan Excel
$data_for_table_and_excel = [];
$sql_where_clause = "";
$params_query = [];
$types_query_select = ""; // Diganti nama agar tidak konflik dengan $types di atas

if ($selected_kelas_id !== null) {
    $sql_where_clause = "WHERE s.kelas_id = ?";
    $params_query[] = $selected_kelas_id;
    $types_query_select .= "i";
}

$query_status_data = "
    SELECT 
        s.id AS siswa_id, 
        s.nama_siswa, 
        k.nama_kelas,

        sp_today_details.status AS status_hari_ini,
        sp_today_details.nama_penjemput AS penjemput_hari_ini,
        sp_today_details.waktu_update_status AS waktu_status_hari_ini,

        COALESCE(sp_initial_note_today.catatan, sp_today_details.catatan) AS catatan_display
    FROM siswa s
    LEFT JOIN kelas k ON s.kelas_id = k.id
    LEFT JOIN (
        SELECT 
            sp_today1.siswa_id, 
            sp_today1.status, 
            sp_today1.nama_penjemput, 
            sp_today1.waktu_update_status,
            sp_today1.catatan
        FROM status_penjemputan sp_today1
        INNER JOIN (
            SELECT siswa_id, MAX(waktu_update_status) AS max_waktu_update_today
            FROM status_penjemputan 
            WHERE DATE(waktu_update_status) = ? 
            GROUP BY siswa_id
        ) sp_today2 ON sp_today1.siswa_id = sp_today2.siswa_id AND sp_today1.waktu_update_status = sp_today2.max_waktu_update_today
    ) sp_today_details ON s.id = sp_today_details.siswa_id
    LEFT JOIN (
        SELECT 
            sp_note.siswa_id, 
            sp_note.catatan
        FROM status_penjemputan sp_note
        INNER JOIN (
            SELECT siswa_id, MIN(waktu_update_status) AS min_waktu_initial_jemput
            FROM status_penjemputan
            WHERE DATE(waktu_update_status) = ? AND status = 'perjalanan_jemput'
            GROUP BY siswa_id
        ) sp_note_time ON sp_note.siswa_id = sp_note_time.siswa_id AND sp_note.waktu_update_status = sp_note_time.min_waktu_initial_jemput
        WHERE sp_note.status = 'perjalanan_jemput' AND DATE(sp_note.waktu_update_status) = ?
    ) sp_initial_note_today ON s.id = sp_initial_note_today.siswa_id
    $sql_where_clause
    ORDER BY k.nama_kelas, s.nama_siswa;
";

$stmt_status_data = $conn->prepare($query_status_data);
if ($stmt_status_data) {
    // Penyesuaian bind_param untuk query utama
    $all_params = [$tanggal_hari_ini, $tanggal_hari_ini, $tanggal_hari_ini]; // Untuk CURDATE() di subquery
    $all_types = "sss";
    if (!empty($params_query)) {
        $all_params = array_merge($all_params, $params_query);
        $all_types .= $types_query_select;
    }
    $stmt_status_data->bind_param($all_types, ...$all_params);
    
    $stmt_status_data->execute();
    $result_status_data = $stmt_status_data->get_result();
    if($result_status_data){
        while ($row_data = $result_status_data->fetch_assoc()) {
            $data_for_table_and_excel[] = $row_data;
        }
    } else {
      $_SESSION['flash_message_status_siswa'] = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Error mengambil data: " . htmlspecialchars($stmt_status_data->error) . "</p>";
    }
    $stmt_status_data->close();
} else {
    $_SESSION['flash_message_status_siswa'] = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Error preparing statement untuk data: " . htmlspecialchars($conn->error) . "</p>";
}


// ... (Logika Download Excel tetap sama) ...
if (isset($_GET['download_excel'])) {
    if (!empty($data_for_table_and_excel)) {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Status Siswa');
        $headers = ['ID Siswa', 'Nama Siswa', 'Kelas', 'Status Terakhir (Hari Ini)', 'Penjemput (Hari Ini)', 'Waktu Status (Hari Ini)', 'Catatan Wali Murid (Hari Ini)'];
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
            $status_excel = $row_excel_item['status_hari_ini'];
            $penjemput_excel = $row_excel_item['penjemput_hari_ini'];
            $waktu_excel = $row_excel_item['waktu_status_hari_ini'];
            $catatan_excel = !empty($row_excel_item['catatan_display']) ? $row_excel_item['catatan_display'] : '-';

            $rowDataArray = [
                $row_excel_item['siswa_id'],
                $row_excel_item['nama_siswa'],
                $row_excel_item['nama_kelas'] ?? 'N/A',
                formatStatusPenjemputan($status_excel),
                $penjemput_excel ?? '-',
                !empty($waktu_excel) ? (new DateTime($waktu_excel))->format('d M Y, H:i') : '-',
                $catatan_excel
            ];
            $sheet->fromArray($rowDataArray, NULL, 'A' . $rowNum);
            $rowNum++;
        }
        
        $filename = 'status_siswa_';
        $kelas_options_for_filename = [];
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
        if (ob_get_length()) ob_end_clean();
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


if (isset($_SESSION['flash_message_status_siswa'])) {
    $message = $_SESSION['flash_message_status_siswa'];
    unset($_SESSION['flash_message_status_siswa']);
}

$kelas_options_for_dropdown = [];
$query_kelas_filter_dd = "SELECT id, nama_kelas FROM kelas ORDER BY nama_kelas";
$result_kelas_filter_dd = $conn->query($query_kelas_filter_dd);
if ($result_kelas_filter_dd) {
    while ($row_kelas_dd = $result_kelas_filter_dd->fetch_assoc()) {
        $kelas_options_for_dropdown[] = $row_kelas_dd;
    }
}

$assets_path_prefix = '../';
include '../includes/header.php';

function formatStatusPenjemputan($status_db) {
    if ($status_db === null || $status_db === '') {
        return 'Belum ada info';
    }
    $status_display = [
        'masuk_kelas' => 'Masuk Kelas',
        'tidak_masuk' => 'Tidak Masuk', 
        'proses_belajar' => 'Proses Belajar',
        'perjalanan_jemput' => 'Wali Murid OTW Jemput',
        'lima_menit_sampai' => 'Penjemput ±5 Menit Lagi',
        'sudah_sampai_sekolah' => 'Penjemput Tiba',
        'sudah_dijemput' => 'Sudah Dijemput',
        'tidak_hadir_info_jemput' => 'Tidak Hadir (Info Wali Murid)'
    ];
    return $status_display[$status_db] ?? ucfirst(str_replace('_', ' ', $status_db));
}
?>

<div class="container mx-auto px-4 py-8">
    <div class="flex flex-col md:flex-row justify-between items-center mb-6 gap-4">
        <h2 class="text-2xl font-bold text-gray-800"><i class="fas fa-tasks mr-2"></i> Status Penjemputan Siswa</h2>
        <div class="flex flex-wrap items-center gap-2">
            <form method="GET" action="status_siswa.php" id="filterForm" class="flex items-center space-x-2">
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
            
            <?php if ($selected_kelas_id !== null): ?>
            <a href="status_siswa.php" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md shadow-sm text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                <i class="fas fa-undo mr-2"></i> Reset Filter Kelas
            </a>
            <?php endif; ?>

            <?php // TOMBOL RESET TOTAL STATUS PENJEMPUTAN HARI INI ?>
            <form method="POST" action="status_siswa.php<?php echo $selected_kelas_id ? '?kelas_id='.$selected_kelas_id : ''; ?>" 
                  onsubmit="return confirm('PERHATIAN! Anda akan mereset semua status penjemputan siswa <?php echo $selected_kelas_id ? "di kelas ini" : "di semua kelas"; ?> untuk hari ini (menghapus entri status penjemputan hari ini). Lanjutkan?');"
                  class="inline-block">
                <button type="submit" name="reset_total_penjemputan_hari_ini"
                        class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                    <i class="fas fa-power-off mr-2"></i> Reset Status Jemput Hari Ini
                </button>
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
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status Terakhir (Hari Ini)</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Penjemput (Hari Ini)</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Waktu Status (Hari Ini)</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Catatan Wali Murid (Hari Ini)</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Aksi</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php
                if (!empty($data_for_table_and_excel)) {
                    foreach ($data_for_table_and_excel as $row) {
                        $status_to_show = $row['status_hari_ini'];
                        $penjemput_to_show = $row['penjemput_hari_ini'];
                        $waktu_to_show = $row['waktu_status_hari_ini'];
                        $catatan_to_show = $row['catatan_display'];

                        echo "<tr>";
                        echo "<td class='px-6 py-4 whitespace-nowrap text-sm text-gray-500'>" . htmlspecialchars($row['siswa_id']) . "</td>";
                        echo "<td class='px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900'>" . htmlspecialchars($row['nama_siswa']) . "</td>";
                        echo "<td class='px-6 py-4 whitespace-nowrap text-sm text-gray-500'>" . htmlspecialchars($row['nama_kelas'] ?? 'N/A') . "</td>";
                        
                        echo "<td class='px-6 py-4 whitespace-nowrap text-sm text-gray-500'>";
                        echo htmlspecialchars(formatStatusPenjemputan($status_to_show)); 
                        echo "</td>";
                        
                        echo "<td class='px-6 py-4 whitespace-nowrap text-sm text-gray-500'>" . htmlspecialchars($penjemput_to_show ?? '-') . "</td>";
                        
                        echo "<td class='px-6 py-4 whitespace-nowrap text-sm text-gray-500'>";
                        echo !empty($waktu_to_show) ? (new DateTime($waktu_to_show))->format('d M Y, H:i') : "-";
                        echo "</td>";
                        
                        echo "<td class='px-6 py-4 whitespace-normal text-sm text-gray-500 max-w-xs break-words'>"; 
                        echo !empty($catatan_to_show) ? nl2br(htmlspecialchars($catatan_to_show)) : '-';
                        echo "</td>";
                        
                        echo "<td class='px-6 py-4 whitespace-nowrap text-sm font-medium space-x-1 md:space-x-2'>";
                        echo "<a href='edit_siswa.php?id=" . htmlspecialchars($row['siswa_id']) . ($selected_kelas_id ? '&ref_kelas_id='.$selected_kelas_id.'&ref_page=status_siswa' : '&ref_page=status_siswa') . "' class='text-indigo-600 hover:text-indigo-900' title='Edit Data Siswa'><i class='fas fa-user-edit'></i><span class='hidden sm:inline ml-1'>Siswa</span></a>";
                        echo "<a href='edit_status_siswa.php?siswa_id=" . htmlspecialchars($row['siswa_id']) . ($selected_kelas_id ? '&ref_kelas_id='.$selected_kelas_id : '') . "' class='text-sky-600 hover:text-sky-900' title='Edit Status Penjemputan'><i class='fas fa-clipboard-check'></i><span class='hidden sm:inline ml-1'>Status</span></a>";
                        echo "<a href='status_siswa.php?hapus_siswa=" . htmlspecialchars($row['siswa_id']) . ($selected_kelas_id ? '&kelas_id='.$selected_kelas_id : '') . "' class='text-red-600 hover:text-red-900' onclick='return confirm(\"Hapus siswa " . htmlspecialchars(addslashes($row['nama_siswa'])) . "? Ini juga akan menghapus riwayat status penjemputan dan kehadirannya.\")' title='Hapus Siswa'><i class='fas fa-trash'></i><span class='hidden sm:inline ml-1'>Hapus</span></a>";
                        echo "</td>";
                        echo "</tr>";
                    }
                } else {
                    $colspan = 8; 
                    echo "<tr><td colspan='$colspan' class='px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center'>";
                    if (isset($_SESSION['flash_message_status_siswa']) && strpos($_SESSION['flash_message_status_siswa'], 'Error preparing statement') !== false) {
                        // Pesan error sudah di handle
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

<?php if (empty($_GET['download_excel'])): ?>
<script>
    var isSubmittingStatusSiswa = false; // Flag untuk form reset
    document.addEventListener('submit', function(event) {
        // Cek apakah form yang disubmit adalah form reset total
        if (event.target.querySelector('button[name="reset_total_penjemputan_hari_ini"]')) {
            isSubmittingStatusSiswa = true;
        }
    });

    setTimeout(function() {
        var kelasFilter = document.getElementById('kelas_id_filter');
        // Jangan reload jika sedang filter atau submit form reset
        if (document.activeElement !== kelasFilter && !isSubmittingStatusSiswa) {
            window.location.reload();
        } else {
            console.log("Filter selected or form submitting, delaying reload.");
            // Coba lagi setelah delay lebih lama jika filter masih aktif atau form disubmit
             setTimeout(function() { if (!isSubmittingStatusSiswa) window.location.reload(); }, 10000); 
        }
    }, 30000); 
</script>
<?php endif; ?>

<?php
include '../includes/footer.php';
if(isset($conn) && $conn) $conn->close();
?>