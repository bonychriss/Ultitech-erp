const RAW = (typeof window !== 'undefined' && window.__VV_CFG__) || {}

export const CFG = {
  apiUrl: RAW.apiUrl || 'view-voucher-ui/api/init.php',
  notifyUrl: RAW.notifyUrl || 'view-voucher-ui/api/whatsapp-notify.php',
  voucherId: RAW.voucherId || 0,
  data: RAW.data || null,
  flash: RAW.flash || {},
}
