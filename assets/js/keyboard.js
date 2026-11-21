// On-screen keyboard: finger color-coding + "next key" (and shift) highlight.
// Learners can hide it once they're ready (toggle handled by the lesson screen).
import { physicalKey } from './engine.js';

const SHIFT_TO_BASE = {
  '!': '1', '@': '2', '#': '3', '$': '4', '%': '5', '^': '6', '&': '7',
  '*': '8', '(': '9', ')': '0', '_': '-', '+': '=', '{': '[', '}': ']',
  '|': '\\', ':': ';', '"': "'", '<': ',', '>': '.', '?': '/', '~': '`',
};

const FINGER = {
  l_pinky: ['`', '1', 'q', 'a', 'z'],
  l_ring:  ['2', 'w', 's', 'x'],
  l_middle:['3', 'e', 'd', 'c'],
  l_index: ['4', 'r', 'f', 'v', '5', 't', 'g', 'b'],
  r_index: ['6', 'y', 'h', 'n', '7', 'u', 'j', 'm'],
  r_middle:['8', 'i', 'k', ','],
  r_ring:  ['9', 'o', 'l', '.'],
  r_pinky: ['0', 'p', ';', '/', '-', '=', '[', ']', "'"],
  thumb:   ['space'],
};
const COLORS = {
  l_pinky: '#e8613c', l_ring: '#e8a33c', l_middle: '#4fb06d',
  l_index: '#3c9ae8', r_index: '#6b6be8', r_middle: '#b05fd8',
  r_ring: '#d85f9e', r_pinky: '#e8613c', thumb: '#8a8f98',
};
const FINGER_OF = {};
for (const [finger, keys] of Object.entries(FINGER)) {
  for (const k of keys) FINGER_OF[k] = finger;
}
const LABEL = { '`': '`', '-': '-', '=': '=', '[': '[', ']': ']', '\\': '\\',
                ';': ';', "'": "'", ',': ',', '.': '.', '/': '/' };

const ROWS = [
  ['`', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '-', '='],
  ['q', 'w', 'e', 'r', 't', 'y', 'u', 'i', 'o', 'p', '[', ']', '\\'],
  ['a', 's', 'd', 'f', 'g', 'h', 'j', 'k', 'l', ';', "'"],
  ['LShift', 'z', 'x', 'c', 'v', 'b', 'n', 'm', ',', '.', '/', 'RShift'],
  ['space'],
];

export class KeyboardView {
  constructor() {
    this.root = null;
    this.keyEls = {}; // base char / 'space' / 'LShift' / 'RShift' -> element
  }

  render(container) {
    const kb = document.createElement('div');
    kb.className = 'keyboard';
    for (const row of ROWS) {
      const rowEl = document.createElement('div');
      rowEl.className = 'kb-row';
      for (const key of row) {
        const el = document.createElement('div');
        el.className = 'kb-key';
        if (key === 'space') { el.classList.add('kb-space'); el.textContent = 'space'; }
        else if (key === 'LShift' || key === 'RShift') {
          el.classList.add('kb-shift'); el.textContent = 'shift';
          el.style.setProperty('--finger', COLORS.l_pinky);
        } else {
          el.textContent = key >= 'a' && key <= 'z' ? key.toUpperCase() : (LABEL[key] ?? key);
        }
        const finger = key === 'space' ? 'thumb' : FINGER_OF[key];
        if (finger) el.style.setProperty('--finger', COLORS[finger]);
        this.keyEls[key] = el;
        rowEl.appendChild(el);
      }
      kb.appendChild(rowEl);
    }
    container.appendChild(kb);
    this.root = kb;
  }

  clearHighlight() {
    for (const el of Object.values(this.keyEls)) {
      el.classList.remove('next', 'wrong');
    }
  }

  highlightNext(expected) {
    this.clearHighlight();
    if (expected == null) return;
    const base = physicalKey(expected); // 'space' | 'a' | '1' | ';' ...
    const el = this.keyEls[base];
    if (el) el.classList.add('next');
    const needsShift = (expected >= 'A' && expected <= 'Z') || (expected in SHIFT_TO_BASE);
    if (needsShift) {
      const finger = base === 'space' ? 'thumb' : FINGER_OF[base];
      const leftHand = finger && finger.startsWith('l_');
      const shiftKey = leftHand ? 'RShift' : 'LShift'; // press shift with the opposite hand
      this.keyEls[shiftKey]?.classList.add('next');
    }
  }

  flashWrong(expected) {
    const base = expected == null ? null : physicalKey(expected);
    const el = base && this.keyEls[base];
    if (!el) return;
    el.classList.add('wrong');
    setTimeout(() => el.classList.remove('wrong'), 220);
  }

  setVisible(v) {
    if (this.root) this.root.style.display = v ? '' : 'none';
  }
}
