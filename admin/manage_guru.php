<?php
// error_reporting(E_ALL);
// ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include '../includes/db.php';
require '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['flash_message'] = "<p class='text-red-500 p-4'>Akses ditolak. Anda harus login sebagai admin.</p>";
    header('Location: ../login.php');
    exit;
}

function hashGuruPassword($password) {
    return MD5($password);
}

$message = '';

// Logika Tambah Wali Kelas
if (isset($_POST['tambah'])) {
    $nama = isset($_POST['nama']) ? trim($_POST['nama']) : '';
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password_input = isset($_POST['password']) ? $_POST['password'] : ''; // Password should not be trimmed
    $kelas_id = isset($_POST['kelas_id']) ? (int)$_POST['kelas_id'] : 0;

    if (empty($nama) || empty($username) || empty($password_input) || $kelas_id <= 0) {
        $message = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Semua kolom harus diisi dengan benar.</p>";
        $_SESSION['form_data'] = $_POST;
        $_SESSION['flash_message_form_error'] = $message;
        header('Location: manage_guru.php');
        exit;
    } else {
        $stmt_check = $conn->prepare("SELECT id FROM users WHERE username = ?");
        if ($stmt_check) {
            $stmt_check->bind_param("s", $username);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();

            if ($result_check->num_rows > 0) {
                $message = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Username '<strong>" . htmlspecialchars($username) . "</strong>' sudah digunakan.</p>";
                $_SESSION['form_data'] = $_POST;
                $_SESSION['flash_message_form_error'] = $message;
                $stmt_check->close();
                header('Location: manage_guru.php');
                exit;
            } else {
                // Username is unique
                $hashed_password = hashGuruPassword($password_input);
                $role = 'wali_kelas';
                $stmt_insert = $conn->prepare("INSERT INTO users (nama, username, password, role, kelas_id) VALUES (?, ?, ?, ?, ?)");
                if ($stmt_insert) {
                    $stmt_insert->bind_param("ssssi", $nama, $username, $hashed_password, $role, $kelas_id);
                    if ($stmt_insert->execute()) {
                        $_SESSION['flash_message'] = "<p class='text-green-500 p-3 bg-green-100 border border-green-400 rounded'>Wali kelas '<strong>" . htmlspecialchars($nama) . "</strong>' berhasil ditambahkan.</p>";
                        $stmt_insert->close();
                        $stmt_check->close();
                        header('Location: manage_guru.php');
                        exit;
                    } else {
                        // This is where your fatal error was likely triggered if the above checks didn't prevent it
                        $message = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Gagal menambah wali kelas (DB Error): " . htmlspecialchars($stmt_insert->error) . "</p>";
                        // Check for specific duplicate entry error if not caught by earlier check
                        if ($conn->errno == 1062) { // 1062 is MySQL error code for duplicate entry
                           $message = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Terjadi kesalahan: Username '<strong>" . htmlspecialchars($username) . "</strong>' sudah ada (dicek ulang oleh DB).</p>";
                        }
                    }
                    $stmt_insert->close();
                } else {
                    $message = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Error (prepare insert): " . htmlspecialchars($conn->error) . "</p>";
                }
            }
            $stmt_check->close();
        } else {
            $message = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Error (prepare check username): " . htmlspecialchars($conn->error) . "</p>";
        }

        // If any message was set and not redirected yet, store for display
        if (!empty($message)) {
            $_SESSION['form_data'] = $_POST;
            $_SESSION['flash_message_form_error'] = $message;
        }
        header('Location: manage_guru.php');
        exit;
    }
}


// Logika Hapus Wali Kelas
if (isset($_GET['hapus']) && ctype_digit((string)$_GET['hapus'])) {
    $id_hapus = (int)$_GET['hapus'];
    $stmt_delete = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'wali_kelas'");
    if ($stmt_delete) {
        $stmt_delete->bind_param("i", $id_hapus);
        if ($stmt_delete->execute()) {
            if ($stmt_delete->affected_rows > 0) {
                $_SESSION['flash_message'] = "<p class='text-green-500 p-3 bg-green-100 border border-green-400 rounded'>Wali kelas berhasil dihapus.</p>";
            } else {
                $_SESSION['flash_message'] = "<p class='text-yellow-500 p-3 bg-yellow-100 border border-yellow-400 rounded'>Wali kelas tidak ditemukan atau sudah dihapus.</p>";
            }
        } else {
            $_SESSION['flash_message'] = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Gagal menghapus wali kelas: " . htmlspecialchars($stmt_delete->error) . "</p>";
        }
        $stmt_delete->close();
    } else {
        $_SESSION['flash_message'] = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Error (prepare delete): " . htmlspecialchars($conn->error) . "</p>";
    }
    header('Location: manage_guru.php');
    exit;
}

// Logika Update Wali Kelas
if (isset($_POST['update'])) {
    $id_update = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $nama_update = isset($_POST['nama']) ? trim($_POST['nama']) : '';
    $username_update = isset($_POST['username']) ? trim($_POST['username']) : '';
    $kelas_id_update = isset($_POST['kelas_id']) ? (int)$_POST['kelas_id'] : 0;
    $password_update_input = isset($_POST['password']) ? $_POST['password'] : '';

    if (empty($nama_update) || empty($username_update) || $kelas_id_update <= 0 || $id_update <= 0) {
        $_SESSION['flash_message'] = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Data tidak lengkap untuk update.</p>";
        // To retain edit form values, you'd need to store them in session like for 'tambah'
    } else {
        $stmt_check_username_update = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        if ($stmt_check_username_update) {
            $stmt_check_username_update->bind_param("si", $username_update, $id_update);
            $stmt_check_username_update->execute();
            $result_check_username_update = $stmt_check_username_update->get_result();

            if ($result_check_username_update->num_rows > 0) {
                 $_SESSION['flash_message'] = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Username '<strong>" . htmlspecialchars($username_update) . "</strong>' sudah digunakan oleh user lain.</p>";
            } else {
                $sql_update = "UPDATE users SET nama = ?, username = ?, kelas_id = ?";
                $types = "ssi";
                $params = [$nama_update, $username_update, $kelas_id_update];

                if (!empty($password_update_input)) {
                    $sql_update .= ", password = ?";
                    $types .= "s";
                    $params[] = hashGuruPassword($password_update_input);
                }
                $sql_update .= " WHERE id = ? AND role = 'wali_kelas'";
                $types .= "i";
                $params[] = $id_update;

                $stmt_update_data = $conn->prepare($sql_update);
                if ($stmt_update_data) {
                    $stmt_update_data->bind_param($types, ...$params);
                    if ($stmt_update_data->execute()) {
                        $_SESSION['flash_message'] = "<p class='text-green-500 p-3 bg-green-100 border border-green-400 rounded'>Data wali kelas berhasil diperbarui.</p>";
                    } else {
                        $_SESSION['flash_message'] = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Gagal memperbarui data: " . htmlspecialchars($stmt_update_data->error) . "</p>";
                    }
                    $stmt_update_data->close();
                } else {
                    $_SESSION['flash_message'] = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Error (prepare update): " . htmlspecialchars($conn->error) . "</p>";
                }
            }
            $stmt_check_username_update->close();
        } else {
             $_SESSION['flash_message'] = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Error (prepare check username update): " . htmlspecialchars($conn->error) . "</p>";
        }
    }
    // Redirect back to edit page if there was an error specific to it, or general list
    $redirect_url = 'manage_guru.php';
    if (isset($_SESSION['flash_message']) && strpos($_SESSION['flash_message'], 'text-red-500') !== false && $id_update > 0) {
        // If error and we have an ID, go back to edit form
        $redirect_url .= '?edit=' . $id_update;
    }
    header('Location: ' . $redirect_url);
    exit;
}

// Logika Upload Excel
if (isset($_POST['upload']) && isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === UPLOAD_ERR_OK) {
    $file_tmp_name = $_FILES['excel_file']['tmp_name'];
    $conn->begin_transaction(); // Start transaction
    $overall_success = true; // Flag to track if all rows are successful
    $import_messages = []; // To store messages for each row or general
    $success_count = 0;
    $error_count = 0;
    $skipped_details = [];


    try {
        $spreadsheet = IOFactory::load($file_tmp_name);
        $sheet = $spreadsheet->getActiveSheet();
        $highestRow = $sheet->getHighestDataRow(); // Use getHighestDataRow to ignore empty tailing rows

        // Kolom Excel: A:Nama, B:Username, C:Password, D:Nama Kelas
        for ($row_excel = 2; $row_excel <= $highestRow; $row_excel++) { // Start from row 2 assuming row 1 is header
            $nama_excel = trim($sheet->getCellByColumnAndRow(1, $row_excel)->getValue());
            $username_excel = trim($sheet->getCellByColumnAndRow(2, $row_excel)->getValue());
            $password_excel_input = (string) $sheet->getCellByColumnAndRow(3, $row_excel)->getValue(); // Ensure password is read as string
            $nama_kelas_excel = trim($sheet->getCellByColumnAndRow(4, $row_excel)->getValue());

            // Skip entirely empty rows
            if (empty($nama_excel) && empty($username_excel) && empty($password_excel_input) && empty($nama_kelas_excel)) {
                continue;
            }

            // Validate data for the current row
            if (empty($nama_excel) || empty($username_excel) || empty($password_excel_input) || empty($nama_kelas_excel)) {
                $skipped_details[] = "Baris $row_excel: Data tidak lengkap (Nama, Username, Password, atau Nama Kelas kosong).";
                $error_count++;
                $overall_success = false;
                continue;
            }

            // Dapatkan kelas_id dari nama_kelas
            $kelas_id_excel = null;
            $stmt_kelas_excel = $conn->prepare("SELECT id FROM kelas WHERE nama_kelas = ?");
            if(!$stmt_kelas_excel) {
                $skipped_details[] = "Baris $row_excel: Gagal prepare statement kelas. DB error: ".$conn->error;
                $error_count++; $overall_success = false; continue;
            }
            $stmt_kelas_excel->bind_param("s", $nama_kelas_excel);
            $stmt_kelas_excel->execute();
            $res_kelas_excel = $stmt_kelas_excel->get_result();
            if ($res_kelas_excel->num_rows > 0) {
                $kelas_id_excel = $res_kelas_excel->fetch_assoc()['id'];
            } else {
                $skipped_details[] = "Baris $row_excel: Kelas '".htmlspecialchars($nama_kelas_excel)."' tidak ditemukan di database.";
                $error_count++;
                $overall_success = false;
                $stmt_kelas_excel->close();
                continue;
            }
            $stmt_kelas_excel->close();

            // Cek apakah username sudah ada
            $stmt_cek_excel = $conn->prepare("SELECT id FROM users WHERE username = ?");
            if(!$stmt_cek_excel) {
                $skipped_details[] = "Baris $row_excel: Gagal prepare statement cek username. DB error: ".$conn->error;
                $error_count++; $overall_success = false; continue;
            }
            $stmt_cek_excel->bind_param("s", $username_excel);
            $stmt_cek_excel->execute();
            $res_cek_excel = $stmt_cek_excel->get_result();
            if ($res_cek_excel->num_rows > 0) {
                $skipped_details[] = "Baris $row_excel: Username '".htmlspecialchars($username_excel)."' sudah ada di database.";
                $error_count++;
                $overall_success = false;
                $stmt_cek_excel->close();
                continue;
            }
            $stmt_cek_excel->close();

            // Insert user
            $hashed_password_excel = hashGuruPassword($password_excel_input);
            $role_excel = 'wali_kelas';
            $stmt_insert_excel = $conn->prepare("INSERT INTO users (nama, username, password, role, kelas_id) VALUES (?, ?, ?, ?, ?)");
            if (!$stmt_insert_excel) {
                 $skipped_details[] = "Baris $row_excel: Gagal prepare statement insert user. DB error: ".$conn->error;
                $error_count++; $overall_success = false; continue;
            }
            $stmt_insert_excel->bind_param("ssssi", $nama_excel, $username_excel, $hashed_password_excel, $role_excel, $kelas_id_excel);
            if ($stmt_insert_excel->execute()) {
                $success_count++;
            } else {
                $skipped_details[] = "Baris $row_excel: Gagal insert data ke database. Error: " . htmlspecialchars($stmt_insert_excel->error);
                $error_count++;
                $overall_success = false;
            }
            $stmt_insert_excel->close();
        } // End loop for rows

        if ($overall_success && $error_count == 0) { // Only commit if all rows were successful
            $conn->commit();
            $message = "<p class='text-green-500 p-3 bg-green-100 border border-green-400 rounded'>$success_count data wali kelas dari Excel berhasil diimpor!</p>";
        } else {
            $conn->rollback();
            $message = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Impor Excel Gagal atau sebagian gagal. $success_count berhasil, $error_count gagal. Perubahan dibatalkan.</p>";
            if (!empty($skipped_details)) {
                 $message .= "<p class='text-sm text-gray-700 mt-2'>Detail error/skip:</p><ul class='list-disc list-inside text-sm text-red-600 max-h-40 overflow-y-auto'>";
                foreach ($skipped_details as $skip) { $message .= "<li>" . htmlspecialchars($skip) . "</li>"; }
                $message .= "</ul>";
            }
        }

    } catch (\PhpOffice\PhpSpreadsheet\Reader\Exception $e) {
        if ($conn->inTransaction()) $conn->rollback();
        $message = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Error membaca file Excel: " . htmlspecialchars($e->getMessage()) . "</p>";
    } catch (Exception $e) { // Catch other general exceptions
        if ($conn->inTransaction()) $conn->rollback();
        $message = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Error proses Excel: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    $_SESSION['flash_message'] = $message; // Use flash for upload too
    header('Location: manage_guru.php');
    exit;
}


$guru_edit_data = null;
if (isset($_GET['edit']) && ctype_digit((string)$_GET['edit'])) {
    $id_edit_form = (int)$_GET['edit'];
    $stmt_edit_form = $conn->prepare("SELECT id, nama, username, kelas_id FROM users WHERE id = ? AND role = 'wali_kelas'");
    if ($stmt_edit_form) {
        $stmt_edit_form->bind_param("i", $id_edit_form);
        $stmt_edit_form->execute();
        $result_edit_form = $stmt_edit_form->get_result();
        if ($result_edit_form->num_rows === 1) {
            $guru_edit_data = $result_edit_form->fetch_assoc();
        } else {
             $_SESSION['flash_message'] = "<p class='text-yellow-500 p-3 bg-yellow-100 border border-yellow-400 rounded'>Wali kelas untuk diedit tidak ditemukan.</p>";
             // No redirect here, let the page load and message display
        }
        $stmt_edit_form->close();
    } else {
        // This message won't be seen if header.php is included after, use session flash
        $_SESSION['flash_message'] = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Error (prepare get edit): " . htmlspecialchars($conn->error) . "</p>";
    }
}


if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message']; // Overwrite $message if flash is set
    unset($_SESSION['flash_message']);
}
if (isset($_SESSION['flash_message_form_error'])) {
    $message .= $_SESSION['flash_message_form_error']; // Append form error if exists
    unset($_SESSION['flash_message_form_error']);
}
$form_data = $_SESSION['form_data'] ?? [];
unset($_SESSION['form_data']);

// NOW INCLUDE HEADER
include '../includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <h2 class="text-2xl font-bold mb-6 text-gray-800"><i class="fas fa-user-tie mr-2"></i> Manajemen Wali Kelas</h2>

    <?php if (!empty($message)): ?>
        <div class="mb-4">
            <?php echo $message; // This will display combined messages ?>
        </div>
    <?php endif; ?>

    <!-- Form Tambah/Edit Wali Kelas -->
    <?php if (isset($_GET['edit']) && $guru_edit_data): ?>
        <h3 class="text-xl font-semibold mb-4 text-gray-700">Edit Wali Kelas</h3>
        <form method="POST" action="manage_guru.php?edit=<?php echo htmlspecialchars($guru_edit_data['id']); ?>" class="bg-white shadow-md rounded-lg p-6 mb-8 space-y-4">
            <input type="hidden" name="id" value="<?php echo htmlspecialchars($guru_edit_data['id']); ?>">
            <div>
                <label for="nama_edit" class="block text-sm font-medium text-gray-700">Nama Wali Kelas</label>
                <input type="text" name="nama" id="nama_edit" value="<?php echo htmlspecialchars($guru_edit_data['nama']); ?>" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required>
            </div>
            <div>
                <label for="username_edit" class="block text-sm font-medium text-gray-700">Username</label>
                <input type="text" name="username" id="username_edit" value="<?php echo htmlspecialchars($guru_edit_data['username']); ?>" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required>
            </div>
            <div>
                <label for="password_edit" class="block text-sm font-medium text-gray-700">Password Baru (Opsional)</label>
                <input type="password" name="password" id="password_edit" placeholder="Kosongkan jika tidak ingin mengubah password" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
            </div>
            <div>
                <label for="kelas_id_edit" class="block text-sm font-medium text-gray-700">Kelas yang Diampu</label>
                <select name="kelas_id" id="kelas_id_edit" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required>
                    <option value="">Pilih Kelas</option>
                    <?php
                    $query_kelas_edit = "SELECT id, nama_kelas FROM kelas ORDER BY nama_kelas";
                    $result_kelas_edit = $conn->query($query_kelas_edit);
                    while ($row_kelas_edit = $result_kelas_edit->fetch_assoc()) {
                        $selected = ($row_kelas_edit['id'] == $guru_edit_data['kelas_id']) ? 'selected' : '';
                        echo "<option value='{$row_kelas_edit['id']}' $selected>" . htmlspecialchars($row_kelas_edit['nama_kelas']) . "</option>";
                    }
                    ?>
                </select>
            </div>
            <div class="flex space-x-2">
                <button type="submit" name="update" class="inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                    <i class="fas fa-save mr-2"></i> Update Wali Kelas
                </button>
                <a href="manage_guru.php" class="inline-flex items-center justify-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md shadow-sm text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                    Batal
                </a>
            </div>
        </form>
    <?php else: ?>
        <h3 class="text-xl font-semibold mb-4 text-gray-700">Tambah Wali Kelas Baru</h3>
        <form method="POST" action="manage_guru.php" class="bg-white shadow-md rounded-lg p-6 mb-8 space-y-4">
            <div>
                <label for="nama_tambah" class="block text-sm font-medium text-gray-700">Nama Wali Kelas</label>
                <input type="text" name="nama" id="nama_tambah" placeholder="Nama Lengkap Wali Kelas" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required value="<?php echo htmlspecialchars($form_data['nama'] ?? ''); ?>">
            </div>
            <div>
                <label for="username_tambah" class="block text-sm font-medium text-gray-700">Username</label>
                <input type="text" name="username" id="username_tambah" placeholder="Username untuk login" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required value="<?php echo htmlspecialchars($form_data['username'] ?? ''); ?>">
            </div>
            <div>
                <label for="password_tambah" class="block text-sm font-medium text-gray-700">Password</label>
                <input type="password" name="password" id="password_tambah" placeholder="Password untuk login" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required>
            </div>
            <div>
                <label for="kelas_id_tambah" class="block text-sm font-medium text-gray-700">Kelas yang Diampu</label>
                <select name="kelas_id" id="kelas_id_tambah" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required>
                    <option value="">Pilih Kelas</option>
                    <?php
                    $selected_kelas_tambah = (isset($form_data['kelas_id']) && is_numeric($form_data['kelas_id'])) ? (int)$form_data['kelas_id'] : 0;
                    $query_kelas_tambah = "SELECT id, nama_kelas FROM kelas ORDER BY nama_kelas";
                    $result_kelas_tambah = $conn->query($query_kelas_tambah);
                    while ($row_kelas_tambah = $result_kelas_tambah->fetch_assoc()) {
                        $selected = ($row_kelas_tambah['id'] == $selected_kelas_tambah) ? 'selected' : '';
                        echo "<option value='{$row_kelas_tambah['id']}' $selected>" . htmlspecialchars($row_kelas_tambah['nama_kelas']) . "</option>";
                    }
                    ?>
                </select>
            </div>
            <button type="submit" name="tambah" class="inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                <i class="fas fa-plus mr-2"></i> Tambah Wali Kelas
            </button>
        </form>
    <?php endif; ?>


    <!-- Form Upload Excel -->
     <div class="bg-white shadow-md rounded-lg p-6 mb-8">
        <h3 class="text-xl font-semibold mb-4 text-gray-700">Upload Data Wali Kelas dari Excel</h3>
        <p class="text-sm text-gray-600 mb-1">Format Excel:</p>
        <ul class="list-disc list-inside text-sm text-gray-600 mb-2">
            <li>Kolom A: Nama Wali Kelas</li>
            <li>Kolom B: Username</li>
            <li>Kolom C: Password</li>
            <li>Kolom D: Nama Kelas yang Diampu (Pastikan nama kelas persis seperti di database)</li>
        </ul>
        <form method="POST" enctype="multipart/form-data" action="manage_guru.php">
            <div class="flex flex-col sm:flex-row items-center space-y-2 sm:space-y-0 sm:space-x-4">
                <input type="file" name="excel_file" accept=".xlsx,.xls" required class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
                <button type="submit" name="upload" class="w-full sm:w-auto inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                    <i class="fas fa-upload mr-2"></i> Upload Excel
                </button>
            </div>
        </form>
    </div>

    <!-- Tabel Wali Kelas -->
    <div class="bg-white shadow-md rounded-lg overflow-x-auto">
         <h3 class="text-xl font-semibold mb-4 p-4 text-gray-700">Daftar Wali Kelas</h3>
        <table class="w-full min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nama</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Username</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Password (Hashed)</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kelas Diampu</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Aksi</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php
                $query_tabel = "SELECT u.id, u.nama, u.username, u.password AS password_hashed, k.nama_kelas 
                                FROM users u 
                                LEFT JOIN kelas k ON u.kelas_id = k.id 
                                WHERE u.role = 'wali_kelas'
                                ORDER BY u.nama";
                $result_tabel = $conn->query($query_tabel);
                if ($result_tabel && $result_tabel->num_rows > 0) {
                    while ($row_tabel = $result_tabel->fetch_assoc()) {
                        echo "<tr>
                                <td class='px-6 py-4 whitespace-nowrap text-sm text-gray-500'>" . htmlspecialchars($row_tabel['id']) . "</td>
                                <td class='px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900'>" . htmlspecialchars($row_tabel['nama']) . "</td>
                                <td class='px-6 py-4 whitespace-nowrap text-sm text-gray-500'>" . htmlspecialchars($row_tabel['username']) . "</td>
                                <td class='px-6 py-4 whitespace-nowrap text-xs text-gray-400 break-all' title='" . htmlspecialchars($row_tabel['password_hashed']) . "'>" . substr(htmlspecialchars($row_tabel['password_hashed']), 0, 15) . "...</td>
                                <td class='px-6 py-4 whitespace-nowrap text-sm text-gray-500'>" . htmlspecialchars($row_tabel['nama_kelas'] ?? 'N/A') . "</td>
                                <td class='px-6 py-4 whitespace-nowrap text-sm font-medium'>
                                    <a href='manage_guru.php?edit=" . htmlspecialchars($row_tabel['id']) . "' class='text-indigo-600 hover:text-indigo-900 mr-3' title='Edit Wali Kelas'><i class='fas fa-edit'></i> Edit</a>
                                    <a href='manage_guru.php?hapus=" . htmlspecialchars($row_tabel['id']) . "' class='text-red-600 hover:text-red-900' onclick='return confirm(\"Apakah Anda yakin ingin menghapus wali kelas " . htmlspecialchars(addslashes($row_tabel['nama'])) . "?\")' title='Hapus Wali Kelas'><i class='fas fa-trash'></i> Hapus</a>
                                </td>
                              </tr>";
                    }
                } else {
                    echo "<tr><td colspan='6' class='px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center'>Belum ada data wali kelas.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>
<?php
include '../includes/footer.php';
if (isset($conn) && $conn instanceof mysqli) { // Check if $conn is a valid mysqli object
    $conn->close();
}
?>