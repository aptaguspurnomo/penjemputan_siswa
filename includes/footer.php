<?php
// footer.php

// Pastikan session dimulai jika belum (mungkin diperlukan untuk logika lain atau app_settings)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Logika untuk include db.php (LOGIKA ASLI ANDA, sedikit disesuaikan untuk robustness)
if (!isset($conn) || (isset($conn) && $conn instanceof mysqli && $conn->connect_error)) {
    // Tentukan path relatif ke db.php dari footer.php
    // Variabel $current_page_depth atau $is_root_page harus di-set oleh file yang meng-include footer.php
    // Jika tidak ada, kita coba tebak berdasarkan lokasi footer.php
    $is_footer_in_includes = (strpos(__FILE__, DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR) !== false);

    if ($is_footer_in_includes) { // Jika footer.php ada di folder 'includes'
        $path_to_db_footer = __DIR__ . '/db.php'; // db.php di folder yang sama ('includes')
        if (!file_exists($path_to_db_footer)) {
             $path_to_db_footer = __DIR__ . '/../db.php'; // db.php satu level di atas 'includes' (di root)
        }
    } else { // Jika footer.php ada di root
        $path_to_db_footer = __DIR__ . '/includes/db.php'; // db.php di folder 'includes'
    }

    if (file_exists($path_to_db_footer)) {
        include_once $path_to_db_footer;
    }
    // else {
    //     error_log("footer.php: db.php tidak ditemukan pada path yang dicoba.");
    // }
}

// 2. Inisialisasi default untuk teks dan warna footer
$footer_text_display = 'Aplikasi Penjemputan Siswa © ' . date('Y'); // Default text
$footer_actual_bg_style = ''; // Akan diisi jika warna dari DB
$footer_actual_text_style = ''; // Akan diisi jika warna dari DB
$footer_default_bg_class = 'bg-blue-600'; // Kelas Tailwind default dari HTML Anda
$footer_default_text_class = 'text-white'; // Kelas Tailwind default dari HTML Anda


// 3. Ambil data footer dari database
if (isset($conn) && $conn instanceof mysqli && empty($conn->connect_error)) {
    try {
        // Ambil footer_text DAN footer_color
        $query_footer = "SELECT footer_text, footer_color FROM pengaturan WHERE id = 1 LIMIT 1";
        $result_footer = $conn->query($query_footer);

        if ($result_footer && $result_footer->num_rows > 0) {
            $footer_db_data = $result_footer->fetch_assoc();

            // Terapkan footer_text dari DB jika ada
            if (isset($footer_db_data['footer_text']) && !empty(trim($footer_db_data['footer_text']))) {
                $footer_text_display = $footer_db_data['footer_text'];
            }

            // --- BARU: Terapkan footer_color dari DB ---
            if (isset($footer_db_data['footer_color']) && !empty($footer_db_data['footer_color']) && preg_match('/^#[a-f0-9]{6}$/i', $footer_db_data['footer_color'])) {
                $db_footer_color_val = $footer_db_data['footer_color'];
                $footer_actual_bg_style = "style=\"background-color: " . htmlspecialchars($db_footer_color_val) . ";\"";

                // Fungsi untuk menentukan kontras (bisa di-share dengan header jika diletakkan di file functions.php)
                if (!function_exists('is_dark_color_for_footer')) { // Nama unik
                    function is_dark_color_for_footer($hexcolor){
                        if (empty($hexcolor) || strlen(ltrim($hexcolor, '#')) !== 6) return true;
                        $hexcolor = ltrim($hexcolor, '#');
                        $r = hexdec(substr($hexcolor,0,2)); $g = hexdec(substr($hexcolor,2,2)); $b = hexdec(substr($hexcolor,4,2));
                        return ((($r*299)+($g*587)+($b*114))/1000 <= 128);
                    }
                }
                $footer_text_color_val = is_dark_color_for_footer($db_footer_color_val) ? '#FFFFFF' : '#1F2937'; // Putih atau abu gelap
                $footer_actual_text_style = "style=\"color: " . htmlspecialchars($footer_text_color_val) . ";\"";

                // Kosongkan kelas default jika style dari DB diterapkan
                $footer_default_bg_class = '';
                $footer_default_text_class = '';
            }
        }
        if ($result_footer) {
            $result_footer->free();
        }
    } catch (Exception $e) {
        error_log("Error fetching footer data from DB in footer.php: " . $e->getMessage());
    }
} else {
    // error_log("footer.php: Database connection not available. Using default footer.");
}

// Komentar asli Anda tentang </main> tetap relevan:
// The main content would have been output before this file is included.
// The closing </main> tag should ideally be in the file that includes this footer,
// or in the main layout file that opened it.
// Pastikan </main> ada SEBELUM <footer> jika struktur Anda: <body> <header> <main> ... </main> <footer> ... </footer> </body>

?>
    <!-- Pastikan tag </main> sudah ditutup di file yang memanggil footer ini, jika ada -->
    <!-- atau jika struktur halamannya adalah <body> <header> <div class="content-wrapper"> ... </div> <footer> -->

    <footer class="p-4 mt-6 w-full <?php echo $footer_default_bg_class; ?> <?php echo $footer_default_text_class; ?>" <?php echo $footer_actual_bg_style; ?> <?php echo $footer_actual_text_style; ?>>
    <!-- Catatan: Kelas w-screen, relative, left-1/2, -translate-x-1/2 mungkin tidak diperlukan jika footer sudah 100% width dan container di dalamnya yang mx-auto.
         Biasanya footer cukup w-full. Jika Anda ingin efek khusus, biarkan. -->
        <div class="container mx-auto text-center">
            <marquee behavior="scroll" direction="left" scrollamount="5">
                <?php echo htmlspecialchars($footer_text_display); ?>
            </marquee>
        </div>
    </footer>
<?php
// Penutupan koneksi DB bisa dilakukan di sini jika ini adalah akhir dari script
// atau jika tidak ada file lain yang di-include setelah footer yang butuh koneksi.
// if (isset($conn) && $conn instanceof mysqli && empty($conn->connect_error)) {
//     $conn->close();
// }
?>
</body> <!-- Pastikan tag body dan html ditutup di file utama yang meng-include ini, atau di sini jika ini akhir dari segalanya -->
</html>