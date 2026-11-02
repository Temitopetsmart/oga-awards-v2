import { APP } from "../config/app.config.js";
import { userError } from "../core/store.js";

/**
 * Voting, served by the PHP backend. The server sets the price, checks the limits and talks to the
 * payment provider; nothing here decides what a vote costs or whether a payment is real.
 */
export const orderTotal = (votes) => votes * APP.voting.pricePerVote;

/** Quick check for the form. The server checks again. */
export function validateVotes(n) {
  const max = APP.voting.maxVotesPerPayment;
  if (!Number.isInteger(n) || n < 1) return "Enter at least 1 vote.";
  if (max && n > max) return `You can buy up to ${max} votes per payment.`;
  return null;
}

async function post(path, body) {
  const res = await fetch(`${APP.data.apiBase}${path}`, {
    method: "POST",
    headers: { "Content-Type": "application/json", Accept: "application/json" },
    body: JSON.stringify(body),
  });
  let data = null;
  try {
    data = await res.json();
  } catch {
    /* not JSON */
  }
  if (!res.ok || data?.ok === false)
    throw userError(
      data?.error ?? "Something went wrong. Please try again.",
      data?.fields,
    );
  return data;
}

/** Starts an order. Returns { reference, votes, amount, nomineeId, categoryId, paymentLink }. */
export async function createOrder({
  nomineeId,
  categoryId,
  votes,
  name,
  email,
}) {
  const problem = validateVotes(votes);
  if (problem) throw userError(problem, { votes: problem });
  return post("/votes/orders", { nomineeId, categoryId, votes, name, email });
}

/** Asks the server to check the payment with the provider. Resolves only when the votes are really counted. */
export async function confirmOrder(reference) {
  const r = await post(
    `/votes/orders/${encodeURIComponent(reference)}/confirm`,
    {},
  );
  if (r.status !== "paid")
    throw userError(
      r.status === "failed"
        ? (r.note ??
            "The payment did not go through. You have not been charged.")
        : "We haven’t received confirmation of your payment yet. If you were charged, your votes will be added automatically within a few minutes — keep your reference: " +
            reference,
    );
  return r.order;
}
