import { APP } from "../config/app.config.js";

export const money = (n) =>
  new Intl.NumberFormat(APP.locale, {
    style: "currency",
    currency: APP.currency.code,
    maximumFractionDigits: 0,
  }).format(n);

export const number = (n) => new Intl.NumberFormat(APP.locale).format(n);

export const formatDate = (iso, opts = { dateStyle: "long" }) =>
  new Intl.DateTimeFormat(APP.locale, {
    timeZone: APP.timezone,
    ...opts,
  }).format(new Date(iso));

/** Replace {key} placeholders: tpl('Pay {price}', { price: '₦100' }) */
export const tpl = (str = "", vars = {}) =>
  String(str).replace(/\{(\w+)\}/g, (_, k) => vars[k] ?? `{${k}}`);
