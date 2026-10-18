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

  /** Where data comes from. Switch to 'api' when the PHP backend is ready. */
  data: { source: "json", jsonPath: "data/", apiBase: "/api" },

  /** Lets ?preview=voting force a home-page state. Set to false in production. */
  allowPreview: true,

  /** Business rules (the backend must enforce these too). */
  voting: {
    pricePerVote: 100,
    maxVotesPerPayment: 50,
    maxPaidVotesPerEmailPerCategory: 5,
  },

  contact: { email: "info@example.com", phone: "" }, // TODO: real contact details

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
    { label: "Home", href: "index.html" },
    { label: "About", href: "about.html" },
    {
      label: "Categories",
      href: "categories.html",
      states: ["nominations", "voting", "voting-closed", "results"],
    },
    { label: "Vote", href: "vote.html", states: ["voting"] },
    { label: "Winners", href: "results.html", states: ["results"] },
    { label: "Tickets", href: "tickets.html", feature: "tickets" },
    { label: "Gallery", href: "gallery.html" },
    { label: "Committee", href: "committee.html" },
    { label: "FAQ", href: "faq.html" },
  ],
};

/** Home-page states. Add a state here + a view in site/js/views/home.views.js. */
export const STATES = {
  landing: {
    label: "Coming Soon",
    cta: {
      label: "Join the Committee",
      href: "committee.html",
      feature: "committeeRecruitment",
    },
  },
  nominations: {
    label: "Nominations Open",
    cta: { label: "Register as Nominee", href: "register.html" },
  },
  voting: {
    label: "Voting Open",
    cta: { label: "Vote Now", href: "vote.html" },
  },
  "voting-closed": {
    label: "Voting Closed",
    cta: { label: "How It Works", href: "about.html" },
  },
  results: {
    label: "Winners Announced",
    cta: { label: "See the Winners", href: "results.html" },
  },
};

/**
 * DEMO BRIDGE: values saved from the admin Settings page (kept in this browser) override the
 * defaults above. APP is a const, but its properties are writable. Delete with the backend.
 */
try {
  const s = JSON.parse(localStorage.getItem("oga:admin:settings") ?? "null");
  if (s?.voting) Object.assign(APP.voting, s.voting);
  if (s?.contact) Object.assign(APP.contact, s.contact);
  if (Array.isArray(s?.social)) APP.social = s.social;
} catch {
  /* ignore */
}
