<?php
// Receives POST order_json from shop.php, renders summary and collects billing details
$payload = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $payload = json_decode($_POST['order_json'] ?? '{}', true) ?: [];
}
$items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
// Keep only lines with qty > 0 for display/order, renumber from 1
$items = array_values(array_filter($items, static function ($it) {
  return (int)($it['qty'] ?? 0) > 0;
}));
$subtotal = 0.0;
foreach ($items as $it) {
  $subtotal += (float)($it['unit_price'] ?? 0) * (int)($it['qty'] ?? 0);
}
if (isset($payload['subtotal']) && (float)$payload['subtotal'] > 0 && abs((float)$payload['subtotal'] - $subtotal) < 0.01) {
  $subtotal = (float)$payload['subtotal'];
}
$hasItems = count($items) > 0;
?>
<?php
$title = 'PKS Crackers — Checkout';
require_once __DIR__ . '/lib/routes.php';
$routeExt = route_extension();
$routeBase = route_base();
$extraHead = <<<HEAD
<style>
  :root { --bg:#f3f4f6; --text:#111827; --muted:#4b5563; --danger:#b91c1c; --ok:#166534; --brand:#e11d48; --brand2:#f97316; }
  body{ background: var(--bg) !important; color: var(--text); }
  header.site::before{ background: linear-gradient(to bottom, rgba(255,255,255,.98), rgba(255,255,255,.92), transparent) !important; border-bottom:1px solid rgba(0,0,0,.06) }

  .wrap{max-width:1100px;margin:14px auto;padding:0 16px}
  .back-link{display:inline-flex;align-items:center;gap:6px;color:#b45309;font-weight:700;text-decoration:none;padding:8px 16px}
  .back-link:hover{color:#92400e;text-decoration:underline}
  .grid{display:grid;grid-template-columns:1.2fr .8fr;gap:16px}
  @media (max-width:900px){ .grid{grid-template-columns:1fr} }
  .card{background:#ffffff;border:1px solid rgba(0,0,0,.1);border-radius:14px;padding:16px 16px 18px;box-shadow:0 4px 14px rgba(15,23,42,.04)}
  .card h3{margin:0 0 4px;font-size:1.15rem}
  .card .sub{color:var(--muted);margin-bottom:12px;font-size:13px}
  table{width:100%;border-collapse:collapse}
  th,td{padding:10px 8px;border-bottom:1px solid rgba(0,0,0,.08);vertical-align:middle}
  th{text-align:left;font-size:13px;color:#374151}
  th.col-no, td.col-no{width:48px;text-align:center;font-weight:700;color:#374151}
  .empty-cart{padding:18px 10px;text-align:center;color:var(--muted);border:1px dashed #d1d5db;border-radius:10px;margin:8px 0 12px;background:#fafafa}
  label{display:block;margin-bottom:12px;font-weight:600;font-size:13px;color:#374151}
  label .req{color:var(--brand);margin-left:2px}
  label .field-no{display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:22px;margin-right:6px;border-radius:999px;background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff;font-size:11px;font-weight:800}
  input,textarea,select{width:100%;padding:10px 12px;border-radius:8px;border:1px solid #d1d5db;background:#fff;color:#111;margin-top:6px;font:inherit}
  input:focus,textarea:focus,select:focus{outline:2px solid rgba(249,115,22,.35);border-color:#f97316}
  .row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  @media (max-width:700px){ .row{grid-template-columns:1fr} }
  .ok{color:var(--ok);background:#e2f7e1;padding:10px;border-radius:8px;margin-bottom:10px}
  .err{color:var(--danger);background:#fde2e2;padding:10px;border-radius:8px;margin-bottom:10px}
  .err-msg{display:block;margin-top:6px;color:var(--danger);font-size:12px;font-weight:500;min-height:0}
  .invalid{border-color: var(--danger) !important; outline:none}
  .qtybox{display:inline-flex;align-items:center;gap:8px}
  .qtyinput{width:72px;text-align:center}
  .right{ text-align:right; white-space:nowrap }
  .totals{display:flex;justify-content:flex-end;gap:24px;margin-top:12px;padding-top:10px;border-top:1px solid #e5e7eb}
  .totals small{display:block;color:var(--muted);font-size:12px}
  .totals strong, .totals div:not(small){font-weight:800;font-size:1.05rem}
  .actions-inline{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:4px}
  .min-note{margin-right:auto;font-weight:700;font-size:13px;padding:8px 10px;border-radius:8px;background:#fff7ed;color:#c2410c;border:1px solid #fed7aa}
  .min-note.ok-min{background:#ecfdf5;color:#047857;border-color:#a7f3d0}
  .btn{appearance:none;border:0;border-radius:999px;padding:10px 16px;background:#111827;color:#fff;font-weight:700;cursor:pointer}
  .btn[disabled]{opacity:.55;cursor:not-allowed}
  .btn.ghost{background:transparent;border:1px solid rgba(0,0,0,.2);color:#111827}

  .modal{position:fixed;inset:0;background:rgba(0,0,0,.7);display:none;align-items:center;justify-content:center;z-index:2000;padding:16px}
  .modal.open{display:flex}
  .modal-box{background:#fff;padding:20px;border-radius:16px;max-width:560px;width:100%;text-align:center;position:relative;box-shadow:0 10px 30px rgba(0,0,0,.25)}
  .modal-close{position:absolute;top:10px;right:10px;border:0;background:#eee;border-radius:50%;cursor:pointer;font-size:20px;width:36px;height:36px;line-height:36px}
  .qr-big{width:360px;height:360px;max-width:80vw;max-height:60vh;object-fit:contain;border-radius:12px;border:1px solid #e5e7eb;background:#fff}
  .modal-actions{display:flex;gap:10px;flex-wrap:wrap;justify-content:center;margin-top:12px}

  @media (max-width:640px){
    .qr-big{max-width:90vw;max-height:60vh}
    .qtyinput{width:64px;min-height:44px;font-size:16px}
    input,textarea,select{font-size:16px;min-height:44px} /* avoid iOS input zoom */
    .btn{min-height:44px;padding:12px 16px}
    th:nth-child(4), td:nth-child(4){ white-space:nowrap }
    .card{padding:14px 12px 16px}
    .totals{flex-wrap:wrap;gap:12px;justify-content:space-between}
  }
  @media (max-width:900px) and (min-width:641px){
    .qtyinput{min-height:44px;font-size:16px}
  }
  body{overflow-x:clip}
</style>
HEAD;
include __DIR__ . '/inc/header.php';
?>
    <a class="back-link" href="<?= $routeBase ?>/shop<?= htmlspecialchars($routeExt) ?>">← Back to Shop</a>

    <div class="wrap">
      <div class="grid">
        <div class="card">
          <h3>Order Summary</h3>
          <div class="sub">Review items and totals. Serial numbers start from 1.</div>
          <?php if (!$hasItems): ?>
            <div class="empty-cart" id="emptyCart">
              Your cart is empty. Add products from the shop (minimum order ₹2000), then return here.
            </div>
          <?php endif; ?>
          <table <?= $hasItems ? '' : 'style="display:none"' ?> id="summaryTable">
            <thead>
              <tr>
                <th class="col-no">No.</th>
                <th>Product</th>
                <th style="width:120px">Qty</th>
                <th class="right" style="width:110px">Price</th>
                <th class="right" style="width:110px">Total</th>
              </tr>
            </thead>
            <tbody id="lines">
              <?php $sno = 1; foreach ($items as $i => $it):
                $p = (float)($it['unit_price'] ?? 0);
                $q = (int)($it['qty'] ?? 0);
              ?>
                <tr data-idx="<?= (int)$i ?>">
                  <td class="col-no sno"><?= $sno++ ?></td>
                  <td><?= htmlspecialchars($it['name'] ?? $it['id'] ?? 'Item') ?></td>
                  <td>
                    <div class="qtybox">
                      <input type="number" min="0" step="1" class="qtyinput" value="<?= max(0, $q) ?>" aria-label="Quantity">
                    </div>
                  </td>
                  <td class="right">₹<?= number_format($p, 2) ?></td>
                  <td class="right line-total">₹<?= number_format($p * $q, 2) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <div style="margin-top:10px">
            <button type="button" id="continueShopping" class="btn ghost">Continue Shopping</button>
          </div>

          <div class="totals">
            <div><small>Subtotal</small><div id="subtotal">₹<?= number_format($subtotal, 2) ?></div></div>
            <div><small>Grand Total</small><div id="grand">₹<?= number_format($subtotal, 2) ?></div></div>
          </div>
        </div>

        <div class="card">
          <h3>Billing Details</h3>
          <div class="sub">Fields numbered from 1. All marked fields are required.</div>
          <div id="out"></div>
          <form id="orderForm" novalidate>
            <label><span class="field-no">1</span>Name<span class="req">*</span>
              <input name="name" autocomplete="name" maxlength="120" required>
              <small class="err-msg" id="err_name"></small>
            </label>
            <div class="row">
              <label><span class="field-no">2</span>Email<span class="req">*</span>
                <input name="email" type="email" inputmode="email" autocomplete="email" maxlength="190" required>
                <small class="err-msg" id="err_email"></small>
              </label>
              <label><span class="field-no">3</span>Phone<span class="req">*</span>
                <input name="phone" type="tel" inputmode="numeric" maxlength="10" pattern="[6-9][0-9]{9}" autocomplete="tel" placeholder="10-digit mobile" required>
                <small class="err-msg" id="err_phone"></small>
              </label>
            </div>
            <label><span class="field-no">4</span>Address line 1<span class="req">*</span>
              <input name="address_line1" autocomplete="address-line1" maxlength="255" required>
              <small class="err-msg" id="err_addr1"></small>
            </label>
            <label><span class="field-no">5</span>Address line 2
              <input name="address_line2" autocomplete="address-line2" maxlength="255">
              <small class="err-msg" id="err_addr2"></small>
            </label>
            <?php $tnCities = require __DIR__ . '/data/tn_cities.php'; ?>
            <div class="row">
              <label><span class="field-no">6</span>City<span class="req">*</span>
                <select name="city" required>
                  <option value="" disabled selected>Select City</option>
                  <?php foreach ($tnCities as $c): ?>
                    <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                  <?php endforeach; ?>
                </select>
                <small class="err-msg" id="err_city"></small>
              </label>
              <label><span class="field-no">7</span>State<span class="req">*</span>
                <select name="state" required>
                  <option value="Tamil Nadu" selected>Tamil Nadu</option>
                  <option value="Andhra Pradesh">Andhra Pradesh</option>
                  <option value="Karnataka">Karnataka</option>
                  <option value="Kerala">Kerala</option>
                  <option value="Puducherry">Puducherry</option>
                </select>
                <small class="err-msg" id="err_state"></small>
              </label>
            </div>
            <label><span class="field-no">8</span>Pincode<span class="req">*</span>
              <input name="pincode" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" title="Enter a valid 6-digit pincode" autocomplete="postal-code" required>
              <small class="err-msg" id="err_pincode"></small>
            </label>
            <label><span class="field-no">9</span>Notes
              <textarea name="notes" rows="3" maxlength="500" placeholder="Optional delivery notes"></textarea>
              <small class="err-msg" id="err_notes"></small>
            </label>
            <div class="actions-inline">
              <div id="min_note_checkout" class="min-note">Minimum order: ₹2000</div>
              <button type="button" class="btn ghost" id="preview" <?= $hasItems ? '' : 'disabled' ?>>Preview Invoice</button>
              <button type="button" class="btn" id="placeOrder" <?= $hasItems ? '' : 'disabled' ?>>Place Order</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- QR MODAL (Popup) -->
    <div id="qrModal" class="modal" aria-hidden="true" role="dialog" aria-label="Scan and Pay">
      <div class="modal-box">
        <button class="modal-close" id="closeQr" aria-label="Close">&times;</button>
        <h3 style="margin:0 0 8px">Scan &amp; Pay</h3>
        <p style="margin:0 0 10px;color:#444">UPI ID: <strong>pskcrackers@axl</strong></p>
        <img src="images/payments/upi_qr.png"
             alt="Scan this QR to pay"
             class="qr-big"
             onerror="this.src='images/payments/qr_placeholder.png'">
        <div style="margin-top:12px;text-align:left">
          <label>Transaction / Payment ID<span class="req">*</span>
            <input id="txnIdInput" name="txn_id" placeholder="e.g., UPI/REF/1234567890" maxlength="64" required>
            <small class="err-msg" id="err_txn"></small>
          </label>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn" id="confirmPayment">Confirm Payment</button>
          <button type="button" class="btn ghost" id="cancelQr">Cancel</button>
        </div>
      </div>
    </div>
    <!-- /QR MODAL -->

    <script>
      const fmt = n => '₹' + (Number(n||0)).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
      let items = <?= json_encode(array_values($items), JSON_UNESCAPED_UNICODE) ?>.map(it => ({
        ...it,
        unit_price: Number(it.unit_price),
        qty: Number(it.qty)
      }));
      const subEl = document.getElementById('subtotal');
      const grandEl = document.getElementById('grand');
      const MIN_TOTAL = 2000;
      const previewBtn = document.getElementById('preview');
      const placeBtn = document.getElementById('placeOrder');
      const summaryTable = document.getElementById('summaryTable');
      const emptyCart = document.getElementById('emptyCart');

      function activeItems(){ return items.filter(it => Number(it.qty||0) > 0); }
      function computeSubtotal(){ return activeItems().reduce((s, it) => s + (Number(it.unit_price) * Number(it.qty||0)), 0); }

      function renumberRows(){
        let n = 1;
        document.querySelectorAll('#lines tr[data-idx]').forEach(tr => {
          const idx = Number(tr.dataset.idx);
          const it = items[idx];
          const sno = tr.querySelector('.sno');
          if (!it || Number(it.qty||0) <= 0) {
            tr.style.display = 'none';
            return;
          }
          tr.style.display = '';
          if (sno) sno.textContent = String(n++);
        });
      }

      function refreshLines(){
        document.querySelectorAll('#lines tr[data-idx]').forEach(tr => {
          const idx = Number(tr.dataset.idx);
          const it = items[idx]; if(!it) return;
          const lt = tr.querySelector('.line-total');
          const qi = tr.querySelector('.qtyinput');
          if (lt) lt.textContent = fmt(Number(it.unit_price) * Number(it.qty||0));
          if (qi) qi.value = String(it.qty||0);
        });
        renumberRows();
        const sub = computeSubtotal();
        const active = activeItems();
        subEl.textContent = fmt(sub);
        grandEl.textContent = fmt(sub);
        const note = document.getElementById('min_note_checkout');
        const okMin = sub >= MIN_TOTAL;
        note.classList.toggle('ok-min', okMin);
        note.style.color = '';
        note.textContent = okMin
          ? 'Minimum order met (₹2000)'
          : 'Minimum order: ₹2000' + (sub > 0 ? ' — short by ' + fmt(MIN_TOTAL - sub) : '');

        const has = active.length > 0;
        if (summaryTable) summaryTable.style.display = has ? '' : 'none';
        if (emptyCart) emptyCart.style.display = has ? 'none' : '';
        previewBtn.disabled = !has;
        placeBtn.disabled = !has;
      }

      function clamp(n, lo, hi){ return Math.max(lo, Math.min(hi, n)); }

      document.getElementById('lines')?.addEventListener('input', (e)=>{
        const q = e.target.closest('.qtyinput'); if(!q) return;
        const tr = e.target.closest('tr[data-idx]'); if(!tr) return;
        const idx = Number(tr.dataset.idx); const it = items[idx]; if(!it) return;
        let v = (q.value || '').replace(/\D+/g,'');
        if (v === '') v = '0';
        it.qty = clamp(Number(v), 0, 9999);
        q.value = String(it.qty);
        refreshLines();
      });

      refreshLines();

      document.getElementById('continueShopping').addEventListener('click', ()=>{
        if (window.history.length > 1) history.back();
        else location.href = <?= json_encode($routeBase . '/shop' . $routeExt) ?>;
      });

      const f = document.getElementById('orderForm');
      const err = (id) => document.getElementById(id);
      const setErr = (input, errEl, msg) => {
        if (!input || !errEl) return;
        if (msg){ input.classList.add('invalid'); errEl.textContent = msg; }
        else { input.classList.remove('invalid'); errEl.textContent=''; }
      };
      const isEmail = (s) => /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(String(s||'').trim());
      const isDigits = (s, len) => new RegExp('^\\d{' + len + '}$').test(String(s||'').trim());
      const isIndianMobile = (s) => /^[6-9]\d{9}$/.test(String(s||'').trim());

      function validateField(name){
        const el = name === 'txn_id' ? document.getElementById('txnIdInput') : f.elements[name];
        if(!el) return true;
        const v = (el.value || '').trim();
        switch(name){
          case 'name': {
            const ok = v.length >= 2 && /[A-Za-z\u0B80-\u0BFF]/.test(v);
            setErr(el, err('err_name'), !v ? 'Name is required.' : (ok ? '' : 'Enter a valid name (min 2 characters).'));
            return ok;
          }
          case 'email':
            setErr(el, err('err_email'), !v ? 'Email is required.' : (isEmail(v)?'':'Enter a valid email address.'));
            return !!v && isEmail(v);
          case 'phone':
            el.value = v.replace(/\D+/g,'').slice(0,10);
            setErr(el, err('err_phone'), isIndianMobile(el.value) ? '' : 'Enter a valid 10-digit mobile starting with 6–9.');
            return isIndianMobile(el.value);
          case 'address_line1': {
            const ok = v.length >= 5;
            setErr(el, err('err_addr1'), !v ? 'Address line 1 is required.' : (ok ? '' : 'Address is too short (min 5 characters).'));
            return ok;
          }
          case 'city': setErr(el, err('err_city'), v ? '' : 'Please select a city.'); return !!v;
          case 'state': setErr(el, err('err_state'), v ? '' : 'Please select a state.'); return !!v;
          case 'pincode':
            el.value = v.replace(/\D+/g,'').slice(0,6);
            setErr(el, err('err_pincode'), isDigits(el.value, 6) ? '' : 'Pincode must be exactly 6 digits.');
            return isDigits(el.value, 6);
          case 'txn_id':
            setErr(el, err('err_txn'), v.length >= 6 ? '' : 'Enter a valid Transaction ID (min 6 characters).');
            return v.length >= 6;
          default: return true;
        }
      }

      ['name','email','phone','address_line1','city','state','pincode'].forEach(n=>{
        const el = f.elements[n]; if(!el) return;
        el.addEventListener('input', ()=> validateField(n));
        el.addEventListener('blur',  ()=> validateField(n));
        el.addEventListener('change', ()=> validateField(n));
      });
      document.getElementById('txnIdInput')?.addEventListener('input', ()=> validateField('txn_id'));
      document.getElementById('txnIdInput')?.addEventListener('blur', ()=> validateField('txn_id'));

      function validateForm(){
        let ok = true;
        let firstBad = null;
        ['name','email','phone','address_line1','city','state','pincode'].forEach(n=>{
          const good = validateField(n);
          if (!good && !firstBad) firstBad = (n === 'txn_id' ? document.getElementById('txnIdInput') : f.elements[n]);
          ok = good && ok;
        });
        if (!ok && firstBad) {
          firstBad.focus({ preventScroll: false });
          firstBad.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        return ok;
      }

      function enforceDigits(el, maxlen){
        if (!el) return;
        el.addEventListener('keydown', (e)=>{
          const allowed = ['Backspace','Delete','ArrowLeft','ArrowRight','Tab','Home','End'];
          if (allowed.includes(e.key) || e.ctrlKey || e.metaKey) return;
          if (!/^\d$/.test(e.key)) e.preventDefault();
        });
        el.addEventListener('paste', (e)=>{
          e.preventDefault();
          const text = (e.clipboardData || window.clipboardData).getData('text') || '';
          const digits = text.replace(/\D+/g,'').slice(0, maxlen);
          const start = el.selectionStart ?? el.value.length;
          const end = el.selectionEnd ?? el.value.length;
          el.value = (el.value.slice(0, start) + digits + el.value.slice(end)).replace(/\D+/g,'').slice(0, maxlen);
          el.dispatchEvent(new Event('input', { bubbles: true }));
        });
        el.addEventListener('input', ()=>{ el.value = (el.value||'').replace(/\D+/g,'').slice(0,maxlen); });
      }
      enforceDigits(f.phone, 10);
      enforceDigits(f.pincode, 6);

      function buildPayload(extra={}){
        const ordered = activeItems();
        const sub = ordered.reduce((s, it) => s + Number(it.unit_price) * Number(it.qty||0), 0);
        return {
          sno_start: 1,
          customer: {
            name: f.name.value.trim(),
            email: f.email.value.trim(),
            phone: f.phone.value.trim(),
            address_line1: f.address_line1.value.trim(),
            address_line2: f.address_line2.value.trim(),
            city: f.city.value,
            state: f.state.value,
            pincode: f.pincode.value.trim()
          },
          items: ordered.map(it => ({
            sku: it.id,
            name: it.name || it.id,
            price: Number(it.unit_price),
            qty: Number(it.qty)
          })),
          subtotal: sub,
          discount: 0,
          total: Math.max(0, sub),
          notes: f.notes.value.trim(),
          ...extra
        };
      }

      function openPdf(payload){
        const form = document.createElement('form');
        form.method = 'POST'; form.action = <?= json_encode($routeBase . '/api/order_pdf' . $routeExt) ?>; form.target = '_blank';
        const input = document.createElement('input'); input.type = 'hidden'; input.name = 'order_json'; input.value = JSON.stringify(payload);
        form.appendChild(input); document.body.appendChild(form); form.submit(); form.remove();
      }
      function openPreview(payload){
        const form = document.createElement('form');
        form.method = 'POST'; form.action = <?= json_encode($routeBase . '/invoice_preview' . $routeExt) ?>; form.target = '_blank';
        const input = document.createElement('input'); input.type = 'hidden'; input.name = 'order_json'; input.value = JSON.stringify(payload);
        form.appendChild(input); document.body.appendChild(form); form.submit(); form.remove();
      }

      const qrModal = document.getElementById('qrModal');
      const closeQrBtn = document.getElementById('closeQr');
      const cancelQrBtn = document.getElementById('cancelQr');
      const confirmBtn = document.getElementById('confirmPayment');
      const txnInput = document.getElementById('txnIdInput');

      function openQr(){
        qrModal.classList.add('open');
        qrModal.setAttribute('aria-hidden','false');
        setTimeout(()=> txnInput?.focus(), 50);
      }
      function closeQr(){
        qrModal.classList.remove('open');
        qrModal.setAttribute('aria-hidden','true');
      }

      function guardOrderReady(){
        const netNow = computeSubtotal();
        if (!activeItems().length) {
          const out = document.getElementById('out');
          out.className = 'err';
          out.textContent = 'Your cart is empty. Add at least one item from the shop.';
          out.scrollIntoView({ behavior: 'smooth', block: 'center' });
          return false;
        }
        if (!validateForm()) return false;
        if (netNow < MIN_TOTAL) {
          document.getElementById('min_note_checkout').scrollIntoView({behavior:'smooth', block:'center'});
          const out = document.getElementById('out');
          out.className = 'err';
          out.textContent = 'Minimum order amount is ₹2000. Current total: ' + fmt(netNow) + '.';
          return false;
        }
        return true;
      }

      placeBtn.addEventListener('click', ()=>{
        if (!guardOrderReady()) return;
        openQr();
      });

      confirmBtn.addEventListener('click', async ()=>{
        if (!validateField('txn_id')) {
          txnInput.focus();
          return;
        }
        const txnId = txnInput.value.trim();

        const oldText = confirmBtn.textContent;
        confirmBtn.textContent = 'Placing…';
        confirmBtn.setAttribute('disabled','disabled');

        try {
          const payload = buildPayload({ transaction_id: txnId });
          if (!payload.items.length || payload.total <= 0) {
            const out = document.getElementById('out');
            out.className='err';
            out.textContent = 'Please add at least 1 item and ensure total > 0 before placing the order.';
            closeQr();
            return;
          }
          if (payload.total < MIN_TOTAL) {
            const out = document.getElementById('out');
            out.className='err';
            out.textContent = 'Minimum order amount is ₹2000.';
            closeQr();
            return;
          }

          const res = await fetch(<?= json_encode($routeBase . '/api/save_order' . $routeExt) ?>, {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify(payload)
          });
          const json = await res.json().catch(() => ({ ok:false, error:'Invalid server response' }));

          const out = document.getElementById('out');
          if(res.ok && json.ok){
            const oid = String(json.order_id ?? '');
            out.className='ok';
            out.textContent = '';
            out.appendChild(document.createTextNode('Thank you! Your order has been placed. We will confirm the order soon. Invoice: '));
            const strong = document.createElement('strong');
            strong.textContent = String(json.invoice_code || json.order_id || '');
            out.appendChild(strong);
            out.appendChild(document.createTextNode(' '));
            const dl = document.createElement('button');
            dl.className = 'btn ghost';
            dl.id = 'dlPdf';
            dl.type = 'button';
            dl.style.marginLeft = '8px';
            dl.textContent = 'Download Invoice (PDF)';
            out.appendChild(dl);

            const finalPayload = Object.assign({}, payload, {
              order_id: json.order_id,
              customer_id: json.customer_id,
              invoice_code: json.invoice_code,
              order_year: json.order_year,
              invoice_seq: json.invoice_seq,
              est_no: json.invoice_code,
              sno_start: 1
            });
            openPdf(finalPayload);
            dl.addEventListener('click', ()=> openPdf(finalPayload));

            try { f.reset(); txnInput.value=''; } catch (_) {}
            items = [];
            document.getElementById('lines').innerHTML = '';
            refreshLines();
            closeQr();
            out.scrollIntoView({behavior:'smooth', block:'center'});
          } else {
            out.className='err';
            out.textContent = 'Error: ' + (json.error||'Server') + (json.detail ? (' — ' + json.detail) : '');
            console.error(json);
          }
        } catch (e) {
          const out = document.getElementById('out');
          out.className='err';
          out.textContent = 'Network error while placing the order. Please try again.';
          console.error(e);
        } finally {
          confirmBtn.textContent = oldText;
          confirmBtn.removeAttribute('disabled');
        }
      });

      // Cancel only closes modal — do NOT wipe cart/form
      closeQrBtn.addEventListener('click', closeQr);
      cancelQrBtn.addEventListener('click', () => {
        try { txnInput.value = ''; err('err_txn').textContent = ''; txnInput.classList.remove('invalid'); } catch (_) {}
        closeQr();
      });
      qrModal.addEventListener('click', (e)=>{ if(e.target === qrModal) closeQr(); });
      window.addEventListener('keydown', (e)=>{ if (e.key === 'Escape' && qrModal.classList.contains('open')) closeQr(); });

      previewBtn.addEventListener('click', ()=>{
        if (!guardOrderReady()) return;
        const payload = buildPayload({
          vendor: { name: 'PSK Crackers', branch: 'Chennai', phone: '', email: '' }
        });
        openPreview(payload);
      });
    </script>
<?php include __DIR__ . '/inc/footer.php'; ?>
