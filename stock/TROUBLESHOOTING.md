# 🔧 Troubleshooting Guide

## Common Issues & Solutions

### 1. Stock Not Updating After Receipt
*   **Symptom:** You clicked "Confirm Receipt" but stock quantity remains unchanged.
*   **Cause:** The shipment items might not be linked to valid Product IDs (e.g., if they were free-text entries).
*   **Solution:** Ensure every line item in the shipment is selected from the dropdown list of registered products. Free-text descriptions are for reference only and do not trigger stock updates.

### 2. "Landed Cost" Tab Missing
*   **Symptom:** You cannot see the Landed Cost tab on a shipment.
*   **Cause:** You might be on an older cached version of the page or the shipment is not in a valid status.
*   **Solution:** Refresh the page (Ctrl+F5). Note that costs can be entered at any stage, but typically performed when status is 'In Transit' or 'Customs'.

### 3. Dashboard Charts Not Loading
*   **Symptom:** Empty white space where charts should be.
*   **Cause:** JavaScript error or missing data.
*   **Solution:** Check if `system_stats` table is populated. If new installation, create at least one product and one shipment.

## Database Recovery
If data seems inconsistent:
1.  **Check `stock_movements`:** This table is the source of truth for all changes.
2.  **Recalculate Totals:** Run a manual SQL query to sum `stock_movements` and compare with `stock` table.

## Error Messages
| Error | Meaning | Fix |
| :--- | :--- | :--- |
| `Integrity constraint violation` | Trying to delete a record linked to others. | Delete dependent records first (e.g., delete shipment items before shipment). |
| `Data too long for column` | Input text exceeds limit. | Shorten the text (e.g., product code max 50 chars). |
