/** Load a classic <script> once (used for heavy libraries only needed on one page). */
const loaded = new Map();
export function loadScript(src, isReady = () => false) {
  if (isReady()) return Promise.resolve();
  if (loaded.has(src)) return loaded.get(src);
  const p = new Promise((resolve, reject) => {
    const s = document.createElement("script");
    s.src = src;
    s.onload = resolve;
    s.onerror = () => {
      loaded.delete(src);
      reject(new Error(`Failed to load ${src}`));
    };
    document.head.append(s);
  });
  loaded.set(src, p);
  return p;
}
