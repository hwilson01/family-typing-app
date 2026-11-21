// App shell: session routing + every screen. Vanilla ES modules, no build step.
import { api } from './api.js';
import { TypingSession } from './engine.js';
import { KeyboardView } from './keyboard.js';

const AVATARS = {
  cat: '🐱', dog: '🐶', owl: '🦉', fox: '🦊', panda: '🐼', unicorn: '🦄',
  robot: '🤖', star: '⭐', rocket: '🚀', frog: '🐸', bear: '🐻', tiger: '🐯',
  penguin: '🐧', dragon: '🐉', bee: '🐝',
};
const AVATAR_NAMES = Object.keys(AVATARS);
const avatarEmoji = (n) => AVATARS[n] || '🐱';

const root = () => document.getElementById('app');
const state = { family: false, profile: null };

// ---- tiny DOM helper ------------------------------------------------------
function el(tag, props = {}, ...kids) {
  const n = document.createElement(tag);
  for (const [k, v] of Object.entries(props)) {
    if (k === 'class') n.className = v;
    else if (k === 'html') n.innerHTML = v;
    else if (k.startsWith('on') && typeof v === 'function') n.addEventListener(k.slice(2), v);
    else if (v !== null && v !== undefined) n.setAttribute(k, v);
  }
  for (const kid of kids.flat()) {
    if (kid == null) continue;
    n.append(kid.nodeType ? kid : document.createTextNode(kid));
  }
  return n;
}
function mount(node) { const r = root(); r.innerHTML = ''; r.append(node); }
function screen(cls, ...kids) { return el('div', { class: `screen ${cls}` }, ...kids); }

// ---- boot -----------------------------------------------------------------
async function boot() {
  try {
    const s = await api.get('session');
    state.family = s.family;
    state.profile = s.profile;
  } catch (e) { /* fall through to login */ }
  route();
}

async function route() {
  if (!state.family) return showLogin();
  if (!state.profile) return showPicker();
  return showHome();
}

// ---- family login ---------------------------------------------------------
function showLogin() {
  const input = el('input', { type: 'password', class: 'big-input', placeholder: 'Family password',
                              autocomplete: 'current-password' });
  const err = el('p', { class: 'error' });
  const submit = async () => {
    err.textContent = '';
    try {
      await api.post('site/login', { password: input.value });
      state.family = true;
      route();
    } catch (e) { err.textContent = e.message || 'Try again.'; input.select(); }
  };
  input.addEventListener('keydown', (e) => { if (e.key === 'Enter') submit(); });
  mount(screen('center',
    el('div', { class: 'card' },
      el('h1', {}, '⌨️ Typing'),
      el('p', { class: 'muted' }, 'Type the family password to open the app.'),
      input, err,
      el('button', { class: 'primary big', onclick: submit }, 'Open'),
    )));
  setTimeout(() => input.focus(), 50);
}

// ---- profile picker -------------------------------------------------------
async function showPicker() {
  let profiles = [];
  try { profiles = (await api.get('profiles')).profiles; } catch (e) {}
  const cards = profiles.map((p) =>
    el('button', { class: 'avatar-card' + (p.locked ? ' locked' : ''),
                   onclick: () => p.locked ? null : showPin(p) },
      el('div', { class: 'avatar-emoji' }, avatarEmoji(p.avatar)),
      el('div', { class: 'avatar-name' }, p.name),
      p.role === 'parent' ? el('div', { class: 'tag' }, 'grown-up') : null,
      p.locked ? el('div', { class: 'tag lock' }, '🔒 locked') : null,
    ));
  mount(screen('center',
    el('h1', {}, 'Who is typing?'),
    el('div', { class: 'avatar-grid' }, ...cards),
    el('button', { class: 'link', onclick: logoutSite }, 'Lock the whole app'),
  ));
}

async function logoutSite() {
  try { await api.post('site/logout'); } catch (e) {}
  state.family = false; state.profile = null; route();
}

// ---- PIN entry ------------------------------------------------------------
function showPin(p) {
  let pin = '';
  const dots = el('div', { class: 'pin-dots' });
  const err = el('p', { class: 'error' });
  const render = () => {
    dots.innerHTML = '';
    for (let i = 0; i < Math.max(4, pin.length); i++) {
      dots.append(el('span', { class: 'pin-dot' + (i < pin.length ? ' on' : '') }));
    }
  };
  const submit = async () => {
    try {
      const r = await api.post('profile/select', { profile_id: p.id, pin });
      state.profile = r.profile;
      route();
    } catch (e) {
      if (e.error === 'locked') {
        const secs = e.data?.locked_seconds || 0;
        const mins = Math.ceil(secs / 60);
        err.textContent = mins > 0
          ? `Too many tries. Try again in about ${mins} minute${mins === 1 ? '' : 's'}.`
          : 'Too many tries. Try again in a little while.';
      } else if (e.error === 'wrong_pin') {
        err.textContent = 'That PIN is not right.' +
          (e.data?.remaining != null ? ` ${e.data.remaining} tries left.` : '');
      } else err.textContent = e.message || 'Try again.';
      pin = ''; render();
    }
  };
  const press = (d) => { if (pin.length < 10) { pin += d; render(); } };
  const pad = el('div', { class: 'keypad' });
  ['1','2','3','4','5','6','7','8','9'].forEach((d) =>
    pad.append(el('button', { class: 'key', onclick: () => press(d) }, d)));
  pad.append(el('button', { class: 'key', onclick: () => { pin = pin.slice(0, -1); render(); } }, '⌫'));
  pad.append(el('button', { class: 'key', onclick: () => press('0') }, '0'));
  pad.append(el('button', { class: 'key go', onclick: submit }, '✓'));

  document.onkeydown = (e) => {
    if (/^[0-9]$/.test(e.key)) press(e.key);
    else if (e.key === 'Backspace') { pin = pin.slice(0, -1); render(); }
    else if (e.key === 'Enter') submit();
  };
  render();
  mount(screen('center',
    el('div', { class: 'card' },
      el('div', { class: 'avatar-emoji big' }, avatarEmoji(p.avatar)),
      el('h2', {}, `Hi, ${p.name}!`),
      el('p', { class: 'muted' }, 'Type your PIN.'),
      dots, err, pad,
      el('button', { class: 'link', onclick: () => { document.onkeydown = null; showPicker(); } }, '← back'),
    )));
}

// ---- home / dashboard -----------------------------------------------------
async function showHome() {
  document.onkeydown = null;
  const p = state.profile;
  let lessons = [], progress = null;
  try {
    const r = await api.get('lessons'); lessons = r.lessons;
    progress = (await api.get('progress')).progress;
  } catch (e) {}

  // First-time child with no attempts → placement.
  if (p.role === 'child' && progress && progress.total_attempts === 0) {
    return showPlacementIntro();
  }

  const current = lessons.find((l) => l.status === 'current') || lessons[0];
  const header = el('div', { class: 'home-head' },
    el('div', { class: 'avatar-emoji' }, avatarEmoji(p.avatar)),
    el('div', {}, el('h1', {}, `Hi, ${p.name}!`),
      progress ? el('p', { class: 'muted' },
        `🔥 ${progress.streak}-day streak · ⭐ ${lessons.reduce((a,l)=>a+(l.best?.stars||0),0)} stars`) : null),
    el('div', { class: 'spacer' }),
    el('button', { class: 'link', onclick: switchProfile }, 'Switch'),
  );

  const keepGoing = current ? el('button', { class: 'primary huge',
      onclick: () => startLesson(current.id) },
      el('div', {}, current.status === 'current' ? 'Keep going →' : 'Practice →'),
      el('div', { class: 'sub' }, current.title)) : null;

  const map = el('div', { class: 'lesson-map' });
  let stage = null;
  for (const l of lessons) {
    if (l.stage !== stage) { stage = l.stage; map.append(el('div', { class: 'stage-label' }, stageName(stage))); }
    const locked = l.status === 'locked';
    const node = el('button', { class: `lesson-chip ${l.status}`,
      onclick: () => locked ? null : startLesson(l.id),
      title: l.title },
      el('span', { class: 'chip-no' }, l.order),
      el('span', { class: 'chip-title' }, l.title),
      el('span', { class: 'chip-stars' }, locked ? '🔒' : '★'.repeat(l.best?.stars || 0)));
    map.append(node);
  }

  const actions = el('div', { class: 'home-actions' },
    el('button', { class: 'secondary', onclick: showProgress }, '📈 My progress'),
    p.role === 'parent' ? el('button', { class: 'secondary', onclick: showParent }, '👪 Grown-up area') : null,
    el('button', { class: 'link', onclick: () => showPlacementIntro(true) }, 'Retake placement'),
  );

  mount(screen('home', header, keepGoing, actions, el('h2', {}, 'Lessons'), map));
}

function stageName(s) {
  return { home: 'Home row', top: 'Top row', bottom: 'Bottom row', shift: 'Capitals',
           punct: 'Punctuation', number: 'Numbers', fluency: 'Real typing' }[s] || s;
}

async function switchProfile() {
  try { await api.post('profile/logout'); } catch (e) {}
  state.profile = null; route();
}

// ---- placement ------------------------------------------------------------
function showPlacementIntro(retake = false) {
  mount(screen('center',
    el('div', { class: 'card' },
      el('h1', {}, retake ? 'Placement check' : 'Let\'s find your start'),
      el('p', { class: 'muted' },
        'Type a few short lines the best you can. There is no score to pass — ' +
        'it just picks a good lesson to begin with.'),
      el('button', { class: 'primary big', onclick: runPlacement }, 'Start'),
      retake ? el('button', { class: 'link', onclick: showHome }, 'Never mind') : null,
    )));
}

async function runPlacement() {
  let cfg;
  try { cfg = await api.get('placement'); } catch (e) { return showHome(); }
  const probes = cfg.probes;
  const perStage = {}; // stage -> {correct, total}
  const allKeystats = {};
  let idx = 0, totalChars = 0, totalMs = 0;

  const next = () => {
    if (idx >= probes.length) return finish();
    const probe = probes[idx];
    runTarget({
      title: `${idx + 1} of ${probes.length}: ${probe.label}`,
      prompt: 'Type this line:',
      text: probe.text,
      onDone: (res) => {
        perStage[probe.stage] = { acc: res.accuracy };
        totalChars += probe.text.length; totalMs += res.duration_ms;
        for (const ks of res.keystats) {
          const a = (allKeystats[ks.key] ||= { key: ks.key, hits: 0, misses: 0, time_ms: 0 });
          a.hits += ks.hits; a.misses += ks.misses; a.time_ms += ks.time_ms;
        }
        idx++; next();
      },
      showResult: false,
    });
  };

  const finish = async () => {
    const target = cfg.target || 0.9;
    const ss = cfg.stage_starts;
    let start = ss.shift || 15;
    if ((perStage.home?.acc ?? 0) < target) start = ss.home;
    else if ((perStage.top?.acc ?? 0) < target) start = ss.top;
    else if ((perStage.bottom?.acc ?? 0) < target) start = ss.bottom;
    const accs = Object.values(perStage).map((x) => x.acc);
    const avg = accs.length ? accs.reduce((a, b) => a + b, 0) / accs.length : 0;
    const wpm = Math.min(250, Math.round((totalChars / 5) / Math.max(totalMs / 60000, 1 / 60000)));
    try {
      const r = await api.post('placement/result', {
        current_lesson: start, accuracy: avg, wpm, duration_ms: totalMs,
        keystats: Object.values(allKeystats),
      });
      state.profile = r.profile;
    } catch (e) {}
    mount(screen('center', el('div', { class: 'card' },
      el('h1', {}, '🎉 All set!'),
      el('p', { class: 'muted' }, 'We found a good place for you to start.'),
      el('button', { class: 'primary big', onclick: showHome }, 'Let\'s type!'))));
  };
  next();
}

// ---- lesson ---------------------------------------------------------------
async function startLesson(id) {
  let L;
  try { L = await api.get('lesson', { id }); }
  catch (e) { return showHome(); }

  // Build the step list: a short tricky-keys warm-up (if any), then the lesson.
  const steps = [];
  if (L.tricky_keys && L.tricky_keys.length) {
    const keys = L.tricky_keys.map((t) => t.key).filter((k) => k !== 'space');
    if (keys.length) {
      const warm = keys.map((k) => `${k}${k}${k}`).join(' ') + ' ' +
                   keys.join('') + ' fj ' + keys.map((k) => `${k}f ${k}j`).join(' ');
      steps.push({ kind: 'tricky', prompt: 'Warm up your tricky keys:', text: warm.trim() });
    }
  }
  for (const s of L.steps) steps.push(s);

  // Aggregate across steps, submit one attempt.
  const agg = { correct: 0, wrong: 0, activeMs: 0, chars: 0, keystats: {} };
  let stepIdx = 0;
  const next = () => {
    if (stepIdx >= steps.length) return submit();
    const s = steps[stepIdx];
    runTarget({
      title: `${L.title}`,
      badge: `Step ${stepIdx + 1} of ${steps.length}`,
      prompt: friendlyPrompt(s),
      text: s.text,
      onDone: (res) => {
        agg.correct += res.correct; agg.wrong += res.wrong;
        agg.activeMs += res.duration_ms; agg.chars += s.text.length;
        for (const ks of res.keystats) {
          const a = (agg.keystats[ks.key] ||= { key: ks.key, hits: 0, misses: 0, time_ms: 0 });
          a.hits += ks.hits; a.misses += ks.misses; a.time_ms += ks.time_ms;
        }
        stepIdx++; next();
      },
    });
  };

  const submit = async () => {
    const total = agg.correct + agg.wrong;
    const accuracy = total ? agg.correct / total : 1;
    const wpm = Math.min(250, Math.round((agg.chars / 5) / Math.max(agg.activeMs / 60000, 1 / 60000)));
    let r = { stars: 0, passed: false, current_lesson: state.profile.current_lesson };
    try {
      r = await api.post('attempt', {
        lesson_id: L.id, source: 'lesson', accuracy, wpm,
        duration_ms: agg.activeMs, keystats: Object.values(agg.keystats),
      });
      if (r.current_lesson) state.profile.current_lesson = r.current_lesson;
    } catch (e) {}
    showResult(L, { accuracy, wpm, stars: r.stars, passed: r.passed });
  };

  next();
}

function friendlyPrompt(s) {
  if (s.prompt) return s.prompt;
  return { tricky: 'Warm-up', intro: 'New keys', mix: 'Mix it up',
           words: 'Real words', sentence: 'Type the sentence' }[s.kind] || 'Type this:';
}

// Generic "type this target" view used by lessons AND placement.
let kbPref = localStorage.getItem('kb_hidden') !== '1';
function runTarget({ title, badge, prompt, text, onDone, showResult = true }) {
  const chars = [...text];
  const textEl = el('div', { class: 'type-target' });
  const spans = chars.map((c, i) => {
    const sp = el('span', { class: 'ch' }, c === ' ' ? ' ' : c);
    if (c === ' ') sp.classList.add('space');
    textEl.append(sp);
    return sp;
  });
  const bar = el('div', { class: 'bar' }, el('div', { class: 'bar-fill' }));
  const acc = el('span', { class: 'live-acc' }, '100%');
  const kb = new KeyboardView();
  const kbWrap = el('div', { class: 'kb-wrap' });

  const session = new TypingSession(text, {
    onProgress: (st) => {
      spans.forEach((sp, i) => {
        sp.classList.toggle('done', i < st.pos);
        sp.classList.toggle('cur', i === st.pos);
      });
      bar.querySelector('.bar-fill').style.width = `${(st.pos / st.total) * 100}%`;
      acc.textContent = `${Math.round(st.accuracy * 100)}%`;
      if (st.kind === 'miss') {
        spans[st.pos]?.classList.add('miss');
        setTimeout(() => spans[st.pos]?.classList.remove('miss'), 200);
        kb.flashWrong(st.expected);
      }
      kb.highlightNext(st.expected);
    },
    onComplete: (res) => { document.onkeydown = null; onDone(res); },
  });

  document.onkeydown = (e) => {
    if (e.metaKey || e.ctrlKey || e.altKey) return;      // let shortcuts through
    if (e.key.length === 1 || e.key === ' ') e.preventDefault();
    session.handleKey(e);
  };

  const toggle = el('button', { class: 'link', onclick: () => {
    kbPref = !kbPref; localStorage.setItem('kb_hidden', kbPref ? '0' : '1'); kb.setVisible(kbPref);
    toggle.textContent = kbPref ? 'Hide keyboard' : 'Show keyboard';
  } }, kbPref ? 'Hide keyboard' : 'Show keyboard');

  mount(screen('lesson',
    el('div', { class: 'lesson-top' },
      el('button', { class: 'link', onclick: () => { document.onkeydown = null; showHome(); } }, '← home'),
      el('div', { class: 'spacer' }),
      badge ? el('span', { class: 'badge' }, badge) : null,
      el('span', { class: 'live-acc-wrap' }, 'accuracy ', acc),
    ),
    el('h2', {}, title),
    el('p', { class: 'prompt muted' }, prompt),
    bar,
    textEl,
    kbWrap,
    el('div', { class: 'lesson-foot' }, toggle),
  ));
  kb.render(kbWrap);
  kb.setVisible(kbPref);
  kb.highlightNext(session.expectedChar());
}

function showResult(L, { accuracy, wpm, stars, passed }) {
  const pct = Math.round(accuracy * 100);
  const starRow = el('div', { class: 'star-row' },
    ...[1, 2, 3].map((i) => el('span', { class: 'star' + (i <= stars ? ' on' : '') }, '★')));
  const headline = passed ? (stars === 3 ? 'Perfect typing! 🌟' : 'You passed! 🎉') : 'Good practice! 💪';
  const note = passed
    ? 'The next lesson is unlocked.'
    : `So close — you need 90% to move on. You got ${pct}%. Give it another go!`;
  mount(screen('center',
    el('div', { class: 'card result' },
      starRow,
      el('h1', {}, headline),
      el('div', { class: 'result-stats' },
        el('div', {}, el('b', {}, `${pct}%`), el('span', { class: 'muted' }, 'accuracy')),
        el('div', {}, el('b', {}, wpm), el('span', { class: 'muted' }, 'words/min'))),
      el('p', { class: 'muted' }, note),
      el('div', { class: 'result-actions' },
        el('button', { class: 'secondary', onclick: () => startLesson(L.id) }, 'Try again'),
        el('button', { class: 'primary', onclick: showHome }, passed ? 'Next →' : 'Back to lessons')),
    )));
}

// ---- progress (child) -----------------------------------------------------
async function showProgress() {
  let pr;
  try { pr = (await api.get('progress')).progress; } catch (e) { return showHome(); }
  mount(screen('progress',
    el('div', { class: 'lesson-top' },
      el('button', { class: 'link', onclick: showHome }, '← home'), el('div', { class: 'spacer' })),
    el('h1', {}, 'My progress'),
    el('div', { class: 'stat-grid' },
      stat('🔥', pr.streak, 'day streak'),
      stat('📅', pr.days_practiced, 'days practiced'),
      stat('⏱️', pr.total_minutes, 'minutes typed'),
      stat('✅', pr.lessons_passed, 'lessons passed'),
      stat('🎯', `${Math.round(pr.avg_accuracy * 100)}%`, 'avg accuracy'),
      stat('⚡', pr.best_wpm, 'best words/min')),
    el('p', { class: 'muted' }, 'Keep practicing a little each day — that is how typing sticks.'),
  ));
}
function stat(icon, value, label) {
  return el('div', { class: 'stat' }, el('div', { class: 'stat-icon' }, icon),
    el('div', { class: 'stat-value' }, String(value)), el('div', { class: 'stat-label muted' }, label));
}

// ---- parent area ----------------------------------------------------------
async function showParent() {
  let children = [], profiles = [];
  try { children = (await api.get('parent/progress')).children; } catch (e) {}
  try { profiles = (await api.get('profiles')).profiles; } catch (e) {}

  const rows = children.map((c) => el('tr', {},
    el('td', {}, `${avatarEmoji(c.profile.avatar)} ${c.profile.name}`),
    el('td', {}, String(c.days_practiced)),
    el('td', {}, String(c.streak)),
    el('td', {}, String(c.total_minutes)),
    el('td', {}, `${Math.round((c.avg_accuracy || 0) * 100)}%`),
    el('td', {}, String(c.best_wpm)),
    el('td', {}, String(c.avg_wpm)),
    el('td', {}, String(c.lessons_passed)),
  ));
  const table = children.length ? el('table', { class: 'parent-table' },
    el('thead', {}, el('tr', {}, ...['Child','Days','Streak','Min','Acc','Best WPM','Avg WPM','Passed']
      .map((h) => el('th', {}, h)))),
    el('tbody', {}, ...rows)) : el('p', { class: 'muted' }, 'No children have practiced yet.');

  const list = el('div', { class: 'profile-list' },
    ...profiles.map((p) => el('div', { class: 'profile-row' },
      el('span', {}, `${avatarEmoji(p.avatar)} ${p.name}`),
      el('span', { class: 'tag' }, p.role),
      el('button', { class: 'link', onclick: () => editProfile(p) }, 'Edit'))));

  mount(screen('parent',
    el('div', { class: 'lesson-top' },
      el('button', { class: 'link', onclick: showHome }, '← home'), el('div', { class: 'spacer' })),
    el('h1', {}, 'Grown-up area'),
    el('h2', {}, 'Progress'), table,
    el('h2', {}, 'Profiles'), list,
    el('button', { class: 'primary', onclick: () => editProfile(null) }, '+ Add profile'),
  ));
}

function editProfile(p) {
  const isNew = !p;
  const name = el('input', { class: 'big-input', value: p?.name || '', placeholder: 'Name' });
  const avatarSel = el('select', { class: 'big-input' },
    ...AVATAR_NAMES.map((a) => el('option', { value: a, ...(p?.avatar === a ? { selected: 'selected' } : {}) },
      `${AVATARS[a]} ${a}`)));
  const roleSel = el('select', { class: 'big-input' },
    el('option', { value: 'child', ...(p?.role !== 'parent' ? { selected: 'selected' } : {}) }, 'child'),
    el('option', { value: 'parent', ...(p?.role === 'parent' ? { selected: 'selected' } : {}) }, 'grown-up'));
  const level = el('input', { class: 'big-input', type: 'number', min: '1', max: '12',
                              value: String(p?.reading_level || 4) });
  const pin = el('input', { class: 'big-input', type: 'password', inputmode: 'numeric',
                            placeholder: isNew ? 'PIN (4–10 digits)' : 'New PIN (leave blank to keep)' });
  const err = el('p', { class: 'error' });

  const save = async () => {
    err.textContent = '';
    const payload = { name: name.value, avatar: avatarSel.value, role: roleSel.value,
                      reading_level: Number(level.value) };
    try {
      if (isNew) {
        payload.pin = pin.value;
        await api.post('profile/create', payload);
      } else {
        payload.id = p.id;
        if (pin.value) payload.pin = pin.value;
        await api.post('profile/update', payload);
      }
      showParent();
    } catch (e) { err.textContent = e.message || 'Could not save.'; }
  };

  mount(screen('center',
    el('div', { class: 'card' },
      el('h1', {}, isNew ? 'Add profile' : `Edit ${p.name}`),
      el('label', {}, 'Name'), name,
      el('label', {}, 'Avatar'), avatarSel,
      el('label', {}, 'Role'), roleSel,
      el('label', {}, 'Reading level (Phase 2 content)'), level,
      el('label', {}, 'PIN'), pin, err,
      el('div', { class: 'result-actions' },
        el('button', { class: 'secondary', onclick: showParent }, 'Cancel'),
        el('button', { class: 'primary', onclick: save }, 'Save')),
    )));
}

boot();
