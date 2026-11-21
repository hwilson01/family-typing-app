// Turtle Reef — a calm, unlosable reward mini-game. Swim a sea turtle (mouse or
// arrow keys) to collect shells for a short while, then a soft finish. Vanilla
// canvas, no libraries. Calls onDone(collected) when the swim ends.

export function playTurtleGame(container, onDone) {
  const W = 720, H = 440, DURATION = 38;
  const wrap = el('div', 'game-wrap');
  const hud = el('div', 'game-hud');
  const score = el('div', 'game-score');
  const meter = el('div', 'game-meter');
  const meterFill = el('div', 'game-meter-fill');
  meter.append(meterFill);
  const finishBtn = document.createElement('button');
  finishBtn.className = 'game-finish'; finishBtn.textContent = "I'm done";
  hud.append(score, meter, finishBtn);
  const canvas = document.createElement('canvas');
  canvas.width = W; canvas.height = H; canvas.className = 'game-canvas';
  wrap.append(hud, canvas);
  container.append(wrap);
  const ctx = canvas.getContext('2d');

  const turtle = { x: W / 2, y: H / 2, r: 24, dir: 1 };
  const target = { x: W / 2, y: H / 2 };
  const keys = {};
  let items = [], bubbles = [], sparks = [];
  let collected = 0, elapsed = 0, last = 0, running = true;

  function el(tag, cls) { const n = document.createElement('div'); n.className = cls; return n; }

  const onMove = (e) => {
    const r = canvas.getBoundingClientRect();
    target.x = (e.clientX - r.left) * (W / r.width);
    target.y = (e.clientY - r.top) * (H / r.height);
  };
  const onKey = (e) => {
    if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key)) {
      keys[e.key] = e.type === 'keydown'; e.preventDefault();
    }
  };
  canvas.addEventListener('mousemove', onMove);
  window.addEventListener('keydown', onKey);
  window.addEventListener('keyup', onKey);

  function cleanup() {
    running = false;
    canvas.removeEventListener('mousemove', onMove);
    window.removeEventListener('keydown', onKey);
    window.removeEventListener('keyup', onKey);
  }
  finishBtn.onclick = () => { if (running) { cleanup(); onDone(collected); } };

  function spawnItem() {
    const m = 44;
    items.push({ x: m + Math.random() * (W - 2 * m), y: m + Math.random() * (H - 60 - m),
                 r: 15, type: Math.random() < 0.18 ? 'star' : 'shell', phase: Math.random() * 6.28 });
  }
  for (let i = 0; i < 6; i++) spawnItem();
  for (let i = 0; i < 26; i++) bubbles.push({ x: Math.random() * W, y: Math.random() * H, r: 2 + Math.random() * 4, sp: 18 + Math.random() * 26 });

  function loop(now) {
    if (!running) return;
    const dt = Math.min((now - last) / 1000, 0.05); last = now; elapsed += dt;

    const speed = 250; let moved = false;
    if (keys.ArrowLeft)  { turtle.x -= speed * dt; moved = true; turtle.dir = -1; }
    if (keys.ArrowRight) { turtle.x += speed * dt; moved = true; turtle.dir = 1; }
    if (keys.ArrowUp)    { turtle.y -= speed * dt; moved = true; }
    if (keys.ArrowDown)  { turtle.y += speed * dt; moved = true; }
    if (!moved) {
      const k = Math.min(1, dt * 4);
      turtle.x += (target.x - turtle.x) * k;
      turtle.y += (target.y - turtle.y) * k;
      if (target.x < turtle.x - 3) turtle.dir = -1; else if (target.x > turtle.x + 3) turtle.dir = 1;
    }
    turtle.x = Math.max(turtle.r, Math.min(W - turtle.r, turtle.x));
    turtle.y = Math.max(turtle.r, Math.min(H - 30 - turtle.r, turtle.y));

    for (const it of items) it.phase += dt * 2;
    items = items.filter((it) => {
      const dx = it.x - turtle.x, dy = (it.y + Math.sin(it.phase) * 4) - turtle.y;
      if (dx * dx + dy * dy < (turtle.r + it.r) * (turtle.r + it.r)) {
        collected += it.type === 'star' ? 2 : 1;
        sparks.push({ x: it.x, y: it.y, t: 0 });
        return false;
      }
      return true;
    });
    while (items.length < 6) spawnItem();
    for (const b of bubbles) { b.y -= b.sp * dt; if (b.y < -6) { b.y = H + 6; b.x = Math.random() * W; } }
    sparks = sparks.filter((s) => (s.t += dt) < 0.5);

    draw();
    score.textContent = `🐚 ${collected}`;
    meterFill.style.width = `${Math.min(100, elapsed / DURATION * 100)}%`;
    if (elapsed >= DURATION) { cleanup(); onDone(collected); return; }
    requestAnimationFrame(loop);
  }

  function draw() {
    ctx.fillStyle = '#8fd6e8'; ctx.fillRect(0, 0, W, H * 0.5);
    ctx.fillStyle = '#54b8d6'; ctx.fillRect(0, H * 0.5, W, H * 0.5);
    ctx.fillStyle = '#f2e2b6'; ctx.fillRect(0, H - 28, W, 28);
    ctx.strokeStyle = '#3a8f5e'; ctx.lineWidth = 7; ctx.lineCap = 'round';
    for (const x of [60, 160, 600, 680]) {
      ctx.beginPath(); ctx.moveTo(x, H - 28);
      ctx.quadraticCurveTo(x + 14, H - 70, x, H - 110); ctx.stroke();
    }
    ctx.fillStyle = 'rgba(255,255,255,0.45)';
    for (const b of bubbles) { ctx.beginPath(); ctx.arc(b.x, b.y, b.r, 0, 6.283); ctx.fill(); }
    for (const it of items) drawItem(it);
    for (const s of sparks) {
      ctx.globalAlpha = 1 - s.t * 2; ctx.fillStyle = '#fff6c2';
      ctx.beginPath(); ctx.arc(s.x, s.y, 6 + s.t * 20, 0, 6.283); ctx.fill(); ctx.globalAlpha = 1;
    }
    drawTurtle();
  }

  function drawItem(it) {
    const y = it.y + Math.sin(it.phase) * 4;
    ctx.lineWidth = 2; ctx.strokeStyle = '#1b1b1b';
    if (it.type === 'star') {
      ctx.fillStyle = '#ffcf4d'; ctx.beginPath();
      for (let i = 0; i < 10; i++) {
        const ang = -Math.PI / 2 + i * Math.PI / 5, rad = i % 2 ? 6 : 14;
        ctx[i ? 'lineTo' : 'moveTo'](it.x + Math.cos(ang) * rad, y + Math.sin(ang) * rad);
      }
      ctx.closePath(); ctx.fill(); ctx.stroke();
    } else {
      ctx.fillStyle = '#ff9ec2'; ctx.beginPath();
      ctx.arc(it.x, y + 4, 13, Math.PI, 0); ctx.closePath(); ctx.fill(); ctx.stroke();
      ctx.beginPath();
      for (const d of [-8, 0, 8]) { ctx.moveTo(it.x + d * 0.5, y + 3); ctx.lineTo(it.x + d, y - 8); }
      ctx.stroke();
    }
  }

  function drawTurtle() {
    ctx.save(); ctx.translate(turtle.x, turtle.y); ctx.scale(turtle.dir, 1);
    ctx.lineWidth = 3; ctx.strokeStyle = '#1b1b1b'; ctx.fillStyle = '#54a878';
    for (const [fx, fy] of [[-14, -16], [-14, 16], [16, -12], [16, 12]]) {
      ctx.beginPath(); ctx.ellipse(fx, fy, 11, 7, 0, 0, 6.283); ctx.fill(); ctx.stroke();
    }
    ctx.fillStyle = '#4a9d6e';
    ctx.beginPath(); ctx.ellipse(24, 0, 10, 9, 0, 0, 6.283); ctx.fill(); ctx.stroke();
    ctx.beginPath(); ctx.ellipse(0, 0, 24, 19, 0, 0, 6.283); ctx.fill(); ctx.stroke();
    ctx.fillStyle = '#6cc090';
    for (const [hx, hy] of [[0, 0], [-10, 0], [10, 0], [0, -9], [0, 9]]) {
      ctx.beginPath(); ctx.arc(hx, hy, 4.5, 0, 6.283); ctx.fill();
    }
    ctx.fillStyle = '#1c2b22';
    ctx.beginPath(); ctx.arc(28, -3, 2.3, 0, 6.283); ctx.fill();
    ctx.restore();
  }

  requestAnimationFrame((t) => { last = t; loop(t); });
  return cleanup;
}
