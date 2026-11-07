/**
 * Wires a search box so that it searches ONCE, when the person presses the Search button or Enter, and when they
 * clear the box with the little ×. It never searches while they are still typing.
 */
export function bindSearch(input, button, run) {
  if (!input) return;
  const go = () => run(input.value.trim());
  button?.addEventListener("click", go);
  input.addEventListener("keydown", (e) => {
    if (e.key === "Enter") {
      e.preventDefault();
      go();
    }
  });
  input.addEventListener("search", () => {
    if (!input.value) go();
  });
}
