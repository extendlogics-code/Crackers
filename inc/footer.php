<?php
// Shared site footer (dark, multi-column with optional QR panels)
// Optional images used if present:
//   images/bg/contactbanner.jpg (logo/banner), images/pay/qr1.png, images/pay/qr2.png
// If images are missing, the sections gracefully hide.

// Resolve optional assets
$logoRel = 'images/pkslogo.png';
$logoAbs = __DIR__ . '/../' . $logoRel;
if (!is_file($logoAbs)) { $logoRel = 'images/pkslogo.png'; $logoAbs = __DIR__ . '/../' . $logoRel; }
$qr1Rel = 'images/pay/qr1.png';
$qr2Rel = 'images/pay/qr2.png';
$qr1Abs = __DIR__ . '/../' . $qr1Rel;
$qr2Abs = __DIR__ . '/../' . $qr2Rel;
?>
    <style>
      .site-footer{background:#1f2937;color:#e5e7eb}
      .footer-grid{max-width:1200px;margin:0 auto;padding:28px 16px;display:grid;grid-template-columns:1.1fr 1.2fr 0.9fr 0.9fr;gap:22px;align-items:start}
      .footer-card{width:200px;height:240px;background:#fff;border-radius:6px;display:grid;place-items:center;overflow:hidden}
      .footer-card img{max-width:100%;max-height:100%;object-fit:contain}
      .footer-logo{width:160px;height:160px;border-radius:6px;background:#fff;display:grid;place-items:center;overflow:hidden}
      .footer-bottom{background:linear-gradient(90deg,#f59e0b,#fbbf24);color:#000}
      .footer-bottom-inner{max-width:1200px;margin:0 auto;padding:10px 16px;text-align:center;font-style:italic;font-weight:600}
      @media (max-width:1024px){ .footer-grid{grid-template-columns:1fr 1fr 1fr} }
      @media (max-width:820px){ .footer-grid{grid-template-columns:1fr 1fr} .footer-card{width:180px;height:220px} }
      @media (max-width:560px){ .footer-grid{grid-template-columns:1fr;gap:16px} .footer-logo{width:120px;height:120px;margin:0 auto} .footer-card{width:160px;height:200px;margin:0 auto;}
        .footer-left{text-align:center}
      }
    </style>
    <footer class="site-footer">
      <div class="footer-grid">
        <!-- Logo + Tagline -->
        <div class="footer-left">
          <?php if (is_file($logoAbs)): ?>
            <div class="footer-logo"><img src="/<?= htmlspecialchars($logoRel) ?>" alt="PKS Logo"></div>
          <?php endif; ?>
          <div style="margin-top:10px;color:#ffffff"><strong>Best Quality Crackers</strong><br>@ Whole Sale Price</div>
        </div>

        <!-- Address/Contacts -->
        <div>
          <div style="color:#9ca3af;margin-bottom:6px">Address:</div>
          <div>Satlur - Madathupatti road, Near Vasantham Nagar, Thayilpatti - 626128</div>
          <div style="color:#9ca3af;margin:10px 0 6px">Phone:</div>
          <div>9655505852<br>9789795048<br>9585254326</div>
          <div style="color:#9ca3af;margin:10px 0 6px">Whatsapp:</div>
          <div>9655505852<br>9789795048<br>9585254326</div>
          <div style="color:#9ca3af;margin:10px 0 6px">Email:</div>
          <div><a href="mailto:pskcrackers77@gmail.com" style="color:#93c5fd;text-decoration:none">pskcrackers77@gmail.com</a></div>
        </div>

        <!-- QR 1 -->
        <?php if (is_file($qr1Abs)): ?>
        <div>
          <div style="color:#9ca3af;margin-bottom:8px">Scan to Pay</div>
          <div class="footer-card"><img src="/<?= htmlspecialchars($qr1Rel) ?>" alt="UPI QR"></div>
        </div>
        <?php endif; ?>

        <!-- QR 2 -->
        <?php if (is_file($qr2Abs)): ?>
        <div>
          <div style="color:#9ca3af;margin-bottom:8px">Scan to Pay</div>
          <div class="footer-card"><img src="/<?= htmlspecialchars($qr2Rel) ?>" alt="UPI QR"></div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Bottom bar -->
      <div class="footer-bottom">
        <div class="footer-bottom-inner">
          Copyright © <?= date('Y') ?>, PSK CRACKERS. All rights reserved
        </div>
      </div>
    </footer>
  </body>
</html>
