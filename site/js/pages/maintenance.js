import { notice } from "../sections/page-sections.js";

export const title = "Back soon";

export default async function maintenance() {
  return notice({
    icon: "fa-screwdriver-wrench",
    title: "We’ll be right back",
    text: "The site is undergoing scheduled maintenance. Please check back shortly.",
  });
}
