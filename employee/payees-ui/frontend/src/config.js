const RAW = (typeof window !== 'undefined' && window.__PAYEES_CFG__) || {}

export const CFG = {
  apiUrl: RAW.apiUrl || 'payees-ui/api/index.php',
  payees: Array.isArray(RAW.payees) ? RAW.payees : [],
  types: Array.isArray(RAW.types) && RAW.types.length
    ? RAW.types
    : ['Supplier', 'Staff', 'Service Provider', 'Government', 'Other'],
}
