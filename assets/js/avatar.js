// Avatar renderer. Builds the approved v2 character as an SVG string, with the
// top / skirt / shoes / clip slots driven by the equipped cosmetics' render
// data (from the /avatar or /shop API). The base (hair, face, body) is fixed.

const DEFAULT = {
  clip:  { shape: 'turtle', color: '#4a9d6e' },
  top:   { style: 'tee', fill: '#ffffff', accent: '#f4e59a', label: true },
  skirt: { fill: '#f2e8cf', line: '#d9c79a', style: 'plain' },
  shoes: { fill: '#ffffff', sole: '#ff6f91' },
};

function clipSVG(c) {
  const col = c.color || '#4a9d6e';
  switch (c.shape) {
    case 'bow':
      return `<path d="M300 104 l-22 -12 0 24 Z" class="ol" fill="${col}"/>`
           + `<path d="M300 104 l22 -12 0 24 Z" class="ol" fill="${col}"/>`
           + `<circle cx="300" cy="104" r="6" class="ol" fill="${col}"/>`;
    case 'star':
      return `<path d="M300 86 l7 16 18 2 -13 12 4 18 -16 -9 -16 9 4 -18 -13 -12 18 -2 Z" class="ol" fill="${col}"/>`;
    case 'shell':
      return `<path d="M300 120 C276 120 276 92 300 92 C324 92 324 120 300 120 Z" class="ol" fill="${col}"/>`
           + `<path d="M300 118 l0 -24 M288 115 l6 -22 M312 115 l-6 -22" class="ol2" fill="none" stroke="#ffffff"/>`;
    default: // turtle
      return `<ellipse cx="300" cy="104" rx="19" ry="15" class="ol2" fill="${col}"/>`
           + `<circle cx="288" cy="100" r="6" fill="#6cc090"/>`
           + `<ellipse cx="283" cy="100" rx="7" ry="4" fill="#3a8f5e"/>`
           + `<circle cx="306" cy="98" r="2" fill="#15311f"/>`;
  }
}

function topSVG(t) {
  let s = `<path d="M300 300 q40 -16 80 0 L404 388 q-64 22 -128 0 Z" class="ol" fill="${t.fill}"/>`;
  s += `<path d="M300 300 q40 -16 80 0 l-6 14 q-34 -12 -68 0 Z" fill="rgba(0,0,0,0.08)"/>`;
  if (t.style === 'striped') {
    for (const y of [322, 338, 354, 370]) {
      s += `<path d="M300 ${y} q40 -10 80 0" fill="none" stroke="${t.accent}" stroke-width="7" stroke-linecap="round"/>`;
    }
  } else if (t.style === 'hoodie') {
    s += `<path d="M300 300 q40 -26 80 0 q-40 -4 -80 0 Z" class="ol" fill="${t.accent}"/>`;
    s += `<path d="M320 350 l40 0 0 30 -40 0 Z" class="ol2" fill="${t.accent}"/>`;
    s += `<path d="M330 306 l-4 20 M350 306 l4 20" class="ol2" fill="none" stroke="#ffffff"/>`;
  } else {
    s += `<path d="M300 302 q-16 40 -8 86 l18 -4 q-10 -42 2 -80 Z" class="ol2" fill="${t.accent}"/>`;
    s += `<path d="M380 302 q16 40 8 86 l-18 -4 q10 -42 -2 -80 Z" class="ol2" fill="${t.accent}"/>`;
  }
  s += `<path d="M330 306 l10 16 10 -16" class="ol2" fill="none"/>`;
  if (t.label) {
    s += `<text x="340" y="348" text-anchor="middle" font-family="system-ui,sans-serif" font-size="13" font-weight="700" fill="#163a5f">Ellie</text>`
       + `<text x="340" y="366" text-anchor="middle" font-family="system-ui,sans-serif" font-size="13" font-weight="700" fill="#163a5f">is #1</text>`;
  }
  return s;
}

function skirtSVG(sk) {
  let s = `<path d="M276 384 q64 20 128 0 l16 86 q-80 26 -160 0 Z" class="ol" fill="${sk.fill}"/>`;
  if (sk.style === 'rainbow') {
    const dots = [['#ff6f91', 300, 410], ['#3fb3c9', 340, 420], ['#7ad17f', 380, 410],
                  ['#b07ad1', 318, 446], ['#ff9f4d', 362, 446]];
    for (const [c, x, y] of dots) s += `<circle cx="${x}" cy="${y}" r="6" fill="${c}"/>`;
    s += `<path d="M260 470 q80 26 160 0 l0 -8 q-80 24 -160 0 Z" fill="#ff6f91"/>`;
  }
  s += `<path d="M300 392 l6 76 M340 396 l0 74 M380 392 l-6 76" class="ol2" fill="none" stroke="${sk.line}"/>`;
  s += `<circle cx="340" cy="416" r="4" class="ol2" fill="${sk.line}"/><circle cx="340" cy="442" r="4" class="ol2" fill="${sk.line}"/>`;
  return s;
}

function shoesSVG(sh) {
  return `<path d="M306 556 q-2 24 20 24 l22 0 q6 -12 -2 -24 Z" class="ol" fill="${sh.fill}"/>`
       + `<path d="M342 556 q-2 24 20 24 l22 0 q6 -12 -2 -24 Z" class="ol" fill="${sh.fill}"/>`
       + `<path d="M306 574 l40 0 q4 6 -2 10 l-34 0 q-6 -4 -4 -10 Z" fill="${sh.sole}"/>`
       + `<path d="M342 574 l40 0 q4 6 -2 10 l-34 0 q-6 -4 -4 -10 Z" fill="${sh.sole}"/>`;
}

const BASE_BACK = `
<path d="M340 86 C232 86 168 176 176 298 C180 372 196 436 220 486 L240 452 L262 494 L288 456 L312 498 L340 460 L368 498 L392 456 L418 494 L440 452 L460 486 C486 432 504 360 506 292 C512 172 448 86 340 86 Z" class="ol" fill="#3a2410"/>
<path d="M300 150 C250 180 236 250 246 330 C250 370 260 410 268 440 C258 360 262 270 300 150 Z" fill="#54371d"/>
<path d="M300 420 C330 430 352 430 382 420 L382 300 L300 300 Z" class="ol" fill="#f6cda4"/>
<ellipse cx="340" cy="212" rx="78" ry="82" class="ol" fill="#f6cda4"/>
<path d="M398 170 C420 200 420 250 402 284 C420 240 416 196 398 170 Z" fill="#e9b082"/>
<ellipse cx="373" cy="214" rx="15" ry="20" class="ol" fill="#241a12"/>
<circle cx="368" cy="206" r="5.5" fill="#ffffff"/><circle cx="378" cy="220" r="2.4" fill="#ffffff"/>
<path d="M291 214 q16 16 34 2" class="ol" fill="none"/>
<path d="M286 206 l-7 -5 M292 200 l-5 -7" class="ol2" fill="none"/>
<path d="M356 182 q18 -8 34 4" class="ol2" fill="none"/>
<path d="M292 184 q16 -9 30 -2" class="ol2" fill="none"/>
<path d="M334 232 q8 10 14 1 q-2 8 -8 8 q-7 0 -6 -9 Z" class="ol2" fill="#e9a97f"/>
<path d="M322 250 q20 22 42 2 q-6 20 -22 20 q-17 0 -20 -22 Z" class="ol" fill="#a83f3b"/>
<path d="M326 253 q16 5 34 0 l-3 6 -28 0 Z" fill="#ffffff"/>
<path d="M332 266 q10 9 20 0 q-4 7 -10 7 q-6 0 -10 -7 Z" fill="#e8737a"/>
<ellipse cx="300" cy="244" rx="13" ry="8" fill="#ff9aa0" opacity="0.55"/>
<ellipse cx="384" cy="246" rx="12" ry="8" fill="#ff9aa0" opacity="0.55"/>
<path d="M266 150 C280 128 330 120 342 150 C330 166 330 182 338 196 C322 176 318 174 316 150 C312 180 300 196 286 206 C294 184 292 168 286 158 C280 174 270 190 256 200 C266 182 268 166 266 150 Z" class="ol" fill="#3a2410"/>
<path d="M342 150 C356 126 404 128 416 154 C420 178 414 198 404 210 C410 190 408 172 398 160 C398 184 388 198 376 208 C384 188 382 170 374 158 C366 174 354 186 344 192 C352 176 350 162 342 150 Z" class="ol" fill="#3a2410"/>
<path d="M248 210 C236 270 240 350 262 430 C250 350 248 280 262 214 Z" class="ol" fill="#3a2410"/>
<path d="M432 214 C446 278 442 360 420 436 C436 356 438 282 426 216 Z" class="ol" fill="#3a2410"/>
<ellipse cx="340" cy="112" rx="40" ry="32" class="ol" fill="#3a2410"/>
<path d="M316 104 q24 -16 48 0" class="ol2" fill="none" stroke="#5c3a1d"/>`;

const BASE_LIMBS = `
<path class="limbk" stroke-width="30" d="M326 470 L324 548"/><path class="limb" stroke-width="24" d="M326 470 L324 548"/>
<path class="limbk" stroke-width="30" d="M356 470 L358 548"/><path class="limb" stroke-width="24" d="M356 470 L358 548"/>
<rect x="310" y="536" width="30" height="20" rx="8" class="ol2" fill="#ffffff"/>
<rect x="344" y="536" width="30" height="20" rx="8" class="ol2" fill="#ffffff"/>`;

const BASE_ARMS = `
<path class="limbk" stroke-width="27" d="M296 312 L256 288 L236 244"/><path class="limb" stroke-width="22" d="M296 312 L256 288 L236 244"/>
<path class="limbk" stroke-width="27" d="M384 312 L404 352 L398 392"/><path class="limb" stroke-width="22" d="M384 312 L404 352 L398 392"/>
<ellipse cx="229" cy="230" rx="17" ry="16" class="ol" fill="#f6cda4"/>
<path class="limbk" stroke-width="15" d="M223 220 L219 198"/><path class="limb" stroke-width="11" d="M223 220 L219 198"/>
<path class="limbk" stroke-width="15" d="M236 219 L236 196"/><path class="limb" stroke-width="11" d="M236 219 L236 196"/>
<circle cx="400" cy="398" r="16" class="ol" fill="#f6cda4"/>`;

export function buildAvatarSVG(render = {}, { width = 220 } = {}) {
  const clip  = { ...DEFAULT.clip,  ...(render.clip  || {}) };
  const top   = { ...DEFAULT.top,   ...(render.top   || {}) };
  const skirt = { ...DEFAULT.skirt, ...(render.skirt || {}) };
  const shoes = { ...DEFAULT.shoes, ...(render.shoes || {}) };
  return `<svg viewBox="155 65 370 545" width="${width}" role="img" aria-label="your typing buddy" xmlns="http://www.w3.org/2000/svg">
<style>
.ol{stroke:#1b1b1b;stroke-width:5;stroke-linejoin:round;stroke-linecap:round}
.ol2{stroke:#1b1b1b;stroke-width:3.5;stroke-linejoin:round;stroke-linecap:round}
.limbk{stroke:#1b1b1b;stroke-linecap:round;stroke-linejoin:round;fill:none}
.limb{stroke:#f6cda4;stroke-linecap:round;stroke-linejoin:round;fill:none}
</style>
${BASE_BACK}
${clipSVG(clip)}
${skirtSVG(skirt)}
${BASE_LIMBS}
${shoesSVG(shoes)}
${topSVG(top)}
${BASE_ARMS}
</svg>`;
}
