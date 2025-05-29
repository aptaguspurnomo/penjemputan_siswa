<?php
// Hanya mulai sesi jika belum aktif
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Nonaktifkan display_errors untuk produksi, log error ke file jika perlu
// ini_set('display_errors', 0);
// error_reporting(0);
// Namun, untuk development, biarkan seperti ini:
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


// -----------------------------------------------------------------------------
// BAGIAN 1: VERIFIKASI SESI DAN ROLE
// -----------------------------------------------------------------------------
if (!isset($_SESSION['user_id'])) {
    $_SESSION['flash_message_profil'] = ['type' => 'error', 'messages' => ['Sesi tidak valid atau telah berakhir. Silakan login kembali.']];
    header('Location: login.php');
    exit;
}
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'wali_murid') {
    $_SESSION['flash_message_profil'] = ['type' => 'error', 'messages' => ['Akses ditolak. Halaman ini hanya untuk wali murid.']];
    header('Location: login.php');
    exit;
}

$user_id_wali_murid_saat_ini = $_SESSION['user_id'];
$username_wali_murid_saat_ini = $_SESSION['username'];
$nama_wali_murid_saat_ini = $_SESSION['nama_user'] ?? '';


// -----------------------------------------------------------------------------
// BAGIAN 2: KONEKSI DATABASE
// -----------------------------------------------------------------------------
$db_included = false;
if (file_exists(__DIR__ . '/../includes/db.php')) {
    include_once __DIR__ . '/../includes/db.php';
    $db_included = true;
}

if (!$db_included || !isset($conn) || (is_object($conn) && property_exists($conn, 'connect_error') && $conn->connect_error)) {
    $db_error_message = "Kesalahan sistem: Tidak dapat terhubung ke database.";
    if (isset($conn) && is_object($conn) && property_exists($conn, 'connect_error') && $conn->connect_error) {
        $db_error_message .= " Detail: " . htmlspecialchars($conn->connect_error);
    } elseif (!$db_included) {
        $db_error_message = "Kesalahan sistem: File konfigurasi database tidak ditemukan.";
    }
    $_SESSION['flash_message_profil'] = ['type' => 'error', 'messages' => [$db_error_message]];
    header('Location: edit_profil.php');
    exit;
}


// -----------------------------------------------------------------------------
// BAGIAN 3: PROSES DATA HANYA JIKA METODE ADALAH POST
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username_baru = isset($_POST['username_wali']) ? trim($_POST['username_wali']) : '';
    $nama_baru = isset($_POST['nama_wali']) ? trim($_POST['nama_wali']) : '';
    $password_baru = isset($_POST['password_baru_wali']) ? $_POST['password_baru_wali'] : '';
    $konfirmasi_password_baru = isset($_POST['konfirmasi_password_baru_wali']) ? $_POST['konfirmasi_password_baru_wali'] : '';

    $errors = [];
    if (empty($username_baru)) {
        $errors[] = "Username tidak boleh kosong.";
    } elseif (!preg_match('/^[a-zA-Z0-9_.]+$/', $username_baru)) {
        $errors[] = "Username hanya boleh mengandung huruf, angka, titik (.), dan underscore (_).";
    } elseif (strlen($username_baru) < 4) {
        $errors[] = "Username minimal 4 karakter.";
    }

    if (empty($nama_baru)) {
        $errors[] = "Nama lengkap tidak boleh kosong.";
    }

    if (!empty($password_baru)) {
        if (strlen($password_baru) < 6) {
            $errors[] = "Password baru minimal 6 karakter.";
        }
        if ($password_baru !== $konfirmasi_password_baru) {
            $errors[] = "Konfirmasi password baru tidak cocok.";
        }
    }

    if ($username_baru !== $username_wali_murid_saat_ini && !empty($username_baru) && empty($errors)) {
        $sql_cek_username = "SELECT id FROM users WHERE username = ? AND id != ?";
        $stmt_cek = $conn->prepare($sql_cek_username);
        if ($stmt_cek) {
            $stmt_cek->bind_param("si", $username_baru, $user_id_wali_murid_saat_ini);
            $stmt_cek->execute();
            $stmt_cek->store_result();
            if ($stmt_cek->num_rows > 0) {
                $errors[] = "Username '" . htmlspecialchars($username_baru) . "' sudah digunakan oleh pengguna lain.";
            }
            $stmt_cek->close();
        } else {
            $errors[] = "Gagal melakukan pengecekan username: " . htmlspecialchars($conn->error);
        }
    }

    if (!empty($errors)) {
        $_SESSION['flash_message_profil'] = ['type' => 'error', 'messages' => $errors];
        header('Location: edit_profil.php');
        exit;
    } else {
        $sql_parts = [];
        $params_values = [];
        $types = "";
        $ada_perubahan_data = false;

        if ($username_baru !== $username_wali_murid_saat_ini) {
            $sql_parts[] = "username = ?";
            $params_values[] = $username_baru;
            $types .= "s";
            $ada_perubahan_data = true;
        }

        if ($nama_baru !== $nama_wali_murid_saat_ini) {
            $sql_parts[] = "nama = ?"; // Kolom di DB adalah 'nama'
            $params_values[] = $nama_baru;
            $types .= "s";
            $ada_perubahan_data = true;
        }

        if (!empty($password_baru)) {
            $hashed_password_baru = password_hash($password_baru, PASSWORD_DEFAULT);
            $sql_parts[] = "password = ?";
            $params_values[] = $hashed_password_baru;
            $types .= "s";
            $ada_perubahan_data = true;
        }

        if ($ada_perubahan_data) {
            $sql_update = "UPDATE users SET " . implode(", ", $sql_parts) . " WHERE id = ?";
            $types .= "i";
            $params_values[] = $user_id_wali_murid_saat_ini;

            $stmt = $conn->prepare($sql_update);
            if ($stmt) {
                if (!empty($types) && !empty($params_values)) {
                    $stmt->bind_param($types, ...$params_values);
                }

                if ($stmt->execute()) {
                    $_SESSION['flash_message_profil'] = ['type' => 'success', 'messages' => ['Profil berhasil diperbarui.']];
                    
                    if ($username_baru !== $username_wali_murid_saat_ini) {
                        $_SESSION['username'] = $username_baru;
                    }
                    if ($nama_baru !== $nama_wali_murid_saat_ini) {
                        $_SESSION['nama_user'] = $nama_baru;
                    }
                    header('Location: edit_profil.php?status=sukses'); // AKTIFKAN REDIRECT
                    exit; // PASTIKAN ADA EXIT SETELAH HEADER
                } else {
                    $_SESSION['flash_message_profil'] = ['type' => 'error', 'messages' => ['Gagal memperbarui profil: ' . htmlspecialchars($stmt->error)]];
                    header('Location: edit_profil.php'); // AKTIFKAN REDIRECT
                    exit; // PASTIKAN ADA EXIT SETELAH HEADER
                }
                $stmt->close();
            } else {
                $_SESSION['flash_message_profil'] = ['type' => 'error', 'messages' => ['Terjadi kesalahan sistem (prepare): ' . htmlspecialchars($conn->error)]];
                header('Location: edit_profil.php'); // AKTIFKAN REDIRECT
                exit; // PASTIKAN ADA EXIT SETELAH HEADER
            }
        } else {
            $_SESSION['flash_message_profil'] = ['type' => 'info', 'messages' => ['Tidak ada perubahan data yang disimpan.']];
            header('Location: edit_profil.php'); // AKTIFKAN REDIRECT
            exit; // PASTIKAN ADA EXIT SETELAH HEADER
        }
    }
} else {
    // Jika metode request bukan POST, kembalikan ke form edit profil dengan pesan error
    $_SESSION['flash_message_profil'] = ['type' => 'error', 'messages' => ['Akses tidak valid. Silakan gunakan form yang disediakan.']];
    header('Location: edit_profil.php'); // AKTIFKAN REDIRECT
    exit; // PASTIKAN ADA EXIT SETELAH HEADER
}

// Baris ini seharusnya tidak pernah tercapai jika logika di atas sudah benar
// Namun, sebagai pengaman, bisa tambahkan redirect default
header('Location: edit_profil.php');
exit;
?>