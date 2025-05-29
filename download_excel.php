<?php
session_start();
include 'includes/db.php';
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if ($_SESSION['role'] != 'wali_kelas') {
    header('Location: login.php');
    exit;
}

$kelas_id = $_SESSION['kelas_id'];
$query = "SELECT s.nama_siswa, k.nama_kelas, sp.status, sp.nama_penjemput, sp.waktu_penjemputan 
          FROM siswa s 
          JOIN kelas k ON s.kelas_id = k.id 
          LEFT JOIN status_penjemputan sp ON s.id = sp.siswa_id 
          WHERE s.kelas_id = $kelas_id";
$result = $conn->query($query);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setCellValue('A1', 'Nama Siswa');
$sheet->setCellValue('B1', 'Kelas');
$sheet->setCellValue('C1', 'Status');
$sheet->setCellValue('D1', 'Nama Penjemput');
$sheet->setCellValue('E1', 'Waktu Penjemputan');

$rowNumber = 2;
while ($row = $result->fetch_assoc()) {
    $sheet->setCellValue('A' . $rowNumber, $row['nama_siswa']);
    $sheet->setCellValue('B' . $rowNumber, $row['nama_kelas']);
    $sheet->setCellValue('C' . $rowNumber, $row['status'] ?? '-');
    $sheet->setCellValue('D' . $rowNumber, $row['nama_penjemput'] ?? '-');
    $sheet->setCellValue('E' . $rowNumber, $row['waktu_penjemputan'] ?? '-');
    $rowNumber++;
}

$writer = new Xlsx($spreadsheet);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="daftar_siswa.xlsx"');
header('Cache-Control: max-age=0');
$writer->save('php://output');
exit;
?>