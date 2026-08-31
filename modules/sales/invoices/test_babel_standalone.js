const fs = require('fs');
const Babel = require('@babel/standalone');

const html = fs.readFileSync('c:/xampp/htdocs/public_html/modules/sales/invoices/index.php', 'utf8');
const match = html.match(/<script type="text\/babel">([\s\S]*?)<\/script>/);

if (match && match[1]) {
    try {
        const result = Babel.transform(match[1], { presets: ['react'] });
        console.log('Babel output first 200 chars:', result.code.substring(0, 200));
        if (result.code.includes('import')) console.log('IMPORT FOUND IN OUTPUT!');
    } catch (e) {
        console.error('Babel compilation failed:', e.message);
    }
}
