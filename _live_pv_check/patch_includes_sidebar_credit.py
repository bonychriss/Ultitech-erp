from pathlib import Path
import os
import sys

src = Path(os.environ.get('TEMP', '.')) / 'live_includes_sidebar.php'
if not src.is_file():
    print('missing', src)
    sys.exit(1)

text = src.read_text(encoding='utf-8', errors='replace')
admin_needle = "addItem($menuItems, 'all', 'View All', 'clipboard', $prefix . 'admin/all-vouchers.php?module=voucher');"
# includes/sidebar may use different icons — probe nearby lines if needed
if 'credit-vouchers' in text:
    print('already present')
    sys.exit(0)

# Dump voucher section markers for safety
idx = text.find("case 'voucher':")
print('voucher_idx', idx)
print(text[idx:idx+900] if idx >= 0 else 'NO VOUCHER CASE')

# Try common variants
variants = [
    (
        "addItem($menuItems, 'all', 'View All', 'clipboard', $prefix . 'admin/all-vouchers.php?module=voucher');",
        "addItem($menuItems, 'all', 'View All', 'clipboard', $prefix . 'admin/all-vouchers.php?module=voucher');\n"
        "            addItem($menuItems, 'credit-vouchers', 'Credit Vouchers', 'credit-card', $prefix . 'admin/dashboard.php?module=voucher&payment=credit');",
    ),
    (
        "addItem($menuItems, 'all', 'View All', 'collection', $prefix . 'admin/all-vouchers.php?module=voucher');",
        "addItem($menuItems, 'all', 'View All', 'collection', $prefix . 'admin/all-vouchers.php?module=voucher');\n"
        "            addItem($menuItems, 'credit-vouchers', 'Credit Vouchers', 'credit-card', $prefix . 'admin/dashboard.php?module=voucher&payment=credit');",
    ),
]

emp_variants = [
    (
        "addItem($menuItems, 'view', 'View Vouchers', 'ticket', $prefix . 'employee/my-vouchers.php?module=voucher');",
        "addItem($menuItems, 'view', 'View Vouchers', 'ticket', $prefix . 'employee/my-vouchers.php?module=voucher');\n"
        "            addItem($menuItems, 'credit-vouchers', 'Credit Vouchers', 'credit-card', $prefix . 'admin/dashboard.php?module=voucher&payment=credit');",
    ),
    (
        "addItem($menuItems, 'view', 'My Vouchers', 'ticket-detailed', $prefix . 'employee/my-vouchers.php?module=voucher');",
        "addItem($menuItems, 'view', 'My Vouchers', 'ticket-detailed', $prefix . 'employee/my-vouchers.php?module=voucher');\n"
        "            addItem($menuItems, 'credit-vouchers', 'Credit Vouchers', 'credit-card', $prefix . 'admin/dashboard.php?module=voucher&payment=credit');",
    ),
]

changed = False
for needle, insert in variants:
    if needle in text:
        text = text.replace(needle, insert, 1)
        print('admin ok via', needle[:40])
        changed = True
        break
else:
    print('ADMIN NEEDLE MISSING')

for needle, insert in emp_variants:
    if needle in text:
        text = text.replace(needle, insert, 1)
        print('emp ok via', needle[:40])
        changed = True
        break
else:
    print('EMP NEEDLE MISSING')

if not changed:
    sys.exit(2)

out = Path(os.environ.get('TEMP', '.')) / 'live_includes_sidebar_patched.php'
out.write_text(text, encoding='utf-8')
print('wrote', out, 'credits=', text.count('credit-vouchers'))
