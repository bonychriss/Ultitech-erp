import logoCrdb from '../assets/banks/crdb.png';
import logoNmb from '../assets/banks/nmb.jpg';
import logoNbc from '../assets/banks/nbc.svg';
import logoAbsa from '../assets/banks/absa.svg';
import logoAccess from '../assets/banks/access.png';
import logoEquity from '../assets/banks/equity.png';
import logoKcb from '../assets/banks/kcb.png';
import logoSc from '../assets/banks/standard-chartered.svg';
import logoDtb from '../assets/banks/dtb.png';
import logoExim from '../assets/banks/exim.png';
import logoAzania from '../assets/banks/azania.png';
import logoBoa from '../assets/banks/boa.png';
import logoEcobank from '../assets/banks/ecobank.svg';
import logoCiti from '../assets/banks/citi.svg';
import logoUbl from '../assets/banks/ubl.svg';
import logoYetu from '../assets/banks/yetu.png';
import logoPbz from '../assets/banks/pbz.png';
import logoTpb from '../assets/banks/tpb.png';
import logoAmana from '../assets/banks/amana.png';
import logoIm from '../assets/banks/im.png';
import logoMaendeleo from '../assets/banks/maendeleo.png';
import logoBaroda from '../assets/banks/baroda.png';
import logoUba from '../assets/banks/uba.png';
import logoCanara from '../assets/banks/canara.svg';
import logoIcici from '../assets/banks/icici.svg';
import logoHsbc from '../assets/banks/hsbc.svg';
import logoFnb from '../assets/banks/fnb.svg';
import logoLetshego from '../assets/banks/letshego.png';
import logoGtbank from '../assets/banks/gtbank.svg';
import logoDcb from '../assets/banks/dcb.svg';
import logoMwanga from '../assets/banks/mwanga.png';
import logoMufindi from '../assets/banks/mufindi.png';
import logoMwalimu from '../assets/banks/mwalimu.svg';

/** Tanzanian / regional banks used for payroll salary accounts. */
export const TANZANIA_BANKS = [
  { name: 'CRDB Bank', short: 'CRDB', logo: logoCrdb },
  { name: 'NMB Bank', short: 'NMB', logo: logoNmb },
  { name: 'NBC Bank', short: 'NBC', logo: logoNbc },
  { name: 'United Bank for Africa', short: 'UBA', logo: logoUba },
  { name: 'Equity Bank', short: 'EQB', logo: logoEquity },
  { name: 'Absa Bank', short: 'ABSA', logo: logoAbsa },
  { name: 'Stanbic Bank', short: 'STB', logo: null },
  { name: 'Standard Chartered', short: 'SC', logo: logoSc },
  { name: 'KCB Bank', short: 'KCB', logo: logoKcb },
  { name: 'Diamond Trust Bank', short: 'DTB', logo: logoDtb },
  { name: 'Exim Bank', short: 'EXIM', logo: logoExim },
  { name: 'Azania Bank', short: 'AZN', logo: logoAzania },
  { name: 'Bank of Africa', short: 'BOA', logo: logoBoa },
  { name: 'Access Bank', short: 'ACC', logo: logoAccess },
  { name: 'Ecobank', short: 'ECO', logo: logoEcobank },
  { name: 'TPB Bank', short: 'TPB', logo: logoTpb },
  { name: 'TCB Bank', short: 'TCB', logo: null },
  { name: 'Amana Bank', short: 'AMN', logo: logoAmana },
  { name: 'I&M Bank', short: 'I&M', logo: logoIm },
  { name: 'Akiba Commercial Bank', short: 'ACB', logo: null },
  { name: 'Maendeleo Bank', short: 'MDB', logo: logoMaendeleo },
  { name: 'Habib African Bank', short: 'HAB', logo: null },
  { name: 'PBZ Bank', short: 'PBZ', logo: logoPbz },
  { name: 'Bank of Baroda', short: 'BOB', logo: logoBaroda },
  { name: 'UBL Bank', short: 'UBL', logo: logoUbl },
  { name: 'Citibank', short: 'CITI', logo: logoCiti },
  { name: 'Canara Bank', short: 'CNB', logo: logoCanara },
  { name: 'ICICI Bank', short: 'ICICI', logo: logoIcici },
  { name: 'HSBC', short: 'HSBC', logo: logoHsbc },
  { name: 'FNB Tanzania', short: 'FNB', logo: logoFnb },
  { name: 'Letshego Bank', short: 'LET', logo: logoLetshego },
  { name: 'Guaranty Trust Bank', short: 'GTB', logo: logoGtbank },
  { name: 'DCB Commercial Bank', short: 'DCB', logo: logoDcb },
  { name: 'Mwanga Hakika Bank', short: 'MHB', logo: logoMwanga },
  { name: 'Mufindi Community Bank', short: 'MUCO', logo: logoMufindi },
  { name: 'Mwalimu Commercial Bank', short: 'MCB', logo: logoMwalimu },
  { name: 'China Dasheng Bank', short: 'CDB', logo: null },
  { name: 'Yetu Microfinance Bank', short: 'YETU', logo: logoYetu },
];

export function findBankByName(value) {
  const raw = String(value || '').trim();
  if (!raw) return null;
  const lower = raw.toLowerCase();

  // Common aliases
  if (lower.includes('united bank for africa') || lower === 'uba' || lower.includes('united bank of africa')) {
    return TANZANIA_BANKS.find((bank) => bank.short === 'UBA') || null;
  }

  return (
    TANZANIA_BANKS.find((bank) => bank.name.toLowerCase() === lower)
    || TANZANIA_BANKS.find((bank) => bank.short.toLowerCase() === lower)
    || TANZANIA_BANKS.find((bank) => lower.includes(bank.short.toLowerCase()) || lower.includes(bank.name.toLowerCase()))
    || null
  );
}
