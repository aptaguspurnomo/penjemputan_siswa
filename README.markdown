# Aplikasi Penjemputan Siswa

Aplikasi berbasis web untuk mengelola penjemputan siswa, dibuat dengan PHP, MySQL, dan Tailwind CSS. Aplikasi ini mendukung tiga jenis pengguna: Admin, Wali Kelas, dan Wali Murid.

## Fitur
- **Admin**: Mengelola logo, header, footer, kelas, siswa, guru, dan status penjemputan.
- **Wali Kelas**: Melihat daftar siswa, mengubah status kehadiran, dan mengunduh data siswa dalam format Excel.
- **Wali Murid**: Mengisi nama penjemput, melihat info anak, dan memperbarui status penjemputan.
- Desain responsif untuk perangkat mobile dan desktop.

## Prasyarat
- XAMPP (Apache dan MySQL)
- PHP >= 7.4
- Composer (untuk PhpSpreadsheet)
- Browser modern (Chrome, Firefox, dll.)

## Instalasi
1. **Clone Repository**:
   ```bash
   git clone https://github.com/username/penjemputan_siswa.git
   cd penjemputan_siswa
   ```

2. **Siapkan XAMPP**:
   - Pastikan XAMPP terinstal dan jalankan Apache serta MySQL.
   - Salin folder proyek ke `C:\xampp\htdocs\penjemputan_siswa`.

3. **Buat Database**:
   - Buka `http://localhost/phpmyadmin`.
   - Impor file `database.sql` untuk membuat database dan tabel.

4. **Instal Dependensi**:
   - Untuk fitur upload Excel, instal PhpSpreadsheet:
     ```bash
     composer require phpoffice/phpspreadsheet
     ```

5. **Konfigurasi**:
   - Pastikan folder `assets/uploads` memiliki izin tulis (chmod 777 pada Linux).
   - Letakkan gambar default (`default_logo.png` dan `header.jpg`) di `assets/uploads`.

6. **Jalankan Aplikasi**:
   - Akses `http://localhost/penjemputan_siswa` di browser.
   - Login dengan:
     - Admin: username `admin`, password `admin`.
     - Tambahkan akun wali kelas/wali murid melalui halaman admin.

## Catatan
- Ganti MD5 dengan `password_hash` untuk keamanan di produksi.
- Tambahkan Font Awesome untuk ikon: `<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">`.
- Warna utama: biru (#2563EB), putih, dan abu-abu (#E5E7EB).

## Kontribusi
Silakan buat *pull request* untuk perbaikan atau fitur tambahan.

## Lisensi
MIT License