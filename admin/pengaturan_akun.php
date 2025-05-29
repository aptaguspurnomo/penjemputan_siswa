<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include '../includes/db.php';
include '../includes/header.php';

// Cek apakah pengguna adalah admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

// Ambil data admin saat ini
$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT username FROM users WHERE id = ? AND role = 'admin'");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    echo "<p class='text-red-500'>Akun admin tidak ditemukan!</p>";
    include '../includes/footer.php';
    exit;
}
$admin = $result->fetch_assoc();
$username = $admin['username'];
$stmt->close();

// Proses form pengaturan
if (isset($_POST['simpan'])) {
    $new_username = trim($_POST['username']);
    $new_password = trim($_POST['password']);
    $error = false;
    $success = false;

    // Validasi input
    if (empty($new_username)) {
        echo "<p class='text-red-500'>Username tidak boleh kosong!</p>";
        $error = true;
    }

    // Cek apakah username sudah digunakan (kecuali username sendiri)
    if (!$error) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $stmt->bind_param("si", $new_username, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            echo "<p class='text-red-500'>Username sudah digunakan!</p>";
            $error = true;
        }
        $stmt->close();
    }

    // Update username jika valid
    if (!$error && $new_username !== $username) {
        $stmt = $conn->prepare("UPDATE users SET username = ? WHERE id = ?");
        $stmt->bind_param("si", $new_username, $user_id);
        if ($stmt->execute()) {
            $username = $new_username; // Update tampilan
            $success = true;
        } else {
            echo "<p class='text-red-500'>Gagal memperbarui username: " . $stmt->error . "</p>";
            $error = true;
        }
        $stmt->close();
    }

    // Update password jika diisi
    if (!$error && !empty($new_password)) {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $hashed_password, $user_id);
        if ($stmt->execute()) {
            $success = true;
        } else {
            echo "<p class='text-red-500'>Gagal memperbarui password: " . $stmt->error . "</p>";
            $error = true;
        }
        $stmt->close();
    }

    if ($success && !$error) {
        echo "<p class='text-green-500'>Pengaturan akun berhasil diperbarui!</p>";
    }
}
?>

<h2 class="text-2xl font-bold mb-4"><i class="fas fa-user-cog"></i> Pengaturan Akun Admin</h2>

<form method="POST" class="mb-6 space-y-4 max-w-md">
    <div>
        <label for="username" class="block text-sm font-medium">Username</label>
        <input type="text" name="username" id="username" value="<?php echo htmlspecialchars($username); ?>" class="p-2 border rounded w-full" required>
    </div>
    <div>
        <label for="password" class="block text-sm font-medium">Password Baru (kosongkan jika tidak ingin mengubah)</label>
        <input type="password" name="password" id="password" placeholder="Masukkan password baru" class="p-2 border rounded w-full">
    </div>
    <button type="submit" name="simpan" class="bg-blue-600 text-white p-2 rounded hover:bg-blue-700"><i class="fas fa-save"></i> Simpan</button>
</form>

<?php include '../includes/footer.php'; ?>