import { APP, ROOT } from "../../shared/js/config/app.config.js";
import { store } from "../../shared/js/core/store.js";
import {
  getCategories,
  getWinners,
} from "../../shared/js/services/catalog.service.js";
import {
  currentNominee,
  findNomineeById,
  listMyEntries,
} from "../../shared/js/services/auth.service.js";

export const STATUS = {
  pending: {
    label: "Pending review",
    tone: "wait",
    text: "Our team is reviewing your entry. You’ll appear publicly once approved.",
  },
  approved: {
    label: "Approved",
    tone: "ok",
    text: "You’re approved! Share your profile and ask supporters to vote when voting opens.",
  },
  rejected: {
    label: "Needs changes",
    tone: "bad",
    text: "Your entry wasn’t approved yet. Read the reviewer’s note and send it again.",
  },
};

export const STEPS = [
  ["nominations", "Nominations"],
  ["voting", "Voting"],
  ["voting-closed", "Counting"],
  ["results", "Winners"],
];

/** Everything the portal pages share, worked out once per page load. Returns { blocked } when there is nobody to show. */
export async function loadPortalData(ctx, params) {
  const viewAsId = params.get("viewAs");
  if (viewAsId && !store.get("admin:me")) return { blocked: "admin" };
  const me = viewAsId ? await findNomineeById(viewAsId) : currentNominee();
  if (!me) return { blocked: viewAsId ? "missing" : "signin" };

  const readOnly = Boolean(viewAsId);
  const categories = await getCategories(ctx.occasion.id);
  const cat = (await getCategories(me.occasionId ?? ctx.occasion.id)).find(
    (c) => c.id === me.categoryId,
  );
  const entries = readOnly ? [] : listMyEntries();
  const mineHere = entries.filter((e) => e.occasionId === ctx.occasion.id);
  const takenIds = mineHere.map((e) => String(e.categoryId));
  const maxCategories = APP.voting.maxCategoriesPerNominee;
  const stillOpen = categories.filter(
    (c) =>
      !takenIds.includes(String(c.id)) &&
      (!c.eligibleLevels?.length ||
        c.eligibleLevels.includes(me.accountLevel ?? me.level)),
  );
  const canApply =
    !readOnly &&
    ctx.state === "nominations" &&
    mineHere.length < maxCategories &&
    stillOpen.length > 0;
  const fee = Number(APP.voting.nomineeRegistrationFee) || 0; // per category
  const catName = (entry) =>
    categories.find((c) => String(c.id) === String(entry.categoryId))?.name;

  // Winners are only ever known here once the organisers have published them.
  const winners =
    ctx.state === "results" && me.occasionId === ctx.occasion.id
      ? await getWinners(ctx.occasion.id).catch(() => [])
      : [];
  const isWinner = winners.some((w) => String(w.nomineeId) === String(me.id));

  const checklist = [
    ["A profile photo", Boolean(me.photo)],
    ["A short bio", Boolean((me.bio ?? "").trim())],
    ["A phone number", Boolean((me.phone ?? "").trim())],
    ["A verified email", Boolean(me.emailVerified)],
  ];
  const percent = Math.round(
    (checklist.filter(([, ok]) => ok).length / checklist.length) * 100,
  );
  const at = Math.max(
    0,
    STEPS.findIndex(([k]) => k === ctx.state),
  );
  const needChanges =
    mineHere.filter((e) => e.status === "rejected").length +
    (readOnly && me.status === "rejected" ? 1 : 0);

  return {
    ctx,
    me,
    readOnly,
    viewAsId,
    categories,
    cat,
    entries,
    mineHere,
    takenIds,
    maxCategories,
    canApply,
    fee,
    catName,
    isWinner,
    checklist,
    percent,
    at,
    status: STATUS[me.status] ?? STATUS.pending,
    profileUrl: `${ROOT}nominee?id=${encodeURIComponent(me.id)}`,
    // little numbers on the menu: what needs the nominee's attention
    attention: {
      entries: needChanges,
      profile: percent < 100 ? 1 : 0,
      account: me.emailVerified ? 0 : 1,
    },
  };
}
