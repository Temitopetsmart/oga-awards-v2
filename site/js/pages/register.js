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
import {
  registerNominee,
  confirmRegistration,
  currentNominee,
  getCategoryOptions,
} from "../../../shared/js/services/auth.service.js";
import { pageHero, pageBody, stateGate } from "../sections/page-sections.js";
import {
  matricField,
  matricRule,
  bindMatricVisibility,
  levelNeedsMatric,
} from "../../../shared/js/components/matric-field.js";
import { mountCategoryDropdown } from "../../../shared/js/components/category-dropdown.js";
import { normalizeMatric } from "../../../shared/js/core/matric.js";
import { money } from "../../../shared/js/core/format.js";

export const title = "Register as Nominee";
const LEVELS = APP.levels.map((l) => ({
  value: l,
  label: l,
}));

export default async function register(ctx) {
  if (currentNominee()) {
    go("portal/");
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
    lead: "Tell us your level, choose the category you want to enter, then create your account. You can enter more categories from your portal once you are in.",
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

  return html`${hero}${pageBody(
    html` <form id="registerForm" class="oga-formcard" novalidate>
      <h2 class="h5 mb-1">1. Your level</h2>
      <p class="small text-secondary">
        We use this to show you only the categories open to you.
      </p>
      <div class="row">
        ${field({
          label: "Level",
          name: "level",
          options: LEVELS,
          required: true,
          col: "col-md-6",
        })}
        ${matricField()}
      </div>
      <h2 class="h5 mb-1 mt-2">2. Your category</h2>
      <div id="categoryBlock" class="mb-4">
        <p id="categoryHint" class="small text-secondary mb-0">
          Choose your level above to see the categories open to you.
        </p>
        <div id="categoryReady" class="d-none">
          <label class="form-label" for="cdd-pick"
            >The one category you are registering with
            <span class="text-gold" aria-hidden="true">*</span></label
          >
          <div id="categoryHost"></div>
          <div
            class="invalid-feedback d-block"
            id="categoryError"
            role="alert"
          ></div>
          <div class="form-text" id="feeNote"></div>
        </div>
      </div>
      <h2 class="h5 mb-1">3. About you</h2>
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
      <button class="btn btn-gold btn-lg w-100" type="submit" id="registerBtn">
        <span id="registerBtnText">Create my account</span>
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
      go("portal/");
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

  // ---- step 2: the category dropdown appears once we know the level (and the matric number, where one is needed)
  const hint = form.querySelector("#categoryHint");
  const ready = form.querySelector("#categoryReady");
  const errorBox = form.querySelector("#categoryError");
  const feeNote = form.querySelector("#feeNote");
  const btnText = form.querySelector("#registerBtnText");
  const cache = new Map(); // level -> options from the server
  let options = null;
  let dd = null;
  let asked = "";

  const matricOk = () => {
    const level = form.elements.level.value;
    if (!levelNeedsMatric(level)) return true;
    return Boolean(
      normalizeMatric(
        String(form.elements.matricNo.value ?? "").trim(),
        APP.matric,
      ),
    );
  };
  const describe = (o) => {
    const fee = Number(o.fee) || 0;
    const more = Math.max(0, o.max - 1);
    const later =
      more > 0
        ? ` After you join you can enter up to ${more} more ${more === 1 ? "category" : "categories"} from your portal${fee > 0 ? `, each ${money(fee, o.currency)}` : ""}.`
        : "";
    return fee > 0
      ? `Registration fee: ${money(fee, o.currency)} for this category, paid securely online.${later}`
      : `Registration is free.${later}`;
  };
  const paint = () => {
    const level = form.elements.level.value;
    errorBox.textContent = "";
    if (!level) {
      hint.textContent =
        "Choose your level above to see the categories open to you.";
      hint.classList.remove("d-none");
      ready.classList.add("d-none");
      return;
    }
    if (!matricOk()) {
      hint.textContent =
        "Enter your matric number above to see the categories open to you.";
      hint.classList.remove("d-none");
      ready.classList.add("d-none");
      return;
    }
    if (!options || asked !== level) {
      hint.textContent = "Loading the categories open to you…";
      hint.classList.remove("d-none");
      ready.classList.add("d-none");
      return;
    }
    hint.classList.add("d-none");
    ready.classList.remove("d-none");
    feeNote.textContent = describe(options);
    btnText.textContent =
      Number(options.fee) > 0
        ? `Continue to payment (${money(options.fee, options.currency)})`
        : "Create my account";
    if (!dd) {
      dd = mountCategoryDropdown(form.querySelector("#categoryHost"), {
        categories: options.categories,
        multi: false,
        placeholder: "Choose your category",
        onChange: () => (errorBox.textContent = ""),
      });
    } else {
      dd.set(options.categories);
    }
  };
  const load = async () => {
    const level = form.elements.level.value;
    if (!level) return paint();
    if (cache.has(level)) {
      options = cache.get(level);
      asked = level;
      return paint();
    }
    options = null;
    paint();
    try {
      const o = await getCategoryOptions({
        occasionId: ctx.occasion.id,
        level,
      });
      cache.set(level, o);
      if (form.elements.level.value === level) {
        options = o;
        asked = level;
        paint();
      }
    } catch (err) {
      hint.textContent =
        err.message ?? "We could not load the categories. Please try again.";
    }
  };
  form.elements.level.addEventListener("change", () => {
    dd?.clear();
    load();
  });
  let timer = null;
  form.elements.matricNo.addEventListener("input", () => {
    clearTimeout(timer);
    timer = setTimeout(paint, 350); // only shows or hides the list; no request is made while typing
  });
  form.elements.matricNo.addEventListener("change", paint);
  load();

  bindForm(form, {
    schema: {
      name: [rules.required(), rules.min(2)],
      email: [rules.required(), rules.email()],
      phone: [rules.required(), rules.phone()],
      department: [rules.required()],
      level: [rules.required("Choose your level.")],
      matricNo: [matricRule],
      password: [rules.required(), rules.min(8)],
      confirm: [
        rules.required(),
        rules.match("password", "Passwords do not match."),
      ],
      terms: [rules.required("You must accept to continue.")],
    },
    async onSubmit(values) {
      const picked = dd?.values() ?? [];
      if (picked.length !== 1) {
        errorBox.textContent = "Choose the category you want to register with.";
        form
          .querySelector("#categoryBlock")
          .scrollIntoView({ block: "center" });
        throw new Error("Choose the category you want to register with.");
      }
      const r = await registerNominee({
        ...values,
        categoryIds: picked,
        occasionId: ctx.occasion.id,
      });

      if (r.paymentRequired) {
        location.assign(r.paymentLink);
        await new Promise(() => {});
      }
      await notify.success(
        "Welcome aboard!",
        "Your account is created and pending review.",
      );
      go("portal/");
    },
  });
}
