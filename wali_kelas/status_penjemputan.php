<?php
// wali_kelas/status_penjemputan.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Cek otentikasi dan otorisasi
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'wali_kelas') {
    header('Location: ../login.php');
    exit;
}

require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

include '../includes/db.php';
$assets_path_prefix = '../';

$wali_kelas_user_id = $_SESSION['user_id'];
$kelas_id_wali = $_SESSION['kelas_id_wali'] ?? null;
$message_data_page = null;
$siswa_status_list = [];
$nama_kelas_display = "Kelas Tidak Diketahui";
$error_message_page = null;

// Fungsi format status
if (!function_exists('formatStatusDisplayUmum')) {
    function formatStatusDisplayUmum($status_db) {
        if ($status_db === null || $status_db === '') {
            return 'Belum ada info';
        }
        $status_map = [
            'masuk_kelas' => 'Masuk Kelas',
            'proses_belajar' => 'Proses Belajar',
            'perjalanan_jemput' => 'Wali Murid OTW Jemput',
            'lima_menit_sampai' => 'Penjemput ±5 Menit Lagi',
            'sudah_sampai_sekolah' => 'Penjemput Tiba',
            'sudah_dijemput' => 'Sudah Dijemput',
            'tidak_hadir_info_jemput' => 'Tidak Hadir (Info Wali Murid)'
        ];
        return $status_map[$status_db] ?? ucfirst(str_replace('_', ' ', $status_db));
    }
}

if (!$kelas_id_wali) {
    $error_message_page = "Anda tidak terhubung dengan kelas manapun. Silakan hubungi Administrator.";
} else {
    $stmt_nama_kelas = $conn->prepare("SELECT nama_kelas FROM kelas WHERE id = ?");
    if ($stmt_nama_kelas) {
        $stmt_nama_kelas->bind_param("i", $kelas_id_wali);
        $stmt_nama_kelas->execute();
        $result_nama_kelas = $stmt_nama_kelas->get_result();
        if ($row_nama_kelas = $result_nama_kelas->fetch_assoc()) {
            $nama_kelas_display = htmlspecialchars($row_nama_kelas['nama_kelas']);
        } else {
            $nama_kelas_display = "Kelas (ID: " . htmlspecialchars($kelas_id_wali) . ") Tdk Ditemukan";
            $error_message_page = "Data kelas tidak ditemukan.";
        }
        $stmt_nama_kelas->close();
    } else {
        $error_message_page = "Error mengambil nama kelas: " . htmlspecialchars($conn->error);
        error_log("MySQL Prepare Error (get nama kelas, status_penjemputan.php): " . $conn->error);
    }

    if (!$error_message_page) { 
        $query_siswa_status = "
            SELECT 
                s.id AS siswa_id, 
                s.nama_siswa,
                sp_latest.status AS status_penjemputan_terakhir,
                sp_latest.nama_penjemput AS penjemput_terakhir,
                sp_latest.waktu_update_status AS waktu_penjemputan_terakhir,
                COALESCE(sp_initial_note.catatan, sp_latest.catatan) AS catatan_display 
            FROM siswa s
            LEFT JOIN (
                SELECT sp1.siswa_id, sp1.status, sp1.nama_penjemput, sp1.waktu_update_status, sp1.catatan
                FROM status_penjemputan sp1
                INNER JOIN (
                    SELECT siswa_id, MAX(waktu_update_status) AS max_waktu_update 
                    FROM status_penjemputan 
                    WHERE DATE(waktu_update_status) = CURDATE() 
                    GROUP BY siswa_id
                ) sp2 ON sp1.siswa_id = sp2.siswa_id AND sp1.waktu_update_status = sp2.max_waktu_update
            ) sp_latest ON s.id = sp_latest.siswa_id
            LEFT JOIN (
                SELECT sp_note.siswa_id, sp_note.catatan
                FROM status_penjemputan sp_note
                INNER JOIN (
                    SELECT siswa_id, MIN(waktu_update_status) AS min_waktu_initial_jemput
                    FROM status_penjemputan
                    WHERE DATE(waktu_update_status) = CURDATE() AND status = 'perjalanan_jemput'
                    GROUP BY siswa_id
                ) sp_note_time ON sp_note.siswa_id = sp_note_time.siswa_id AND sp_note.waktu_update_status = sp_note_time.min_waktu_initial_jemput
                WHERE sp_note.status = 'perjalanan_jemput' AND DATE(sp_note.waktu_update_status) = CURDATE()
            ) sp_initial_note ON s.id = sp_initial_note.siswa_id
            WHERE s.kelas_id = ?
            ORDER BY s.nama_siswa ASC;
        ";
        
        $stmt_siswa_status_fetch = $conn->prepare($query_siswa_status);
        if ($stmt_siswa_status_fetch) {
            $stmt_siswa_status_fetch->bind_param("i", $kelas_id_wali);
            $stmt_siswa_status_fetch->execute();
            $result_siswa_status_fetch = $stmt_siswa_status_fetch->get_result();
            if ($result_siswa_status_fetch) {
                while ($row_siswa_status = $result_siswa_status_fetch->fetch_assoc()) {
                    $siswa_status_list[] = $row_siswa_status;
                }
            } else {
                $message_data_page = ['type' => 'error', 'text' => "Error mengambil data status siswa (exec): " . htmlspecialchars($stmt_siswa_status_fetch->error)];
            }
            $stmt_siswa_status_fetch->close();
        } else {
            $message_data_page = ['type' => 'error', 'text' => "Error DB (prepare fetch status): " . htmlspecialchars($conn->error)];
            error_log("MySQL Prepare Error (fetch status, status_penjemputan.php): " . $conn->error);
        }
    }
}

if (isset($_GET['download_excel_status_jemput']) && $kelas_id_wali && empty($error_message_page)) {
    // ... (Logika Excel tetap sama) ...
    if (!empty($siswa_status_list)) {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Status Jemput ' . preg_replace('/[^a-zA-Z0-9_]/', '', $nama_kelas_display));

        $headers = ['No', 'Nama Siswa', 'Status Penjemputan Terakhir', 'Nama Penjemput', 'Waktu Update Terakhir', 'Catatan Wali Murid'];
        $sheet->fromArray($headers, NULL, 'A1');

        $headerStyleArray = ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E40AF']] ];
        $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->applyFromArray($headerStyleArray);
        foreach (range('A', $sheet->getHighestColumn()) as $columnID) { $sheet->getColumnDimension($columnID)->setAutoSize(true); }

        $rowNum = 2; $no_excel = 1;
        foreach ($siswa_status_list as $siswa_excel) {
            $catatan_untuk_excel = !empty($siswa_excel['catatan_display']) ? htmlspecialchars($siswa_excel['catatan_display']) : '';
            $rowDataArray = [
                $no_excel++,
                htmlspecialchars($siswa_excel['nama_siswa']),
                formatStatusDisplayUmum($siswa_excel['status_penjemputan_terakhir']),
                htmlspecialchars($siswa_excel['penjemput_terakhir'] ?? '-'),
                !empty($siswa_excel['waktu_penjemputan_terakhir']) ? (new DateTime($siswa_excel['waktu_penjemputan_terakhir']))->format('d M Y, H:i') : '-',
                $catatan_untuk_excel
            ];
            $sheet->fromArray($rowDataArray, NULL, 'A' . $rowNum++);
        }

        $filename_kelas_part = str_replace(' ', '_', preg_replace('/[^a-zA-Z0-9\s]/', '', $nama_kelas_display));
        $filename = 'status_penjemputan_' . $filename_kelas_part . '_' . date('Ymd') . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        ob_end_clean();
        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    } else {
        $_SESSION['flash_message_redirect_status_jemput'] = ['type' => 'warning', 'text' => 'Tidak ada data status penjemputan untuk diunduh.'];
        header('Location: status_penjemputan.php');
        exit;
    }
}


if (isset($_SESSION['flash_message_redirect_status_jemput'])) {
    $message_data_page = $_SESSION['flash_message_redirect_status_jemput'];
    unset($_SESSION['flash_message_redirect_status_jemput']);
}

include '../includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="flex flex-col sm:flex-row justify-between items-start mb-6">
        <div class="text-center sm:text-left mb-4 sm:mb-0">
            <h1 class="text-3xl font-bold text-gray-800">
                <i class="fas fa-route mr-2 text-teal-500"></i>Status Penjemputan Siswa
            </h1>
            <p class="text-md text-gray-600 mt-1">Kelas: <?php echo $nama_kelas_display; ?></p>
        </div>
        <div class="w-full sm:w-auto flex flex-col sm:flex-row items-center sm:items-start space-y-2 sm:space-y-0 sm:space-x-3">
            <!-- PERUBAHAN 1: Elemen HTML untuk Jam dan Tanggal -->
            <div id="realTimeClock" class="text-sm text-gray-700 bg-gray-100 px-3 py-2 rounded-md shadow-sm text-center sm:text-right">
                Memuat jam...
            </div>
            <?php if ($kelas_id_wali && empty($error_message_page) && !empty($siswa_status_list)): ?>
                <a href="status_penjemputan.php?download_excel_status_jemput=1" 
                   class="w-full sm:w-auto inline-flex items-center justify-center px-4 py-2 bg-green-600 text-white text-sm font-semibold rounded-md shadow-sm hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 transition ease-in-out duration-150">
                    <i class="fas fa-file-excel mr-2"></i> Download Excel
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (isset($error_message_page)): ?>
        <div class="mb-4 p-4 rounded-md text-sm shadow bg-red-100 border-l-4 border-red-500 text-red-700">
            <div class="flex"><div class="flex-shrink-0"><i class="fas fa-times-circle fa-lg"></i></div><div class="ml-3"><p class="font-medium"><?php echo htmlspecialchars($error_message_page); ?></p></div></div>
        </div>
    <?php elseif ($message_data_page): ?>
         <div class="mb-4 p-4 rounded-md text-sm shadow <?php 
            if ($message_data_page['type'] === 'success') echo 'bg-green-100 border-l-4 border-green-500 text-green-700';
            elseif ($message_data_page['type'] === 'error') echo 'bg-red-100 border-l-4 border-red-500 text-red-700';
            elseif ($message_data_page['type'] === 'warning') echo 'bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700';
            else echo 'bg-blue-100 border-l-4 border-blue-500 text-blue-700'; 
        ?>">
             <div class="flex">
                <div class="flex-shrink-0">
                    <?php 
                    if ($message_data_page['type'] === 'success') echo '<i class="fas fa-check-circle fa-lg"></i>';
                    elseif ($message_data_page['type'] === 'error') echo '<i class="fas fa-times-circle fa-lg"></i>';
                    elseif ($message_data_page['type'] === 'warning') echo '<i class="fas fa-exclamation-triangle fa-lg"></i>';
                    else echo '<i class="fas fa-info-circle fa-lg"></i>'; 
                    ?>
                </div>
                <div class="ml-3"><p class="font-medium"><?php echo htmlspecialchars($message_data_page['text']); ?></p></div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($kelas_id_wali && empty($error_message_page)): ?>
        <?php if (!empty($siswa_status_list)): ?>
            <div class="overflow-x-auto bg-white shadow-md rounded-lg">
                <table class="w-full border-collapse border border-gray-300">
                    <thead class="bg-gray-200">
                        <tr class="text-left">
                            <th class="border border-gray-300 p-2 sm:p-3 text-xs font-medium text-gray-500 uppercase tracking-wider">No</th>
                            <th class="border border-gray-300 p-2 sm:p-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Nama Siswa</th>
                            <th class="border border-gray-300 p-2 sm:p-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Status Terakhir</th>
                            <th class="border border-gray-300 p-2 sm:p-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Penjemput</th>
                            <th class="border border-gray-300 p-2 sm:p-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Waktu Update</th>
                            <th class="border border-gray-300 p-2 sm:p-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Catatan Wali Murid</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $no_tabel = 1; foreach ($siswa_status_list as $siswa): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="border border-gray-300 p-2 sm:p-3 text-sm text-gray-600 text-center"><?php echo $no_tabel++; ?></td>
                                <td class="border border-gray-300 p-2 sm:p-3 text-sm font-medium text-gray-800"><?php echo htmlspecialchars($siswa['nama_siswa']); ?></td>
                                <td class="border border-gray-300 p-2 sm:p-3 text-sm">
                                    <span class="px-2.5 py-1 inline-flex text-xs leading-tight font-semibold rounded-full whitespace-nowrap 
                                        <?php
                                        $status_jemput = $siswa['status_penjemputan_terakhir'];
                                        if ($status_jemput === 'sudah_dijemput') echo 'bg-green-100 text-green-700';
                                        elseif (in_array($status_jemput, ['perjalanan_jemput', 'lima_menit_sampai', 'sudah_sampai_sekolah'])) echo 'bg-blue-100 text-blue-700';
                                        elseif ($status_jemput === 'tidak_hadir_info_jemput') echo 'bg-red-100 text-red-700';
                                        elseif (in_array($status_jemput, ['masuk_kelas', 'proses_belajar'])) echo 'bg-yellow-100 text-yellow-700';
                                        else echo 'bg-gray-100 text-gray-700';
                                        ?>">
                                        <?php echo formatStatusDisplayUmum($status_jemput); ?>
                                    </span>
                                </td>
                                <td class="border border-gray-300 p-2 sm:p-3 text-sm text-gray-600"><?php echo htmlspecialchars($siswa['penjemput_terakhir'] ?? '-'); ?></td>
                                <td class="border border-gray-300 p-2 sm:p-3 text-sm text-gray-500 whitespace-nowrap">
                                    <?php echo !empty($siswa['waktu_penjemputan_terakhir']) ? (new DateTime($siswa['waktu_penjemputan_terakhir']))->format('H:i, d M Y') : '-'; ?>
                                </td>
                                <td class="border border-gray-300 p-2 sm:p-3 text-sm text-gray-500 max-w-xs break-words">
                                    <?php echo !empty($siswa['catatan_display']) ? nl2br(htmlspecialchars($siswa['catatan_display'])) : '-'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="text-center py-12">
                <i class="fas fa-info-circle fa-3x text-gray-400 mb-4"></i>
                <p class="text-lg text-gray-500">Belum ada data status penjemputan untuk siswa di kelas ini hari ini.</p>
                <p class="text-sm text-gray-400 mt-1">Status akan muncul di sini setelah wali murid melakukan update.</p>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if (empty($_GET['download_excel_status_jemput']) && empty($error_message_page)): ?>
<script>
    // --- PERUBAHAN 2: Script JavaScript untuk Jam Real-time ---
    function updateRealTimeClock() {
        const clockElement = document.getElementById('realTimeClock');
        if (clockElement) {
            const now = new Date();
            const days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
            const months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

            const dayName = days[now.getDay()];
            const day = now.getDate();
            const monthName = months[now.getMonth()];
            const year = now.getFullYear();
            
            let hours = now.getHours();
            let minutes = now.getMinutes();
            let seconds = now.getSeconds();

            hours = hours < 10 ? '0' + hours : hours;
            minutes = minutes < 10 ? '0' + minutes : minutes;
            seconds = seconds < 10 ? '0' + seconds : seconds;

            // Format: Hari, DD Bulan YYYY HH:MM:SS
            clockElement.innerHTML = `${dayName}, ${day} ${monthName} ${year}<br><strong class="text-lg">${hours}:${minutes}:${seconds}</strong>`;
        }
    }

    // Panggil updateRealTimeClock setiap detik
    setInterval(updateRealTimeClock, 1000);
    // Panggil sekali saat load untuk tampilan awal
    updateRealTimeClock();


    // Script auto-reload
    setTimeout(function() {
        // Cek apakah ada parameter 'download_excel_status_jemput' di URL
        // agar tidak reload saat proses download
        const urlParams = new URLSearchParams(window.location.search);
        if (!urlParams.has('download_excel_status_jemput')) {
            window.location.reload(true); 
        }
    }, 10000); 
</script>
<?php endif; ?>

<?php
include '../includes/footer.php';
if(isset($conn) && $conn) $conn->close();
?>