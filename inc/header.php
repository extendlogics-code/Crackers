<?php
// Shared site header. Usage:
//   $title = 'Page Title';
//   $extraHead = '<style>...</style>'; // optional
//   include __DIR__ . '/header.php';
if (!isset($title)) { $title = 'PKS Crackers'; }

// Choose celebratory crackers background. If a custom photo exists, prefer it.
$bgCandidates = [
  'images/bg/custom_fireworks.jpg',
  'images/bg/custom_fireworks.jpeg',
  'images/bg/custom_fireworks.png',
  'images/bg/custom_fireworks.webp',
  'images/bg/default.gif',
];
$bgUrl = 'images/bg/default.gif';
foreach ($bgCandidates as $rel) {
  $abs = __DIR__ . '/../' . $rel;
  if (is_file($abs)) { $bgUrl = $rel; break; }
}
?><!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title) ?></title>
    <?php
      // Security headers
      if (!headers_sent()) {
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header("Content-Security-Policy: default-src 'self' https: data: blob:; img-src 'self' data: blob: https:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; frame-ancestors 'none';");
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
          header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }
      }
    ?>
    <style>
      :root { --bg:#ffffff; --text:#111827; --muted:#4b5563; --brand:#e11d48; --brand2:#f97316; --gold:#f59e0b; --glow:#ff7a18; }
      *{box-sizing:border-box}
      body{ margin:0; background: var(--bg); color:var(--text); font:16px/1.45 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif }
      /* Make all images fluid by default for mobile/tablet responsiveness */
      img{max-width:100%; height:auto}

      /* Top golden bar */
      .topbar{position:sticky;top:0;z-index:50;background:linear-gradient(0deg,#fbbf24,var(--gold));color:#111;border-bottom:1px solid rgba(0,0,0,.12)}
      .topbar .row{max-width:1200px;margin:0 auto;display:flex;align-items:center;gap:12px;padding:6px 16px}
      .pill{display:inline-flex;align-items:center;gap:6px;background:#fff;border-radius:999px;padding:4px 8px;border:1px solid rgba(0,0,0,.06)}
      .payicon{width:22px;height:22px;border-radius:999px;background:#e5e7eb;display:grid;place-items:center;overflow:hidden}
      .payicon img{width:100%;height:100%;object-fit:cover}
      .phones{font-weight:700;letter-spacing:.3px}
      .marq{margin-left:auto;white-space:nowrap;overflow:hidden}
      .marq-inner{display:inline-block;padding-left:100%;animation:marq 35s linear infinite}
      @keyframes marq { from{transform:translateX(0)} to{transform:translateX(-100%)} }
      @media (prefers-reduced-motion: reduce){ .marq-inner{animation:none;padding-left:0} }

      /* Brand hero band */
     /* .brand-hero{position:relative;background:linear-gradient(0deg, rgba(0,0,0,.55), rgba(0,0,0,.55)), var(--hero-bg, url('images/bg/bigsale.png')) center/cover no-repeat;color:#fff}  style="--hero-bg:url('/<?= htmlspecialchars($bgUrl) ?>')"background:linear-gradient(0deg,#fbbf24,var(--gold));box-shadow:0 10px 24px rgba(0,0,0,.25);*/
      
       .brand-hero{position:relative;  background: linear-gradient(0deg, #fbbf24, var(--gold));color:#fff}
      
        
      .brand-hero .wrap{max-width:1200px;margin:0 auto;padding:18px 16px 26px;display:flex;align-items:center;gap:18px}
      .brand-hero .circle{width:120px;height:120px;border-radius:999px;background:#fff;overflow:hidden;flex:0 0 auto;box-shadow:0 8px 24px rgba(0,0,0,.25)}
      .brand-hero .circle img{width:100%;height:100%;object-fit:contain}
      .brand-hero .title{font-size:32px;font-weight:800;letter-spacing:.5px}
      .brand-hero .underline{height:4px;width:260px;background:#fff;border-radius:2px;margin:6px 0 8px}
      .brand-hero .tag{color:#e5e7eb}
      .nav-pill{margin-left:auto;color:#111;padding:10px 12px;border-radius:16px;display:flex;gap:12px;align-items:center}
      .nav-pill a{color:#111;text-decoration:none;padding:6px 10px;border-radius:10px;font-weight:700}
      .nav-pill a.active{background:#000;color:#fff}

      /* Mobile hamburger */
      .hamburger{display:none;background:#111;color:#fff;border:0;border-radius:10px;padding:8px 10px;cursor:pointer}
      .hamburger .bar{display:block;width:20px;height:2px;background:#fff;margin:4px 0;border-radius:2px}
      .mobile-menu{display:none;position:fixed;inset:0;z-index:60;background:rgba(0,0,0,.6)}
      .mobile-menu .panel{position:absolute;left:0;right:0;top:0;background:#ffffff;color:#111;border-bottom:1px solid rgba(0,0,0,.1);border-radius:0 0 12px 12px;max-width:1200px;margin:0 auto;padding:12px}
      .mobile-menu .links{display:flex;flex-direction:column;gap:8px}
      .mobile-menu a{color:#111;text-decoration:none;padding:10px 12px;border-radius:8px;background:#f9fafb}
      .mobile-menu.open{display:block}

      /* Optional safety image strip */
      .safety-strip{display:none}
      .safety-strip.active{display:block}
      .safety-strip img{display:block;max-width:1200px;width:100%;height:auto;margin:0 auto;border-bottom:1px solid rgba(0,0,0,.06)}

      /* Responsive tweaks */
      @media (max-width: 1024px){
        .brand-hero .circle{width:96px;height:96px}
        .brand-hero .title{font-size:28px}
        .brand-hero .underline{width:200px}
      }
      @media (max-width: 768px){
        .topbar .row{flex-wrap:wrap;gap:8px}
        .phones{font-size:14px}
        .brand-hero .wrap{flex-wrap:wrap;gap:12px;padding:14px 12px 18px}
        .brand-hero .circle{width:80px;height:80px}
        .brand-hero .title{font-size:24px}
        .brand-hero .underline{width:160px;height:3px}
        .nav-pill{display:none}
        .hamburger{display:inline-block;margin-left:auto}
        }
      @media (max-width: 560px){
        .marq{display:none}
        .phones{font-size:13px}
        .brand-hero .title{font-size:22px}
      }
    </style>
    <?php if (!empty($extraHead)) echo $extraHead; ?>
    
    
  </head>
  <body>
    <?php
      $cur = basename(parse_url($_SERVER['SCRIPT_NAME'] ?? '', PHP_URL_PATH));
      $logoRel = 'pkslogo.png';
      $logoAbs = __DIR__ . '/../' . $logoRel;
      if (!is_file($logoAbs)) { $logoRel = 'images/bg/contactbanner.jpg'; $logoAbs = __DIR__ . '/../' . $logoRel; }
      $gpayRel = 'images/pay/gpay.png'; $gpayAbs = __DIR__ . '/../' . $gpayRel;
      $ppRel = 'images/pay/phonepe.png'; $ppAbs = __DIR__ . '/../' . $ppRel;
    ?>
    <div class="topbar">
      <div class="row">
        <div class="marq" aria-label="Offer ticker"><div class="marq-inner">✨ This Diwali, Light Up Your Celebrations with a Bang! Get up to 80% OFF on All Crackers – Limited Time Sale! 🎆</div> </div>
        
       
        
        
        
      </div>
    </div>

    <div class="brand-hero" >
      <div class="wrap">
        <div class="circle"><?php if(is_file($logoAbs)): ?><img src="/<?= htmlspecialchars($logoRel) ?>" alt="PKS Logo"><?php endif; ?></div>
        <div>
          <div class="title">PSK CRACKERS</div>
          <div class="underline" aria-hidden="true"></div>
          <div class="tag">Best Quality Crackers @ Whole Sale Price</div>
        </div>
        <nav class="nav-pill">
          <a class="<?= $cur==='home.php' || $cur==='index.php' ? 'active':'' ?>" href="/home.php">Home</a>
          <a class="<?= $cur==='about.php' ? 'active':'' ?>" href="/about.php">About</a>
          <a class="<?= $cur==='shop.php' ? 'active':'' ?>" href="/shop.php">Pricelist</a>
          <a class="<?= $cur==='safety.php' ? 'active':'' ?>" href="/safety.php">Safety Tips</a>
          <a class="<?= $cur==='contact.php' ? 'active':'' ?>" href="/contact.php">Contact</a>
        </nav>
        <button id="menuBtn" class="hamburger" aria-label="Open menu" aria-controls="mobileMenu" aria-expanded="false">
          <span class="bar"></span>
          <span class="bar"></span>
          <span class="bar"></span>
        </button>
      </div>
    </div>

    <!-- Mobile menu overlay -->
    <div id="mobileMenu" class="mobile-menu" hidden>
      <div class="panel">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px">
          <strong>Menu</strong>
          <button id="menuClose" class="hamburger" aria-label="Close menu"><span class="bar"></span><span class="bar"></span><span class="bar" style="transform:rotate(90deg)"></span></button>
        </div>
        <div class="links">
          <a href="/home.php">Home</a>
          <a href="/about.php">About</a>
          <a href="/shop.php">Pricelist</a>
          <a href="/safety.php">Safety Tips</a>
          <a href="/contact.php">Contact</a>
        </div>
      </div>
    </div>

    <script>
      (function(){
        const btn = document.getElementById('menuBtn');
        const menu = document.getElementById('mobileMenu');
        const closeBtn = document.getElementById('menuClose');
        if (!btn || !menu) return;
        function open(){ menu.classList.add('open'); menu.hidden=false; btn.setAttribute('aria-expanded','true'); document.body.style.overflow='hidden'; }
        function close(){ menu.classList.remove('open'); menu.hidden=true; btn.setAttribute('aria-expanded','false'); document.body.style.overflow=''; }
        btn.addEventListener('click', ()=>{ if(menu.classList.contains('open')) close(); else open(); });
        if (closeBtn) closeBtn.addEventListener('click', close);
        menu.addEventListener('click', (e)=>{ if(e.target===menu) close(); });
        window.addEventListener('keydown', (e)=>{ if(e.key==='Escape') close(); });
      })();
    </script>
   
