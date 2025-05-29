<?php include '../includes/header.php'; ?>
<h2 class="text-2xl font-bold mb-4"><i class="fas fa-check-circle"></i> Status Kehadiran</h2>
<table class="w-full border-collapse border">
    <thead>
        <tr class="bg-gray-200">
            <th class="border p-2">Nama Siswa</th>
            <th class="border p-2">Status</th>
            <th class="border p-2">Aksi</th>
        </tr>
    </thead>
    <tbody>
        <?php
        $kelas_id = $_SESSION['kelas_id'];
        $query = "SELECT s.id, s.nama_siswa, sp.status 
                  FROM siswa s 
                  LEFT JOIN status_penjemputan sp ON s.id = sp.siswa_id 
                  WHERE s.kelas_id = $kelas_id";
        $result = $conn->query($query);
        while ($row = $result->fetch_assoc()) {
            echo "<tr>
                    <td class='border p-2'>{$row['nama_siswa']}</td>
                    <td class='border p-2'>" . ($row['status'] ?? '-') . "</td>
                    <td class='border p-2'>
                        <form method='POST'>
                            <input type='hidden' name='siswa_id' value='{$row['id']}'>
                            <select name='status' class='p-2 border rounded'>
                                <option value='masuk'>Masuk</option>
                                <option value='tidak_masuk'>Tidak Masuk</option>
                            </select>
                            <button type='submit' class='bg-blue-600 text-white p-2 rounded hover:bg-blue-700'><i class='fas fa-save'></i> Update</button>
                        </form>
                    </td>
                  </tr>";
        }
        ?>
    </tbody>
</table>
<?php
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $siswa_id = $_POST['siswa_id'];
    $status = $_POST['status'];
    $query = "INSERT INTO status_penjemputan (siswa_id, status, waktu_penjemputan) 
              VALUES ($siswa_id, '$status', NOW()) 
              ON DUPLICATE KEY UPDATE status='$status', waktu_penjemputan=NOW()";
    $conn->query($query);
    header('Location: status_kehadiran.php');
}
?>
<?php include '../includes/footer.php'; ?>