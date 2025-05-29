<?php
// 1. Mulai session dan include koneksi database (SEBELUM APAPUN)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include '../includes/db.php'; // Pastikan $conn diinisialisasi di sini

// 2. Cek otentikasi dan otorisasi
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

// 3. Inisialisasi variabel
$message = '';
// GANTI DEFAULT PASSWORD DI SINI
$DEFAULT_WALI_PASSWORD_FALLBACK_EDIT = 'walmur123'; // <<< PERUBAHAN DI SINI
$siswa_id_to_edit = null;
$siswa_data = null;
$wali_data = null;

// --- Fungsi Bantu untuk Hashing Password ---
function hashPasswordEdit($password) {
    // return password_hash($password, PASSWORD_DEFAULT); // Idealnya
    return MD5($password); // TIDAK AMAN, sesuaikan dengan sistem Anda jika sudah pakai password_hash
}
// -------------------------------------------

// ... (Sisa kode PHP untuk logika pengambilan data GET dan pemrosesan POST tetap sama seperti sebelumnya) ...
// Pastikan semua logika ada di sini sebelum include header.php

// 4. Logika Pengambilan Data Awal (jika metode GET)
if (isset($_GET['id']) && ctype_digit((string)$_GET['id'])) {
    $siswa_id_to_edit = (int)$_GET['id'];
    // Ambil data siswa dan wali murid terkait
    $stmt_get_data = $conn->prepare(
        "SELECT s.nama_siswa, s.kelas_id, 
                u.id AS wali_id, u.username AS username_wali, u.nama AS nama_wali
         FROM siswa s
         LEFT JOIN users u ON s.id_wali_murid = u.id
         WHERE s.id = ?"
    );
    if ($stmt_get_data) {
        $stmt_get_data->bind_param("i", $siswa_id_to_edit);
        $stmt_get_data->execute();
        $result_get_data = $stmt_get_data->get_result();
        if ($result_get_data->num_rows === 1) {
            $data_edit = $result_get_data->fetch_assoc();
            $siswa_data = [
                'nama_siswa' => $data_edit['nama_siswa'],
                'kelas_id' => $data_edit['kelas_id']
            ];
            if ($data_edit['wali_id']) {
                $wali_data = [
                    'id' => $data_edit['wali_id'],
                    'username' => $data_edit['username_wali'],
                    'nama' => $data_edit['nama_wali']
                ];
            }
        } else {
            $message = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Siswa tidak ditemukan.</p>";
            $siswa_id_to_edit = null;
        }
        $stmt_get_data->close();
    } else {
        $message = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Error mengambil data siswa: " . htmlspecialchars($conn->error) . "</p>";
        $siswa_id_to_edit = null;
    }
} elseif (!isset($_POST['update_siswa'])) { 
    $message = "<p class='text-yellow-500 p-3 bg-yellow-100 border border-yellow-400 rounded'>ID Siswa tidak valid untuk diedit.</p>";
}


// 5. Logika Pemrosesan Form Update (jika metode POST)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_siswa']) && isset($_POST['siswa_id_hidden']) && ctype_digit((string)$_POST['siswa_id_hidden'])) {
    $siswa_id_to_edit = (int)$_POST['siswa_id_hidden']; 

    $nama_siswa_update = isset($_POST['nama_siswa']) ? trim($_POST['nama_siswa']) : '';
    $kelas_id_update = isset($_POST['kelas_id']) ? (int)$_POST['kelas_id'] : 0;
    $username_wali_update = isset($_POST['username_wali_murid']) ? trim($_POST['username_wali_murid']) : '';
    $nama_wali_update = isset($_POST['nama_wali_murid_lengkap']) ? trim($_POST['nama_wali_murid_lengkap']) : '';
    $password_wali_update_input = isset($_POST['password_wali_murid']) ? $_POST['password_wali_murid'] : '';

    if (empty($nama_siswa_update) || $kelas_id_update <= 0 || empty($username_wali_update) || empty($nama_wali_update)) {
        $message = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Semua field siswa dan wali harus diisi!</p>";
        $siswa_data = ['nama_siswa' => $nama_siswa_update, 'kelas_id' => $kelas_id_update];
        $wali_data = ['username' => $username_wali_update, 'nama' => $nama_wali_update];
    } else {
        $conn->begin_transaction();
        try {
            $id_wali_murid_for_siswa = null;
            $pesan_wali_tambahan = '';

            $stmt_cek_wali_upd = $conn->prepare("SELECT id, role FROM users WHERE username = ?");
            if (!$stmt_cek_wali_upd) throw new Exception("Error prepare (cek wali upd): " . $conn->error);
            $stmt_cek_wali_upd->bind_param("s", $username_wali_update);
            $stmt_cek_wali_upd->execute();
            $res_cek_wali_upd = $stmt_cek_wali_upd->get_result();

            if ($res_cek_wali_upd->num_rows > 0) { 
                $existing_wali_upd = $res_cek_wali_upd->fetch_assoc();
                if ($existing_wali_upd['role'] === 'wali_murid') {
                    $id_wali_murid_for_siswa = $existing_wali_upd['id'];
                    
                    $sql_update_wali = "UPDATE users SET nama = ?";
                    $params_update_wali_bind = [$nama_wali_update];
                    $types_update_wali_bind = "s";

                    if (!empty($password_wali_update_input)) {
                        $sql_update_wali .= ", password = ?";
                        $params_update_wali_bind[] = hashPasswordEdit($password_wali_update_input);
                        $types_update_wali_bind .= "s";
                        $pesan_wali_tambahan = " Password wali juga diperbarui.";
                    }
                    $sql_update_wali .= " WHERE id = ?";
                    $params_update_wali_bind[] = $id_wali_murid_for_siswa;
                    $types_update_wali_bind .= "i";

                    $stmt_update_wali_data = $conn->prepare($sql_update_wali);
                    if (!$stmt_update_wali_data) throw new Exception("Error prepare (update wali data): " . $conn->error);
                    $stmt_update_wali_data->bind_param($types_update_wali_bind, ...$params_update_wali_bind);
                    if (!$stmt_update_wali_data->execute()) throw new Exception("Gagal update data wali: " . $stmt_update_wali_data->error);
                    $stmt_update_wali_data->close();
                } else {
                    throw new Exception("Username Wali '<strong>" . htmlspecialchars($username_wali_update) . "</strong>' sudah ada tapi bukan wali murid.");
                }
            } else { 
                $pass_to_hash_new = !empty($password_wali_update_input) ? $password_wali_update_input : $DEFAULT_WALI_PASSWORD_FALLBACK_EDIT; // Menggunakan variabel yang sudah diubah
                $hashed_pass_new = hashPasswordEdit($pass_to_hash_new);
                $role_wali_new = 'wali_murid';

                $stmt_create_wali_new = $conn->prepare("INSERT INTO users (username, password, role, nama) VALUES (?, ?, ?, ?)");
                if (!$stmt_create_wali_new) throw new Exception("Error prepare (create wali new): " . $conn->error);
                $stmt_create_wali_new->bind_param("ssss", $username_wali_update, $hashed_pass_new, $role_wali_new, $nama_wali_update);
                if (!$stmt_create_wali_new->execute()) throw new Exception("Gagal membuat wali baru: " . $stmt_create_wali_new->error);
                $id_wali_murid_for_siswa = $stmt_create_wali_new->insert_id;
                $stmt_create_wali_new->close();
                $pesan_wali_tambahan = " Akun wali murid baru '<strong>" . htmlspecialchars($username_wali_update) . "</strong>' dibuat.";
                if (empty($password_wali_update_input)) $pesan_wali_tambahan .= " dengan password default.";
            }
            $stmt_cek_wali_upd->close();

            $stmt_update_siswa_data = $conn->prepare("UPDATE siswa SET nama_siswa = ?, kelas_id = ?, id_wali_murid = ? WHERE id = ?");
            if (!$stmt_update_siswa_data) throw new Exception("Error prepare (update siswa): " . $conn->error);
            
            $stmt_update_siswa_data->bind_param("siii", $nama_siswa_update, $kelas_id_update, $id_wali_murid_for_siswa, $siswa_id_to_edit);
            if (!$stmt_update_siswa_data->execute()) throw new Exception("Gagal update data siswa: " . $stmt_update_siswa_data->error);
            $stmt_update_siswa_data->close();

            $conn->commit();
            $_SESSION['flash_message_manage_siswa'] = "<p class='text-green-500 p-3 bg-green-100 border border-green-400 rounded'>Data siswa '<strong>" . htmlspecialchars($nama_siswa_update) . "</strong>' berhasil diperbarui." . $pesan_wali_tambahan . "</p>";
            
            $redirect_back_to = 'manage_siswa.php'; 
            if (isset($_POST['ref_page']) && $_POST['ref_page'] === 'status_siswa') {
                $redirect_back_to = 'status_siswa.php';
                if (isset($_POST['ref_kelas_id']) && ctype_digit((string)$_POST['ref_kelas_id'])) {
                    $redirect_back_to .= '?kelas_id=' . (int)$_POST['ref_kelas_id'];
                }
            } elseif (isset($_POST['filter_kelas_id_asal']) && ctype_digit((string)$_POST['filter_kelas_id_asal'])) { 
                $redirect_back_to = 'manage_siswa.php?filter_kelas_id=' . (int)$_POST['filter_kelas_id_asal'];
            }

            header("Location: " . $redirect_back_to);
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            $message = "<p class='text-red-500 p-3 bg-red-100 border border-red-400 rounded'>Update Gagal: " . htmlspecialchars($e->getMessage()) . "</p>";
            $siswa_data = ['nama_siswa' => $nama_siswa_update, 'kelas_id' => $kelas_id_update];
            $wali_data = ['username' => $username_wali_update, 'nama' => $nama_wali_update];
        }
    }
}


// 6. SEKARANG BARU INCLUDE header.php
$assets_path_prefix = '../';
include '../includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <h2 class="text-2xl font-bold mb-6 text-gray-800"><i class="fas fa-user-edit mr-2"></i> Edit Data Siswa & Wali Murid</h2>

    <?php
    $link_kembali = 'manage_siswa.php';
    if (isset($_GET['ref_page']) && $_GET['ref_page'] === 'status_siswa') {
        $link_kembali = 'status_siswa.php';
        if (isset($_GET['ref_kelas_id']) && ctype_digit((string)$_GET['ref_kelas_id'])) {
            $link_kembali .= '?kelas_id=' . (int)$_GET['ref_kelas_id'];
        }
    } elseif (isset($_GET['filter_kelas_id_asal']) && ctype_digit((string)$_GET['filter_kelas_id_asal'])) {
         $link_kembali = 'manage_siswa.php?filter_kelas_id=' . (int)$_GET['filter_kelas_id_asal'];
    }
    ?>
    <a href="<?php echo $link_kembali; ?>" class="mb-4 inline-block text-indigo-600 hover:text-indigo-800">
        <i class="fas fa-arrow-left mr-2"></i> Kembali
    </a>

    <?php if (!empty($message)): ?>
        <div class="mb-4">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <?php if ($siswa_id_to_edit !== null && $siswa_data): ?>
    <form method="POST" action="edit_siswa.php?id=<?php echo htmlspecialchars($siswa_id_to_edit); 
        if (isset($_GET['ref_page'])) echo '&ref_page=' . htmlspecialchars($_GET['ref_page']);
        if (isset($_GET['ref_kelas_id'])) echo '&ref_kelas_id=' . htmlspecialchars($_GET['ref_kelas_id']);
        if (isset($_GET['filter_kelas_id_asal'])) echo '&filter_kelas_id_asal=' . htmlspecialchars($_GET['filter_kelas_id_asal']);
    ?>" class="bg-white shadow-md rounded-lg p-6 space-y-6">
        <input type="hidden" name="update_siswa" value="1">
        <input type="hidden" name="siswa_id_hidden" value="<?php echo htmlspecialchars($siswa_id_to_edit); ?>">
        <?php if (isset($_GET['ref_page'])): ?>
            <input type="hidden" name="ref_page" value="<?php echo htmlspecialchars($_GET['ref_page']); ?>">
        <?php endif; ?>
        <?php if (isset($_GET['ref_kelas_id'])): ?>
            <input type="hidden" name="ref_kelas_id" value="<?php echo htmlspecialchars($_GET['ref_kelas_id']); ?>">
        <?php endif; ?>
         <?php if (isset($_GET['filter_kelas_id_asal'])): ?>
            <input type="hidden" name="filter_kelas_id_asal" value="<?php echo htmlspecialchars($_GET['filter_kelas_id_asal']); ?>">
        <?php endif; ?>

        <fieldset class="border border-gray-300 p-4 rounded-md">
            <legend class="text-lg font-medium text-gray-700 px-2">Data Siswa (ID: <?php echo htmlspecialchars($siswa_id_to_edit); ?>)</legend>
            <div class="space-y-4 mt-2">
                <div>
                    <label for="nama_siswa_edit" class="block text-sm font-medium text-gray-700">Nama Lengkap Siswa</label>
                    <input type="text" name="nama_siswa" id="nama_siswa_edit" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required 
                           value="<?php echo htmlspecialchars($siswa_data['nama_siswa'] ?? (isset($_POST['nama_siswa']) && $message ? $_POST['nama_siswa'] : '')); ?>">
                </div>
                <div>
                    <label for="kelas_id_edit" class="block text-sm font-medium text-gray-700">Kelas</label>
                    <select name="kelas_id" id="kelas_id_edit" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required>
                        <option value="">Pilih Kelas</option>
                        <?php
                        $selected_kelas_val_edit = $siswa_data['kelas_id'] ?? (isset($_POST['kelas_id']) && $message ? (int)$_POST['kelas_id'] : 0);
                        $query_kelas_edit = "SELECT id, nama_kelas FROM kelas ORDER BY nama_kelas";
                        $result_kelas_edit_opts = $conn->query($query_kelas_edit);
                        if ($result_kelas_edit_opts && $result_kelas_edit_opts->num_rows > 0) {
                            while ($row_kelas_edit = $result_kelas_edit_opts->fetch_assoc()) {
                                $selected = ($row_kelas_edit['id'] == $selected_kelas_val_edit) ? 'selected' : '';
                                echo "<option value='{$row_kelas_edit['id']}' $selected>" . htmlspecialchars($row_kelas_edit['nama_kelas']) . "</option>";
                            }
                            $result_kelas_edit_opts->free();
                        }
                        ?>
                    </select>
                </div>
            </div>
        </fieldset>

        <fieldset class="border border-gray-300 p-4 rounded-md">
            <legend class="text-lg font-medium text-gray-700 px-2">Data Wali Murid Terkait</legend>
            <p class="text-xs text-gray-500 mb-2">Anda bisa mengubah username wali (akan membuat wali baru jika belum ada) atau memperbarui data wali yang sudah ada.</p>
            <div class="space-y-4 mt-2">
                <div>
                    <label for="username_wali_murid_edit" class="block text-sm font-medium text-gray-700">Username Wali Murid</label>
                    <input type="text" name="username_wali_murid" id="username_wali_murid_edit" placeholder="Username Wali Murid" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required 
                           value="<?php echo htmlspecialchars($wali_data['username'] ?? (isset($_POST['username_wali_murid']) && $message ? $_POST['username_wali_murid'] : '')); ?>">
                </div>
                <div>
                    <label for="nama_wali_murid_lengkap_edit" class="block text-sm font-medium text-gray-700">Nama Lengkap Wali Murid</label>
                    <input type="text" name="nama_wali_murid_lengkap" id="nama_wali_murid_lengkap_edit" placeholder="Nama Lengkap Wali Murid" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" required 
                           value="<?php echo htmlspecialchars($wali_data['nama'] ?? (isset($_POST['nama_wali_murid_lengkap']) && $message ? $_POST['nama_wali_murid_lengkap'] : '')); ?>">
                </div>
                <div>
                    <label for="password_wali_murid_edit" class="block text-sm font-medium text-gray-700">Password Baru Wali Murid (Opsional)</label>
                    <input type="password" name="password_wali_murid" id="password_wali_murid_edit" placeholder="Kosongkan jika tidak ingin mengubah password" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                    <p class="mt-1 text-xs text-gray-500">Jika username wali baru dan password dikosongkan, password default (<strong><?php echo htmlspecialchars($DEFAULT_WALI_PASSWORD_FALLBACK_EDIT);?></strong>) akan digunakan.</p>
                </div>
            </div>
        </fieldset>
        <button type="submit" name="update_siswa_btn" class="w-full md:w-auto inline-flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
            <i class="fas fa-save mr-2"></i> Update Data
        </button>
    </form>
    <?php elseif (empty($message) && !isset($_GET['id']) && !isset($_POST['update_siswa'])): ?>
        <p class='text-yellow-500 p-3 bg-yellow-100 border border-yellow-400 rounded'>Silakan pilih siswa dari halaman manajemen untuk diedit.</p>
    <?php endif; ?>
</div>

<?php
// 7. Include footer.php dan TUTUP KONEKSI di akhir
include '../includes/footer.php';
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>