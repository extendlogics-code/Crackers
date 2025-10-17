<?php
$title = 'Contact — PKS Crackers';
$extraHead = '<style>
  /* Pure white background for Contact page */
  :root { --bg:#ffffff; --text:#111827; --muted:#4b5563; }
  body{ background: var(--bg) !important; color: var(--text); }
  header.site::before{ background: linear-gradient(to bottom, rgba(255,255,255,.96), rgba(255,255,255,.9), transparent) !important; border-bottom:1px solid rgba(0,0,0,.06) }
  .wrap{max-width:900px;margin:20px auto;padding:0 16px}
  input,textarea{width:100%;padding:10px;border-radius:8px;border:1px solid rgba(0,0,0,.12);background:#ffffff;color:#111}
  .row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  @media (max-width:700px){ .row{grid-template-columns:1fr} }
  .btn{appearance:none;border:none;border-radius:10px;padding:10px 12px;background:linear-gradient(135deg,#e11d48,#f97316);color:#fff;font-weight:800;cursor:pointer;box-shadow:0 6px 16px rgba(175,0,45,.25)}
</style>';
require_once __DIR__ . '/lib/routes.php';
$routeExt = route_extension();
include __DIR__ . '/inc/header.php';
?>

<style>
   /* Full hero */
  .hero{position:relative;height:64vh;min-height:440px;display:grid;place-items:center;overflow:hidden}
  .hero .bg{position:absolute;inset:-10%;background:radial-gradient(800px 300px at 20% 20%, rgba(225,29,72,.35), transparent), radial-gradient(800px 300px at 80% 80%, rgba(249,115,22,.35), transparent)}
  /* Fill the hero area with the banner image */
  .hero .stars{position:absolute;inset:0;background:url('images/bg/contactbanner.jpg') center/cover no-repeat}
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
  <img class="hero-img" src="images/bg/contactbanner.jpg" alt="Hero Image">
  <canvas id="fw"></canvas>
</section>

<section>
    
    <div class="wrap"> <h1>Contact PKS Crackers</h1> <p>Have questions about products, orders, or delivery? Send us a message.</p>
  <form id="contactForm" method="post">
  <div class="row">
    <label>Name
      <input name="name" required>
    </label>
    <label>Email
      <input type="email" name="email" required>
    </label>
  </div>
  <label>Phone
    <input name="phone">
  </label>
  <label>Message
    <textarea name="message" rows="5" required></textarea>
  </label>
  <button class="btn" type="submit">Send</button>
</form>

<div id="successMsg" style="color:green; margin-top:10px; display:none;"></div>
</div>
</section>

<script>
document.getElementById('contactForm').addEventListener('submit', function(e){
    e.preventDefault();

    const form = e.target;
    const data = new FormData(form);

    fetch('/send_mail' + <?= json_encode($routeExt) ?>, {
        method: 'POST',
        body: data
    })
    .then(res => res.json())
    .then(response => {
        const msg = document.getElementById('successMsg');
        msg.innerText = response.message;
        msg.style.display = 'block';
        form.reset();
        setTimeout(() => { msg.style.display = 'none'; }, 5000);
    })
    .catch(err => {
        alert('Something went wrong!');
        console.error(err);
    });
});
</script>


<?php include __DIR__ . '/inc/footer.php'; ?>
