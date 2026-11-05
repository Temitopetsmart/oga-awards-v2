import { notice } from "../sections/page-sections.js";

export const title = "Page not found";

export default async function notFound() {
  return notice({
    icon: "fa-compass",
    title: "Page not found",
    text: "The page you’re looking for doesn’t exist or has moved.",
    actions: [
      { label: "Back home", href: "./" },
      { label: "FAQ", href: "faq", variant: "ghost" },
    ],
  });
}
