<?php
// Receives POST order_json from shop.php, renders summary and collects customer details
$payload = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $payload = json_decode($_POST['order_json'] ?? '{}', true) ?: [];
}
$items = $payload['items'] ?? [];
$subtotal = (float)($payload['subtotal'] ?? 0);
?>
<?php
$title = 'PKS Crackers — Checkout';
require_once __DIR__ . '/lib/routes.php';
$routeExt = route_extension();
$extraHead = <<<HEAD
<style>
  :root { --bg:#f3f4f6; --text:#111827; --muted:#4b5563; --danger:#991b1b; --ok:#166534; }
  body{ background: var(--bg) !important; color: var(--text); }
  header.site::before{ background: linear-gradient(to bottom, rgba(255,255,255,.98), rgba(255,255,255,.92), transparent) !important; border-bottom:1px solid rgba(0,0,0,.06) }

  .wrap{max-width:1100px;margin:14px auto;padding:0 16px}
  .grid{display:grid;grid-template-columns:1.2fr .8fr;gap:16px}
  @media (max-width:900px){ .grid{grid-template-columns:1fr} }
  .card{background:#ffffff;border:1px solid rgba(0,0,0,.1);border-radius:14px;padding:14px}
  table{width:100%;border-collapse:collapse}
  th,td{padding:10px;border-bottom:1px solid rgba(0,0,0,.08);vertical-align:middle}
  th{text-align:left}
  input,textarea,select{width:100%;padding:10px;border-radius:8px;border:1px solid rgba(0,0,0,.12);background:#fff;color:#111}
  .row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  @media (max-width:700px){ .row{grid-template-columns:1fr} }
  .ok{color:var(--ok);background:#e2f7e1;padding:10px;border-radius:8px}
  .err{color:var(--danger);background:#fde2e2;padding:10px;border-radius:8px}
  .err-msg{display:block;margin-top:6px;color:var(--danger);font-size:12px}
  .invalid{border-color: var(--danger) !important; outline:none}
  /* qty controls */
  .qtybox{display:inline-flex;align-items:center;gap:8px}
  .qtybtn{appearance:none;border:1px solid rgba(0,0,0,.15);background:#fff;border-radius:8px;cursor:pointer;padding:4px 10px;font-weight:700}
  .qtyinput{width:72px;text-align:center}
  .right{ text-align:right; white-space:nowrap }
  .actions-inline{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
  .btn{appearance:none;border:0;border-radius:999px;padding:10px 14px;background:#111827;color:#fff;font-weight:700;cursor:pointer}
  .btn[disabled]{opacity:.7;cursor:not-allowed}
  .btn.ghost{background:transparent;border:1px solid rgba(0,0,0,.2);color:#111827}

  /* Modal (Scan & Pay) */
  .modal{position:fixed;inset:0;background:rgba(0,0,0,.7);display:none;align-items:center;justify-content:center;z-index:2000;padding:16px}
  .modal.open{display:flex}
  .modal-box{background:#fff;padding:20px;border-radius:16px;max-width:560px;width:100%;text-align:center;position:relative;box-shadow:0 10px 30px rgba(0,0,0,.25)}
  .modal-close{position:absolute;top:10px;right:10px;border:0;background:#eee;border-radius:50%;cursor:pointer;font-size:20px;width:36px;height:36px;line-height:36px}
  .qr-big{width:360px;height:360px;max-width:80vw;max-height:60vh;object-fit:contain;border-radius:12px;border:1px solid #e5e7eb;background:#fff}
  .modal-actions{display:flex;gap:10px;flex-wrap:wrap;justify-content:center;margin-top:12px}

  /* Mobile tweaks */
  @media (max-width:640px){
    .qr-big{max-width:90vw;max-height:60vh}
    .qtyinput{width:64px}
    th:nth-child(3), td:nth-child(3){ white-space:nowrap }
  }
</style>
HEAD;
include __DIR__ . '/inc/header.php';
?>
    <div style="padding:8px 16px"><a href="/shop<?= $routeExt ?>" style="color:#93c5fd;text-decoration:none">← Back to Shop</a></div>

    <div class="wrap">
      <div class="grid">
        <div class="card">
          <h3 style="margin:0 0 6px">Order Summary</h3>
          <div style="color:var(--muted);margin-bottom:8px">Review items and totals</div>
          <table>
            <thead>
              <tr>
                <th>Product</th>
                <th style="width:160px">Qty</th>
                <th class="right" style="width:120px">Price</th>
                <th class="right" style="width:120px">Total</th>
              </tr>
            </thead>
            <tbody id="lines">
              <?php $i=0; foreach ($items as $it): $p=(float)($it['unit_price']??0); $q=(int)($it['qty']??0); ?>
                <tr data-idx="<?= $i ?>">
                  <td><?= htmlspecialchars($it['name'] ?? $it['id'] ?? 'Item') ?></td>
                  <td>
                    <div class="qtybox">
                      <input type="number" min="0" step="1" class="qtyinput" value="<?= max(0,$q) ?>">
                    </div>
                  </td>
                  <td class="right">₹<?= number_format($p,2) ?></td>
                  <td class="right line-total">₹<?= number_format($p*$q,2) ?></td>
                </tr>
              <?php $i++; endforeach; ?>
            </tbody>
          </table>

          <div style="display:grid;grid-template-columns:1fr;gap:10px;margin-top:10px">
            <div>
              <button type="button" id="continueShopping" class="btn ghost">Continue Shopping</button>
            </div>
          </div>

          <div style="display:flex;justify-content:flex-end;gap:16px;margin-top:10px">
            <div><small style="color:var(--muted)">Subtotal</small><div id="subtotal">₹<?= number_format($subtotal,2) ?></div></div>
            <div><small style="color:var(--muted)">Grand Total</small><div id="grand">₹<?= number_format($subtotal,2) ?></div></div>
          </div>
        </div>

        <div class="card">
          <h3 style="margin:0 0 6px">Customer Details</h3>
          <div id="out"></div>
          <form id="orderForm" novalidate>
            <label>Name
              <input name="name" required>
              <small class="err-msg" id="err_name"></small>
            </label>
            <div class="row">
              <label>Email
                <input name="email" type="email" inputmode="email" required>
                <small class="err-msg" id="err_email"></small>
              </label>
              <label>Phone
                <input name="phone" type="tel" inputmode="numeric" maxlength="10" pattern="[0-9]{10}" required>
                <small class="err-msg" id="err_phone"></small>
              </label>
            </div>
            <label>Address line 1
              <input name="address_line1" required>
              <small class="err-msg" id="err_addr1"></small>
            </label>
            <label>Address line 2
              <input name="address_line2">
              <small class="err-msg" id="err_addr2"></small>
            </label>
            <?php $tnCities = require __DIR__ . '/data/tn_cities.php'; ?>
            <div class="row">
              <label>City
                <select name="city" required>
                  <option value="" disabled selected>Select City</option>
                  <?php foreach ($tnCities as $c): ?>
                    <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                  <?php endforeach; ?>
                </select>
                <small class="err-msg" id="err_city"></small>
              </label>
              <label>State
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
            <label>Pincode
              <input name="pincode" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" title="Enter a valid pincode" required>
              <small class="err-msg" id="err_pincode"></small>
            </label>
            <label>Notes
              <textarea name="notes" rows="3"></textarea>
              <small class="err-msg" id="err_notes"></small>
            </label>
            <div style="margin-top:10px" class="actions-inline">
              <div id="min_note_checkout" style="color:#fbbf24;margin-right:auto">Minimum order: ₹2000</div>
              <button type="button" class="btn ghost" id="preview">Preview Invoice</button>
              <button type="button" class="btn" id="placeOrder">Place Order</button>
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
          <label>Transaction / Payment ID
            <input id="txnIdInput" name="txn_id" placeholder="e.g., UPI/REF/1234567890" required>
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
      let items = <?= json_encode($items) ?>.map(it => ({ ...it, unit_price: Number(it.unit_price), qty: Number(it.qty) }));
      const subEl = document.getElementById('subtotal');
      const grandEl = document.getElementById('grand');
      const MIN_TOTAL = 2000;

      // Totals
      function computeSubtotal(){ return items.reduce((s, it) => s + (Number(it.unit_price) * Number(it.qty||0)), 0); }
      function refreshLines(){
        document.querySelectorAll('#lines tr[data-idx]').forEach(tr => {
          const idx = Number(tr.dataset.idx);
          const it = items[idx]; if(!it) return;
          tr.querySelector('.line-total').textContent = fmt(Number(it.unit_price) * Number(it.qty||0));
          tr.querySelector('.qtyinput').value = String(it.qty||0);
        });
        const sub = computeSubtotal();
        subEl.textContent = fmt(sub);
        const net = sub;
        grandEl.textContent = fmt(net);
        const note = document.getElementById('min_note_checkout');
        note.style.color = net < MIN_TOTAL ? '#ef4444' : '#10b981';
      }
      function clamp(n, lo, hi){ return Math.max(lo, Math.min(hi, n)); }

      // Qty controls
      document.getElementById('lines').addEventListener('click', (e)=>{
        const minus = e.target.closest('.qminus');
        const plus  = e.target.closest('.qplus');
        if (!minus && !plus) return;
        const tr = e.target.closest('tr[data-idx]'); if(!tr) return;
        const idx = Number(tr.dataset.idx); const it = items[idx]; if(!it) return;
        it.qty = clamp(Number(it.qty||0) + (plus?1:-1), 0, 9999);
        refreshLines();
      });
      document.getElementById('lines').addEventListener('input', (e)=>{
        const q = e.target.closest('.qtyinput'); if(!q) return;
        const tr = e.target.closest('tr[data-idx]'); if(!tr) return;
        const idx = Number(tr.dataset.idx); const it = items[idx]; if(!it) return;
        let v = (q.value || '').replace(/\D+/g,'');
        if (v === '') v = '0';
        it.qty = clamp(Number(v), 0, 9999);
        q.value = String(it.qty);
        refreshLines();
      });

      // Initial
      refreshLines();

      // Continue shopping (keeps quantities)
      document.getElementById('continueShopping').addEventListener('click', ()=>{ history.back(); });

      // ----- Validation -----
      const f = document.getElementById('orderForm');
      const err = (id) => document.getElementById(id);
      const setErr = (input, errEl, msg) => { if (msg){ input.classList.add('invalid'); errEl.textContent = msg; } else { input.classList.remove('invalid'); errEl.textContent=''; } };
      const isEmail = (s) => /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(String(s||'').trim());
      const isDigits = (s, len) => new RegExp('^\\d{' + len + '}$').test(String(s||'').trim());

      function validateField(name){
        const el = f.elements[name]; if(!el) return true;
        const v = (el.value || '').trim();
        switch(name){
          case 'name': setErr(el, err('err_name'), v ? '' : 'Name is required.'); return !!v;
          case 'email':
            setErr(el, err('err_email'), !v ? 'Email is required.' : (isEmail(v)?'':'Enter a valid email address.'));
            return !!v && isEmail(v);
          case 'phone':
            el.value = v.replace(/\D+/g,'').slice(0,10);
            setErr(el, err('err_phone'), isDigits(el.value, 10) ? '' : 'Phone must be exactly 10 digits.');
            return isDigits(el.value, 10);
          case 'address_line1': setErr(el, err('err_addr1'), v ? '' : 'Address line 1 is required.'); return !!v;
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
      ['name','email','phone','address_line1','city','state','pincode','txn_id'].forEach(n=>{
        const el = f.elements[n]; if(!el) return;
        el.addEventListener('input', ()=> validateField(n));
        el.addEventListener('blur',  ()=> validateField(n));
      });
      function validateForm(){
        let ok = true;
        ['name','email','phone','address_line1','city','state','pincode'].forEach(n=>{ ok = validateField(n) && ok; });
        return ok;
      }

      // Enforce digits while typing (phone/pincode)
      function enforceDigits(el, maxlen){
        if (!el) return;
        el.addEventListener('keydown', (e)=>{
          const allowed = ['Backspace','Delete','ArrowLeft','ArrowRight','Tab','Home','End'];
          if (allowed.includes(e.key) || e.ctrlKey || e.metaKey) return;
          if (!/^\d$/.test(e.key)) e.preventDefault();
        });
        el.addEventListener('input', ()=>{ el.value = (el.value||'').replace(/\D+/g,'').slice(0,maxlen); });
      }
      enforceDigits(f.phone, 10);
      enforceDigits(f.pincode, 6);

      // Build payload
      function buildPayload(extra={}){
        const sub = computeSubtotal();
        const total = Math.max(0, sub);
        return {
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
          items: items.map(it=>({ sku: it.id, name: it.name||it.id, price: Number(it.unit_price), qty: Number(it.qty) })),
          subtotal: sub,
          discount: 0,
          total,
          notes: f.notes.value,
          ...extra
        };
      }

      // Preview / PDF helpers
      function openPdf(payload){
        const form = document.createElement('form');
        form.method = 'POST'; form.action = '/api/order_pdf' + <?= json_encode($routeExt) ?>; form.target = '_blank';
        const input = document.createElement('input'); input.type = 'hidden'; input.name = 'order_json'; input.value = JSON.stringify(payload);
        form.appendChild(input); document.body.appendChild(form); form.submit(); form.remove();
      }
      function openPreview(payload){
        const form = document.createElement('form');
        form.method = 'POST'; form.action = '/invoice_preview' + <?= json_encode($routeExt) ?>; form.target = '_blank';
        const input = document.createElement('input'); input.type = 'hidden'; input.name = 'order_json'; input.value = JSON.stringify(payload);
        form.appendChild(input); document.body.appendChild(form); form.submit(); form.remove();
      }

      // ----- QR MODAL flow -----
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

      // Place Order -> validate -> open modal
      document.getElementById('placeOrder').addEventListener('click', ()=>{
        const netNow = Math.max(0, computeSubtotal());
        if (!validateForm()) return;
        if (netNow < MIN_TOTAL) {
          document.getElementById('min_note_checkout').scrollIntoView({behavior:'smooth', block:'center'});
          return;
        }
        openQr();
      });

      // Confirm payment -> validate txn -> PLACE ORDER (DB save + AUTO open PDF)
      confirmBtn.addEventListener('click', async ()=>{
        // Use txnInput.value directly for transaction ID
        const txnId = txnInput.value.trim();
        // Validate transaction ID (min 6 chars)
        if (txnId.length < 6) {
          txnInput.focus();
          const out = document.getElementById('out');
          out.className = 'err';
          out.textContent = 'Please enter a valid Transaction ID (min 6 characters) to confirm payment.';
          // Log error to server
          fetch('/api/save_order' + <?= json_encode($routeExt) ?>, {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ error: 'Invalid transaction ID', txn_id: txnId, time: new Date().toISOString() })
          });
          return;
        }

        const oldText = confirmBtn.textContent;
        confirmBtn.textContent = 'Placing…';
        confirmBtn.setAttribute('disabled','disabled');

        try {
          const payload = buildPayload({ transaction_id: txnId });
          if (!payload.items || !payload.items.length || payload.total <= 0) {
            const out = document.getElementById('out');
            out.className='err';
            out.textContent = 'Please add at least 1 item and ensure total > 0 before placing the order.';
            // Log error to server
            fetch('/api/save_order' + <?= json_encode($routeExt) ?>, {
              method: 'POST',
              headers: {'Content-Type':'application/json'},
              body: JSON.stringify({ error: 'No items or total <= 0', payload, time: new Date().toISOString() })
            });
            return;
          }

          const res = await fetch('/api/save_order' + <?= json_encode($routeExt) ?>, {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify(payload)
          });
          if (!res.ok) {
            // Log error to server
            fetch('/api/save_order' + <?= json_encode($routeExt) ?>, {
              method: 'POST',
              headers: {'Content-Type':'application/json'},
              body: JSON.stringify({ error: 'Network response was not ok', payload, time: new Date().toISOString() })
            });
            throw new Error('Network response was not ok');
          }
          const json = await res.json();

          const out = document.getElementById('out');
          if(json.ok){
            out.className='ok';
            out.innerHTML = 'Thank you! Your order has been placed. We will confirm the order soon. Order ID: <strong>'+json.order_id+'</strong> ' +
              '<button class="btn ghost" id="dlPdf" type="button" style="margin-left:8px">Download Invoice (PDF)</button>';

            // Final payload including IDs from server
            const finalPayload = Object.assign({}, payload, { order_id: json.order_id, customer_id: json.customer_id });

            // AUTO open PDF now
            openPdf(finalPayload);

            // Also wire manual download button
            document.getElementById('dlPdf')?.addEventListener('click', ()=> openPdf(finalPayload));

            try { f.reset(); txnInput.value=''; } catch (_) {}
            closeQr();
            out.scrollIntoView({behavior:'smooth', block:'center'});
          } else {
            out.className='err';
            out.textContent = 'Error: ' + (json.error||'Server') + (json.detail ? (' — ' + json.detail) : '');
            // Log error to server
            fetch('/api/save_order' + <?= json_encode($routeExt) ?>, {
              method: 'POST',
              headers: {'Content-Type':'application/json'},
              body: JSON.stringify({ error: json.error||'Server', detail: json.detail||'', payload, time: new Date().toISOString() })
            });
            console.error(json);
          }
        } catch (e) {
          const out = document.getElementById('out');
          out.className='err';
          out.textContent = 'Network error while placing the order. Please try again.';
          // Log error to server
          fetch('/api/save_order' + <?= json_encode($routeExt) ?>, {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ error: 'Network error', detail: e.message, time: new Date().toISOString() })
          });
          console.error(e);
        } finally {
          confirmBtn.textContent = oldText;
          confirmBtn.removeAttribute('disabled');
        }
      });

      // Modal close actions
      closeQrBtn.addEventListener('click', closeQr);
      cancelQrBtn.addEventListener('click', () => {
        // Reset form fields
        try { f.reset(); txnInput.value = ''; } catch (_) {}
        // Clear all item quantities
        items.forEach(it => { it.qty = 0; });
        refreshLines();
        closeQr();
        // Optionally, scroll to top or show a message if needed
      });
      qrModal.addEventListener('click', (e)=>{ if(e.target === qrModal) closeQr(); });
      window.addEventListener('keydown', (e)=>{ if (e.key === 'Escape' && qrModal.classList.contains('open')) closeQr(); });

      // ---------- PREVIEW: preview only (NO DB SAVE) ----------
      const previewBtn = document.getElementById('preview');
      previewBtn.addEventListener('click', ()=>{
        if (!validateForm()) return;
        const netNow = Math.max(0, computeSubtotal());
        if (netNow < MIN_TOTAL) {
          document.getElementById('min_note_checkout').scrollIntoView({behavior:'smooth', block:'center'});
          return;
        }
        const payload = buildPayload({
          vendor: { name: 'PSK Crackers', branch: 'Chennai', phone: '', email: '' }
        });
        if (!payload.items || !payload.items.length || payload.total <= 0) {
          const out = document.getElementById('out');
          out.className='err';
          out.textContent = 'Please add at least 1 item and ensure total > 0 to preview.';
          return;
        }
        // Preview only, no DB write:
        openPreview(payload);
      });
      // ---------- /PREVIEW ----------

      // Block basic inspect shortcuts so casual users cannot open dev tools from the popup
      document.addEventListener('contextmenu', (e)=> e.preventDefault());
      document.addEventListener('keydown', (e)=>{
        const key = (e.key || '').toLowerCase();
        if (key === 'f12') {
          e.preventDefault();
          return;
        }
        const isCtrlShift = e.ctrlKey && e.shiftKey;
        if (isCtrlShift && ['i','j','c','k'].includes(key)) {
          e.preventDefault();
          return;
        }
        if (e.ctrlKey && ['u','s','p'].includes(key)) {
          e.preventDefault();
        }
      });
    </script>
<?php include __DIR__ . '/inc/footer.php'; ?>
