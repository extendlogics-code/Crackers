<?php
$title = 'PKS Crackers — Home';
require_once __DIR__ . '/lib/db.php';

function product_img(string $sku, string $name = '', string $fallback = ''): string {
  $base = __DIR__ . '/images/products/' . preg_replace('/[^A-Za-z0-9_-]/','-',$sku);
  if (is_dir($base)) {
    foreach (scandir($base) as $f) {
      if ($f === '.' || $f === '..') continue;
      $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
      if (in_array($ext, ['jpg','jpeg','png','webp','gif'])) {
        return 'images/products/' . basename($base) . '/' . rawurlencode($f);
      }
    }
  }
  return $fallback ?: 'images/banners/slide1.svg';
}

// Load featured products from DB, fallback to JSON
$featured = [];
try {
  $pdo = get_pdo();
  $st = $pdo->query("SELECT sku, name, category, price, discount_pct, image_url FROM products ORDER BY discount_pct DESC, price ASC LIMIT 12");
  $featured = $st->fetchAll();
} catch (Throwable $__) {}
if (!$featured) {
  $json = @json_decode(@file_get_contents(__DIR__ . '/data/products.json'), true) ?: [];
  foreach (array_slice($json, 0, 12) as $it) {
    $featured[] = [
      'sku' => (string)($it['id'] ?? ''),
      'name' => (string)($it['name'] ?? ''),
      'category' => (string)($it['category'] ?? ''),
      'price' => (float)($it['price'] ?? 0),
      'discount_pct' => (int)($it['discount_pct'] ?? 0),
      'image_url' => (string)($it['image'] ?? ''),
    ];
  }
}

// Categories
$catList = (function(){
  require_once __DIR__ . '/lib/categories.php';
  return load_categories();
})();
$catList = array_slice($catList, 0, 8);

$extraHead = <<<HEAD
<style>
  /* Light theme for Home page */
  :root { --bg:#ffffff; --text:#111827; --muted:#4b5563; --brand:#e11d48; --brand2:#f97316 }
  body{ background:#ffffff !important; color: var(--text); }
  /* Match header to light theme */
  header.site::before{ background: linear-gradient(to bottom, rgba(255,255,255,.96), rgba(255,255,255,.9), transparent) !important; border-bottom:1px solid rgba(0,0,0,.06) }
  /* Promo strip */
  .promo{display:flex;align-items:center;justify-content:center;gap:10px;padding:8px 12px;background:linear-gradient(90deg,rgba(225,29,72,.25),rgba(249,115,22,.25));border-bottom:1px solid rgba(255,255,255,.12)}
  .promo strong{color:#fff}
  .promo a{color:#ffd7a3;text-decoration:none}

  /* Full hero */
  .hero{position:relative;height:64vh;min-height:440px;display:grid;place-items:center;overflow:hidden}
  .hero .bg{position:absolute;inset:-10%;background:radial-gradient(800px 300px at 20% 20%, rgba(225,29,72,.35), transparent), radial-gradient(800px 300px at 80% 80%, rgba(249,115,22,.35), transparent)}
  /* Fill the hero area with the banner image */
  .hero .stars{position:absolute;inset:0;background:url('images/bg/dwaili.jpg') center/cover no-repeat}
  .hero .inner{position:relative;z-index:2;text-align:center;max-width:820px;padding:0 16px}
  .hero h1{margin:0 0 8px;font-size:44px;line-height:1.1}
  .hero p{margin:0 0 14px;color:var(--muted);font-size:18px}
  .cta-row{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
  .btn{appearance:none;border:0;border-radius:999px;padding:12px 16px;background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff;font-weight:800;cursor:pointer;text-decoration: none;}
  .ghost{background:transparent;border:1px solid rgba(255,255,255,.2)}
  #fw{position:absolute;inset:0;z-index:1;pointer-events:none;mix-blend-mode:screen}

  /* Wave divider */
  .wave{display:block;height:60px;background:linear-gradient(to bottom, transparent, rgba(255,255,255,.04));mask:radial-gradient(120% 100% at 50% -10%, #000 50%, transparent 51%)}

  .wrap{max-width:1200px;margin:0 auto;padding:0 16px}

  /* Intro section */
  .welcome-title{margin:18px 0 8px;text-align:center;font-size:36px;font-family:Georgia, 'Times New Roman', serif}
  .intro{display:grid;grid-template-columns:1.2fr .8fr;gap:18px;margin:10px 0 22px}
  .intro h2{margin:0 0 8px;font-size:28px}
  .intro p{color:var(--muted)}
  .intro .img{border-radius:14px;overflow:hidden;border:1px solid rgba(0,0,0,.06);background:#fff}
  @media (max-width:900px){ .intro{grid-template-columns:1fr} }

  /* Features */
  .features{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:18px auto}
  .feat{padding:14px;border-radius:12px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1)}
  @media (max-width:900px){ .features{grid-template-columns:repeat(2,1fr)} }

  /* Trending products */
  .trend{margin:10px 0 24px}
  .trend h3{margin:0 0 10px}
  .rail{display:flex;gap:12px;overflow:auto;padding-bottom:8px}
  .card{min-width:200px;max-width:200px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:12px;overflow:hidden}
  .card img{display:block;width:100%;height:140px;object-fit:cover}
  .card .px{padding:10px}
  .badge{display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.06);font-size:12px;color:var(--muted)}
  /* Product grid */
  .grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
  .grid .card{min-width:unset;max-width:unset}
  @media (max-width:1000px){ .grid{grid-template-columns:repeat(3,1fr)} }
  @media (max-width:700px){ .grid{grid-template-columns:repeat(2,1fr)} }

  /* Categories */
  .cat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:8px 0 24px}
  .cat{display:flex;align-items:center;gap:10px;padding:12px;border-radius:12px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);text-decoration:none;color:var(--text)}
  .cat .icon{width:40px;height:40px;border-radius:12px;display:grid;place-items:center;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12)}
  @media (max-width:900px){ .cat-grid{grid-template-columns:repeat(2,1fr)} }

  /* Story */
  .story{display:grid;grid-template-columns:1.2fr .8fr;gap:16px;margin:10px 0 24px}
  .story .img{border-radius:14px;overflow:hidden;border:1px solid rgba(255,255,255,.1);min-height:260px;background:url('images/banners/slide2.svg') center/cover no-repeat}
  .story .box{padding:14px;border-radius:12px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1)}
  @media (max-width:900px){ .story{grid-template-columns:1fr} }
  /* Festive banner */
  .banner{position:relative;border-radius:14px;overflow:hidden;border:1px solid rgba(255,255,255,.1);min-height:160px;background:linear-gradient(135deg, rgba(225,29,72,.35), rgba(249,115,22,.25));display:flex;align-items:center}
  .banner .inner{padding:18px}
  .banner h3{margin:0 0 6px}
  .banner p{margin:0;color:var(--muted)}
  /* Floating CTA */
  .floating-cta{position:fixed;right:16px;bottom:16px;z-index:50}
  .floating-cta a{display:inline-flex;align-items:center;gap:8px;padding:10px 12px;border-radius:999px;text-decoration:none;background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff;font-weight:700;box-shadow:0 8px 18px rgba(225,29,72,.35)}

  /* FAQ */
  .faq details{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:10px}
  .faq summary{cursor:pointer}
  
  
/* Mobile adjustments */
@media (max-width: 768px) {
  .hero {
    position: relative;
    height: auto;         /* allow height to adjust */
    min-height: 250px;    /* minimum height */
    display: grid;
    place-items: center;
    overflow: hidden;
  }

  .hero .stars {
    position: absolute;
    inset: 0;
    background: url('images/bg/dwaili.jpg') center center no-repeat;
    background-size: contain; /* fit whole image without cropping or zoom */
  }

  .hero .bg {
    position: absolute;
    inset: 0;
    background: transparent;
  }
}


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
HEAD;
include __DIR__ . '/inc/header.php';
?>



<section class="hero1">
 
  <img class="hero-img" src="images/bg/dwaili.jpg" alt="Hero Image">
  <canvas id="fw"></canvas>
</section>
    <!-- HERO -->
   <!-- <section class="hero">
     <div class="bg"></div>
     <div class="stars"></div>
     <canvas id="fw"></canvas>-->
      <!-- <div class="inner">
        <h1>Celebrate Brighter. Celebrate Safer.</h1>
        <p>Premium crackers curated for every festival night — rockets, sparklers, flower pots and more at honest prices.</p>
        <div class="cta-row">
          <a class="btn" href="shop.php">Shop Now</a>
          <a class="btn ghost" href="about.php">Why PKS</a>
        </div>
      </div> -->
   <!-- </section>-->
    <div class="wave"></div>

    <div class="wrap">
      <!-- WELCOME / INTRO -->
      <h1 class="welcome-title">Welcome to PSK CRACKERS</h1>
      <section >
        <div>
          <h2>Diwali Best Crackers In Sivakasi</h2>
          <p>We supply quality crackers at the lowest price. Crackers are available in different specifications as per the requirements of our clients. We provide a variety of firecrackers including single and multi-sound crackers, sparklers, ground chakkars, flower pots, twinkling stars, pencils, fancy rockets, aerial and fancy fireworks, fancy whistling varieties, atomroses, doras garlands, cone crackers and electric crackers.</p>
          <p>We are specialists in fireworks gift boxes and we have a variety of gift boxes. Crackers are procured from reliable vendors and are known for low emission of noise and pollution. Our crackers are available in various size packs; it is suitable for all types of occasions.</p>
          <p>At PSK CRACKERS, customer satisfaction is our top priority. Our friendly staff is always on hand to help you choose the best crackers that suit your needs. With a reputation built on trust, safety, and affordability, we have established strong relationships with our suppliers to bring you the very best in crackers.</p>
        </div>
      </section>
      <!-- FEATURES -->
      <!-- <section class="features">
        <div class="feat"><strong>Certified Quality</strong><br><span style="color:var(--muted)">ISI-marked brands</span></div>
        <div class="feat"><strong>Great Prices</strong><br><span style="color:var(--muted)">Seasonal offers</span></div>
        <div class="feat"><strong>Fast Delivery</strong><br><span style="color:var(--muted)">On-time dispatch</span></div>
        <div class="feat"><strong>Support</strong><br><span style="color:var(--muted)">Phone & WhatsApp</span></div>
      </section> -->

      <!-- POPULAR GRID -->
      <!-- <section class="trend">
        <h3>Popular Picks</h3>
        <div class="grid">
          <?php foreach (array_slice($featured, 0, 8) as $p): $sku=$p['sku']; $name=$p['name']; $price=(float)$p['price']; $disc=(int)($p['discount_pct']??0); $final=$price*(1-$disc/100); $img=product_img($sku,$name,(string)($p['image_url']??'')); ?>
          <article class="card">
            <img src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($name) ?>">
            <div class="px">
              <div><strong><?= htmlspecialchars($name) ?></strong></div>
              <div><span class="badge">Code: <?= htmlspecialchars($sku) ?></span></div>
              <div style="display:flex;align-items:center;gap:8px;margin-top:6px">
                <?php if ($disc>0): ?><small class="badge">-<?= (int)$disc ?>%</small><?php endif; ?>
                <div><?php if($disc>0): ?><s>₹<?= number_format($price,2) ?></s><?php endif; ?> <strong>₹<?= number_format($final,2) ?></strong></div>
              </div>
            </div>
          </article>
          <?php endforeach; ?>
        </div>
      </section> -->

      <!-- TRENDING -->
      <!-- <section class="trend">
        <h3>Trending Now</h3>
        <div class="rail">
          <?php foreach ($featured as $p): $sku=$p['sku']; $name=$p['name']; $price=(float)$p['price']; $disc=(int)($p['discount_pct']??0); $final=$price*(1-$disc/100); $img=product_img($sku,$name,(string)($p['image_url']??'')); ?>
          <article class="card">
            <img src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($name) ?>">
            <div class="px">
              <div><strong><?= htmlspecialchars($name) ?></strong></div>
              <div><span class="badge">Code: <?= htmlspecialchars($sku) ?></span></div>
              <div style="display:flex;align-items:center;gap:8px;margin-top:6px">
                <?php if ($disc>0): ?><small class="badge">-<?= (int)$disc ?>%</small><?php endif; ?>
                <div><?php if($disc>0): ?><s>₹<?= number_format($price,2) ?></s><?php endif; ?> <strong>₹<?= number_format($final,2) ?></strong></div>
              </div>
            </div>
          </article>
          <?php endforeach; ?>
        </div>
      </section> -->

      <!-- OUR PRODUCTS (categories) -->
      <section>
          <br>
          <br>
         <h1 class="welcome-title">Our Products</h1>
        <p style="color:var(--muted);max-width:900px text-align:center">Our motto is to make every festival celebration bright and safe.This, we bring out with our wide range of firecrackers. With over 200 varieties of crackers developed and marketed every year, we are among the most sought brands in the Sivakasi region and around the country. Our products are known for their safety and we take great efforts to ensure that all our orders are delivered in a standard time frame with an economical pricing.</p>
       <!-- <div class="cat-grid">
          <?php foreach ($catList as $c): ?>
            <a class="cat" href="shop.php?cat=<?= urlencode($c) ?>">
              <div class="icon">🎇</div>
              <div><?= htmlspecialchars($c) ?></div>
            </a>
          <?php endforeach; ?>
        </div>
        <div style="text-align:center;margin-top:10px">
          <a class="btn ghost" href="shop.php" style="text-decoration:none">View More Products</a>
        </div>-->
      </section>
      
      
      
      
      
      
      <section>
          
          <style>
/* Container */
.products-section {
  max-width: 1200px;
  margin: 50px auto;
  padding: 0 20px;
  text-align: center;
  font-family: system-ui, sans-serif;
}

.products-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 30px;
  margin-bottom: 30px;
}

.product-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  transition: transform 0.3s ease, box-shadow 0.3s ease;
}
.product-card:hover {
  transform: translateY(-6px);
}

.product-card img {
  width: 180px;
  height: 180px;
  border-radius: 50%;
  object-fit: cover;
  margin-bottom: 15px;
  border: 4px solid #f0f0f0;
  transition: 0.3s;
}
.product-card img:hover {
  border-color: #ff6600;
}

.product-name {
  font-size: 1rem;
  font-weight: 600;
  color: #333;
  margin: 0;
}

/* View More Button */
.view-more-btn {
  display: inline-block;
  padding: 12px 28px;
  font-size: 1rem;
  font-weight: 600;
  background: linear-gradient(135deg, var(--brand), var(--brand2));
  color: #fff;
  border-radius: 30px;
  text-decoration: none;
  transition: background 0.3s ease;
}
.view-more-btn:hover {
  background: #cc5200;
}

/* Responsive */
@media (max-width: 991px) {
  .products-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}
@media (max-width: 576px) {
  .products-grid {
    grid-template-columns: 1fr;
  }
}
</style>

<div class="products-section">
  <div class="products-grid">
    <div class="product-card">
      <img src="images/brands/p1.webp" alt="Cracker 1">
      <p class="product-name">Flower Pots</p>
    </div>
    <div class="product-card">
      <img src="images/brands/p2.webp" alt="Cracker 2">
      <p class="product-name">Sparklers</p>
    </div>
    <div class="product-card">
      <img src="images/brands/p3.webp" alt="Cracker 3">
      <p class="product-name">Ground Chakkars</p>
    </div>
    <div class="product-card">
      <img src="images/brands/p4.webp" alt="Cracker 4">
      <p class="product-name">Sky Shots</p>
    </div>
  </div>

  <a href="shop.php" class="view-more-btn">View More</a>
</div>

      </section>
      
      
      
      
      
      
      
      
      
      
      
      
      
      

      <!-- FESTIVE BANNER -->
    <!-- FESTIVE BANNER -->
<style>
.banner {
  position: relative;
  background: url('images/bg/dwaili.jpg') center/cover no-repeat;
  color: #fff;
  text-align: center;
  padding: 80px 20px;
  border-radius: 12px;
  overflow: hidden;
}

/* Dark overlay for readability */
.banner::before {
  content: "";
  position: absolute;
  inset: 0;
  background: rgba(0, 0, 0, 0.60);
}

.banner .inner {
  position: relative;
  max-width: 700px;
  margin: 0 auto;
  z-index: 1;
}

.banner h3 {
  font-size: 2rem;
  margin-bottom: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 1px;
}

.banner p {
  font-size: 1.1rem;
  line-height: 1.6;
  margin-bottom: 20px;
  color:white;
}

/* Button */
.banner .btn {
  display: inline-block;
  padding: 12px 28px;
  background: linear-gradient(135deg, var(--brand), var(--brand2));
  color: #fff;
  font-weight: 600;
  border-radius: 30px;
  text-decoration: none;
  transition: background 0.3s ease, transform 0.3s ease;
}
.banner .btn:hover {
  background: #cc5200;
  transform: translateY(-3px);
}

/* Responsive */
@media (max-width: 768px) {
  .banner {
    padding: 60px 16px;
  }
  .banner h3 {
    font-size: 1.5rem;
  }
  .banner p {
    font-size: 1rem;
  }
}
</style>

<section class="banner">
  <div class="inner">
    <h3>Bundle & Save</h3>
    <p>Check out combo packs curated for family celebrations. More sparkle, better value.</p>
    <div><a class="btn" href="shop.php">Browse Combos</a></div>
  </div>
</section>


      <!-- STORY -->
      <section class="story">
        <style>
.faq {
  
  padding: 0 16px;
  font-family: system-ui, sans-serif;
}

.faq h3 {
  text-align: center;
  margin-bottom: 20px;
  font-size: 1.5rem;
  color: #222;
}

.faq details {
  background: #fff;
  border-radius: 10px;
  padding: 14px 18px;
  border: 1px solid #e5e7eb;
  box-shadow: 0 2px 6px rgba(0,0,0,0.04);
  transition: all 0.3s ease;
}

.faq details[open] {
  border-color: #ff6600;
  box-shadow: 0 4px 14px rgba(255,102,0,0.15);
}

.faq summary {
  cursor: pointer;
  font-weight: 600;
  font-size: 1rem;
  color: #333;
  list-style: none;
  position: relative;
  padding-right: 25px;
}

/* Hide default triangle */
.faq summary::-webkit-details-marker {
  display: none;
}

/* Custom + / - icon */
.faq summary::after {
  content: "+";
  position: absolute;
  right: 0;
  top: 0;
  font-size: 1.2rem;
  color: #ff6600;
  transition: transform 0.3s ease;
}

.faq details[open] summary::after {
  content: "−";
  transform: rotate(180deg);
}

.faq details div {
  margin-top: 10px;
  font-size: 0.95rem;
  color: #555;
  line-height: 1.6;
  padding-right: 10px;
}
</style>

<section class="faq">
  <h3>FAQ</h3>
  <div style="display:grid;gap:12px">
    <details>
      <summary>Do you deliver to my area?</summary>
      <div>We serve most neighborhoods nearby. Share your pincode on WhatsApp to confirm.</div>
    </details>
    <details>
      <summary>Are products safe and certified?</summary>
      <div>Yes, we stock ISI-marked items from reputed manufacturers only.</div>
    </details>
    <details>
      <summary>What is the minimum order?</summary>
      <div>₹3000 including a fixed ₹150 shipping charge.</div>
    </details>
  </div>
</section>

        <div class="box">
          <h3>Our Promise</h3>
          <p style="color:var(--muted)">We hand-pick quality crackers from trusted brands, pack them safely, and deliver with care. Whether it’s a small family gathering or a grand celebration, we’ve got the sparkle you need.</p>
          <br>
          <a class="btn" href="contact.php">Talk to Us</a>
        </div>
      </section>
 
      <section>

          
          <!-- Swiper CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css" />

<style>
.brand-carousel {
  padding: 40px 0;
  background: #fff;
}
.swiper-slide {
  display: flex;
  align-items: center;
  justify-content: center;
}
.swiper-slide img {
  max-height: 80px;
  width: auto;

  transition: 0.3s;
}
.swiper-slide img:hover {
  filter: grayscale(0%);
  transform: scale(1.05);
}
</style>

<div class="brand-carousel">
    
     <h1 style="text-align:center">Brands We Handle</h1>
     <br>
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

      </section>

      <!-- FAQ -->
     
    </div>

    <!-- Floating CTA (customize to WhatsApp when number is available) -->
    <div class="floating-cta">
      <a href="contact.php" aria-label="Chat with us">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12c0 1.733.44 3.363 1.214 4.79L2 22l5.31-1.2C8.7 21.56 10.306 22 12 22Z" stroke="white" stroke-opacity=".85" stroke-width="1.5"/><path d="M8.5 10.5c0 3.038 2.462 5.5 5.5 5.5" stroke="white" stroke-width="1.5" stroke-linecap="round"/></svg>
        <span>Chat with us</span>
      </a>
    </div>

    <script>
      // Fireworks canvas (subtle)
      (function(){
        const c = document.getElementById('fw'); if(!c) return; const ctx=c.getContext('2d');
        function resize(){ c.width=innerWidth; c.height=document.querySelector('.hero').offsetHeight; } resize(); addEventListener('resize', resize);
        const sparks=[]; const bursts=[];
        function launch(){
          const x = 60 + Math.random()*(c.width-120); const y = c.height - 10; const color = Math.random()*360;
          bursts.push({x,y,age:0,life:1000+Math.random()*600,h:color});
        }
        function step(ts){
          ctx.clearRect(0,0,c.width,c.height);
          if (bursts.length < 6 && Math.random() < 0.05) launch();
          for(let i=bursts.length-1;i>=0;i--){ const b=bursts[i]; b.age+=16; if(b.age>200){
            // explode into sparks once
            for(let k=0;k<60;k++){ const a=Math.random()*Math.PI*2; const v=0.4+Math.random()*1.2; sparks.push({x:b.x,y:b.y-80*Math.random(),vx:Math.cos(a)*v,vy:Math.sin(a)*v-0.3,age:0,life:800+Math.random()*600,h:b.h}); }
            bursts.splice(i,1);
          } else {
            ctx.fillStyle=`hsla(${b.h},100%,70%,.8)`; ctx.beginPath(); ctx.arc(b.x, b.y - b.age*0.2, 2, 0, Math.PI*2); ctx.fill();
          }}
          for(let i=sparks.length-1;i>=0;i--){ const p=sparks[i]; p.age+=16; if(p.age>p.life){ sparks.splice(i,1); continue; }
            p.x+=p.vx*8; p.y+=p.vy*8; p.vy+=0.005; const a=1-(p.age/p.life); ctx.fillStyle=`hsla(${p.h},100%,70%,${a})`;
            ctx.fillRect(p.x,p.y,1.2,1.2);
          }
          requestAnimationFrame(step);
        } requestAnimationFrame(step);
      })();
    </script>
<?php include __DIR__ . '/inc/footer.php'; ?>
