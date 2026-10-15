export function resolveDeliveryType(order) {
  if (!order) return 'Dispatch'
  const existing = String(order.delivery_type || '').trim()
  if (existing !== '') return existing
  const kind = String(order.request_kind || '').trim().toLowerCase()
  if (kind === 'client_visit') return 'Client Visit'
  if (kind === 'goods_collection') return 'Goods Collection'
  const invoiceId = Number(order.sales_invoice_id || 0)
  const invoiceRef = String(order.invoice_ref || '').trim()
  if (invoiceId > 0 || invoiceRef !== '') return 'Office Trip'
  return 'Dispatch'
}

export function typePill(deliveryType) {
  const type = String(deliveryType || '').toLowerCase()
  if (type === 'client visit') {
    return { label: 'Client Visit', cls: 'dlv-vbadge dlv-vbadge--client-visit' }
  }
  if (type === 'goods collection') {
    return { label: 'Goods Collection', cls: 'dlv-vbadge dlv-vbadge--collection' }
  }
  if (type === 'office trip') {
    return { label: 'Office Trip', cls: 'dlv-vbadge dlv-vbadge--office-trip' }
  }
  return { label: 'Dispatch', cls: 'dlv-vbadge dlv-vbadge--dispatch' }
}
