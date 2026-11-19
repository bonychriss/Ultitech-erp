from pathlib import Path
import os
import sys

src = Path(os.environ.get('TEMP', '.')) / 'live_sidebar.php'
if not src.is_file():
    print('missing', src)
    sys.exit(1)

text = src.read_text(encoding='utf-8', errors='replace')
admin_needle = "addItem($menuItems, 'all', 'View All', 'collection', $prefix . 'admin/all-vouchers.php?module=voucher');"
admin_insert = (
    admin_needle
    + "\n            addItem($menuItems, 'credit-vouchers', 'Credit Vouchers', 'credit-card', "
    + "$prefix . 'admin/dashboard.php?module=voucher&payment=credit');"
)
emp_needle = "addItem($menuItems, 'view', 'My Vouchers', 'ticket-detailed', $prefix . 'employee/my-vouchers.php?module=voucher');"
emp_insert = (
    emp_needle
    + "\n            addItem($menuItems, 'credit-vouchers', 'Credit Vouchers', 'credit-card', "
    + "$prefix . 'admin/dashboard.php?module=voucher&payment=credit');"
)

if 'credit-vouchers' in text:
    print('already present')
    sys.exit(0)

changed = False
if admin_needle not in text:
    print('ADMIN NEEDLE MISSING')
else:
    text = text.replace(admin_needle, admin_insert, 1)
    print('admin ok')
    changed = True

if emp_needle not in text:
    print('EMP NEEDLE MISSING')
else:
    text = text.replace(emp_needle, emp_insert, 1)
    print('emp ok')
    changed = True

if not changed:
    sys.exit(2)

out = Path(os.environ.get('TEMP', '.')) / 'live_sidebar_patched.php'
out.write_text(text, encoding='utf-8')
print('wrote', out, 'credits=', text.count('credit-vouchers'))
