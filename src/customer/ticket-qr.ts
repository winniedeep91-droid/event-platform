/**
 * Renders a scannable QR code into every EventOS ticket placeholder on a
 * WooCommerce order page (order-received / My Account → View Order).
 *
 * Deliberately outside the wp-admin React app: this runs on a customer-facing
 * WooCommerce template, not inside the admin SPA, so it stays a small
 * standalone script with its own build (see vite.customer.config.ts) rather
 * than pulling in the admin bundle's dependencies.
 *
 * PHP (Ticket_Display::render_tickets()) writes one
 * `.eventos-ticket-qr[data-eventos-qr]` element per active ticket; this
 * script only ever reads that attribute, never talks to a REST endpoint —
 * the token was already scoped to this order by WooCommerce's own
 * order-received/My-Account access control before PHP rendered it.
 */
import QRCode from "qrcode";

function renderTicketQrCodes(): void {
  const nodes = document.querySelectorAll<HTMLElement>(".eventos-ticket-qr[data-eventos-qr]");

  nodes.forEach((node) => {
    const token = node.dataset.eventosQr;
    if (!token) return;

    const canvas = document.createElement("canvas");
    QRCode.toCanvas(canvas, token, { width: 160, margin: 1 })
      .then(() => {
        node.replaceChildren(canvas);
      })
      .catch(() => {
        node.textContent = "Ticket QR code unavailable — use the ticket number above at the door.";
      });
  });
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", renderTicketQrCodes);
} else {
  renderTicketQrCodes();
}
