import { html } from "../../../shared/js/core/dom.js";
import { go } from "../../../shared/js/core/nav.js";
import { rules } from "../../../shared/js/core/validate.js";
import {
  field,
  checkField,
} from "../../../shared/js/components/form-fields.js";
import { bindForm } from "../../../shared/js/ui/form.js";
import { notify } from "../../../shared/js/ui/notify.js";
import { getCategories } from "../../../shared/js/services/catalog.service.js";
import {
  registerNominee,
  currentNominee,
} from "../../../shared/js/services/auth.service.js";
import { pageHero, pageBody, stateGate } from "../sections/page-sections.js";

export const title = "Register as Nominee";
const LEVELS = ["ND I", "ND II", "HND I", "HND II"].map((l) => ({
  value: l,
  label: l,
}));

export default async function register(ctx) {
  if (currentNominee()) {
    go("dashboard.html");
    return null;
  }
  const hero = pageHero({
    eyebrow: "Nominations",
    title: "Register as a nominee",
    lead: "Create your account, choose a category and tell us about yourself.",
    crumbs: [{ label: "Register" }],
  });
  const closed = stateGate(ctx, ["nominations"], {
    default: {
      icon: "fa-user-lock",
      title: "Nominations aren’t open",
      text: "Registration is only available during the nomination window.",
      actions: [
        { label: "Nominee login", href: "login.html" },
        { label: "Back home", href: "index.html", variant: "ghost" },
      ],
    },
  });
  if (closed) return html`${hero}${closed}`;

  const cats = await getCategories(ctx.occasion.id);
  return html`${hero}${pageBody(
    html` <form id="registerForm" class="oga-formcard" novalidate>
      <div class="row">
        ${field({
          label: "Full name",
          name: "name",
          required: true,
          autocomplete: "name",
          col: "col-md-6",
        })}
        ${field({
          label: "Email",
          name: "email",
          type: "email",
          required: true,
          autocomplete: "email",
          col: "col-md-6",
        })}
        ${field({
          label: "Phone",
          name: "phone",
          type: "tel",
          required: true,
          autocomplete: "tel",
          col: "col-md-6",
        })}
        ${field({
          label: "Department",
          name: "department",
          required: true,
          col: "col-md-6",
        })}
        ${field({
          label: "Level",
          name: "level",
          options: LEVELS,
          required: true,
          col: "col-md-6",
        })}
        ${field({
          label: "Category",
          name: "categoryId",
          options: cats.map((c) => ({ value: c.id, label: c.name })),
          required: true,
          col: "col-md-6",
        })}
        ${field({
          label: "Password",
          name: "password",
          type: "password",
          required: true,
          autocomplete: "new-password",
          col: "col-md-6",
          help: "At least 8 characters.",
        })}
        ${field({
          label: "Confirm password",
          name: "confirm",
          type: "password",
          required: true,
          autocomplete: "new-password",
          col: "col-md-6",
        })}
      </div>
      ${checkField({
        name: "terms",
        label: "I agree to the Terms of Use and Privacy Policy.",
      })}
      <button class="btn btn-gold btn-lg w-100" type="submit">
        Create my account
      </button>
      <p class="text-center mt-3 mb-0">
        Already registered? <a href="login.html">Log in</a>
      </p>
    </form>`,
    { width: "44rem" },
  )}`;
}

export function init(root, ctx) {
  const form = root.querySelector("#registerForm");
  if (!form) return;
  bindForm(form, {
    schema: {
      name: [rules.required(), rules.min(2)],
      email: [rules.required(), rules.email()],
      phone: [rules.required(), rules.phone()],
      department: [rules.required()],
      level: [rules.required("Choose your level.")],
      categoryId: [rules.required("Choose a category.")],
      password: [rules.required(), rules.min(8)],
      confirm: [
        rules.required(),
        rules.match("password", "Passwords do not match."),
      ],
      terms: [rules.required("You must accept to continue.")],
    },
    async onSubmit(values) {
      await registerNominee({ ...values, occasionId: ctx.occasion.id });
      await notify.success(
        "Welcome aboard!",
        "Your account is created and pending review.",
      );
      go("dashboard.html");
    },
  });
}
