import { getResource } from "../core/http.js";
import { store, delay, uid, userError } from "../core/store.js";

/** DEMO adapter (localStorage). Replace with PHP API calls later; keep the signatures. */
const ORDERS = "ticket-orders";
const TICKETS = "tickets";

export async function getTicketTypes(occasionId) {
  return (await getResource("ticket-types")).filter(
    (t) => t.occasionId === occasionId && t.active !== false,
  );
}

export async function createTicketOrder({
  type,
  qty,
  name,
  email,
  phone,
  editionId,
}) {
  await delay(300);
  if (!Number.isInteger(qty) || qty < 1 || qty > type.maxPerOrder)
    throw userError(`You can buy 1 to ${type.maxPerOrder} tickets per order.`, {
      qty: "Choose a valid quantity.",
    });
  const order = {
    reference: uid("ORD-", 8),
    editionId,
    typeId: type.id,
    typeName: type.name,
    qty,
    name,
    email: email.toLowerCase(),
    phone,
    amount: type.price * qty,
    status: "pending",
    createdAt: new Date().toISOString(),
  };
  store.set(ORDERS, [...store.get(ORDERS, []), order]);
  return order;
}

export async function issueTickets(reference) {
  await delay(300);
  const orders = store.get(ORDERS, []);
  const order = orders.find((o) => o.reference === reference);
  if (!order) throw userError("We could not find that order.");
  order.status = "paid";
  const tickets = Array.from({ length: order.qty }, () => ({
    code: uid("TKT-", 8),
    editionId: order.editionId,
    orderReference: order.reference,
    typeName: order.typeName,
    holder: order.name,
    email: order.email,
    used: false,
  }));
  store.set(ORDERS, orders);
  store.set(TICKETS, [...store.get(TICKETS, []), ...tickets]);
  return { order, tickets };
}

/** Find tickets by ticket code OR order reference, confirmed with the buyer's email. */
export async function findTickets({ ref, email }) {
  await delay(300);
  const r = ref.trim().toUpperCase();
  const e = email.trim().toLowerCase();
  const tickets = store
    .get(TICKETS, [])
    .filter((t) => (t.code === r || t.orderReference === r) && t.email === e);
  if (!tickets.length)
    throw userError(
      "No ticket found for those details. Check the reference and email and try again.",
    );
  return tickets;
}
