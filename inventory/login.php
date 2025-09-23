<?php
require_once __DIR__ . '/../lib/auth.php';
admin_session_start();
if (isset($_GET['logout'])) { admin_logout(); header('Location: /inventory/login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!function_exists('admin_csrf_check')) { require_once __DIR__ . '/../lib/auth.php'; }
  if (!admin_csrf_check($_POST['csrf'] ?? '')) { http_response_code(400); $err = 'Invalid session, please try again'; }
  $user = trim($_POST['user'] ?? '');
  $pass = trim($_POST['pass'] ?? '');
  if (empty($err) && admin_login($user, $pass)) {
    header('Location: /inventory/dashboard.php');
    exit;
  }
  $err = 'Invalid credentials';
}
?><!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Inventory Login • PKS Crackers</title>
    <style>
      :root{ --bg:#0b1020; --panel:rgba(255,255,255,.07); --line:rgba(255,255,255,.12); --text:#e7eefc; --muted:#9fb4d6; --brand:#e11d48; --brand2:#f97316 }
      *{box-sizing:border-box}
      body{margin:0;min-height:100vh;background:var(--bg);color:var(--text);font:16px/1.5 system-ui,-apple-system,Segoe UI,Roboto;position:relative;overflow:hidden}
      header{position:absolute;left:0;right:0;top:0;display:flex;justify-content:center;padding:16px;pointer-events:none}
      .brand{display:flex;gap:10px;align-items:center;background:linear-gradient(135deg,#ffffff10,#ffffff05);backdrop-filter:saturate(140%) blur(6px);border:1px solid var(--line);border-radius:999px;padding:8px 14px}
      .logo{width:28px;height:28px;border-radius:8px;background:#fff url('/logo.jpeg') center/cover no-repeat}
      .brand b{letter-spacing:.3px}
      .wrap{display:grid;place-items:center;min-height:100vh;padding:24px}
      .card{width:380px;max-width:92vw;background:var(--panel);border:1px solid var(--line);border-radius:16px;padding:20px;position:relative;z-index:2;box-shadow:0 10px 30px rgba(0,0,0,.25)}
      h1{margin:0 0 6px;font-size:20px}
      p.sub{margin:0 0 12px;color:var(--muted);font-size:13px}
      label{display:block;margin:10px 0 6px;color:var(--muted);font-size:13px}
      .field{position:relative}
      .field input{width:100%;padding:12px 40px 12px 12px;border-radius:10px;border:1px solid var(--line);background:rgba(255,255,255,.06);color:var(--text)}
      .field .eye{position:absolute;right:10px;top:50%;transform:translateY(-50%);cursor:pointer;opacity:.8}
      .btn{width:100%;margin-top:12px;appearance:none;border:0;border-radius:10px;padding:12px;background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff;font-weight:800;cursor:pointer}
      .row{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:8px}
      .muted{color:var(--muted);font-size:12px}
      .err{margin-top:8px;color:#fca5a5}
      canvas#fx{position:absolute;inset:0;z-index:1;opacity:.9}
    </style>
  </head>
  <body>
    <header>
      <div class="brand"><div class="logo" aria-hidden="true"></div><b>PKS Crackers • Inventory</b></div>
    </header>
    <canvas id="fx"></canvas>
    <div class="wrap">
      <form class="card" method="post" autocomplete="username">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(admin_csrf_token()) ?>">
        <h1>Welcome back</h1>
        <p class="sub">Sign in to manage stock and orders</p>
        <label>Username</label>
        <div class="field"><input name="user" required autofocus placeholder="Enter username"></div>
        <label>Password</label>
        <div class="field">
          <input name="pass" id="pass" type="password" required placeholder="Enter password" autocomplete="current-password">
          <span class="eye" id="toggle" title="Show/Hide">👁️</span>
        </div>
        <div class="row">
          <label class="muted" style="display:flex;gap:6px;align-items:center"><input type="checkbox" id="remember"> Remember me</label>
          <a class="muted" href="#" onclick="alert('Ask admin to reset password');return false;">Forgot password?</a>
        </div>
        <button class="btn" type="submit" id="submitBtn">Sign in</button>
        <?php if (!empty($err)): ?><div class="err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
        <div class="muted" style="margin-top:10px">Tip: Default admin can be set in config.local.php</div>
      </form>
    </div>
    <script>
    const p = document.getElementById('pass');
    const t = document.getElementById('toggle');
    t.addEventListener('click', ()=>{ p.type = p.type==='password' ? 'text' : 'password'; });
    document.getElementById('submitBtn').addEventListener('click', ()=> setTimeout(()=>{ document.getElementById('submitBtn').disabled=true; }, 0));
    (function(){
      const canvas = document.getElementById('fx'); const ctx = canvas.getContext('2d');
      let W=0,H=0; const parts=[]; function resize(){ W=canvas.width=innerWidth; H=canvas.height=innerHeight; } resize(); addEventListener('resize', resize);
      function burst(x,y,cnt=30){ for(let i=0;i<cnt;i++){ const a=Math.random()*Math.PI*2; const v=1+Math.random()*3; parts.push({x,y,vx:Math.cos(a)*v,vy:Math.sin(a)*v,life:800+Math.random()*600,age:0,h:Math.random()*360}); } }
      let last=0; function step(ts){ const dt=Math.min(32,(ts-(last||ts))); last=ts; ctx.fillStyle='rgba(11,16,32,0.25)'; ctx.fillRect(0,0,W,H);
        for(let i=parts.length-1;i>=0;i--){ const p=parts[i]; p.age+=dt; if(p.age>p.life){ parts.splice(i,1); continue; } p.vy+=0.004*dt; p.vx*=0.996; p.vy*=0.996; p.x+=p.vx; p.y+=p.vy; const a=1-p.age/p.life; ctx.strokeStyle=`hsla(${p.h},90%,70%,${a})`; ctx.beginPath(); ctx.moveTo(p.x,p.y); ctx.lineTo(p.x-p.vx*2,p.y-p.vy*2); ctx.stroke(); }
        requestAnimationFrame(step);
      }
      requestAnimationFrame(step);
      setInterval(()=> burst(Math.random()*W*0.8+W*0.1, Math.random()*H*0.5+H*0.1, 24), 1400);
      canvas.addEventListener('click', e=> burst(e.clientX, e.clientY, 36));
    })();
    </script>
  </body>
</html>
