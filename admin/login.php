<?php
session_start();
require __DIR__ . '/../inc/bootstrap.php';

// Kredensial sementara (nanti bisa dipindah ke tabel/users)
$ADMIN_USER = 'admin';
$ADMIN_PASS = 'admin123';

$msg = isset($_GET['msg']) ? $_GET['msg'] : '';
$error = '';

if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = (string)($_POST['username'] ?? '');
    $p = (string)($_POST['password'] ?? '');

    if (hash_equals($ADMIN_USER, $u) && hash_equals($ADMIN_PASS, $p)) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $u;
        header('Location: index.php');
        exit();
    }
    $error = 'Username atau password salah.';
}
?>
<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Salin Aslimas</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'], heading: ['Plus Jakarta Sans', 'sans-serif'] },
                    colors: { brand: { 50: '#ecfdf5', 100: '#d1fae5', 500: '#10b981', 600: '#059669', 700: '#047857', 900: '#064e3b' } },
                    boxShadow: { 'soft': '0 10px 40px -10px rgba(0,0,0,0.08)' }
                }
            }
        }
    </script>
    <style>
        body { background-color: #FAFAFA; }
        .input-premium{
            width:100%;
            background:#f8fafc;
            border:1.5px solid #cbd5e1;
            color:#1e293b;
            border-radius:12px;
            padding:14px 16px;
            outline:none;
            transition:all .25s ease;
            font-size:14px;
        }
        .input-premium:hover{ border-color:#94a3b8; background:#ffffff; }
        .input-premium:focus{ background:#ffffff; border-color:#10b981; box-shadow:0 0 0 4px rgba(16,185,129,0.12); }
    </style>
</head>
<body class="text-slate-600 antialiased">
    <main class="min-h-screen flex items-center justify-center px-4 py-10">
        <div class="w-full max-w-md bg-white rounded-[1.5rem] shadow-soft border border-slate-100 overflow-hidden">
            <div class="p-8">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.3em] text-slate-400">Admin Panel</p>
                        <h1 class="font-heading text-2xl font-extrabold text-slate-900 mt-2">Login</h1>
                    </div>
                    <a href="../index.php" class="text-sm font-semibold text-brand-600 hover:text-brand-700">Kembali</a>
                </div>

                <?php if($msg): ?>
                    <div class="mt-6 p-4 bg-brand-50 border border-brand-100 rounded-xl text-sm text-brand-700">
                        <?php echo htmlspecialchars($msg); ?>
                    </div>
                <?php endif; ?>

                <?php if($error): ?>
                    <div class="mt-6 p-4 bg-red-50 border border-red-100 rounded-xl text-sm text-red-700">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="mt-8 space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Username</label>
                        <input type="text" name="username" required class="input-premium" autocomplete="username">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Password</label>
                        <input type="password" name="password" required class="input-premium" autocomplete="current-password">
                    </div>
                    <button type="submit" class="w-full bg-slate-900 text-white py-3 rounded-xl font-bold hover:bg-slate-800 transition shadow-md">
                        Masuk
                    </button>
                </form>

                <div class="mt-6 text-xs text-slate-400 leading-relaxed">
                    Kredensial sementara default: <span class="font-mono">admin / admin123</span>. Silakan ganti di file <span class="font-mono">admin/login.php</span>.
                </div>
            </div>
        </div>
    </main>
</body>
</html>
<?php if($connected) $conn->close(); ?>

