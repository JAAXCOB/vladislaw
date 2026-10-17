# AV Rescue — product context

## Product

AV Rescue is an operational roadside-assistance platform. It connects clients,
dispatchers, own-fleet drivers, external executors and B2B partners. The public
brand promise is: «Найдём помощь рядом».

## Primary users and conditions

- A driver works outdoors, often one-handed, under time pressure and with an
  unstable mobile connection.
- A dispatcher scans many orders quickly and must see the real status without
  opening unnecessary screens.
- A client may be stressed and needs one obvious way to request help and track
  payment or order status.
- An administrator needs dense but understandable operational and financial
  information with safe, explicit actions.

## Critical flows

1. Driver: sign in → go online → Available → accept → Assigned → route to A →
   arrived → route to B → complete.
2. Client: choose service and vehicle → specify A and B → see preliminary
   price → create order → pay or choose cash → track status.
3. Dispatcher: see new orders → assign or monitor automatic assignment → track
   driver progress → resolve exceptions → close and reconcile.
4. Payment: show the exact order, amount, method and status; never imply success
   until the bank and server both confirm it.

## Product truths that design must preserve

- Driver tabs are «Свободные», «Назначенные», «Закрытые».
- The primary action must remain visible and large at every operational step.
- Nested screens have a clear Back action.
- Status and errors are written in plain Russian, not raw technical codes.
- Point A and point B are never visually interchangeable.
- One driver can have only one active ordinary order; assignment and completion
  must not create duplicates.
- Existing approved drivers are not sent through registration or moderation
  again.
- The brand palette is black, red and white. Vehicle imagery is preferred over
  emoji for service and vehicle choices.
- B2B MAX orders and their accounting remain separate unless the owner approves
  a product change.

## Success criteria

- A driver understands the next action in under three seconds.
- A dispatcher can distinguish new, assigned, in-progress, blocked and closed
  orders without opening each order.
- Destructive, financial and irreversible actions require explicit wording and
  confirmation proportional to the risk.
- Interfaces remain usable on a small Android phone, a modern iPhone/PWA and a
  desktop dispatcher screen.
- Design changes do not alter server rules, money calculations, order states or
  production data.

