 <?php
$title = 'Safety — PKS Crackers';
$extraHead = '<style>
  :root { --bg:#ffffff; --text:#111827; --muted:#4b5563; --brand:#e11d48; --brand2:#f97316 }
  body{ background:#ffffff !important; color:var(--text) }
  header.site::before{ background: linear-gradient(to bottom, rgba(255,255,255,.96), rgba(255,255,255,.9), transparent) !important; border-bottom:1px solid rgba(0,0,0,.06) }
  .wrap{max-width:1000px;margin:20px auto;padding:0 16px}
  h1{margin:0 0 8px}
  h2{margin:20px 0 8px}
  ul{margin:8px 0 16px}
  li{margin:6px 0}
  .note{padding:10px;border-radius:8px;background:#fff8f7;border:1px solid #ffd7d0;color:#7c2d12}
</style>';
include __DIR__ . '/inc/header.php';
  // Optional safety banner image just below the menu if present
      $safetyRel = 'images/bg/safety.jpg';
      $safetyAbs = __DIR__ . '/../' . $safetyRel;
      if (is_file($safetyAbs)) {
        echo '<div class="safety-strip active"><img src="/' . htmlspecialchars($safetyRel) . '" alt="Safety"></div>';
      }
?>
<style>
   /* Full hero */
  .hero{position:relative;height:64vh;min-height:440px;display:grid;place-items:center;overflow:hidden}
  .hero .bg{position:absolute;inset:-10%;background:radial-gradient(800px 300px at 20% 20%, rgba(225,29,72,.35), transparent), radial-gradient(800px 300px at 80% 80%, rgba(249,115,22,.35), transparent)}
  /* Fill the hero area with the banner image */
  .hero .stars{position:absolute;inset:0;background:url('images/bg/safety.jpg') center/cover no-repeat}
  .hero .inner{position:relative;z-index:2;text-align:center;max-width:820px;padding:0 16px}
  .hero h1{margin:0 0 8px;font-size:44px;line-height:1.1}
  .hero p{margin:0 0 14px;color:var(--muted);font-size:18px}
  .cta-row{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
  .btn{appearance:none;border:0;border-radius:999px;padding:12px 16px;background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff;font-weight:800;cursor:pointer}
  .ghost{background:transparent;border:1px solid rgba(255,255,255,.2)}
  #fw{position:absolute;inset:0;z-index:1;pointer-events:none;mix-blend-mode:screen}






.hero1 {
  position: relative;
  width: 100%;
  display: grid;
  place-items: center;
  overflow: hidden;
}

.hero1 .hero-img {
  width: 100%;       /* fit full width */
  height: auto;      /* maintain aspect ratio */
  display: block;
}

@media (max-width: 768px) {
  .hero1 {
    height: auto;    /* height adjusts to image automatically */
  }

  .hero1 .hero-img {
    width: 100%;     /* fill container width */
    height: auto;    /* prevent zoom/crop */
  }
}





  </style>
  
  
  
 <!--<section class="hero">
  <div class="bg"></div>
  <div class="stars"></div>
     <canvas id="fw"></canvas>
   </section>-->
    
    <section class="hero1">
  <img class="hero-img" src="images/bg/safety.jpg" alt="Hero Image">
  <canvas id="fw"></canvas>
</section>

    
    
    
    
    <!-- Safety Tips: Dos & Don'ts -->
<style>
/* Container */
.safety-section {
  max-width: 1100px;
  margin: 36px auto;
  padding: 20px;
  font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
}

/* Grid: two columns on desktop, single column on mobile */
.safety-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
  align-items: start;
}

/* Card base */
.safety-card {
  border-radius: 12px;
  padding: 22px;
  box-shadow: 0 8px 20px rgba(15,23,42,0.06);
  background: #fff;
  transition: transform 0.22s ease, box-shadow 0.22s ease;
}
.safety-card:focus-within,
.safety-card:hover {
  transform: translateY(-6px);
  box-shadow: 0 18px 36px rgba(15,23,42,0.08);
}

/* Header */
.safety-header {
  display:flex;
  gap:12px;
  align-items:center;
  margin-bottom:12px;
}
.safety-icon {
  width:48px;
  height:48px;
  display:grid;
  place-items:center;
  border-radius:10px;
  flex-shrink:0;
}
.safety-title {
  margin:0;
  font-size:1.125rem;
  letter-spacing:0.2px;
}

/* Do / Don't colorings */
.safety-do .safety-icon { background: linear-gradient(135deg, rgba(6,182,212,0.12), rgba(34,197,94,0.06)); }
.safety-dont .safety-icon { background: linear-gradient(135deg, rgba(255,99,71,0.10), rgba(244,63,94,0.04)); }

.safety-do .safety-title { color:#065f46; }    /* greenish */
.safety-dont .safety-title { color:#7f1d1d; }  /* reddish */

/* List */
.safety-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 10px;
}
.safety-item {
  display:flex;
  gap: 12px;
  align-items:flex-start;
}
.safety-bullet {
  width:18px;
  height:18px;
  border-radius:4px;
  flex-shrink:0;
  margin-top:4px;
}
.safety-do .safety-bullet { background:#10b981; }    /* green */
.safety-dont .safety-bullet { background:#ef4444; }  /* red */

.safety-text {
  color:#0f172a;
  line-height:1.5;
  font-size:0.95rem;
  margin:0;
}

/* Small note / footer */
.safety-note {
  margin-top:14px;
  font-size:0.85rem;
  color:#475569;
}

/* Responsive */
@media (max-width: 780px) {
  .safety-grid { grid-template-columns: 1fr; }
  .safety-header { gap:10px; }
  .safety-icon { width:44px; height:44px; border-radius:8px; }
}
</style>

<section class="safety-section" aria-labelledby="safety-heading">
  <h1>
    Safety Tips — Do's & Don'ts
  </h1>
  <br>

  <div class="safety-grid">
    <!-- DOs -->
    <div class="safety-card safety-do" role="region" aria-labelledby="dos-title">
      <div class="safety-header">
        <div class="safety-icon" aria-hidden="true">
          <!-- Check / shield icon -->
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
            <path d="M12 2l7 3v5c0 5-3.5 9.5-7 11-3.5-1.5-7-6-7-11V5l7-3z" stroke="#059669" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M9 12l2 2 4-4" stroke="#059669" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>
        <h3 id="dos-title" class="safety-title">Do — Safe Practices</h3>
      </div>

      <ul class="safety-list" aria-label="Safety do list">
        <li class="safety-item">
          <span class="safety-bullet" aria-hidden="true"></span>
          <p class="safety-text"><strong>Instructions.</strong> 
Display fireworks as per the instructions mentioned on the pack.</p>
        </li>

        <li class="safety-item">
          <span class="safety-bullet" aria-hidden="true"></span>
          <p class="safety-text"><strong>Distance.</strong> 
Light only one firework at a time, by one person. Others should watch from a safe distance.</p>
        </li>

        <li class="safety-item">
          <span class="safety-bullet" aria-hidden="true"></span>
          <p class="safety-text"><strong>Branded Fireworks.</strong> 
Buy fireworks from authorized / reputed manufacturers only.</p>
        </li>

        <li class="safety-item">
          <span class="safety-bullet" aria-hidden="true"></span>
          <p class="safety-text"><strong>Water.</strong> 
Keep two buckets of water handy. In the event of fire or any mishap.</p>
        </li>

        <li class="safety-item">
          <span class="safety-bullet" aria-hidden="true"></span>
          <p class="safety-text"><strong>Outdoor.</strong> 
Use fireworks only outdoor.</p>
        </li>
      </ul>

    
    </div>

    <!-- DON'Ts -->
    <div class="safety-card safety-dont" role="region" aria-labelledby="donts-title">
      <div class="safety-header">
        <div class="safety-icon" aria-hidden="true">
          <!-- Cross / warning icon -->
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
            <circle cx="12" cy="12" r="9" stroke="#ef4444" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M9.5 9.5l5 5M14.5 9.5l-5 5" stroke="#ef4444" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>
        <h3 id="donts-title" class="safety-title">Don't — Unsafe Practices</h3>
      </div>

      <ul class="safety-list" aria-label="Safety don't list">
        <li class="safety-item">
          <span class="safety-bullet" aria-hidden="true"></span>
          <p class="safety-text"><strong>Don't make tricks.</strong> 
Never make your own fireworks.</p>
        </li>

        <li class="safety-item">
          <span class="safety-bullet" aria-hidden="true"></span>
          <p class="safety-text"><strong>Don't Touch it.</strong> 
After fireworks display never pick up fireworks that may be left over, they still may be active.</p>
        </li>

        <li class="safety-item">
          <span class="safety-bullet" aria-hidden="true"></span>
          <p class="safety-text"><strong>Don't relight.</strong> 
Never try to re-light or pick up fireworks that have not ignited fully.</p>
        </li>

        <li class="safety-item">
          <span class="safety-bullet" aria-hidden="true"></span>
          <p class="safety-text"><strong>Don't carry it.</strong> 
Never carry fireworks in your pockets.</p>
        </li>

        <li class="safety-item">
          <span class="safety-bullet" aria-hidden="true"></span>
          <p class="safety-text"><strong>Don't wear loose clothes.</strong> 
Do not wear loose clothing while using fireworks.</p>
        </li>
      </ul>

      
    </div>
  </div>
</section>

    
    
    
    
    
    
    
    
    
    
  <div class="wrap">
    <h1>Safety Guidelines</h1>
    <p class="note">Celebrate responsibly. Always read and follow the instructions printed on each product. Keep water or sand nearby.</p>

    <h2>Safety Precautions</h2>
    <ul>
      <li>Use crackers only in open outdoor spaces away from buildings, vehicles, and dry vegetation.</li>
      <li>Keep children under close adult supervision at all times.</li>
      <li>Maintain a safe distance while lighting. Use an extended lighting stick; never bend over the cracker.</li>
      <li>Do not attempt to relight malfunctioning (dud) crackers. Wait 10–15 minutes, then soak in water.</li>
      <li>Wear cotton clothing; avoid loose synthetic fabric. Tie long hair and remove loose accessories.</li>
      <li>Store crackers in a cool, dry place away from heat sources, flames, and direct sunlight.</li>
      <li>Light one item at a time; keep unused items sealed and away from the lighting area.</li>
      <li>Never point rockets or fountains at people, houses, or power lines. Use a stable bottle/stand for rockets.</li>
      <li>Keep pets indoors in a calm environment; avoid loud items around animals.</li>
    </ul>

    <h2>Emergency Tips</h2>
    <ul>
      <li>For minor burns, cool the area under running water for 10–20 minutes; do not apply oil/ice.</li>
      <li>Seek medical help for eye injuries immediately. Do not rub or wash the eye.</li>
      <li>Keep a first‑aid kit and water bucket/blanket handy in the celebration area.</li>
    </ul>

    <h2>Storage & Handling</h2>
    <ul>
      <li>Keep crackers in original packaging. Do not tamper with fuses or mix loose items.</li>
      <li>Transport in small quantities and avoid keeping them inside hot vehicle cabins.</li>
      <li>Dispose of spent items only after soaking them in water to ensure they are fully extinguished.</li>
    </ul>

    <p><a href="shop.php" style="color:#2563eb">Back to Shop</a></p>
  </div>
<?php include __DIR__ . '/inc/footer.php'; ?>

