## 2026-10-31 - Add ARIA Labels to Icon-only Buttons
**Learning:** Found several icon-only buttons (using text '×' or '✕') missing 'aria-label' and 'title' attributes. This is an accessibility issue because screen readers won't read '×' intelligibly or they won't describe the context, and sighted users on desktop lose out on tooltip hints.
**Action:** Always verify icon-only buttons (especially structural components like modals or table actions) include localized ARIA labels and titles to ensure full accessibility.
