import { APP, STATES } from "../config/app.config.js";
import { getResource } from "../core/http.js";

/** Phases, in order. The last matching phase wins. */
const PHASES = [
  { state: "nominations", start: "nominationsOpen", end: "nominationsClose" },
  { state: "voting", start: "votingOpen", end: "votingClose" },
  { state: "voting-closed", start: "votingClose", end: "resultsPublish" },
  { state: "results", start: "resultsPublish", end: null },
];

const MILESTONES = [
  ["nominationsOpen", "Nominations open"],
  ["nominationsClose", "Nominations close"],
  ["votingOpen", "Voting opens"],
  ["votingClose", "Voting closes"],
  ["resultsPublish", "Winners announced"],
  ["ceremony", "Awards night"],
];

/** What the countdown should count towards in each state. */
const STATE_TARGET = {
  nominations: "nominationsClose",
  voting: "votingClose",
  "voting-closed": "resultsPublish",
};

const ms = (iso) => (iso ? new Date(iso).getTime() : null);

function nextMilestone(occ, now) {
  const t = occ.timeline ?? {};
  return (
    MILESTONES.map(([key, label]) => ({ key, label, at: t[key] }))
      .filter((m) => m.at && ms(m.at) > now)
      .sort((a, b) => ms(a.at) - ms(b.at))[0] ?? null
  );
}

/** Work out which home state an occasion is in (auto from dates, manual, or preview). */
export function resolveState(
  occ,
  { now = Date.now(), preview = null, allowPreview = false } = {},
) {
  if (allowPreview && preview && STATES[preview])
    return { state: preview, source: "preview" };
  if (occ.mode === "manual" && STATES[occ.forceState])
    return { state: occ.forceState, source: "manual" };
  const t = occ.timeline ?? {};
  let state = "landing";
  for (const p of PHASES) {
    const start = ms(t[p.start]);
    const end = p.end ? ms(t[p.end]) : null;
    if (start !== null && now >= start && (end === null || now < end))
      state = p.state;
  }
  return { state, source: "auto" };
}

function countdownFor(occ, state, now) {
  const key = STATE_TARGET[state];
  const at = key ? occ.timeline?.[key] : null;
  if (at && ms(at) > now)
    return { at, label: MILESTONES.find((m) => m[0] === key)[1] };
  return nextMilestone(occ, now);
}

export const hasFeature = (occ, key) => Boolean(occ.features?.[key]);

/** Everything a page needs to know about the current event + state. */
export async function getHomeContext() {
  const [data, content] = await Promise.all([
    getResource("occasions"),
    getResource("content"),
  ]);
  const occasion =
    data.occasions.find((o) => o.id === data.main) ?? data.occasions[0];
  const preview = new URLSearchParams(location.search).get("preview");
  const now = Date.now();
  const { state, source } = resolveState(occasion, {
    now,
    preview,
    allowPreview: APP.allowPreview,
  });
  return {
    occasion,
    state,
    source,
    stateInfo: STATES[state],
    countdown: countdownFor(occasion, state, now),
    copy: content.home[state] ?? {},
    specials: data.occasions.filter(
      (o) => o.type === "special" && o.enabled && o.link?.href,
    ),
  };
}
