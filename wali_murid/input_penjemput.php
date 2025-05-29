<?php include '../includes/header.php'; ?>
<h2 class="text-2xl font-bold mb-4"><i class="fas fa-user-plus"></i> Input Nama Penjemput</h2>
<form method="POST" class="space-y-4">
    <select name="siswa_id" class="p-2 border rounded" required>
        <?php
        $wali_murid_id = $_SESSION['user_id'];
        $query = "SELECT id, nama_siswa FROM siswa WHERE id_wali_murid = $wali_murid_id";
        $result = $conn->query($query);
        while ($row = $result->fetch_assoc()) {
            echo "<option value='{$row['id']}'>{$row['nama_siswa']}</option>";
        }
        ?>
    </select>
    <input type="text" name="nama_penjemput" placeholder="Nama Penjemput" class="p-2 border rounded" required>
    <button type="submit" class="bg-blue-600 text-white p-2 rounded hover:bg-blue-700"><i class="fas fa-save"></i> Simpan</button>
</form>
<?php
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $siswa_id = $_POST['siswa_id'];
    $nama_penjemput = $_POST['nama_penjemput'];
    $query = "INSERT INTO status_penjemputan (siswa_id, nama_penjemput, waktu_penjemputan, status) 
              VALUES ($siswa_id, '$nama_penjemput', NOW(), 'perjalanan_jemput') 
              ON DUPLICATE KEY UPDATE nama_penjemput='$nama_penjemput', waktu_penjemputan=NOW()";
    $conn->query($query);
    echo "<p class='text-green-500'>Data penjemput berhasil disimpan!</p>";
}
?>
<?php include '../includes/footer.php'; ?>