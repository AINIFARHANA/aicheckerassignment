<?php
include 'config.php';

 $stmt = $conn->prepare("
    SELECT * FROM vouchers
    WHERE status = 'Active'
    AND (expiry_date IS NULL OR expiry_date >= CURDATE())
    ORDER BY discount_amount DESC
");
 $stmt->execute();
 $result = $stmt->get_result();
 $vouchers = [];
while ($row = $result->fetch_assoc()) {
    $vouchers[] = $row;
}
 $stmt->close();
 $conn->close();

 $today = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vouchers — AI Assignment Checker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap">
    <style>
        :root{--primary:#6A0DAD;--primary-light:#9C27B0;--primary-dark:#4A0072;--primary-rgb:106,13,173;--bg:#F3F0F7;--card-bg:rgba(255,255,255,0.85);--text-dark:#2D1B4E;--text-muted:#7B6B8D;--border-color:rgba(106,13,173,0.08);--input-bg:#FFFFFF;--shadow-sm:0 2px 8px rgba(106,13,173,0.06);--shadow-md:0 4px 20px rgba(106,13,173,0.1);--shadow-lg:0 8px 40px rgba(106,13,173,0.15);--radius:16px;--radius-sm:10px}
        [data-theme="dark"]{--bg:#110B18;--card-bg:rgba(32,18,52,0.85);--text-dark:#E8E0F0;--text-muted:#9B8DB5;--border-color:rgba(156,39,176,0.12);--input-bg:rgba(45,27,78,0.6);--shadow-sm:0 2px 8px rgba(0,0,0,0.25);--shadow-md:0 4px 20px rgba(0,0,0,0.35);--shadow-lg:0 8px 40px rgba(0,0,0,0.45)}
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Poppins',sans-serif;background:var(--bg);color:var(--text-dark);min-height:100vh;transition:background .35s ease,color .35s ease;overflow-x:hidden}

        .user-nav{background:rgba(255,255,255,.85);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border-bottom:1px solid var(--border-color);padding:0 30px;height:64px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:1000;transition:background .35s ease}
        [data-theme="dark"] .user-nav{background:rgba(17,11,24,.9)}
        .user-nav .nav-brand{display:flex;align-items:center;gap:10px;text-decoration:none}
        .user-nav .nav-brand img{height:38px;border-radius:8px}
        .user-nav .nav-brand span{font-size:16px;font-weight:700;color:var(--primary)}
        .nav-right{display:flex;align-items:center;gap:10px}
        .theme-toggle-btn{width:40px;height:40px;border-radius:12px;border:1px solid var(--border-color);background:var(--input-bg);display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:16px;cursor:pointer;transition:all .25s ease}
        .theme-toggle-btn:hover{border-color:var(--primary);color:var(--primary)}
        .user-nav .nav-links{display:flex;align-items:center;gap:20px}
        .user-nav .nav-link{color:var(--text-muted);font-size:13.5px;font-weight:500;text-decoration:none;transition:color var(--text-muted) .25s ease;padding:6px 0}
        .user-nav .nav-link:hover{color:var(--primary)}
        .user-nav .nav-link.active{color:var(--primary);font-weight:600}

        .vouchers-page{max-width:960px;margin:0 auto;padding:40px 20px 60px}
        .page-header{text-align:center;margin-bottom:40px}
        .page-header h1{font-size:32px;font-weight:800;margin-bottom:8px;position:relative;display:inline-block}
        .page-header h1 span{color:var(--primary);position:relative}
        .page-header h1 span::after{content:'';position:absolute;bottom:-4px;left:0;right:0;height:4px;background:linear-gradient(90deg,var(--primary),var(--primary-light));border-radius:4px;opacity:.4}
        .page-header p{color:var(--text-muted);font-size:15px;max-width:500px;margin:12px auto 0}

        .voucher-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:24px}
        .voucher-card{background:var(--card-bg);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border:1px solid var(--border-color);border-radius:var(--radius);overflow:hidden;position:relative;transition:all .35s cubic-bezier(.4,0,.2,1);box-shadow:var(--shadow-sm)}
        .voucher-card:hover{transform:translateY(-8px);box-shadow:var(--shadow-lg);border-color:rgba(var(--primary-rgb),.15)}

        .voucher-card .card-ribbon{position:absolute;top:16px;right:-8px;background:linear-gradient(135deg,var(--primary),var(--primary-light));color:#fff;font-size:10px;font-weight:700;padding:4px 14px 4px 12px;border-radius:6px 0 0 6px;letter-spacing:.5px;text-transform:uppercase;z-index:2}
        .voucher-card .card-ribbon.hot{background:linear-gradient(135deg,#E65100,#FF9800)}
        .voucher-card .card-ribbon.expiring{background:linear-gradient(135deg,#C62828,#EF5350);animation:ribbonPulse 2s ease-in-out infinite}
        @keyframes ribbonPulse{0%,100%{opacity:1}50%{opacity:.75}}

        .voucher-card .card-top{background:linear-gradient(135deg,var(--primary-dark),var(--primary) 60%,var(--primary-light));padding:28px 24px;text-align:center;position:relative;overflow:hidden}
        .voucher-card .card-top::before{content:'';position:absolute;inset:0;background:url("data:image/svg+xml,%3Csvg width='40' height='40' viewBox='0 0 40 40' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%23ffffff' fill-opacity='0.05'%3E%3Ccircle cx='20' cy='20' r='3'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");opacity:.5}
        .voucher-card .card-top .discount-label{color:rgba(255,255,255,.7);font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:2px;margin-bottom:4px;position:relative;z-index:1}
        .voucher-card .card-top .discount-value{font-size:36px;font-weight:800;color:#fff;position:relative;z-index:1;line-height:1.1}
        .voucher-card .discount-value small{font-size:18px;font-weight:600}
        .voucher-card .card-top .discount-type{color:rgba(255,255,255,.6);font-size:12px;margin-top:2px;position:relative;z-index:1}

        .voucher-card .card-dashed{border-top:2px dashed rgba(var(--primary-rgb),.2);position:relative;margin:0 20px}
        .voucher-card .card-dashed::before,.voucher-card .card-dashed::after{content:'';position:absolute;top:-14px;width:28px;height:28px;background:var(--bg);border-radius:50%;border:2px solid rgba(var(--primary-rgb),.12);transition:background .35s ease}
        .voucher-card .card-dashed::before{left:-34px}
        .voucher-card .card-dashed::after{right:-34px}

        .voucher-card .card-body-inner{padding:20px 24px 24px;text-align:center}
        .voucher-card .code-box{background:rgba(var(--primary-rgb),.06);border:1px dashed rgba(var(--primary-rgb),.2);border-radius:var(--radius-sm);padding:12px 16px;margin-bottom:16px;position:relative}
        .voucher-card .code-text{font-family:'Courier New',monospace;font-size:20px;font-weight:800;letter-spacing:3px;color:var(--primary)}
        .voucher-card .copy-btn{position:absolute;right:8px;top:50%;transform:translateY(-50%);width:32px;height:32px;border-radius:8px;border:1px solid rgba(var(--primary-rgb),.2);background:var(--input-bg);display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:13px;cursor:pointer;transition:all .2s ease}
        .voucher-card .copy-btn:hover{background:var(--primary);color:#fff;border-color:var(--primary)}
        .voucher-card .copy-btn.copied{background:#2E7D32;color:#fff;border-color:#2E7D32}

        .voucher-card .details-row{display:flex;justify-content:space-between;align-items:center;padding:6px 0;font-size:12.5px;border-bottom:1px solid var(--border-color)}
        .voucher-card .details-row:last-child{border-bottom:none}
        .voucher-card .details-label{color:var(--text-muted);font-weight:500}
        .voucher-card .details-value{font-weight:600;color:var(--text-dark)}
        .voucher-card .details-value.expiring-soon{color:#C62828;font-weight:700}
        .voucher-card .use-btn{display:block;width:100%;margin-top:18px;padding:12px;border:none;border-radius:var(--radius-sm);background:linear-gradient(135deg,var(--primary),var(--primary-light));color:#fff;font-family:inherit;font-size:14px;font-weight:600;cursor:pointer;transition:all .25s ease;text-align:center}
        .voucher-card .use-btn:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(var(--primary-rgb),.35)}

        .empty-state{text-align:center;padding:80px 20px}
        .empty-state i{font-size:64px;color:var(--text-muted);opacity:.15;margin-bottom:16px}
        .empty-state h3{font-size:22px;font-weight:700;color:var(--text-dark);margin-bottom:8px}
        .empty-state p{color:var(--text-muted);font-size:14px}

        .copy-toast{position:fixed;bottom:30px;left:50%;transform:translateX(-50%) translateY(100px);background:linear-gradient(135deg,#2E7D32,#43A047);color:#fff;padding:12px 28px;border-radius:30px;font-size:13.5px;font-weight:600;box-shadow:0 8px 30px rgba(0,0,0,.25);z-index:9999;transition:transform:translateX(-50%) translateY(0) .4s cubic-bezier(.16,1,.3,1);display:flex;align-items:center;gap:8px;white-space:nowrap}
        .copy-toast.show{transform:translateX(-50%) translateY(0)}

        @keyframes fadeInUp{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:translateY(0)}}
        .voucher-card{animation:fadeInUp .5s ease forwards;opacity:0}
        .voucher-card:nth-child(1){animation-delay:.05s}
        .voucher-card:nth-child(2){animation-delay:.1s}
        .voucher-card:nth-child(3){animation-delay:.15s}
        .voucher-card:nth-child(4){animation-delay:.2s}
        .voucher-card:nth-child(5){animation-delay:.25s}
        .voucher-card:nth-child(6){animation-delay:.3s}
        ::-webkit-scrollbar{width:6px}::-webkit-scrollbar-track{background:transparent}::-webkit-scrollbar-thumb{background:rgba(var(--primary-rgb),.2);border-radius:10px}::-webkit-scrollbar-thumb:hover{background:rgba(var(--primary-rgb),.35)}
        @media(max-width:767.98px){.vouchers-page{padding:24px 16px 40px}.page-header h1{font-size:26px}.voucher-grid{grid-template-columns:1fr}.user-nav{padding:0 16px}.voucher-card .card-top .discount-value{font-size:30px}.voucher-card .card-top .discount-value small{font-size:15px}.code-text{font-size:16px;letter-spacing:2px}}
        @media(max-width:400px){.voucher-card .code-text{font-size:14px;letter-spacing:1.5px}.page-header h1{font-size:22px}}
        @media print{.user-nav,.theme-toggle-btn,.copy-btn,.use-btn{display:none!important}.voucher-card{break-inside:avoid;box-shadow:none!important;border:1px solid #ddd!important}body{background:#fff!important}}
    </style>
</head>
<body>
    <nav class="user-nav">
        <a href="index.php" class="nav-brand">
            <img src="image/logo1.png" alt="Logo" onerror="this.style.display='none'">
            <span>AI Checker</span>
        </a>
        <div class="nav-right">
            <button class="theme-toggle-btn" id="themeToggleBtn" title="Toggle Dark Mode"><i class="fas fa-moon"></i></button>
        </div>
    </nav>

    <div class="vouchers-page">
        <div class="page-header">
            <h1>Available <span>Vouchers</span></h1>
            <p>Grab exclusive discounts on our AI Assignment Checking plans. Copy the code and apply it at checkout.</p>
        </div>

        <?php if (empty($vouchers)): ?>
        <div class="empty-state">
            <i class="fas fa-ticket-alt"></i>
            <h3>No Vouchers Available</h3>
            <p>There are no active vouchers at the moment. Check back later!</p>
        </div>
        <?php else: ?>
        <div class="voucher-grid">
            <?php foreach ($vouchers as $v):
                $code = htmlspecialchars($v['code']);
                $disc = number_format((float)$v['discount_amount'], 2);
                $min_amt = number_format((float)$v['min_amount'], 2);
                $exp_date = $v['expiry_date'];

                $badge = ''; $badge_class = '';
                $days_left = null;

                if ($exp_date) {
                    $diff = (strtotime($exp_date) - strtotime($today)) / 86400;
                    $days_left = (int)$diff;
                    if ($days_left <= 3 && $days_left >= 0) {
                        $badge = 'Expiring Soon';
                        $badge_class = 'expiring';
                    } elseif ($days_left <= 7 && $days_left > 3) {
                        $badge = 'Hot';
                        $badge_class = 'hot';
                    } else {
                        $badge = 'New';
                        $badge_class = '';
                    }
                } else {
                    $badge = 'New';
                    $badge_class = '';
                }

                $exp_display = $exp_date ? date('M d, Y', strtotime($exp_date)) : 'No Expiry';
                $exp_class = ($days_left !== null && $days_left <= 3 && $days_left >= 0) ? 'expiring-soon' : '';
            ?>
            <div class="voucher-card">
                <?php if (!empty($badge)): ?>
                <div class="card-ribbon <?php echo $badge_class; ?>"><?php echo $badge; ?></div>
                <?php endif; ?>
                <div class="card-top">
                    <div class="discount-label">Discount</div>
                    <div class="discount-value"><small>RM </small><?php echo $disc; ?></div>
                    <div class="discount-type">off your purchase</div>
                </div>
                <div class="card-dashed"></div>
                <div class="card-body-inner">
                    <div class="code-box">
                        <span class="code-text" id="code-<?php echo $v['voucher_id']; ?>"><?php echo $code; ?></span>
                        <button class="copy-btn" onclick="copyCode('<?php echo $code; ?>',this)" title="Copy Code"><i class="fas fa-copy"></i></button>
                    </div>
                    <div class="details-row">
                        <span class="details-label">Min. Spend</span>
                        <span class="details-value">RM <?php echo $min_amt; ?></span>
                    </div>
                    <div class="details-row">
                        <span class="details-label">Expires</span>
                        <span class="details-value <?php echo $exp_display; ?></span>
                    </div>
                    <?php if ($days_left !== null && $days_left >= 0): ?>
                    <div class="details-row">
                        <span class="details-label">Time Left</span>
                        <span class="details-value <?php echo $days_left; ?> day<?php echo $days_left !== 1 ? 's' : ''; ?></span></span>
                    </div>
                    <?php endif; ?>
                    <button class="use-btn" onclick="copyCode('<?php echo $code; ?>',this.closest('.voucher-card').querySelector('.copy-btn'))">
                        <i class="fas fa-copy" style="margin-right:6px;"></i> Copy & Use Code
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="copy-toast" id="copyToast"><i class="fas fa-check-circle"></i> Code copied to clipboard!</div>

    <script>
        const htmlEl = document.documentElement;
        const themeBtn = document.getElementById('themeToggleBtn');
        const themeIcon = themeBtn.querySelector('i');

        function applyTheme(theme) {
            htmlEl.setAttribute('data-theme', theme);
            themeIcon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
            localStorage.setItem('user_theme', theme);
        }

        if (localStorage.getItem('user_theme') === 'dark') {
            applyTheme('dark');
        } else {
            applyTheme('light');
        }

        themeBtn.addEventListener('click', () => {
            const current = htmlEl.getAttribute('data-theme');
            applyTheme(current === 'dark' ? 'light' : 'dark');
        });

        function copyCode(code, btnEl) {
            navigator.clipboard.writeText(code).then(() => {
                if (btnEl) {
                    btnEl.classList.add('copied');
                    btnEl.innerHTML = '<i class="fas fa-check"></i>';
                    setTimeout(() => {
                        btnEl.classList.remove('copied');
                        btnEl.innerHTML = '<i class="fas fa-copy"></i>';
                    }, 2000);
                }
                const toast = document.getElementById('copyToast');
                toast.classList.add('show');
                setTimeout(() => toast.classList.remove('show'), 2500);
            }).catch(() => {
                const ta = document.createElement('textarea');
                ta.value = code;
                ta.style.position = 'fixed';
                ta.style.left = '-9999px';
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
                const toast = document.getElementById('copyToast');
                toast.classList.add('show');
                setTimeout(() => toast.classList.remove('show'), 2500);
            });
        }
    </script>
</body>
</html>