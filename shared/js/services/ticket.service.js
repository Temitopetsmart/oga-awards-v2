import { APP } from "../config/app.config.js";
import { store, userError } from "../core/store.js";
import { getResource } from "../core/http.js";

/**
 * Tickets, served by the PHP backend. The server sets the price, checks the payment with the provider and creates
 * the tickets (one per seat). Nothing here decides what a ticket costs or whether a payment is real.
 */
const PENDING = "ticket-pending"; // { reference, email } — remembered while the buyer is on the payment page

export async function getTicketTypes(occasionId) {
  const all = await getResource("ticket-types");
  return all.filter((t) => t.occasionId === occasionId && t.active !== false);
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

/** Starts an order. Returns { reference, amount, qty, email, paymentLink } — or { free: true } for ₦0 tickets. */
export async function createTicketOrder({ type, qty, name, email, phone }) {
  const order = await post("/tickets/orders", { typeId: type.id, qty, name, email, phone });
  store.set(PENDING, { reference: order.reference, email: order.email });
  return order;
}

/** The email we remembered for an order, so the buyer lands on their tickets after paying. */
export const pendingOrder = (reference) => {
  const p = store.get(PENDING);
  return p && p.reference === reference ? p : null;
};

/** Asks the server to check the payment. Resolves only when the tickets really exist. */
export async function issueTickets(reference) {
  const r = await post(`/tickets/orders/${encodeURIComponent(reference)}/confirm`, {});
  if (r.status !== "paid")
    throw userError(
      r.status === "failed"
        ? (r.note ?? "The payment did not go through. You have not been charged.")
        : "We haven’t received confirmation of your payment yet. If you were charged, your tickets will be ready within a few minutes — keep your reference: " +
            reference,
    );
  return { order: r.order, tickets: Array.from({ length: r.order.tickets }) };
}

/** By order reference OR ticket code, plus the buyer's email. */
export async function findTickets({ ref, email }) {
  const r = await post("/tickets/lookup", { ref: ref.trim(), email: email.trim() });
  return r.tickets;
}