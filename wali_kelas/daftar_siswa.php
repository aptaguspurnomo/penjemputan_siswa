<?php include '../includes/header.php'; ?>
<h2 class="text-2xl font-bold mb-4"><i class="fas fa-users"></i> Daftar Siswa</h2>
<table class="w-full border-collapse border">
    <thead>
        <tr class="bg-gray-200">
            <th class="border p-2">Nama Siswa</th>
            <th class="border p-2">Kelas</th>
            <th class="border p-2">Status</th>
            <th class="border p-2">Penjemput</th>
            <th class="border p-2">Waktu</th>
        </tr>
    </thead>
    <tbody>
        <?php
        $kelas_id = $_SESSION['kelas_id'];
        $query = "SELECT s.nama_siswa, k.nama_kelas, sp.status, sp.nama_penjemput, sp.waktu_penjemputan 
                  FROM siswa s 
                  JOIN kelas k ON s.kelas_id = k.id 
                  LEFT JOIN status_penjemputan sp ON s.id = sp.siswa_id 
                  WHERE s.kelas_id = $kelas_id";
        $result = $conn->query($query);
        while ($row = $result->fetch_assoc()) {
            echo "<tr>
                    <td class='border p-2'>{$row['nama_siswa']}</td>
                    <td class='border p-2'>{$row['nama_kelas']}</td>
                    <td class='border p-2'>" . ($row['status'] ?? '-') . "</td>
                    <td class='border p-2'>" . ($row['nama_penjemput'] ?? '-') . "</td>
                    <td class='border p-2'>" . ($row['waktu_penjemputan'] ?? '-') . "</td>
                  </tr>";
        }
        ?>
    </tbody>
</table>
<a href="../download_excel.php" class="bg-blue-600 text-white p-2 rounded hover:bg-blue-700 mt-4 inline-block"><i class="fas fa-download"></i> Download Data (Excel)</a>
<?php include '../includes/footer.php'; ?>