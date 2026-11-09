import { html } from "../../../shared/js/core/dom.js";
import { APP } from "../../../shared/js/config/app.config.js";
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
  confirmRegistration,
  currentNominee,
} from "../../../shared/js/services/auth.service.js";
import { pageHero, pageBody, stateGate } from "../sections/page-sections.js";
import {
  matricField,
  matricRule,
  bindMatricVisibility,
  categoryLabel,
} from "../../../shared/js/components/matric-field.js";

export const title = "Register as Nominee";
const LEVELS = APP.levels.map((l) => ({
  value: l,
  label: l,
}));

export default async function register(ctx) {
  if (currentNominee()) {
    go("dashboard");
    return null;
  }

  if (new URLSearchParams(location.search).get("pay") === "return") {
    // Back from the payment page: show a waiting screen; init() confirms the payment with the server.
    return html`${pageHero({
      eyebrow: "Nominations",
      title: "Confirming your payment",
      lead: "One moment please…",
      crumbs: [{ label: "Register" }],
    })}${pageBody(
      html`<div id="regReturn" class="text-center py-5">
        <div class="spinner-border text-warning" role="status"></div>
        <p class="mt-3 mb-0">Confirming your payment…</p>
      </div>`,
      { width: "44rem" },
    )}`;
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
        { label: "Nominee login", href: "login" },
        { label: "Back home", href: "./", variant: "ghost" },
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
        ${matricField()}
        ${field({
          label: "Category",
          name: "categoryId",
          options: cats.map((c) => ({ value: c.id, label: categoryLabel(c) })),
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
        Already registered? <a href="login">Log in</a>
      </p>
    </form>`,
    { width: "44rem" },
  )}`;
}

async function finishRegistration(el) {
  const back = new URLSearchParams(location.search);
  const ref = back.get("tx_ref");
  history.replaceState(null, "", location.pathname); // a refresh must not repeat this
  if (back.get("status") === "cancelled" || !ref) {
    await notify.warning(
      "Payment cancelled",
      "You have not been charged. You can try again.",
    );
    go("register");
    return;
  }
  try {
    const r = await confirmRegistration(ref);
    if (r.signedIn) {
      await notify.success(
        "Welcome aboard!",
        "Your payment is confirmed and your account is created.",
      );
      go("dashboard");
    } else {
      await notify.success(
        "Payment confirmed",
        r.note ?? "Your account is ready. Log in to continue.",
      );
      go("login");
    }
  } catch (err) {
    await notify.warning("We couldn’t confirm your payment yet", err.message);
    el.innerHTML =
      '<p class="mb-0">Your registration will be completed automatically once the payment is confirmed. You can <a href="login">log in</a> in a few minutes.</p>';
  }
}

export function init(root, ctx) {
  const ret = root.querySelector("#regReturn");
  if (ret) return finishRegistration(ret);
  const form = root.querySelector("#registerForm");
  if (!form) return;
  bindMatricVisibility(form);

  bindForm(form, {
    schema: {
      name: [rules.required(), rules.min(2)],
      email: [rules.required(), rules.email()],
      phone: [rules.required(), rules.phone()],
      department: [rules.required()],
      level: [rules.required("Choose your level.")],
      matricNo: [matricRule],
      categoryId: [rules.required("Choose a category.")],
      password: [rules.required(), rules.min(8)],
      confirm: [
        rules.required(),
        rules.match("password", "Passwords do not match."),
      ],
      terms: [rules.required("You must accept to continue.")],
    },
    async onSubmit(values) {
      const r = await registerNominee({
        ...values,
        occasionId: ctx.occasion.id,
      });
      if (r.paymentRequired) {
        location.assign(r.paymentLink); // this edition charges a fee: go and pay
        await new Promise(() => {}); // the browser is leaving
      }
      await notify.success(
        "Welcome aboard!",
        "Your account is created and pending review.",
      );
      go("dashboard");
    },
  });
}
