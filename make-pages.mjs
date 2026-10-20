// Run from the project root:  node make-pages.mjs
// Creates every inner page (about.html, vote.html, ...) from one template.
import { writeFileSync } from "node:fs";

const template = ({ title, desc, page, doc }) => `<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>${title} — OGA Awards</title>
  <meta name="description" content="${desc}">
  <meta name="theme-color" content="#0a0a0a">
  <link rel="icon" href="assets/images/logo/favicon.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.7.2/css/all.min.css">
  <link rel="stylesheet" href="shared/css/tokens.css">
  <link rel="stylesheet" href="shared/css/base.css">
  <link rel="stylesheet" href="shared/css/components.css">
  <link rel="stylesheet" href="site/css/site.css">
  <link rel="stylesheet" href="site/css/pages.css">
</head>
<body data-page="${page}"${doc ? ` data-doc="${doc}"` : ""}>
  <oga-header></oga-header>
  <main id="app" tabindex="-1"></main>
  <oga-footer></oga-footer>
  <noscript><p style="padding:2rem;text-align:center">Please enable JavaScript to view this site.</p></noscript>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5/dist/sweetalert2.all.min.js"></script>
  <script type="module" src="site/js/pages/main.js"></script>
</body>
</html>
`;

// [file, page module, title, description, optional data-doc]
const pages = [
  ["about", "about", "About", "Learn about OGA, the OGITECH Student Awards."],
  ["categories", "categories", "Categories", "Award categories and nominees."],
  ["nominee", "nominee", "Nominee", "Nominee profile."],
  ["faq", "faq", "FAQ", "Answers to common questions."],
  ["contact", "contact", "Contact", "Get in touch with the OGA team."],
  ["occasion", "occasion", "Occasion", "A special occasion from OGA."],
  ["vote", "vote", "Vote", "Vote for your favourite nominee."],
  ["results", "results", "Winners", "The winners of this edition."],
  ["tickets", "tickets", "Tickets", "Buy tickets for awards night."],
  ["ticket", "ticket", "Your Ticket", "View and print your ticket."],
  [
    "editions",
    "editions",
    "Hall of Fame",
    "Winners and committees from every past edition.",
  ],
  ["edition", "edition", "Edition", "A past edition of the awards."],
  ["gallery", "gallery", "Gallery", "Photos from past editions."],
  ["committee", "committee", "Committee", "Meet and join the OGA committee."],
  [
    "register",
    "register",
    "Register as Nominee",
    "Create your nominee account.",
  ],
  ["login", "login", "Nominee Login", "Log in to your nominee dashboard."],
  ["dashboard", "dashboard", "My Dashboard", "Your nominee dashboard."],
  [
    "privacy",
    "legal",
    "Privacy Policy",
    "How OGA handles your information.",
    "privacy",
  ],
  ["terms", "legal", "Terms of Use", "Terms for using the OGA site.", "terms"],
  ["maintenance", "maintenance", "Back soon", "The site is under maintenance."],
  ["404", "notfound", "Page not found", "Page not found."],
];

for (const [file, page, title, desc, doc] of pages) {
  writeFileSync(`${file}.html`, template({ title, desc, page, doc }));
}
console.log(`Created ${pages.length} pages.`);
