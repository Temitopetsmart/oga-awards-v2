/** CSV export. Cells starting with = + - @ are prefixed with ' so spreadsheets don't run them as formulas. */
const cell = (v) => {
  let s = String(v ?? "");
  if (/^[=+\-@\t\r]/.test(s)) s = `'${s}`;
  return /[",\n\r]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
};

export function toCsv(rows, columns) {
  const head = columns.map((c) => cell(c.label)).join(",");
  const body = rows.map((r) =>
    columns
      .map((c) => cell(typeof c.value === "function" ? c.value(r) : r[c.key]))
      .join(","),
  );
  return [head, ...body].join("\r\n");
}

export function downloadCsv(filename, rows, columns) {
  const blob = new Blob([`\ufeff${toCsv(rows, columns)}`], {
    type: "text/csv;charset=utf-8",
  });
  const url = URL.createObjectURL(blob);
  const a = Object.assign(document.createElement("a"), {
    href: url,
    download: filename,
  });
  document.body.append(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
