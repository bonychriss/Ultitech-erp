<?php
/**
 * Banks and mobile money networks operating in Tanzania, offered as suggestions when naming
 * a Bank / Mobile money account. Logos live in assets/balances/institutions/{slug}.{ext}
 * (fetched by scripts/fetch-institution-logos.php).
 *
 * Optional keys: 'logo_url' (verified logo, used before searching the site), 'logo_crop' => 'symbol' (keep only
 * the brand mark of a wide wordmark) and 'badge' (initials when no logo exists).
 *
 * @return list<array{slug:string,name:string,type:string,domain:string,color:string,aliases:list<string>,logo_url?:string,badge?:string}>
 */
return [
    // Mobile money
    ['slug' => 'mpesa', 'name' => 'M-Pesa (Vodacom)', 'type' => 'mobile', 'domain' => 'vodacom.co.tz', 'color' => '#e60000', 'aliases' => ['mpesa', 'm pesa', 'vodacom', 'voda']],
    ['slug' => 'mixx-by-yas', 'name' => 'Mixx by Yas', 'type' => 'mobile', 'domain' => 'yas.co.tz', 'color' => '#ffcc00', 'aliases' => ['mixx', 'yas', 'tigo pesa', 'tigopesa', 'tigo', 'ezypesa', 'zantel']],
    ['slug' => 'airtel-money', 'name' => 'Airtel Money', 'type' => 'mobile', 'domain' => 'airtel.co.tz', 'color' => '#ed1c24', 'aliases' => ['airtel']],
    ['slug' => 'halopesa', 'badge' => 'HP', 'name' => 'HaloPesa (Halotel)', 'type' => 'mobile', 'domain' => 'halotel.co.tz', 'color' => '#f58220', 'aliases' => ['halopesa', 'halotel', 'halo pesa'], 'logo_url' => 'https://halopesa.co.tz/images/applications-system.png', 'logo_crop' => 'symbol'],
    ['slug' => 'tpesa', 'name' => 'T-Pesa (TTCL)', 'type' => 'mobile', 'domain' => 'ttcl.co.tz', 'color' => '#0055a5', 'aliases' => ['tpesa', 't pesa', 'ttcl']],
    ['slug' => 'azampesa', 'name' => 'AzamPesa', 'type' => 'mobile', 'domain' => 'azampesa.co.tz', 'color' => '#0b4ea2', 'aliases' => ['azam', 'azam pesa']],
    ['slug' => 'selcom-pesa', 'name' => 'Selcom Pesa', 'type' => 'mobile', 'domain' => 'selcom.net', 'color' => '#00a3e0', 'aliases' => ['selcom']],

    // Commercial banks
    ['slug' => 'crdb', 'name' => 'CRDB Bank', 'type' => 'bank', 'domain' => 'crdbbank.co.tz', 'color' => '#43b02a', 'aliases' => ['crdb'], 'logo_url' => 'https://crdbbank.co.tz/storage/app/media/images/logo.svg', 'logo_crop' => 'main'],
    ['slug' => 'nmb', 'badge' => 'NMB', 'name' => 'NMB Bank', 'type' => 'bank', 'domain' => 'nmbbank.co.tz', 'color' => '#003a70', 'aliases' => ['nmb'], 'logo_url' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/8/88/Nmb_logo.jpg/330px-Nmb_logo.jpg'],
    ['slug' => 'nbc', 'name' => 'NBC Bank', 'type' => 'bank', 'domain' => 'nbc.co.tz', 'color' => '#e30613', 'aliases' => ['nbc', 'national bank of commerce'], 'logo_url' => 'https://www.nbc.co.tz/content/dam/nbc/tanzania/logos/NBC-logo-01.svg'],
    ['slug' => 'stanbic', 'name' => 'Stanbic Bank Tanzania', 'type' => 'bank', 'domain' => 'stanbicbank.co.tz', 'color' => '#0033a1', 'aliases' => ['stanbic']],
    ['slug' => 'standard-chartered', 'name' => 'Standard Chartered Bank Tanzania', 'type' => 'bank', 'domain' => 'sc.com', 'color' => '#0473ea', 'aliases' => ['standard chartered', 'stanchart', 'scb']],
    ['slug' => 'absa', 'name' => 'Absa Bank Tanzania', 'type' => 'bank', 'domain' => 'absa.co.tz', 'color' => '#dc0032', 'aliases' => ['absa', 'barclays']],
    ['slug' => 'exim', 'name' => 'Exim Bank Tanzania', 'type' => 'bank', 'domain' => 'eximbank.co.tz', 'color' => '#ed1c24', 'aliases' => ['exim']],
    ['slug' => 'dtb', 'badge' => 'DTB', 'name' => 'Diamond Trust Bank (DTB)', 'type' => 'bank', 'domain' => 'dtbafrica.com', 'color' => '#0b3b7a', 'aliases' => ['dtb', 'diamond trust'], 'logo_url' => 'https://dtbafrica.com/storage/uploads/b022ae98-1973-4e4e-93e0-9ebf891f3d86/logo.png'],
    ['slug' => 'equity', 'name' => 'Equity Bank Tanzania', 'type' => 'bank', 'domain' => 'equitybank.co.tz', 'color' => '#a32a29', 'aliases' => ['equity']],
    ['slug' => 'kcb', 'name' => 'KCB Bank Tanzania', 'type' => 'bank', 'domain' => 'kcbgroup.com', 'color' => '#00843d', 'aliases' => ['kcb']],
    ['slug' => 'im-bank', 'name' => 'I&M Bank Tanzania', 'type' => 'bank', 'domain' => 'imbank.com', 'color' => '#e4002b', 'aliases' => ['i&m', 'i and m', 'im bank']],
    ['slug' => 'boa', 'name' => 'Bank of Africa Tanzania', 'type' => 'bank', 'domain' => 'boatanzania.co.tz', 'color' => '#00529b', 'aliases' => ['boa', 'bank of africa']],
    ['slug' => 'azania', 'name' => 'Azania Bank', 'type' => 'bank', 'domain' => 'azaniabank.co.tz', 'color' => '#00703c', 'aliases' => ['azania']],
    ['slug' => 'tcb', 'name' => 'Tanzania Commercial Bank (TCB)', 'type' => 'bank', 'domain' => 'tcbbank.co.tz', 'color' => '#0a4595', 'aliases' => ['tcb', 'tpb', 'posta bank']],
    ['slug' => 'pbz', 'name' => "People's Bank of Zanzibar (PBZ)", 'type' => 'bank', 'domain' => 'pbzbank.co.tz', 'color' => '#006837', 'aliases' => ['pbz', 'peoples bank of zanzibar']],
    ['slug' => 'akiba', 'name' => 'Akiba Commercial Bank', 'type' => 'bank', 'domain' => 'acbbank.co.tz', 'color' => '#0b6e4f', 'aliases' => ['akiba', 'acb']],
    ['slug' => 'amana', 'name' => 'Amana Bank', 'type' => 'bank', 'domain' => 'amanabank.co.tz', 'color' => '#00733e', 'aliases' => ['amana']],
    ['slug' => 'access', 'badge' => 'ACCESS', 'name' => 'Access Bank Tanzania', 'type' => 'bank', 'domain' => 'accessbankplc.com', 'color' => '#f37021', 'aliases' => ['access'], 'logo_url' => 'https://www.accessbankplc.com/Content/images/access-lg-logo.png', 'logo_crop' => 'symbol'],
    ['slug' => 'uba', 'name' => 'UBA Tanzania', 'type' => 'bank', 'domain' => 'ubagroup.com', 'color' => '#d71920', 'aliases' => ['uba', 'united bank for africa']],
    ['slug' => 'ecobank', 'name' => 'Ecobank Tanzania', 'type' => 'bank', 'domain' => 'ecobank.com', 'color' => '#005c84', 'aliases' => ['ecobank']],
    ['slug' => 'citibank', 'name' => 'Citibank Tanzania', 'type' => 'bank', 'domain' => 'citi.com', 'color' => '#056dae', 'aliases' => ['citi', 'citibank']],
    ['slug' => 'bank-of-baroda', 'badge' => 'BOB', 'name' => 'Bank of Baroda Tanzania', 'type' => 'bank', 'domain' => 'bankofbaroda.in', 'color' => '#f15a22', 'aliases' => ['baroda', 'bob'], 'logo_url' => 'https://bankofbaroda.bank.in/-/media/project/bob/shared/favicon/favicon.png'],
    ['slug' => 'bank-of-india', 'badge' => 'BOI', 'name' => 'Bank of India Tanzania', 'type' => 'bank', 'domain' => 'bankofindia.co.in', 'color' => '#f7941d', 'aliases' => ['bank of india', 'boi'], 'logo_url' => 'https://upload.wikimedia.org/wikipedia/commons/8/8c/Bank_of_India.png'],
    ['slug' => 'habib', 'name' => 'Africa & Gulf Bank Tanzania (formerly Habib African Bank)', 'type' => 'bank', 'domain' => 'habibafricanbank.co.tz', 'color' => '#1b3f8b', 'aliases' => ['habib', 'agb', 'africa and gulf', 'africa & gulf'], 'logo_url' => 'https://habibafricanbank.co.tz/logo4.jpg'],
    ['slug' => 'icb', 'badge' => 'ICB', 'name' => 'International Commercial Bank (ICB)', 'type' => 'bank', 'domain' => 'icbank.co.tz', 'logo_url' => 'https://icbank.co.tz/wp-content/uploads/2025/07/cropped-favicon-32x32-1-192x192.png', 'color' => '#1b3f8b', 'aliases' => ['icb', 'international commercial']],
    ['slug' => 'china-dasheng', 'badge' => 'CDB', 'name' => 'China Dasheng Bank', 'type' => 'bank', 'domain' => 'chinadashengbank.co.tz', 'color' => '#c8102e', 'aliases' => ['dasheng', 'china dasheng']],
    ['slug' => 'letshego', 'badge' => 'LB', 'name' => 'Letshego Bank', 'type' => 'bank', 'domain' => 'letshego.com', 'color' => '#00a19a', 'aliases' => ['letshego'], 'logo_url' => 'https://www.letshego.com/sites/default/files/logo_0.png'],
    ['slug' => 'mkombozi', 'badge' => 'MKCB', 'name' => 'Mkombozi Commercial Bank', 'type' => 'bank', 'domain' => 'mkombozibank.co.tz', 'color' => '#1c3f94', 'aliases' => ['mkombozi', 'mkcb'], 'logo_url' => 'https://upload.wikimedia.org/wikipedia/en/2/22/Mkombozi_Commercial_Bank_logo.png'],
    ['slug' => 'maendeleo', 'name' => 'Maendeleo Bank', 'type' => 'bank', 'domain' => 'maendeleobank.co.tz', 'color' => '#0d6b3a', 'aliases' => ['maendeleo']],
    ['slug' => 'mwanga-hakika', 'name' => 'Mwanga Hakika Bank', 'type' => 'bank', 'domain' => 'mhbbank.co.tz', 'color' => '#00599c', 'aliases' => ['mwanga', 'hakika', 'mhb'], 'logo_url' => 'https://mhbbank.co.tz/images/logo.png'],
    ['slug' => 'dcb', 'name' => 'DCB Commercial Bank', 'type' => 'bank', 'domain' => 'dcb.co.tz', 'color' => '#00529c', 'aliases' => ['dcb']],
    ['slug' => 'uchumi', 'name' => 'Uchumi Commercial Bank', 'type' => 'bank', 'domain' => 'uchumibank.co.tz', 'color' => '#0b7a3e', 'aliases' => ['uchumi']],
    ['slug' => 'kcbl', 'name' => 'Kilimanjaro Co-operative Bank', 'type' => 'bank', 'domain' => 'kcbl.co.tz', 'color' => '#2e7d32', 'aliases' => ['kilimanjaro', 'kcbl']],
    ['slug' => 'finca', 'name' => 'FINCA Microfinance Bank', 'type' => 'bank', 'domain' => 'finca.co.tz', 'color' => '#00a3ad', 'aliases' => ['finca']],
    ['slug' => 'selcom-bank', 'name' => 'Selcom Microfinance Bank', 'type' => 'bank', 'domain' => 'selcom.net', 'color' => '#00a3e0', 'aliases' => ['selcom bank', 'selcom microfinance']],
    ['slug' => 'tadb', 'name' => 'Tanzania Agricultural Development Bank (TADB)', 'type' => 'bank', 'domain' => 'tadb.co.tz', 'color' => '#3a7d22', 'aliases' => ['tadb', 'agricultural development']],
    ['slug' => 'tib', 'name' => 'TIB Development Bank', 'type' => 'bank', 'domain' => 'tib.co.tz', 'color' => '#003b71', 'aliases' => ['tib']],
];
