const RAW = (typeof window !== 'undefined' && window.__VV_CFG__) || {}

export const CFG = {
  apiUrl: RAW.apiUrl || 'view-voucher-ui/api/init.php',
  voucherId: RAW.voucherId || 0,
  data: RAW.data || null,
  flash: RAW.flash || {},
}
