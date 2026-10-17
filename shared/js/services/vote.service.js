import { APP } from "../config/app.config.js";
import { store, delay, uid, userError } from "../core/store.js";

/** DEMO adapter (localStorage). Replace the bodies with fetch() calls to the PHP API; keep the signatures. */
const KEY = "vote-orders";

export const orderTotal = (votes) => votes * APP.voting.pricePerVote;

export function validateVotes(n) {
  const max = APP.voting.maxVotesPerPayment;
  if (!Number.isInteger(n) || n < 1) return "Enter at least 1 vote.";
  if (n > max) return `You can buy up to ${max} votes per payment.`;
  return null;
}

export async function createOrder({
  nomineeId,
  categoryId,
  votes,
  name,
  email,
}) {
  await delay(300);
  const problem = validateVotes(votes);
  if (problem) throw userError(problem, { votes: problem });
  const order = {
    reference: uid("VOT-", 8),
    nomineeId,
    categoryId,
    votes,
    name,
    email: email.toLowerCase(),
    amount: orderTotal(votes),
    status: "pending",
    createdAt: new Date().toISOString(),
  };
  store.set(KEY, [...store.get(KEY, []), order]);
  return order;
}

export async function confirmOrder(reference) {
  await delay(300);
  const orders = store.get(KEY, []);
  const order = orders.find((o) => o.reference === reference);
  if (!order) throw userError("We could not find that payment.");
  order.status = "paid";
  store.set(KEY, orders);
  return order;
}
