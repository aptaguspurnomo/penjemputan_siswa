<?php
// AKTIFKAN INI UNTUK DEBUGGING JIKA ADA ERROR 500 (OPSIONAL, HAPUS DI PRODUKSI)
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$is_root_page = (isset($current_page_depth) && $current_page_depth === 'root');

if (!isset($conn)) {
    $path_to_db = $is_root_page ? './includes/db.php' : '../includes/db.php';
    if ($is_root_page && !file_exists($path_to_db) && file_exists('./includes/db.php')) {
        $path_to_db = './includes/db.php';
    } elseif (!$is_root_page && !file_exists($path_to_db) && file_exists(__DIR__ . '/db.php')) {
         $path_to_db = __DIR__ . '/db.php';
    }
    if (file_exists($path_to_db)) {
        include_once $path_to_db;
    }
}

$nama_sekolah_display = 'Aplikasi Penjemputan Siswa';
$logo_src_default_base = 'assets/images/default_logo.png';
$assets_css_base = 'assets/css/style.css';
$base_path_for_includes = $is_root_page ? './' : '../';

$logo_src_default = $is_root_page ? $logo_src_default_base : '../' . $logo_src_default_base;
$assets_css_path = $is_root_page ? $assets_css_base : '../' . $assets_css_base;

if (isset($assets_path_prefix)) {
    $assets_path_prefix_normalized = rtrim($assets_path_prefix, '/') . '/';
    $assets_css_path = $assets_path_prefix_normalized . ltrim($assets_css_base, './');
    $logo_src_default = $assets_path_prefix_normalized . ltrim($logo_src_default_base, './');
}

$logo_src_to_use = $logo_src_default;

// --- Inisialisasi variabel warna ---
$header_style_attribute = ''; // Akan diisi jika warna dari DB
$header_text_style_attribute = ''; // Untuk warna teks header
$mobile_menu_style_attribute = ''; // Untuk background mobile menu
$mobile_submenu_style_attribute = ''; // Untuk background mobile submenu

// Default CSS classes (jika tidak ada warna dari DB)
$header_default_bg_class = 'bg-indigo-600';
$header_default_text_class = 'text-white';
$mobile_menu_default_bg_class = 'bg-indigo-700';


// Default link hover/active (jika tidak ada warna dari DB)
$link_hover_bg_css = 'rgba(255, 255, 255, 0.1)';
$link_active_bg_css = 'rgba(255, 255, 255, 0.2)';

if (isset($conn) && $conn) {
    $query_pengaturan = "SELECT nama_sekolah, logo_sekolah, header_color, footer_color FROM pengaturan WHERE id = 1 LIMIT 1";
    $result_pengaturan = $conn->query($query_pengaturan);
    if ($result_pengaturan && $result_pengaturan->num_rows > 0) {
        $pengaturan_db = $result_pengaturan->fetch_assoc();
        if (!empty($pengaturan_db['nama_sekolah'])) {
            $nama_sekolah_display = $pengaturan_db['nama_sekolah'];
        }
        if (!empty($pengaturan_db['logo_sekolah'])) {
            $path_from_db = ltrim($pengaturan_db['logo_sekolah'], '/');
            $potential_logo_src = $is_root_page ? $path_from_db : '../' . $path_from_db;
            $check_path_logo_db_server_root = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/' . $path_from_db;
            if (file_exists($check_path_logo_db_server_root)) {
                $logo_src_to_use = $potential_logo_src;
            }
        }

        if (!empty($pengaturan_db['header_color']) && preg_match('/^#[a-f0-9]{6}$/i', $pengaturan_db['header_color'])) {
            $db_header_color_val = $pengaturan_db['header_color'];
            $header_style_attribute = "style=\"background-color: " . htmlspecialchars($db_header_color_val) . ";\"";

            if (!function_exists('is_dark_color_for_header_v2')) { // Nama fungsi unik
                function is_dark_color_for_header_v2($hexcolor){
                    if (empty($hexcolor) || strlen(ltrim($hexcolor, '#')) !== 6) return true;
                    $hexcolor = ltrim($hexcolor, '#');
                    $r = hexdec(substr($hexcolor,0,2)); $g = hexdec(substr($hexcolor,2,2)); $b = hexdec(substr($hexcolor,4,2));
                    return ((($r*299)+($g*587)+($b*114))/1000 <= 128);
                }
            }
            $header_text_color_val = is_dark_color_for_header_v2($db_header_color_val) ? '#FFFFFF' : '#1F2937'; // Putih atau abu gelap
            $header_text_style_attribute = "style=\"color: " . htmlspecialchars($header_text_color_val) . ";\"";

            // Update link hover/active berdasarkan warna teks dan background header
            $link_hover_bg_css = is_dark_color_for_header_v2($db_header_color_val) ? 'rgba(255, 255, 255, 0.15)' : 'rgba(0, 0, 0, 0.05)';
            $link_active_bg_css = is_dark_color_for_header_v2($db_header_color_val) ? 'rgba(255, 255, 255, 0.25)' : 'rgba(0, 0, 0, 0.1)';

            if (!function_exists('adjust_brightness_v2')) { // Nama fungsi unik
                function adjust_brightness_v2($hex, $steps) {
                    $steps = max(-255, min(255, $steps)); $hex = str_replace('#', '', $hex);
                    if (strlen($hex) == 3) { $hex = str_repeat(substr($hex,0,1), 2).str_repeat(substr($hex,1,1), 2).str_repeat(substr($hex,2,1), 2); }
                    $color_parts = str_split($hex, 2); $return = '';
                    foreach ($color_parts as $color) { $color = hexdec($color); $color = max(0,min(255,$color + $steps)); $return .= str_pad(dechex($color), 2, '0', STR_PAD_LEFT); }
                    return '#'.$return;
                }
            }
            $mobile_menu_raw_bg = is_dark_color_for_header_v2($db_header_color_val) ? adjust_brightness_v2($db_header_color_val, -20) : adjust_brightness_v2($db_header_color_val, 20);
            $mobile_menu_style_attribute = "style=\"background-color: " . htmlspecialchars($mobile_menu_raw_bg) . "; color: " . htmlspecialchars($header_text_color_val) . ";\"";
            $mobile_submenu_raw_bg = is_dark_color_for_header_v2($db_header_color_val) ? adjust_brightness_v2($db_header_color_val, -30) : adjust_brightness_v2($db_header_color_val, 30);
            $mobile_submenu_style_attribute = "style=\"background-color: " . htmlspecialchars($mobile_submenu_raw_bg) . ";\"";
            
            // Kosongkan kelas default jika style dari DB diterapkan
            $header_default_bg_class = '';
            $header_default_text_class = '';
            $mobile_menu_default_bg_class = '';
        }
    } elseif (isset($conn) && $conn->error) {
        error_log("Header.php - DB query pengaturan error: " . $conn->error);
    }
}

$home_link = $base_path_for_includes . 'login.php';
$profil_link = $base_path_for_includes . 'profil.php';
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    switch ($_SESSION['role']) {
        case 'admin':
            $home_link = $base_path_for_includes . 'admin/index.php';
            $profil_link = $base_path_for_includes . 'admin/profil.php';
            break;
        case 'wali_kelas':
            $home_link = $base_path_for_includes . 'wali_kelas/index.php';
            $profil_link = $base_path_for_includes . 'wali_kelas/edit_akun.php';
            break;
        case 'wali_murid':
            $home_link = $base_path_for_includes . 'wali_murid/index.php';
            $profil_link = $base_path_for_includes . 'wali_murid/edit_profil.php';
            break;
    }
}

if (!function_exists('isActiveLink')) {
    function isActiveLink($pageName, $roleFolder = null) {
        $currentScript = basename($_SERVER['PHP_SELF']);
        $isActive = ($currentScript == $pageName);
        if ($roleFolder !== null) {
            $path_parts = explode('/', trim($_SERVER['PHP_SELF'], '/'));
            $currentDir = $path_parts[count($path_parts) - 2] ?? null;
            $isActive = $isActive && ($currentDir === $roleFolder);
        }
        return $isActive ? 'active' : '';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if (isset($additional_meta_tags_in_head)) { echo $additional_meta_tags_in_head; } ?>
    <title><?php echo htmlspecialchars($nama_sekolah_display); ?> - Aplikasi</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <?php
        $css_check_path = $is_root_page ? rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/' . ltrim($assets_css_path, './') : realpath(__DIR__ . '/' . $assets_css_path) ;
        if ($css_check_path && file_exists($css_check_path)):
    ?>
        <link rel="stylesheet" href="<?php echo htmlspecialchars($assets_css_path); ?>?v=<?php echo time(); ?>">
    <?php endif; ?>
    <style>
        .app-header { box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .nav-link { padding: 0.5rem 0.75rem; border-radius: 0.375rem; transition: background-color 0.2s ease-in-out, color 0.2s ease-in-out; display: flex; align-items: center; }
        .nav-link:hover { background-color: <?php echo $link_hover_bg_css; ?>; }
        .nav-link.active { background-color: <?php echo $link_active_bg_css; ?>; font-weight: 600; }
        #mobile-menu { z-index: 49; width: 100%; }
        #mobile-menu .mobile-nav-item, #mobile-menu .user-menu-trigger { padding: 0.9rem 1rem; border-bottom: 1px solid rgba(255,255,255,0.08); width: 100%; display: flex; align-items: center; justify-content: flex-start; }
        #mobile-menu .mobile-nav-item:last-child, #mobile-menu .user-menu-trigger:last-child { border-bottom: none; }
        #mobile-menu .mobile-nav-item:hover, #mobile-menu .user-menu-trigger:hover { background-color: <?php echo $link_hover_bg_css; ?>; }
        #mobile-menu .mobile-nav-item.active { background-color: <?php echo $link_active_bg_css; ?>; }
        #mobile-user-submenu .mobile-submenu-item { padding: 0.8rem 1rem 0.8rem 2.5rem; border-bottom: 1px solid rgba(255,255,255,0.05); width:100%; display:flex; align-items:center;}
        #mobile-user-submenu .mobile-submenu-item:last-child { border-bottom: none; }
        #mobile-user-submenu .mobile-submenu-item i { margin-right: 0.85rem; width: 20px; text-align: center;}
        #mobile-menu .mobile-nav-item i, #mobile-menu .user-menu-trigger i:first-child { margin-right: 0.85rem; width: 20px; text-align: center; }
        .body-no-scroll { overflow: hidden; }
    </style>
</head>
<body class="bg-gray-100 font-sans">
    <header class="app-header p-3 md:p-4 sticky top-0 z-50 <?php echo $header_default_bg_class; ?> <?php echo $header_default_text_class; ?>" <?php echo $header_style_attribute; ?>>
        <div class="container mx-auto flex justify-between items-center">
            <a href="<?php echo htmlspecialchars($home_link); ?>" class="flex items-center space-x-2 sm:space-x-3 min-w-0">
                <?php
                    $final_logo_to_display = null;
                    $path_check_logo = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/' . ltrim($logo_src_to_use, './');
                    if (file_exists($path_check_logo)) {
                        $final_logo_to_display = $logo_src_to_use;
                    } else {
                         $path_check_default_logo = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/' . ltrim($logo_src_default, './');
                         if (file_exists($path_check_default_logo)) {
                             $final_logo_to_display = $logo_src_default;
                         }
                    }
                    if ($final_logo_to_display):
                ?>
                    <img src="<?php echo htmlspecialchars($final_logo_to_display); ?>?t=<?php echo time(); ?>" alt="Logo Sekolah" class="h-10 w-10 sm:h-12 sm:w-12 md:h-14 md:w-14 object-contain rounded-full flex-shrink-0">
                <?php else: ?>
                    <div class="h-10 w-10 sm:h-12 sm:w-12 md:h-14 md:w-14 bg-white flex items-center justify-center rounded-full text-indigo-600 text-xl sm:text-2xl font-bold flex-shrink-0">
                        <i class="fas fa-school"></i>
                    </div>
                <?php endif; ?>
                <span class="text-md sm:text-lg md:text-xl font-semibold tracking-tight truncate" <?php echo $header_text_style_attribute; ?>><?php echo htmlspecialchars($nama_sekolah_display); ?></span>
            </a>

            <nav class="hidden md:flex items-center space-x-1">
                <ul class="flex items-center space-x-1 md:space-x-2 text-sm" <?php echo $header_text_style_attribute; ?>>
                    <?php if (isset($_SESSION['user_id']) && isset($_SESSION['role'])): ?>
                        <?php if ($_SESSION['role'] === 'admin'): ?>
                            <li><a href="<?php echo $base_path_for_includes; ?>admin/index.php" class="nav-link <?php echo isActiveLink('index.php', 'admin'); ?>"><i class="fas fa-tachometer-alt mr-1"></i> Dashboard</a></li>
                            <li><a href="<?php echo $base_path_for_includes; ?>admin/manage_siswa.php" class="nav-link <?php echo isActiveLink('manage_siswa.php','admin'); ?>"><i class="fas fa-users mr-1"></i> Siswa</a></li>
                            <li><a href="<?php echo $base_path_for_includes; ?>admin/manage_guru.php" class="nav-link <?php echo isActiveLink('manage_guru.php','admin'); ?>"><i class="fas fa-chalkboard-teacher mr-1"></i> Wali Kelas</a></li>
                            <li><a href="<?php echo $base_path_for_includes; ?>admin/manage_kelas.php" class="nav-link <?php echo isActiveLink('manage_kelas.php','admin'); ?>"><i class="fas fa-school mr-1"></i> Kelas</a></li>
                            <li><a href="<?php echo $base_path_for_includes; ?>admin/status_siswa.php" class="nav-link <?php echo isActiveLink('status_siswa.php','admin'); ?>"><i class="fas fa-tasks mr-1"></i> Status</a></li>
                            <!-- MENU PENGATURAN DIHAPUS DARI SINI -->
                            <!-- <li><a href="<?php echo $base_path_for_includes; ?>admin/pengaturan_aplikasi.php" class="nav-link <?php echo isActiveLink('pengaturan_aplikasi.php','admin'); ?>"><i class="fas fa-cog mr-1"></i> Pengaturan</a></li> -->
                        <?php elseif ($_SESSION['role'] === 'wali_kelas'): ?>
                            <li><a href="<?php echo $base_path_for_includes; ?>wali_kelas/index.php" class="nav-link <?php echo isActiveLink('index.php', 'wali_kelas'); ?>"><i class="fas fa-home mr-1"></i> Dashboard</a></li>
                            <li><a href="<?php echo $base_path_for_includes; ?>wali_kelas/status_penjemputan.php" class="nav-link <?php echo isActiveLink('status_penjemputan.php','wali_kelas'); ?>"><i class="fas fa-clipboard-list mr-1"></i> Status Jemput</a></li>
                        <?php elseif ($_SESSION['role'] === 'wali_murid'): ?>
                             <li><a href="<?php echo $base_path_for_includes; ?>wali_murid/index.php" class="nav-link <?php echo isActiveLink('index.php', 'wali_murid'); ?>"><i class="fas fa-child mr-1"></i> Info Anak</a></li>
                        <?php endif; ?>
                        <li class="relative group">
                             <button class="nav-link">
                                <i class="fas fa-user-circle mr-1"></i>
                                <span><?php echo htmlspecialchars($_SESSION['nama_user'] ?? $_SESSION['username']); ?></span>
                                <i class="fas fa-chevron-down ml-1 text-xs"></i>
                            </button>
                            <ul class="absolute hidden group-hover:block right-0 mt-1 bg-white text-gray-700 shadow-lg rounded-md py-1 w-48 z-[100]">
                                <li><a href="<?php echo htmlspecialchars($profil_link); ?>" class="block px-4 py-2 text-sm hover:bg-gray-100"><i class="fas fa-user-edit mr-2"></i> Edit Akun</a></li>
                                <li>
                                    <a href="<?php echo $base_path_for_includes; ?>logout.php" class="block px-4 py-2 text-sm hover:bg-red-500 hover:text-white text-red-600">
                                        <i class="fas fa-sign-out-alt mr-2"></i> Logout
                                    </a>
                                </li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li><a href="<?php echo $base_path_for_includes; ?>login.php" class="nav-link <?php echo isActiveLink('login.php'); ?>"><i class="fas fa-sign-in-alt mr-1"></i> Login</a></li>
                    <?php endif; ?>
                </ul>
            </nav>

            <div class="md:hidden">
                <button id="mobile-menu-button" class="focus:outline-none p-2 -mr-2" <?php echo $header_text_style_attribute; ?>>
                    <i class="fas fa-bars fa-lg"></i>
                </button>
            </div>
        </div>

        <div id="mobile-menu" class="hidden md:hidden absolute top-full left-0 right-0 shadow-lg <?php echo $mobile_menu_default_bg_class; ?>" <?php echo $mobile_menu_style_attribute; ?> >
            <ul class="flex flex-col items-start text-sm py-1" <?php echo (empty($mobile_menu_style_attribute)) ? $header_text_style_attribute : ''; // Jika mobile menu pakai style sendiri, teks ikut style itu, jika tidak, ikut header utama ?>>
                 <?php if (isset($_SESSION['user_id']) && isset($_SESSION['role'])): ?>
                    <?php if ($_SESSION['role'] === 'admin'): ?>
                        <li><a href="<?php echo $base_path_for_includes; ?>admin/index.php" class="mobile-nav-item <?php echo isActiveLink('index.php', 'admin'); ?>"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                        <li><a href="<?php echo $base_path_for_includes; ?>admin/manage_siswa.php" class="mobile-nav-item <?php echo isActiveLink('manage_siswa.php','admin'); ?>"><i class="fas fa-users"></i> Siswa</a></li>
                        <li><a href="<?php echo $base_path_for_includes; ?>admin/manage_guru.php" class="mobile-nav-item <?php echo isActiveLink('manage_guru.php','admin'); ?>"><i class="fas fa-chalkboard-teacher"></i> Wali Kelas</a></li>
                        <li><a href="<?php echo $base_path_for_includes; ?>admin/manage_kelas.php" class="mobile-nav-item <?php echo isActiveLink('manage_kelas.php','admin'); ?>"><i class="fas fa-school"></i> Kelas</a></li>
                        <li><a href="<?php echo $base_path_for_includes; ?>admin/status_siswa.php" class="mobile-nav-item <?php echo isActiveLink('status_siswa.php','admin'); ?>"><i class="fas fa-tasks"></i> Status</a></li>
                        <!-- MENU PENGATURAN DIHAPUS DARI SINI (MOBILE) -->
                        <!-- <li><a href="<?php echo $base_path_for_includes; ?>admin/pengaturan_aplikasi.php" class="mobile-nav-item <?php echo isActiveLink('pengaturan_aplikasi.php','admin'); ?>"><i class="fas fa-cog"></i> Pengaturan</a></li> -->
                    <?php elseif ($_SESSION['role'] === 'wali_kelas'): ?>
                        <li><a href="<?php echo $base_path_for_includes; ?>wali_kelas/index.php" class="mobile-nav-item <?php echo isActiveLink('index.php', 'wali_kelas'); ?>"><i class="fas fa-home"></i> Dashboard</a></li>
                        <li><a href="<?php echo $base_path_for_includes; ?>wali_kelas/status_penjemputan.php" class="mobile-nav-item <?php echo isActiveLink('status_penjemputan.php','wali_kelas'); ?>"><i class="fas fa-clipboard-list"></i> Status Jemput</a></li>
                    <?php elseif ($_SESSION['role'] === 'wali_murid'): ?>
                         <li><a href="<?php echo $base_path_for_includes; ?>wali_murid/index.php" class="mobile-nav-item <?php echo isActiveLink('index.php', 'wali_murid'); ?>"><i class="fas fa-child"></i> Info Anak</a></li>
                    <?php endif; ?>
                    
                    <li class="w-full">
                        <button id="mobile-user-menu-trigger" class="user-menu-trigger w-full flex justify-between items-center text-gray-300 hover:text-white">
                            <span><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($_SESSION['nama_user'] ?? $_SESSION['username']); ?></span>
                            <i class="fas fa-chevron-down text-xs transition-transform duration-200"></i>
                        </button>
                        <ul id="mobile-user-submenu" class="hidden pl-0" <?php echo $mobile_submenu_style_attribute; ?>>
                            <li><a href="<?php echo htmlspecialchars($profil_link); ?>" class="mobile-submenu-item hover:bg-indigo-600"><i class="fas fa-user-edit"></i> Edit Akun</a></li>
                            <li><a href="<?php echo $base_path_for_includes; ?>logout.php" class="mobile-submenu-item hover:bg-red-500 text-red-300 hover:text-white"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                        </ul>
                    </li>
                <?php else: ?>
                    <li><a href="<?php echo $base_path_for_includes; ?>login.php" class="mobile-nav-item <?php echo isActiveLink('login.php'); ?>"><i class="fas fa-sign-in-alt"></i> Login</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </header>
    <main class="container mx-auto mt-4 md:mt-6 p-4">
        <?php
        if (isset($_SESSION['flash_message_header'])) {
            echo "<div class='mb-4 p-3 bg-blue-100 border border-blue-300 text-blue-700 rounded'>" . htmlspecialchars($_SESSION['flash_message_header']) . "</div>";
            unset($_SESSION['flash_message_header']);
        }
        if (isset($_SESSION['flash_message']) && is_array($_SESSION['flash_message']) && !empty($_SESSION['flash_message']['message'])) {
            $type_flash = $_SESSION['flash_message']['type'] ?? 'info';
            $bgColor_flash = 'bg-blue-100 border-blue-300 text-blue-700';
            if ($type_flash === 'success') { $bgColor_flash = 'bg-green-100 border-green-300 text-green-700'; }
            elseif ($type_flash === 'error') { $bgColor_flash = 'bg-red-100 border-red-300 text-red-700'; }
            elseif ($type_flash === 'warning') { $bgColor_flash = 'bg-yellow-100 border-yellow-300 text-yellow-700'; }
            echo "<div class='mb-4 p-3 " . $bgColor_flash . " rounded'>" . htmlspecialchars($_SESSION['flash_message']['message']) . "</div>";
            unset($_SESSION['flash_message']);
        }
        ?>
        <!-- Konten utama -->
    </main>

    <script>
    // Script Mobile Menu Anda (TETAP)
    document.addEventListener('DOMContentLoaded', function () {
        const menuButton = document.getElementById('mobile-menu-button');
        const mobileMenu = document.getElementById('mobile-menu');
        const body = document.body;

        if (menuButton && mobileMenu) {
            const menuIcon = menuButton.querySelector('i');
            menuButton.addEventListener('click', function (event) {
                event.stopPropagation();
                const isHidden = mobileMenu.classList.contains('hidden');
                if (isHidden) {
                    mobileMenu.classList.remove('hidden');
                    if (menuIcon) menuIcon.classList.replace('fa-bars', 'fa-times');
                    body.classList.add('body-no-scroll');
                } else {
                    mobileMenu.classList.add('hidden');
                    if (menuIcon) menuIcon.classList.replace('fa-times', 'fa-bars');
                    body.classList.remove('body-no-scroll');
                    const userSubmenuMobile = document.getElementById('mobile-user-submenu');
                    const userMenuChevronMobile = document.querySelector('#mobile-user-menu-trigger .fa-chevron-up');
                    if (userSubmenuMobile && !userSubmenuMobile.classList.contains('hidden')) {
                        userSubmenuMobile.classList.add('hidden');
                        if (userMenuChevronMobile) userMenuChevronMobile.classList.replace('fa-chevron-up', 'fa-chevron-down');
                    }
                }
            });
        }

        const userMenuTriggerMobile = document.getElementById('mobile-user-menu-trigger');
        const userSubmenuMobile = document.getElementById('mobile-user-submenu');

        if (userMenuTriggerMobile && userSubmenuMobile) {
            const chevronIcon = userMenuTriggerMobile.querySelector('.fa-chevron-down, .fa-chevron-up');
            userMenuTriggerMobile.addEventListener('click', function(event) {
                event.stopPropagation();
                const isSubmenuHidden = userSubmenuMobile.classList.contains('hidden');
                if (isSubmenuHidden) {
                    userSubmenuMobile.classList.remove('hidden');
                    if (chevronIcon) chevronIcon.classList.replace('fa-chevron-down', 'fa-chevron-up');
                } else {
                    userSubmenuMobile.classList.add('hidden');
                    if (chevronIcon) chevronIcon.classList.replace('fa-chevron-up', 'fa-chevron-down');
                }
            });
        }

        document.addEventListener('click', function(event) {
            if (mobileMenu && !mobileMenu.classList.contains('hidden') && !mobileMenu.contains(event.target) && menuButton && !menuButton.contains(event.target)) {
                mobileMenu.classList.add('hidden');
                const menuIcon = menuButton.querySelector('i');
                if (menuIcon) menuIcon.classList.replace('fa-times', 'fa-bars');
                body.classList.remove('body-no-scroll');
                const userSubmenuMobile = document.getElementById('mobile-user-submenu');
                const userMenuChevronMobile = document.querySelector('#mobile-user-menu-trigger .fa-chevron-up');
                 if (userSubmenuMobile && !userSubmenuMobile.classList.contains('hidden')) {
                    userSubmenuMobile.classList.add('hidden');
                    if (userMenuChevronMobile) userMenuChevronMobile.classList.replace('fa-chevron-up', 'fa-chevron-down');
                }
            }
        });
    });
    </script>
</body>
</html>