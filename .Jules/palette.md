## 2024-05-15 - ARIA Labels for Icon-Only Buttons
**Learning:** Found multiple instances of icon-only buttons (like modal close buttons using "×" or "✕") that lacked `aria-label` attributes. Screen readers would read these as "times" or "multiply", confusing users.
**Action:** Always verify icon-only buttons have an `aria-label` describing their action (e.g. `aria-label="Cerrar"`).
