<?php
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/categories.php';

function load_products_json(): array {
  $path = __DIR__ . '/data/products.json';
  if (is_file($path)) {
    $data = json_decode((string)file_get_contents($path), true);
    if (is_array($data)) return $data;
  }
  return [];
}

function load_products_db(): array {
  try {
    $pdo = get_pdo();
    $st = $pdo->query("SELECT id, sku, name, category, unit, price, discount_pct, image_url FROM products ORDER BY name");
    $rows = $st->fetchAll();
    $out = [];
    foreach ($rows as $r) {
      $out[] = [
        'id' => (string)$r['sku'],
        'name' => (string)$r['name'],
        'category' => (string)$r['category'],
        'unit' => (string)($r['unit'] ?? ''),
        'price' => (float)$r['price'],
        'discount_pct' => (int)($r['discount_pct'] ?? 0),
        'image' => (string)($r['image_url'] ?? ''),
      ];
    }
    return $out;
  } catch (Throwable $__) {
    return [];
  }
}

$products = load_products_db();
$fromDb = !empty($products);
if (!$fromDb) { $products = load_products_json(); }

// Helper: find local images for a product under images/products/{id}/ or by slugified name
function product_images(string $id, ?string $name = null): array {
  $out = [];
  $candidates = [];
  $base = __DIR__ . '/images/products';
  $safeId = preg_replace('/[^A-Za-z0-9_-]/', '-', $id);
  $candidates[] = $base . '/' . $safeId;
  if ($name) {
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-'));
    if ($slug) $candidates[] = $base . '/' . $slug;
  }
  $seen = [];
  foreach ($candidates as $dir) {
    if (!is_dir($dir)) continue;
    foreach (scandir($dir) as $f) {
      if ($f === '.' || $f === '..') continue;
      $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
      if (!in_array($ext, ['jpg','jpeg','png','webp','gif'])) continue;
      $key = strtolower($f);
      if (isset($seen[$key])) continue;
      $seen[$key] = true;
      $rel = 'images/products/' . basename($dir) . '/' . $f;
      $out[] = $rel;
    }
  }
  return $out;
}

// For demo JSON, ensure we have 100 items. Skip when using DB.
if (!$fromDb && count($products) < 100) {
  $base = $products;
  if (empty($base)) {
    $base = [ [ 'id'=>'CRK-100', 'name'=>'Sample Sparkler', 'category'=>'Sparklers', 'price'=>99, 'discount_pct'=>10, 'image'=>'https://via.placeholder.com/400x300?text=PKS+Crackers' ] ];
  }
  $i = 0;
  while (count($products) < 100) {
    $src = $base[$i % count($base)];
    $n = count($products) + 1;
    $price = (float)($src['price'] ?? 100);
    $delta = (($n % 9) - 4) * 2; // -8..+10
    $newPrice = max(19, $price + $delta);
    $disc = isset($src['discount_pct']) ? (int)$src['discount_pct'] : (($n % 21));
    $name = ($src['name'] ?? 'Item') . ' #' . $n;
    $id = sprintf('%s-%03d', preg_replace('/[^A-Z0-9]/','', strtoupper(substr($src['category'] ?? 'CRK',0,3))), $n);
    $img = $src['image'] ?? 'https://via.placeholder.com/400x300?text=PKS+Crackers+' . $n;
    $products[] = [
      'id' => $id,
      'name' => $name,
      'category' => $src['category'] ?? 'Crackers',
      'price' => $newPrice,
      'discount_pct' => $disc,
      'image' => $img,
    ];
    $i++;
  }
}
?>
<?php
$title = 'PKS Crackers — Shop';
$extraHead = <<<HEAD
<style>
  /* Shop page as pure white background */
  :root { --bg:#ffffff; --text:#111827; --muted:#4b5563; }
  body{ background: var(--bg) !important; color: var(--text); }
  header.site::before{ background: linear-gradient(to bottom, rgba(255,255,255,.96), rgba(255,255,255,.9), transparent) !important; border-bottom:1px solid rgba(0,0,0,.06) }
  .wrap{max-width:1200px;margin:14px auto;padding:0 16px}
  .toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px}
  .toggle{display:flex;border:1px solid rgba(255,255,255,.18);border-radius:10px;overflow:hidden}
  .toggle button{background:transparent;border:0;color:var(--text);padding:8px 12px;cursor:pointer}
  .toggle button.active{background:rgba(255,255,255,.12)}
  .pretty-border{border:2px solid transparent; border-radius:10px; padding:8px 12px; background:#fff; color:#111;
    background-image: linear-gradient(#ffffff,#ffffff), linear-gradient(135deg,#e11d48,#ff7a18);
    background-origin: border-box; background-clip: padding-box, border-box;}
  .toolbar input[type="text"]{height:38px}
  /* Center align category select + options */
  #cat{ text-align:center; text-align-last:center; -moz-text-align-last:center; }
  #cat option{ text-align:center; }
  .count{border:2px solid transparent;border-radius:999px;padding:6px 10px;background:#fff;color:#111;font-weight:700;
    background-image: linear-gradient(#ffffff,#ffffff), linear-gradient(135deg,#e11d48,#ff7a18); background-origin:border-box; background-clip:padding-box,border-box}
  /* Mobile toolbar improvements */
  @media (max-width:640px){
    .toolbar{position:sticky; top:56px; background:var(--bg); z-index:6; padding:8px; border-bottom:1px solid rgba(0,0,0,.08); margin-bottom:8px}
    .toolbar input[type="text"], .toolbar select{width:100%}
    .toolbar label{width:100%}
    .toggle{width:100%; justify-content:space-between}
  }
  .hidden{display:none !important}
  .grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
  @media (max-width:1000px){ .grid{grid-template-columns:repeat(3,1fr)} }
  @media (max-width:750px){ .grid{grid-template-columns:repeat(2,1fr)} }
  @media (max-width:520px){ .grid{grid-template-columns:1fr} }
  .card{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:14px;overflow:hidden;display:flex;flex-direction:column}
  .card img{display:block;width:100%;aspect-ratio:4/3;object-fit:cover}
  .px{padding:12px;display:flex;flex-direction:column;gap:8px}
  .row{display:flex;align-items:center;justify-content:space-between;gap:8px}
  .badge{font-size:12px;padding:4px 8px;border-radius:999px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);color:var(--muted)}
  .qty{display:flex;gap:6px;align-items:center}
  .qty input{width:80px;padding:8px;border-radius:8px;border:1px solid rgba(255,255,255,.16);background:rgba(255,255,255,.06);color:var(--text)}
  .thumbs{display:flex;gap:6px;flex-wrap:wrap}
  .thumbs img{width:44px;height:44px;object-fit:cover;border-radius:6px;border:1px solid rgba(255,255,255,.18);opacity:.9}
  .thumbs img:hover{opacity:1}

  /* List view */
  .table-responsive{width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch; border:1px solid rgba(0,0,0,.08); border-radius:12px; background:rgba(0,0,0,.02)}
  table{width:100%; border-collapse:collapse; min-width:840px}
  th,td{padding:5px 4px; border-bottom:1px solid rgba(0,0,0,.06); text-align:left; vertical-align:middle}
  th{background:rgba(0,0,0,.04)}
  .cat-row td{background:#f1c40f; color:#111; font-weight:800; letter-spacing:.4px; text-transform:uppercase; text-align:center}
  .thumb{width:72px; height:72px; object-fit:cover; border-radius:6px; border:1px solid rgba(0,0,0,.08)}
  .qty-input{border:2px solid #ef4444}
  .qty-input.valid{border-color:#facc15 !important}
  td.price,td.total{text-align:right; white-space:nowrap}
  .sticky{position:sticky;bottom:0;left:0;right:0;z-index:10;background:rgba(255,255,255,.96);backdrop-filter:saturate(140%) blur(6px);border-top:1px solid rgba(0,0,0,.08)}
  .bar{max-width:1200px;margin:0 auto;padding:10px 16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}

  @media (max-width:900px){ .grid{grid-template-columns:repeat(2,1fr)} }

  /* Keep Unit & MRP visible on phones */
  @media (max-width:640px){
    .grid{grid-template-columns:1fr}
    .table-responsive table{min-width:600px}
    .thumb{width:56px;height:56px}
    .qty-input{width:88px}
    th.col-unit, th.col-mrp, td.col-unit, td.col-mrp{
      display:table-cell !important;
      white-space:nowrap;
      font-size:12px;
    }
  }
  @media (max-width:480px){
    .qty-input{width:72px}
    .table-responsive table{min-width:560px}
  }

  /* Gallery modal */
  .modal{position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:rgba(0,0,0,.7);z-index:1000}
  .modal.open{display:flex}
  .modal .box{background:#0b1020;border:1px solid rgba(255,255,255,.15);border-radius:12px;max-width:900px;width:92%;padding:10px}
  .modal .viewer{position:relative;aspect-ratio:4/3;background:#000;display:grid;place-items:center;border-radius:8px;overflow:hidden}
  .modal .viewer img{max-width:100%;max-height:100%}
  .modal .nav{position:absolute;top:50%;transform:translateY(-50%);width:100%;display:flex;justify-content:space-between}
  .modal .nav button{background:rgba(255,255,255,.2);border:0;color:#fff;font-weight:800;padding:8px 10px;border-radius:8px;cursor:pointer}
  .modal .actions{display:flex;justify-content:space-between;align-items:center;margin-top:8px}
  .modal .actions .thumbs{max-height:80px;overflow:auto}
</style>
HEAD;
include __DIR__ . '/inc/header.php';
?>
<style>
  /* Full hero */
  .hero{position:relative;height:64vh;min-height:440px;display:grid;place-items:center;overflow:hidden}
  .hero .bg{position:absolute;inset:-10%;background:radial-gradient(800px 300px at 20% 20%, rgba(225,29,72,.35), transparent), radial-gradient(800px 300px at 80% 80%, rgba(249,115,22,.35), transparent)}
  .hero .stars{position:absolute;inset:0;background:url('images/pslide.jpg') center/cover no-repeat}
  .hero .inner{position:relative;z-index:2;text-align:center;max-width:820px;padding:0 16px}
  .hero h1{margin:0 0 8px;font-size:44px;line-height:1.1}
  .hero p{margin:0 0 14px;color:var(--muted);font-size:18px}
  .cta-row{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
  .btn{appearance:none;border:0;border-radius:999px;padding:12px 16px;background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff;font-weight:800;cursor:pointer}
  .ghost{background:transparent;border:1px solid rgba(255,255,255,.2)}
  #fw{position:absolute;inset:0;z-index:1;pointer-events:none;mix-blend-mode:screen}
</style>

<section class="hero">
  <div class="bg"></div>
  <div class="stars"></div>
  <canvas id="fw"></canvas>
</section>

<div class="wrap">
  <div class="toolbar">
    <?php $totalProducts = count($products); ?>
    <input id="search" type="text" class="pretty-border" placeholder="Search products...">
    <?php $cats = load_categories(); ?>
    <label style="display:flex;align-items:center;gap:8px">Category
      <select id="cat" class="pretty-border">
        <option value="">All</option>
        <?php foreach ($cats as $c): ?>
          <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </div>

  <div class="table-responsive hidden" id="list" style="margin-top:12px">
    <table>
      <thead>
        <tr>
          <th style="width:90px">Image</th>
          <th>Product</th>
          <th class="col-unit" style="width:100px">Unit</th>
          <th class="col-mrp" style="width:120px">MRP</th>
          <th style="width:120px">Price</th>
          <th style="width:140px">Qty</th>
          <th style="width:140px">Total</th>
        </tr>
      </thead>
      <tbody>
      <?php 
        // Sort by category to insert category header rows
        usort($products, function($a,$b){ return strcasecmp($a['category'] ?? '', $b['category'] ?? ''); });
        $lastCat = null;
        foreach ($products as $p): 
          $price=(float)($p['price']??0); 
          $disc=(int)($p['discount_pct']??0); 
          $final=$price*(1-$disc/100); 
          $imgs = product_images($p['id'], $p['name']); 
          $cat = strtoupper(trim($p['category'] ?? ''));
          $unit = htmlspecialchars($p['unit'] ?? 'Box');
          if ($lastCat !== $cat) { $lastCat = $cat; ?>
            <!-- ✅ include data-category so JS can toggle headers -->
            <tr class="cat-row" data-category="<?= htmlspecialchars($lastCat) ?>"><td colspan="7"><?= htmlspecialchars($lastCat ?: 'OTHERS') ?></td></tr>
          <?php }
          // Build gallery list and pick primary image
          $gallery = [];
          if (!empty($p['image'])) { $gallery[] = (string)$p['image']; }
          foreach ($imgs as $im) { if (!in_array($im, $gallery, true)) $gallery[] = $im; }
          $primary = !empty($p['image'])
                      ? (string)$p['image']
                      : (!empty($imgs) ? $imgs[0] : 'https://via.placeholder.com/400x300?text=PKS+Crackers');
      ?>
        <tr 
          data-id="<?= htmlspecialchars($p['id']) ?>" 
          data-name="<?= htmlspecialchars($p['name']) ?>" 
          data-category="<?= htmlspecialchars($cat) ?>" 
          data-price="<?= number_format($final,2,'.','') ?>" 
          data-images='<?= htmlspecialchars(json_encode($gallery, JSON_UNESCAPED_SLASHES)) ?>'>
          <td>
            <?php if (!empty($primary)): ?>
              <img class="thumb open-gallery"
                   src="<?= htmlspecialchars($primary) ?>"
                   alt="<?= htmlspecialchars($p['name']) ?>"
                   loading="lazy">
            <?php else: ?>
              <div class="thumb" style="background:#eee"></div>
            <?php endif; ?>
          </td>
          <td><strong><?= htmlspecialchars($p['name']) ?></strong></td>
          <td class="col-unit"><?= $unit ?></td>
          <td class="col-mrp"><s>₹<?= number_format($price,2) ?></s></td>
          <td>₹<?= number_format($final,2) ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <input type="number" class="qty-input" min="0" step="1" inputmode="numeric" pattern="[0-9]*" value="0" style="width:100px;padding:8px;border-radius:8px;background:#fff;color:#111;border:2px solid #ef4444">
              <?php if (!empty($gallery)): ?><button type="button" class="btn ghost open-gallery" style="padding:6px 8px">View</button><?php endif; ?>
            </div>
          </td>
          <td class="line-total">₹0.00</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<form class="sticky" id="checkoutForm" method="post" action="checkout.php">
  <input type="hidden" name="order_json" id="order_json" value="{}">
  <div class="bar">
    <div><small style="color:var(--muted)">Items</small> <strong id="sum_items">0</strong></div>
    <div><small style="color:var(--muted)">Subtotal</small> <strong id="sum_sub">₹0.00</strong></div>
    <div><small style="color:var(--muted)">Grand Total</small> <strong id="sum_grand">₹0.00</strong></div>
    <div id="min_note" style="color:#fbbf24">Minimum order: ₹3000</div>
    <div style="margin-left:auto"></div>
    <button type="button" class="btn ghost" id="clear">Clear</button>
    <button type="submit" class="btn" id="checkoutBtn">Checkout</button>
  </div>
</form>

<script>
  const fmt = n => '₹' + (Number(n||0)).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  const TOTAL_PRODUCTS = <?= (int)$totalProducts ?>;
  const listEl = document.getElementById('list');
  const rows = Array.from(listEl.querySelectorAll('tbody tr[data-id]'));         // product rows
  const catHeaderRows = Array.from(listEl.querySelectorAll('tbody tr.cat-row')); // category headers
  const sumItems = document.getElementById('sum_items');
  const sumSub = document.getElementById('sum_sub');
  const sumGrand = document.getElementById('sum_grand');
  const orderJson = document.getElementById('order_json');
  const form = document.getElementById('checkoutForm');
  const catSel = document.getElementById('cat');
  const search = document.getElementById('search');

  // Modal gallery (Download button removed)
  const modal = document.createElement('div');
  modal.className = 'modal';
  modal.innerHTML = `
    <div class="box">
      <div class="viewer"><img id="gImg" alt=""></div>
      <div class="nav"><button id="gPrev">‹</button><button id="gNext">›</button></div>
      <div class="actions">
        <div class="thumbs" id="gThumbs"></div>
        <div style="display:flex;gap:8px">
          <button class="btn ghost" id="gClose" type="button">Close</button>
        </div>
      </div>
    </div>`;
  document.body.appendChild(modal);

  let gList = []; let gIdx = 0;
  function openGallery(list, idx=0){
    if (!list || !list.length) return;
    gList = list; gIdx = Math.max(0, Math.min(idx, list.length-1));
    const img = modal.querySelector('#gImg');
    const thumbs = modal.querySelector('#gThumbs'); thumbs.innerHTML='';
    list.forEach((src,i)=>{
      const t=document.createElement('img'); t.src=src; t.alt='thumb'; t.style.outline = i===gIdx ? '2px solid #fff' : 'none';
      t.addEventListener('click', ()=>{ openGallery(list, i); }); thumbs.appendChild(t);
    });
    img.src = list[gIdx];
    modal.classList.add('open');
  }
  function closeGallery(){ modal.classList.remove('open'); }
  modal.querySelector('#gPrev').addEventListener('click', ()=>{ if(!gList.length) return; gIdx = (gIdx-1+gList.length)%gList.length; openGallery(gList, gIdx); });
  modal.querySelector('#gNext').addEventListener('click', ()=>{ if(!gList.length) return; gIdx = (gIdx+1)%gList.length; openGallery(gList, gIdx); });
  modal.querySelector('#gClose').addEventListener('click', closeGallery);
  modal.addEventListener('click', (e)=>{ if(e.target===modal) closeGallery(); });

  const MIN_TOTAL = 3000;
  function recalc(){
    let items=0, subtotal=0; const lines=[];
    rows.forEach(r => {
      const qEl = r.querySelector('.qty-input');
      if (!qEl) return;
      const price = Number(r.dataset.price);
      const qty = Math.max(0, parseInt(qEl.value||'0',10));
      const total = price * qty;
      const tEl = r.querySelector('.line-total'); if (tEl) tEl.textContent = fmt(total);
      if(qty>0){ items += qty; subtotal += total; lines.push({ id:r.dataset.id, name:r.dataset.name, unit_price:price, qty }); }
    });
    sumItems.textContent = items; sumSub.textContent = fmt(subtotal); sumGrand.textContent = fmt(subtotal);
    orderJson.value = JSON.stringify({ items: lines, subtotal });

    const note = document.getElementById('min_note');
    const btn = document.getElementById('checkoutBtn');
    if (subtotal < MIN_TOTAL) {
      note.style.color = '#ef4444';
      btn.setAttribute('disabled','disabled');
      btn.classList.add('ghost');
    } else {
      note.style.color = '#10b981';
      btn.removeAttribute('disabled');
      btn.classList.remove('ghost');
    }
  }

  function sanitizeQty(el){
    if (!el) return 0;
    let v = (el.value || '').replace(/\D+/g,'');
    el.value = v;
    const n = Math.max(0, parseInt(v || '0', 10));
    if (n > 0) el.classList.add('valid'); else el.classList.remove('valid');
    return n;
  }
  listEl.addEventListener('input', e => {
    if(e.target.classList.contains('qty-input')){
      sanitizeQty(e.target);
      recalc();
    }
  });
  listEl.addEventListener('click', e => {
    const trigger = e.target.closest('.open-gallery');
    if (!trigger) return;
    const row = e.target.closest('tr');
    const list = JSON.parse(row.dataset.images || '[]');
    openGallery(list, 0);
  });
  document.getElementById('clear').addEventListener('click', () => { document.querySelectorAll('.qty-input').forEach(i=>i.value=0); recalc(); });
  form.addEventListener('submit', e => { if(orderJson.value==="{}") recalc(); });
  listEl.classList.remove('hidden');

  function updateCount(){
    let selected = 0;
    rows.forEach(r => {
      const qEl = r.querySelector('.qty-input');
      const n = Math.max(0, parseInt((qEl && qEl.value) ? qEl.value : '0', 10) || 0);
      selected += n;
    });
    const pc = document.getElementById('prodCount');
    if (pc) pc.textContent = 'Products: ' + selected + ' / ' + TOTAL_PRODUCTS;
  }

  // Filter products + show/hide category headers to match search/category
  function applyFilters(){
    const want = (catSel.value || '').toUpperCase();   // '' means All
    const q = (search.value || '').toLowerCase().trim();

    const visibleCats = new Set();

    // Filter product rows
    rows.forEach(r => {
      const have = (r.dataset.category||'').toUpperCase();
      const name = (r.dataset.name||'').toLowerCase();
      const okCat = (!want || have === want);
      const okSearch = (!q || name.includes(q));
      const show = okCat && okSearch;
      r.style.display = show ? '' : 'none';
      if (show) visibleCats.add(have);
    });

    // Toggle category header rows based on visibility of their products
    catHeaderRows.forEach(hr => {
      const cat = (hr.dataset.category || '').toUpperCase();
      hr.style.display = visibleCats.has(cat) ? '' : 'none';
    });

    // Persist filters
    localStorage.setItem('shop_cat', want);
    localStorage.setItem('shop_search', q);

    updateCount();
  }

  catSel.addEventListener('change', applyFilters);
  search.addEventListener('input', applyFilters);

  // Restore last filters
  const lastCat = localStorage.getItem('shop_cat') || '';
  const lastQ = localStorage.getItem('shop_search') || '';
  if (lastCat) { catSel.value = lastCat; }
  if (lastQ) { search.value = lastQ; }

  applyFilters(); // initial filter application
  // Initialize qty input states
  document.querySelectorAll('.qty-input').forEach(el => sanitizeQty(el));
  recalc();
</script>
<?php include __DIR__ . '/inc/footer.php'; ?>
