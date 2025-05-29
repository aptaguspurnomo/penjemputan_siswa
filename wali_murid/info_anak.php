<?php include '../includes/header.php'; ?>
<h2 class="text-2xl font-bold mb-4"><i class="fas fa-info-circle"></i> Info Anak</h2>
<table class="w-full border-collapse border">
    <thead>
        <tr class="bg-gray-200">
            <th class="border p-2">Nama Siswa</th>
            <th class="border p-2">Kelas</th>
            <th class="border p-2">Status</th>
            <th class="border p-2">Waktu</th>
            <th class="border p-2">Aksi</th>
        </tr>
    </thead>
    <tbody>
        <?php
        $wali_murid_id = $_SESSION['user_id'];
        $query = "SELECT s.id, s.nama_siswa, k.nama_kelas, sp.status, sp.waktu_penjemputan 
                  FROM siswa s 
                  JOIN kelas k ON s.kelas_id = k.id 
                  LEFT JOIN status_penjemputan sp ON s.id = sp.siswa_id 
                  WHERE s.id_wali_murid = $wali_murid_id";
        $result = $conn->query($query);
        while ($row = $result->fetch_assoc()) {
            echo "<tr>
                    <td class='border p-2'>{$row['nama_siswa']}</td>
                    <td class='border p-2'>{$row['nama_kelas']}</td>
                    <td class='border p-2'>" . ($row['status'] ?? '-') . "</td>
                    <td class='border p-2'>" . ($row['waktu_penjemputan'] ?? '-') . "</td>
                    <td class='border p-2'>
                        <form method='POST'>
                            <input type='hidden' name='siswa_id' value='{$row['id']}'>
                            <select name='status' class='p-2 border rounded'>
                                <option value='perjalanan_jemput'>Perjalanan Jemput</option>
                                <option value='lima_menit_sampai'>5 Menit Lagi Sampai</option>
                                <option value='sudah_sampai_sekolah'>Sudah Sampai Sekolah</option>
                                <option value='sudah_dijemput'>Sudah Dijemput</option>
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
    $query = "UPDATE status_penjemputan SET status='$status', waktu_penjemputan=NOW() 
              WHERE siswa_id=$siswa_id";
    $conn->query($query);
    header('Location: info_anak.php');
}
?>
<?php include '../includes/footer.php'; ?>