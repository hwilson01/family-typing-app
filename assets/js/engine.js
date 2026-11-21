// Source-agnostic typing engine.
//
// Phase 1 feeds it curriculum drill text. Phase 2 (game layer, AI passages)
// feeds it ANY target string the same way and reads the same result object —
// scoring and the key_stats shape never change. That is the whole point.
//
//   const s = new TypingSession("type this", { onProgress, onComplete });
//   s.handleKey(keyboardEvent);   // call from a keydown listener
//
// Rules: a wrong key never advances the cursor (no way to "fail past" a key),
// it just counts against accuracy. No countdown, no harsh feedback.

const IDLE_CAP_MS = 3000; // inter-key gaps longer than this don't count as typing time

// Map a produced character to its physical key, so 'A' and 'a' and '!'/'1'
// aggregate onto one key for the (future) heatmap and the tricky-keys drill.
const SHIFT_TO_BASE = {
  '!': '1', '@': '2', '#': '3', '$': '4', '%': '5', '^': '6', '&': '7',
  '*': '8', '(': '9', ')': '0', '_': '-', '+': '=', '{': '[', '}': ']',
  '|': '\\', ':': ';', '"': "'", '<': ',', '>': '.', '?': '/', '~': '`',
};
export function physicalKey(ch) {
  if (ch === ' ') return 'space';
  if (ch >= 'A' && ch <= 'Z') return ch.toLowerCase();
  return SHIFT_TO_BASE[ch] || ch;
}

export class TypingSession {
  constructor(text, { onProgress = () => {}, onComplete = () => {} } = {}) {
    this.text = text;
    this.onProgress = onProgress;
    this.onComplete = onComplete;
    this.pos = 0;
    this.correct = 0;   // correct keystrokes
    this.wrong = 0;     // wrong keystrokes
    this.startTime = null;
    this.lastKeyTime = null;
    this.activeMs = 0;  // typing time with long idle gaps trimmed
    this.keystats = {}; // physicalKey -> {hits, misses, time_ms}
    this.done = false;
    this.lastWasWrong = false;
  }

  expectedChar() {
    return this.text[this.pos] ?? null;
  }

  _stat(key) {
    return (this.keystats[key] ||= { hits: 0, misses: 0, time_ms: 0 });
  }

  // Returns 'hit' | 'miss' | 'ignore' | 'done'
  handleKey(e) {
    if (this.done) return 'done';
    // Ignore pure modifiers and navigation; blocking mode has nothing to delete.
    if (['Shift', 'Control', 'Alt', 'Meta', 'CapsLock', 'Tab', 'Backspace',
         'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Enter', 'Escape',
         'Home', 'End', 'Dead'].includes(e.key)) {
      return 'ignore';
    }
    const expected = this.expectedChar();
    if (expected === null) return 'done';

    // Only single produced characters are typing input.
    const typed = e.key;
    if (typed.length !== 1) return 'ignore';

    const now = performance.now();
    if (this.startTime === null) { this.startTime = now; this.lastKeyTime = now; }
    const gap = Math.min(now - this.lastKeyTime, IDLE_CAP_MS);
    this.lastKeyTime = now;

    const pk = physicalKey(expected);
    const st = this._stat(pk);

    if (typed === expected) {
      this.activeMs += gap;
      this.correct++;
      st.hits++;
      st.time_ms += Math.round(gap);
      this.pos++;
      this.lastWasWrong = false;
      const result = this._progress('hit');
      if (this.pos >= this.text.length) return this._finish();
      return result;
    } else {
      // Wrong key: count against accuracy and against the key that was expected.
      this.wrong++;
      st.misses++;
      this.lastWasWrong = true;
      return this._progress('miss');
    }
  }

  accuracy() {
    const total = this.correct + this.wrong;
    return total ? this.correct / total : 1;
  }

  _progress(kind) {
    this.onProgress({
      kind,
      pos: this.pos,
      total: this.text.length,
      expected: this.expectedChar(),
      accuracy: this.accuracy(),
      lastWasWrong: this.lastWasWrong,
    });
    return kind;
  }

  _finish() {
    this.done = true;
    const minutes = Math.max(this.activeMs / 60000, 1 / 60000); // avoid /0
    const wpm = Math.min(250, Math.round((this.text.length / 5) / minutes)); // cap absurd values
    const acc = this.accuracy();
    const keystats = Object.entries(this.keystats).map(([key, v]) => ({
      key, hits: v.hits, misses: v.misses, time_ms: v.time_ms,
    }));
    const result = {
      accuracy: acc,
      wpm,
      duration_ms: Math.round(this.activeMs),
      correct: this.correct,
      wrong: this.wrong,
      keystats,
      stars: acc >= 0.99 ? 3 : acc >= 0.95 ? 2 : acc >= 0.90 ? 1 : 0,
    };
    this.onComplete(result);
    return 'done';
  }
}
