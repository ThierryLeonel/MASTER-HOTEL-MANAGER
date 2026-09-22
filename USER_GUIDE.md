# User Guide

This guide covers every workflow in the system as it exists today — everything built in Phases 1–8, everything
added in Phase 9 (9.0 through 9.7.6), and the two out-of-band additions layered on top (Front Desk petty sales,
complimentary items/stays). It's organized by role, since what a screen does and who can reach it is the same
thing in this system — every nav link and every action is permission-gated, and the sidebar you see is already
trimmed to what your account can actually do (see README §9 for the complete role → permission matrix behind
every gate described below).

If a screen or button described here doesn't appear for you, it's almost certainly a permission your account
doesn't hold, not a bug — check the matrix in README §9, or ask whoever holds `manage-users` to review your role.

---

## 1. Signing in

This system uses three different login methods depending on role, chosen because they fit how each role actually
works day to day:

- **Password + TOTP (two-factor)** — Owner, General Manager, Accountant. Go to `/login`, enter your username, then
  your password. The first time you sign in, you'll be walked through enrolling a TOTP authenticator app (Google
  Authenticator, Authy, or similar) by scanning a QR code; every login after that asks for a 6-digit code from
  that app alongside your password.
- **Password only** — Duty Manager. Same flow, no TOTP step.
- **PIN** — Cashier, Front Desk, Bar Staff, Restaurant Staff. Enter your username, then a 4–6 digit PIN instead of
  a password — designed for a fast counter/till login. Too many wrong PIN attempts locks the account for a
  configurable cooldown (an Owner/GM can clear this manually if needed); this is intentional, not a bug, and
  matches the small keyspace a short PIN has.

The login page asks for your username first and automatically routes you to the right next step based on your
account's configured auth type — you don't need to know in advance which kind of account you have.

---

## 2. Every role, what they see, and what they do

### Owner / General Manager

These two roles hold, functionally, every permission in the system (Owner bypasses every permission check
entirely; GM is granted the full permission set explicitly) — they're the only roles that see every nav section:
Front Office, POS (all three outlets), Inventory, Finance, HR, Reports, and Administration.

**Day to day**, most Owner/GM time is spent in:
- **Dashboard** — the landing page after login. Shows pending-approval counts across every approval type you can
  act on (reservations, expenses, refunds, maintenance tickets that take a room out of service, purchase orders,
  requisitions, wastage, leave, payroll), plus a rolling weekly financial summary widget.
- **Reports** — Income Statement, Cash Flow, AR Aging, Revenue, Inventory Valuation, Occupancy/ADR, Guest Stay
  History, Payroll Cost, Attendance Summary, the Weekly Owner Summary (a one-page print/CSV view of the last 7
  days), plus printable/CSV versions of the Payment Summary and Expense Report. Every financial report is also
  available as a printable document for board/handover purposes.
- **Approvals** — anything flagged on the Dashboard: purchase orders, requisitions, wastage write-offs, expenses
  (including "large" expenses above the configurable threshold — only Owner/GM/Accountant can approve those),
  refunds, leave requests, payroll runs.
- **Administration** — Users (create/edit/deactivate staff accounts, reset a password or PIN, unlock a locked-out
  PIN), Departments, Room Categories, Settings (every business policy — tax rate, deposit %, expense-approval
  threshold, session timeout, PIN lockout rules, the hotel logo — editable here without a code deploy), and, if
  running the hybrid sync topology, **Sync Status** (`/admin/sync`) — see §4 below.
- **Marking a stay complimentary** — Owner/GM can do this too (the `mark-stay-complimentary` permission), though
  in practice this is more often a Duty Manager's day-to-day call; see §3's Front Desk section for how it works.

### Duty Manager

The operational manager on shift — holds most of the same day-to-day permissions as Owner/GM (guests,
reservations, rooms, check-in/out, folios, housekeeping, maintenance approval, POS across all three outlets,
purchase orders, requisition/wastage approval, stock counts) but **not** Administration/Users, large-expense
approval, payroll approval, or accounting-day locking — those stay with Owner/GM/Accountant, a deliberate
separation of duties (see `SECURITY.md` for the specific self-approval guards this enforces). Duty Manager also
holds `mark-stay-complimentary` and `manage-sync`.

**Distinctive to this role**: approving purchase orders/requisitions/wastage that Bar/Restaurant/Inventory staff
raised, front-line escalations (unlocking a locked-out PIN account, correcting a colleague's attendance record),
and the two things Front Desk itself cannot do — marking a stay complimentary, and the sync dashboard.

### Accountant

Finance-focused: expense approval (small and large), refund approval, payroll management and approval, the
accounting-day lock (requires today's cash reconciliation to already exist), and every financial/inventory/HR
report. Does **not** hold day-to-day guest/room/POS permissions — this role lives in Finance and Reports, not
Front Office or the tills.

### Cashier

Posts payments against a guest's folio (`post-folio-payment`) and records the daily cash reconciliation. A narrow,
deliberately scoped role — a Cashier cannot post a *charge*, only receive a *payment*, and cannot void a
transaction (that needs `void-folio-transaction`, held by managers/Accountant).

### Front Desk

The reception counter — check guests in/out, manage reservations, view the room board, post folio charges (any
type — room, bar, restaurant, front-desk, other service), manage housekeeping tasks, and (since the out-of-band
addition covered in §3) ring up small walk-up sales through a dedicated Front Desk POS till. Front Desk does
**not** hold `mark-stay-complimentary` — waiving a room charge is a Duty Manager/GM/Owner decision, deliberately
kept out of front-line hands even though Front Desk otherwise manages the whole stay lifecycle.

**Typical shift**: Walk-in Check-in or converting an approved Reservation into a Stay → Active Stays to view a
guest's folio, post charges/print an invoice → Front Desk Sales for a quick counter item → Check Out with the
final night's charge included (or skipped, if the stay is complimentary — see §3) → Housekeeping to log a room
back to clean once housekeeping confirms it.

### Bar Staff / Restaurant Staff

Each operates their own POS till (`operate-bar-pos` / `operate-restaurant-pos`) — open a table or table-less
order, add items (or comp one, see §3), confirm it, and close it either against a checked-in guest's room folio or
an immediate cash/card/mobile-money/bank-transfer payment. Both roles also hold `request-stock` and
`record-wastage` for their own outlet's inventory, and `report-maintenance` to log an issue.

---

## 3. Workflows added after the original spec (out-of-band additions)

These two features were added mid-project at the property's request and aren't part of any single role's
description above, since they cut across POS and Front Office.

### Front Desk petty sales

Front Desk staff can ring up small walk-up items — bottled water, juice, soft drinks, biscuits, snacks — through
their own **Front Desk Sales** till, reached from the nav the same way Bar/Restaurant POS is. It's the exact same
order screen Bar and Restaurant use (open → add items → confirm → close with cash or deferred to a guest's room
folio), just with no table selection, since a reception counter has no seating to track. A front desk sale
deferred to a guest's folio posts specifically as a **front desk charge**, distinct from a room/bar/restaurant
charge, so it shows correctly on every report and on the guest's own itemized bill.

### Complimentary items (a free item within an otherwise-paid order)

Any POS outlet (Bar, Restaurant, or Front Desk) can mark a **single line item** on an order as complimentary —
free — while every other item on that same order stays normally billable. On the order screen's "add item" form,
check the **Complimentary** box and enter a reason (required — e.g. "welcome drink for a returning guest"); the
item is added at zero charge and shows a purple **Comp** badge in the order's item list (hover it to see the
reason). This is per-item, not per-order: comping a welcome drink doesn't make the guest's actual meal free too.
An order made up entirely of comp items can be closed with no payment method and no room folio selected, since
there's nothing to collect.

### Complimentary stays ("special guest lodging")

A Duty Manager, GM, or Owner can mark an **active stay** complimentary from that guest's own page (the "Mark
complimentary" form, requiring a reason — e.g. "familiarization stay for a travel partner," "VIP guest") — Front
Desk cannot do this themselves. This waives **only the room charge**: every other charge on that guest's folio
(bar, restaurant, front-desk sales, other services) still posts and gets paid exactly as normal, and the guest's
page clearly labels the stay "Complimentary" with the reason and who authorized it. At checkout, the final
night's room charge is automatically skipped for a complimentary stay rather than needing to be manually removed
— checkout is never held up by this. A manager can remove the complimentary flag at any time (the "Remove
complimentary status" button on the same page), after which room charges apply normally again for any future
night.

---

## 4. The hybrid sync dashboard (only if this property runs the local-server + cloud-VPS setup)

If your property runs the two-node hybrid topology (`HYBRID_ARCHITECTURE.md`), Owner/GM/Duty Manager see a
**Sync Status** link. This shows every registered counterpart device (the other installation), when it was last
seen, and both directions' last-synced times. If a genuine conflict comes up — the same record was edited
differently on both installations between two sync cycles — it appears on the **Conflicts** page with both
versions shown side by side, and you choose how to resolve it: keep the local version, accept the incoming
version, mark it as already manually reconciled, or leave both as history. This is a deliberately manual step —
the system will never silently guess which side is "more correct" for you.

---

## 5. Getting help

- **Locked out of a PIN account** — wait out the lockout window, or ask an Owner/GM to clear it from
  Administration → Users.
- **A screen or button you expect isn't there** — almost always a permission your role doesn't hold; see README §9
  for the full matrix, or ask whoever holds `manage-users` to review it.
- **TOTP codes not accepted** — check that your phone's clock is accurate; TOTP is time-based and drifts of more
  than about 30–60 seconds between your phone and the server will fail every code.
- For anything covered here that doesn't match what you actually see on screen, that's worth reporting — see
  `TEST_REPORT.md`/`SECURITY.md` for this project's standing approach to treating a real discrepancy as a genuine
  bug to fix, not something to explain away.
