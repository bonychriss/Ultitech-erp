/**
 * Shared sample data for every layout in the library.
 * Swap these values when you want real client content.
 */
export const invoice = {
  brand: 'Business Name.',
  date: 'December 26, 2020',
  number: 'INV-2020-026',
  officeAddress: 'Main street, Number 06/B,\nSouth Mountain, YK',
  phone: '(+62) 123 456 7890',
  to: {
    name: 'Wagino Subianto',
    address: 'Main street, Number 06/B,\nSouth Mountain, YK',
  },
  items: [
    {
      name: 'Items Name',
      detail: 'Lorem ipsum is simply dummy text of the printing and typesetting.',
      unitPrice: 20,
      qty: 1,
    },
    {
      name: 'Items Name',
      detail: 'Lorem ipsum is simply dummy text of the printing and typesetting.',
      unitPrice: 30,
      qty: 1,
    },
    {
      name: 'Items Name',
      detail: 'Lorem ipsum is simply dummy text of the printing and typesetting.',
      unitPrice: 50,
      qty: 1,
    },
    {
      name: 'Items Name',
      detail: 'Lorem ipsum is simply dummy text of the printing and typesetting.',
      unitPrice: 60,
      qty: 1,
    },
  ],
  taxRate: 0.15,
  discountRate: 0.05,
  note: 'Lorem ipsum is simply dummy text of the printing and typesetting industry. Lorem Ipsum has been the industry.',
  thankYou: 'Thank you for your Business',
  questions: {
    email: 'hello@reallygreatsite.com',
    phone: '+123-456-7890',
  },
  payment: {
    account: '123-456-7890',
    name: 'Lorem Ipsum',
    bank: 'Your Bank Name',
  },
  terms:
    'Please make payment within 14 days. Late payments may incur a fee. Contact us with any questions about this invoice.',
}

/** Layout 2 — purple Brand Name invoice (from layy.png) */
export const brandPurple = {
  initial: 'B',
  brand: 'BRAND NAME',
  tagline: 'TAGLINE GOES HERE',
  invoiceNo: '#1234567899',
  date: '10 November 2030',
  to: {
    name: 'DWYANE CLARK',
    address: ['Address and City Name', '2424 Country Name'],
  },
  items: [
    { name: 'Logo Design', price: 40, qty: 1 },
    { name: 'Business Card Design', price: 45, qty: 3 },
    { name: 'Flyer Design', price: 50, qty: 5 },
    { name: 'Theme Development', price: 65, qty: 8 },
    { name: 'Construction Design', price: 42, qty: 4 },
    { name: 'Infographic Presentation', price: 50, qty: 2 },
  ],
  vatRate: 0.1,
  thanks: 'Thank You for Your Business',
  phone: '+0000 123 456 789',
  web: 'www.websitename.com',
  payment: {
    account: '0000000000',
    name: 'Name Place Here',
    code: '0004567',
  },
  signer: { name: 'Thomas S. Smith', role: 'Account Officer' },
  terms:
    'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.',
}

/** Layout 4 — Pixel Mart red & black invoice */
export const pixelMart = {
  brand: 'Pixel Mart',
  tagline: 'Creative design house',
  invoiceNo: '#060820',
  accountNo: '280090',
  date: '20 Nov, 2023',
  to: {
    name: 'MD ABDULLAH',
    role: 'Manager, Company Name',
    phone: '+0000 1234 5678',
    email: 'info@yourmail.com',
  },
  // `image`: any URL or path (e.g. '/projects/invoice/items/logo.png'). Leave '' for a placeholder.
  items: [
    { image: '', name: 'Animation Design', detail: 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt.', qty: 5, price: 200 },
    { image: '', name: 'Website Design', detail: 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt.', qty: 2, price: 100 },
    { image: '', name: 'Application Development', detail: 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt.', qty: 3, price: 150 },
    { image: '', name: 'Video Content Editing', detail: 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt.', qty: 4, price: 100 },
    { image: '', name: 'Ui/Ux Design', detail: 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt.', qty: 1, price: 300 },
  ],
  vatRate: 0.1,
  discountRate: 0.05,
  payment: {
    account: '0123456789001090',
    swift: '0123456O',
    payoneer: 'payoneer@gmail.com',
  },
  terms: 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore dolore.',
  thanks: 'Thanks for your business',
  signer: { name: 'NAZMUL HASAN', role: 'Chief Director' },
  footer: {
    phone: '+000 12345 6789',
    web: 'urwebsitename.com',
    address: 'Street Address, write Here,100',
  },
}

/** Layout 5 — yellow bar Brand Name invoice */
export const brandYellow = {
  brand: 'Brand Name',
  tagline: 'TAGLINE SPACE HERE',
  invoiceNo: '521488',
  date: '01 / 02 / 2020',
  to: {
    name: 'Dwyane Clark',
    address: ['24 Dummy Street Area,', 'Location, Lorem Ipsum,', '5700 x59x'],
  },
  items: [
    { name: 'Lorem Ipsum Dolor', price: 50, qty: 1 },
    { name: 'Pellentesque id neque ligula', price: 20, qty: 3 },
    { name: 'Interdum et malesuada fames', price: 10, qty: 2 },
    { name: 'Vivamus volutpat faucibus', price: 90, qty: 1 },
  ],
  taxRate: 0,
  thanks: 'Thank you for your business',
  terms:
    'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt.',
  payment: {
    account: '123456789012',
    name: 'Lorem Ipsum',
    bank: 'Add your bank details',
  },
  sign: 'Authorised Sign',
  footer: {
    phone: 'Phone #',
    address: 'Address',
    web: 'Website',
  },
}

/** Match the purple template style: "$ 20,00" */
export function money(amount) {
  const n = Number(amount).toFixed(2).replace('.', ',')
  return `$ ${n}`
}

export function totals(data = invoice) {
  const subtotal = data.items.reduce((sum, row) => sum + row.qty * row.unitPrice, 0)
  const tax = subtotal * data.taxRate
  const discount = subtotal * data.discountRate
  const total = subtotal + tax - discount
  return { subtotal, tax, discount, total }
}
