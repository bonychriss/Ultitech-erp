<?php
/**
 * Shipment Helper Functions
 */

function getShipmentStatusBadge($status) {
    $colors = [
        'pending' => 'warning',
        'confirmed' => 'info',
        'shipped' => 'primary',
        'in_transit' => 'primary',
        'arrived_at_port' => 'info',
        'in_customs' => 'warning',
        'ready_for_pickup' => 'info',
        'out_for_delivery' => 'primary',
        'delivered' => 'success',
        'cancelled' => 'danger'
    ];
    
    $color = $colors[$status] ?? 'secondary';
    $label = ucwords(str_replace('_', ' ', $status));
    
    return "<span class='badge bg-{$color}'>{$label}</span>";
}
?>
