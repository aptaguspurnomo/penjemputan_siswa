<?php
$host = 'localhost';
$db = 'mitm6294_penjemputan_siswa';
$user = 'root';
$pass = '';

$conn = new mysqli($host, $user, $pass, $db);

if ($conn->connect_error) {
    die("Koneksi gagal: " . $conn->connect_error);
}
echo "";
?>
