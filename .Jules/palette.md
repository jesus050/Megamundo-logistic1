## 2026-11-07 - Missing ARIA Labels on Icon-Only Buttons
**Learning:** Found multiple instances where close or remove buttons consisting only of an "×" or "✕" character lacked `aria-label` attributes. Screen reader users would have a poor experience as these would likely be announced as 'times' or 'multiply' rather than 'Close' or 'Remove'.
**Action:** Always ensure that icon-only buttons (like those with "×", "✕", or other generic symbols/emojis used as icons) have descriptive `aria-label` attributes to explain their function (e.g., 'Cerrar', 'Eliminar producto').
