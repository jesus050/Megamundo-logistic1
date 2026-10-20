## 2024-05-18 - Missing ARIA labels in icon-only buttons
**Learning:** Found multiple instances where icon-only buttons (like `x` to close modals or delete rows) in the PHP frontend traits (`ScannerViewController.php`, `PedidosTrait.php`) lacked `aria-label`s, causing accessibility issues.
**Action:** When working on UI components, routinely check `button` tags with only icons or symbols and add contextual Spanish `aria-label`s.
