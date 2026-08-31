DEPLOYMENT INSTRUCTIONS - DELIVERY LOGISTICS MODULE
===================================================

This zip file contains the latest updates for the Delivery and Logistics module, including:
1.  Verify Delivery Interface (with download icons).
2.  Conditional Download Logic (preventing unsigned downloads).
3.  Single Signature Enforcement.
4.  Link Expiration Security (1-hour validity).
5.  Database Schema Updates (handled automatically).

FILES INCLUDED:
---------------
- deliveries/ (Folder containing all delivery scripts)
- includes/functions.php (Core logic and database schema updates)

HOW to DEPLOY to CPANEL:
------------------------
1.  Upload this zip file to your 'public_html/staff' directory (or wherever your 'staff' folder is located).
2.  Extract the zip file.
    - IMPORTANT: Ensure the files overlay/overwrite the existing 'deliveries' folder and 'includes/functions.php'.
3.  Database Updates:
    - The `includes/functions.php` file contains improper automatic schema migrations.
    - Simply visiting any page that generates a verification link (like creating an order) will trigger the update to add `verification_hash_created_at` and `receiver_signature_path` columns if they don't exist.
    - No manual SQL execution is required.

VERIFICATION:
-------------
1.  Create a new delivery order.
2.  Generate a verification link.
3.  Test the link in a private/incognito window to verify the new "Sign to Enable Download" behavior.

Ref: Task ID 4bf3d7b6
