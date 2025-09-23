<?php
$title = 'About — PKS Crackers';
$extraHead = '<style>
  /* Pure white background just for About page */
  :root { --bg:#ffffff; --text:#111827; --muted:#4b5563; }
  body{ background: var(--bg) !important; color: var(--text); }
  header.site::before{ background: linear-gradient(to bottom, rgba(255,255,255,.96), rgba(255,255,255,.9), transparent) !important; border-bottom:1px solid rgba(0,0,0,.06) }
  .wrap{padding: 60px 20px;
    max-width: 1200px;
    margin: 0 auto;}
  a{color:#2563eb}
</style>';
include __DIR__ . '/inc/header.php';
?>
<style>
    
    
   
 
.about-section {
  display: grid;
  grid-template-columns: 1fr 1fr; /* two equal columns */
  gap: 30px;
  align-items: center;
  padding: 60px 20px;
  max-width: 1200px;
  margin: 0 auto;
}

.about-text h1 {
  font-size: 2rem;
  margin-bottom: 15px;
}

.about-text p {
  line-height: 1.6;
  margin-bottom: 15px;
}

.about-text a {
  display: inline-block;
  margin-top: 10px;
  padding: 10px 20px;
  background: #f8b119;
  color: #fff;
  border-radius: 6px;
  text-decoration: none;
  transition: 0.3s;
}
.about-text a:hover {
  background: #cc5200;
}

.about-image img {
  width: 100%;
  border-radius: 12px;
}

/* Responsive for mobile */
@media (max-width: 768px) {
  .about-section {
    grid-template-columns: 1fr; /* stack on mobile */
    text-align: center;
  }
  .about-text {
    order: 2; /* show text below image */
  }
  .about-image {
    order: 1;
  }
}





/* Container */
.vm-section {
  max-width: 1100px;
  margin: 48px auto;
  padding: 24px;
  display: grid;
  gap: 24px;
}

/* Grid: 2 columns on wide screens, 1 on small */
.vm-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
  align-items: start;
}

/* Card styling */
.vm-card {
  background: linear-gradient(180deg, #ffffff 0%, #fbfbfb 100%);
  border-radius: 12px;
  padding: 28px;
  box-shadow: 0 6px 18px rgba(15, 23, 42, 0.06);
  transition: transform 0.28s ease, box-shadow 0.28s ease;
  display: flex;
  gap: 18px;
  align-items: flex-start;
}
.vm-card:hover {
  transform: translateY(-6px);
  box-shadow: 0 12px 30px rgba(15, 23, 42, 0.10);
}

/* Icon box */
.vm-icon {
  min-width: 68px;
  min-height: 68px;
  border-radius: 14px;
  display: grid;
  place-items: center;
  background: linear-gradient(135deg, rgba(255,102,0,0.12), rgba(255,153,51,0.06));
  flex-shrink: 0;
}

/* Headings & text */
.vm-title {
  margin: 0 0 8px;
  font-size: 1.25rem;
  line-height: 1.2;
  color: #0f172a;
}

.vm-desc {
  margin: 0;
  color: #475569;
  line-height: 1.65;
  font-size: 0.95rem;
}



/* Mobile */
@media (max-width: 768px) {
  .vm-grid {
    grid-template-columns: 1fr;
  }
  .vm-section { padding: 16px; }
  .vm-card { padding: 20px; gap: 14px; }
  .vm-icon { min-width:56px; min-height:56px; border-radius:10px; }
  .vm-title { font-size: 1.125rem; }
}





.brand-carousel {
  padding: 40px 0;
 
}
.swiper-slide {
  display: flex;
  align-items: center;
  justify-content: center;
}
.swiper-slide img {
  max-height: 60px;
  width: auto;
  /*filter: grayscale(100%);*/
  transition: 0.3s;
}
.swiper-slide img:hover {
  filter: grayscale(0%);
  transform: scale(1.05);
}
  
    
</style>
<style>
   /* Full hero */
  .hero{position:relative;height:64vh;min-height:440px;display:grid;place-items:center;overflow:hidden}
  .hero .bg{position:absolute;inset:-10%;background:radial-gradient(800px 300px at 20% 20%, rgba(225,29,72,.35), transparent), radial-gradient(800px 300px at 80% 80%, rgba(249,115,22,.35), transparent)}
  /* Fill the hero area with the banner image */
  .hero .stars{position:absolute;inset:0;background:url('images/bg/diwalisale.jpg') center/cover no-repeat}
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
  
   <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css" />
   
   <section class="hero1">
  <img class="hero-img" src="images/bg/diwalisale.jpg" alt="Hero Image">
  <canvas id="fw"></canvas>
</section>

   
   
   
 <!--<section class="hero">
     <div class="bg"></div>
     <div class="stars"></div>
     <canvas id="fw"></canvas>
   </section>-->
   <!-- <div class="wrap">
     <h1>About PKS Crackers</h1>
     <p>PKS Crackers brings festive joy with a curated selection of high‑quality crackers at great prices. Our focus is on safety, authenticity, and hassle‑free delivery.</p>
     <p>We partner with trusted manufacturers and continuously update our catalog to offer fresh combos and seasonal specials.</p>
  <p><a href="home.php">Return Home</a></p>
   </div>-->
   
   
  
<div class="about-section">
      <div class="about-image">
    <img src="images/abt.png" alt="PKS Crackers">
  </div>
  <div class="about-text">
    <h1>About PKS Crackers</h1>
    <p>PKS Crackers brings festive joy with a curated selection of high-quality crackers at great prices. Our focus is on safety, authenticity, and hassle-free delivery.</p>
    <p>We partner with trusted manufacturers and continuously update our catalog to offer fresh combos and seasonal specials.</p>
    <p>Welcome to PSK CRACKERS , your one-stop destination for high-quality crackers available both in retail and wholesale! As one of the leading cracker stores in the region, we offer an extensive variety of fireworks ranging from sparklers, rockets, flowerpots, and more, ensuring celebrations filled with joy, color, and excitement.<br><br>

Our shop crackers to all types of events, from festivals like Diwali and New Year to weddings, birthdays, and special occasions. We proudly maintain the highest safety standards, ensuring that our products are not only vibrant but also reliable and safe for use.<br><br>

Whether you're looking for a small pack to light up a cozy family gathering or bulk orders for large events or businesses, we provide competitive pricing and premium quality products that never fail to impress.</p>
    <p><a href="home.php">Return Home</a></p>
  </div>

</div>





<section class="vm-section" aria-labelledby="vm-heading">
  <h1>
    Our Vision & Mission
  </h1>

  <div class="vm-grid">
    <!-- Vision card -->
    <article class="vm-card" aria-labelledby="vision-title" role="region">
      <div class="vm-icon" aria-hidden="true">
        <!-- Simple SVG icon (eye + star) -->
        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <path d="M12 5C7.5 5 3.7 7.6 2 12c1.7 4.4 5.5 7 10 7s8.3-2.6 10-7c-1.7-4.4-5.5-7-10-7z" stroke="#ff6600" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
          <circle cx="12" cy="12" r="3" stroke="#ff6600" stroke-width="1.4"/>
        </svg>
      </div>

      <div>
        <h3 id="vision-title" class="vm-title">Our Vision</h3>
      
        <p class="vm-desc">
         Our vision is to be the best wholesale & retail dealer for all kind of fancy crackers & giftboxes to our beloved customers.
        </p>
       
      </div>
    </article>

    <!-- Mission card -->
    <article class="vm-card" aria-labelledby="mission-title" role="region">
      <div class="vm-icon" aria-hidden="true">
        <!-- Simple SVG icon (target/aim) -->
        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <circle cx="12" cy="12" r="8" stroke="#0ea5a4" stroke-width="1.4"/>
          <circle cx="12" cy="12" r="4" stroke="#0ea5a4" stroke-width="1.6"/>
          <path d="M20 12h2" stroke="#0ea5a4" stroke-width="1.4" stroke-linecap="round"/>
        </svg>
      </div>

      <div>
        <h3 id="mission-title" class="vm-title">Our Mission</h3>
        
        <p class="vm-desc">
        To provide Quality & Innovative Fireworks products to our valuable customers at reasonable price and light up all their celebrations.
        </p>
      
      </div>
    </article>
  </div>
</section>




    
    
   
 <div class="wrap">
    <h1>Brands We Handle</h1>
<div class="brand-carousel ">

  <div class="swiper myBrandSwiper">
      
    <div class="swiper-wrapper">
      <div class="swiper-slide"><img src="images/brands/b1.jpeg" alt="Brand 1"></div>
      <div class="swiper-slide"><img src="images/brands/b2.webp" alt="Brand 2"></div>
      <div class="swiper-slide"><img src="images/brands/b3.webp" alt="Brand 3"></div>
      <div class="swiper-slide"><img src="images/brands/b4.png" alt="Brand 4"></div>
      <div class="swiper-slide"><img src="images/brands/b5.jpg" alt="Brand 5"></div>
      <div class="swiper-slide"><img src="images/brands/b6.jpg" alt="Brand 6"></div>
      <div class="swiper-slide"><img src="images/brands/b7.webp" alt="Brand 6"></div>
    </div>
  </div>
</div>
</div>

<!-- Swiper JS -->
<script src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js"></script>

<script>
var swiper = new Swiper(".myBrandSwiper", {
  slidesPerView: 5,
  spaceBetween: 30,
  loop: true,
  autoplay: {
    delay: 2000,
    disableOnInteraction: false,
  },
  breakpoints: {
    320: { slidesPerView: 2, spaceBetween: 10 },
    640: { slidesPerView: 3, spaceBetween: 20 },
    991: { slidesPerView: 4, spaceBetween: 20 },
    1200: { slidesPerView: 5, spaceBetween: 30 }
  }
});
</script>

    
    
    
    
    
    
    
    
<?php include __DIR__ . '/inc/footer.php'; ?>
