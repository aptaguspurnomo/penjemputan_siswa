<?php
// error_reporting(E_ALL); // Aktifkan untuk debugging jika perlu
// ini_set('display_errors', 1);

// 1. START SESSION (if not already started)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. INCLUDE DB & VENDOR AUTOLOAD
include '../includes/db.php';
require '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Exception as PhpSpreadsheetException;

// 3. ADMIN CHECK - Early exit if not admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    // Set a flash message for the login page or a generic access denied page
    $_SESSION['flash_message'] = "<p class='text-red-500 p-4'>Akses ditolak. Anda harus login sebagai admin.</p>";
    header('Location: ../login.php'); // Redirect to login or an access denied page
    exit;
}

// 4. ALL PHP LOGIC FOR FORM PROCESSING (TAMBAH, HAPUS, UPDATE, UPLOAD)
//    All header() calls must be within this section, before any HTML output.

$message = ''; // Initialize message for feedback within the page if not redirecting

// Logika Tambah Kelas
if (isset($_POST['tambah'])) {
    $nama_kelas_tambah = isset($_POST['nama_kelas']) ? trim($_POST['nama_kelas']) : '';

    if (empty($nama_kelas_tambah)) {
        $_SESSION['flash_message_form_error'] = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Nama kelas tidak boleh kosong.</p>";
        $_SESSION['form_data_kelas'] = $_POST; // Store for repopulation
    } else {
        $stmt_check = $conn->prepare("SELECT id FROM kelas WHERE nama_kelas = ?");
        if ($stmt_check) {
            $stmt_check->bind_param("s", $nama_kelas_tambah);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();
            if ($result_check->num_rows > 0) {
                $_SESSION['flash_message_form_error'] = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Nama kelas '<strong>" . htmlspecialchars($nama_kelas_tambah) . "</strong>' sudah ada.</p>";
                $_SESSION['form_data_kelas'] = $_POST;
            } else {
                $stmt_insert = $conn->prepare("INSERT INTO kelas (nama_kelas) VALUES (?)");
                if ($stmt_insert) {
                    $stmt_insert->bind_param("s", $nama_kelas_tambah);
                    if ($stmt_insert->execute()) {
                        $_SESSION['flash_message'] = "<p class='text-green-500 p-3 bg-green-100 border border-green-400 rounded'>Kelas '<strong>" . htmlspecialchars($nama_kelas_tambah) . "</strong>' berhasil ditambahkan.</p>";
                    } else {
                        $_SESSION['flash_message'] = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Gagal menambah kelas: " . htmlspecialchars($stmt_insert->error) . "</p>";
                    }
                    $stmt_insert->close();
                } else {
                     $_SESSION['flash_message'] = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Error (prepare insert): " . htmlspecialchars($conn->error) . "</p>";
                }
            }
            $stmt_check->close();
        } else {
            $_SESSION['flash_message'] = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Error (prepare check): " . htmlspecialchars($conn->error) . "</p>";
        }
    }
    header('Location: manage_kelas.php'); // Redirect after processing
    exit;
}

// Logika Hapus Kelas
if (isset($_GET['hapus']) && ctype_digit((string)$_GET['hapus'])) {
    $id_hapus = (int)$_GET['hapus'];
    $stmt_delete = $conn->prepare("DELETE FROM kelas WHERE id = ?");
    if ($stmt_delete) {
        $stmt_delete->bind_param("i", $id_hapus);
        if ($stmt_delete->execute()) {
            if ($stmt_delete->affected_rows > 0) {
                $_SESSION['flash_message'] = "<p class='text-green-500 p-3 bg-green-100 border border-green-400 rounded'>Kelas berhasil dihapus.</p>";
            } else {
                $_SESSION['flash_message'] = "<p class='text-yellow-500 p-3 bg-yellow-100 border border-yellow-400 rounded'>Kelas tidak ditemukan atau sudah dihapus.</p>";
            }
        } else {
            if ($conn->errno == 1451) {
                $_SESSION['flash_message'] = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Error: Kelas tidak dapat dihapus karena masih digunakan oleh data siswa atau wali kelas.</p>";
            } else {
                $_SESSION['flash_message'] = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Gagal menghapus kelas: " . htmlspecialchars($stmt_delete->error) . "</p>";
            }
        }
        $stmt_delete->close();
    } else {
         $_SESSION['flash_message'] = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Error (prepare delete): " . htmlspecialchars($conn->error) . "</p>";
    }
    header('Location: manage_kelas.php'); // THIS IS LINE 83 in your original code for Hapus
    exit;
}

// Logika Update Kelas
if (isset($_POST['update'])) {
    $id_update = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $nama_kelas_update = isset($_POST['nama_kelas']) ? trim($_POST['nama_kelas']) : '';
    $redirect_url = 'manage_kelas.php'; // Default redirect

    if (empty($nama_kelas_update) || $id_update <= 0) {
        $_SESSION['flash_message'] = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Nama kelas tidak boleh kosong untuk update.</p>";
        if ($id_update > 0) $redirect_url .= '?edit=' . $id_update; // Redirect back to edit form on error
    } else {
        $stmt_check_update = $conn->prepare("SELECT id FROM kelas WHERE nama_kelas = ? AND id != ?");
        if ($stmt_check_update) {
            $stmt_check_update->bind_param("si", $nama_kelas_update, $id_update);
            $stmt_check_update->execute();
            $result_check_update = $stmt_check_update->get_result();
            if ($result_check_update->num_rows > 0) {
                $_SESSION['flash_message'] = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Nama kelas '<strong>" . htmlspecialchars($nama_kelas_update) . "</strong>' sudah digunakan oleh kelas lain.</p>";
                $redirect_url .= '?edit=' . $id_update; // Redirect back to edit form
            } else {
                $stmt_update = $conn->prepare("UPDATE kelas SET nama_kelas = ? WHERE id = ?");
                if ($stmt_update) {
                    $stmt_update->bind_param("si", $nama_kelas_update, $id_update);
                    if ($stmt_update->execute()) {
                        $_SESSION['flash_message'] = "<p class='text-green-500 p-3 bg-green-100 border border-green-400 rounded'>Kelas berhasil diperbarui.</p>";
                        // On success, redirect to the main list (default $redirect_url)
                    } else {
                        $_SESSION['flash_message'] = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Gagal memperbarui kelas: " . htmlspecialchars($stmt_update->error) . "</p>";
                        $redirect_url .= '?edit=' . $id_update; // Redirect back to edit form
                    }
                    $stmt_update->close();
                } else {
                    $_SESSION['flash_message'] = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Error (prepare update): " . htmlspecialchars($conn->error) . "</p>";
                    $redirect_url .= '?edit=' . $id_update;
                }
            }
            $stmt_check_update->close();
        } else {
            $_SESSION['flash_message'] = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Error (prepare check update): " . htmlspecialchars($conn->error) . "</p>";
            $redirect_url .= '?edit=' . $id_update;
        }
    }
    header('Location: ' . $redirect_url);
    exit;
}

// Logika Upload Excel untuk Kelas
if (isset($_POST['upload_excel']) && isset($_FILES['excel_file_kelas']) && $_FILES['excel_file_kelas']['error'] === UPLOAD_ERR_OK) {
    $file_tmp_name = $_FILES['excel_file_kelas']['tmp_name'];
    $conn->begin_transaction();
    $upload_overall_success = true;
    $success_count = 0;
    $error_count = 0;
    $skipped_details_excel = [];

    try {
        $spreadsheet = IOFactory::load($file_tmp_name);
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestDataRow();

        for ($row_excel = 2; $row_excel <= $highestRow; $row_excel++) {
            $nama_kelas_excel = trim($sheet->getCellByColumnAndRow(1, $row_excel)->getValue());

            if (empty($nama_kelas_excel)) {
                continue; 
            }

            $stmt_cek_excel = $conn->prepare("SELECT id FROM kelas WHERE nama_kelas = ?");
            if ($stmt_cek_excel) {
                $stmt_cek_excel->bind_param("s", $nama_kelas_excel);
                $stmt_cek_excel->execute();
                $res_cek_excel = $stmt_cek_excel->get_result();
                if ($res_cek_excel->num_rows > 0) {
                    $skipped_details_excel[] = "Baris $row_excel: Nama kelas '<strong>" . htmlspecialchars($nama_kelas_excel) . "</strong>' sudah ada.";
                    $error_count++;
                    $upload_overall_success = false;
                    $stmt_cek_excel->close();
                    continue;
                }
                $stmt_cek_excel->close();
            } else {
                $skipped_details_excel[] = "Baris $row_excel: Gagal prepare cek Excel. DB error: ".$conn->error;
                $error_count++; $upload_overall_success = false; continue;
            }


            $stmt_insert_excel = $conn->prepare("INSERT INTO kelas (nama_kelas) VALUES (?)");
            if ($stmt_insert_excel) {
                $stmt_insert_excel->bind_param("s", $nama_kelas_excel);
                if ($stmt_insert_excel->execute()) {
                    $success_count++;
                } else {
                    $skipped_details_excel[] = "Baris $row_excel: Gagal insert kelas '<strong>" . htmlspecialchars($nama_kelas_excel) . "</strong>' - " . htmlspecialchars($stmt_insert_excel->error);
                    $error_count++;
                    $upload_overall_success = false;
                }
                $stmt_insert_excel->close();
            } else {
                $skipped_details_excel[] = "Baris $row_excel: Gagal prepare insert Excel. DB error: ".$conn->error;
                $error_count++; $upload_overall_success = false; continue;
            }
        }

        if ($upload_overall_success && $error_count == 0) {
            $conn->commit();
            if ($success_count > 0) {
                $_SESSION['flash_message'] = "<p class='text-green-500 p-3 bg-green-100 border border-green-400 rounded'>$success_count data kelas dari Excel berhasil diimpor!</p>";
            } else {
                 $_SESSION['flash_message'] = "<p class='text-yellow-500 p-3 bg-yellow-100 border border-yellow-400 rounded'>Tidak ada data kelas baru yang valid untuk diimpor dari Excel.</p>";
            }
        } else {
            $conn->rollback();
            $temp_message = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Impor Excel Gagal atau sebagian gagal. $success_count kelas berhasil, $error_count gagal/dilewati. Perubahan dibatalkan.</p>";
            if (!empty($skipped_details_excel)) {
                 $temp_message .= "<p class='text-sm text-gray-700 mt-2'>Detail error/skip:</p><ul class='list-disc list-inside text-sm text-red-600 max-h-40 overflow-y-auto'>";
                foreach ($skipped_details_excel as $skip) { $temp_message .= "<li>" . $skip . "</li>"; }
                $temp_message .= "</ul>";
            }
            $_SESSION['flash_message'] = $temp_message;
        }

    } catch (PhpSpreadsheetException $e) {
        if ($conn->inTransaction()) $conn->rollback();
        $_SESSION['flash_message'] = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Error membaca file Excel: Pastikan format file benar. (" . htmlspecialchars($e->getMessage()) . ")</p>";
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollback();
        $_SESSION['flash_message'] = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Error proses Excel: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    header('Location: manage_kelas.php');
    exit;
}


// --- DATA FETCHING AND PREPARATION FOR DISPLAY (after all processing and redirects) ---
$kelas_edit_data = null;
if (isset($_GET['edit']) && ctype_digit((string)$_GET['edit'])) {
    $id_edit_form = (int)$_GET['edit'];
    $stmt_edit_form = $conn->prepare("SELECT id, nama_kelas FROM kelas WHERE id = ?");
    if ($stmt_edit_form) {
        $stmt_edit_form->bind_param("i", $id_edit_form);
        $stmt_edit_form->execute();
        $result_edit_form = $stmt_edit_form->get_result();
        if ($result_edit_form->num_rows === 1) {
            $kelas_edit_data = $result_edit_form->fetch_assoc();
        } else {
            // Set message to be displayed on current page load, not for redirect
            $message = "<p class='text-yellow-500 p-3 bg-yellow-100 border border-yellow-400 rounded'>Kelas untuk diedit tidak ditemukan.</p>";
        }
        $stmt_edit_form->close();
    } else {
        $message = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Error (prepare get edit): " . htmlspecialchars($conn->error) . "</p>";
    }
}

// Combine session flash messages with any messages generated during this page load (e.g., edit not found)
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'] . $message; // Prepend session message
    unset($_SESSION['flash_message']);
}
if (isset($_SESSION['flash_message_form_error'])) {
    $message .= $_SESSION['flash_message_form_error']; // Append form specific error
    unset($_SESSION['flash_message_form_error']);
}
$form_data_kelas = $_SESSION['form_data_kelas'] ?? []; // For repopulating 'Tambah Kelas' form
unset($_SESSION['form_data_kelas']);


// --- NOW INCLUDE THE HEADER.PHP (which starts HTML output) ---
include '../includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <h2 class="text-2xl font-bold mb-6 text-gray-800"><i class="fas fa-chalkboard mr-2"></i> Manajemen Kelas</h2>

    <?php if (!empty($message)): ?>
        <div class="mb-4">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <!-- Form Tambah/Edit Kelas -->
    <?php if (isset($_GET['edit']) && $kelas_edit_data): ?>
        <h3 class="text-xl font-semibold mb-4 text-gray-700">Edit Kelas</h3>
        <form method="POST" action="manage_kelas.php?edit=<?php echo htmlspecialchars($kelas_edit_data['id']); ?>" class="bg-white shadow-md rounded-lg p-6 mb-8 flex items-center space-x-3">
            <input type="hidden" name="id" value="<?php echo htmlspecialchars($kelas_edit_data['id']); ?>">
            <div class="flex-grow">
                <label for="nama_kelas_edit" class="sr-only">Nama Kelas</label>
                <input type="text" name="nama_kelas" id="nama_kelas_edit" value="<?php echo htmlspecialchars($kelas_edit_data['nama_kelas']); ?>" class="w-full p-2.5 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required>
            </div>
            <button type="submit" name="update" class="inline-flex items-center justify-center px-4 py-2.5 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                <i class="fas fa-save mr-2"></i> Update
            </button>
            <a href="manage_kelas.php" class="inline-flex items-center justify-center px-4 py-2.5 border border-gray-300 text-sm font-medium rounded-md shadow-sm text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                Batal
            </a>
        </form>
    <?php else: ?>
        <h3 class="text-xl font-semibold mb-4 text-gray-700">Tambah Kelas Baru</h3>
        <form method="POST" action="manage_kelas.php" class="bg-white shadow-md rounded-lg p-6 mb-8 flex items-center space-x-3">
            <div class="flex-grow">
                <label for="nama_kelas_tambah" class="sr-only">Nama Kelas</label>
                <input type="text" name="nama_kelas" id="nama_kelas_tambah" placeholder="Masukkan Nama Kelas Baru" class="w-full p-2.5 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required value="<?php echo htmlspecialchars($form_data_kelas['nama_kelas'] ?? ''); ?>">
            </div>
            <button type="submit" name="tambah" class="inline-flex items-center justify-center px-4 py-2.5 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                <i class="fas fa-plus mr-2"></i> Tambah
            </button>
        </form>
    <?php endif; ?>


    <!-- Form Upload Excel Kelas -->
    <div class="bg-white shadow-md rounded-lg p-6 mb-8">
        <h3 class="text-xl font-semibold mb-4 text-gray-700">Upload Data Kelas dari Excel</h3>
        <p class="text-sm text-gray-600 mb-2">Format Excel: Kolom pertama (Kolom A) berisi Nama Kelas. Baris pertama akan dilewati (dianggap header).</p>
        <form method="POST" enctype="multipart/form-data" action="manage_kelas.php">
            <div class="flex flex-col sm:flex-row items-center space-y-2 sm:space-y-0 sm:space-x-4">
                <input type="file" name="excel_file_kelas" accept=".xlsx,.xls" class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100" required>
                <button type="submit" name="upload_excel" class="w-full sm:w-auto inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                    <i class="fas fa-upload mr-2"></i> Upload Excel Kelas
                </button>
            </div>
        </form>
    </div>


    <!-- Tabel Kelas -->
    <div class="bg-white shadow-md rounded-lg overflow-x-auto">
        <h3 class="text-xl font-semibold mb-4 p-4 text-gray-700">Daftar Kelas</h3>
        <table class="w-full min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nama Kelas</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Aksi</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php
                $query_tabel_kelas = "SELECT id, nama_kelas FROM kelas ORDER BY nama_kelas";
                $result_tabel_kelas = $conn->query($query_tabel_kelas);
                if ($result_tabel_kelas && $result_tabel_kelas->num_rows > 0) {
                    while ($row_tabel_kelas = $result_tabel_kelas->fetch_assoc()) {
                        echo "<tr>
                                <td class='px-6 py-4 whitespace-nowrap text-sm text-gray-500'>" . htmlspecialchars($row_tabel_kelas['id']) . "</td>
                                <td class='px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900'>" . htmlspecialchars($row_tabel_kelas['nama_kelas']) . "</td>
                                <td class='px-6 py-4 whitespace-nowrap text-sm font-medium'>
                                    <a href='manage_kelas.php?edit=" . htmlspecialchars($row_tabel_kelas['id']) . "' class='text-indigo-600 hover:text-indigo-900 mr-3' title='Edit Kelas'><i class='fas fa-edit'></i> Edit</a>
                                    <a href='manage_kelas.php?hapus=" . htmlspecialchars($row_tabel_kelas['id']) . "' class='text-red-600 hover:text-red-900' onclick='return confirm(\"Apakah Anda yakin ingin menghapus kelas " . htmlspecialchars(addslashes($row_tabel_kelas['nama_kelas'])) . "? Ini bisa berdampak pada data siswa dan wali kelas terkait.\")' title='Hapus Kelas'><i class='fas fa-trash'></i> Hapus</a>
                                </td>
                              </tr>";
                    }
                } else {
                    echo "<tr><td colspan='3' class='px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center'>Belum ada data kelas.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>
<?php
include '../includes/footer.php';
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>