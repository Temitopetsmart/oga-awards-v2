import { notice } from "../sections/page-sections.js";
import { APP } from "../../../shared/js/config/app.config.js";

export const title = "Back soon";

export default async function maintenance() {
  return notice({
    icon: "fa-screwdriver-wrench",
    title: "We’ll be right back",
    text:
      APP.maintenance?.message ||
      "The site is undergoing scheduled maintenance. Please check back shortly.",
  });
}
