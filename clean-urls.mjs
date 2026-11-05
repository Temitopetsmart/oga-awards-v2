// Run ONCE from the project root:  node clean-urls.mjs
// Rewrites every ".html" link in the JavaScript and data files to its clean address
// (vote.html -> vote, admin/login.html -> admin/login, index.html -> ./ , admin/index.html -> admin/).
// Safe to run twice (a second run changes nothing). Review the result with: git diff
import { readdirSync, readFileSync, statSync, writeFileSync } from "node:fs";
import { join } from "node:path";

const roots = ["site", "shared", "admin", "data"];
const files = [];
const walk = (dir) => {
  for (const name of readdirSync(dir)) {
    if (name === "node_modules" || name.startsWith(".")) continue;
    const full = join(dir, name);
    if (statSync(full).isDirectory()) walk(full);
    else if (/\.(js|json)$/.test(name)) files.push(full);
  }
};
roots.forEach(walk);

const rules = [
  [/admin\/index\.html/g, "admin/"], // admin/index.html  -> admin/
  [/\$\{ROOT\}index\.html/g, "${ROOT}"], // ${ROOT}index.html -> ${ROOT}
  [/(["'`])index\.html\1/g, "$1./$1"], // "index.html"      -> "./"
  [/\b([A-Za-z0-9_-]+)\.html(?=["'`?#])/g, "$1"], // vote.html / vote.html?x / vote.html#x
  [/\b(page like |like |or )([a-z0-9_-]+)\.html\b/g, "$1$2"], // help text such as "a page like contact.html or ..."
];

let changed = 0;
for (const file of files) {
  const before = readFileSync(file, "utf8");
  let after = before;
  for (const [pattern, replacement] of rules)
    after = after.replace(pattern, replacement);
  if (after !== before) {
    writeFileSync(file, after);
    changed++;
    console.log("updated", file);
  }
}
console.log(`\n${changed} file(s) updated.`);

// Anything still mentioning ".html" needs a human look.
const left = [];
for (const file of files) {
  readFileSync(file, "utf8")
    .split("\n")
    .forEach((line, i) => {
      if (/\.html\b/.test(line))
        left.push(`${file}:${i + 1}: ${line.trim().slice(0, 110)}`);
    });
}
console.log(
  left.length
    ? "\nStill contain .html (check by hand):\n" + left.join("\n")
    : "\nNo .html left in these files.",
);
