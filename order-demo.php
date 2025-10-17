<?php
// Simple demo form to test saving orders
require_once __DIR__ . '/lib/routes.php';
$routeExt = route_extension();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Order Demo</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; margin: 2rem; }
    form { max-width: 720px; }
    label { display:block; margin-top: .75rem; font-weight: 600; }
    input, textarea { width: 100%; padding: .5rem; }
    table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
    th, td { border: 1px solid #ddd; padding: .5rem; }
    .row { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
    .actions { margin-top: 1rem; }
    .ok { color: #155724; background: #d4edda; padding: .5rem; }
    .err { color: #721c24; background: #f8d7da; padding: .5rem; }
  </style>
  <script>
    async function submitOrder(e){
      e.preventDefault();
      const form = e.target;
      const items = [];
      const rows = document.querySelectorAll('#items tbody tr');
      rows.forEach(r => {
        const name = r.querySelector('[name="item_name[]"]').value.trim();
        const sku = r.querySelector('[name="item_sku[]"]').value.trim();
        const price = parseFloat(r.querySelector('[name="item_price[]"]').value || '0');
        const qty = parseInt(r.querySelector('[name="item_qty[]"]').value || '1', 10);
        if (name && qty > 0) items.push({ name, sku, price, qty });
      });
      const payload = {
        customer: {
          name: form.name.value,
          email: form.email.value,
          phone: form.phone.value,
          address_line1: form.address1.value,
          address_line2: form.address2.value,
          city: form.city.value,
          state: form.state.value,
          pincode: form.pincode.value,
        },
        subtotal: parseFloat(form.subtotal.value || '0'),
        total: parseFloat(form.total.value || form.subtotal.value || '0'),
        notes: form.notes.value,
        items
      };
      const res = await fetch('/api/save_order' + <?= json_encode($routeExt) ?>, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)});
      const json = await res.json();
      const out = document.getElementById('out');
      if (json.ok) {
        out.className = 'ok';
        out.textContent = 'Saved: order #' + json.order_id + ' (customer #' + json.customer_id + ')';
      } else {
        out.className = 'err';
        out.textContent = 'Error: ' + (json.error || 'Unknown');
        console.error(json);
      }
    }
    function addRow(){
      const tbody = document.querySelector('#items tbody');
      const tr = document.createElement('tr');
      tr.innerHTML = '<td><input name="item_name[]" required></td>'+
                     '<td><input name="item_sku[]"></td>'+
                     '<td><input name="item_price[]" type="number" step="0.01" value="0"></td>'+
                     '<td><input name="item_qty[]" type="number" step="1" value="1" min="1"></td>'+
                     '<td><button type="button" onclick="this.closest(\'tr\').remove()">Remove</button></td>';
      tbody.appendChild(tr);
    }
    document.addEventListener('DOMContentLoaded', addRow);
  </script>
  
</head>
<body>
  <h1>Order Demo</h1>
  <p>Fill the form and it will POST to <code><?= htmlspecialchars('/api/save_order' . $routeExt) ?></code> and store in MySQL.</p>
  <div id="out"></div>

  <form onsubmit="submitOrder(event)">
    <h2>Customer</h2>
    <label>Name<input name="name" required></label>
    <div class="row">
      <label>Email<input name="email" type="email"></label>
      <label>Phone<input name="phone"></label>
    </div>
    <label>Address line 1<input name="address1"></label>
    <label>Address line 2<input name="address2"></label>
    <div class="row">
      <label>City<input name="city"></label>
      <label>State<input name="state"></label>
    </div>
    <label>Pincode<input name="pincode"></label>

    <h2>Items</h2>
    <table id="items">
      <thead>
        <tr><th>Name</th><th>SKU</th><th>Unit Price</th><th>Qty</th><th></th></tr>
      </thead>
      <tbody></tbody>
    </table>
    <div class="actions">
      <button type="button" onclick="addRow()">Add item</button>
    </div>

    <h2>Totals</h2>
    <div class="row">
      <label>Subtotal <input name="subtotal" type="number" step="0.01" value="0"></label>
      <label>Total <input name="total" type="number" step="0.01" value="0" required></label>
    </div>
    <label>Notes <textarea name="notes" rows="3"></textarea></label>

    <div class="actions"><button type="submit">Save Order</button></div>
  </form>
</body>
</html>
