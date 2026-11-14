/**
 * OGA Awards — central configuration.
 * Anything that might change (names, prices, limits, nav, states) lives here
 * or in /data/*.json — never inside page code.
 */

/** Absolute URL of the project root, so paths work from any page depth or sub-folder. */
export const ROOT = new URL("../../../", import.meta.url).href;

export const APP = {
  brand: {
    name: "OGA Awards",
    org: "OGITECH Student Awards",
    logo: "assets/images/logo/logo.jpeg", // optional: header hides it if missing
  },
  locale: "en-NG",
  timezone: "Africa/Lagos",
  currency: { code: "NGN" },

  /** Every page reads its data from the PHP API. */
  data: {
    apiBase: new URL("api", ROOT).href,
  },

  /** Lets ?preview=voting force a home-page state. Set to false in production. */
  allowPreview: false,

  /** Business rules (the backend must enforce these too). */
  voting: {
    pricePerVote: 100,
    maxVotesPerPayment: 50,
    maxPaidVotesPerEmailPerCategory: 5,
    nomineeRegistrationFee: 0,
    maxCategoriesPerNominee: 3,
  },

  contact: { email: "", phone: "" }, // the real details come from Admin, Settings, Contact

  /** Site mode. Filled from Admin, Settings, Site mode. */
  maintenance: { enabled: false, message: "" },

  /** Filled from Admin → Settings when the page loads (see the fetch at the bottom of this file). */
  levels: [],
  levelRules: [], // [{ name, requiresMatric }] for the active levels
  departments: [],
  matric: null, // { maxLength, formats: [{ label, pattern, example }] }

  /** Leave href empty to hide an icon. */
  social: [
    { label: "Instagram", icon: "fa-brands fa-instagram", href: "" },
    { label: "X (Twitter)", icon: "fa-brands fa-x-twitter", href: "" },
    { label: "Facebook", icon: "fa-brands fa-facebook-f", href: "" },
    { label: "TikTok", icon: "fa-brands fa-tiktok", href: "" },
  ],

  /**
   * Navigation. `states`: only show in these home states. `feature`: only show when
   * the main occasion has this feature flag on. Omit both to always show.
   */
  nav: [
    { label: "Home", href: "./" },
    { label: "About", href: "about" },
    {
      label: "Categories",
      href: "categories",
      states: ["nominations", "voting", "voting-closed", "results"],
    },
    { label: "Vote", href: "vote", states: ["voting"] },
    { label: "Winners", href: "results", states: ["results"] },
    { label: "Tickets", href: "tickets", feature: "tickets" },
    { label: "Hall of Fame", href: "editions" },
    { label: "Gallery", href: "gallery" },
    { label: "Committee", href: "committee" },
    { label: "FAQ", href: "faq" },
    { label: "Contact", href: "contact" },
  ],
};

/** Home-page states. Add a state here + a view in site/js/views/home.views.js. */
export const STATES = {
  landing: {
    label: "Coming Soon",
    cta: {
      label: "Join the Committee",
      href: "committee",
      feature: "committeeRecruitment",
    },
  },
  nominations: {
    label: "Nominations Open",
    cta: { label: "Register as Nominee", href: "register" },
  },
  voting: {
    label: "Voting Open",
    cta: { label: "Vote Now", href: "vote" },
  },
  "voting-closed": {
    label: "Voting Closed",
    cta: { label: "How It Works", href: "about" },
  },
  results: {
    label: "Winners Announced",
    cta: { label: "See the Winners", href: "results" },
  },
};

/**
 * With the PHP backend, contact details and social links come from the server (set in Admin → Settings).
 * Top-level await: the page waits for this one small, cached request, so the footer is right on first paint.
 */
try {
  const ctl = new AbortController();
  const timer = setTimeout(() => ctl.abort(), 4000);
  const res = await fetch(`${APP.data.apiBase}/settings`, {
    signal: ctl.signal,
  });
  clearTimeout(timer);
  const site = res.ok ? await res.json() : null;
  if (site?.contact) Object.assign(APP.contact, site.contact);
  if (Array.isArray(site?.social) && site.social.length)
    APP.social = site.social;
  if (site?.brand?.name) APP.brand.name = site.brand.name;
  if (site?.brand?.org) APP.brand.org = site.brand.org;
  if (site?.maintenance) APP.maintenance = site.maintenance;
  if (site?.brand?.tagline) APP.brand.tagline = site.brand.tagline;
  if (Array.isArray(site?.levels)) APP.levels = site.levels;
  if (Array.isArray(site?.levelRules)) APP.levelRules = site.levelRules;
  if (Array.isArray(site?.departments)) APP.departments = site.departments;
  if (site?.matric?.formats?.length) APP.matric = site.matric;
} catch {
  /* keep the defaults if the server can't be reached */
}
