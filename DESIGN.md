# AV Rescue — design system and interaction rules

## Direction

The visual character is dependable roadside operations: dark, precise,
high-contrast and calm. Red signals action and urgency; it is not decoration.
The product should feel fast and controlled, not theatrical.

## Surface modes

### Marketing website — Persuade

- One primary CTA: «Вызвать помощь».
- Real, relevant vehicle and roadside imagery; no random stock placeholders.
- Strong typography and composition are welcome, but factual copy, prices and
  availability must come from project truth.
- Motion may support the story, but must not delay the form or hijack scrolling.

### Driver Android and iOS/PWA — Operate

- Optimize for one-handed use, glare, motion and intermittent connectivity.
- Keep «Свободные / Назначенные / Закрытые» stable and easy to reach.
- Use one visually dominant action per order state: «Принять», «На месте»,
  «К точке Б», «Завершить».
- Keep A and B labels attached to their addresses and coordinates. Never infer
  an address from display text when coordinates are available.
- Preserve the current step after refresh or reconnect and show whether data is
  current, cached or retrying.
- Do not hide primary actions behind overflow menus or decorative transitions.

### Dispatcher and admin — Operate

- Prefer scanability, compact information hierarchy and consistent status
  tokens over large marketing layouts.
- Counts, filters and table headers remain visible and useful at desktop width.
- Red is reserved for the main action, a real fault or a destructive operation.
- Never use animation to conceal status changes or delay order assignment.

### Payment and legal — Read / Operate

- Show order number, exact amount, method and current payment state together.
- Use calm language and explicit next steps for pending, declined, refunded and
  verification-required states.
- No celebratory success state before verified server confirmation.

## Core tokens

- Background: `#08090A`
- Surface: `#111317`
- Raised surface: `#17191C`
- Primary text: `#FFFFFF`
- Secondary text: `#AEB0B4`
- Divider: `#30343A`
- Brand/action red: `#ED111C`
- Success: `#2EAD67`
- Warning: `#F2A93B`
- Error: `#E5484D`
- Base corner radius: 8 px; cards may use 12–14 px.
- Minimum tap target: 48 × 48 dp; critical driver actions should be taller.
- Use a readable sans-serif with Cyrillic support. Do not introduce a display
  font into operational screens merely for novelty.

## Motion

- Frequent controls: 120–180 ms; panels and status transitions: 180–220 ms.
- Prefer an ease-out curve for appearing UI and direct feedback.
- Animate only `transform` and `opacity` where practical.
- Never animate routine driver actions for longer than 300 ms.
- No scroll-jacking, pinned GSAP sequences, parallax or cinematic section
  changes in Android, dispatcher, admin or payment flows.
- Respect reduced-motion preferences and provide an immediate non-animated path.

## Content and accessibility

- Russian is the source language for user-facing operational copy.
- Explain the recovery action: not «Ошибка 403», but «Сессия закончилась —
  войдите снова».
- Status must never rely on color alone; pair color with text and, where useful,
  a simple icon.
- Maintain WCAG AA contrast for text and controls.
- Visible focus, keyboard navigation and semantic labels are required on web.

## How the design skills are applied

1. **Impeccable** is the baseline audit for hierarchy, consistency,
   accessibility, responsive behavior and edge cases.
2. **gpt-taste** may influence the public marketing surface only. Its random
   layouts, large spacing and heavy GSAP direction are explicitly disabled for
   operational, payment and native-app screens.
3. **emil-design-eng** governs micro-interactions: exact properties, short
   timings, useful origins, interruptibility and restrained feedback.

Every visual change must be rendered at phone and desktop sizes where relevant,
checked against the critical flow, and tested without modifying production.
